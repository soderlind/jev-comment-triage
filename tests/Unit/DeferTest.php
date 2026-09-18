<?php
/**
 * Tests for defer(): holding, base capture, and respecting WordPress decisions.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function JevCommentTriage\defer;

beforeEach( function (): void {
	// Defining the function makes provider_ready() see the provider as available.
	Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( [] );
} );

it( 'holds a comment WordPress would approve', function (): void {
	$result = defer(
		'1',
		[
			'user_id'              => 0,
			'comment_type'         => 'comment',
			'comment_content'      => 'hello',
			'comment_author_email' => 'a@b.test',
			'comment_post_ID'      => 1,
		]
	);

	expect( $result )->toBe( '0' );
} );

it( 'leaves a WordPress spam decision untouched', function (): void {
	expect( defer( 'spam', [ 'user_id' => 0, 'comment_type' => 'comment' ] ) )->toBe( 'spam' );
} );

it( 'leaves a WordPress trash decision untouched', function (): void {
	expect( defer( 'trash', [ 'user_id' => 0, 'comment_type' => 'comment' ] ) )->toBe( 'trash' );
} );

it( 'skips pingbacks and trackbacks', function (): void {
	expect( defer( '1', [ 'user_id' => 0, 'comment_type' => 'pingback' ] ) )->toBe( '1' );
} );

it( 'skips trusted users', function (): void {
	Functions\when( 'user_can' )->justReturn( true );
	expect( defer( '1', [ 'user_id' => 5, 'comment_type' => 'comment' ] ) )->toBe( '1' );
} );
