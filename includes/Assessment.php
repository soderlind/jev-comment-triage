<?php
/**
 * Jev assessment module.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

namespace JevCommentTriage;

final class Assessment {
	private const RELEVANCE_OPTIONS = [ 'on_topic', 'off_topic', 'unclear' ];

	private readonly \Closure $evaluate;

	public function __construct( callable $evaluate ) {
		$this->evaluate = \Closure::fromCallable( $evaluate );
	}

	/**
	 * Judge comments on one post and return one result for every caller key.
	 *
	 * @param array $postdata Post data (post_title, post_content).
	 * @param array<int|string, array{commentdata:array, link_count:int}> $items Comments keyed by caller ID.
	 * @return array<int|string, array<string, mixed>|\WP_Error>
	 */
	public function assess( array $postdata, array $items ): array {
		if ( [] === $items ) {
			return [];
		}

		$results = $this->request( $postdata, $items );

		if ( ! is_wp_error( $results ) ) {
			return $results;
		}

		if ( count( $items ) < 2 || ! $this->is_request_rejection( $results ) ) {
			return array_fill_keys( array_keys( $items ), $results );
		}

		$halves = array_chunk( $items, (int) ceil( count( $items ) / 2 ), true );

		return $this->assess( $postdata, $halves[0] ) + $this->assess( $postdata, $halves[1] );
	}

	/**
	 * Send one request and normalize its answers.
	 *
	 * @param array $postdata Post data.
	 * @param array<int|string, array{commentdata:array, link_count:int}> $items Comments keyed by caller ID.
	 * @return array<int|string, array<string, mixed>|\WP_Error>|\WP_Error
	 */
	private function request( array $postdata, array $items ): array|\WP_Error {
		$state = [
			'post' => [
				'title'   => (string) ( $postdata['post_title'] ?? '' ),
				'content' => (string) ( $postdata['post_content'] ?? '' ),
			],
		];
		$questions = [];
		$prefixes  = [];
		$index     = 0;

		foreach ( $items as $key => $item ) {
			$prefix           = 'c' . $index++ . '_';
			$prefixes[ $key ] = $prefix;
			$payload          = comment_payload( (array) $item['commentdata'], (int) $item['link_count'] );

			foreach ( $this->questions( $payload ) as $id => $question ) {
				$questions[ $prefix . $id ] = $question;
			}
		}

		$response = ( $this->evaluate )( $state, $questions );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$answers = is_array( $response['answers'] ?? null ) ? $response['answers'] : [];
		$results = [];

		foreach ( $prefixes as $key => $prefix ) {
			$results[ $key ] = $this->parse_answers( $answers, $prefix );
		}

		return $results;
	}

	/**
	 * Build the three independent judgments for one comment.
	 *
	 * @param array<string, mixed> $comment Comment payload.
	 * @return array<string, array<string, mixed>>
	 */
	private function questions( array $comment ): array {
		return [
			'relevance' => [
				'type'         => 'choice',
				'instructions' => [
					'question' => 'How does `comment.content` relate to the blog post in `post.title` and `post.content`?',
					'comment'  => $comment,
				],
				'criteria'     => [
					'on_topic'  => 'It discusses, questions, critiques, or adds to the subject of the post.',
					'off_topic' => 'It is about something unrelated to the post.',
					'unclear'   => 'It is too short, garbled, or vague to tell.',
				],
			],
			'spam'      => [
				'type'         => 'noul',
				'instructions' => [
					'question' => 'Is `comment` spam: unsolicited promotion or advertising, a scam, phishing, SEO link-dropping, or bulk content not written as genuine discussion? A comment can mention the post and still be spam.',
					'comment'  => $comment,
				],
			],
			'abusive'   => [
				'type'         => 'noul',
				'instructions' => [
					'question' => 'Does `comment.content` contain insults, harassment, threats, hate speech, or other abuse directed at a person or group?',
					'comment'  => $comment,
				],
			],
		];
	}

	/**
	 * Validate and normalize the three answers for one comment.
	 *
	 * @param array<string, mixed> $answers All answers in the response.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function parse_answers( array $answers, string $prefix ): array|\WP_Error {
		$relevance     = $answers[ $prefix . 'relevance' ] ?? null;
		$choice        = is_array( $relevance ) ? (string) ( $relevance['choice'] ?? '' ) : '';
		$confidence    = is_array( $relevance ) ? ( $relevance['confidence'] ?? null ) : null;
		$probabilities = is_array( $relevance ) ? ( $relevance['probabilities'] ?? null ) : null;

		if (
			! in_array( $choice, self::RELEVANCE_OPTIONS, true )
			|| ! $this->is_unit_interval( $confidence )
			|| ! is_array( $probabilities )
			|| array_diff( self::RELEVANCE_OPTIONS, array_keys( $probabilities ) )
		) {
			return new \WP_Error( 'jct_invalid_response', 'Jev returned an invalid relevance answer.' );
		}

		foreach ( $probabilities as $probability ) {
			if ( ! $this->is_unit_interval( $probability ) ) {
				return new \WP_Error( 'jct_invalid_response', 'Jev returned invalid relevance probabilities.' );
			}
		}

		$signals = [];
		foreach ( [ 'spam', 'abusive' ] as $id ) {
			$answer = $answers[ $prefix . $id ] ?? null;
			$value  = is_array( $answer ) ? ( $answer['noul'] ?? null ) : null;
			if ( ! $this->is_unit_interval( $value ) ) {
				return new \WP_Error( 'jct_invalid_response', 'Jev returned an invalid ' . $id . ' answer.' );
			}
			$signals[ $id ] = (float) $value;
		}

		return [
			'version'   => SCHEMA_VERSION,
			'source'    => 'jev',
			'relevance' => [
				'choice'        => $choice,
				'confidence'    => (float) $confidence,
				'probabilities' => array_map( 'floatval', $probabilities ),
			],
			'spam'      => $signals['spam'],
			'abusive'   => $signals['abusive'],
		];
	}

	private function is_unit_interval( mixed $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0.0 && (float) $value <= 1.0;
	}

	private function is_request_rejection( \WP_Error $error ): bool {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		return in_array( $status, [ 413, 422 ], true );
	}
}
