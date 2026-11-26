<?php
/**
 * Head-to-head benchmark: Jev vs Akismet on spam detection.
 *
 * Akismet answers one question (spam or not), so the engines are compared on
 * that axis. Jev's other routes (held for off-topic, unclear, or abusive
 * comments) are reported, but not counted as spam calls.
 *
 * Two corpora:
 *
 * - fixtures: the 20 labelled comments in bin/fixtures.php. They were written
 *   alongside Jev, so treat this result as biased toward it.
 * - dataset:  an independent, public corpus neither engine was tuned on, the
 *   UCI YouTube Spam Collection (CC BY 4.0, 1,956 comments on five music
 *   videos, labelled spam/ham). A balanced, deterministic sample is drawn per
 *   video and class. Each video becomes a post that gives Jev its context.
 *
 * Each engine runs as its plugin does in production:
 *
 * - Jev:     `Assessment::assess()` with up to 20 comments per request, routed
 *            by `decide()` as if the site would otherwise approve.
 * - Akismet: `Akismet::auto_check_comment()`, one request per comment, with
 *            `is_test=true` so the run never trains the Akismet account.
 *
 * Neither engine changes a comment, because no comment is stored: only the
 * context posts are created, and they are removed afterwards.
 *
 * Usage (from the WordPress root):
 *
 *     curl -sSLo /tmp/yt.zip https://archive.ics.uci.edu/static/public/380/youtube+spam+collection.zip
 *     unzip -o /tmp/yt.zip -d /tmp/ytspam
 *     wp eval-file wp-content/plugins/jev-comment-triage/benchmark/compare-akismet.php \
 *         dataset=/tmp/ytspam sample=200 seed=jct out=/tmp/jev-akismet.csv --url=<site-url>
 *
 * Flags: dataset=<dir> (omit to run the fixtures only), sample=<n> (balanced
 * across videos and classes, default 200), seed=<s> (sample selection, default
 * "jct"), out=<file> (per-comment CSV for auditing), keep (retain the posts).
 *
 * Limitations: a CLI run has no real visitor IP, browser user agent, or
 * JavaScript signals, which Akismet uses and Jev does not. Every comment is
 * sent with a stable per-author documentation-range IP (198.51.100.0/24) and
 * a fixed desktop browser user agent.
 *
 * @package JevCommentTriage
 */

namespace Soderlind\Plugin\JevCommentTriage\Benchmark;

use Soderlind\Plugin\JevCommentTriage as Triage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/bin/fixtures.php';

const AKISMET_SLUG = 'jct-akismet-fixture-post';
const BROWSER_UA   = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';

/**
 * Context posts for the YouTube Spam Collection, keyed by file name prefix.
 *
 * @return array<string, array{title:string, content:string}>
 */
function youtube_posts(): array {
	$video = static fn( string $title, string $about ): array => [
		'title'   => $title,
		'content' => "<p>{$about}</p><p>Watch the official music video below and share what you think of the song in the comments.</p>",
	];

	return [
		'Youtube01-Psy'       => $video( 'PSY - GANGNAM STYLE (강남스타일) M/V', 'The official music video for "Gangnam Style" by the South Korean singer PSY, the 2012 K-pop single known for its horse-riding dance.' ),
		'Youtube02-KatyPerry' => $video( 'Katy Perry - Roar (Official)', 'The official music video for "Roar", the 2013 lead single from Katy Perry\'s album Prism, set in a jungle.' ),
		'Youtube03-LMFAO'     => $video( 'LMFAO - Party Rock Anthem ft. Lauren Bennett, GoonRock', 'The official music video for "Party Rock Anthem" by the electronic duo LMFAO, the 2011 dance single famous for the shuffle.' ),
		'Youtube04-Eminem'    => $video( 'Eminem - Love The Way You Lie ft. Rihanna', 'The official music video for "Love The Way You Lie" by Eminem featuring Rihanna, the 2010 single about an abusive relationship.' ),
		'Youtube05-Shakira'   => $video( 'Shakira - Waka Waka (This Time for Africa)', 'The official music video for "Waka Waka (This Time for Africa)" by Shakira, the official song of the 2010 FIFA World Cup.' ),
	];
}

