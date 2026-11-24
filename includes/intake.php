<?php
/**
 * Comment intake and queue handoff.
 *
 * @package Soderlind\Plugin\JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Keep the pre-insert WordPress decision available to the comment_post hook.
 *
 * @return array<string, string>
 */
function &base_registry(): array {
	static $registry = [];

	return $registry;
}

/**
 * Remember which inserts Jev claimed in pre_comment_approved during this request.
 *
 * @return array<string, true>
 */
function &claim_registry(): array {
	static $claims = [];

	return $claims;
}

/**
 * Build a key from stored (unslashed) comment values.
 *
 * @param int|string $post_id Comment post ID.
 * @param string     $email   Comment author email.
 * @param string     $content Comment content.
 */
function claim_key( $post_id, string $email, string $content ): string {
	return md5( (string) $post_id . '|' . $email . '|' . $content );
}

/**
 * Build the request-local handoff key shared by both WordPress hooks.
 *
 * @param array $commentdata Comment data.
 */
function fingerprint( array $commentdata ): string {
	return md5(
		(string) ( $commentdata['comment_post_ID'] ?? '' ) . '|'
		. (string) ( $commentdata['comment_author_email'] ?? '' ) . '|'
		. (string) ( $commentdata['comment_content'] ?? '' )
	);
}

/**
 * Hold an eligible comment while preserving WordPress's effective decision.
 *
 * @param int|string|\WP_Error $approved    Current approval status.
 * @param array                $commentdata Comment data.
 * @return int|string|\WP_Error
 */
function defer( $approved, array $commentdata ) {
	if ( is_wp_error( $approved ) || ! provider_ready() ) {
		return $approved;
	}

	if ( ! is_regular_comment( $commentdata ) || is_trusted( (int) ( $commentdata['user_id'] ?? 0 ) ) ) {
		return $approved;
	}

	if ( 'spam' === $approved || 'trash' === $approved ) {
		return $approved;
	}

	$registry                                =& base_registry();
	$registry[ fingerprint( $commentdata ) ] = ( '1' === (string) $approved ) ? '1' : '0';

	$claims = &claim_registry();
	$claims[ claim_key(
		$commentdata['comment_post_ID'] ?? '',
		(string) wp_unslash( (string) ( $commentdata['comment_author_email'] ?? '' ) ),
		(string) wp_unslash( (string) ( $commentdata['comment_content'] ?? '' ) )
	) ]     = true;

	return '0';
}

/**
 * Take over automatic moderation from the AI plugin's Comment Moderation experiment.
 *
 * Removing a regular comment's type from `wpai_comment_moderation_comment_types`
 * makes the experiment skip it before it calls the `ai/comment-analysis` ability,
 * so Jev alone decides and no second, synchronous AI request is made on submit.
 * Only comments Jev held in `defer()` are taken; direct `wp_insert_comment()`
 * calls never reach Jev's intake, so the AI plugin keeps moderating those.
 *
 * @param mixed $comment_types Comment types the AI plugin would moderate.
 * @param mixed $comment       The comment being checked.
 * @return mixed
 */
function yield_ai_moderation( $comment_types, $comment = null ) {
	if ( ! is_array( $comment_types ) || ! $comment instanceof \WP_Comment || ! provider_ready() ) {
		return $comment_types;
	}

	$claims = &claim_registry();
	if ( ! isset( $claims[ claim_key( $comment->comment_post_ID, (string) $comment->comment_author_email, (string) $comment->comment_content ) ] ) ) {
		return $comment_types;
	}

	if ( ! (bool) apply_filters( 'jct_override_ai_moderation', true ) ) {
		return $comment_types;
	}

	return array_values(
		array_filter(
			$comment_types,
			static fn( $type ): bool => ! is_regular_comment( [ 'comment_type' => (string) $type ] )
		)
	);
}

/**
 * Persist queue markers after WordPress has created the comment.
 *
 * @param int        $comment_id  New comment ID.
 * @param int|string $approved    Approval status.
 * @param array      $commentdata Comment data.
 */
function enqueue( int $comment_id, $approved, array $commentdata ): void {
	if ( ! provider_ready() || ! is_regular_comment( $commentdata ) || is_trusted( (int) ( $commentdata['user_id'] ?? 0 ) ) ) {
		return;
	}

	if ( 'spam' === $approved || 'trash' === $approved ) {
		return;
	}

	$registry =& base_registry();
	$base     = $registry[ fingerprint( $commentdata ) ] ?? '0';

	add_comment_meta( $comment_id, PENDING_META, 1, true );
	add_comment_meta( $comment_id, BASE_META, $base, true );
	ensure_scheduled();

	if ( ! wp_next_scheduled( EVENT, [ 'now' ] ) && ! get_transient( 'jct_drain_soon' ) ) {
		set_transient( 'jct_drain_soon', 1, 15 );
		wp_schedule_single_event( time(), EVENT, [ 'now' ] );
		spawn_cron();
	}
}
