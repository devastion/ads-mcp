---
description: Build ASSESSMENT.md Part 0 (data model, seed, live ingest, frozen v0 endpoint) with the superpowers workflow
argument-hint: "[extra notes]"
thinking: high
---

Build **Part 0 of `ASSESSMENT.md`**: the data model, the deterministic seed, the live ingest script, and the `GET /api/v0/ads` endpoint with its contract test. v0 cannot exist without the world it reads from, so all four are in scope.

Extra notes from the user (may be empty): $@

Use the superpowers skills for the workflow and this project's skills for the Laravel details. Read each `SKILL.md` before you act on it, and announce which skill you are using at each phase.

## Ground truth

Read these first, completely:

1. `ASSESSMENT.md`, sections 0.1–0.4. They are the spec. Quote them; don't paraphrase from memory.
2. `AGENTS.md`, for the code structure (`app/Actions`, `app/DTO`, `app/Enums`, `app/Http/Resources`, …), TDD, and the "don't implement speculative behaviour" rule. Don't add new top-level folders without asking.
3. `.agents/skills/v0-contract-guard/SKILL.md` and its `references/v0-contract.md`. The guard's checklist (§6) is the minimum contract test, and its quirks table (§4) lists what you must **not** fix.
4. `.ai/rules/index.md`, if it exists, and every rule file it maps to the paths you will touch.

Out of scope: v1, MCP, scopes, rate limiting, metering and quotas. Build only what v0 needs today. Part 3 will extend the key model later, so YAGNI applies.

## Phase 1: Brainstorm (superpowers `brainstorming`)

The spec already fixes most behaviour, so don't brainstorm what it decides. Spend the questions on the gaps, one question at a time, with a recommended option first:

- **Ad `id`:** an auto-increment integer, or a string/ULID? The sample response shows a string.
- **Niche storage:** a `niches` table, or a backed enum with display names? Either way, slug **or** display name has to match case-insensitively.
- **`countries` storage:** a JSON column or a pivot table.
- **API keys before Part 3:** the smallest key model that lets v0 check `?api_key=` and that Part 3 can grow into (hashed plus a visible prefix, with no scopes yet). Also, how do local and dev users get a key?
- **Seed determinism:** a fixed Faker seed is not enough if dates are relative to "today". Decide what "running it twice produces the same data" means here: a fixed anchor date, or same data on the same day.
- **Ingest form:** an artisan command that loops until it receives SIGINT/SIGTERM. Make it testable through an injectable tick or iteration count, not a real sleep in tests. 30% of new ads backdate their `start_date` by up to 90 days.
- **Database divergence:** the app runs on pgsql (`.env.example`), but tests run on in-memory sqlite (`phpunit.xml`). `LIKE` is case-insensitive on sqlite and case-sensitive on pgsql. Choose a matching strategy that behaves the same on both, and decide how `%` and `_` in `q` are handled.
- **The unspecified v0 edge cases** in the guard's `references/v0-contract.md` §5: decide each one explicitly, because whatever ships becomes the frozen contract.

Write the design doc where the skill says, `docs/superpowers/specs/YYYY-MM-DD-part0-v0-design.md`. Respect the brainstorming hard gate: **nothing is built until I approve the written doc.**

## Phase 2: Plan (superpowers `writing-plans`)

Save the plan to `docs/superpowers/plans/YYYY-MM-DD-part0-v0.md`. Requirements:

- Use bite-sized tasks, each with a failing Pest test first, using factories with explicit values rather than seeded data.
- Make the **v0 contract test an early task**, written red against the spec, before the controller exists.
- Use `php artisan make:* --no-interaction` for every new file, and the Boost `search-docs` tool before relying on Laravel 13 or Pest 5 APIs.
- Order the tasks so each one ends green: enums and model, migration and factory, `days_active`, seed, ingest, v0 key check, v0 endpoint and contract test.
- Every task names its exact verification commands (below).

Show me the plan and wait for approval.

## Phase 3: Execute (superpowers `subagent-driven-development`, with `test-driven-development` per task)

- Before the first task, use `using-git-worktrees`: work on a feature branch, `part-0-v0`, in an isolated worktree.
- **Commits are explicitly authorized here.** Make one small commit per green task, with short imperative messages and no squashing (the assessment grades commit history). Do **not** push, merge or rebase without asking.
- Give each implementer subagent the relevant spec excerpt and the paths of the project skills it must read: `.pi/skills/laravel-best-practices`, `.pi/skills/testing-best-practices`, and the v0 guard. Subagents don't inherit your context.
- RED must fail for the right reason before any implementation is written. If a test passes on its first run, it isn't testing anything yet.
- After each task, run the per-task review the skill describes (spec compliance first, then code quality). Check spec compliance against `ASSESSMENT.md` §0, not against the plan's paraphrase.

Verification commands (no Makefile exists, so use these):

```bash
php artisan test --compact <path-or-filter>   # the narrowest test first
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

## Phase 4: Prove the contract bites, then finish

1. Run the v0 guard's mutation check: apply each one-line mutation from its Step 4 by hand, one at a time, watch the contract test fail, and revert that exact edit. Never use git to revert a mutation. Record the results.
2. Use `verification-before-completion`. Show fresh command output for the v0 tests, `php artisan db:seed` run twice (prove the data is identical, e.g. compare row counts plus a checksum query), and a short `ads:ingest` run that shows inserts, deactivations and backdated starts.
3. Use `requesting-code-review` for a whole-branch review. Then end with the guard's `## v0 guard:` report.
4. Use `finishing-a-development-branch`, but present the options and let me choose. Ask me to run the full suite with `composer test`.

Stop and ask if the spec is ambiguous in a way the design doc didn't settle. Don't pick silently. Record every assumption in `README.md`, because the assessment rewards written assumptions.
