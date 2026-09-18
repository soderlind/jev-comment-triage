<?php
/**
 * Test bootstrap: minimal WordPress surface, then load the plugin functions.
 *
 * @package JevCommentTriage
 */

declare( strict_types=1 );

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );

	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		/** Minimal WP_Error stand-in. */
		class WP_Error {
			/** @var array<string, list<string>> */
			public array $errors = [];

			public function __construct( string $code = '', string $message = '' ) {
				if ( '' !== $code ) {
					$this->errors[ $code ][] = $message;
				}
			}

			public function get_error_message(): string {
				foreach ( $this->errors as $messages ) {
					return $messages[0] ?? '';
				}
				return '';
			}
		}
	}

	if ( ! class_exists( 'WP_Comment' ) ) {
		/** Minimal WP_Comment stand-in. */
		class WP_Comment {
			public int|string $comment_ID       = 0;
			public string $comment_content       = '';
			public string $comment_author        = '';
			public string $comment_author_url    = '';
			public string $comment_author_email  = '';
			public string $comment_approved      = '1';
			public int|string $user_id           = 0;

			/** @param array<string, mixed> $props */
			public function __construct( array $props = [] ) {
				foreach ( $props as $key => $value ) {
					$this->$key = $value;
				}
			}
		}
	}

	// No-op stubs for the hooks the plugin registers at load time.
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( ...$args ): bool {
			return true;
		}
	}
	if ( ! function_exists( 'add_action' ) ) {
		function add_action( ...$args ): bool {
			return true;
		}
	}
	if ( ! function_exists( 'register_activation_hook' ) ) {
		function register_activation_hook( ...$args ): void {}
	}
	if ( ! function_exists( 'register_deactivation_hook' ) ) {
		function register_deactivation_hook( ...$args ): void {}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ): bool {
			return $thing instanceof \WP_Error;
		}
	}
}

namespace AiProviderForJev\Settings {
	// The provider's settings surface; the evaluate() function is stubbed per test.
	if ( ! class_exists( SettingsManager::class ) ) {
		class SettingsManager {
			private static ?SettingsManager $instance = null;

			public static function instance(): self {
				return self::$instance ??= new self();
			}

			public function is_configured(): bool {
				return true;
			}
		}
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/vendor/autoload.php';
	require_once dirname( __DIR__ ) . '/jev-comment-triage.php';
}
