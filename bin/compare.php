<?php
/**
 * Head-to-head benchmark: Jev vs the AI plugin's Comment Moderation analysis.
 *
 * Sends the same labelled fixtures, on the same post, to both engines and
 * scores each against the route the comment should get:
 *
 * - Jev:    `Assessment::assess()` (batched, as in production) and `decide()`.
 * - AI:     the AI plugin's `ai/comment-analysis` class, via
 *           `analyze_comment_by_id()` (one request per comment, as in
 *           production), with whatever provider the AI plugin uses (OpenAI).
 *           Routed by the experiment's default rule: hold when
 *           toxicity >= 0.7 and sentiment is negative, filtered through
 *           `wpai_comment_moderation_should_moderate`. It has no spam route.
 *           An extra "AI + value" route also holds low value scores (< 0.4,
 *           the plugin's own "Low value" band) to show the best case.
 *
 * Both engines are judged as if the site would otherwise approve the comment,
 * so the publish path is reachable. Nothing changes comment status.
 *
 * Usage (from the WordPress root):
 *
 *     wp eval-file wp-content/plugins/jev-comment-triage/bin/compare.php [repeat=<n>] [keep] --url=<site-url>
 *
 * Writes one post and its comments (plus the AI plugin's analysis meta on
 * them) and removes them again. Run it on a development install only.
 *
 * @package JevCommentTriage
 */

namespace Soderlind\Plugin\JevCommentTriage\Benchmark;

use Soderlind\Plugin\JevCommentTriage as Triage;
use WordPress\AI\Abilities\Comment_Moderation\Comment_Analysis;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once __DIR__ . '/fixtures.php';

const COMPARE_SLUG = 'jct-compare-fixture-post';

/**
 * Positional arguments supplied by `wp eval-file`.
 *
 * @var array $args
 */
$flags  = isset( $args ) && is_array( $args ) ? $args : [];
$keep   = in_array( 'keep', $flags, true );
$repeat = 1;
foreach ( $flags as $flag ) {
	if ( is_string( $flag ) && str_starts_with( $flag, 'repeat=' ) ) {
		$repeat = max( 1, (int) substr( $flag, 7 ) );
	}
}

if ( ! class_exists( Triage\Assessment::class ) || ! Triage\provider_ready() ) {
	\WP_CLI::error( 'jev-comment-triage must be active with a configured Jev provider.' );
}
if ( ! class_exists( Comment_Analysis::class ) || ! function_exists( 'WordPress\\AI\\has_ai_credentials' ) || ! \WordPress\AI\has_ai_credentials() ) {
	\WP_CLI::error( 'The AI plugin must be active with provider credentials.' );
}

// Neither plugin may act on the fixtures while they are created.
remove_all_actions( 'wp_insert_comment' );
remove_all_actions( 'comment_post' );

/*
 * Time every provider request at the HTTP layer and read its token usage.
 * Jev requests go to `/systemone`; anything else that names a model in the JSON
 * body (OpenAI, Anthropic) or the URL (Gemini `/models/<id>:generateContent`)
 * is counted as the AI plugin's provider.
 */
