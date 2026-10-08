<?php
/**
 * Plugin Name: Jev Comment Triage
 * Description: Uses AI Provider for Jev to judge pending comments for relevance to their post, spam, and abuse, and acts only on confident answers.
 * Requires Plugins: ai-provider-for-jev
 * Requires PHP: 8.3
 * Version: 2.0.0
 * License: GPL-2.0-or-later
 * Text Domain: jev-comment-triage
 * Domain Path: /languages
 *
 * @package JevCommentTriage
 */

namespace JevCommentTriage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const META_KEY      = '_jev_triage';
const PENDING_META  = '_jct_pending';
const ATTEMPTS_META = '_jct_attempts';
const BASE_META     = '_jct_base';
const EVENT         = 'jct_drain';
const INTERVAL      = 'jct_minute';
const MAX_ATTEMPTS  = 3;
const LOCK_KEY      = 'jct_draining';

// Version of the stored assessment shape and of the questions asked. Bump it
// when question wording changes so cached verdicts are not reused.
const SCHEMA_VERSION = 2;

const RELEVANCE_OPTIONS = [ 'on_topic', 'off_topic', 'unclear' ];

// Thresholds scale with the cost of a wrong action: publishing needs a
// confident on-topic answer and clean spam/abuse signals; flagging spam needs
// a strong spam signal; anything abusive is held for a person.
const DEFAULT_THRESHOLDS = [
	'spam'      => 0.90, // spam probability that marks a comment as spam
	'abusive'   => 0.50, // abuse probability that holds a comment for review
	'relevance' => 0.80, // on-topic confidence required to keep WordPress's approval
	'clean'     => 0.20, // spam and abuse must both stay below this to approve
];

const COMMENTS_PER_REQUEST = 20;
const SPAM_CACHE_PREFIX    = 'jct_spam_';
const SPAM_CACHE_MIN       = 0.98;
const SPAM_CACHE_TTL       = 604800; // 7 days.
const CACHE_STATS_OPTION   = 'jct_spam_cache_stats';

/**
 * Load translations.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'jev-comment-triage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * True when the AI Provider for Jev helper API is available and configured.
 */
function provider_ready(): bool {
	if ( ! function_exists( 'AiProviderForJev\\evaluate' ) ) {
		return false;
	}

	return \AiProviderForJev\Settings\SettingsManager::instance()->is_configured();
}

/**
 * Count the distinct links in a comment (a classic spam signal).
 *
 * @param string $content Comment text.
 * @return int
 */
function count_links( string $content ): int {
	$urls = preg_match_all( '#https?://#i', $content );
	$www  = preg_match_all( '#(^|\s)www\.#i', $content );

	return (int) $urls + (int) $www;
}

/**
 * Thresholds used by decide(), after the `jct_thresholds` filter.
 *
 * Filtered values are merged over the defaults; missing or non-numeric keys
 * fall back to the default and every value is clamped to 0..1.
 *
 * @param array $assessment Assessment being decided (passed to the filter).
 * @return array{spam:float, abusive:float, relevance:float, clean:float}
 */
function thresholds( array $assessment = [] ): array {
	$filtered   = apply_filters( 'jct_thresholds', DEFAULT_THRESHOLDS, $assessment );
	$filtered   = is_array( $filtered ) ? $filtered : [];
	$thresholds = [];

	foreach ( DEFAULT_THRESHOLDS as $key => $default ) {
		$value              = $filtered[ $key ] ?? $default;
		$value              = is_numeric( $value ) ? (float) $value : $default;
		$thresholds[ $key ] = max( 0.0, min( 1.0, $value ) );
	}

	return $thresholds;
}

/**
 * The per-comment data placed inside each question.
 *
 * @param array $commentdata Comment data.
 * @param int   $link_count  Pre-computed link count.
 * @return array<string, mixed>
 */
