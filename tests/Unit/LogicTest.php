<?php
/**
 * Tests for the pure decision logic (count_links, decide, thresholds, is_trusted).
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function JevCommentTriage\comment_payload;
use function JevCommentTriage\count_links;
use function JevCommentTriage\decide;
use function JevCommentTriage\is_request_rejection;
use function JevCommentTriage\is_trusted;
use function JevCommentTriage\spam_cache_key;
use function JevCommentTriage\thresholds;
use function JevCommentTriage\word_count;

/**
 * Build an assessment in the stored shape.
 */
function assessment( string $relevance, float $confidence, float $spam = 0.02, float $abusive = 0.02 ): array {
	return [
		'version'   => 2,
		'source'    => 'jev',
		'relevance' => [ 'choice' => $relevance, 'confidence' => $confidence, 'probabilities' => [] ],
		'spam'      => $spam,
		'abusive'   => $abusive,
	];
}

it( 'counts http, https, and www links', function (): void {
	expect( count_links( 'see http://a.com and https://b.com and www.c.com' ) )->toBe( 3 );
	expect( count_links( 'no links here at all' ) )->toBe( 0 );
} );

it( 'counts words across any whitespace', function (): void {
	expect( word_count( "  ok \n thanks  " ) )->toBe( 2 );
	expect( word_count( '' ) )->toBe( 0 );
} );

describe( 'spam cache key', function (): void {
	beforeEach( function (): void {
		$salt = 'salt-a';
		Functions\when( 'get_site_option' )->alias( static function () use ( &$salt ) {
			return $salt;
		} );
		$this->salt = &$salt;
	} );

	/**
	 * Key for a comment as the plugin would build it.
	 */
	function key_for( string $content, string $name = 'Reader', string $url = '', string $email = 'reader@example.test' ): string {
		return spam_cache_key(
			comment_payload(
				[ 'comment_content' => $content, 'comment_author' => $name, 'comment_author_url' => $url, 'comment_author_email' => $email ],
				count_links( $content )
			)
		);
	}

	it( 'is stable for an identical submission', function (): void {
		expect( key_for( 'Great post! thanks' ) )->toBe( key_for( 'Great post! thanks' ) );
	} );

	it( 'changes with anything the spam question can read', function (): void {
		$plain = key_for( 'Great post! thanks' );

		expect( key_for( 'Great post! <a href="http://casino.example">thanks</a>' ) )->not->toBe( $plain );
		expect( key_for( 'GREAT POST! THANKS' ) )->not->toBe( $plain );
		expect( key_for( 'Great post! thanks', 'Cheap Pills' ) )->not->toBe( $plain );
		expect( key_for( 'Great post! thanks', 'Reader', 'http://casino.example/win' ) )->not->toBe( $plain );
		expect( key_for( 'Great post! thanks', 'Reader', '', 'other@example.test' ) )->not->toBe( $plain );
	} );

	it( 'changes when the install salt is rotated', function (): void {
		$before     = key_for( 'Great post! thanks' );
		$this->salt = 'salt-b';

		expect( key_for( 'Great post! thanks' ) )->not->toBe( $before );
	} );
} );

it( 'splits only on request-body rejections', function (): void {
	foreach ( [ 413, 422 ] as $status ) {
		expect( is_request_rejection( new WP_Error( 'jev_api_error', 'x', [ 'status' => $status ] ) ) )->toBeTrue();
	}
	foreach ( [ 400, 401, 403, 404, 405, 429, 500, 529 ] as $status ) {
		expect( is_request_rejection( new WP_Error( 'jev_api_error', 'x', [ 'status' => $status ] ) ) )->toBeFalse();
	}
	expect( is_request_rejection( new WP_Error( 'http_request_failed', 'timeout' ) ) )->toBeFalse();
} );

describe( 'decide', function (): void {
	it( 'marks a strong spam signal as spam, even when on-topic', function (): void {
		expect( decide( assessment( 'on_topic', 0.99, 0.93 ), '1' ) )->toBe( 'spam' );
	} );

	it( 'holds an abusive comment instead of marking it spam', function (): void {
		expect( decide( assessment( 'on_topic', 0.99, 0.04, 0.98 ), '1' ) )->toBe( '0' );
	} );

	it( 'keeps WordPress approval for a confident, clean on-topic comment', function (): void {
		expect( decide( assessment( 'on_topic', 0.85 ), '1' ) )->toBe( '1' );
	} );

	it( 'respects WordPress moderation for a confident, clean on-topic comment', function (): void {
		expect( decide( assessment( 'on_topic', 0.85 ), '0' ) )->toBe( '0' );
	} );

	it( 'holds an on-topic comment below the relevance threshold', function (): void {
		expect( decide( assessment( 'on_topic', 0.79 ), '1' ) )->toBe( '0' );
	} );

	it( 'holds an on-topic comment with a borderline spam signal', function (): void {
		expect( decide( assessment( 'on_topic', 0.99, 0.30 ), '1' ) )->toBe( '0' );
	} );

	it( 'holds off-topic and unclear comments', function (): void {
		expect( decide( assessment( 'off_topic', 1.0 ), '1' ) )->toBe( '0' );
		expect( decide( assessment( 'unclear', 1.0 ), '1' ) )->toBe( '0' );
	} );

	it( 'holds an assessment without judgments', function (): void {
		expect( decide( [ 'version' => 2, 'source' => 'rule' ], '1' ) )->toBe( '0' );
	} );
} );

describe( 'thresholds', function (): void {
	it( 'merges filtered values over the defaults and clamps them', function (): void {
		Functions\when( 'apply_filters' )->justReturn( [ 'spam' => 0.75, 'abusive' => 'high', 'relevance' => 2 ] );

		expect( thresholds() )->toBe( [ 'spam' => 0.75, 'abusive' => 0.50, 'relevance' => 1.0, 'clean' => 0.20 ] );
	} );

	it( 'falls back to the defaults when the filter returns a non-array', function (): void {
		Functions\when( 'apply_filters' )->justReturn( null );

		expect( thresholds() )->toBe( \JevCommentTriage\DEFAULT_THRESHOLDS );
	} );
} );

describe( 'is_trusted', function (): void {
	it( 'is false for guests', function (): void {
		expect( is_trusted( 0 ) )->toBeFalse();
	} );

	it( 'reflects the capability for logged-in users', function (): void {
		Functions\when( 'user_can' )->justReturn( true );
		expect( is_trusted( 5 ) )->toBeTrue();
	} );

	it( 'is false without the capability', function (): void {
		Functions\when( 'user_can' )->justReturn( false );
		expect( is_trusted( 5 ) )->toBeFalse();
	} );
} );
