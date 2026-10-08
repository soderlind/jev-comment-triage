# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.2.0] - 2026-10-09

### Changed

- The PHP namespace is now `Soderlind\Plugin\JevCommentTriage`. Hooks, filters,
  options, and comment meta are unchanged; only code that called the plugin's
  namespaced functions directly needs updating.
- The entry point is now a composition root: the runtime code lives in focused
  modules under `includes/` (`core.php`, `policy.php`, `intake.php`,
  `scheduler.php`, `class-assessment.php`, `processor.php`, `spam-cache.php`,
  and `admin.php`).
- The Jev assessment logic is encapsulated in an `Assessment` class with a
  single `assess()` entry point and an injectable provider adapter.

### Added

- WordPress Coding Standards setup: `phpcs.xml.dist` (short array syntax
  allowed) plus `composer lint` and `composer lint:fix`.
- `GLOSSARY.md`, defining the domain term "Assessment".

### Fixed

- The README now links the required AI Provider for Jev dependency.

## [2.1.0] - 2026-10-08

### Added

- Self-updates from GitHub releases through
  [`soderlind/wordpress-github-updater`](https://github.com/soderlind/wordpress-plugin-gitHub-updater),
  which checks for a new release every 6 hours and installs the
  `jev-comment-triage.zip` asset.
- GitHub Actions: a CI workflow that validates the Composer metadata and runs
  the test suite on PHP 8.3 and 8.4, plus release-triggered and manual
  workflows that build `jev-comment-triage.zip` and attach it to the release.
- `.distignore`, so the release zip contains only the runtime plugin files and
  the production Composer dependencies.
- An Installation section in `README.md` linking to the latest release zip.
- WordPress.org plugin banners and icons.

## [2.0.0] - 2026-10-08

### Added

- Post-relevance judgment: each comment is compared with its post's title and
  content and classified as on-topic, off-topic, or unclear.
- Comments on the same post are judged in one Jev request (up to 20, set with
  `jct_comments_per_request`), so the post is sent once. A malformed answer
  retries only the affected comment, and a rejected request body (HTTP 413 or
  422) is split until the offending comment is isolated.
- Spam-verdict cache keyed on the exact comment payload Jev sees (text, link
  count, and author name, URL, and email when sent), the model, the question
  version, and a per-install salt, shared across the network. It runs in
  shadow mode, counting would-be hits in atomic network-wide counters, until
  enabled with the `jct_spam_cache` filter.
- Optional `jct_min_words` rule that holds very short, link-free comments
  without calling Jev (off by default).
- `bin/benchmark.php`, a WP-CLI end-to-end benchmark and routing harness with
  hard-case fixtures, plus `docs/architecture.md`.
- WordPress.org `readme.txt`.
- Translation tooling: `i18n-map.json`, `languages/`, npm i18n scripts, and a
  generated `.pot`.

### Changed

- Each comment now gets three independent judgments: relevance (Choice), spam
  (Noul), and abuse (Noul), replacing the separate spam, scam, and toxicity
  questions. Abusive comments are held for review rather than marked as spam.
- **Breaking:** Publishing requires a confident on-topic answer with low spam
  and abuse signals; thresholds rise with the cost of a wrong action and are
  adjustable through `jct_thresholds` (`spam`, `abusive`, `relevance`, `clean`).
  Callbacks that set the old `spam` key keep working; other old keys are
  ignored.
- Removed the link-count spam heuristic; the link count is passed to Jev as
  context instead.
- The Comments screen shows relevance, confidence, spam, abuse, and the
  decision, and labels assessments stored by earlier versions.
- Uninstall also removes the spam cache and its counters.

## [1.4.0] - 2026-09-18

### Added

- Privacy disclosure via `wp_add_privacy_policy_content`, and a
  `jct_include_author_details` filter to stop sending author PII to the API.
- Admin notice when `DISABLE_WP_CRON` is set, since the drain relies on cron.
- `uninstall.php` to remove comment meta, the schedule, and transients.
- Translation loading (`load_plugin_textdomain`).
- Tests for `defer()` and for respecting WordPress's moderation decision.

### Changed

- **Respect WordPress's own moderation.** Triage now remembers the site's
  would-be decision and only *downgrades* to spam/hold — clean comments are
  published only if the site would have approved them anyway, never past a
  "hold all comments" policy.

### Fixed

- Skip comments WordPress already flagged via the blocklist (spam/trash) — no
  wasted API call.
- Skip pingbacks and trackbacks.
- Add a lock so overlapping cron runs cannot process the same batch twice.

## [1.3.0] - 2026-09-18

### Added

- Recurring per-minute **batched drain** (`jct_drain`) that processes a bounded
  batch of pending comments per tick, replacing the previous one cron event per
  comment.
- Pending marker meta so the drain is scoped strictly to comments this plugin
  deferred; `jct_batch_size` filter for the batch size.
- Self-healing retry: comments whose API call fails stay pending and are retried
  on later ticks, then left held for a human after `MAX_ATTEMPTS`.
- Pest + Brain Monkey unit tests covering the decision logic and the background
  handler.
- `README.md` with usage, hooks, and benchmark.

### Changed

- Activation/deactivation now schedule and clear the recurring drain event.

## [1.2.0] - 2026-09-18

### Added

- **Asynchronous** triage: the Jev call runs in a background job, so comment
  submission stays fast (~2 ms vs ~1.2 s).
- Untrusted comments are held pending on submit and classified in the background;
  nothing is publicly visible before triage.

## [1.1.0] - 2026-09-18

### Added

- Dedicated scam/phishing question (`is_scam`) alongside spam and toxicity.
- Structured state (author name, URL, email, link count) for stronger signal.
- Link-heavy heuristic tied to `comment_max_links`.
- Filterable thresholds (`jct_thresholds`) and a trusted-user short-circuit.

### Changed

- Lowered the spam threshold and route borderline content to the moderation
  queue instead of the spam bucket, improving recall while limiting false
  positives.

## [1.0.0] - 2026-09-18

### Added

- Initial release: synchronous spam + toxicity triage on `pre_comment_approved`,
  scores stored as comment meta, and a "Jev" column on the admin Comments screen.

[2.2.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/2.2.0
[2.1.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/2.1.0
[2.0.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/2.0.0
[1.4.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.4.0
[1.3.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.3.0
[1.2.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.2.0
[1.1.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.1.0
[1.0.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.0.0
