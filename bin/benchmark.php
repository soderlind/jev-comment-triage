<?php
/**
 * End-to-end benchmark harness for JEV Comment Triage.
 *
 * Exercises the real production path — `wp_new_comment()` -> `defer()` ->
 * `enqueue()` -> `drain()` -> `process_comments()` — against a live Jev
 * provider, and reports latency, request batching, and routing against the
 * route each fixture should receive.
 *
 * Usage (from the WordPress root):
 *
 *     wp eval-file wp-content/plugins/jev-comment-triage/bin/benchmark.php [--url=<site-url>]
 *
 * Supported positional flags:
 *
 *     keep           Leave the benchmark post and comments in place for inspection.
 *     hold-policy    Do not relax `comment_moderation`; every verdict is capped at "hold".
 *     repeat=<n>     Submit the fixture set <n> times (default 1) for a larger sample.
 *     spam-cache     Enable the spam-verdict cache (default is shadow mode).
 *
 * This script writes to the database (one post, N comments, spam-cache entries
 * and counters) and removes all of it again, including when it exits early.
 * To make the publish path observable it overrides `comment_moderation` for
 * its own process only, so the stored setting and other visitors' comments are
 * never affected. An interrupted run (Ctrl-C) can leave the fixture post and
 * comments behind; re-running the script removes a leftover fixture post.
 * Run it on a development install only.
 *
 * @package JevCommentTriage
 */

namespace Soderlind\Plugin\JevCommentTriage\Benchmark;

use Soderlind\Plugin\JevCommentTriage as Triage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once __DIR__ . '/fixtures.php';

const SLUG = 'jct-benchmark-fixture-post';

/**
 * Spam-cache key for a fixture as submitted, matching what the plugin computes.
 *
 * @param array{author:string,content:string,url:string,email:string} $fixture Fixture.
 */
function cache_key( array $fixture ): string {
	$commentdata = [
		'comment_content'      => $fixture['content'],
		'comment_author'       => $fixture['author'],
		'comment_author_url'   => $fixture['url'],
		'comment_author_email' => $fixture['email'],
	];

	return Triage\SPAM_CACHE_PREFIX . Triage\spam_cache_key( Triage\comment_payload( $commentdata, Triage\count_links( $fixture['content'] ) ) );
}

/**
 * Positional arguments supplied by `wp eval-file`.
 *
 * @var array $args
 */
$flags         = isset( $args ) && is_array( $args ) ? $args : [];
$keep          = in_array( 'keep', $flags, true );
$relax_holding = ! in_array( 'hold-policy', $flags, true );
$repeat        = 1;

if ( in_array( 'spam-cache', $flags, true ) ) {
	add_filter( 'jct_spam_cache', '__return_true' );
}

foreach ( $flags as $flag ) {
	if ( is_string( $flag ) && str_starts_with( $flag, 'repeat=' ) ) {
		$repeat = max( 1, (int) substr( $flag, 7 ) );
	}
}

if ( ! class_exists( Triage\Assessment::class ) ) {
	\WP_CLI::error( 'jev-comment-triage is not active on this site.' );
}

if ( ! Triage\provider_ready() ) {
	\WP_CLI::error( 'The Jev provider is not configured; an end-to-end benchmark is not possible.' );
}

$thresholds = Triage\thresholds();

\WP_CLI::log( 'Site:                 ' . home_url() . ' (blog ' . get_current_blog_id() . ')' );
\WP_CLI::log( 'Thresholds:           ' . wp_json_encode( $thresholds ) );
\WP_CLI::log( 'Comments per request: ' . (int) apply_filters( 'jct_comments_per_request', Triage\COMMENTS_PER_REQUEST ) );

$original_moderation = get_option( 'comment_moderation' );
$original_stats      = Triage\cache_stats();

