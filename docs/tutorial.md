# Using Jev in WordPress

This tutorial shows how to use Jev to moderate WordPress comments with the
Jev Comment Triage plugin. It follows TypeSafe's model: send state and several
small, typed questions, then use the structured answers and their confidence
in ordinary PHP code.

Jev is not a text-generation chatbot. It answers typed **Choice**, **Score**,
and **Noul** questions. Jev Comment Triage uses one Choice question for
relevance and two Noul questions for spam and abuse. The plugin then applies a
fail-closed WordPress policy:

- confident spam is marked as spam;
- abusive comments are held for moderation;
- a confident, relevant, clean comment keeps WordPress's original decision;
- uncertain or off-topic comments are held.

The plugin calls Jev asynchronously through WP-Cron, so the comment submission
request does not wait for the remote model.

## Before you begin

You need:

- WordPress 6.8 or later;
- PHP 8.3 or later;
- the [AI Provider for Jev](https://github.com/soderlind/ai-provider-for-jev)
  plugin;
- a TypeSafe API key.

The AI Provider for Jev owns the API key, model selection, and HTTP transport.
Jev Comment Triage owns the WordPress comment lifecycle and moderation policy.
Do not put a TypeSafe API key in a theme, plugin source file, or `wp-config.php`
unless you are deliberately managing that secret outside WordPress.

The examples below can live in a small site plugin. For a quick experiment,
you can also place them in a child theme's `functions.php`; a site plugin is
safer because the behavior should not disappear when the theme changes.

## 101: Install and use Jev

This level is for a site administrator who wants asynchronous AI comment
moderation without writing integration code.

### 1. Install the provider

Install and activate **AI Provider for Jev**. Open its settings, enter the
TypeSafe API key, choose a model if the provider exposes model selection, and
save.

The triage plugin checks that the provider exists and is configured before it
queues comments. If it is unavailable, WordPress keeps its normal decision and
the plugin does not call Jev.

### 2. Install the triage plugin

Install **Jev Comment Triage** from a release ZIP or from the WordPress
Plugins screen:

1. Go to **Plugins > Add New Plugin > Upload Plugin**.
2. Select `jev-comment-triage.zip`.
3. Choose **Install Now**, then **Activate**.

WordPress declares the provider as a plugin dependency. Activate and configure
the provider before expecting new comments to be triaged.

### 3. Submit a test comment

Submit a comment on a normal post. On submission, the plugin:

1. lets WordPress and earlier moderation filters determine the original
   decision;
2. holds an eligible comment immediately;
3. stores a queue marker in comment meta;
4. schedules the `jct_drain` event.

There is no Jev request in the visitor's comment-submit request.

Trusted authors who can `moderate_comments`, pingbacks, trackbacks, and
comments already marked as WordPress spam or trash are not sent to Jev.

### 4. Wait for the background drain

The recurring drain runs every minute. A newly queued comment also nudges
WP-Cron immediately, subject to a short throttle. The drain:

- selects a bounded batch of held comments;
- groups comments by post;
- sends the post once with up to 20 comments per request;
- validates every structured answer;
- stores the result in `_jev_triage` comment meta;
- changes the WordPress comment status.

For low-traffic sites, configure a real system cron to call `wp-cron.php`.
Otherwise WP-Cron may wait for the next site request. If `DISABLE_WP_CRON` is
enabled without a replacement cron, the plugin displays an administrator
warning.

### 5. Review the result

Go to **Comments**. The **Jev** column shows the relevance, confidence, spam
probability, abuse probability, and final decision.

The plugin never publishes a comment that WordPress would have held. A clean
assessment can preserve an original approval, but it cannot override the
site's existing moderation policy.

### 6. Test the queue with WP-CLI

Use WordPress functions through WP-CLI to inspect the scheduled event and a
comment's stored assessment:

```sh
wp cron event list --field=hook | grep '^jct_drain$'
wp comment list --status=hold --format=table
wp comment meta get COMMENT_ID _jev_triage --format=json
```

Replace `COMMENT_ID` with a real comment ID. To run due cron events manually
in a development environment:

```sh
wp cron event run jct_drain
```

Do not use a manual run as a substitute for reliable production scheduling.

## 201: Tune Jev with WordPress hooks

This level is for a developer who wants to adapt the policy without changing
the plugin.

### 1. Create a site integration plugin

Create `wp-content/plugins/my-site-jev-policy/my-site-jev-policy.php`:

```php
<?php
/**
 * Plugin Name: My Site Jev Policy
 */

declare( strict_types=1 );

add_filter(
	'jct_thresholds',
	static function ( array $thresholds, array $assessment ): array {
		// Require stronger relevance before an otherwise clean comment
		// can retain WordPress's original approval decision.
		$thresholds['relevance'] = 0.90;

		return $thresholds;
	},
	10,
	2
);
```

Activate it with:

```sh
wp plugin activate my-site-jev-policy
```

The filter receives the normalized assessment as its second argument. Returned
thresholds are merged with the defaults and clamped to `0..1`.

### 2. Understand the four thresholds

The default values are:

| Key | Default | Meaning |
| --- | ---: | --- |
| `spam` | `0.90` | Mark the comment as spam at or above this probability. |
| `abusive` | `0.50` | Hold the comment at or above this probability. |
| `relevance` | `0.80` | Minimum confidence for an `on_topic` answer. |
| `clean` | `0.20` | Spam and abuse must both stay below this value to retain the base decision. |

Use stricter values when a false approval is expensive. Use the WordPress
moderation queue for uncertain cases rather than trying to force every result
into publish or spam.

### 3. Stop sending author details

Comment text and the related post are always sent for triage. Author name,
email, and URL are included by default, but a site can exclude them:

```php
add_filter( 'jct_include_author_details', '__return_false' );
```

This uses a WordPress filter and changes the payload used for the Jev request
and spam-cache key. Update the site's privacy policy when changing what data is
sent to the external service.

### 4. Enable the spam cache deliberately

The cache is shadow-only by default: Jev is still asked, while counters record
whether a cached verdict would have agreed. Inspect the counters before
enabling it:

```sh
wp option get jct_spam_cache_stats_lookups
wp option get jct_spam_cache_stats_hits
wp option get jct_spam_cache_stats_agreed
```

For a single-site installation, enable it in the site plugin:

```php
add_filter( 'jct_spam_cache', '__return_true' );
```

On multisite, the counters are network-wide. The cache stores only highly
confident spam verdicts for seven days. Relevance is never cached because it
depends on the post.

### 5. Control throughput

Use filters when a site needs a different queue shape:

```php
add_filter(
	'jct_batch_size',
	static function (): int {
		return 10;
	}
);

add_filter(
	'jct_comments_per_request',
	static function (): int {
		return 10;
	}
);
```

`jct_batch_size` limits comments selected by one drain. The per-request limit
controls how many comments on one post share one Jev request. Keep both within
the provider and site's operational limits. The plugin splits a request
rejected with HTTP 413 or 422 to isolate the offending comment.

### 6. Hold very short comments locally

If a site wants link-free comments below a minimum word count held without an
AI call:

```php
add_filter(
	'jct_min_words',
	static function (): int {
		return 3;
	}
);
```

This is a site policy, not a Jev judgment. It is disabled by default because a
comment such as “Thanks!” may be perfectly appropriate on one site.

## 301: Build a WordPress workflow around the result

This level is for a developer who wants to observe or extend moderation after
the plugin makes a decision. It uses the plugin's `jct_triaged` action instead
of calling the TypeSafe API directly.

### 1. Record a moderation audit note

The action fires after the assessment is stored and the WordPress comment
status is updated. Use WordPress comment meta for a small audit record:

```php
add_action(
	'jct_triaged',
	static function ( int $comment_id, array $assessment, string $decision ): void {
		update_comment_meta(
			$comment_id,
			'_my_jev_audit',
			[
				'decision' => $decision,
				'source'   => (string) ( $assessment['source'] ?? 'jev' ),
				'updated'  => current_time( 'mysql', true ),
			]
		);
	},
	10,
	3
);
```

`$decision` is `spam`, `1`, or `0`. The assessment source is normally `jev`,
and can also be `cache` or `rule`.

### 2. Notify moderators about held comments

Use the WordPress comment object and mail API to notify a moderation mailbox.
Do not email the full comment or author email unless the site's privacy policy
allows it:

```php
add_action(
	'jct_triaged',
	static function ( int $comment_id, array $assessment, string $decision ): void {
		if ( '0' !== $decision ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}

		$post = get_post( $comment->comment_post_ID );
		$subject = sprintf(
			/* translators: %d: comment ID. */
			__( 'Comment %d needs moderation', 'my-site-jev-policy' ),
			$comment_id
		);
		$message = sprintf(
			/* translators: 1: comment ID, 2: post title. */
			__( "Comment %1\$d was held on “%2\$s”. Review it in Comments.", 'my-site-jev-policy' ),
			$comment_id,
			$post instanceof WP_Post ? $post->post_title : __( '(unknown post)', 'my-site-jev-policy' )
		);

		wp_mail( get_option( 'admin_email' ), $subject, $message );
	},
	10,
	3
);
```

The action does not fire for empty comments, failed attempts, or comments
abandoned after three failures. Those comments remain held and can be found
with `get_comments()` or WP-CLI.

### 3. Add a moderator-only dashboard summary

Use a WordPress admin hook and capability check to count held comments with a
stored assessment:

```php
add_action(
	'admin_notices',
	static function (): void {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return;
		}

		$held = get_comments(
			[
				'status'     => 'hold',
				'count'      => true,
				'meta_query' => [
					[
						'key'     => '_jev_triage',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		if ( 0 === (int) $held ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: held comment count. */
					_n(
						'Jev is holding %d comment for review.',
						'Jev is holding %d comments for review.',
						(int) $held,
						'my-site-jev-policy'
					),
					(int) $held
				)
			)
		);
	}
);
```

This example uses `get_comments()` rather than direct SQL and escapes the
complete message with `esc_html()`. For a large site, replace the count with a
cached value or a purpose-built query after measuring the admin-page cost.

### 4. Apply a deliberate, separate moderation rule

If a site needs a deterministic rule after Jev, keep it explicit and
fail-closed. For example, a site may continue to hold a comment when Jev found
it off-topic, while leaving spam and abusive decisions untouched:

```php
add_action(
	'jct_triaged',
	static function ( int $comment_id, array $assessment, string $decision ): void {
		if ( 'jev' !== ( $assessment['source'] ?? '' ) ) {
			return;
		}

		if ( 'off_topic' !== ( $assessment['relevance']['choice'] ?? '' ) ) {
			return;
		}

		// Explicitly keep the comment in the moderation queue.
		if ( 'spam' !== $decision ) {
			wp_set_comment_status( $comment_id, 'hold' );
		}
	},
	10,
	3
);
```

Use this pattern sparingly. The built-in policy already holds off-topic,
unclear, abusive, and low-confidence comments. A post-decision action can
change the final status, so test it with representative comments before
enabling it on a production site.

### 5. Test the complete workflow

Use a staging site and real WordPress functions:

```sh
wp plugin status ai-provider-for-jev jev-comment-triage
wp cron event run jct_drain
wp comment list --meta_key=_jev_triage --format=table
wp comment meta get COMMENT_ID _jev_triage --format=json
```

Check all of these cases:

1. a relevant, non-spam comment that WordPress would approve;
2. a spam comment;
3. an abusive comment about the post;
4. an off-topic but non-spam comment;
5. a malformed or unavailable-provider case;
6. a comment WordPress would already hold.

The expected safety property is that no Jev result publishes a comment that
WordPress would have held. Keep the site's own moderation settings enabled
while testing.

## Learn more

- [TypeSafe introduction](https://docs.typesafe.ai/introduction)
- [TypeSafe quick start](https://docs.typesafe.ai/introduction/quickstart)
- [TypeSafe primitives](https://docs.typesafe.ai/primitives)
- [TypeSafe confidence](https://docs.typesafe.ai/confidence)
- [TypeSafe patterns](https://docs.typesafe.ai/patterns)
- [Jev Comment Triage README](../README.md)
- [Architecture and data flow](architecture.md)
