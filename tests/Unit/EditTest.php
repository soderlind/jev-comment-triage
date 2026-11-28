<?php
/**
 * Tests for re-triaging edited comments.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function Soderlind\Plugin\JevCommentTriage\hold_edited_comment;
use function Soderlind\Plugin\JevCommentTriage\queue_edited_comment;

beforeEach(
	function (): void {
		Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( [] );
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( true );

		$this->meta = [];
		Functions\when( 'get_comment_meta' )->alias( fn( $id, $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'update_comment_meta' )->alias(
			function ( $id, $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		);
		Functions\when( 'delete_comment_meta' )->alias(
			function ( $id, $key ): void {
				unset( $this->meta[ $key ] );
			}
		);
	}
);

/**
 * Comment data as wp_update_comment() passes it to the filter.
 *
 * @return array<string, mixed>
 */
function edited_comment( string $content, $approved = 1 ): array {
	return [
		'comment_ID'       => 7,
		'comment_type'     => 'comment',
		'comment_content'  => $content,
		'comment_approved' => $approved,
		'user_id'          => 0,
	];
}

it(
	'holds a changed approved comment and requeues it to be approved again',
	function (): void {
		$this->meta = [ '_jev_triage' => [ 'decision' => '1' ] ];

		$data = hold_edited_comment( edited_comment( "Now it's spam" ), [ 'comment_content' => "It\\'s fine" ] );
		queue_edited_comment( 7 );

		expect( $data['comment_approved'] )->toBe( 0 )
			->and( $this->meta )->toBe(
				[
					'_jct_pending' => 1,
					'_jct_base'    => '1',
				]
			);
	}
);

it(
	'ignores an edit that leaves the slashed old content unchanged',
	function (): void {
		$data = hold_edited_comment( edited_comment( "It's fine" ), [ 'comment_content' => "It\\'s fine" ] );
		queue_edited_comment( 7 );

		expect( $data['comment_approved'] )->toBe( 1 )->and( $this->meta )->toBe( [] );
	}
);

it(
	'never lets an edit release a held comment',
	function (): void {
		$this->meta = [ '_jev_triage' => [ 'decision' => '0' ] ];

		hold_edited_comment( edited_comment( 'Changed', '0' ), [ 'comment_content' => 'Original' ] );
		queue_edited_comment( 7 );

		expect( $this->meta['_jct_base'] )->toBe( '0' );
	}
);

it(
	'does not let an edit that also approves release a held comment',
	function (): void {
		$this->meta = [ '_jev_triage' => [ 'decision' => '0' ] ];

		hold_edited_comment(
			edited_comment( 'Changed', '1' ),
			[
				'comment_content'  => 'Original',
				'comment_approved' => '0',
			]
		);
		queue_edited_comment( 7 );

		expect( $this->meta['_jct_base'] )->toBe( '0' );
	}
);

it(
	'keeps the original base of a comment still waiting in the queue',
	function (): void {
		$this->meta = [
			'_jct_pending' => 1,
			'_jct_base'    => '1',
		];

		hold_edited_comment( edited_comment( 'Changed', '0' ), [ 'comment_content' => 'Original' ] );
		queue_edited_comment( 7 );

		expect( $this->meta['_jct_base'] )->toBe( '1' );
	}
);

it(
	'does not triage edits by comment moderators',
	function (): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$data = hold_edited_comment( edited_comment( 'Changed' ), [ 'comment_content' => 'Original' ] );
		queue_edited_comment( 7 );

		expect( $data['comment_approved'] )->toBe( 1 )->and( $this->meta )->toBe( [] );
	}
);

it(
	'leaves edits of spam comments alone',
	function (): void {
		$data = hold_edited_comment( edited_comment( 'Changed', 'spam' ), [ 'comment_content' => 'Original' ] );

		expect( $data['comment_approved'] )->toBe( 'spam' );
	}
);
