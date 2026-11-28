<?php
/**
 * Forum integration for bbPress: triage new and edited forum topics and replies.
 *
 * Topics and replies are posts, so they use post meta and bbPress's own
 * moderation functions, which keep forum counts, subscriptions, and spam
 * restoration consistent. The decision pipeline is shared with comments.
 *
 * @package Soderlind\Plugin\JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Whether bbPress and the moderation functions this integration uses are loaded.
 */
function bbpress_active(): bool {
	return function_exists( 'bbp_get_topic_post_type' ) && function_exists( 'bbp_approve_topic' );
}

/**
 * Register the bbPress hooks once all plugins are loaded.
 */
function register_bbpress(): void {
	if ( ! bbpress_active() ) {
		return;
	}

	// Run last so Akismet and bbPress moderation keys have already decided the status.
	add_filter( 'bbp_new_topic_pre_insert', __NAMESPACE__ . '\\hold_new_forum_post', PHP_INT_MAX );
	add_filter( 'bbp_new_reply_pre_insert', __NAMESPACE__ . '\\hold_new_forum_post', PHP_INT_MAX );
	add_action( 'bbp_new_topic', __NAMESPACE__ . '\\queue_new_forum_post' );
	add_action( 'bbp_new_reply', __NAMESPACE__ . '\\queue_new_forum_post' );

	add_filter( 'bbp_edit_topic_pre_insert', __NAMESPACE__ . '\\hold_edited_forum_post', PHP_INT_MAX );
	add_filter( 'bbp_edit_reply_pre_insert', __NAMESPACE__ . '\\hold_edited_forum_post', PHP_INT_MAX );
	add_action( 'bbp_edit_topic', __NAMESPACE__ . '\\queue_edited_forum_post' );
	add_action( 'bbp_edit_reply', __NAMESPACE__ . '\\queue_edited_forum_post' );

	foreach ( [ bbp_get_topic_post_type(), bbp_get_reply_post_type() ] as $post_type ) {
		// bbPress replaces the column list at priority 10.
		add_filter( "manage_{$post_type}_posts_columns", __NAMESPACE__ . '\\add_column', 20 );
		add_action( "manage_{$post_type}_posts_custom_column", __NAMESPACE__ . '\\render_forum_column', 10, 2 );
	}
}

/**
 * Map a bbPress post type to its item type.
 *
 * @param string $post_type Post type.
 * @return string 'topic', 'reply', or '' for anything else.
 */
function forum_kind( string $post_type ): string {
	if ( ! bbpress_active() ) {
		return '';
	}

	return match ( $post_type ) {
		bbp_get_topic_post_type() => 'topic',
		bbp_get_reply_post_type() => 'reply',
		default                   => '',
	};
}

/**
 * Whether a user moderates the forum that contains an object.
 *
 * @param int $user_id   WordPress user ID.
 * @param int $object_id Forum, topic, or reply ID.
 */
