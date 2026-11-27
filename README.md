# Jev Comment Triage

Auto-moderate WordPress comments with [TypeSafe](https://docs.typesafe.ai/introduction)'s
**Jev** model, via the [AI Provider for Jev](https://github.com/soderlind/ai-provider-for-jev)
plugin. For every comment Jev answers three independent questions in the
context of its post: how it relates to the post, whether it is spam, and
whether it is abusive. Plain PHP turns those answers into a decision, and only
confident answers lead to an automatic action.

The Jev call runs **asynchronously**, off the comment-submit request, so
commenting stays fast even though moderation calls a remote API.

On the same 60 labelled comments, Jev routed **60 / 60** correctly, while the
AI plugin's Comment Moderation with OpenAI `gpt-5.6-luna` routed 24 / 60 and
published 33 it should not have. Jev was also about 165× faster per comment
(12.7 ms vs 2,118 ms). See
[Jev vs the AI plugin's Comment Moderation](#jev-vs-the-ai-plugins-comment-moderation-openai).

## Requirements

- WordPress 6.8+, PHP 8.3+
- The **[AI Provider for Jev](https://github.com/soderlind/ai-provider-for-jev)** plugin, active and configured with an API key
  (declared as a dependency via `Requires Plugins`).

## Installation

1. Download the latest
   [`jev-comment-triage.zip`](https://github.com/soderlind/jev-comment-triage/releases/latest/download/jev-comment-triage.zip).
2. In WordPress, go to **Plugins > Add New Plugin > Upload Plugin**, select the
   downloaded ZIP, and choose **Install Now**.
3. Activate **Jev Comment Triage**. WordPress will notify you when future
   releases are available.

## How it works

1. **On submit** (`pre_comment_approved`) — untrusted comments are held as
   *pending* instantly, with **no API call**, while WordPress's own would-be
   decision is remembered. Trusted users (`moderate_comments`), pingbacks and
   trackbacks, an unconfigured provider, and comments WordPress already caught
   with the blocklist (spam/trash) keep WordPress's decision.
2. **On store** (`comment_post`) — the comment is tagged with a pending marker,
   a per-minute drain event is ensured, and a throttled immediate nudge
   (`spawn_cron`) is fired.
3. **Background drain** (`jct_drain`, every minute) — a bounded batch of
   pending, un-triaged comments is grouped by post. Each post's content is sent
   once, with up to 20 comments per Jev request, and every comment is routed
   on its own answers (see [Decision logic](#decision-logic)), **never
   publishing past the site's own moderation policy**.
4. **Self-healing** — a comment whose answer is malformed keeps its pending
   marker and is retried on the next tick; after 3 attempts it is left held for
   a human. If Jev rejects a request body (HTTP 413 or 422, for example because
   one comment is too large), the request is split in half and retried until
   the offending comment is isolated, so the others are still judged. Outages,
   timeouts, auth or configuration errors, and rate limits leave the whole
   chunk pending without splitting. Comments are never publicly visible before
   triage, and a lock prevents concurrent drains from double-processing.

The answers and decision are stored as the `_jev_triage` comment meta and shown
in a **Jev** column on the admin Comments screen.

### Coexisting with the AI plugin's Comment Moderation

If the [AI plugin](https://github.com/WordPress/ai)'s
[Comment Moderation](https://github.com/WordPress/ai/blob/trunk/docs/experiments/comment-moderation.md)
experiment is enabled, Jev takes over its automatic moderation. While the Jev
provider is configured, each regular comment Jev holds on submit is removed
from the experiment's `wpai_comment_moderation_comment_types` list, so it never
calls the `ai/comment-analysis` ability for that comment and never changes its
status. Comments created directly with `wp_insert_comment()` bypass Jev's intake,
so the experiment still moderates those. That avoids a second, synchronous AI request on every submit and conflicting
decisions. The experiment's manual **Analyze** row and bulk actions, its
columns, and other comment types it is filtered to moderate are untouched.
Return `false` from `jct_override_ai_moderation` to let both run. For how the
two compare, see the [benchmark](#jev-vs-the-ai-plugins-comment-moderation-openai).

## What Jev is asked

| Question | Type | Why this type |
| -------- | ---- | ------------- |
| How does the comment relate to the post? | Choice: `on_topic`, `off_topic`, `unclear` | The options are mutually exclusive; its `confidence` says whether to trust the pick. |
| Is the comment spam (promotion, scam, phishing, link-dropping)? | Noul (probability of yes) | Spam can mention the post, so it is not an alternative to on-topic. |
| Does the comment contain insults, harassment, threats, or hate? | Noul | Abuse can be on-topic too. |

Asking these separately matters. An abusive comment about the post is clearly
on-topic *and* clearly abusive; a single "pick one category" question can only
express that as a split, uncertain answer, and puts threats in the spam folder.

## Decision logic

Thresholds rise with the cost of a wrong action:

| Order | Condition (defaults) | Result |
| ----- | -------------------- | ------ |
| 1 | spam ≥ `0.90` | **spam** |
| 2 | abusive ≥ `0.50` | **held** for a person, not hidden as spam |
| 3 | on-topic with confidence ≥ `0.80`, and spam and abusive both < `0.20` | WordPress's remembered decision: **approved** only if the site would have approved it anyway |
| 4 | anything else (off-topic, unclear, low confidence, borderline signals) | **held** |

## Fewer calls when the answer is known

- **Spam-verdict cache.** When Jev is at least `0.98` sure a comment is spam,
  the verdict is cached network-wide for 7 days under a hash of the exact
  comment payload Jev saw — text with any link markup, link count, and (unless
  `jct_include_author_details` is off) the author name, URL, and email — plus
  the model, question version, and a per-install salt. The same submission
  replayed on another post or subsite is then recognized, while a submission
  that differs in anything the spam question can read never shares a verdict.
  The post itself is deliberately not part of the key, since recognizing
  cross-post replays is the point. Only spam verdicts are cached: relevance
  depends on the post, and borderline answers can vary between runs. The cache
  runs in **shadow mode** by default — Jev is still asked, and network-wide
  counters (`jct_spam_cache_stats_lookups`, `_hits`, `_agreed`, incremented
  atomically) record how often the cache would have answered and how often
  Jev agreed. Turn it on with the `jct_spam_cache` filter once those numbers
  justify it.
- **Optional short-comment rule.** With `jct_min_words` set, link-free comments
  shorter than that are held without calling Jev. It is off by default because
  whether "Thanks!" should be published is a site policy.
- **Your own word lists.** WordPress's *Disallowed Comment Keys* (and Akismet,
  if active) already mark comments as spam or trash before triage, and those
  comments never reach Jev. Prefer them to custom regular expressions.

## Hooks

| Hook | Type | Purpose |
| ---- | ---- | ------- |
| `jct_thresholds` | filter | Decision thresholds: `spam` (0.90), `abusive` (0.50), `relevance` (0.80), `clean` (0.20). Returned values are merged over the defaults and clamped to 0–1. Receives the assessment as its second argument. |
| `jct_batch_size` | filter | Comments processed per drain tick (default 20). |
| `jct_comments_per_request` | filter | Comments on the same post sent in one Jev request (default 20, i.e. 60 questions). |
| `jct_spam_cache` | filter | Return `true` to skip Jev for cached spam texts (default `false`, shadow mode). |
| `jct_min_words` | filter | Hold link-free comments with fewer words without calling Jev (default `0`, off). |
| `jct_include_author_details` | filter | Whether to send the author name, URL, and email to the AI service (default `true`). Return `false` for stricter privacy. |
| `jct_override_ai_moderation` | filter | Whether Jev replaces the AI plugin's automatic Comment Moderation for regular comments Jev holds (default `true`). |
| `jct_triaged` | action | Fires after a decision: `do_action( 'jct_triaged', $comment_id, $assessment, $decision )`. |

```php
// Require a stronger on-topic signal, act on cached spam, stop sending author PII.
add_filter( 'jct_thresholds', fn( $t ) => [ 'relevance' => 0.9 ] + $t );
add_filter( 'jct_spam_cache', '__return_true' );
add_filter( 'jct_include_author_details', '__return_false' );
```

## Privacy

Comment text and the related post title and content (and, unless disabled via
`jct_include_author_details`, the author name, email, and URL) are sent to the
TypeSafe (Jev) service. The spam cache stores only a salted hash of the
comment and its author details, plus two probabilities. The plugin registers a
suggested privacy-policy snippet under **Settings → Privacy**.

## Benchmark

How Jev compares with the AI plugin's Comment Moderation and with Akismet. The
figures come from separate runs, described below and in
[`benchmark/`](benchmark/README.md). A dash means the comparison was not run,
or does not apply.

| | Jev | AI plugin (OpenAI) | Akismet |
| - | --- | ------------------ | ------- |
| What it decides | publish, hold, or spam | hold or publish (toxicity and sentiment) | spam or not |
| Fixtures routed as expected | **60 / 60** | 24 / 60 | — (spam only) |
| Fixtures kept from publishing (should be) | **42 / 42** | 9 / 42 | 2 / 14 |
| Fixture spam caught | **6 / 6** | — (no spam route) | 2 / 6 |
| YouTube spam caught (of 100) | **52** (84 with a spam threshold of 0.5) | — | 2 |
| Good comments held or sent to spam | 0 | 0 | 0 |
| Requests | 1 per 20 comments | 1 per comment | 1 per comment |
| Time per comment | **10–13 ms** | 2,118 ms | 180 ms |

Fixture figures use the 20 labelled comments in `bin/fixtures.php`, which
were written alongside Jev. The Jev and AI plugin comparison ran them three
times (60 comments). The Akismet comparison ran them once (20 comments). The
YouTube figures use 200 comments (100 spam) from a public dataset that neither
engine was tuned on. In this setup Akismet gets no real visitor IP, browser,
or site history, so it was judged on the comment text alone. See
[`benchmark/`](benchmark/README.md) for the limitations.

### Jev's production path

Reproducible via the tracked harness, which drives the real production path
(`wp_new_comment()` → `defer()` → `enqueue()` → `drain()` → `process_comments()`):

```sh
wp eval-file wp-content/plugins/jev-comment-triage/bin/benchmark.php repeat=3 --url=https://example.com
```

It creates a fixture post, submits 20 labelled comments per round, drains the
queue against the live Jev API, compares each comment's route with the route it
should get, and removes everything it created. Flags: `keep` retains the
fixtures, `hold-policy` keeps the site's `comment_moderation` policy, and
`spam-cache` turns the cache on. To reach the publish path it relaxes
`comment_moderation` for its own process only, so the stored setting and other
visitors are never affected, and it cleans up even when it exits early.

The fixtures include the hard cases: an abusive comment about the post, a
threat, promotion and phishing that mention the post, a prompt-injection
attempt, a legitimate documentation link, a Norwegian comment, and civil
criticism.

Run below: 60 comments (3 × 20 fixtures), `jev-latest`, default thresholds, on a
Local multisite subsite.

| Metric | Result |
| ------ | ------ |
| Submission latency (what the commenter waits for, no API call) | mean **4.6 ms**, p95 7.4 ms |
| Jev requests | 3, each with 20 comments (60 questions); mean **338 ms**, max 379 ms |
| Drain throughput | 60 comments in 1.21 s — **49.5 comments/s**, 17 ms of API time per comment |
| Input tokens | 514 per comment |
| Routed as expected | **60 / 60** |
| Published but should not be | **0** |
| Good comments sent to spam | 0 |

For comparison, the previous design (one Choice question, one request per
comment) drained 3.25 comments/s on the same site and held abusive or
threatening comments only when its confidence happened to fall below the
threshold.

Each round posts to its own fixture post, so rounds 2 and 3 replay round 1's
exact submissions on other posts. In shadow mode the cache would have answered
8 of those replays, and Jev — judging them on a different post — agreed with
all 8. With `spam-cache` enabled, those replays skipped Jev and routing stayed
60 / 60.

> **WP-Cron note:** the drain relies on WP-Cron. On low-traffic sites, add a
> real system cron calling `wp-cron.php` (and set `DISABLE_WP_CRON`) so the
> backlog is processed promptly.

### Jev vs the AI plugin's Comment Moderation (OpenAI)

A second harness sends the same fixtures, on the same post, to Jev and to the
[AI plugin](https://github.com/WordPress/ai)'s `ai/comment-analysis` (here
backed by OpenAI), and scores both against the expected routes. Nothing
changes comment status, and the fixtures are removed afterwards.

```sh
wp eval-file wp-content/plugins/jev-comment-triage/bin/compare.php repeat=3 --url=https://example.com
```

Each engine runs the way its plugin does in production: Jev answers up to 20
comments per request; the AI plugin makes one request per comment. The AI
plugin is routed by its default rule (hold when toxicity ≥ 0.7 *and* sentiment
is negative); it has no spam route. "AI + low-value hold" also holds comments
in the plugin's own *Low value* band (< 0.4). That is not something the plugin
does, but it shows the best case for its scores.

Run below: 60 comments (3 × 20 fixtures), `jev-latest` vs OpenAI `gpt-5.6-luna`,
on a Local multisite subsite.

| Metric | Jev | AI plugin (default) | AI + low-value hold |
| ------ | --- | ------------------- | ------------------- |
| Routed as expected | **60 / 60** | 24 / 60 | 42 / 60 |
| Kept from publishing (of 42 that should be) | **42 / 42** | 9 / 42 | 41 / 42 |
| Published but should not be | **0** | 33 | 1 |
| Good comments held or sent to spam | 0 / 18 | 0 / 18 | 0 / 18 |
| Requests | 3 | 60 | 60 |
| Request latency (mean / p95) | 254 / 306 ms | 2,095 / 2,756 ms | same |
| Time per comment | **12.7 ms** | 2,118 ms | same |
| Tokens in / out per comment | 510 / 83 | 638 / 63 | same |

The default AI rule only catches comments that are both toxic and negative, so
spam, promotion, phishing, prompt injection, off-topic, and junk comments
all pass. Promotion and prompt injection score as non-toxic and neutral or
positive. Its value score does separate them, but nothing in the plugin acts
on it, and it still cannot tell spam from off-topic. Jev routed every comment
correctly and was about 165× faster per comment, because it batches.

### Jev vs Akismet

[`benchmark/`](benchmark/README.md) compares Jev with
[Akismet](https://wordpress.org/plugins/akismet/) on spam detection. It uses
the fixtures and a balanced sample from the public YouTube Spam Collection.
On 200 YouTube comments, Jev caught 52 of 100 spam comments at its default
threshold, and Akismet caught 2. Neither engine flagged a legitimate comment
as spam. See the benchmark README for the method, limitations, and how to run
it.

## Architecture

See [Architecture](docs/architecture.md) for component responsibilities,
comment lifecycle states, execution flows, invariants, boundaries, and change
locations.

## License

GPL-2.0-or-later

## AI contribution attribution

When AI tools contribute to development, add an `Assisted-by` tag to the
commit message:

`Assisted-by: AGENT_NAME:MODEL_VERSION [TOOL1] [TOOL2]`

List only specialized analysis tools, not basic tools such as git or editors.
Example: `Assisted-by: Claude:claude-3-opus coccinelle sparse`
