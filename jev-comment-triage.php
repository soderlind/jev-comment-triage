<?php
/**
 * Plugin Name: Jev Comment Triage
 * Description: Uses AI Provider for Jev to auto-moderate comments asynchronously — a batched background job triages pending comments (spam/scam/toxicity) while respecting WordPress's own moderation, keeping comment submission fast.
 * Requires Plugins: ai-provider-for-jev
 * Requires PHP: 8.3
 * Version: 1.4.0
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

/**
 * Load translations.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'jev-comment-triage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

/**
 * Default decision thresholds. Override with the `jct_thresholds` filter.
 *
 * @return array{spam:float, scam:float, hold:float, toxicity_hold:float, link_assist:float}
 */
function thresholds(): array {
	return apply_filters(
		'jct_thresholds',
		[
			'spam'          => 0.75, // spam/scam probability that auto-flags as spam
			'scam'          => 0.65, // phishing/fraud probability that auto-flags as spam
			'hold'          => 0.45, // borderline probability that holds for review
			'toxicity_hold' => 1.5,  // toxicity score (0-2) that holds for review
			'link_assist'   => 0.50, // spam probability needed for the link heuristic
		]
	);
}

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
 * Ask Jev to assess a comment for spam, scam/phishing, and toxicity.
 *
 * The state includes author metadata and a link count so the model can weigh
 * the strongest spam signals (author URL, link-dropping) alongside the text.
 *
 * @param array $commentdata Comment data.
 * @param int   $link_count  Pre-computed link count.
 * @return array{is_spam:float, is_scam:float, toxicity:float}|\WP_Error
 */
function assess( array $commentdata, int $link_count ): array|\WP_Error {
	$state = [
		'comment'    => (string) ( $commentdata['comment_content'] ?? '' ),
		'link_count' => $link_count,
	];

	// Author details help accuracy but are PII; let sites opt out.
	if ( (bool) apply_filters( 'jct_include_author_details', true, $commentdata ) ) {
		$state['author']       = (string) ( $commentdata['comment_author'] ?? '' );
		$state['author_url']   = (string) ( $commentdata['comment_author_url'] ?? '' );
		$state['author_email'] = (string) ( $commentdata['comment_author_email'] ?? '' );
	}

	$response = \AiProviderForJev\evaluate(
		$state,
		[
			'is_spam'  => [
				'type'         => 'noul',
				'instructions' => 'This comment is spam: unsolicited advertising, affiliate or product promotion, SEO link-dropping, keyword stuffing, or text unrelated to the post written mainly to place links or a name/URL.',
				'criteria'     => [
					'true'  => 'Spam, promotional, or link-dropping',
					'false' => 'A genuine, on-topic comment from a real reader',
				],
			],
			'is_scam'  => [
				'type'         => 'noul',
				'instructions' => 'This comment attempts a scam, phishing, or fraud: fake offers or prizes, money-making or investment schemes, crypto pumps, adult or dating bait, or links to deceptive or malicious sites.',
				'criteria'     => [
					'true'  => 'Deceptive, fraudulent, or malicious',
					'false' => 'No scam or deception',
				],
			],
			'toxicity' => [
				'type'         => 'score',
				'instructions' => 'How toxic, abusive, or harassing is this comment?',
				'criteria'     => [ 'Civil', 'Rude or hostile', 'Abusive, hateful, or harassing' ],
			],
		]
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$answers = $response['answers'] ?? [];

	return [
		'is_spam'  => (float) ( $answers['is_spam']['noul'] ?? 0.0 ),
		'is_scam'  => (float) ( $answers['is_scam']['noul'] ?? 0.0 ),
		'toxicity' => (float) ( $answers['toxicity']['score'] ?? 0.0 ),
	];
}

/**
 * Turn an assessment into a comment approval status.
 *
 * @param array{is_spam:float, is_scam:float, toxicity:float} $a          Assessment.
 * @param int                                                 $link_count Link count.
 * @param int|string                                          $approved   Current status.
 * @return int|string '1', '0', or 'spam'.
 */
function decide( array $a, int $link_count, $approved ) {
	$t         = thresholds();
	$max_links = (int) get_option( 'comment_max_links', 2 );
	$spammy    = max( $a['is_spam'], $a['is_scam'] );

	// Link-heavy comments with any real spam signal are almost always spam.
	$link_spam = $max_links > 0 && $link_count >= $max_links && $spammy >= $t['link_assist'];

	if ( $spammy >= $t['spam'] || $a['is_scam'] >= $t['scam'] || $link_spam ) {
		return 'spam';
	}

	// Borderline spam or toxic content is held for a human rather than deleted,
	// which keeps false positives out of the spam bucket.
	if ( $spammy >= $t['hold'] || $a['toxicity'] >= $t['toxicity_hold'] ) {
		return '0';
	}

	return $approved;
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

		foreach ( $comments as $comment ) {
			process_comment( $comment );
		}
	} finally {
		delete_transient( LOCK_KEY );
	}
}
add_action( EVENT, __NAMESPACE__ . '\\drain' );