function comment_payload( array $commentdata, int $link_count ): array {
	$payload = [
		'content'    => (string) ( $commentdata['comment_content'] ?? '' ),
		'link_count' => (string) $link_count,
	];

	// Author details help accuracy but are PII; let sites opt out.
	if ( (bool) apply_filters( 'jct_include_author_details', true, $commentdata ) ) {
		$payload['author'] = [
			'name'  => (string) ( $commentdata['comment_author'] ?? '' ),
			'url'   => (string) ( $commentdata['comment_author_url'] ?? '' ),
			'email' => (string) ( $commentdata['comment_author_email'] ?? '' ),
		];
	}

	return $payload;
}

/**
 * The three independent judgments asked about one comment.
 *
 * Relevance is a Choice because its options are mutually exclusive. Spam and
 * abuse are separate Nouls because a comment can be on-topic and still be
 * spam or abusive. The comment travels inside each question so several
 * comments on one post can share a single request.
 *
 * @param array<string, mixed> $comment Comment payload from comment_payload().
 * @return array<string, array<string, mixed>> Questions keyed relevance/spam/abusive.
 */
function comment_questions( array $comment ): array {
	return [
		'relevance' => [
			'type'         => 'choice',
			'instructions' => [
				'question' => 'How does `comment.content` relate to the blog post in `post.title` and `post.content`?',
				'comment'  => $comment,
			],
			'criteria'     => [
				'on_topic'  => 'It discusses, questions, critiques, or adds to the subject of the post.',
				'off_topic' => 'It is about something unrelated to the post.',
				'unclear'   => 'It is too short, garbled, or vague to tell.',
			],
		],
		'spam'      => [
			'type'         => 'noul',
			'instructions' => [
				'question' => 'Is `comment` spam: unsolicited promotion or advertising, a scam, phishing, SEO link-dropping, or bulk content not written as genuine discussion? A comment can mention the post and still be spam.',
				'comment'  => $comment,
			],
		],
		'abusive'   => [
			'type'         => 'noul',
			'instructions' => [
				'question' => 'Does `comment.content` contain insults, harassment, threats, hate speech, or other abuse directed at a person or group?',
				'comment'  => $comment,
			],
		],
	];
}

/**
 * Validate and normalize the three answers for one comment.
 *
 * @param array<string, mixed> $answers All answers in the response.
 * @param string               $prefix  Question-id prefix for this comment.
 * @return array<string, mixed>|\WP_Error
 */
function parse_answers( array $answers, string $prefix ): array|\WP_Error {
	$relevance     = $answers[ $prefix . 'relevance' ] ?? null;
	$choice        = is_array( $relevance ) ? (string) ( $relevance['choice'] ?? '' ) : '';
	$confidence    = is_array( $relevance ) ? ( $relevance['confidence'] ?? null ) : null;
	$probabilities = is_array( $relevance ) ? ( $relevance['probabilities'] ?? null ) : null;

	if (
		! in_array( $choice, RELEVANCE_OPTIONS, true )
		|| ! is_unit_interval( $confidence )
		|| ! is_array( $probabilities )
		|| array_diff( RELEVANCE_OPTIONS, array_keys( $probabilities ) )
	) {
		return new \WP_Error( 'jct_invalid_response', 'Jev returned an invalid relevance answer.' );
	}

	foreach ( $probabilities as $probability ) {
		if ( ! is_unit_interval( $probability ) ) {
			return new \WP_Error( 'jct_invalid_response', 'Jev returned invalid relevance probabilities.' );
		}
	}

	$signals = [];
	foreach ( [ 'spam', 'abusive' ] as $id ) {
		$answer = $answers[ $prefix . $id ] ?? null;
		$value  = is_array( $answer ) ? ( $answer['noul'] ?? null ) : null;
		if ( ! is_unit_interval( $value ) ) {
			return new \WP_Error( 'jct_invalid_response', 'Jev returned an invalid ' . $id . ' answer.' );
		}
		$signals[ $id ] = (float) $value;
	}

	return [
		'version'   => SCHEMA_VERSION,
		'source'    => 'jev',
		'relevance' => [
			'choice'        => $choice,
			'confidence'    => (float) $confidence,
			'probabilities' => array_map( 'floatval', $probabilities ),
		],
		'spam'      => $signals['spam'],
		'abusive'   => $signals['abusive'],
	];
}

/**
 * Whether a value is a number between 0 and 1 inclusive.
 *
 * @param mixed $value Value to check.
 */