if ( $relax_holding && '1' === (string) $original_moderation ) {
	// Override in memory only: nothing is stored, and other requests keep the
	// site's real policy while the benchmark runs.
	add_filter( 'pre_option_comment_moderation', static fn() => '0' );
	\WP_CLI::log( 'Moderation policy:    relaxed for this process only (comment_moderation 1 -> 0) so the publish path is reachable' );
} else {
	\WP_CLI::log( 'Moderation policy:    comment_moderation=' . wp_json_encode( $original_moderation ) );
}

$fixtures    = fixtures();
$post_ids    = [];
$comment_ids = [];
$cache_keys  = [];

// Any cached verdicts for the fixtures would skew the run, so start clean.
foreach ( $fixtures as $fixture ) {
	delete_site_transient( cache_key( $fixture ) );
}

// Track every verdict the run caches, so cleanup removes exactly those even if
// WordPress altered a comment's text on the way in.
add_action(
	'setted_site_transient',
	static function ( $transient ) use ( &$cache_keys ): void {
		if ( str_starts_with( (string) $transient, Triage\SPAM_CACHE_PREFIX ) ) {
			$cache_keys[ (string) $transient ] = true;
		}
	}
);

// Runs from `finally` and, because WP_CLI::error() and fatal errors exit
// without running `finally`, also on shutdown. The flag makes it run once.
$cleaned = false;
$cleanup = static function () use ( &$cleaned, &$comment_ids, &$post_ids, &$cache_keys, $original_stats, $keep ): void {
	if ( $cleaned ) {
		return;
	}
	$cleaned = true;

	// Put the network counters back to their values before the run.
	foreach ( $original_stats as $counter => $value ) {
		$option = Triage\CACHE_STATS_OPTION . '_' . $counter;
		if ( 0 === $value ) {
			delete_site_option( $option );
		} else {
			update_site_option( $option, $value );
		}
	}

	if ( $keep ) {
		\WP_CLI::log( 'Fixture posts #' . implode( ', #', $post_ids ) . ' and their comments were retained.' );
		return;
	}

	foreach ( array_keys( $cache_keys ) as $transient ) {
		delete_site_transient( $transient );
	}
	foreach ( $comment_ids as $comment_id ) {
		wp_delete_comment( $comment_id, true );
	}
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
	\WP_CLI::log( '' );
	\WP_CLI::log( 'Cleaned up the fixture posts, comments, cache entries, and counters. Pass "keep" to retain them.' );
};
register_shutdown_function( $cleanup );

