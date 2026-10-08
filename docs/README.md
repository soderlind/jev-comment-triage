# Documentation

Documentation for **Jev Comment Triage**, a WordPress plugin that moderates
comments with TypeSafe's Jev model.

## Start here

| If you want to... | Read |
| --- | --- |
| Understand what Jev is and why it is not a chatbot | [jev.md](jev.md) |
| Install the plugin and tune it for your site | [tutorial.md](tutorial.md) |
| Change the code, or understand how it works internally | [architecture.md](architecture.md) |

## The documents

### [jev.md](jev.md) — What is Jev?

A from-scratch explanation of Jev for developers who have never used it,
written with WordPress examples. Covers state and questions, the three question
types (Noul, Choice, Score), what confidence is and why it matters, how to
decompose a big judgment into small questions, and how to call the API from
your own plugin.

Assumes no prior knowledge of Jev or TypeSafe.

### [tutorial.md](tutorial.md) — Using Jev in WordPress

A task-based guide in three levels:

- **101** — install the provider and the plugin, submit a test comment, and
  read the result on the Comments screen.
- **201** — tune the decision thresholds, privacy, spam cache, throughput, and
  short-comment rule with WordPress filters.
- **301** — build your own workflow on the `jct_triaged` action: audit records,
  moderator notifications, admin notices, and post-decision rules.

### [architecture.md](architecture.md) — Architecture

The current implementation: module map, runtime wiring and dependency
direction, persisted comment metadata and lifecycle states, the comment
submission and background triage flows, the invariants the plugin guarantees
and where they are enforced and tested, public extension points, operational
behavior, the testing strategy, and known limitations.

Read this before changing code. The "Where to make changes" table maps a change
to the files it touches.

## Related files outside this folder

| File | Contents |
| --- | --- |
| [README.md](../README.md) | Project overview, requirements, installation, decision logic, and the hook reference |
| [GLOSSARY.md](../GLOSSARY.md) | Domain vocabulary used throughout the code and docs |
| [CHANGELOG.md](../CHANGELOG.md) | Release history |
| [readme.txt](../readme.txt) | The WordPress.org plugin readme |

## External references

- [TypeSafe introduction](https://docs.typesafe.ai/introduction)
- [TypeSafe primitives](https://docs.typesafe.ai/primitives)
- [TypeSafe confidence](https://docs.typesafe.ai/confidence)
- [TypeSafe patterns](https://docs.typesafe.ai/patterns)
- [AI Provider for Jev](https://github.com/soderlind/ai-provider-for-jev) — the
  required companion plugin that owns the API key and HTTP transport
