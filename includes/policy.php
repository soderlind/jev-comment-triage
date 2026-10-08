<?php
/**
 * Comment decision policy.
 *
 * @package Soderlind\Plugin\JevCommentTriage
 */

declare( strict_types=1 );

namespace Soderlind\Plugin\JevCommentTriage;

/**
 * Normalize filterable decision thresholds to the unit interval.
 *
 * @param array $assessment Assessment being decided.
 * @return array{spam:float, abusive:float, relevance:float, clean:float}
 */
function thresholds( array $assessment = [] ): array {
	$filtered   = apply_filters( 'jct_thresholds', DEFAULT_THRESHOLDS, $assessment );
	$filtered   = is_array( $filtered ) ? $filtered : [];
	$thresholds = [];

	foreach ( DEFAULT_THRESHOLDS as $key => $default ) {
		$value              = $filtered[ $key ] ?? $default;
		$value              = is_numeric( $value ) ? (float) $value : $default;
		$thresholds[ $key ] = max( 0.0, min( 1.0, $value ) );
	}

	return $thresholds;
}

/**
 * Apply the fail-closed moderation policy to a normalized assessment.
 *
 * WordPress's original decision is an upper bound: a clean assessment can
 * retain it, but never improve it.
 *
 * @param array      $assessment Normalized assessment.
 * @param int|string $approved   WordPress's own decision.
 * @return int|string
 */
function decide( array $assessment, $approved ) {
	$t       = thresholds( $assessment );
	$spam    = (float) ( $assessment['spam'] ?? 0.0 );
	$abusive = (float) ( $assessment['abusive'] ?? 0.0 );

	if ( $spam >= $t['spam'] ) {
		return 'spam';
	}

	if ( $abusive >= $t['abusive'] ) {
		return '0';
	}

	$relevance = (array) ( $assessment['relevance'] ?? [] );
	$on_topic  = 'on_topic' === ( $relevance['choice'] ?? '' )
		&& (float) ( $relevance['confidence'] ?? 0.0 ) >= $t['relevance'];

	if ( $on_topic && $spam < $t['clean'] && $abusive < $t['clean'] ) {
		return $approved;
	}

	return '0';
}
