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

		$items = array_merge( comment_queue( $batch ), forum_queue( $batch ) );

		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) $a['date'], (string) $b['date'] ) );

		process_items( array_slice( $items, 0, max( 0, $batch ) ) );
	} finally {
		delete_transient( LOCK_KEY );
	}
}

/**
 * Meta query that selects queued, not yet triaged items.
 *
 * @return array<int|string, mixed>
 */
function queue_meta_query(): array {
	return [
		'relation' => 'AND',
		[
			'key'     => PENDING_META,
			'compare' => 'EXISTS',
		],
		[
			'key'     => META_KEY,
			'compare' => 'NOT EXISTS',
		],
	];
}

/**
 * Select held, untriaged comments, oldest first.
 *
 * @param int $limit Maximum number of items.
 * @return array<int, array<string, mixed>>
 */
function comment_queue( int $limit ): array {
	if ( $limit < 1 ) {
		return [];
	}

	$comments = get_comments(
		[
			'status'     => 'hold',
			'number'     => $limit,
			'orderby'    => 'comment_date_gmt',
			'order'      => 'ASC',
			'meta_query' => queue_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Queue markers are stored as comment metadata.
		]
	);

	return array_map( __NAMESPACE__ . '\\comment_item', array_filter( (array) $comments, static fn( $comment ): bool => $comment instanceof \WP_Comment ) );
}