function is_unit_interval( mixed $value ): bool {
	return is_numeric( $value ) && (float) $value >= 0.0 && (float) $value <= 1.0;
}

/**
 * Judge several comments on the same post in one Jev request.
 *
 * The post is the shared state, sent once; each comment contributes its own
 * relevance, spam, and abuse questions. Every comment is validated on its own,
 * so one malformed answer does not discard the others.
 *
 * @param array                                               $postdata Post data (post_title, post_content).
 * @param array<int|string, array{commentdata:array, link_count:int}> $items    Comments keyed by caller id.
 * @return array<int|string, array<string, mixed>|\WP_Error>|\WP_Error Per-item assessments, or an error for the whole request.
 */
function assess_many( array $postdata, array $items ): array|\WP_Error {
	if ( [] === $items ) {
		return [];
	}

	$state     = [
		'post' => [
			'title'   => (string) ( $postdata['post_title'] ?? '' ),
			'content' => (string) ( $postdata['post_content'] ?? '' ),
		],
	];
	$questions = [];
	$prefixes  = [];
	$index     = 0;

	foreach ( $items as $key => $item ) {
		$prefix           = 'c' . $index++ . '_';
		$prefixes[ $key ] = $prefix;
		$payload          = comment_payload( (array) $item['commentdata'], (int) $item['link_count'] );

		foreach ( comment_questions( $payload ) as $id => $question ) {
			$questions[ $prefix . $id ] = $question;
		}
	}

	$response = \AiProviderForJev\evaluate( $state, $questions );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$answers = is_array( $response['answers'] ?? null ) ? $response['answers'] : [];
	$results = [];

	foreach ( $prefixes as $key => $prefix ) {
		$results[ $key ] = parse_answers( $answers, $prefix );
	}

	return $results;
}

/**
 * Judge comments like assess_many(), isolating comments that break a request.
 *
 * A rejected request (HTTP 4xx other than auth or rate limiting) can be caused
 * by a single comment, for example one that is too large. Such a chunk is split
 * in half and retried, so only the offending comment uses up its attempts.
 * Outages, timeouts, auth failures, and rate limits are returned for every
 * comment unchanged, so they do not multiply into more requests.
 *
 * @param array                                                        $postdata Post data.
 * @param array<int|string, array{commentdata:array, link_count:int}> $items    Comments keyed by caller id.
 * @return array<int|string, array<string, mixed>|\WP_Error> Per-item assessments or errors.
 */
function assess_isolated( array $postdata, array $items ): array {
	$results = assess_many( $postdata, $items );

	if ( ! is_wp_error( $results ) ) {
		return $results;
	}

	if ( count( $items ) < 2 || ! is_request_rejection( $results ) ) {
		return array_fill_keys( array_keys( $items ), $results );
	}

	$halves = array_chunk( $items, (int) ceil( count( $items ) / 2 ), true );

	return assess_isolated( $postdata, $halves[0] ) + assess_isolated( $postdata, $halves[1] );
}

/**
 * Whether an API error means the request itself was rejected, as opposed to
 * an outage, timeout, auth problem, or rate limit.
 *
 * @param \WP_Error $error Error from the provider.
 */
function is_request_rejection( \WP_Error $error ): bool {
	$data   = $error->get_error_data();
	$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

	return $status >= 400 && $status < 500 && ! in_array( $status, [ 401, 403, 408, 429 ], true );
}

/**
 * Judge one comment in the context of its post.
 *
 * @param array $commentdata Comment data.
 * @param array $postdata    Post data.
 * @param int   $link_count  Pre-computed link count.
 * @return array<string, mixed>|\WP_Error
 */
function assess( array $commentdata, array $postdata, int $link_count ): array|\WP_Error {
	$results = assess_many( $postdata, [ 0 => [ 'commentdata' => $commentdata, 'link_count' => $link_count ] ] );

	return is_wp_error( $results ) ? $results : $results[0];
}

/**
 * Turn an assessment into a comment approval status.
 *
 * Triage never publishes past WordPress's own decision: at most it keeps
 * `$approved`, otherwise it downgrades to spam or hold.
 *
 * @param array      $assessment Assessment from assess() or the spam cache.
 * @param int|string $approved   WordPress's own decision.
 * @return int|string '1', '0', or 'spam'.
 */
