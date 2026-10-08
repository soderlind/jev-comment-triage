<?php
/**
 * Uninstall cleanup: remove the schedule, comment meta, and transients.
 *
 * @package JevCommentTriage
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'jct_drain' );

foreach ( [ '_jev_triage', '_jct_pending', '_jct_attempts', '_jct_base' ] as $meta_key ) {
	delete_metadata( 'comment', 0, $meta_key, '', true );
}

delete_transient( 'jct_drain_soon' );
delete_transient( 'jct_draining' );
// Deleting the salt orphans every cached spam verdict, including entries held
// in a persistent object cache that cannot be enumerated here; they expire.
foreach ( [ 'jct_spam_cache_salt', 'jct_spam_cache_stats_lookups', 'jct_spam_cache_stats_hits', 'jct_spam_cache_stats_agreed' ] as $jct_option ) {
	delete_site_option( $jct_option );
}

// Without a persistent object cache, the verdicts are rows; remove them now.
global $wpdb;
$jct_like  = $wpdb->esc_like( '_site_transient_jct_spam_' ) . '%';
$jct_tlike = $wpdb->esc_like( '_site_transient_timeout_jct_spam_' ) . '%';
if ( is_multisite() ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", $jct_like, $jct_tlike ) );
} else {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $jct_like, $jct_tlike ) );
}
