<?php
/**
 * Shared plugin helpers.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Load plugin translations from the languages directory.
 */
function load_textdomain(): void {
	load_plugin_textdomain( 'jev-comment-triage', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
}

/**
 * Whether the provider helper exists and has been configured.
 */
function provider_ready(): bool {
	if ( ! function_exists( 'AiProviderForJev\\evaluate' ) ) {
		return false;
	}

	return \AiProviderForJev\Settings\SettingsManager::instance()->is_configured();
}

/**
 * Count HTTP and www links in comment content.
 *
 * @param string $content Comment content.
 */
function count_links( string $content ): int {
	$urls = preg_match_all( '#https?://#i', $content );
	$www  = preg_match_all( '#(^|\s)www\.#i', $content );

	return (int) $urls + (int) $www;
}

/**
 * Build the comment data sent to the provider.
 *
 * @param array $commentdata Comment data.
 * @param int   $link_count  Number of links in the comment.
 * @return array<string, mixed>
 */
function comment_payload( array $commentdata, int $link_count ): array {
	$payload = [
		'content'    => (string) ( $commentdata['comment_content'] ?? '' ),
		'link_count' => (string) $link_count,
	];

	if ( (bool) apply_filters( 'jct_include_author_details', true, $commentdata ) ) {
		$payload['author'] = [
			'name'  => (string) ( $commentdata['comment_author'] ?? '' ),
			'url'   => (string) ( $commentdata['comment_author_url'] ?? '' ),
			'email' => (string) ( $commentdata['comment_author_email'] ?? '' ),
		];
	}

	return $payload;
}

/**
 * Count whitespace-separated words in comment content.
 *
 * @param string $content Comment content.
 */
function word_count( string $content ): int {
	$words = preg_split( '/\s+/u', trim( $content ), -1, PREG_SPLIT_NO_EMPTY );

	return is_array( $words ) ? count( $words ) : 0;
}

/**
 * Whether a logged-in author can already moderate comments.
 *
 * @param int $user_id WordPress user ID.
 */
function is_trusted( int $user_id ): bool {
	return $user_id > 0 && user_can( $user_id, 'moderate_comments' );
}

/**
 * Whether comment data represents a regular comment.
 *
 * @param array $commentdata Comment data.
 */
function is_regular_comment( array $commentdata ): bool {
	$type = (string) ( $commentdata['comment_type'] ?? '' );

	return '' === $type || 'comment' === $type;
}
