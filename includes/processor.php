<?php
/**
 * Background triage processing for comments and forum posts.
 *
 * Every source (WordPress comments, bbPress topics and replies) is turned into
 * the same item shape, so batching, the spam cache, assessment, and the policy
 * are shared. Only metadata storage and the final status change are per source.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Process one pending comment through the normal batch path.
 *
 * @param \WP_Comment $comment Comment to process.
 */
function process_comment( \WP_Comment $comment ): void {
	process_comments( [ $comment ] );
}

/**
 * Process pending comments.
 *
 * @param array<int, \WP_Comment> $comments   Comments to process.
 * @param Assessment|null         $assessment Assessment module.
 */
function process_comments( array $comments, ?Assessment $assessment = null ): void {
	$items = [];
	foreach ( $comments as $comment ) {
		if ( $comment instanceof \WP_Comment ) {
			$items[] = comment_item( $comment );
		}
	}

	process_items( $items, $assessment );
}

/**
 * Normalize a comment into a triage item.
 *
 * @param \WP_Comment $comment Comment.
 * @return array{type:string, id:int, context:int, date:string, commentdata:array<string, mixed>}
 */
function comment_item( \WP_Comment $comment ): array {
	return [
		'type'        => 'comment',
		'id'          => (int) $comment->comment_ID,
		'context'     => (int) $comment->comment_post_ID,
		'date'        => (string) ( $comment->comment_date_gmt ?? '' ),
		'commentdata' => [
			'comment_content'      => $comment->comment_content,
			'comment_author'       => $comment->comment_author,
			'comment_author_url'   => $comment->comment_author_url,
			'comment_author_email' => $comment->comment_author_email,
			'user_id'              => (int) $comment->user_id,
		],
	];
}

/**
 * Group items by source and context, then process bounded request chunks.
 *
 * @param array<int, array<string, mixed>> $items      Triage items.
 * @param Assessment|null                  $assessment Assessment module.
 */
function process_items( array $items, ?Assessment $assessment = null ): void {
	$assessment ??= new Assessment(
		static fn( array $state, array $questions ) => \AiProviderForJev\evaluate( $state, $questions )
	);

	$groups = [];
	foreach ( $items as $item ) {
		$groups[ $item['type'] . ':' . $item['context'] ][] = $item;
	}

	$per_request = max( 1, (int) apply_filters( 'jct_comments_per_request', COMMENTS_PER_REQUEST ) );
	$stats       = [
		'lookups' => 0,
		'hits'    => 0,
		'agreed'  => 0,
	];

	foreach ( $groups as $group ) {
		$post     = get_post( $group[0]['context'] );
		$postdata = [
			'post_title'   => is_object( $post ) ? (string) ( $post->post_title ?? '' ) : '',
			'post_content' => is_object( $post ) ? (string) ( $post->post_content ?? '' ) : '',
		];

		foreach ( array_chunk( $group, $per_request ) as $chunk ) {
			process_chunk( $postdata, $chunk, $stats, $assessment );
		}
	}

	record_cache_stats( $stats );
}

/**
 * Apply short-content rules, the spam cache, and the assessment result.
 *
 * @param array                            $postdata   Context data.
 * @param array<int, array<string, mixed>> $chunk      Items of one source sharing one context.
 * @param array<string, int>               $stats      Cache counters.
 * @param Assessment                       $assessment Assessment module.
 */
function process_chunk( array $postdata, array $chunk, array &$stats, Assessment $assessment ): void {
	$use_cache = (bool) apply_filters( 'jct_spam_cache', false );
	$min_words = max( 0, (int) apply_filters( 'jct_min_words', 0 ) );
	$items     = [];
	$byid      = [];
	$bases     = [];
	$shadowed  = [];

	foreach ( $chunk as $item ) {
		$id           = (int) $item['id'];
		$byid[ $id ]  = $item;
		$bases[ $id ] = ( '1' === (string) item_meta( $item, BASE_META ) ) ? '1' : '0';
		$content      = trim( (string) $item['commentdata']['comment_content'] );

		if ( '' === $content ) {
			delete_item_meta( $item, PENDING_META );
			delete_item_meta( $item, BASE_META );
			finalize( $item, $bases[ $id ] );
			continue;
		}

		$link_count = count_links( $content );

		if ( $min_words > 0 && 0 === $link_count && word_count( $content ) < $min_words ) {
			apply_assessment(
				$item,
				$bases[ $id ],
				[
					'version' => SCHEMA_VERSION,
					'source'  => 'rule',
					'rule'    => 'min_words',
				],
				$link_count
			);
			continue;
		}

		$cache_key = spam_cache_key( comment_payload( $item['commentdata'], $link_count ) );
		$cached    = get_site_transient( SPAM_CACHE_PREFIX . $cache_key );
		++$stats['lookups'];

		if ( is_array( $cached ) ) {
			++$stats['hits'];
			if ( $use_cache ) {
				apply_assessment( $item, $bases[ $id ], [ 'source' => 'cache' ] + $cached, $link_count );
				continue;
			}
			$shadowed[ $id ] = true;
		}

		$items[ $id ] = [
			'commentdata' => $item['commentdata'],
			'link_count'  => $link_count,
			'cache_key'   => $cache_key,
		];
	}

	if ( [] === $items ) {
		return;
	}

	$results = $assessment->assess( $postdata, $items, (string) $chunk[0]['type'] );

	foreach ( $items as $id => $request ) {
		$result = $results[ $id ] ?? new \WP_Error( 'jct_invalid_response', 'Jev returned no answer for this item.' );

		if ( is_wp_error( $result ) ) {
			record_failure( $byid[ $id ] );
			continue;
		}

		if ( isset( $shadowed[ $id ] ) && $result['spam'] >= thresholds( $result )['spam'] ) {
			++$stats['agreed'];
		}

		if ( $result['spam'] >= SPAM_CACHE_MIN ) {
			set_site_transient(
				SPAM_CACHE_PREFIX . $request['cache_key'],
				[
					'version' => SCHEMA_VERSION,
					'spam'    => $result['spam'],
					'abusive' => $result['abusive'],
				],
				SPAM_CACHE_TTL
			);
		}

		apply_assessment( $byid[ $id ], $bases[ $id ], $result, $request['link_count'] );
	}
}

