<?php
/**
 * Tests for defer(): holding, base capture, and respecting WordPress decisions.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function Soderlind\Plugin\JevCommentTriage\defer;
use function Soderlind\Plugin\JevCommentTriage\yield_ai_moderation;

beforeEach(
	function (): void {
		// Defining the function makes provider_ready() see the provider as available.
		Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( [] );
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
	}
);

it(
	'holds a comment WordPress would approve',
	function (): void {
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
	}
);

it(
	'leaves a WordPress spam decision untouched',
	function (): void {
		expect(
			defer(
				'spam',
				[
					'user_id'      => 0,
					'comment_type' => 'comment',
				]
			)
		)->toBe( 'spam' );
	}
);

it(
	'leaves a WordPress trash decision untouched',
	function (): void {
		expect(
			defer(
				'trash',
				[
					'user_id'      => 0,
					'comment_type' => 'comment',
				]
			)
		)->toBe( 'trash' );
	}
);

it(
	'skips pingbacks and trackbacks',
	function (): void {
		expect(
			defer(
				'1',
				[
					'user_id'      => 0,
					'comment_type' => 'pingback',
				]
			)
		)->toBe( '1' );
	}
);

it(
	'skips trusted users',
	function (): void {
		Functions\when( 'user_can' )->justReturn( true );
		expect(
			defer(
				'1',
				[
					'user_id'      => 5,
					'comment_type' => 'comment',
				]
			)
		)->toBe( '1' );
	}
);

it(
	'takes comments Jev held away from the AI plugin moderation experiment',
	function (): void {
		defer(
			'1',
			[
				'user_id'              => 0,
				'comment_type'         => 'comment',
				'comment_content'      => 'It\\\'s claimed',
				'comment_author_email' => 'held@b.test',
				'comment_post_ID'      => 7,
			]
		);
		$comment = new WP_Comment(
			[
				'comment_content'      => "It's claimed",
				'comment_author_email' => 'held@b.test',
				'comment_post_ID'      => '7',
			]
		);

		expect( yield_ai_moderation( [ 'comment', 'review', '' ], $comment ) )->toBe( [ 'review' ] );
	}
);

it(
	'leaves direct inserts Jev never held to the AI plugin',
	function (): void {
		$comment = new WP_Comment(
			[
				'comment_content'      => 'Inserted directly',
				'comment_author_email' => 'direct@b.test',
				'comment_post_ID'      => 7,
			]
		);

		expect( yield_ai_moderation( [ 'comment' ], $comment ) )->toBe( [ 'comment' ] );
	}
);

it(
	'leaves the AI plugin moderation alone when jct_override_ai_moderation is false',
	function (): void {
		defer(
			'1',
			[
				'user_id'              => 0,
				'comment_type'         => 'comment',
				'comment_content'      => 'opt out',
				'comment_author_email' => 'opt@b.test',
				'comment_post_ID'      => 8,
			]
		);
		Functions\when( 'apply_filters' )->justReturn( false );

		$comment = new WP_Comment(
			[
				'comment_content'      => 'opt out',
				'comment_author_email' => 'opt@b.test',
				'comment_post_ID'      => 8,
			]
		);

		expect( yield_ai_moderation( [ 'comment' ], $comment ) )->toBe( [ 'comment' ] );
	}
);