function decide( array $assessment, $approved ) {
	$t       = thresholds( $assessment );
	$spam    = (float) ( $assessment['spam'] ?? 0.0 );
	$abusive = (float) ( $assessment['abusive'] ?? 0.0 );

	if ( $spam >= $t['spam'] ) {
		return 'spam';
	}

	// Abuse is held for a person rather than hidden in the spam folder.
	if ( $abusive >= $t['abusive'] ) {
		return '0';
	}

	$relevance = (array) ( $assessment['relevance'] ?? [] );
	$on_topic  = 'on_topic' === ( $relevance['choice'] ?? '' )
		&& (float) ( $relevance['confidence'] ?? 0.0 ) >= $t['relevance'];

	if ( $on_topic && $spam < $t['clean'] && $abusive < $t['clean'] ) {
		return $approved;
	}

	return '0';
}

/**
 * Whether a user is trusted enough to skip triage entirely.
 *
 * @param int $user_id User ID (0 for guests).
 */
function is_trusted( int $user_id ): bool {
	return $user_id > 0 && user_can( $user_id, 'moderate_comments' );
}

/**
 * Register the one-minute schedule used to drain the triage backlog.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function add_schedule( array $schedules ): array {
	$schedules[ INTERVAL ] = [
		'interval' => MINUTE_IN_SECONDS,
		'display'  => __( 'Every minute (Jev triage)', 'jev-comment-triage' ),
	];
	return $schedules;
}
add_filter( 'cron_schedules', __NAMESPACE__ . '\\add_schedule' );

/**
 * Ensure the recurring drain event is scheduled.
 */
function ensure_scheduled(): void {
	if ( provider_ready() && ! wp_next_scheduled( EVENT ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, INTERVAL, EVENT );
	}
}
add_action( 'init', __NAMESPACE__ . '\\ensure_scheduled' );
register_activation_hook( __FILE__, __NAMESPACE__ . '\\ensure_scheduled' );
register_deactivation_hook(
	__FILE__,
	static function (): void {
		wp_unschedule_hook( EVENT );
	}
);

/**
 * Whether the comment is a regular visitor comment (not a pingback/trackback).
 *
 * @param array $commentdata Comment data.
 */
function is_regular_comment( array $commentdata ): bool {
	$type = (string) ( $commentdata['comment_type'] ?? '' );
	return '' === $type || 'comment' === $type;
}

/**
 * Registry of WordPress's own "would-be" decision, keyed by comment fingerprint,
 * shared between defer() and enqueue() within a request.
 *
 * @return array<string, string>
 */
function &base_registry(): array {
	static $registry = [];
	return $registry;
}

/**
 * Fingerprint a comment so defer() and enqueue() agree on the same entry.
 *
 * @param array $commentdata Comment data.
 */
function fingerprint( array $commentdata ): string {
	return md5(
		(string) ( $commentdata['comment_post_ID'] ?? '' ) . '|'
		. (string) ( $commentdata['comment_author_email'] ?? '' ) . '|'
		. (string) ( $commentdata['comment_content'] ?? '' )
	);
}

/**
 * Hold untrusted comments as pending so nothing is public until triage runs,
 * while remembering WordPress's own decision so triage can respect it.
 *
 * Comments WordPress already rejected (spam/trash via the blocklist) are left
 * untouched with no API call; pingbacks, trackbacks, trusted users, and an
 * absent provider keep WordPress's decision.
 *
 * @param int|string|\WP_Error $approved    Current approval status.
 * @param array                $commentdata Comment data.
 * @return int|string|\WP_Error
 */
