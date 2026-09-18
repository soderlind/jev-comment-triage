<?php
/**
 * Tests for the async background handler process_comment().
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function JevCommentTriage\process_comment;

/**
 * Build a WP_Comment stand-in.
 */
function make_comment( string $content, int $id = 10 ): WP_Comment {
	return new WP_Comment( [ 'comment_ID' => $id, 'comment_content' => $content ] );
}

/**
 * Stub the Jev API response with the given answer values.
 */
function stub_evaluate( float $spam, float $scam, float $toxicity ): void {
	Functions\when( 'AiProviderForJev\\evaluate' )->justReturn(
		[
			'answers' => [
				'is_spam'  => [ 'noul' => $spam ],
				'is_scam'  => [ 'noul' => $scam ],
				'toxicity' => [ 'score' => $toxicity ],
			],
		]
	);
}

beforeEach( function (): void {
	Functions\when( 'get_option' )->justReturn( 2 );
	Functions\when( 'get_comment_meta' )->justReturn( '' );
	Functions\when( 'add_comment_meta' )->justReturn( true );
	Functions\when( 'delete_comment_meta' )->justReturn( true );
} );

it( 'marks a spammy comment as spam', function (): void {
	stub_evaluate( 0.99, 0.80, 0.10 );
	Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'spam' );

	process_comment( make_comment( 'buy cheap stuff http://x.example' ) );
} );

it( 'approves a clean comment', function (): void {
	stub_evaluate( 0.02, 0.01, 0.00 );
	Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'approve' );

	process_comment( make_comment( 'Great post, thanks for sharing.' ) );
} );

it( 'holds a toxic comment', function (): void {
	stub_evaluate( 0.05, 0.02, 1.90 );
	Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'hold' );

	process_comment( make_comment( 'you are an idiot' ) );
} );

it( 'approves empty content without calling the API', function (): void {
	Functions\expect( 'AiProviderForJev\\evaluate' )->never();
	Functions\expect( 'wp_set_comment_status' )->once()->with( 10, 'approve' );

	process_comment( make_comment( '   ' ) );
} );

it( 'retries on an API error and leaves the status unchanged', function (): void {
	Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( new WP_Error( 'jev_api_error', 'boom' ) );
	Functions\expect( 'update_comment_meta' )->once(); // attempts incremented
	Functions\expect( 'wp_set_comment_status' )->never();

	process_comment( make_comment( 'something that fails' ) );
} );
