<?php
/**
 * WordPress administration presentation and notices.
 *
 * @package Soderlind\Plugin\JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Add the Jev result column to the comments list.
 *
 * @param array<string, string> $columns Comments-list columns.
 */
function add_column( array $columns ): array {
	$columns['jev_triage'] = __( 'Jev', 'jev-comment-triage' );

	return $columns;
}

/**
 * Render a stored Jev result for one comments-list row.
 *
 * @param string $column     Current column name.
 * @param int    $comment_id Comment ID.
 */
function render_column( string $column, int $comment_id ): void {
	if ( 'jev_triage' !== $column ) {
		return;
	}

	$data = get_comment_meta( $comment_id, META_KEY, true );
	if ( ! is_array( $data ) ) {
		echo '—';
		return;
	}

	echo esc_html( column_text( $data ) );
}

/**
 * Format a stored assessment for the comments list.
 *
 * @param array<string, mixed> $data Stored assessment.
 */
function column_text( array $data ): string {
	$decisions = [
		'spam' => __( 'spam', 'jev-comment-triage' ),
		'1'    => __( 'approved', 'jev-comment-triage' ),
		'0'    => __( 'held', 'jev-comment-triage' ),
	];
	$decision  = isset( $data['decision'] ) ? ( $decisions[ (string) $data['decision'] ] ?? (string) $data['decision'] ) : '';
	$suffix    = '' !== $decision ? ' → ' . $decision : '';

	if ( SCHEMA_VERSION !== (int) ( $data['version'] ?? 0 ) ) {
		return __( 'Earlier version', 'jev-comment-triage' ) . $suffix;
	}

	$source = (string) ( $data['source'] ?? 'jev' );

	if ( 'rule' === $source ) {
		return __( 'Too short to judge', 'jev-comment-triage' ) . $suffix;
	}

	if ( 'cache' === $source ) {
		return sprintf(
			/* translators: %s: spam probability, e.g. 0.99. */
			__( 'Known spam text (cached) · spam %s', 'jev-comment-triage' ),
			number_format( (float) ( $data['spam'] ?? 0 ), 2 )
		) . $suffix;
	}

	$relevance = [
		'on_topic'  => __( 'on-topic', 'jev-comment-triage' ),
		'off_topic' => __( 'off-topic', 'jev-comment-triage' ),
		'unclear'   => __( 'unclear', 'jev-comment-triage' ),
	];
	$choice    = (string) ( $data['relevance']['choice'] ?? '' );

	return sprintf(
		/* translators: 1: relevance label, 2: relevance confidence, 3: spam probability, 4: abuse probability. */
		__( '%1$s %2$s · spam %3$s · abuse %4$s', 'jev-comment-triage' ),
		$relevance[ $choice ] ?? $choice,
		number_format( (float) ( $data['relevance']['confidence'] ?? 0 ), 2 ),
		number_format( (float) ( $data['spam'] ?? 0 ), 2 ),
		number_format( (float) ( $data['abusive'] ?? 0 ), 2 )
	) . $suffix;
}

/**
 * Add the external-processing disclosure to the privacy policy.
 */
function register_privacy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = __( 'When you submit a comment, its text and the related post content — and, unless the site disables it, your name, email address, and website — are sent to the TypeSafe (Jev) service to check the comment for relevance, spam, and abuse before it is published.', 'jev-comment-triage' );
	wp_add_privacy_policy_content( 'Jev Comment Triage', wp_kses_post( wpautop( $content ) ) );
}

/**
 * Warn administrators when WP-Cron is disabled.
 */
function cron_notice(): void {
	if ( ! current_user_can( 'manage_options' ) || ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'edit-comments', 'plugins' ], true ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'Jev Comment Triage: WP-Cron is disabled (DISABLE_WP_CRON). Make sure a system cron runs wp-cron.php, or deferred comments will not be triaged.', 'jev-comment-triage' )
	);
}