function defer( $approved, array $commentdata ) {
	if ( is_wp_error( $approved ) || ! provider_ready() ) {
		return $approved;
	}

	if ( ! is_regular_comment( $commentdata ) || is_trusted( (int) ( $commentdata['user_id'] ?? 0 ) ) ) {
		return $approved;
	}

	if ( 'spam' === $approved || 'trash' === $approved ) {
		return $approved;
	}

	// Remember whether WordPress would have approved or held this comment.
	$registry =& base_registry();
	$registry[ fingerprint( $commentdata ) ] = ( '1' === (string) $approved ) ? '1' : '0';

	return '0';
}
// Runs last so it captures the site's effective decision after other moderation
// plugins, and remembers it for the background job.
add_filter( 'pre_comment_approved', __NAMESPACE__ . '\\defer', PHP_INT_MAX, 2 );

/**
 * Mark a new comment for the background drain and nudge it to run soon.
 *
 * @param int        $comment_id  New comment ID.
 * @param int|string $approved    Approval status.
 * @param array      $commentdata Comment data.
 */
function enqueue( int $comment_id, $approved, array $commentdata ): void {
	if ( ! provider_ready() || ! is_regular_comment( $commentdata ) || is_trusted( (int) ( $commentdata['user_id'] ?? 0 ) ) ) {
		return;
	}

	if ( 'spam' === $approved || 'trash' === $approved ) {
		return;
	}

	$registry =& base_registry();
	$base     = $registry[ fingerprint( $commentdata ) ] ?? '0';

	add_comment_meta( $comment_id, PENDING_META, 1, true );
	add_comment_meta( $comment_id, BASE_META, $base, true );
	ensure_scheduled();

	// Nudge a near-immediate drain, at most once every 15s to avoid piling up.
	if ( ! wp_next_scheduled( EVENT, [ 'now' ] ) && ! get_transient( 'jct_drain_soon' ) ) {
		set_transient( 'jct_drain_soon', 1, 15 );
		wp_schedule_single_event( time(), EVENT, [ 'now' ] );
		spawn_cron();
	}
}
add_action( 'comment_post', __NAMESPACE__ . '\\enqueue', 10, 3 );

/**
 * Drain the pending-comment backlog: assess a bounded batch and route each.
 *
 * Scoped to comments this plugin deferred (a pending marker) that have not been
 * triaged yet. Batch size is filterable via `jct_batch_size`.
 */
function drain(): void {
	if ( ! provider_ready() || get_transient( LOCK_KEY ) ) {
		return;
	}

	// Prevent overlapping cron runs from processing the same batch twice.
	set_transient( LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );

	try {
		$batch = (int) apply_filters( 'jct_batch_size', 20 );

		$comments = get_comments(
			[
				'status'     => 'hold',
				'number'     => $batch,
				'orderby'    => 'comment_date_gmt',
				'order'      => 'ASC',
				'meta_query' => [
					'relation' => 'AND',
					[ 'key' => PENDING_META, 'compare' => 'EXISTS' ],
					[ 'key' => META_KEY, 'compare' => 'NOT EXISTS' ],
				],
			]
		);

		process_comments( $comments );
	} finally {
		delete_transient( LOCK_KEY );
	}
}
add_action( EVENT, __NAMESPACE__ . '\\drain' );

/**
 * Assess one pending comment with Jev and apply the decision.
 *
 * @param \WP_Comment $comment Comment to process.
 */
function process_comment( \WP_Comment $comment ): void {
	process_comments( [ $comment ] );
}

/**
 * Assess pending comments and apply each decision.
 *
 * Comments are grouped by post so each post's content is sent once, with up
 * to `jct_comments_per_request` comments in one Jev request. On API failure a
 * comment is left pending (fail-safe against spam) and retried on later ticks;
 * after MAX_ATTEMPTS it is left held for a human.
 *
 * @param array<int, \WP_Comment> $comments Comments to process.
 */
function process_comments( array $comments ): void {
	$by_post = [];
	foreach ( $comments as $comment ) {
		if ( $comment instanceof \WP_Comment ) {
			$by_post[ (int) $comment->comment_post_ID ][] = $comment;
		}
	}

	$per_request = max( 1, (int) apply_filters( 'jct_comments_per_request', COMMENTS_PER_REQUEST ) );
	$stats       = [ 'lookups' => 0, 'hits' => 0, 'agreed' => 0 ];

	foreach ( $by_post as $post_id => $group ) {
		$post     = get_post( $post_id );
		$postdata = [
			'post_title'   => is_object( $post ) ? (string) ( $post->post_title ?? '' ) : '',
			'post_content' => is_object( $post ) ? (string) ( $post->post_content ?? '' ) : '',
		];

		foreach ( array_chunk( $group, $per_request ) as $chunk ) {
			process_chunk( $postdata, $chunk, $stats );
		}
	}

	record_cache_stats( $stats );
}

