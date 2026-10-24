---
description: Build ASSESSMENT.md Part 1 (v1 search API with strict validation, keyset cursors, one error shape, OpenAPI) with the superpowers workflow
argument-hint: "[extra notes]"
thinking: high
---

Build **Part 1 of `ASSESSMENT.md`**: the v1 search API (`GET /api/v1/ads`), without changing anything v0 callers observe. Part 0 is done; its decisions are recorded in the docs below.

Extra notes from the user (may be empty): $@

Use the superpowers skills for the workflow and this project's skills for the Laravel details. Read each `SKILL.md` before you act on it, and announce which skill you are using at each phase.

## Ground truth

Read these first, completely:

1. `ASSESSMENT.md`: "The scenario", "Part 1", and "How we assess". Quote the spec; don't paraphrase from memory. The five customer complaints in the scenario are the acceptance story: unknown filters return 200, vague errors, duplicates and gaps while paging, null impressions on top, retries double-billed (billing is Part 3, out of scope here).
2. `docs/superpowers/specs/*-part0-v0-design.md` and `docs/superpowers/plans/*-part0-v0.md`. They record the data model, the shared `app/Actions/SearchAds` (decision B), `Ad::daysActive()`, the enums, the key model, and the v0 edge-case rulings. Check the code on the current branch against them. If Part 0 lives on another branch (for example `part-0-v0`), tell me before you start.
3. `AGENTS.md`: the code structure, TDD, "no speculative behaviour". No new top-level folders. Subfolders such as `V1/` are fine.
4. `.agents/skills/v0-contract-guard/SKILL.md` and its `references/v0-contract.md`.
5. `.ai/rules/index.md`, if it exists, and every rule file it maps to the paths you will touch.

Out of scope: MCP, scopes, revocation, rate limiting, metering and quotas, the developer page. Don't build them. Do keep the v1 error codes reusable, because the MCP server will reuse them.

## Requirements (from the spec)

MUST:
- Keyword search `q`. Filters: `niche` (one or more slugs), `format`, `status`, `started_after` (a date).
- Sorts: `newest` (by `start_date`) and `impressions`. Ads with a null `impression_rank` always come last. Ties break deterministically.
- Reject unknown parameters and invalid values. State the allowed values and, where possible, what the caller probably meant (`nich` → `niche`, `Skincare` → `skincare`).
- Every successful response echoes the filters actually applied, after normalisation.
- Cursor pagination that stays correct while the ingest command runs, for both sorts. Walking every page never repeats an ad, and never skips an ad that existed when page 1 was served and still matches. Write down what the cursor promises about ads inserted or deactivated mid-walk, and prove it with a test that mutates data between pages.
- One error shape everywhere, including 401, 404, 422, 429 and 500: a machine-readable code, a human message, what to do next, and whether retrying can help. This applies to `api/v1/*` only. v0 keeps `{"error":"Invalid request"}`.
- An OpenAPI spec kept true by generation or by a contract test that fails on drift.
- One test per filter that proves the filter changes the results, and that would fail if the filter were silently ignored.

SHOULD: `Authorization: Bearer` auth, with the same key working on v0 (query parameter) and v1 (header).

## Phase 1: Brainstorm (superpowers `brainstorming`)

Don't brainstorm what the spec decides. Spend the questions on the gaps, one at a time, with a recommended option first:

