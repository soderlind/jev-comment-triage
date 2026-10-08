<?php
/**
 * Tests for the Assessment module interface.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Soderlind\Plugin\JevCommentTriage\Assessment;

/**
 * Build the three answers returned for one comment.
 *
 * @return array<string, array<string, mixed>>
 */
function assessment_answers( string $prefix, string $relevance = 'on_topic', float $confidence = 0.95 ): array {
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
			'noul' => 0.02,
		],
		$prefix . 'abusive'   => [
			'type' => 'noul',
			'noul' => 0.03,
		],
	];
}

/**
 * Build one item accepted by Assessment::assess().
 *
 * @return array{commentdata:array<string, mixed>, link_count:int}
 */
function assessment_item( string $content ): array {
	return [
		'commentdata' => [
			'comment_content'      => $content,
			'comment_author'       => 'Reader',
			'comment_author_url'   => '',
			'comment_author_email' => 'reader@example.test',
		],
		'link_count'  => 0,
	];
}

it(
	'sends one post and typed questions, then returns normalized keyed results',
	function (): void {
		$provider = static function ( array $state, array $questions ): array {
			expect( $state )->toBe(
				[
					'post' => [
						'title'   => 'Title',
						'content' => 'Body',
					],
				]
			);
			expect( array_keys( $questions ) )->toBe( [ 'c0_relevance', 'c0_spam', 'c0_abusive' ] );
			expect( $questions['c0_relevance']['type'] )->toBe( 'choice' );
			expect( array_keys( $questions['c0_relevance']['criteria'] ) )->toBe( [ 'on_topic', 'off_topic', 'unclear' ] );
			expect( $questions['c0_spam']['type'] )->toBe( 'noul' );
			expect( $questions['c0_abusive']['type'] )->toBe( 'noul' );
			expect( $questions['c0_spam']['instructions']['comment']['content'] )->toBe( 'Relevant reply.' );

			return [ 'answers' => assessment_answers( 'c0_' ) ];
		};

		$results = ( new Assessment( $provider ) )->assess(
			[
				'post_title'   => 'Title',
				'post_content' => 'Body',
			],
			[ 42 => assessment_item( 'Relevant reply.' ) ]
		);

		expect( $results )->toHaveKey( 42 );
		expect( $results[42] )->toBe(
			[
				'version'   => 2,
				'source'    => 'jev',
				'relevance' => [
					'choice'        => 'on_topic',
					'confidence'    => 0.95,
					'probabilities' => [
						'on_topic'  => 0.9,
						'off_topic' => 0.05,
						'unclear'   => 0.05,
					],
				],
				'spam'      => 0.02,
				'abusive'   => 0.03,
			]
		);
	}
);

it(
	'returns an error only for the comment with malformed answers',
	function (): void {
		$provider = static function (): array {
			$answers                    = assessment_answers( 'c0_' ) + assessment_answers( 'c1_' );
			$answers['c1_spam']['noul'] = 1.7;

			return [ 'answers' => $answers ];
		};

		$results = ( new Assessment( $provider ) )->assess(
			[],
			[
				11 => assessment_item( 'Fine.' ),
				12 => assessment_item( 'Broken.' ),
			]
		);

		expect( $results[11] )->toBeArray();
		expect( $results[12] )->toBeInstanceOf( WP_Error::class );
	}
);

it(
	'isolates a comment whose request is rejected',
	function ( int $status ): void {
		$calls    = 0;
		$provider = static function ( array $state, array $questions ) use ( &$calls, $status ): array|WP_Error {
			++$calls;
			$contents = array_map( static fn( array $question ): string => $question['instructions']['comment']['content'], $questions );
			if ( in_array( 'Too large.', $contents, true ) ) {
				return new WP_Error( 'jev_api_error', 'Rejected.', [ 'status' => $status ] );
			}

			$answers = [];
			foreach ( array_keys( $questions ) as $id ) {
				$answers += assessment_answers( substr( $id, 0, (int) strpos( $id, '_' ) + 1 ) );
			}
			return [ 'answers' => $answers ];
		};

		$results = ( new Assessment( $provider ) )->assess(
			[],
			[
				11 => assessment_item( 'Fine.' ),
				12 => assessment_item( 'Also fine.' ),
				13 => assessment_item( 'Too large.' ),
				14 => assessment_item( 'Fine too.' ),
			]
		);

		expect( $calls )->toBe( 5 );
		expect( $results[11] )->toBeArray();
		expect( $results[12] )->toBeArray();
		expect( $results[13] )->toBeInstanceOf( WP_Error::class );
		expect( $results[14] )->toBeArray();
	}
)->with( [ 413, 422 ] );

it(
	'does not split failures unrelated to the request body',
	function (): void {
		$calls    = 0;
		$error    = new WP_Error( 'jev_api_error', 'Unavailable.', [ 'status' => 529 ] );
		$provider = static function () use ( &$calls, $error ): WP_Error {
			++$calls;
			return $error;
		};

		$results = ( new Assessment( $provider ) )->assess(
			[],
			[
				11 => assessment_item( 'One.' ),
				12 => assessment_item( 'Two.' ),
			]
		);

		expect( $calls )->toBe( 1 );
		expect( $results )->toBe(
			[
				11 => $error,
				12 => $error,
			]
		);
	}
);