/**
 * Assess comments that belong to one post in a single request.
 *
 * @param array                    $postdata Post data (post_title, post_content).
 * @param array<int, \WP_Comment>  $comments Comments on that post.
 * @param array<string, int>       $stats    Spam-cache counters, updated in place.
 */
function process_chunk( array $postdata, array $comments, array &$stats ): void {
	$use_cache = (bool) apply_filters( 'jct_spam_cache', false );
	$min_words = max( 0, (int) apply_filters( 'jct_min_words', 0 ) );
	$items     = [];
	$bases     = [];
	$shadowed  = [];

	foreach ( $comments as $comment ) {
		$comment_id           = (int) $comment->comment_ID;
		$bases[ $comment_id ] = ( '1' === (string) get_comment_meta( $comment_id, BASE_META, true ) ) ? '1' : '0';
		$content              = trim( (string) $comment->comment_content );

		if ( '' === $content ) {
			delete_comment_meta( $comment_id, PENDING_META );
			delete_comment_meta( $comment_id, BASE_META );
			finalize( $comment_id, $bases[ $comment_id ] ); // nothing to assess; respect WordPress's decision
			continue;
		}

		$link_count = count_links( $content );

		// Optional site policy: hold very short, link-free comments without a call.
		if ( $min_words > 0 && 0 === $link_count && word_count( $content ) < $min_words ) {
			apply_assessment(
				$comment_id,
				$bases[ $comment_id ],
				[ 'version' => SCHEMA_VERSION, 'source' => 'rule', 'rule' => 'min_words' ],
				$link_count
			);
			continue;
		}

		$cache_key = spam_cache_key( $content, (string) $comment->comment_author_url, (string) $comment->comment_author_email );
		$cached    = get_site_transient( SPAM_CACHE_PREFIX . $cache_key );
		++$stats['lookups'];

		if ( is_array( $cached ) ) {
			++$stats['hits'];
			if ( $use_cache ) {
				apply_assessment( $comment_id, $bases[ $comment_id ], [ 'source' => 'cache' ] + $cached, $link_count );
				continue;
			}
			// Shadow mode: still ask Jev, and record whether it agrees.
			$shadowed[ $comment_id ] = true;
		}

		$items[ $comment_id ] = [
			'commentdata' => [
				'comment_content'      => $comment->comment_content,
				'comment_author'       => $comment->comment_author,
				'comment_author_url'   => $comment->comment_author_url,
				'comment_author_email' => $comment->comment_author_email,
				'user_id'              => (int) $comment->user_id,
			],
			'link_count'  => $link_count,
			'cache_key'   => $cache_key,
		];
	}

	if ( [] === $items ) {
		return;
	}

	$results = assess_isolated( $postdata, $items );

	foreach ( $items as $comment_id => $item ) {
		$assessment = is_wp_error( $results )
			? $results
			: ( $results[ $comment_id ] ?? new \WP_Error( 'jct_invalid_response', 'Jev returned no answer for this comment.' ) );

		if ( is_wp_error( $assessment ) ) {
			record_failure( $comment_id );
			continue;
		}

		if ( isset( $shadowed[ $comment_id ] ) && $assessment['spam'] >= thresholds( $assessment )['spam'] ) {
			++$stats['agreed'];
		}

		if ( $assessment['spam'] >= SPAM_CACHE_MIN ) {
			set_site_transient(
				SPAM_CACHE_PREFIX . $item['cache_key'],
				[
					'version' => SCHEMA_VERSION,
					'spam'    => $assessment['spam'],
					'abusive' => $assessment['abusive'],
				],
				SPAM_CACHE_TTL
			);
		}

		apply_assessment( $comment_id, $bases[ $comment_id ], $assessment, $item['link_count'] );
	}
}

