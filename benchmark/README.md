# Benchmarks

Three benchmarks of Jev Comment Triage. Each one is a WP-CLI script that runs
against a real WordPress site and calls the live services.

| Benchmark | Script | Question |
| --------- | ------ | -------- |
| [Jev's production path](#jevs-production-path) | [`../bin/benchmark.php`](../bin/benchmark.php) | Does Jev route each comment correctly through the real submit-and-drain path, and how fast is it? |
| [Jev vs the AI plugin](#jev-vs-the-ai-plugins-comment-moderation-openai) | [`../bin/compare.php`](../bin/compare.php) | How does Jev compare with the AI plugin's Comment Moderation on the same comments? |
| [Jev vs Akismet](#jev-vs-akismet) | [`compare-akismet.php`](compare-akismet.php) | How does Jev compare with Akismet on spam, including comments neither was tuned on? |

The first two use the 20 labelled comments in
[`../bin/fixtures.php`](../bin/fixtures.php). Those comments were written
alongside Jev, so treat results on them as biased toward it. The Akismet
benchmark also uses a public dataset.

## Jev's production path

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

## Jev vs the AI plugin's Comment Moderation (OpenAI)

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

## Jev vs Akismet

A head-to-head test of Jev Comment Triage and
[Akismet](https://wordpress.org/plugins/akismet/) on spam detection.

Akismet answers one question: is this comment spam? So this benchmark compares
the two engines on that question only. Jev can also hold comments for
moderation (off-topic, unclear, or abusive). Those holds are reported, but they
do not count as spam calls.

### Results

Run on 2026-11-27 on a Local multisite subsite (WordPress 7.2-RC2, PHP 8.4).
Jev Comment Triage 2.3.0 with `jev-latest` and the default thresholds was
compared with Akismet 5.7.2. The test covered 220 comments.

| Corpus | Engine | Spam caught | Legit flagged as spam | Accuracy |
| ------ | ------ | ----------- | --------------------- | -------- |
| Fixtures (20) | Jev | **6 / 6** (100%, 61–100) | 0 / 14 | 100% (84–100) |
| | Akismet | 2 / 6 (33%, 10–70) | 0 / 14 | 80% (58–92) |
| YouTube Spam Collection (200) | Jev | **52 / 100** (52%, 42–62) | 0 / 100 (0–3.7) | 76% (70–81) |
| | Akismet | 2 / 100 (2%, 0.6–7.0) | 0 / 100 (0–3.7) | 51% (44–58) |

Ranges are 95% Wilson intervals, in percent.

- **Is the difference real?** On YouTube it is: Jev was right on 50 comments
  that Akismet got wrong, and Akismet was never right where Jev was wrong. An
  exact McNemar test gives p = 1.8 × 10⁻¹⁵. On the 20 fixtures the difference
  is 4 comments to 0, which is too few to be significant (p = 0.125).
- **Neither engine flagged a legitimate comment as spam.**
- **Jev's spam scores rank spam well, but its default threshold is cautious.**
  The ranking scores 0.996 on YouTube, where 1.0 would put every spam comment
  above every legitimate one. At the default spam threshold of 0.90, Jev only
  catches 52%. On the same run, lower thresholds catch more:

  | `spam` threshold | Spam caught | Legit flagged as spam |
  | ---------------- | ----------- | --------------------- |
  | 0.90 (default) | 52 / 100 | 0 / 100 |
  | 0.70 | 75 / 100 | 0 / 100 |
  | 0.50 | 84 / 100 | 0 / 100 |
  | 0.30 | 90 / 100 | 2 / 100 |

  These numbers come from the same run, not from a separate held-out test.
  Of the 48 YouTube spam comments Jev did not mark as spam, it held 45 for
  moderation and approved 3.
- **Speed.** Jev used 11 requests for 220 comments, at a mean of 204 ms per
  request, or 10 ms per comment. Akismet sends one request per comment, at a
  mean of 180 ms each.

The per-comment results are in
[`results/jev-akismet.csv`](results/jev-akismet.csv). It has the label, Jev's
route and scores, Akismet's verdict, and the comment text for each row.

### What was tested

Two sets of comments:

| Corpus | Comments | Notes |
| ------ | -------- | ----- |
| Fixtures | 20 (6 spam) | The labelled comments in [`../bin/fixtures.php`](../bin/fixtures.php), on a post about multisite caching. Spam includes outright spam, on-topic promotion, phishing, and prompt injection. These comments were written alongside Jev, so this result is biased toward it. |
| [YouTube Spam Collection](https://archive.ics.uci.edu/dataset/380/youtube+spam+collection) | 200 (100 spam) | A public dataset that neither engine was tuned on (CC BY 4.0). It has 1,956 labelled comments on five music videos. The benchmark draws a balanced sample of 20 spam and 20 legitimate comments per video. Rows are picked by `md5(seed . COMMENT_ID)`, so the same seed always gives the same sample. Each video becomes a post that gives Jev its context. |

Each engine runs through the same code its plugin uses in production:

- **Jev:** `Assessment::assess()`, up to 20 comments per request (the
  `jct_comments_per_request` setting). The answers are routed by `decide()` as
  if the site would otherwise approve the comment.
- **Akismet:** `Akismet::auto_check_comment()`, one request per comment, in the
  REST context. A "discard" verdict therefore comes back as an error instead of
  stopping the script, and the benchmark counts it as spam.

No comment is stored, so neither plugin changes anything. The script only
creates the context posts, and deletes them afterwards. It sends Akismet
requests with `is_test=true`, so the run does not train the Akismet account.
It also restores the `akismet_spam_count` option when it finishes.

Request time is measured where each request leaves WordPress, using the
`pre_http_request` and `http_api_debug` hooks.

### Limitations

- **Akismet works partly blind.** A command-line run has no visitor IP, browser
  user agent, or JavaScript checks, and Akismet relies on those signals; Jev
  does not. To stand in for them, each comment is sent with an IP address that
  stays the same for each author, from the 198.51.100.0/24 documentation range,
  and with a fixed desktop Safari user agent. On a live site, with real
  visitors and site history, Akismet would likely catch more. This benchmark
  measures what each engine can tell from the comment itself.
- **No email addresses.** The fixtures use made-up `@example.test` addresses.
  Akismet treats that reserved domain as spam regardless of the comment: in a
  first run it flagged all 14 legitimate fixture comments as spam. Both engines
  are therefore sent no email, and the YouTube rows have none anyway.
- **Most YouTube spam has no links.** It is mostly self-promotion like "check
  out my channel", which link-based signals miss. The comments come from
  music-video pages, not blog posts.
- **Is Akismet's low catch rate a setup error?** Two checks suggest it is not.
  Akismet's built-in test author, `akismet-guaranteed-spam`, returns spam.
  Sending 20 YouTube spam comments without `is_test` gave the same verdicts.
- **One run only.** Jev's answers can vary from run to run, and this benchmark
  ran once. To estimate the spread, rerun it with a few different seeds.

### Running it

Requirements:

- A WordPress site with Jev Comment Triage and its AI provider active.
- Akismet active with a valid API key (`wp option get wordpress_api_key`).
- WP-CLI, `curl`, and `unzip`.

The site makes one Akismet request per comment and about one Jev request per
20 comments. A sample of 200 takes about a minute.

#### Quick run

From the plugin directory:

```sh
benchmark/run.sh https://example.com            # sample 200, seed "jct"
benchmark/run.sh https://example.com 500 other  # sample 500, seed "other"
```

The first run downloads the dataset to `benchmark/data/`, which git ignores.
Each run writes `benchmark/results/jev-akismet.csv`. To use a different WP-CLI
command, such as the Local wrapper:

```sh
WP="bash ~/.agents/skills/wp-cli-local/scripts/wp" benchmark/run.sh http://plugins.local/subsite30
```

#### Manual run

```sh
curl -fsSLo /tmp/yt.zip https://archive.ics.uci.edu/static/public/380/youtube+spam+collection.zip
unzip -oq /tmp/yt.zip -d /tmp/ytspam -x '__MACOSX/*'
wp eval-file wp-content/plugins/jev-comment-triage/benchmark/compare-akismet.php \
    dataset=/tmp/ytspam sample=200 seed=jct out=/tmp/jev-akismet.csv --url=https://example.com
```

| Argument | Default | Meaning |
| -------- | ------- | ------- |
| `dataset=<dir>` | none | Folder with the `Youtube0*.csv` files. Leave it out to run the fixtures only. |
| `sample=<n>` | 200 | Total YouTube comments, split evenly across the five videos and the two classes. |
| `seed=<s>` | `jct` | Controls which comments are sampled. |
| `out=<file>` | none | Write a per-comment CSV. |
| `keep` | off | Keep the context posts. By default they are deleted. |

The script prints, for each corpus:

- the counts of spam caught and missed, and of legitimate comments flagged or
  passed, with 95% Wilson intervals;
- precision, F1, and accuracy;
- an exact McNemar test on the cases where only one engine was right;
- how well Jev's spam score ranks spam above legitimate comments (ROC AUC);
- how Jev routed the spam and the legitimate comments.

It then prints request times for both engines.

#### Files

| File | Purpose |
| ---- | ------- |
| [`compare-akismet.php`](compare-akismet.php) | The benchmark script, run with `wp eval-file`. |
| [`run.sh`](run.sh) | Downloads the dataset and runs the script. |
| [`results/jev-akismet.csv`](results/jev-akismet.csv) | Per-comment results of the run above. |
| [`../bin/fixtures.php`](../bin/fixtures.php) | The fixture comments, shared with the other benchmarks in `bin/`. |

## Dataset attribution

The YouTube Spam Collection is by T. C. Alberto, J. V. Lochter, and T. A.
Almeida, from the
[UCI Machine Learning Repository](https://archive.ics.uci.edu/dataset/380/youtube+spam+collection)
([doi:10.24432/C58885](https://doi.org/10.24432/C58885)). It is licensed under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/). The results CSV
contains excerpts from it.

See the [project README](../README.md#ai-contribution-attribution) for AI
contribution attribution.
