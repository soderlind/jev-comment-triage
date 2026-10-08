<?php
/**
 * Tests for the background handlers process_comment() and process_comments().
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function Soderlind\Plugin\JevCommentTriage\column_text;
use function Soderlind\Plugin\JevCommentTriage\process_comment;
use function Soderlind\Plugin\JevCommentTriage\process_comments;

/**
 * Build a WP_Comment stand-in.
 */
function make_comment( string $content, int $id = 10, int $post_id = 20 ): WP_Comment {
	return new WP_Comment(
		[
			'comment_ID'      => $id,
			'comment_post_ID' => $post_id,
			'comment_content' => $content,
		]
	);
}

/**
 * The three answers Jev returns for one comment.
 *
 * @return array<string, array<string, mixed>>
 */
function answers_for( string $prefix, string $relevance, float $confidence, float $spam = 0.02, float $abusive = 0.02 ): array {
	$probabilities               = array_fill_keys( [ 'on_topic', 'off_topic', 'unclear' ], 0.05 );
	$probabilities[ $relevance ] = 0.90;

	return [
		$prefix . 'relevance' => [
			'type'          => 'choice',
			'choice'        => $relevance,
			'probabilities' => $probabilities,
			'confidence'    => $confidence,
		],
		$prefix . 'spam'      => [
			'type' => 'noul',
			'noul' => $spam,
		],
		$prefix . 'abusive'   => [
			'type' => 'noul',
			'noul' => $abusive,
		],
	];
}

/**
 * Stub a single-comment Jev response.
 */
function stub_evaluate( string $relevance, float $confidence, float $spam = 0.02, float $abusive = 0.02 ): void {
	Functions\when( 'AiProviderForJev\\evaluate' )->justReturn(
		[ 'answers' => answers_for( 'c0_', $relevance, $confidence, $spam, $abusive ) ]
	);
}

/**
 * Stub the stored base decision ('1' = WordPress would approve, '0' = hold).
 */
function stub_base( string $base ): void {
	Functions\when( 'get_comment_meta' )->alias(
		static fn( $id, $key, $single = true ) => \Soderlind\Plugin\JevCommentTriage\BASE_META === $key ? $base : ''
	);
}

/**
 * Make apply_filters return $overrides[ hook ] for the given hooks, the default otherwise.
 *
 * @param array<string, mixed> $overrides Hook name => value.
 */
function stub_filters( array $overrides ): void {
	Functions\when( 'apply_filters' )->alias(
		static fn( string $hook, $value = null ) => array_key_exists( $hook, $overrides ) ? $overrides[ $hook ] : $value
	);
}

beforeEach(
	function (): void {
		Functions\when( 'get_post' )->justReturn(
			(object) [
				'post_title'   => 'A useful post',
				'post_content' => 'Detailed post content.',
			]
		);
		Functions\when( 'add_comment_meta' )->justReturn( true );
		Functions\when( 'delete_comment_meta' )->justReturn( true );
		Functions\when( 'get_site_transient' )->justReturn( false );
		Functions\when( 'set_site_transient' )->justReturn( true );
		Functions\when( 'get_site_option' )->alias(
			static fn( string $option, $default = false ) => \Soderlind\Plugin\JevCommentTriage\CACHE_SALT_OPTION === $option ? 'test-salt' : $default
		);
	}
);

afterEach(
	function (): void {
		unset( $GLOBALS['wpdb'] );
	}
);

/**
 * Minimal $wpdb that records the SQL it is asked to run.
 */
final class RecordingWpdb {
	public string $sitemeta = 'wp_sitemeta';
	public string $options  = 'wp_options';
	/** @var list<string> */
	public array $queries = [];

	public function prepare( string $query, mixed ...$args ): string {
		return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
	}

	public function query( string $sql ): int {
		$this->queries[] = $sql;
		return 1;
	}
}

it(
	'marks a spammy comment as spam',
	function (): void {
		stub_base( '1' );
		stub_evaluate( 'on_topic', 0.95, 0.97 );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'spam' );

		process_comment( make_comment( 'Nice post! Cheap backlinks at http://x.example' ) );
	}
);

