<?php
/**
 * Tests for the pure decision logic (count_links, decide, thresholds, is_trusted).
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function JevCommentTriage\count_links;
use function JevCommentTriage\decide;
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

it( 'keys the spam cache on normalized text', function (): void {
	expect( spam_cache_key( "BUY  cheap\nPills" ) )->toBe( spam_cache_key( 'buy cheap pills' ) );
	expect( spam_cache_key( 'buy cheap pills' ) )->not->toBe( spam_cache_key( 'buy cheap watches' ) );
} );

it( 'keys the spam cache on link targets and author signals, not just visible text', function (): void {
	$plain = spam_cache_key( 'Great post! thanks', '', 'reader@example.test' );

	expect( spam_cache_key( 'Great post! <a href="http://casino.example">thanks</a>', '', 'reader@example.test' ) )->not->toBe( $plain );
	expect( spam_cache_key( 'Great post! thanks', 'http://casino.example/win', 'reader@example.test' ) )->not->toBe( $plain );
	expect( spam_cache_key( 'Great post! thanks', '', 'bot@casino.example' ) )->not->toBe( $plain );
	expect( spam_cache_key( 'Great post! thanks', '', 'other.reader@EXAMPLE.test' ) )->toBe( $plain );
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