$http   = [
	'jev' => [],
	'ai'  => [],
];
$models = [];
$opened = [];
add_filter(
	'pre_http_request',
	static function ( $pre, $request_args, $url ) use ( &$opened ) {
		$opened[ (string) $url ] = hrtime( true );
		return $pre;
	},
	10,
	3
);
add_action(
	'http_api_debug',
	static function ( $response, $context, $transport, $request_args, $url ) use ( &$opened, &$http, &$models ): void {
		$url   = (string) $url;
		$start = $opened[ $url ] ?? null;
		unset( $opened[ $url ] );
		if ( null === $start ) {
			return;
		}
		$sent     = json_decode( (string) ( $request_args['body'] ?? '' ), true );
		$received = is_array( $response ) ? json_decode( (string) ( $response['body'] ?? '' ), true ) : null;
		$usage    = is_array( $received['usage'] ?? null ) ? $received['usage'] : [];
		$gemini   = is_array( $received['usageMetadata'] ?? null ) ? $received['usageMetadata'] : [];

		if ( str_contains( $url, '/systemone' ) ) {
			$engine = 'jev';
		} elseif ( is_array( $sent ) && isset( $sent['model'] ) ) {
			$engine = 'ai';
			$models[ wp_parse_url( $url, PHP_URL_HOST ) . ' ' . $sent['model'] ] = true;
		} elseif ( preg_match( '#/models/([^/:]+):(?:stream)?generateContent#i', $url, $match ) ) {
			$engine = 'ai';
			$models[ wp_parse_url( $url, PHP_URL_HOST ) . ' ' . $match[1] ] = true;
		} else {
			return;
		}

		$http[ $engine ][] = [
			'ns'         => (float) ( hrtime( true ) - $start ),
			'tokens_in'  => (int) ( $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? $gemini['promptTokenCount'] ?? 0 ),
			'tokens_out' => (int) ( $usage['output_tokens'] ?? $usage['completion_tokens'] ?? $gemini['candidatesTokenCount'] ?? 0 ),
			'ok'         => ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ),
		];
	},
	10,
	5
);

$fixture_post_id = 0;
$comment_ids     = [];
$cleaned         = false;
$cleanup         = static function () use ( &$cleaned, &$fixture_post_id, &$comment_ids, $keep ): void {
	if ( $cleaned ) {
		return;
	}
	$cleaned = true;
	if ( $keep ) {
		\WP_CLI::log( "Fixture post #{$fixture_post_id} and its comments were retained." );
		return;
	}
	foreach ( $comment_ids as $comment_id ) {
		wp_delete_comment( $comment_id, true );
	}
	if ( $fixture_post_id ) {
		wp_delete_post( $fixture_post_id, true );
	}
	\WP_CLI::log( '' );
	\WP_CLI::log( 'Cleaned up the fixture post and comments. Pass "keep" to retain them.' );
};
register_shutdown_function( $cleanup );

