# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.3.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.3.0
[1.2.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.2.0
[1.1.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.1.0
[1.0.0]: https://github.com/soderlind/jev-comment-triage/releases/tag/1.0.0