/**
 * Store an assessment, apply its decision, and clear the queue markers.
 *
 * @param int                  $comment_id Comment ID.
 * @param string               $base       WordPress's own decision ('1' or '0').
 * @param array<string, mixed> $assessment Assessment to store.
 * @param int                  $link_count Link count.
 */
function apply_assessment( int $comment_id, string $base, array $assessment, int $link_count ): void {
	// The base is WordPress's own decision, so triage never publishes past the
	// site's moderation policy — it only downgrades to spam or hold.
	$decision = (string) decide( $assessment, $base );

	add_comment_meta(
		$comment_id,
		META_KEY,
		$assessment + [ 'link_count' => $link_count, 'decision' => $decision ],
		true
	);
	delete_comment_meta( $comment_id, PENDING_META );
	delete_comment_meta( $comment_id, BASE_META );

	finalize( $comment_id, $decision );

	do_action( 'jct_triaged', $comment_id, $assessment, $decision );
}

/**
 * Count a failed attempt; give up after MAX_ATTEMPTS and leave it held.
 *
 * @param int $comment_id Comment ID.
 */
function record_failure( int $comment_id ): void {
	$attempts = (int) get_comment_meta( $comment_id, ATTEMPTS_META, true ) + 1;
	update_comment_meta( $comment_id, ATTEMPTS_META, $attempts );
	if ( $attempts >= MAX_ATTEMPTS ) {
		delete_comment_meta( $comment_id, PENDING_META ); // give up; leave held for a human
	}
}

/**
 * Number of whitespace-separated words in a comment.
 *
 * @param string $content Comment text.
 */
function word_count( string $content ): int {
	$words = preg_split( '/\s+/u', trim( $content ), -1, PREG_SPLIT_NO_EMPTY );

	return is_array( $words ) ? count( $words ) : 0;
}

/**
 * Cache key for a confident spam verdict on this exact submission.
 *
 * Covers everything the spam judgment can rely on: the raw text including any
 * link markup, the author's website host, and the author's email domain. A
 * generic phrase posted with a spam link or spam website therefore does not
 * mark the same phrase as spam for everyone else. The model and schema version
 * are included so a model or question change does not reuse old verdicts.
 *
 * @param string $content      Comment text.
 * @param string $author_url   Comment author URL.
 * @param string $author_email Comment author email.
 */