function is_forum_trusted( int $user_id, int $object_id ): bool {
	return $user_id > 0 && user_can( $user_id, 'moderate', $object_id ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- bbPress maps this capability.
}

/**
 * Translate a bbPress status into the original decision, or null to skip triage.
 *
 * Closed, private, spam, and trashed posts are left alone.
 *
 * @param string $status Post status bbPress chose.
 */
function forum_base( string $status ): ?string {
	return match ( $status ) {
		bbp_get_public_status_id()  => '1',
		bbp_get_pending_status_id() => '0',
		default                     => null,
	};
}

/**
 * Hold a new topic or reply and remember bbPress's effective decision.
 *
 * A reply to a pending topic arrives as pending, so its base is '0' and
 * triage can mark it spam but never publish it.
 *
 * @param mixed $data Post data about to be inserted.
 * @return mixed
 */
function hold_new_forum_post( $data ) {
	if ( ! is_array( $data ) || ! provider_ready() ) {
		return $data;
	}

	$kind = forum_kind( (string) ( $data['post_type'] ?? '' ) );
	if ( '' === $kind || is_forum_trusted( (int) ( $data['post_author'] ?? 0 ), (int) ( $data['post_parent'] ?? 0 ) ) ) {
		return $data;
	}

	$base = forum_base( (string) ( $data['post_status'] ?? '' ) );
	if ( null === $base ) {
		return $data;
	}

	$claims                   = &edit_registry();
	$claims[ 'new:' . $kind ] = $base;
	$data['post_status']      = bbp_get_pending_status_id();

	return $data;
}

/**
 * Queue a topic or reply held by hold_new_forum_post().
 *
 * @param int $post_id New topic or reply ID.
 */
function queue_new_forum_post( $post_id ): void {
	$kind   = forum_kind( (string) get_post_type( (int) $post_id ) );
	$claims = &edit_registry();

	if ( '' === $kind || ! array_key_exists( 'new:' . $kind, $claims ) ) {
		return;
	}

	$base = $claims[ 'new:' . $kind ];
	unset( $claims[ 'new:' . $kind ] );

	queue_item(
		[
			'type' => $kind,
			'id'   => (int) $post_id,
		],
		$base
	);
}

/**
 * Hold an edited topic or reply whose title or content changed.
 *
 * @param mixed $data Post data about to be updated.
 * @return mixed
 */
function hold_edited_forum_post( $data ) {
	if ( ! is_array( $data ) || ! provider_ready() ) {
		return $data;
	}

	$id   = (int) ( $data['ID'] ?? 0 );
	$kind = forum_kind( (string) ( $data['post_type'] ?? '' ) );
	$old  = $id > 0 ? get_post( $id ) : null;

	if ( '' === $kind || ! is_object( $old ) ) {
		return $data;
	}

	if ( is_forum_trusted( get_current_user_id(), $id ) || is_forum_trusted( (int) ( $data['post_author'] ?? 0 ), $id ) ) {
		return $data;
	}

	// bbPress passes slashed data, ready for wp_update_post(); compare it unslashed.
	$title   = (string) wp_unslash( (string) ( $data['post_title'] ?? '' ) );
	$content = (string) wp_unslash( (string) ( $data['post_content'] ?? '' ) );
	if ( $title === (string) $old->post_title && $content === (string) $old->post_content ) {
		return $data;
	}

	$status = forum_base( (string) ( $data['post_status'] ?? '' ) );
	if ( null === $status ) {
		return $data;
	}

	$item = [
		'type' => $kind,
		'id'   => $id,
	];

	$claims                                = &edit_registry();
	$claims[ 'edit:' . $kind . ':' . $id ] = retriage_base( $item, $status );
	$data['post_status']                   = bbp_get_pending_status_id();

	return $data;
}

/**
 * Queue a topic or reply held by hold_edited_forum_post().
 *
 * @param int $post_id Edited topic or reply ID.
 */
function queue_edited_forum_post( $post_id ): void {
	$post_id = (int) $post_id;
	$kind    = forum_kind( (string) get_post_type( $post_id ) );
	$key     = 'edit:' . $kind . ':' . $post_id;
	$claims  = &edit_registry();

	if ( '' === $kind || ! array_key_exists( $key, $claims ) ) {
		return;
	}

	$base = $claims[ $key ];
	unset( $claims[ $key ] );

	queue_item(
		[
			'type' => $kind,
			'id'   => $post_id,
		],
		$base
	);
}

/**
 * Normalize a topic or reply into a triage item.
 *
 * A topic is judged against its forum, with its title prepended to the body;
 * a reply is judged against its topic.
 *
 * @param \WP_Post $post Topic or reply.
 * @return array{type:string, id:int, context:int, date:string, commentdata:array<string, mixed>}
 */
function forum_item( \WP_Post $post ): array {
	$kind      = forum_kind( (string) $post->post_type );
	$author_id = (int) $post->post_author;
	$content   = 'topic' === $kind
		? trim( (string) $post->post_title . "\n\n" . (string) $post->post_content )
		: (string) $post->post_content;

	if ( $author_id > 0 ) {
		$user   = get_userdata( $author_id );
		$author = [
			is_object( $user ) ? (string) $user->display_name : '',
			is_object( $user ) ? (string) $user->user_url : '',
			is_object( $user ) ? (string) $user->user_email : '',
		];
	} else {
		$author = [
			(string) get_post_meta( (int) $post->ID, '_bbp_anonymous_name', true ),
			(string) get_post_meta( (int) $post->ID, '_bbp_anonymous_website', true ),
			(string) get_post_meta( (int) $post->ID, '_bbp_anonymous_email', true ),
		];
	}

	return [
		'type'        => $kind,
		'id'          => (int) $post->ID,
		'context'     => (int) $post->post_parent,
		'date'        => (string) $post->post_date_gmt,
		'commentdata' => [
			'comment_content'      => $content,
			'comment_author'       => $author[0],
			'comment_author_url'   => $author[1],
			'comment_author_email' => $author[2],
			'user_id'              => $author_id,
		],
	];
}

/**
 * Select held, untriaged topics and replies, oldest first.
 *
 * @param int $limit Maximum number of items.
 * @return array<int, array<string, mixed>>
 */
function forum_queue( int $limit ): array {
	if ( ! bbpress_active() || $limit < 1 ) {
		return [];
	}

	$posts = get_posts(
		[
			'post_type'      => [ bbp_get_topic_post_type(), bbp_get_reply_post_type() ],
			'post_status'    => bbp_get_pending_status_id(),
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'meta_query'     => queue_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Queue markers are stored as post metadata.
		]
	);

	return array_map( __NAMESPACE__ . '\\forum_item', array_filter( $posts, static fn( $post ): bool => $post instanceof \WP_Post ) );
}

/**
 * Apply a decision with bbPress's moderation functions.
 *
 * @param string $kind     'topic' or 'reply'.
 * @param int    $post_id  Topic or reply ID.
 * @param string $decision Internal moderation decision.
 */
function finalize_forum_post( string $kind, int $post_id, string $decision ): void {
	if ( ! bbpress_active() || ! in_array( $kind, [ 'topic', 'reply' ], true ) ) {
		return;
	}

	if ( 'spam' === $decision ) {
		without_akismet_reports( "bbp_spammed_{$kind}", static fn() => call_user_func( "bbp_spam_{$kind}", $post_id ) );
		return;
	}

	call_user_func( '1' === $decision ? "bbp_approve_{$kind}" : "bbp_unapprove_{$kind}", $post_id );
}

/**
 * Run a callback without bbPress's Akismet extension reporting the result.
 *
 * Every bbPress spam action is reported to Akismet as a moderator's verdict.
 * WordPress comments only report user-initiated changes, so automated forum
 * decisions are kept out of Akismet's training data the same way.
 *
 * @param string   $hook     Action Akismet listens to.
 * @param callable $callback Callback to run.
 */
function without_akismet_reports( string $hook, callable $callback ): void {
	$akismet  = function_exists( 'bbpress' ) ? ( bbpress()->extend->akismet ?? null ) : null;
	$listener = is_object( $akismet ) ? [ $akismet, 'submit_post' ] : null;
	$priority = null !== $listener ? has_action( $hook, $listener ) : false;

	if ( false !== $priority ) {
		remove_action( $hook, $listener, $priority );
	}

	try {
		$callback();
	} finally {
		if ( false !== $priority ) {
			add_action( $hook, $listener, $priority );
		}
	}
}

/**
 * Render a stored Jev result in the topics and replies lists.
 *
 * @param string $column  Current column name.
 * @param int    $post_id Topic or reply ID.
 */
function render_forum_column( $column, $post_id ): void {
	if ( 'jev_triage' !== $column ) {
		return;
	}

	$data = get_post_meta( (int) $post_id, META_KEY, true );
	echo esc_html( is_array( $data ) ? column_text( $data ) : '—' );
}