/**
 * Draw a balanced, deterministic sample from the YouTube Spam Collection.
 *
 * @param string $dir   Directory with the extracted CSV files.
 * @param int    $total Total comments to draw.
 * @param string $seed  Selection seed.
 * @return array<string, list<array{kind:string, spam:bool, author:string, email:string, url:string, content:string, id:string}>>
 */
function youtube_sample( string $dir, int $total, string $seed ): array {
	$posts = youtube_posts();
	$per   = max( 1, intdiv( $total, count( $posts ) * 2 ) );
	$out   = [];

	foreach ( array_keys( $posts ) as $video ) {
		$file = rtrim( $dir, '/' ) . "/{$video}.csv";
		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( "Missing dataset file: {$file}" );
		}

		$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$header = fgetcsv( $handle, null, ',', '"', '' );
		$rows   = [
			'0' => [],
			'1' => [],
		];
		while ( false !== ( $line = fgetcsv( $handle, null, ',', '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$row = array_combine( $header, $line );
			if ( is_array( $row ) && isset( $rows[ $row['CLASS'] ] ) ) {
				$rows[ $row['CLASS'] ][] = $row;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		foreach ( $rows as $class => $list ) {
			// Order by a seeded hash of the comment ID: stable across runs and PHP versions.
			usort( $list, static fn( $a, $b ) => strcmp( md5( $seed . $a['COMMENT_ID'] ), md5( $seed . $b['COMMENT_ID'] ) ) );
			foreach ( array_slice( $list, 0, $per ) as $row ) {
				$out[ $video ][] = [
					'kind'    => '1' === (string) $class ? 'spam' : 'ham',
					'spam'    => '1' === (string) $class,
					'author'  => html_entity_decode( (string) $row['AUTHOR'], ENT_QUOTES | ENT_HTML5 ),
					'email'   => '',
					'url'     => '',
					'content' => html_entity_decode( (string) $row['CONTENT'], ENT_QUOTES | ENT_HTML5 ),
					'id'      => (string) $row['COMMENT_ID'],
				];
			}
		}
	}

	return $out;
}

/**
 * Wilson score interval for a proportion.
 *
 * @param int $hits Successes.
 * @param int $n    Trials.
 * @return array{0:float,1:float}
 */
function wilson( int $hits, int $n ): array {
	if ( 0 === $n ) {
		return [ 0.0, 0.0 ];
	}
	$z      = 1.959964;
	$p      = $hits / $n;
	$denom  = 1 + $z * $z / $n;
	$centre = ( $p + $z * $z / ( 2 * $n ) ) / $denom;
	$half   = $z * sqrt( $p * ( 1 - $p ) / $n + $z * $z / ( 4 * $n * $n ) ) / $denom;

	return [ max( 0.0, $centre - $half ), min( 1.0, $centre + $half ) ];
}

/**
 * Format a proportion with its 95% Wilson interval.
 *
 * @param int $hits Successes.
 * @param int $n    Trials.
 */
function pct( int $hits, int $n ): string {
	if ( 0 === $n ) {
		return '-';
	}
	[ $lo, $hi ] = wilson( $hits, $n );

	return sprintf( '%d/%d = %.1f%% [%.1f–%.1f]', $hits, $n, 100 * $hits / $n, 100 * $lo, 100 * $hi );
}

/**
 * Two-sided exact McNemar test on the discordant pairs.
 *
 * @param int $b Pairs only the first engine got right.
 * @param int $c Pairs only the second engine got right.
 */
function mcnemar( int $b, int $c ): float {
	$n = $b + $c;
	if ( 0 === $n ) {
		return 1.0;
	}
	$tail  = 0.0;
	$lower = min( $b, $c );
	for ( $k = 0; $k <= $lower; $k++ ) {
		$tail += exp( log_choose( $n, $k ) - $n * log( 2 ) );
	}

	return min( 1.0, 2 * $tail );
}

/**
 * Natural log of the binomial coefficient.
 *
 * @param int $n Trials.
 * @param int $k Successes.
 */
function log_choose( int $n, int $k ): float {
	$sum = 0.0;
	for ( $i = 1; $i <= $k; $i++ ) {
		$sum += log( $n - $k + $i ) - log( $i );
	}

	return $sum;
}

/**
 * Area under the ROC curve for a score (Mann–Whitney, ties count half).
 *
 * @param list<array{score:float, spam:bool}> $points Scored items.
 */
function auc( array $points ): ?float {
	$pos = array_values( array_filter( $points, static fn( $p ) => $p['spam'] ) );
	$neg = array_values( array_filter( $points, static fn( $p ) => ! $p['spam'] ) );
	if ( [] === $pos || [] === $neg ) {
		return null;
	}
	$wins = 0.0;
	foreach ( $pos as $p ) {
		foreach ( $neg as $q ) {
			$wins += $p['score'] > $q['score'] ? 1.0 : ( $p['score'] === $q['score'] ? 0.5 : 0.0 );
		}
	}

	return $wins / ( count( $pos ) * count( $neg ) );
}

/**
 * Positional arguments supplied by `wp eval-file`.
 *
 * @var array $args
 */
$flags   = isset( $args ) && is_array( $args ) ? $args : [];
$options = [
	'dataset' => '',
	'sample'  => '200',
	'seed'    => 'jct',
	'out'     => '',
];
foreach ( $flags as $flag ) {
	if ( is_string( $flag ) && str_contains( $flag, '=' ) ) {
		[ $name, $value ] = explode( '=', $flag, 2 );
		if ( array_key_exists( $name, $options ) ) {
			$options[ $name ] = $value;
		}
	}
}
$keep = in_array( 'keep', $flags, true );

if ( ! class_exists( Triage\Assessment::class ) || ! Triage\provider_ready() ) {
	\WP_CLI::error( 'jev-comment-triage must be active with a configured Jev provider.' );
}
if ( ! class_exists( \Akismet::class ) || ! \Akismet::get_api_key() ) {
	\WP_CLI::error( 'Akismet must be active with an API key.' );
}
if ( 'valid' !== \Akismet::verify_key( \Akismet::get_api_key() ) ) {
	\WP_CLI::error( 'The Akismet API key is not valid.' );
}

// --- Corpora: each group shares one context post.
$groups = [
	'fixtures' => [
		'post'     => [
			'title'   => 'Caching strategies for WordPress multisite',
			'content' => post_body(),
		],
		'comments' => array_map(
			static fn( array $f ): array => [
				'kind'    => $f['kind'],
				'spam'    => 'spam' === $f['route'],
				'author'  => $f['author'],
				// The fixtures' synthetic @example.test addresses use a reserved
				// TLD that Akismet treats as spam on its own, so neither engine
				// gets an email; the YouTube rows have none either.
				'email'   => '',
				'url'     => $f['url'],
				'content' => $f['content'],
				'id'      => $f['kind'],
				'route'   => $f['route'],
			],
			fixtures()
		),
		'corpus'   => 'fixtures',
	],
];
if ( '' !== $options['dataset'] ) {
	$video_posts = youtube_posts();
	foreach ( youtube_sample( $options['dataset'], max( 10, (int) $options['sample'] ), $options['seed'] ) as $video => $video_comments ) {
		$groups[ $video ] = [
			'post'     => $video_posts[ $video ],
			'comments' => $video_comments,
			'corpus'   => 'youtube',
		];
	}
}

// --- Time every provider request at the HTTP layer.
$http   = [
	'jev'     => [],
	'akismet' => [],
];
$opened = [];
add_filter(
	'pre_http_request',
	static function ( $pre, $request_args, $url ) use ( &$opened ) {
		$opened[ (string) $url ][] = hrtime( true );
		return $pre;
	},
	10,
	3
);
add_action(
	'http_api_debug',
	static function ( $response, $context, $transport, $request_args, $url ) use ( &$opened, &$http ): void {
		$url   = (string) $url;
		$start = empty( $opened[ $url ] ) ? null : array_pop( $opened[ $url ] );
		if ( null === $start ) {
			return;
		}
		if ( str_contains( $url, '/systemone' ) ) {
			$engine = 'jev';
		} elseif ( str_contains( $url, 'akismet.com' ) && str_contains( $url, 'comment-check' ) ) {
			$engine = 'akismet';
		} else {
			return;
		}
		$http[ $engine ][] = [
			'ns' => (float) ( hrtime( true ) - $start ),
			'ok' => ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ),
		];
	},
	10,
	5
);

// --- Akismet request shaping: test mode, and stable stand-ins for the visitor
// signals a CLI process does not have. Nothing else is changed.
$akismet_ip = '';
add_filter(
	'akismet_request_args',
	static function ( $request, $endpoint ) use ( &$akismet_ip ) {
		if ( 'comment-check' !== $endpoint ) {
			return $request;
		}
		$request['is_test']               = 'true';
		$request['user_ip']               = $akismet_ip;
		$request['user_agent']            = BROWSER_UA;
		$request['referrer']              = $request['permalink'] ?? home_url();
		$request['akismet_comment_nonce'] = 'inactive';
		unset( $request['callback'] );
		return $request;
	},
	10,
	2
);

$spam_count_before = get_option( 'akismet_spam_count' );
$post_ids          = [];
$cleaned           = false;
$cleanup           = static function () use ( &$cleaned, &$post_ids, $keep, $spam_count_before ): void {
	if ( $cleaned ) {
		return;
	}
	$cleaned = true;

	if ( false === $spam_count_before ) {
		delete_option( 'akismet_spam_count' );
	} else {
		update_option( 'akismet_spam_count', $spam_count_before );
	}

	if ( $keep ) {
		\WP_CLI::log( 'Context posts #' . implode( ', #', $post_ids ) . ' were retained.' );
		return;
	}
	foreach ( $post_ids as $context_id ) {
		wp_delete_post( (int) $context_id, true );
	}
	\WP_CLI::log( '' );
	\WP_CLI::log( 'Cleaned up the context posts. Pass "keep" to retain them.' );
};
register_shutdown_function( $cleanup );

try {
	foreach ( array_keys( $groups ) as $index => $group ) {
		$slug     = AKISMET_SLUG . '-' . sanitize_title( $group );
		$existing = get_page_by_path( $slug, OBJECT, 'post' );
		if ( $existing instanceof \WP_Post ) {
			wp_delete_post( (int) $existing->ID, true );
		}
		$context_id = wp_insert_post(
			[
				'post_title'     => $groups[ $group ]['post']['title'],
				'post_name'      => $slug,
				'post_content'   => $groups[ $group ]['post']['content'],
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'comment_status' => 'open',
			],
			true
		);
		if ( is_wp_error( $context_id ) ) {
			\WP_CLI::error( 'Could not create a context post: ' . $context_id->get_error_message() );
		}
		$post_ids[ $group ] = (int) $context_id;
	}

	$total = array_sum( array_map( static fn( $g ) => count( $g['comments'] ), $groups ) );
	\WP_CLI::log( 'Site:     ' . home_url() . ' (blog ' . get_current_blog_id() . ')' );
	\WP_CLI::log( sprintf( 'Corpus:   %d comments in %d groups (seed "%s")', $total, count( $groups ), $options['seed'] ) );
	\WP_CLI::log( 'Jev:      ' . \AiProviderForJev\Settings\SettingsManager::instance()->get_model() . ', thresholds ' . wp_json_encode( Triage\thresholds() ) );
	\WP_CLI::log( 'Akismet:  ' . AKISMET_VERSION . ', is_test=true' );

	// --- Jev: one request per chunk of comments on the same post.
	\WP_CLI::log( '' );
	\WP_CLI::log( 'Jev: assessing...' );
	$assessment  = new Triage\Assessment( static fn( array $state, array $questions ) => \AiProviderForJev\evaluate( $state, $questions ) );
	$per_request = max( 1, (int) apply_filters( 'jct_comments_per_request', Triage\COMMENTS_PER_REQUEST ) );
	$jev         = [];
	$jev_start   = hrtime( true );
	foreach ( $groups as $group => $data ) {
		$items = [];
		foreach ( $data['comments'] as $i => $c ) {
			$items[ "{$group}#{$i}" ] = [
				'commentdata' => [
					'comment_content'      => $c['content'],
					'comment_author'       => $c['author'],
					'comment_author_url'   => $c['url'],
					'comment_author_email' => $c['email'],
				],
				'link_count'  => Triage\count_links( $c['content'] ),
			];
		}
		foreach ( array_chunk( $items, $per_request, true ) as $chunk ) {
			$jev += $assessment->assess(
				[
					'post_title'   => $data['post']['title'],
					'post_content' => $data['post']['content'],
				],
				$chunk
			);
		}
	}
	$jev_wall = (float) ( hrtime( true ) - $jev_start );

	// --- Akismet: one request per comment, sequentially.
	\WP_CLI::log( 'Akismet: checking (one request per comment)...' );
	$akismet       = [];
	$akismet_start = hrtime( true );
	foreach ( $groups as $group => $data ) {
		foreach ( $data['comments'] as $i => $c ) {
			$akismet_ip = '198.51.100.' . ( 1 + hexdec( substr( md5( $c['author'] ), 0, 2 ) ) % 254 );
			$result     = \Akismet::auto_check_comment(
				[
					'comment_post_ID'      => $post_ids[ $group ],
					'comment_author'       => $c['author'],
					'comment_author_email' => $c['email'],
					'comment_author_url'   => $c['url'],
					'comment_content'      => $c['content'],
					'comment_type'         => 'comment',
					'comment_parent'       => 0,
					'user_ID'              => 0,
				],
				'rest_api'
			);
			// A "discard" verdict comes back as an error in the REST context; it is spam.
			$akismet[ "{$group}#{$i}" ] = is_wp_error( $result )
				? [
					'result' => 'true',
					'tip'    => 'discard',
				]
				: [
					'result' => (string) ( $result['akismet_result'] ?? '' ),
					'tip'    => (string) ( $result['akismet_pro_tip'] ?? '' ),
				];
		}
	}
	$akismet_wall = (float) ( hrtime( true ) - $akismet_start );

	// --- Score per corpus on the spam axis.
	$labels = [
		'1'    => 'approved',
		'0'    => 'held',
		'spam' => 'spam',
	];
	$blank  = static fn(): array => [
		'tp'     => 0,
		'fp'     => 0,
		'tn'     => 0,
		'fn'     => 0,
		'errors' => 0,
	];
	$score  = [];
	$routes = [];
	$pairs  = [];
	$auc    = [];
	$csv    = [];

	foreach ( $groups as $group => $data ) {
		$corpus = $data['corpus'];
		foreach ( $data['comments'] as $i => $c ) {
			$key = "{$group}#{$i}";
			$j   = $jev[ $key ] ?? new \WP_Error( 'missing', 'No answer.' );
			$a   = $akismet[ $key ];

			$jev_route                     = is_wp_error( $j ) ? 'error' : $labels[ (string) Triage\decide( $j, '1' ) ];
			$jev_spam                      = 'spam' === $jev_route;
			$ak_ok                         = in_array( $a['result'], [ 'true', 'false' ], true );
			$ak_spam                       = 'true' === $a['result'];
			$want                          = $c['spam'];
			$score[ $corpus ]['jev']     ??= $blank();
			$score[ $corpus ]['akismet'] ??= $blank();

			foreach ( [
				'jev'     => [ 'error' !== $jev_route, $jev_spam ],
				'akismet' => [ $ak_ok, $ak_spam ],
			] as $engine => [ $ok, $said ] ) {
				if ( ! $ok ) {
					++$score[ $corpus ][ $engine ]['errors'];
					continue;
				}
				++$score[ $corpus ][ $engine ][ $want ? ( $said ? 'tp' : 'fn' ) : ( $said ? 'fp' : 'tn' ) ];
			}

			$routes[ $corpus ][ $want ? 'spam' : 'ham' ][ $jev_route ] = ( $routes[ $corpus ][ $want ? 'spam' : 'ham' ][ $jev_route ] ?? 0 ) + 1;

			if ( 'error' !== $jev_route && $ak_ok ) {
				$jr                           = $jev_spam === $want;
				$ar                           = $ak_spam === $want;
				$pairs[ $corpus ]['jev_only'] = ( $pairs[ $corpus ]['jev_only'] ?? 0 ) + (int) ( $jr && ! $ar );
				$pairs[ $corpus ]['ak_only']  = ( $pairs[ $corpus ]['ak_only'] ?? 0 ) + (int) ( $ar && ! $jr );
			}
			if ( ! is_wp_error( $j ) ) {
				$auc[ $corpus ][] = [
					'score' => (float) $j['spam'],
					'spam'  => $want,
				];
			}

			$csv[] = [
				'corpus'    => $corpus,
				'group'     => $group,
				'id'        => $c['id'],
				'label'     => $want ? 'spam' : 'ham',
				'jev_route' => $jev_route,
				'jev_spam'  => is_wp_error( $j ) ? '' : sprintf( '%.3f', $j['spam'] ),
				'jev_abuse' => is_wp_error( $j ) ? '' : sprintf( '%.3f', $j['abusive'] ),
				'jev_rel'   => is_wp_error( $j ) ? $j->get_error_message() : $j['relevance']['choice'],
				'akismet'   => $ak_ok ? ( $ak_spam ? 'spam' : 'ham' ) : 'error:' . $a['result'],
				'ak_tip'    => $a['tip'],
				'content'   => $c['content'],
			];
		}
	}

	if ( '' !== $options['out'] ) {
		$handle = fopen( $options['out'], 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $handle, array_keys( $csv[0] ), ',', '"', '' );
		foreach ( $csv as $row ) {
			fputcsv( $handle, $row, ',', '"', '' );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		\WP_CLI::log( 'Per-comment results: ' . $options['out'] );
	}

	foreach ( $score as $corpus => $engines ) {
		$table = [];
		foreach ( $engines as $engine => $st ) {
			$precision = $st['tp'] + $st['fp'] > 0 ? $st['tp'] / ( $st['tp'] + $st['fp'] ) : 0.0;
			$recall    = $st['tp'] + $st['fn'] > 0 ? $st['tp'] / ( $st['tp'] + $st['fn'] ) : 0.0;
			$table[]   = [
				'engine'               => 'jev' === $engine ? 'Jev' : 'Akismet',
				'spam caught (recall)' => pct( $st['tp'], $st['tp'] + $st['fn'] ),
				'ham → spam (FPR)'     => pct( $st['fp'], $st['fp'] + $st['tn'] ),
				'precision'            => sprintf( '%.3f', $precision ),
				'F1'                   => sprintf( '%.3f', $precision + $recall > 0 ? 2 * $precision * $recall / ( $precision + $recall ) : 0 ),
				'accuracy'             => pct( $st['tp'] + $st['tn'], $st['tp'] + $st['tn'] + $st['fp'] + $st['fn'] ),
				'errors'               => $st['errors'],
			];
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( "=== {$corpus}: spam detection (95% Wilson intervals) ===" );
		\WP_CLI\Utils\format_items( 'table', $table, array_keys( $table[0] ) );

		$b = $pairs[ $corpus ]['jev_only'] ?? 0;
		$c = $pairs[ $corpus ]['ak_only'] ?? 0;
		\WP_CLI::log( sprintf( 'Paired: only Jev right %d, only Akismet right %d; exact McNemar p = %.4g', $b, $c, mcnemar( $b, $c ) ) );
		$area = auc( $auc[ $corpus ] ?? [] );
		if ( null !== $area ) {
			\WP_CLI::log( sprintf( 'Jev spam-score ROC AUC: %.3f (threshold-free; Akismet is binary)', $area ) );
		}
		foreach ( [ 'spam', 'ham' ] as $label ) {
			$dist = $routes[ $corpus ][ $label ] ?? [];
			ksort( $dist );
			\WP_CLI::log( sprintf( 'Jev routes for %-4s %s', $label . ':', wp_json_encode( $dist ) ) );
		}
	}

	$timing = [];
	foreach ( [
		'Jev'     => [ $http['jev'], $jev_wall ],
		'Akismet' => [ $http['akismet'], $akismet_wall ],
	] as $engine => [ $requests, $wall ] ) {
		$req      = stats( array_column( $requests, 'ns' ) );
		$timing[] = [
			'engine'          => $engine,
			'requests'        => count( $requests ),
			'failed'          => count( array_filter( $requests, static fn( $r ) => ! $r['ok'] ) ),
			'req mean/p95 ms' => ms( $req['mean'] ) . '/' . ms( $req['p95'] ),
			'ms/comment'      => ms( $wall / max( 1, $total ) ),
		];
	}
	\WP_CLI::log( '' );
	\WP_CLI::log( '=== Latency (all corpora) ===' );
	\WP_CLI\Utils\format_items( 'table', $timing, array_keys( $timing[0] ) );
} finally {
	$cleanup();
}
