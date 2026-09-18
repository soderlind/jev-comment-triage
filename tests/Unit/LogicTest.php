<?php
/**
 * Tests for the pure decision logic (count_links, decide, is_trusted).
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function JevCommentTriage\count_links;
use function JevCommentTriage\decide;
use function JevCommentTriage\is_trusted;

it( 'counts http, https, and www links', function (): void {
	expect( count_links( 'see http://a.com and https://b.com and www.c.com' ) )->toBe( 3 );
	expect( count_links( 'no links here at all' ) )->toBe( 0 );
} );

describe( 'decide', function (): void {
	beforeEach( function (): void {
		Functions\when( 'get_option' )->justReturn( 2 ); // comment_max_links
	} );

	it( 'flags high spam probability as spam', function (): void {
		expect( decide( [ 'is_spam' => 0.90, 'is_scam' => 0.10, 'toxicity' => 0.0 ], 0, '1' ) )->toBe( 'spam' );
	} );

	it( 'flags high scam probability as spam', function (): void {
		expect( decide( [ 'is_spam' => 0.20, 'is_scam' => 0.70, 'toxicity' => 0.0 ], 0, '1' ) )->toBe( 'spam' );
	} );

	it( 'flags link-heavy comments with a spam signal as spam', function (): void {
		expect( decide( [ 'is_spam' => 0.60, 'is_scam' => 0.10, 'toxicity' => 0.0 ], 2, '1' ) )->toBe( 'spam' );
	} );

	it( 'holds borderline spam', function (): void {
		expect( decide( [ 'is_spam' => 0.50, 'is_scam' => 0.10, 'toxicity' => 0.0 ], 0, '1' ) )->toBe( '0' );
	} );

	it( 'holds toxic comments', function (): void {
		expect( decide( [ 'is_spam' => 0.10, 'is_scam' => 0.10, 'toxicity' => 1.7 ], 0, '1' ) )->toBe( '0' );
	} );

	it( 'passes clean comments through', function (): void {
		expect( decide( [ 'is_spam' => 0.05, 'is_scam' => 0.02, 'toxicity' => 0.0 ], 0, '1' ) )->toBe( '1' );
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
