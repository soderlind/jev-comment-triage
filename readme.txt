=== Jev Comment Triage ===
Contributors: PerS
Requires at least: 6.8
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: comments, moderation, spam, ai, anti-spam

Asynchronous AI comment moderation powered by TypeSafe's Jev model — background spam, scam/phishing, and toxicity triage that keeps comment submission fast.

== Description ==

Jev Comment Triage auto-moderates WordPress comments using [TypeSafe](https://docs.typesafe.ai/introduction)'s **Jev** model, via the **AI Provider for Jev** plugin. Each comment is scored for **spam**, **scam/phishing**, and **toxicity**, then routed — approved, flagged as spam, or held for review.

The Jev call runs **asynchronously**, off the comment-submit request, so commenting stays fast even though moderation calls a remote API. The plugin respects WordPress's own moderation: it only ever downgrades a comment to spam or hold, never publishing past the site's policy.

= Highlights =

* Fast submit — comments are held pending instantly with no API call on the request.
* Batched background drain (WP-Cron) with a bounded batch size and a concurrency lock.
* Spam, scam/phishing, and toxicity assessment in a single Jev request per comment.
* Respects WordPress moderation, the comment blocklist, and trusted users; skips pingbacks and trackbacks.
* Self-healing retry on API errors; nothing is publicly visible before triage.
* Scores shown in a "Jev" column on the Comments screen.

= Privacy =

Comment text — and, unless disabled with the `jct_include_author_details` filter, the author name, email, and URL — is sent to the TypeSafe (Jev) service for assessment. The plugin registers a suggested privacy-policy snippet under Settings → Privacy.

= Hooks =

* `jct_thresholds` (filter) — tune decision thresholds.
* `jct_batch_size` (filter) — comments processed per drain tick (default 20).
* `jct_include_author_details` (filter) — whether to send author PII (default true).
* `jct_triaged` (action) — fires after a decision with the comment ID, assessment, and decision.

== Installation ==

1. Install and activate the **AI Provider for Jev** plugin, and configure it with a TypeSafe API key.
2. Upload the `jev-comment-triage` folder to `/wp-content/plugins/`, or install it from the Plugins screen.
3. Activate **Jev Comment Triage**.

On low-traffic sites, run a real system cron for `wp-cron.php` so the background drain processes comments promptly.

== Frequently Asked Questions ==

= Does it require another plugin? =

Yes. It depends on the **AI Provider for Jev** plugin (declared via the `Requires Plugins` header), which must be configured with a TypeSafe API key.

= Will it override my moderation settings? =

No. It remembers WordPress's own decision and only downgrades to spam or hold. Clean comments are published only if the site would have approved them anyway.

= What happens if the API is unavailable? =

The comment stays pending (never auto-published) and is retried on later drain ticks; after a few failed attempts it is left held for a human.

== Changelog ==

= 1.4.0 =
* Respect WordPress's own moderation — only downgrade to spam/hold, never publish past site policy.
* Skip blocklist-flagged comments (spam/trash) and pingbacks/trackbacks — no wasted API call.
* Add a `jct_include_author_details` filter and a privacy-policy disclosure.
* Add a drain concurrency lock, `uninstall.php` cleanup, translation loading, and a WP-Cron-disabled admin notice.

= 1.3.0 =
* Batched per-minute background drain replacing one cron event per comment, with self-healing retry.

= 1.2.0 =
* Asynchronous triage: the Jev call runs in the background, keeping comment submission fast.

= 1.1.0 =
* Added scam/phishing detection, structured state, a link heuristic, filterable thresholds, and a trusted-user short-circuit.

= 1.0.0 =
* Initial release: synchronous spam and toxicity triage.