try {
	$existing = get_page_by_path( COMPARE_SLUG, OBJECT, 'post' );
	if ( $existing instanceof \WP_Post ) {
		wp_delete_post( (int) $existing->ID, true );
	}

	$fixture_post_id = wp_insert_post(
		[
			'post_title'     => 'Caching strategies for WordPress multisite',
			'post_name'      => COMPARE_SLUG,
			'post_content'   => post_body(),
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'comment_status' => 'open',
		],
		true
	);
	if ( is_wp_error( $fixture_post_id ) ) {
		\WP_CLI::error( 'Could not create the fixture post: ' . $fixture_post_id->get_error_message() );
	}

	$expected = [];
	for ( $round = 1; $round <= $repeat; $round++ ) {
		foreach ( fixtures() as $fixture ) {
			$comment_id = wp_insert_comment(
				[
					'comment_post_ID'      => $fixture_post_id,
					'comment_author'       => $fixture['author'],
					'comment_author_email' => $fixture['email'],
					'comment_author_url'   => $fixture['url'],
					'comment_content'      => $fixture['content'],
					'comment_type'         => 'comment',
					'comment_approved'     => '0',
					'user_id'              => 0,
				]
			);
			if ( $comment_id ) {
				$comment_ids[]           = (int) $comment_id;
				$expected[ $comment_id ] = $fixture;
			}
		}
	}

	\WP_CLI::log( 'Site:     ' . home_url() . ' (blog ' . get_current_blog_id() . ')' );
	\WP_CLI::log( sprintf( 'Fixtures: %d comments (%d x %d) on post #%d', count( $comment_ids ), $repeat, count( fixtures() ), $fixture_post_id ) );

	// --- Jev: one request per chunk of comments on the post, as in production.
	\WP_CLI::log( '' );
	\WP_CLI::log( 'Jev: assessing...' );
	$assessment  = new Triage\Assessment( static fn( array $state, array $questions ) => \AiProviderForJev\evaluate( $state, $questions ) );
	$postdata    = [
		'post_title'   => get_post_field( 'post_title', $fixture_post_id ),
		'post_content' => get_post_field( 'post_content', $fixture_post_id ),
	];
	$per_request = max( 1, (int) apply_filters( 'jct_comments_per_request', Triage\COMMENTS_PER_REQUEST ) );
	$jev         = [];
	$jev_start   = hrtime( true );
	foreach ( array_chunk( $comment_ids, $per_request ) as $chunk ) {
		$items = [];
		foreach ( $chunk as $comment_id ) {
			$fixture_comment      = get_comment( $comment_id );
			$items[ $comment_id ] = [
				'commentdata' => [
					'comment_content'      => $fixture_comment->comment_content,
					'comment_author'       => $fixture_comment->comment_author,
					'comment_author_url'   => $fixture_comment->comment_author_url,
					'comment_author_email' => $fixture_comment->comment_author_email,
				],
				'link_count'  => Triage\count_links( $fixture_comment->comment_content ),
			];
		}
		$jev += $assessment->assess( $postdata, $items );
	}
	$jev_wall = (float) ( hrtime( true ) - $jev_start );

	// --- AI plugin: one request per comment, sequentially, as in production.
	\WP_CLI::log( 'AI plugin: analyzing (one request per comment)...' );
	$analysis = new Comment_Analysis(
		'ai/comment-analysis',
		[
			'label'       => 'Comment Analysis',
			'description' => 'Analyzes a comment for toxicity, sentiment, and value.',
		]
	);
	$ai       = [];
	$ai_start = hrtime( true );
	foreach ( $comment_ids as $comment_id ) {
		$ai[ $comment_id ] = $analysis->analyze_comment_by_id( $comment_id );
	}
	$ai_wall = (float) ( hrtime( true ) - $ai_start );

	// --- Score.
	$labels = [
		'1'    => 'approved',
		'0'    => 'held',
		'spam' => 'spam',
	];
	$score  = static function ( array $routes ) use ( $expected ): array {
		$s = [
			'exact'        => 0,
			'blocked_ok'   => 0,
			'unsafe'       => 0,
			'good_blocked' => 0,
			'good_spam'    => 0,
			'errors'       => 0,
		];
		foreach ( $routes as $comment_id => $routed ) {
			$want = $expected[ $comment_id ]['route'];
			if ( 'error' === $routed ) {
				++$s['errors'];
				continue;
			}
			$s['exact']        += (int) ( $routed === $want );
			$s['blocked_ok']   += (int) ( 'approved' !== $want && 'approved' !== $routed );
			$s['unsafe']       += (int) ( 'approved' !== $want && 'approved' === $routed );
			$s['good_blocked'] += (int) ( 'approved' === $want && 'approved' !== $routed );
			$s['good_spam']    += (int) ( 'approved' === $want && 'spam' === $routed );
		}
		return $s;
	};

	$jev_routes   = [];
	$ai_routes    = [];
	$aiv_routes   = [];
	$rows         = [];
	$value_config = \WordPress\AI\Experiments\Comment_Moderation\Comment_Moderation::get_value_score_config();
	$low_value    = (float) $value_config['low']['max'];

	foreach ( $comment_ids as $comment_id ) {
		$j = $jev[ $comment_id ] ?? new \WP_Error( 'missing', 'No answer.' );
		$a = $ai[ $comment_id ];

		$jev_routes[ $comment_id ] = is_wp_error( $j ) ? 'error' : $labels[ (string) Triage\decide( $j, '1' ) ];

		if ( is_wp_error( $a ) ) {
			$ai_routes[ $comment_id ]  = 'error';
			$aiv_routes[ $comment_id ] = 'error';
		} else {
			$hold                      = (bool) apply_filters( 'wpai_comment_moderation_should_moderate', $a['toxicity_score'] >= 0.7 && 'negative' === $a['sentiment'], $a, $comment_id );
			$ai_routes[ $comment_id ]  = $hold ? 'held' : 'approved';
			$aiv_routes[ $comment_id ] = ( $hold || $a['value_score'] < $low_value ) ? 'held' : 'approved';
		}

		$relevance = is_wp_error( $j ) ? [] : (array) ( $j['relevance'] ?? [] );
		$rows[]    = [
			'kind'     => $expected[ $comment_id ]['kind'],
			'expected' => $expected[ $comment_id ]['route'],
			'jev'      => $jev_routes[ $comment_id ] . ( $jev_routes[ $comment_id ] === $expected[ $comment_id ]['route'] ? '' : ' ✗' ),
			'jev rel'  => isset( $relevance['choice'] ) ? sprintf( '%s@%.2f', $relevance['choice'], (float) $relevance['confidence'] ) : '-',
			'jev s/a'  => is_wp_error( $j ) ? $j->get_error_message() : sprintf( '%.2f/%.2f', $j['spam'], $j['abusive'] ),
			'ai'       => $ai_routes[ $comment_id ] . ( $ai_routes[ $comment_id ] === $expected[ $comment_id ]['route'] ? '' : ' ✗' ),
			'ai+value' => $aiv_routes[ $comment_id ] . ( $aiv_routes[ $comment_id ] === $expected[ $comment_id ]['route'] ? '' : ' ✗' ),
			'ai t/s/v' => is_wp_error( $a ) ? $a->get_error_message() : sprintf( '%.2f/%s/%.2f', $a['toxicity_score'], substr( $a['sentiment'], 0, 3 ), $a['value_score'] ),
		];
	}

	\WP_CLI::log( '' );
	if ( 1 === $repeat ) {
		\WP_CLI\Utils\format_items( 'table', $rows, array_keys( $rows[0] ) );
	}

	$total  = count( $comment_ids );
	$should = count( array_filter( $expected, static fn( $f ) => 'approved' !== $f['route'] ) );
	$good   = $total - $should;
	$table  = [];
	foreach ( [
		'Jev'                 => [ $score( $jev_routes ), $http['jev'], $jev_wall ],
		'AI plugin (default)' => [ $score( $ai_routes ), $http['ai'], $ai_wall ],
		'AI + low-value hold' => [ $score( $aiv_routes ), $http['ai'], $ai_wall ],
	] as $engine => [ $scored, $requests, $wall ] ) {
		$req     = stats( array_column( $requests, 'ns' ) );
		$table[] = [
			'engine'                => $engine,
			'exact route'           => sprintf( '%d/%d', $scored['exact'], $total ),
			'kept from publishing'  => sprintf( '%d/%d', $scored['blocked_ok'], $should ),
			'published, should not' => $scored['unsafe'],
			'good comments held'    => sprintf( '%d/%d', $scored['good_blocked'], $good ),
			'good → spam'           => $scored['good_spam'],
			'errors'                => $scored['errors'],
			'requests'              => count( $requests ),
			'req mean/p95 ms'       => ms( $req['mean'] ) . '/' . ms( $req['p95'] ),
			'ms/comment'            => ms( $wall / max( 1, $total ) ),
			'tokens in/out per cmt' => sprintf( '%d/%d', array_sum( array_column( $requests, 'tokens_in' ) ) / max( 1, $total ), array_sum( array_column( $requests, 'tokens_out' ) ) / max( 1, $total ) ),
		];
	}

	\WP_CLI::log( '' );
	\WP_CLI::log( 'AI plugin provider/model: ' . ( [] === $models ? 'unknown' : implode( ', ', array_keys( $models ) ) ) );
	\WP_CLI::log( 'Jev model:                ' . \AiProviderForJev\Settings\SettingsManager::instance()->get_model() );
	\WP_CLI::log( '' );
	\WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );
} finally {
	$cleanup();
}
