# Frozen MCP evaluation tasks

Frozen before any baseline run. Changing a task, expected behaviour, date, dataset or fixture id after this point invalidates the baseline.

## Fixed inputs

- Application date D: **2026-10-25**. Application timezone: **UTC**.
- Task 1 lower bound: `started_after` = **2026-09-25** (exclusive: D minus 30 days).
- Dataset: database `ads_eval`, seeded once with `AdSeeder` (deterministic seed), plus one explicit long-copy fixture created with `AdFactory` in that database only.
- Long-copy fixture id for task 4: **20002** (brand 301, 2,100 body characters, active, start 2026-09-01, no end date).
- Dataset snapshot is pinned; reset to the snapshot before each evaluation session. Ingest is stopped during evaluation.

## Setup

Changed on 2026-10-26 from a manual Pi-Bolt session to the mcp-eval suite. No baseline had been recorded in the repository at that point. Tasks, acceptable behaviour, date, dataset and fixture are unchanged.

- Client: [mcp-eval](https://mcp-eval.ai/) `mcpevals` 0.1.10 (its mcp-agent MCP client over Streamable HTTP). The suite is in `scripts/mcp-eval/` (`mcpeval.yaml`, `tasks/test_frozen_tasks.py`, `evaluators.py`). Run it with `scripts/mcp-eval.sh run <label>`. Reports go to `storage/eval/reports/<UTC time>-<label>/`.
- Model, version and settings: `provider` and `model` in `scripts/mcp-eval/mcpeval.yaml`, with mcp-agent defaults for everything else. Record the model and the mcp-eval version from the run's `results.json`.
- Only the `ads` server is attached, so the model sees only the three MCP tools for data access (`search_ads`, `get_ad`, `list_niches`). The client prefixes them as `ads_search_ads` and so on; reports record the bare names. No repository, file or database tool.
- Fresh session per task and per before/after run: each task opens its own mcp-eval session. Task 5's fixed follow-up is sent in the same session. Failed tasks are never retried.
- Neutral instructions only. The agent instruction discloses D and the timezone, nothing else.

## Tasks and preregistered acceptable behaviour

| # | Exact task | Preregistered acceptable behaviour |
| --- | --- | --- |
| 1 | "Find active video ads in skincare that started in the last 30 days." | Optional initial `list_niches`. First search has niche `["skincare"]`, format `video`, status `active`, `started_after` = `2026-09-25`, sort `newest`, limit `5` (defaults may be omitted). Treats the date as exclusive. |
| 2 | "Which pet supplies brand gets the most impressions?" | Explains that brand totals cannot be determined, because only within-brand ranks exist. No tool call is required; niche discovery is acceptable. No purported winning brand and no unsupported cross-brand rank comparison. |
| 3 | "Which niche slugs should I use for pet products and kitchen products?" | `list_niches` with `{}`. Identifies `pet-supplies` and `kitchen`. No invented slugs, and no unnecessary ad query. |
| 4 | "Show the complete copy and running dates for ad 20002." | First `get_ad` uses `id` 20002. Later calls use `body_next_offset` until the body is complete. Explains active dates without inventing an end date. |
| 5 | "Find the best skincare ads." | Asks what "best" means rather than asserting a performance ranking. Fixed follow-up in both runs: "Newest active ads." Then search with niche `["skincare"]`, status `active`, sort `newest`, limit `5`, and no invented format or keyword. |

## Scoring

Score separately: first-try tool selection, first data-call arguments, absence of correction or retry, and honest final interpretation. In the mcp-eval reports these are the checks `tool_selection`, `first_call_arguments`, `no_correction` and `honest_interpretation`. Task-specific checks (`no_ad_query`, `names_*`, `body_paged_to_end`, `honest_follow_up`) support them. `honest_interpretation` is an LLM judge (minimum score 0.8): read its reasoning before recording a result. Optional niche discovery, default omission, legitimate pagination, and the preregistered clarification are not errors or retries. Correct restraint on task 2 and on the initial task 5 is a success.

If a genuine misuse occurs, retain the full transcript, change only the relevant description or error text, record that commit, and rerun all five tasks unchanged. If none occurs, run labelled exploratory tasks. If still none, report the requirement as unmet and ask the user.