function spam_cache_key( string $content, string $author_url = '', string $author_email = '' ): string {
	$lower = static fn( string $text ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

	$text   = $lower( (string) preg_replace( '/\s+/u', ' ', trim( $content ) ) );
	$host   = $lower( (string) parse_url( $author_url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- host only.
	$domain = $lower( (string) substr( (string) strrchr( $author_email, '@' ), 1 ) );
	$model  = '';

	$settings = \AiProviderForJev\Settings\SettingsManager::instance();
	if ( method_exists( $settings, 'get_model' ) ) {
		$model = (string) $settings->get_model();
	}

	return md5( implode( '|', [ SCHEMA_VERSION, $model, $host, $domain, $text ] ) );
}

/**
 * Accumulate network-wide spam-cache counters.
 *
 * In shadow mode these show how often the cache would have answered and how
 * often Jev agreed, before anyone turns `jct_spam_cache` on.
 *
 * @param array<string, int> $stats Counters from this run.
 */
function record_cache_stats( array $stats ): void {
	if ( empty( $stats['lookups'] ) ) {
		return;
	}

	$stored = get_site_option( CACHE_STATS_OPTION, [] );
	$stored = is_array( $stored ) ? $stored : [];

	foreach ( $stats as $key => $value ) {
		$stored[ $key ] = (int) ( $stored[ $key ] ?? 0 ) + (int) $value;
	}

	update_site_option( CACHE_STATS_OPTION, $stored );
}

/**
 * Apply a decision ('spam', '1', or '0') to a comment's status.
 *
 * @param int    $comment_id Comment ID.
 * @param string $decision   Decision value.
 */
function finalize( int $comment_id, string $decision ): void {
	$map = [ 'spam' => 'spam', '1' => 'approve', '0' => 'hold' ];
	wp_set_comment_status( $comment_id, $map[ $decision ] ?? 'hold' );
}

/**
 * Show the stored judgments and decision in the comments list.
 *
 * @param array $columns Existing columns.
 * @return array
 */
function add_column( array $columns ): array {
	$columns['jev_triage'] = __( 'Jev', 'jev-comment-triage' );
	return $columns;
}
add_filter( 'manage_edit-comments_columns', __NAMESPACE__ . '\\add_column' );

/**
 * Render the triage column.
 *
 * @param string $column     Column id.
 * @param int    $comment_id Comment id.
 */
function render_column( string $column, int $comment_id ): void {
	if ( 'jev_triage' !== $column ) {
		return;
	}

	$data = get_comment_meta( $comment_id, META_KEY, true );
	if ( ! is_array( $data ) ) {
		echo '—';
		return;
	}

	echo esc_html( column_text( $data ) );
}
add_action( 'manage_comments_custom_column', __NAMESPACE__ . '\\render_column', 10, 2 );

/**
 * Human-readable summary of a stored assessment.
 *
 * @param array<string, mixed> $data Stored assessment.
 */
function column_text( array $data ): string {
	$decisions = [
		'spam' => __( 'spam', 'jev-comment-triage' ),
		'1'    => __( 'approved', 'jev-comment-triage' ),
		'0'    => __( 'held', 'jev-comment-triage' ),
	];
	$decision  = isset( $data['decision'] ) ? ( $decisions[ (string) $data['decision'] ] ?? (string) $data['decision'] ) : '';
	$suffix    = '' !== $decision ? ' → ' . $decision : '';

	// Assessments stored by earlier plugin versions use a different shape.
	if ( SCHEMA_VERSION !== (int) ( $data['version'] ?? 0 ) ) {
		return __( 'Earlier version', 'jev-comment-triage' ) . $suffix;
	}

	$source = (string) ( $data['source'] ?? 'jev' );

	if ( 'rule' === $source ) {
		return __( 'Too short to judge', 'jev-comment-triage' ) . $suffix;
	}

	if ( 'cache' === $source ) {
		return sprintf(
			/* translators: %s: spam probability, e.g. 0.99. */
			__( 'Known spam text (cached) · spam %s', 'jev-comment-triage' ),
			number_format( (float) ( $data['spam'] ?? 0 ), 2 )
		) . $suffix;
	}

	$relevance = [
		'on_topic'  => __( 'on-topic', 'jev-comment-triage' ),
		'off_topic' => __( 'off-topic', 'jev-comment-triage' ),
		'unclear'   => __( 'unclear', 'jev-comment-triage' ),
	];
	$choice    = (string) ( $data['relevance']['choice'] ?? '' );

	return sprintf(
		/* translators: 1: relevance label, 2: relevance confidence, 3: spam probability, 4: abuse probability. */
		__( '%1$s %2$s · spam %3$s · abuse %4$s', 'jev-comment-triage' ),
		$relevance[ $choice ] ?? $choice,
		number_format( (float) ( $data['relevance']['confidence'] ?? 0 ), 2 ),
		number_format( (float) ( $data['spam'] ?? 0 ), 2 ),
		number_format( (float) ( $data['abusive'] ?? 0 ), 2 )
	) . $suffix;
}

/**
 * Disclose that comment data is sent to a third-party AI service.
 */
function register_privacy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = __( 'When you submit a comment, its text and the related post content — and, unless the site disables it, your name, email address, and website — are sent to the TypeSafe (Jev) service to check the comment for relevance, spam, and abuse before it is published.', 'jev-comment-triage' );
	wp_add_privacy_policy_content( 'Jev Comment Triage', wp_kses_post( wpautop( $content ) ) );
}
add_action( 'admin_init', __NAMESPACE__ . '\\register_privacy_content' );

/**
 * Warn admins when WP-Cron is disabled, since the drain relies on it.
 */
function cron_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'edit-comments', 'plugins' ], true ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Jev Comment Triage: WP-Cron is disabled (DISABLE_WP_CRON). Make sure a system cron runs wp-cron.php, or deferred comments will not be triaged.', 'jev-comment-triage' )
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\cron_notice' );