it(
	'sends the post once as state and the comment inside three typed questions',
	function (): void {
		stub_base( '1' );
		Functions\expect( 'AiProviderForJev\\evaluate' )
		->once()
		->with(
			\Mockery::on(
				static fn( array $state ): bool =>
					[
						'post' => [
							'title'   => 'A useful post',
							'content' => 'Detailed post content.',
						],
					] === $state
			),
			\Mockery::on(
				static fn( array $questions ): bool =>
					[ 'c0_relevance', 'c0_spam', 'c0_abusive' ] === array_keys( $questions )
					&& 'choice' === $questions['c0_relevance']['type']
					&& [ 'on_topic', 'off_topic', 'unclear' ] === array_keys( $questions['c0_relevance']['criteria'] )
					&& 'noul' === $questions['c0_spam']['type']
					&& 'noul' === $questions['c0_abusive']['type']
					&& 'A relevant reply.' === $questions['c0_spam']['instructions']['comment']['content']
					&& str_contains( $questions['c0_relevance']['instructions']['question'], '`post.content`' )
			)
		)
		->andReturn( [ 'answers' => answers_for( 'c0_', 'on_topic', 0.92 ) ] );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'approve' );

		process_comment( make_comment( 'A relevant reply.' ) );
	}
);

it(
	'approves a confident, clean on-topic comment WordPress would allow',
	function (): void {
		stub_base( '1' );
		stub_evaluate( 'on_topic', 0.90 );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'approve' );

		process_comment( make_comment( 'Great post, thanks for sharing.' ) );
	}
);

it(
	'holds a clean comment when WordPress would moderate',
	function (): void {
		stub_base( '0' );
		stub_evaluate( 'on_topic', 0.90 );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'hold' );

		process_comment( make_comment( 'Great post, thanks for sharing.' ) );
	}
);

it(
	'holds an abusive on-topic comment for a person',
	function (): void {
		stub_base( '1' );
		stub_evaluate( 'on_topic', 0.99, 0.04, 0.98 );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'hold' );

		process_comment( make_comment( 'This advice is garbage and so are you.' ) );
	}
);

it(
	'holds an off-topic comment regardless of base',
	function (): void {
		stub_base( '1' );
		stub_evaluate( 'off_topic', 0.90 );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'hold' );

		process_comment( make_comment( 'Can anyone recommend a plumber?' ) );
	}
);

it(
	'holds a low-confidence on-topic comment',
	function (): void {
		stub_base( '1' );
		stub_evaluate( 'on_topic', 0.40 );
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'hold' );

		process_comment( make_comment( 'This might relate to the post.' ) );
	}
);

it(
	'applies the base to empty content without calling the API',
	function (): void {
		stub_base( '1' );
		Functions\expect( 'AiProviderForJev\\evaluate' )->never();
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'approve' );

		process_comment( make_comment( '   ' ) );
	}
);

it(
	'retries on an API error and leaves the status unchanged',
	function (): void {
		stub_base( '1' );
		Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( new WP_Error( 'jev_api_error', 'boom' ) );
		Functions\expect( 'update_comment_meta' )->once(); // Attempts incremented.
		Functions\expect( 'wp_set_comment_status' )->never();

		process_comment( make_comment( 'something that fails' ) );
	}
);

it(
	'treats a missing answer as a failure for that comment',
	function (): void {
		stub_base( '1' );
		$answers = answers_for( 'c0_', 'on_topic', 0.95 );
		unset( $answers['c0_abusive'] );
		Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( [ 'answers' => $answers ] );
		Functions\expect( 'update_comment_meta' )->once();
		Functions\expect( 'wp_set_comment_status' )->never();

		process_comment( make_comment( 'Malformed response test.' ) );
	}
);

