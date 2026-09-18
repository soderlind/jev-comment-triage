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
