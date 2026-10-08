<?php
/**
 * Network-wide spam verdict cache.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Hash the exact spam-visible payload for a per-install cache key.
 *
 * @param array<string, mixed> $payload Exact payload sent to Jev.
 */
function spam_cache_key( array $payload ): string {
	$model    = '';
	$settings = \AiProviderForJev\Settings\SettingsManager::instance();
	if ( method_exists( $settings, 'get_model' ) ) {
		$model = (string) $settings->get_model();
	}

	return md5( SCHEMA_VERSION . '|' . $model . '|' . cache_salt() . '|' . (string) wp_json_encode( $payload ) );
}

/**
 * Load or create the per-install cache salt.
 */
function cache_salt(): string {
	$salt = get_site_option( CACHE_SALT_OPTION );

	if ( ! is_string( $salt ) || '' === $salt ) {
		add_site_option( CACHE_SALT_OPTION, wp_generate_password( 20, false ) );
		$salt = get_site_option( CACHE_SALT_OPTION );
	}

	return is_string( $salt ) ? $salt : '';
}

/**
 * Add this drain's counters to the network-wide totals.
 *
 * @param array<string, int> $stats Counters from this run.
 */
function record_cache_stats( array $stats ): void {
	foreach ( CACHE_COUNTERS as $counter ) {
		increment_counter( $counter, (int) ( $stats[ $counter ] ?? 0 ) );
	}
}

/**
 * Atomically increment one cache counter in the active site's options.
 *
 * @param string $counter Counter name.
 * @param int    $by      Amount to add.
 */
function increment_counter( string $counter, int $by ): void {
	global $wpdb;

	if ( $by <= 0 || ! is_object( $wpdb ) ) {
		return;
	}

	$option = CACHE_STATS_OPTION . '_' . $counter;

	if ( is_multisite() ) {
		$network_id = get_current_network_id();
		add_network_option( $network_id, $option, 0 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->sitemeta} SET meta_value = meta_value + %d WHERE site_id = %d AND meta_key = %s", $by, $network_id, $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A direct query provides an atomic counter update.
		wp_cache_delete( $network_id . ':' . $option, 'site-options' );
		return;
	}

	add_option( $option, 0, '', false );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + %d WHERE option_name = %s", $by, $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A direct query provides an atomic counter update.
	wp_cache_delete( $option, 'options' );
}

/**
 * Read the current network-wide cache counters.
 *
 * @return array<string, int>
 */
function cache_stats(): array {
	$stats = [];
	foreach ( CACHE_COUNTERS as $counter ) {
		$stats[ $counter ] = (int) get_site_option( CACHE_STATS_OPTION . '_' . $counter, 0 );
	}

	return $stats;
}
