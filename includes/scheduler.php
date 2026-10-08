<?php
/**
 * WP-Cron scheduling and queue drain.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Add the one-minute schedule used by the triage drain.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function add_schedule( array $schedules ): array {
	$schedules[ INTERVAL ] = [
		'interval' => MINUTE_IN_SECONDS,
		'display'  => __( 'Every minute (Jev triage)', 'jev-comment-triage' ),
	];

	return $schedules;
}

/**
 * Ensure the recurring drain exists while the provider is ready.
 */
function ensure_scheduled(): void {
	if ( provider_ready() && ! wp_next_scheduled( EVENT ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, INTERVAL, EVENT );
	}
}

/**
 * Remove all scheduled triage events during deactivation.
 */
function deactivate(): void {
	wp_unschedule_hook( EVENT );
}

/**
 * Process one bounded batch while preventing overlapping drains.
 */
function drain(): void {
	if ( ! provider_ready() || get_transient( LOCK_KEY ) ) {
		return;
	}

	set_transient( LOCK_KEY, 1, 10 * MINUTE_IN_SECONDS );

	try {
		$batch = (int) apply_filters( 'jct_batch_size', 20 );

		$comments = get_comments(
			[
				'status'     => 'hold',
				'number'     => $batch,
				'orderby'    => 'comment_date_gmt',
				'order'      => 'ASC',
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Queue markers are stored as comment metadata.
					'relation' => 'AND',
					[
						'key'     => PENDING_META,
						'compare' => 'EXISTS',
					],
					[
						'key'     => META_KEY,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		process_comments( $comments );
	} finally {
		delete_transient( LOCK_KEY );
	}
}
