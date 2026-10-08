<?php
/**
 * Plugin Name: Jev Comment Triage
 * Description: Uses AI Provider for Jev to judge pending comments for relevance to their post, spam, and abuse, and acts only on confident answers.
 * Requires Plugins: ai-provider-for-jev
 * Requires PHP: 8.3
 * Version: 2.2.0
 * License: GPL-2.0-or-later
 * Text Domain: jev-comment-triage
 * Domain Path: /languages
 *
 * @package JevCommentTriage
 */

namespace Soderlind\Plugin\JevCommentTriage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PLUGIN_FILE = __FILE__;

const META_KEY      = '_jev_triage';
const PENDING_META  = '_jct_pending';
const ATTEMPTS_META = '_jct_attempts';
const BASE_META     = '_jct_base';
const EVENT         = 'jct_drain';
const INTERVAL      = 'jct_minute';
const MAX_ATTEMPTS  = 3;
const LOCK_KEY      = 'jct_draining';

const SCHEMA_VERSION = 2;

const DEFAULT_THRESHOLDS = [
	'spam'      => 0.90,
	'abusive'   => 0.50,
	'relevance' => 0.80,
	'clean'     => 0.20,
];

const COMMENTS_PER_REQUEST = 20;
const SPAM_CACHE_PREFIX    = 'jct_spam_';
const SPAM_CACHE_MIN       = 0.98;
const SPAM_CACHE_TTL       = 604800;
const CACHE_STATS_OPTION   = 'jct_spam_cache_stats';
const CACHE_COUNTERS       = [ 'lookups', 'hits', 'agreed' ];
const CACHE_SALT_OPTION    = 'jct_spam_cache_salt';

$autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

if ( class_exists( \Soderlind\WordPress\GitHubUpdater::class ) ) {
	\Soderlind\WordPress\GitHubUpdater::init(
		github_url:   'https://github.com/soderlind/jev-comment-triage',
		plugin_file:  PLUGIN_FILE,
		plugin_slug:  'jev-comment-triage',
		name_regex:   '/jev-comment-triage\.zip/',
		branch:       'main',
		check_period: 6,
	);
}

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/policy.php';
require_once __DIR__ . '/includes/class-assessment.php';
require_once __DIR__ . '/includes/spam-cache.php';
require_once __DIR__ . '/includes/processor.php';
require_once __DIR__ . '/includes/scheduler.php';
require_once __DIR__ . '/includes/intake.php';
require_once __DIR__ . '/includes/admin.php';

add_action( 'init', __NAMESPACE__ . '\\load_textdomain' );

add_filter( 'cron_schedules', __NAMESPACE__ . '\\add_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- The callback defines the interval.
add_action( 'init', __NAMESPACE__ . '\\ensure_scheduled' );
register_activation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\ensure_scheduled' );
register_deactivation_hook( PLUGIN_FILE, __NAMESPACE__ . '\\deactivate' );

// Capture the effective WordPress decision after earlier moderation filters.
add_filter( 'pre_comment_approved', __NAMESPACE__ . '\\defer', PHP_INT_MAX, 2 );
add_action( 'comment_post', __NAMESPACE__ . '\\enqueue', 10, 3 );
add_action( EVENT, __NAMESPACE__ . '\\drain' );

add_filter( 'manage_edit-comments_columns', __NAMESPACE__ . '\\add_column' );
add_action( 'manage_comments_custom_column', __NAMESPACE__ . '\\render_column', 10, 2 );
add_action( 'admin_init', __NAMESPACE__ . '\\register_privacy_content' );
add_action( 'admin_notices', __NAMESPACE__ . '\\cron_notice' );
