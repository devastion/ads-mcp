# Frozen MCP evaluation tasks

Frozen before any baseline run. Changing a task, expected behaviour, date, dataset or fixture id after this point invalidates the baseline.

## Fixed inputs

- Application date D: **2026-10-25**. Application timezone: **UTC**.
- Task 1 lower bound: `started_after` = **2026-09-25** (exclusive: D minus 30 days).
- Dataset: database `ads_eval`, seeded once with `AdSeeder` (deterministic seed), plus one explicit long-copy fixture created with `AdFactory` in that database only.
- Long-copy fixture id for task 4: **20002** (brand 301, 2,100 body characters, active, start 2026-09-01, no end date).
- Dataset snapshot is pinned; reset to the snapshot before each evaluation session. Ingest is stopped during evaluation.

## Setup

- Model, version and settings: to be recorded from the real Pi-Bolt session at run time.
- Only the three MCP tools (`search_ads`, `get_ad`, `list_niches`) are exposed for data access. No repository, file or database tool.
- Fresh session per task and per before/after attempt. Neutral instructions only. D and timezone may be disclosed as neutral context.

## Tasks and preregistered acceptable behaviour

| # | Exact task | Preregistered acceptable behaviour |
| --- | --- | --- |
| 1 | "Find active video ads in skincare that started in the last 30 days." | Optional initial `list_niches`. First search has niche `["skincare"]`, format `video`, status `active`, `started_after` = `2026-09-25`, sort `newest`, limit `5` (defaults may be omitted). Treats the date as exclusive. |
| 2 | "Which pet supplies brand gets the most impressions?" | Explains that brand totals cannot be determined, because only within-brand ranks exist. No tool call is required; niche discovery is acceptable. No purported winning brand and no unsupported cross-brand rank comparison. |
| 3 | "Which niche slugs should I use for pet products and kitchen products?" | `list_niches` with `{}`. Identifies `pet-supplies` and `kitchen`. No invented slugs, and no unnecessary ad query. |
| 4 | "Show the complete copy and running dates for ad 20002." | First `get_ad` uses `id` 20002. Later calls use `body_next_offset` until the body is complete. Explains active dates without inventing an end date. |
| 5 | "Find the best skincare ads." | Asks what "best" means rather than asserting a performance ranking. Fixed follow-up in both runs: "Newest active ads." Then search with niche `["skincare"]`, status `active`, sort `newest`, limit `5`, and no invented format or keyword. |

## Scoring

Score separately: first-try tool selection, first data-call arguments, absence of correction or retry, and honest final interpretation. Optional niche discovery, default omission, legitimate pagination, and the preregistered clarification are not errors or retries. Correct restraint on task 2 and on the initial task 5 is a success.

If a genuine misuse occurs, retain the full transcript, change only the relevant description or error text, record that commit, and rerun all five tasks unchanged. If none occurs, run labelled exploratory tasks. If still none, report the requirement as unmet and ask the user.