/**
 * Assess one pending comment with Jev and apply the decision.
 *
 * On API failure the comment is left pending (fail-safe against spam) and
 * retried on later ticks; after MAX_ATTEMPTS it is left held for a human.
 *
 * @param \WP_Comment $comment Comment to process.
 */
function process_comment( \WP_Comment $comment ): void {
	$comment_id = (int) $comment->comment_ID;
	$base       = ( '1' === (string) get_comment_meta( $comment_id, BASE_META, true ) ) ? '1' : '0';
	$content    = trim( (string) $comment->comment_content );

	if ( '' === $content ) {
		delete_comment_meta( $comment_id, PENDING_META );
		delete_comment_meta( $comment_id, BASE_META );
		finalize( $comment_id, $base ); // nothing to assess; respect WordPress's decision
		return;
	}

	$link_count = count_links( $content );
	$assessment = assess(
		[
			'comment_content'      => $comment->comment_content,
			'comment_author'       => $comment->comment_author,
			'comment_author_url'   => $comment->comment_author_url,
			'comment_author_email' => $comment->comment_author_email,
			'user_id'              => (int) $comment->user_id,
		],
		$link_count
	);

	if ( is_wp_error( $assessment ) ) {
		$attempts = (int) get_comment_meta( $comment_id, ATTEMPTS_META, true ) + 1;
		update_comment_meta( $comment_id, ATTEMPTS_META, $attempts );
		if ( $attempts >= MAX_ATTEMPTS ) {
			delete_comment_meta( $comment_id, PENDING_META ); // give up; leave held for a human
		}
		return;
	}

	// The base is WordPress's own decision, so triage never publishes past the
	// site's moderation policy — it only downgrades to spam or hold.
	$decision = decide( $assessment, $link_count, $base );

	add_comment_meta(
		$comment_id,
		META_KEY,
		$assessment + [ 'link_count' => $link_count, 'decision' => (string) $decision ],
		true
	);
	delete_comment_meta( $comment_id, PENDING_META );
	delete_comment_meta( $comment_id, BASE_META );

	finalize( $comment_id, (string) $decision );

	do_action( 'jct_triaged', $comment_id, $assessment, (string) $decision );
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
 * Show the stored scores as a column in the admin comments list.
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

	printf(
		'spam %s · scam %s · tox %s%s',
		esc_html( number_format( (float) ( $data['is_spam'] ?? 0 ), 2 ) ),
		esc_html( number_format( (float) ( $data['is_scam'] ?? 0 ), 2 ) ),
		esc_html( number_format( (float) ( $data['toxicity'] ?? 0 ), 2 ) ),
		isset( $data['decision'] ) ? ' → ' . esc_html( (string) $data['decision'] ) : ''
	);
}
add_action( 'manage_comments_custom_column', __NAMESPACE__ . '\\render_column', 10, 2 );

/**
 * Disclose that comment data is sent to a third-party AI service.
 */
function register_privacy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = __( 'When you submit a comment, its text — and, unless the site disables it, your name, email address, and website — is sent to the TypeSafe (Jev) service to check it for spam, scams, and abuse.', 'jev-comment-triage' );
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