/**
 * Persist a normalized assessment and apply its moderation decision.
 *
 * @param array<string, mixed> $item       Triage item.
 * @param string               $base       Original moderation decision.
 * @param array<string, mixed> $assessment Assessment to store.
 * @param int                  $link_count Number of links in the content.
 */
function apply_assessment( array $item, string $base, array $assessment, int $link_count ): void {
	$decision = (string) decide( $assessment, $base );

	add_item_meta(
		$item,
		META_KEY,
		$assessment + [
			'link_count' => $link_count,
			'decision'   => $decision,
		]
	);
	delete_item_meta( $item, PENDING_META );
	delete_item_meta( $item, BASE_META );

	finalize( $item, $decision );

	if ( 'comment' === $item['type'] ) {
		do_action( 'jct_triaged', (int) $item['id'], $assessment, $decision );
	} else {
		do_action( 'jct_triaged_forum_post', (int) $item['id'], $assessment, $decision, (string) $item['type'] );
	}
}

/**
 * Record a failed attempt and abandon processing after the retry limit.
 *
 * @param array<string, mixed> $item Triage item.
 */
function record_failure( array $item ): void {
	$attempts = (int) item_meta( $item, ATTEMPTS_META ) + 1;
	update_item_meta( $item, ATTEMPTS_META, $attempts );
	if ( $attempts >= MAX_ATTEMPTS ) {
		delete_item_meta( $item, PENDING_META );
	}
}

/**
 * Apply an internal decision ('1' approve, '0' hold, 'spam') through the source's own API.
 *
 * @param array<string, mixed> $item     Triage item.
 * @param string               $decision Internal moderation decision.
 */
function finalize( array $item, string $decision ): void {
	if ( 'comment' !== $item['type'] ) {
		finalize_forum_post( (string) $item['type'], (int) $item['id'], $decision );
		return;
	}

	$map = [
		'spam' => 'spam',
		'1'    => 'approve',
		'0'    => 'hold',
	];
	wp_set_comment_status( (int) $item['id'], $map[ $decision ] ?? 'hold' );
}

/**
 * Read single item metadata from comment or post meta.
 *
 * @param array<string, mixed> $item Triage item.
 * @param string               $key  Meta key.
 * @return mixed
 */
function item_meta( array $item, string $key ) {
	return 'comment' === $item['type']
		? get_comment_meta( (int) $item['id'], $key, true )
		: get_post_meta( (int) $item['id'], $key, true );
}

/**
 * Add unique item metadata.
 *
 * @param array<string, mixed> $item  Triage item.
 * @param string               $key   Meta key.
 * @param mixed                $value Meta value.
 */
function add_item_meta( array $item, string $key, $value ): void {
	if ( 'comment' === $item['type'] ) {
		add_comment_meta( (int) $item['id'], $key, $value, true );
	} else {
		add_post_meta( (int) $item['id'], $key, $value, true );
	}
}

/**
 * Update item metadata.
 *
 * @param array<string, mixed> $item  Triage item.
 * @param string               $key   Meta key.
 * @param mixed                $value Meta value.
 */
function update_item_meta( array $item, string $key, $value ): void {
	if ( 'comment' === $item['type'] ) {
		update_comment_meta( (int) $item['id'], $key, $value );
	} else {
		update_post_meta( (int) $item['id'], $key, $value );
	}
}

/**
 * Delete item metadata.
 *
 * @param array<string, mixed> $item Triage item.
 * @param string               $key  Meta key.
 */
function delete_item_meta( array $item, string $key ): void {
	if ( 'comment' === $item['type'] ) {
		delete_comment_meta( (int) $item['id'], $key );
	} else {
		delete_post_meta( (int) $item['id'], $key );
	}
}
