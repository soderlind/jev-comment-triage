# Acceptance criteria

This file is the contract for the Playwright acceptance suite in this folder. Each test title starts
with the criterion ID it proves. Change this file first if a criterion changes.

## Environment

- Site: `http://plugins.local/subsite30` (Local by Flywheel multisite), with bbPress and
  AI Provider for Jev active and the Jev API key configured. Jev is called live.
- Chromium only, one worker, no retries. The drain lock is not atomic, so tests must not run in parallel.
- The background drain is triggered with WP-CLI: `wp cron event run jct_drain`. The test mu-plugin
  stops web requests from draining the queue, so the held state can be observed before the drain.
- Each submitted body ends with a per-test `(ref …)` tag. WordPress and bbPress reject a repeated body
  from the same author, and rows from earlier runs persist, so the tag also serves as the lookup key.
  bbPress leaves reply titles empty, so replies are found on the Replies screen by their topic title.
- Global setup creates a subscriber, an author, and a bbPress moderator with random passwords. It also
  creates the test forum and a test post, sets `comment_moderation` to `0`, and installs a test
  mu-plugin. That mu-plugin turns off bbPress flood checks and WordPress comment flood checks.
  When the `jct_e2e_provider_off` cookie is sent, it also hides the API key.
- Global teardown deletes the users. Their posts are reassigned to the admin first. It also deletes
  the test post and its comments, restores `comment_moderation`, and removes the mu-plugin. The test
  forum, topics, and replies are kept on purpose and are reassigned to the admin (user 1).

## Criteria

| ID | Actor | Given | When | Then | Evidence |
| --- | --- | --- | --- | --- | --- |
| AC-01 | Subscriber | The provider is configured and a post is open for comments. | The subscriber posts an on-topic comment from the front end. | It shows as awaiting moderation. After the drain it is approved, and the Jev column shows a decision. | `jev-comment-triage.php:83`, `includes/intake.php:65`, `includes/processor.php:251` |
| AC-02 | Subscriber | Same as AC-01. | The subscriber posts obvious spam. | After the drain the comment is listed under Spam. | `includes/policy.php:42`, `includes/processor.php:251` |
| AC-03 | Author | An approved comment by a subscriber is on the author's own post. | The author edits the comment text into spam in wp-admin. | The comment is held right away. After the drain it is spam. | `jev-comment-triage.php:86`, `includes/intake.php:222` |
| AC-04 | Admin, Author | An approved comment that Jev has already judged. | An admin changes its text, or the author saves it without changing the text. | The status and the Jev result stay the same, and nothing is queued. | `includes/intake.php:222` (moderator and unchanged-text exits) |
| AC-05 | Subscriber | A public forum. | The subscriber creates a topic from the bbPress form. | The topic is pending. After the drain it is published. | `includes/bbpress.php:101`, `includes/bbpress.php:128`, `includes/bbpress.php:298` |
| AC-06 | Subscriber | A published topic. | The subscriber posts an on-topic reply, and then a spam reply. | After the drain the on-topic reply is published and the spam reply is spam. | `includes/bbpress.php:101`, `includes/bbpress.php:298` |
| AC-07 | Subscriber, Admin | A published reply by the subscriber. | The subscriber edits it into spam on the front end. After the drain, an admin clicks Unspam on the Replies screen. | After the drain the reply is spam. After Unspam it is pending. | `includes/bbpress.php:154`, `includes/bbpress.php:197`, `includes/bbpress.php:298` |
| AC-08 | Forum moderator | A published topic by a subscriber. | The moderator replies, and then edits the subscriber's topic. | The reply is published immediately with no Jev result (`—`). The edited topic stays published and is not queued. | `includes/bbpress.php:73`, `includes/bbpress.php:101`, `includes/bbpress.php:154` |
| AC-09 | Admin | Comments, topics, and replies that Jev has judged. | The admin opens the Comments, Topics, and Replies screens. | Each screen has a Jev column that shows the decision for that item. | `includes/admin.php:17`, `includes/admin.php:48`, `includes/bbpress.php:44`, `includes/bbpress.php:345` |
| AC-10 | Subscriber | The provider is not configured (API key hidden). | The subscriber creates a topic. | The topic is published immediately and never queued. | `includes/core.php:22`, `includes/bbpress.php:101` |

## Out of scope

- Retries and back-off after Jev errors, the spam cache, and the short-text rule. Unit tests cover these
  (`tests/Unit`).
- Anonymous posting. It is turned off on the test site (`_bbp_allow_anonymous=0`).
