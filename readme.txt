=== Jev Comment Triage ===
Contributors: PerS
Requires at least: 6.8
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: comments, moderation, spam, ai, anti-spam

Asynchronous AI comment moderation powered by TypeSafe's Jev model — relevance, spam, and abuse checks that act only on confident answers and keep comment submission fast.

== Description ==

Jev Comment Triage auto-moderates WordPress comments using [TypeSafe](https://docs.typesafe.ai/introduction)'s **Jev** model, via the **AI Provider for Jev** plugin. For every comment Jev answers three independent questions in the context of its post: how it relates to the post (on-topic, off-topic, or unclear), whether it is spam, and whether it is abusive. Only confident answers lead to an automatic action; everything else is held for a person.

The Jev call runs **asynchronously**, off the comment-submit request, so commenting stays fast even though moderation calls a remote API. The plugin respects WordPress's own moderation: it only ever downgrades a comment to spam or hold, never publishing past the site's policy.

= Highlights =

* Fast submit — comments are held pending instantly with no API call on the request.
* Batched background drain (WP-Cron): comments on the same post share one Jev request (up to 20 per request).
* Separate relevance, spam, and abuse judgments, so an abusive comment about the post is held instead of published, and threats are not hidden in the spam folder.
* Thresholds that rise with the cost of a wrong action, all adjustable with one filter.
* Optional spam-verdict cache (shadow mode by default) and optional short-comment rule to skip calls when the answer is known.
* Respects WordPress moderation, the comment blocklist, and trusted users; skips pingbacks and trackbacks.
* Self-healing per-comment retry on API errors; nothing is publicly visible before triage.
* Answers and decision shown in a "Jev" column on the Comments screen.

= Privacy =

Comment text and the related post title and content — and, unless disabled with the `jct_include_author_details` filter, the author name, email, and URL — are sent to the TypeSafe (Jev) service. The spam cache stores only a hash of the comment text and two probabilities. The plugin registers a suggested privacy-policy snippet under Settings → Privacy.

= Hooks =

* `jct_thresholds` (filter) — decision thresholds `spam` (0.90), `abusive` (0.50), `relevance` (0.80), and `clean` (0.20), merged over the defaults.
* `jct_batch_size` (filter) — comments processed per drain tick (default 20).
* `jct_comments_per_request` (filter) — comments on the same post sent in one Jev request (default 20).
* `jct_spam_cache` (filter) — return true to skip Jev for cached spam texts (default false, shadow mode).
* `jct_min_words` (filter) — hold link-free comments with fewer words without calling Jev (default 0, off).
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

= 2.0.0 =
* Breaking: `jct_thresholds` now uses `spam`, `abusive`, `relevance`, and `clean`. The `spam` key keeps working; other 1.4.0 keys are ignored.
* Each comment gets three independent judgments against its post: relevance, spam, and abuse. Abusive comments are held for review instead of being marked as spam.
* Comments are published only when confidently on-topic with low spam and abuse signals, and never past WordPress's own decision.
* Comments on the same post share one Jev request (up to 20), about 15x faster; a comment that breaks a request is isolated so the rest are still judged.
* Optional network-wide spam-verdict cache (shadow mode by default) and optional `jct_min_words` short-comment rule.
* Removed the link-count spam heuristic; the link count is passed to Jev as context.
* The Comments screen shows relevance, spam, abuse, and the decision; uninstall also removes the spam cache.
* Added translation tooling and a WP-CLI benchmark harness.

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
