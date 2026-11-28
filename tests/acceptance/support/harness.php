<?php
/**
 * Plugin Name: Jev Comment Triage acceptance harness
 * Description: Test-only. The Playwright acceptance suite installs this file and removes it afterwards.
 *
 * @package JevCommentTriage
 */

namespace Soderlind\Plugin\JevCommentTriage\E2E;

defined( 'ABSPATH' ) || exit;

// Tests post several items quickly as the same user.
add_filter( 'bbp_bypass_check_for_flood', '__return_true' );
add_filter( 'wp_is_comment_flood', '__return_false', PHP_INT_MAX );

// Only the suite's WP-CLI drain may process the queue, so held states stay observable.
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	add_action(
		'plugins_loaded',
		static function (): void {
			remove_action( 'jct_drain', 'Soderlind\\Plugin\\JevCommentTriage\\drain' );
		},
		PHP_INT_MAX
	);
}

// Simulate an unconfigured provider for requests that carry the test cookie (AC-10).
if ( isset( $_COOKIE['jct_e2e_provider_off'] ) ) {
	add_filter( 'pre_option_ai_provider_jev_api_key', '__return_empty_string' );
}
