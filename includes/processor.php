<?php
/**
 * Background comment processing.
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
 * Group pending comments by post and process bounded request chunks.
 *
 * @param array<int, \WP_Comment> $comments   Comments to process.
 * @param Assessment|null         $assessment Assessment module.
 */
function process_comments( array $comments, ?Assessment $assessment = null ): void {
	$assessment ??= new Assessment(
		static fn( array $state, array $questions ) => \AiProviderForJev\evaluate( $state, $questions )
	);

	$by_post = [];
	foreach ( $comments as $comment ) {
		if ( $comment instanceof \WP_Comment ) {
			$by_post[ (int) $comment->comment_post_ID ][] = $comment;
		}
	}

	$per_request = max( 1, (int) apply_filters( 'jct_comments_per_request', COMMENTS_PER_REQUEST ) );
	$stats       = [
		'lookups' => 0,
		'hits'    => 0,
		'agreed'  => 0,
	];

	foreach ( $by_post as $post_id => $group ) {
		$post     = get_post( $post_id );
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
 * Apply short-comment rules, the spam cache, and the assessment result.
 *
 * @param array                   $postdata   Post data.
 * @param array<int, \WP_Comment> $comments   Comments on that post.
 * @param array<string, int>      $stats      Cache counters.
 * @param Assessment              $assessment Assessment module.
 */
function process_chunk( array $postdata, array $comments, array &$stats, Assessment $assessment ): void {
	$use_cache = (bool) apply_filters( 'jct_spam_cache', false );
	$min_words = max( 0, (int) apply_filters( 'jct_min_words', 0 ) );
	$items     = [];
	$bases     = [];
	$shadowed  = [];

	foreach ( $comments as $comment ) {
		$comment_id           = (int) $comment->comment_ID;
		$bases[ $comment_id ] = ( '1' === (string) get_comment_meta( $comment_id, BASE_META, true ) ) ? '1' : '0';
		$content              = trim( (string) $comment->comment_content );

		if ( '' === $content ) {
			delete_comment_meta( $comment_id, PENDING_META );
			delete_comment_meta( $comment_id, BASE_META );
			finalize( $comment_id, $bases[ $comment_id ] );
			continue;
		}

		$link_count = count_links( $content );

		if ( $min_words > 0 && 0 === $link_count && word_count( $content ) < $min_words ) {
			apply_assessment(
				$comment_id,
				$bases[ $comment_id ],
				[
					'version' => SCHEMA_VERSION,
					'source'  => 'rule',
					'rule'    => 'min_words',
				],
				$link_count
			);
			continue;
		}

		$commentdata = [
			'comment_content'      => $comment->comment_content,
			'comment_author'       => $comment->comment_author,
			'comment_author_url'   => $comment->comment_author_url,
			'comment_author_email' => $comment->comment_author_email,
			'user_id'              => (int) $comment->user_id,
		];
		$cache_key   = spam_cache_key( comment_payload( $commentdata, $link_count ) );
		$cached      = get_site_transient( SPAM_CACHE_PREFIX . $cache_key );
		++$stats['lookups'];

		if ( is_array( $cached ) ) {
			++$stats['hits'];
			if ( $use_cache ) {
				apply_assessment( $comment_id, $bases[ $comment_id ], [ 'source' => 'cache' ] + $cached, $link_count );
				continue;
			}
			$shadowed[ $comment_id ] = true;
		}

		$items[ $comment_id ] = [
			'commentdata' => $commentdata,
			'link_count'  => $link_count,
			'cache_key'   => $cache_key,
		];
	}

	if ( [] === $items ) {
		return;
	}

	$results = $assessment->assess( $postdata, $items );

	foreach ( $items as $comment_id => $item ) {
		$result = $results[ $comment_id ] ?? new \WP_Error( 'jct_invalid_response', 'Jev returned no answer for this comment.' );

		if ( is_wp_error( $result ) ) {
			record_failure( $comment_id );
			continue;
		}

		if ( isset( $shadowed[ $comment_id ] ) && $result['spam'] >= thresholds( $result )['spam'] ) {
			++$stats['agreed'];
		}

		if ( $result['spam'] >= SPAM_CACHE_MIN ) {
			set_site_transient(
				SPAM_CACHE_PREFIX . $item['cache_key'],
				[
					'version' => SCHEMA_VERSION,
					'spam'    => $result['spam'],
					'abusive' => $result['abusive'],
				],
				SPAM_CACHE_TTL
			);
		}

		apply_assessment( $comment_id, $bases[ $comment_id ], $result, $item['link_count'] );
	}
}

/**
 * Persist a normalized assessment and apply its moderation decision.
 *
 * @param int                  $comment_id Comment ID.
 * @param string               $base       Original WordPress decision.
 * @param array<string, mixed> $assessment Assessment to store.
 * @param int                  $link_count Number of links in the comment.
 */
function apply_assessment( int $comment_id, string $base, array $assessment, int $link_count ): void {
	$decision = (string) decide( $assessment, $base );

	add_comment_meta(
		$comment_id,
		META_KEY,
		$assessment + [
			'link_count' => $link_count,
			'decision'   => $decision,
		],
		true
	);
	delete_comment_meta( $comment_id, PENDING_META );
	delete_comment_meta( $comment_id, BASE_META );

	finalize( $comment_id, $decision );

	do_action( 'jct_triaged', $comment_id, $assessment, $decision );
}

/**
 * Record a failed attempt and abandon processing after the retry limit.
 *
 * @param int $comment_id Comment ID.
 */
function record_failure( int $comment_id ): void {
	$attempts = (int) get_comment_meta( $comment_id, ATTEMPTS_META, true ) + 1;
	update_comment_meta( $comment_id, ATTEMPTS_META, $attempts );
	if ( $attempts >= MAX_ATTEMPTS ) {
		delete_comment_meta( $comment_id, PENDING_META );
	}
}

/**
 * Convert the internal decision to a WordPress comment status.
 *
 * @param int    $comment_id Comment ID.
 * @param string $decision   Internal moderation decision.
 */
function finalize( int $comment_id, string $decision ): void {
	$map = [
		'spam' => 'spam',
		'1'    => 'approve',
		'0'    => 'hold',
	];
	wp_set_comment_status( $comment_id, $map[ $decision ] ?? 'hold' );
}
