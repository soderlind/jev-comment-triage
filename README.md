# Jev Comment Triage

Auto-moderate WordPress comments with [TypeSafe](https://docs.typesafe.ai/introduction)'s
**Jev** model, via the [AI Provider for Jev](https://github.com/soderlind/ai-provider-for-jev)
plugin. Each comment is scored for **spam**, **scam/phishing**, and **toxicity**,
then routed — approved, flagged as spam, or held for review.

The Jev call runs **asynchronously**, off the comment-submit request, so
commenting stays fast even though moderation calls a remote API.

## Requirements

- WordPress 6.8+, PHP 8.3+
- The **AI Provider for Jev** plugin, active and configured with an API key
  (declared as a dependency via `Requires Plugins`).

## How it works

1. **On submit** (`pre_comment_approved`) — untrusted comments are held as
   *pending* instantly, with **no API call**. Trusted users (`moderate_comments`)
   and an unconfigured provider keep WordPress's own decision.
2. **On store** (`comment_post`) — the comment is tagged with a pending marker,
   a per-minute drain event is ensured, and a throttled immediate nudge
   (`spawn_cron`) is fired.
3. **Background drain** (`jct_drain`, every minute) — a bounded batch of pending,
   un-triaged comments is assessed in one Jev call each and routed:
   - spam/scam ≥ threshold → **spam**
   - borderline or toxic → **held** for review
   - clean → **approved**
4. **Self-healing** — on an API error a comment keeps its pending marker and is
   retried on the next tick; after 3 attempts it is left held for a human.
   Comments are never publicly visible before triage.

Scores are stored as the `_jev_triage` comment meta and shown in a **Jev** column
on the admin Comments screen.

## Decision logic

The combined spam signal is `max(is_spam, is_scam)`. A comment is marked spam
when that exceeds the `spam` threshold, when `is_scam` alone exceeds the `scam`
threshold, or when it is link-heavy (≥ `comment_max_links`) with any real spam
signal. Borderline spam or toxic content is held rather than deleted, keeping
false positives out of the spam bucket.

## Hooks

| Hook | Type | Purpose |
| ---- | ---- | ------- |
| `jct_thresholds` | filter | Tune decision thresholds (`spam` 0.75, `scam` 0.65, `hold` 0.45, `toxicity_hold` 1.5, `link_assist` 0.50). |
| `jct_batch_size` | filter | Comments processed per drain tick (default 20). |
| `jct_triaged` | action | Fires after a decision: `do_action( 'jct_triaged', $comment_id, $assessment, $decision )`. |

```php
// Be stricter, and process more per tick.
add_filter( 'jct_thresholds', fn( $t ) => [ ...$t, 'spam' => 0.65 ] );
add_filter( 'jct_batch_size', fn() => 50 );
```

## Accuracy

Against a hand-labelled set of tricky cases (polite affiliate spam, phishing,
crypto, dating bait, a link-farm, plus genuine comments including one with a
legitimate link), the tuned logic scored **10/10** with no false positives — the
genuine comment carrying a link scored 0.34 spam and was correctly passed.

## Benchmark

100 comments on a Local multisite subsite, `jev-latest`, sequential.

**On-request insert latency** (what the commenter waits for):

| min | mean | p95 | max |
| ---- | ---- | ---- | ---- |
| 1.28 ms | **2.38 ms** | 3.94 ms | 9.06 ms |

**Background drain** (off the request path):

| processed | passes | wall time | throughput | mean/comment |
| --------- | ------ | --------- | ---------- | ------------ |
| 100 | 1 | 70.7 s | 1.41 comments/s | 707 ms |

### Async vs. the original synchronous version

| Metric | Sync | Async (this version) | Change |
| ------ | ---- | -------------------- | ------ |
| Commenter-facing latency (mean) | 1237 ms | **2.4 ms** | ~520× faster |
| Commenter-facing p95 | 1608 ms | **3.9 ms** | ~410× faster |
| Where the Jev call runs | on submit (blocking) | background drain | — |
| Throughput | 0.77/s | 1.41/s | +83% |

Moving the Jev call off the request cut comment submission from ~1.2 s to ~2 ms
(~500×). The drain is also faster per comment than the old sync path (707 ms vs
1237 ms) because it does only the API call and status update, not the full
comment-insert pipeline — so the entire 100-comment backlog cleared in a single
drain pass.

> **WP-Cron note:** the drain relies on WP-Cron. On low-traffic sites, add a
> real system cron calling `wp-cron.php` (and set `DISABLE_WP_CRON`) so the
> backlog is processed promptly.

## License

GPL-2.0-or-later