describe(
	'batching',
	function (): void {
		it(
			'judges comments on the same post in one request',
			function (): void {
				stub_base( '1' );
				Functions\expect( 'AiProviderForJev\\evaluate' )
				->once()
				->with( \Mockery::type( 'array' ), \Mockery::on( static fn( array $q ): bool => 6 === count( $q ) ) )
				->andReturn(
					[ 'answers' => answers_for( 'c0_', 'on_topic', 0.95 ) + answers_for( 'c1_', 'on_topic', 0.95, 0.99 ) ]
				);
				Functions\expect( 'wp_set_comment_status' )->once()->with( 11, 'approve' );
				Functions\expect( 'wp_set_comment_status' )->once()->with( 12, 'spam' );

				process_comments( [ make_comment( 'Relevant.', 11 ), make_comment( 'Spammy.', 12 ) ] );
			}
		);

		it(
			'sends one request per post',
			function (): void {
				stub_base( '1' );
				Functions\expect( 'AiProviderForJev\\evaluate' )
				->twice()
				->andReturn( [ 'answers' => answers_for( 'c0_', 'on_topic', 0.95 ) ] );
				Functions\expect( 'wp_set_comment_status' )->twice();

				process_comments( [ make_comment( 'On post A.', 11, 1 ), make_comment( 'On post B.', 12, 2 ) ] );
			}
		);

		it(
			'splits a large group by jct_comments_per_request',
			function (): void {
				stub_base( '1' );
				stub_filters( [ 'jct_comments_per_request' => 1 ] );
				Functions\expect( 'AiProviderForJev\\evaluate' )
				->twice()
				->andReturn( [ 'answers' => answers_for( 'c0_', 'on_topic', 0.95 ) ] );
				Functions\expect( 'wp_set_comment_status' )->twice();

				process_comments( [ make_comment( 'One.', 11 ), make_comment( 'Two.', 12 ) ] );
			}
		);

		it(
			'retries only the comment whose answers are malformed',
			function (): void {
				stub_base( '1' );
				$answers                    = answers_for( 'c0_', 'on_topic', 0.95 ) + answers_for( 'c1_', 'on_topic', 0.95 );
				$answers['c1_spam']['noul'] = 1.7;
				Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( [ 'answers' => $answers ] );
				Functions\expect( 'update_comment_meta' )->once()->with( 12, \Soderlind\Plugin\JevCommentTriage\ATTEMPTS_META, 1 );
				Functions\expect( 'wp_set_comment_status' )->once()->with( 11, 'approve' );

				process_comments( [ make_comment( 'Fine.', 11 ), make_comment( 'Broken.', 12 ) ] );
			}
		);

		it(
			'isolates a comment that makes the request be rejected',
			function (): void {
				stub_base( '1' );
				$calls = 0;
				Functions\when( 'AiProviderForJev\\evaluate' )->alias(
					static function ( array $state, array $questions ) use ( &$calls ) {
						++$calls;
						$contents = array_map( static fn( array $q ): string => $q['instructions']['comment']['content'], $questions );
						if ( in_array( 'Too large.', $contents, true ) ) {
							return new WP_Error( 'jev_api_error', 'The request failed validation.', [ 'status' => 422 ] );
						}
						$answers = [];
						foreach ( array_keys( $questions ) as $id ) {
							$answers += answers_for( substr( $id, 0, (int) strpos( $id, '_' ) + 1 ), 'on_topic', 0.95 );
						}
						return [ 'answers' => $answers ];
					}
				);
				Functions\expect( 'update_comment_meta' )->once()->with( 13, \Soderlind\Plugin\JevCommentTriage\ATTEMPTS_META, 1 );
				Functions\expect( 'wp_set_comment_status' )->times( 3 );

				process_comments(
					[ make_comment( 'Fine.', 11 ), make_comment( 'Also fine.', 12 ), make_comment( 'Too large.', 13 ), make_comment( 'Fine too.', 14 ) ]
				);

				// Whole chunk, then each half, then each comment of the failing half.
				expect( $calls )->toBe( 5 );
			}
		);

		it(
			'does not split a chunk on a configuration error such as 404',
			function (): void {
				stub_base( '1' );
				Functions\expect( 'AiProviderForJev\\evaluate' )
				->once()
				->andReturn( new WP_Error( 'jev_api_error', 'Not found.', [ 'status' => 404 ] ) );
				Functions\expect( 'update_comment_meta' )->twice();
				Functions\expect( 'wp_set_comment_status' )->never();

				process_comments( [ make_comment( 'One.', 11 ), make_comment( 'Two.', 12 ) ] );
			}
		);

		it(
			'does not split a chunk when the provider is down',
			function (): void {
				stub_base( '1' );
				Functions\expect( 'AiProviderForJev\\evaluate' )
				->once()
				->andReturn( new WP_Error( 'jev_api_error', 'Overloaded.', [ 'status' => 529 ] ) );
				Functions\expect( 'update_comment_meta' )->twice();
				Functions\expect( 'wp_set_comment_status' )->never();

				process_comments( [ make_comment( 'One.', 11 ), make_comment( 'Two.', 12 ) ] );
			}
		);
	}
);

