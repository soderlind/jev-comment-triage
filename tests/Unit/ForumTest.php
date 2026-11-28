<?php
/**
 * Tests for the bbPress integration.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

use Brain\Monkey\Functions;

use function Soderlind\Plugin\JevCommentTriage\finalize_forum_post;
use function Soderlind\Plugin\JevCommentTriage\forum_item;
use function Soderlind\Plugin\JevCommentTriage\hold_edited_forum_post;
use function Soderlind\Plugin\JevCommentTriage\hold_new_forum_post;
use function Soderlind\Plugin\JevCommentTriage\queue_edited_forum_post;
use function Soderlind\Plugin\JevCommentTriage\queue_new_forum_post;

beforeEach(
	function (): void {
		Functions\when( 'AiProviderForJev\\evaluate' )->justReturn( [] );
		Functions\when( 'bbp_get_topic_post_type' )->justReturn( 'topic' );
		Functions\when( 'bbp_get_reply_post_type' )->justReturn( 'reply' );
		Functions\when( 'bbp_get_public_status_id' )->justReturn( 'publish' );
		Functions\when( 'bbp_get_pending_status_id' )->justReturn( 'pending' );
		Functions\when( 'bbp_approve_topic' )->justReturn( true );
		Functions\when( 'user_can' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_next_scheduled' )->justReturn( true );
		Functions\when( 'wp_unslash' )->alias( 'stripslashes' );

		$this->meta = [];
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ): void {
				$this->meta[ $id ][ $key ] = $value;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $id, $key ): void {
				unset( $this->meta[ $id ][ $key ] );
			}
		);
	}
);

it(
	'holds a new topic bbPress would publish and queues it to be published',
	function (): void {
		Functions\when( 'get_post_type' )->justReturn( 'topic' );

		$data = hold_new_forum_post(
			[
				'post_type'   => 'topic',
				'post_status' => 'publish',
				'post_author' => 0,
				'post_parent' => 3,
			]
		);
		queue_new_forum_post( 10 );

		expect( $data['post_status'] )->toBe( 'pending' )
			->and( $this->meta[10] )->toBe(
				[
					'_jct_pending' => 1,
					'_jct_base'    => '1',
				]
			);
	}
);

it(
	'keeps a reply that bbPress holds from being published by triage',
	function (): void {
		Functions\when( 'get_post_type' )->justReturn( 'reply' );

		hold_new_forum_post(
			[
				'post_type'   => 'reply',
				'post_status' => 'pending',
				'post_author' => 0,
				'post_parent' => 10,
			]
		);
		queue_new_forum_post( 11 );

		expect( $this->meta[11]['_jct_base'] )->toBe( '0' );
	}
);

it(
	'does not triage posts by forum moderators',
	function (): void {
		Functions\when( 'user_can' )->justReturn( true );

		$data = hold_new_forum_post(
			[
				'post_type'   => 'reply',
				'post_status' => 'publish',
				'post_author' => 2,
				'post_parent' => 10,
			]
		);

		expect( $data['post_status'] )->toBe( 'publish' );
	}
);

it(
	'holds an edited reply only when its content changed',
	function (): void {
		Functions\when( 'get_post' )->justReturn( new WP_Post( [ 'post_content' => 'Original' ] ) );
		Functions\when( 'get_post_type' )->justReturn( 'reply' );

		$edit = [
			'ID'           => 11,
			'post_type'    => 'reply',
			'post_status'  => 'publish',
			'post_author'  => 0,
			'post_title'   => '',
			'post_content' => 'Original',
		];

		$unchanged = hold_edited_forum_post( $edit );
		$changed   = hold_edited_forum_post( [ 'post_content' => 'Changed' ] + $edit );
		queue_edited_forum_post( 11 );

		expect( $unchanged['post_status'] )->toBe( 'publish' )
			->and( $changed['post_status'] )->toBe( 'pending' )
			->and( $this->meta[11]['_jct_base'] )->toBe( '1' );
	}
);

it(
	'ignores an unchanged edit whose text bbPress passes slashed',
	function (): void {
		$title   = 'Don\'t "quote" C:\\path';
		$content = 'It\'s fine';
		Functions\when( 'get_post' )->justReturn(
			new WP_Post(
				[
					'post_title'   => $title,
					'post_content' => $content,
				]
			)
		);

		$data = hold_edited_forum_post(
			[
				'ID'           => 11,
				'post_type'    => 'topic',
				'post_status'  => 'publish',
				'post_author'  => 0,
				'post_title'   => addslashes( $title ),
				'post_content' => addslashes( $content ),
			]
		);

		expect( $data['post_status'] )->toBe( 'publish' )->and( $this->meta )->toBe( [] );
	}
);

it(
	'judges a topic by its title and body against its forum',
	function (): void {
		$item = forum_item(
			new WP_Post(
				[
					'ID'            => 10,
					'post_type'     => 'topic',
					'post_title'    => 'Title',
					'post_content'  => 'Body',
					'post_parent'   => 3,
					'post_date_gmt' => '2026-01-01 00:00:00',
				]
			)
		);

		expect( $item['context'] )->toBe( 3 )
			->and( $item['commentdata']['comment_content'] )->toBe( "Title\n\nBody" );
	}
);

it(
	'does not report automated spam decisions to Akismet',
	function (): void {
		$akismet         = new class() {
			/** Akismet listener stand-in. */
			public function submit_post(): void {}
		};
		$extend          = new stdClass();
		$extend->akismet = $akismet;
		$bbpress         = new stdClass();
		$bbpress->extend = $extend;
		$hooked          = true;

		Functions\when( 'bbpress' )->justReturn( $bbpress );
		Functions\when( 'has_action' )->justReturn( 10 );
		Functions\expect( 'remove_action' )->once()->with( 'bbp_spammed_reply', [ $akismet, 'submit_post' ], 10 )->andReturnUsing(
			function () use ( &$hooked ): bool {
				$hooked = false;
				return true;
			}
		);
		Functions\expect( 'bbp_spam_reply' )->once()->with( 11 )->andReturnUsing(
			function () use ( &$hooked ): bool {
				expect( $hooked )->toBeFalse();
				return true;
			}
		);
		// add_action is a no-op stub from the bootstrap.

		finalize_forum_post( 'reply', 11, 'spam' );
	}
);