try {
	// Remove fixture posts left behind by an interrupted earlier run.
	$cleanup_rounds = max( $repeat, 10 );
	for ( $round = 1; $round <= $cleanup_rounds; $round++ ) {
		$existing = get_page_by_path( SLUG . '-' . $round, OBJECT, 'post' );
		if ( $existing instanceof \WP_Post ) {
			wp_delete_post( (int) $existing->ID, true );
		}
	}

	// One post per round, all with the same content: rounds 2+ replay round 1's
	// exact submissions on another post, which is what the spam cache is for.
	for ( $round = 1; $round <= $repeat; $round++ ) {
		$fixture_post_id = wp_insert_post(
			[
				'post_title'     => 'Caching strategies for WordPress multisite',
				'post_name'      => SLUG . '-' . $round,
				'post_content'   => post_body(),
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
			],
			true
		);

		if ( is_wp_error( $fixture_post_id ) ) {
			\WP_CLI::error( 'Could not create a fixture post: ' . $fixture_post_id->get_error_message() );
		}
		$post_ids[ $round ] = (int) $fixture_post_id;
	}
	\WP_CLI::log( 'Fixture posts:        #' . implode( ', #', $post_ids ) );

	$submit_times = [];
	$expected     = [];

	\WP_CLI::log( '' );
	\WP_CLI::log( sprintf( 'Phase 1: submitting %d comments through wp_new_comment() (%d x %d fixtures)...', count( $fixtures ) * $repeat, $repeat, count( $fixtures ) ) );

	// A benchmark posts far faster than a human, so WordPress's flood control
	// would reject every comment after the first.
	add_filter( 'wp_is_comment_flood', '__return_false', 999 );

	// enqueue() nudges WP-Cron with a loopback request, which would start a
	// second drain in another process while this one forces its own passes.
	// Make the nudge throttle look active, in memory only, so this process is
	// the only drain and every count is exact.
	add_filter( 'pre_transient_jct_drain_soon', static fn() => 1 );

	for ( $round = 1; $round <= $repeat; $round++ ) {
		foreach ( $fixtures as $fixture ) {
			$start          = hrtime( true );
			$comment_id     = wp_new_comment(
				[
					'comment_post_ID'      => $post_ids[ $round ],
					'comment_author'       => $fixture['author'],
					'comment_author_email' => $fixture['email'],
					'comment_author_url'   => $fixture['url'],
					'comment_content'      => $fixture['content'],
					'comment_type'         => 'comment',
					'user_id'              => 0,
				],
				true
			);
			$submit_times[] = (float) ( hrtime( true ) - $start );

			if ( is_wp_error( $comment_id ) ) {
				\WP_CLI::warning( 'Comment rejected by WordPress: ' . $comment_id->get_error_message() );
				continue;
			}

			$comment_ids[]           = (int) $comment_id;
			$expected[ $comment_id ] = $fixture;
		}
	}

	$pending = 0;
	foreach ( $comment_ids as $comment_id ) {
		if ( '' !== (string) get_comment_meta( $comment_id, Triage\PENDING_META, true ) ) {
			++$pending;
		}
	}
	remove_filter( 'wp_is_comment_flood', '__return_false', 999 );
	\WP_CLI::log( sprintf( 'Deferred for triage:  %d / %d', $pending, count( $comment_ids ) ) );

	// Time each Jev request at the HTTP layer; with batching, one request
	// answers many comments, so per-comment event deltas are meaningless.
	$requests = [];
	$opened   = null;
	add_filter(
		'pre_http_request',
		static function ( $pre, $request_args, $url ) use ( &$opened ) {
			if ( str_contains( (string) $url, '/systemone' ) ) {
				$opened = hrtime( true );
			}
			return $pre;
		},
		10,
		3
	);
	add_action(
		'http_api_debug',
		static function ( $response, $context, $transport, $request_args, $url ) use ( &$opened, &$requests ): void {
			if ( null === $opened || ! str_contains( (string) $url, '/systemone' ) ) {
				return;
			}
			$sent       = json_decode( (string) ( $request_args['body'] ?? '' ), true );
			$received   = is_array( $response ) ? json_decode( (string) ( $response['body'] ?? '' ), true ) : null;
			$requests[] = [
				'ns'        => (float) ( hrtime( true ) - $opened ),
				'questions' => is_array( $sent['questions'] ?? null ) ? count( $sent['questions'] ) : 0,
				'tokens_in' => (int) ( $received['usage']['input_tokens'] ?? 0 ),
				'ok'        => ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ),
			];
			$opened     = null;
		},
		10,
		5
	);

	$triaged = [];
	add_action(
		'jct_triaged',
		static function ( $comment_id ) use ( &$triaged ): void {
			$triaged[ (int) $comment_id ] = true;
		},
		10,
		1
	);

	\WP_CLI::log( '' );
	\WP_CLI::log( 'Phase 2: draining the queue (live Jev API calls)...' );

	// Each WP-Cron tick is its own request with a cold runtime cache. Looping
	// drain() inside one long-lived CLI process keeps stale comment and meta
	// entries in memory, so reset the runtime cache between passes to model
	// production faithfully.
	$reset_runtime_cache = static function (): void {
		if ( function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} else {
			wp_cache_flush();
		}
	};

	$ours          = array_flip( $comment_ids );
	$comment_total = count( $comment_ids );
	$drain_start   = hrtime( true );
	$passes        = 0;
	$stalled       = 0;

	// drain() handles a bounded batch per tick, so keep ticking until our own
	// comments are all triaged — exactly what repeated WP-Cron runs would do.
	// A stalled pass usually means the provider is throttling, so back off.
	do {
		$reset_runtime_cache();
		delete_transient( Triage\LOCK_KEY );
		$before = count( array_intersect_key( $triaged, $ours ) );
		Triage\drain();
		++$passes;
		$after = count( array_intersect_key( $triaged, $ours ) );

		\WP_CLI::log( sprintf( 'Pass %d: %d new (%d/%d of this run complete)', $passes, $after - $before, $after, count( $comment_ids ) ) );

		if ( $after > $before ) {
			$stalled = 0;
		} else {
			++$stalled;
			\WP_CLI::log( sprintf( 'Pass %d made no progress (provider throttling?); backing off %ds...', $passes, $stalled * 5 ) );
			sleep( $stalled * 5 );
		}
	} while ( $after < $comment_total && $stalled < 4 && $passes < 50 );

	$drain_total = (float) ( hrtime( true ) - $drain_start );
	$foreign     = count( $triaged ) - count( array_intersect_key( $triaged, $ours ) );
	if ( $foreign > 0 ) {
		\WP_CLI::log( sprintf( 'Note: the site had %d pre-existing pending comment(s); they were triaged too.', $foreign ) );
	}

	$reset_runtime_cache();

	$labels       = [
		'1'    => 'approved',
		'0'    => 'held',
		'spam' => 'spam',
	];
	$rows         = [];
	$matrix       = [];
	$matched      = 0;
	$unsafe       = 0;
	$false_spam   = 0;
	$retried      = 0;
	$still_queued = 0;

	foreach ( $comment_ids as $comment_id ) {
		$meta    = get_comment_meta( $comment_id, Triage\META_KEY, true );
		$fixture = $expected[ $comment_id ];
		$routed  = is_array( $meta ) && isset( $meta['decision'] ) ? ( $labels[ (string) $meta['decision'] ] ?? (string) $meta['decision'] ) : 'NOT TRIAGED';

		if ( $routed === $fixture['route'] ) {
			++$matched;
		}
		// The publication invariant: nothing may be approved unless it should be.
		if ( 'approved' === $routed && 'approved' !== $fixture['route'] ) {
			++$unsafe;
		}
		if ( 'spam' === $routed && 'approved' === $fixture['route'] ) {
			++$false_spam;
		}
		if ( (int) get_comment_meta( $comment_id, Triage\ATTEMPTS_META, true ) > 0 ) {
			++$retried;
		}
		if ( '' !== (string) get_comment_meta( $comment_id, Triage\PENDING_META, true ) ) {
			++$still_queued;
		}

		$key            = $fixture['kind'] . '|' . $fixture['route'] . '|' . $routed;
		$matrix[ $key ] = ( $matrix[ $key ] ?? 0 ) + 1;

		$relevance = is_array( $meta ) ? (array) ( $meta['relevance'] ?? [] ) : [];
		$rows[]    = [
			'kind'      => $fixture['kind'],
			'expected'  => $fixture['route'],
			'routed'    => $routed,
			'ok'        => $routed === $fixture['route'] ? 'yes' : 'NO',
			'relevance' => isset( $relevance['choice'] ) ? sprintf( '%s@%.2f', $relevance['choice'], (float) $relevance['confidence'] ) : '-',
			'spam'      => is_array( $meta ) && isset( $meta['spam'] ) ? number_format( (float) $meta['spam'], 2 ) : '-',
			'abuse'     => is_array( $meta ) && isset( $meta['abusive'] ) ? number_format( (float) $meta['abusive'], 2 ) : '-',
			'source'    => is_array( $meta ) ? (string) ( $meta['source'] ?? '?' ) : '-',
		];
	}

	\WP_CLI::log( '' );
	if ( 1 === $repeat ) {
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'kind', 'expected', 'routed', 'ok', 'relevance', 'spam', 'abuse', 'source' ] );
	} else {
		ksort( $matrix );
		$summary = [];
		foreach ( $matrix as $key => $count ) {
			[ $kind, $route, $routed ] = explode( '|', $key, 3 );
			$summary[]                 = [
				'kind'     => $kind,
				'expected' => $route,
				'routed'   => $routed,
				'count'    => $count,
			];
		}
		\WP_CLI\Utils\format_items( 'table', $summary, [ 'kind', 'expected', 'routed', 'count' ] );
	}

	$submit     = stats( $submit_times );
	$request_ns = array_column( $requests, 'ns' );
	$per_req    = stats( $request_ns );
	$total      = count( $comment_ids );
	$questions  = array_sum( array_column( $requests, 'questions' ) );
	$tokens_in  = array_sum( array_column( $requests, 'tokens_in' ) );
	$failed     = count( array_filter( $requests, static fn( $r ) => ! $r['ok'] ) );

	\WP_CLI::log( '' );
	\WP_CLI::log( '--- Latency ---' );
	\WP_CLI::log( sprintf( 'Submission (no API):  min %sms  mean %sms  p95 %sms  max %sms', ms( $submit['min'] ), ms( $submit['mean'] ), ms( $submit['p95'] ), ms( $submit['max'] ) ) );
	\WP_CLI::log( sprintf( 'Jev request:          min %sms  mean %sms  p95 %sms  max %sms', ms( $per_req['min'] ), ms( $per_req['mean'] ), ms( $per_req['p95'] ), ms( $per_req['max'] ) ) );
	\WP_CLI::log( sprintf( 'Requests:             %d (%d failed), %d questions, %s questions/request', count( $requests ), $failed, $questions, count( $requests ) > 0 ? number_format( $questions / count( $requests ), 1 ) : '0' ) );
	\WP_CLI::log( sprintf( 'Input tokens:         %d total, %s per comment', $tokens_in, count( $triaged ) > 0 ? number_format( $tokens_in / count( $triaged ), 0 ) : '0' ) );
	\WP_CLI::log( sprintf( 'Drain:                %d comments in %sms over %d pass(es) (%s comments/sec, %sms API time per comment)', count( $triaged ), ms( $drain_total ), $passes, $drain_total > 0 ? number_format( count( $triaged ) / ( $drain_total / 1e9 ), 2 ) : '0', count( $triaged ) > 0 ? ms( array_sum( $request_ns ) / count( $triaged ) ) : '0' ) );

	\WP_CLI::log( '' );
	\WP_CLI::log( '--- Routing ---' );
	\WP_CLI::log( sprintf( 'Routed as expected:                 %d / %d (%s%%)', $matched, $total, $total > 0 ? number_format( 100 * $matched / $total, 1 ) : '0' ) );
	\WP_CLI::log( sprintf( 'Published but should not be:        %d  (publication invariant)', $unsafe ) );
	\WP_CLI::log( sprintf( 'Good comments sent to spam:         %d', $false_spam ) );
	\WP_CLI::log( sprintf( 'Hit a provider error at least once: %d', $retried ) );
	\WP_CLI::log( sprintf( 'Still queued when the run ended:    %d', $still_queued ) );

	$stats = Triage\cache_stats();
	\WP_CLI::log( '' );
	\WP_CLI::log( '--- Spam cache (' . ( apply_filters( 'jct_spam_cache', false ) ? 'enabled' : 'shadow mode' ) . ') ---' );
	foreach ( $stats as $counter => $value ) {
		\WP_CLI::log( sprintf( '%-8s %d', $counter . ':', $value - (int) ( $original_stats[ $counter ] ?? 0 ) ) );
	}
} finally {
	$cleanup();
}