describe(
	'spam cache',
	function (): void {
		it(
			'stores a confident spam verdict',
			function (): void {
				stub_base( '1' );
				stub_evaluate( 'off_topic', 0.95, 0.99 );
				$stored = [];
				Functions\when( 'set_site_transient' )->alias(
					static function ( string $key, $value ) use ( &$stored ): bool {
						$stored[ $key ] = $value;
						return true;
					}
				);
				Functions\when( 'wp_set_comment_status' )->justReturn( true );

				process_comment( make_comment( 'Buy pills now.' ) );

				expect( $stored )->toHaveCount( 1 );
				expect( array_key_first( $stored ) )->toStartWith( \Soderlind\Plugin\JevCommentTriage\SPAM_CACHE_PREFIX );
				expect( current( $stored ) )->toBe(
					[
						'version' => 2,
						'spam'    => 0.99,
						'abusive' => 0.02,
					]
				);
			}
		);

		it(
			'does not store a spam verdict below the cache bar',
			function (): void {
				stub_base( '1' );
				stub_evaluate( 'off_topic', 0.95, 0.95 );
				$stored = 0;
				Functions\when( 'set_site_transient' )->alias(
					static function () use ( &$stored ): bool {
						++$stored;
						return true;
					}
				);
				Functions\when( 'wp_set_comment_status' )->justReturn( true );

				process_comment( make_comment( 'Buy pills now.' ) );

				expect( $stored )->toBe( 0 );
			}
		);

		it(
			'still asks Jev in shadow mode and counts the would-be hit',
			function (): void {
				stub_base( '1' );
				Functions\when( 'get_site_transient' )->justReturn(
					[
						'version' => 2,
						'spam'    => 0.99,
						'abusive' => 0.01,
					]
				);
				Functions\expect( 'AiProviderForJev\\evaluate' )
				->once()
				->andReturn( [ 'answers' => answers_for( 'c0_', 'off_topic', 0.95, 0.99 ) ] );
				$GLOBALS['wpdb'] = new RecordingWpdb();
				Functions\when( 'is_multisite' )->justReturn( true );
				Functions\when( 'get_current_network_id' )->justReturn( 1 );
				Functions\when( 'add_network_option' )->justReturn( true );
				Functions\when( 'wp_cache_delete' )->justReturn( true );
				Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'spam' );

				process_comment( make_comment( 'Buy pills now.' ) );

				// One atomic SQL increment per counter, no read-modify-write.
				expect( $GLOBALS['wpdb']->queries )->toBe(
					[
						"UPDATE wp_sitemeta SET meta_value = meta_value + 1 WHERE site_id = 1 AND meta_key = 'jct_spam_cache_stats_lookups'",
						"UPDATE wp_sitemeta SET meta_value = meta_value + 1 WHERE site_id = 1 AND meta_key = 'jct_spam_cache_stats_hits'",
						"UPDATE wp_sitemeta SET meta_value = meta_value + 1 WHERE site_id = 1 AND meta_key = 'jct_spam_cache_stats_agreed'",
					]
				);
			}
		);

		it(
			'skips Jev for known spam when enabled',
			function (): void {
				stub_base( '1' );
				stub_filters( [ 'jct_spam_cache' => true ] );
				Functions\when( 'get_site_transient' )->justReturn(
					[
						'version' => 2,
						'spam'    => 0.99,
						'abusive' => 0.01,
					]
				);
				Functions\expect( 'AiProviderForJev\\evaluate' )->never();
				Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'spam' );

				process_comment( make_comment( 'Buy pills now.' ) );
			}
		);
	}
);

it(
	'holds a short comment without calling Jev when jct_min_words is set',
	function (): void {
		stub_base( '1' );
		stub_filters( [ 'jct_min_words' => 3 ] );
		Functions\expect( 'AiProviderForJev\\evaluate' )->never();
		Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'hold' );

		process_comment( make_comment( 'ok thanks' ) );
	}
);

describe(
	'column_text',
	function (): void {
		beforeEach(
			function (): void {
				Functions\stubTranslationFunctions();
			}
		);

		it(
			'labels assessments stored by earlier versions',
			function (): void {
				expect(
					column_text(
						[
							'is_spam'  => 0.9,
							'decision' => 'spam',
						]
					)
				)->toBe( 'Earlier version → spam' );
			}
		);

		it(
			'summarizes the three judgments',
			function (): void {
				$data = [
					'version'   => 2,
					'source'    => 'jev',
					'relevance' => [
						'choice'     => 'on_topic',
						'confidence' => 0.99,
					],
					'spam'      => 0.03,
					'abusive'   => 0.98,
					'decision'  => '0',
				];

				expect( column_text( $data ) )->toBe( 'on-topic 0.99 · spam 0.03 · abuse 0.98 → held' );
			}
		);
	}
);