- **Sharing with v0:** how v1 extends `SearchAds` without any v0-visible change (options on the Action, a v1-only Action sharing a query builder, or something else). v0 must not gain a tiebreaker, strictness, or cursor fields. The guard's Step 1 row "Shared search query or Action" applies.
- **The impressions sort:** `impression_rank` only compares ads within one brand, and rank 1 at a small brand is not comparable to rank 1 at a large one. Decide what "most impressions" means across brands (for example rank ascending, then a documented tiebreaker). State the limitation in the docs and the response rather than hide it.
- **Cursor design:** an opaque keyset cursor over `(sort key, id)` with the null-last rule, and the cursor bound to the query (a changed filter or sort must be rejected). Is it signed or validated? What does it promise for ads inserted, deactivated or backdated mid-walk? An ad discovered later with an old `start_date` lands in the middle of a `newest` walk. Say plainly what is and isn't guaranteed.
- **Error catalogue:** the codes, the `next_step` wording, and the `retryable` rule. Shape: `{error: {code, message, next_step, retryable, …}}`. Decide how to scope rendering to `api/v1/*` in `bootstrap/app.php` so the v0 exception rendering stays byte-identical.
- **"Did you mean":** the matching approach for param names and values (case-insensitive lookup plus a small edit distance), and the threshold.
- **Validation details:** `per_page` default and max (out of range is rejected, not clamped, unlike v0), the `started_after` date format, the repeated or comma-separated form of `niche`, whether `q` has a minimum length, and whether an empty value counts as absent or invalid.
- **Response shape:** compact fields (include `days_active` from `Ad::daysActive()`, consistent with v0), `applied_filters`, `next_cursor`, and whether `total` is included (counting is expensive at scale; this feeds `DESIGN.md`).
- **OpenAPI:** generated (a package or a route/FormRequest reflection) versus hand-written plus a contract test. Decide how drift is detected. This needs my sign-off, since a package is a dependency change.
- **Auth:** how v1 accepts Bearer while v0 still takes `?api_key=`, and how a v1 401 uses the new error shape.
- **Database divergence:** tests may run on sqlite while the app runs on pgsql. Choose a text-match and keyset-comparison strategy that behaves the same on both, including null ordering, and verify it against the installed versions.

Write the design doc to `docs/superpowers/specs/YYYY-MM-DD-part1-v1-design.md`. Respect the brainstorming hard gate: **nothing is built until I approve the written doc.**

## Phase 2: Plan (superpowers `writing-plans`)

Save the plan to `docs/superpowers/plans/YYYY-MM-DD-part1-v1.md`. Requirements:

- Bite-sized tasks, each with a failing Pest test first, using factories with explicit values rather than seeded data. Tests must not depend on row order among equal sort keys.
- Make the **error-shape test and the unknown-parameter rejection** early tasks, then filters, sorts, the cursor, the OpenAPI spec and Bearer auth.
- Include a **v0 guard run** as the first and last step of every task that touches shared code.
- Use `php artisan make:* --no-interaction` for every new file, and the Boost `search-docs` tool before relying on Laravel 13 or Pest 5 APIs.
- Every task names its exact verification commands (below).

Show me the plan and wait for approval.

## Phase 3: Execute (superpowers `subagent-driven-development`, with `test-driven-development` per task)

- Use `using-git-worktrees`: work on a feature branch, `part-1-v1`, in an isolated worktree.
- **Commits are explicitly authorized here.** Make one small commit per green task, with short imperative messages and no squashing. Do **not** push, merge or rebase without asking.
- Give each implementer subagent the relevant spec excerpt and the paths of the skills it must read: `.pi/skills/laravel-best-practices`, `.pi/skills/testing-best-practices`, and the v0 guard. Subagents don't inherit your context. Parallelise only truly independent tasks, such as the per-filter tests (`dispatching-parallel-agents`).
- RED must fail for the right reason. If a test passes on its first run, it isn't testing anything yet.
- After each task, run the per-task review (spec compliance against `ASSESSMENT.md` Part 1 first, then code quality). Handle feedback with `receiving-code-review`. Use `systematic-debugging` for any failure, and don't guess at fixes.
- **Never edit the v0 contract test.** If it fails, v0 broke. Fix the cause.

Verification commands (check the repo for a Makefile or composer scripts first; if none exist, use these):

```bash
php artisan test --compact <path-or-filter>   # the narrowest test first
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

## Phase 4: Prove the tests bite, then finish

1. **Filter mutation check:** for each filter, sort, the cursor, and unknown-parameter rejection, disable it by hand (one change at a time), watch its test fail, and revert that exact edit. Never use git to revert a mutation. Record the results. This is raw material for `AI_NOTES.md`.
2. **Pagination under load:** run a walk of both sorts while `ads:ingest` runs (or a test that simulates it). Show no duplicates and no skipped ads.
3. Run the v0 guard in full and end with its `## v0 guard:` report.
4. Use `verification-before-completion`. Show fresh command output for the v1 and v0 tests, the OpenAPI drift check, and a sample success response and error response of each kind.
5. Use `requesting-code-review` for a whole-branch review. Then use `finishing-a-development-branch`, presenting the options and letting me choose. Ask me to run the full suite.

Add to `README.md` every assumption you made and the cursor guarantees. Keep a short list of AI mistakes caught and how, for `AI_NOTES.md`; don't write that file unless I ask.

Stop and ask if the spec is ambiguous in a way the design doc didn't settle. Don't pick silently.
