---
description: Build ASSESSMENT.md Part 3 (hashed keys with scopes and revocation, shared 60/min rate limit, exact metering, idempotency, quota) with the superpowers workflow
argument-hint: "[extra notes]"
thinking: high
---

Build **Part 3 of `ASSESSMENT.md`**: API keys, rate limits and metering, shared by v0, v1 and MCP, without changing anything v0 callers observe.

Extra notes from the user (may be empty): $@

Use the superpowers skills (https://github.com/obra/superpowers, installed under `.agents/skills`) for the workflow and this project's skills for the Laravel details. Read each `SKILL.md` before you act on it, and announce which skill you are using at each phase.

## Ground truth

Read these first, completely:

1. `ASSESSMENT.md`: "The scenario" (the retries-double-billed complaint), "Part 3", the Part 1 and Part 2 bullets that touch auth, 401/429 and billing, and "How we assess" (Security and metering is 15%). Quote the spec; don't paraphrase from memory.
2. `docs/superpowers/specs/*` and `docs/superpowers/plans/*` for the earlier parts. Check which branch holds what (`git branch -a`, `git log --oneline --all --graph`). Part 0 is on `part-0-v0` and Part 1 on `part-1-v1` and may not be merged into `main`. If the branch this work builds on is unclear, or Part 1 or Part 2 code (v1 error shape, MCP) is missing or uncommitted, tell me before you start.
3. The existing key code: `app/Models/ApiKey.php`, `app/Actions/IssueApiKey.php`, `app/Http/Middleware/AuthenticateV0ApiKey.php`, the api_keys migration and factory, and the ingest/seed path that issues the v0 key. Today a key has `name`, `prefix`, `key_hash` (sha256) only, with no scopes, revocation, last-used or usage. Extend; don't rewrite.
4. `AGENTS.md`: code structure, TDD, "no speculative behaviour". No new top-level folders.
5. `.agents/skills/v0-contract-guard/SKILL.md` and its `references/v0-contract.md`. This part touches keys, rate limits, metering and middleware on v0's path, so the guard applies to almost every task.
6. `.ai/rules/index.md`, if it exists, and every rule file it maps to the paths you will touch.

Out of scope: the developer page and the "Connect your AI tool" panel (Part 4, but leave the data it needs easy to read: key list, last used, usage per period from the same meter), the MCP server itself (Part 2). Don't build them. Do make the key, throttle and meter code callable from MCP.

## Requirements (from the spec)

MUST:
- API keys stored hashed and shown exactly once. A visible prefix. One or more scopes (at least `search:read` and `keys:manage`). Revocable. A revoked key fails on its very next call, on REST and MCP alike (no caching that delays it).
- A per-key rate limit of **60 requests per minute**, shared across v0, v1 and MCP, returning 429 with an accurate `Retry-After`.
- Define what counts as a billable call, write the rule down, and count it exactly. Requests with the same `Idempotency-Key` within 24 hours are billed once. Decide and explain how an MCP client's retries are handled. Include a test that fires concurrent requests and proves the count is exact.

SHOULD: a monthly plan quota of **1,000 billable calls**. Going over returns a distinct, non-retryable error that says when the quota resets.

Constraint from v0: v0 keeps its exact status codes, body shapes and `{"error":"Invalid request"}`. A v0 429 or revoked-key response must be decided against the frozen contract, not against the v1 shape. Read the contract before choosing, and ask me if the contract is silent.

## Phase 1: Brainstorm (superpowers `brainstorming`)

Don't brainstorm what the spec decides. Spend the questions on the gaps, one at a time, with a recommended option first:

- **Key model:** where scopes live (JSON column, pivot, or enum cast), `revoked_at`, `last_used_at` (written on every call or throttled, since a write per call costs), the prefix length and the visible part, the hash (sha256 is fine for high-entropy random secrets; confirm and say why not bcrypt), and constant-time comparison.
- **Shown exactly once:** how `IssueApiKey` returns the secret once, and what happens to the existing `firstOrCreate` behaviour (it returns an existing key for a known secret, which the seed and ingest rely on). Keep that path working for the seeded v0 key.
- **Scopes:** which routes need `search:read` and which need `keys:manage`, what the key-management endpoints are (create, list, revoke; Part 4 will consume them), the error for a missing scope on v0 (frozen contract) versus v1 and MCP, and whether the seeded v0 key gets `search:read`.
- **Revocation:** check the key on every request from the database, with no cache, or an explicit cache with invalidation. Decide and justify. Name how MCP (a long-lived connection or per-call HTTP) re-checks.
- **Rate limit:** Laravel's `RateLimiter` per key with a 60/minute limit, fixed versus sliding window, the cache store and its atomicity (the default `database` store versus Redis; see `config/cache.php` and the installed version), how `Retry-After` is computed so it is accurate, and the headers on every response. How a v0 429 is rendered. One limiter shared by all surfaces, keyed by key id, never by IP.
- **Billable rule:** write the rule down in one sentence. Consider: only a successful (2xx) call that performed a search or fetch is billed; 4xx, 429, 5xx, auth failures, `tools/list`, `initialize` and `list_niches` are not (or are, decide). Does a v0 call count? (The spec says the key is shared; decide and document.) What about an empty result, and a cursor page 2? Decide each.
- **Exact counting:** the meter is an append-only `api_usages` row per billable call with a unique constraint, or an atomic counter. Which survives concurrency and a crash between response and write? Count before or after the response? Must the "usage" shown on the developer page and the quota check read from the same meter?
- **Idempotency:** the `Idempotency-Key` header; scope the key per API key; the 24-hour window; what is stored (hash of the key, plus the first response's billed state); behaviour when the same key arrives with a different request body; the race of two concurrent requests with the same key (a unique index and a lock, not check-then-insert). Does the replay return the cached response or run again unbilled? Decide.
- **MCP retries:** MCP clients send no `Idempotency-Key`. Decide whether a derived key (the JSON-RPC request id plus the session) is used, or retries are billed, and write down why. Must agree with Part 2 if it exists.
- **Quota (SHOULD):** 1,000 per calendar month in UTC or per rolling 30 days, the reset timestamp in the error, the distinct error code with `retryable: false`, checked before or after the call, and what happens on the call that crosses the limit. Do it only if I say yes, after you show the cost; the spec marks it SHOULD.
- **Error shape:** 401 (missing, invalid, revoked), 403 (scope), 429 (rate limit) and 429 or 402 (quota) in the v1 error shape `{error: {code, message, next_step, retryable}}`, with new codes added to the existing catalogue, not a parallel one.

Write the design doc to `docs/superpowers/specs/YYYY-MM-DD-part3-keys-metering-design.md`, including the billable rule in one paragraph (it will be copied to the README and `DESIGN.md`). Respect the brainstorming hard gate: **nothing is built until I approve the written doc.**

## Phase 2: Plan (superpowers `writing-plans`)

Save the plan to `docs/superpowers/plans/YYYY-MM-DD-part3-keys-metering.md`. Requirements:

- Bite-sized tasks, each with a failing Pest test first, using factories with explicit values rather than seeded data (add states such as `revoked()` and `withScopes()` to the existing `ApiKeyFactory`).
- Order: scopes and revocation first (migration, model, middleware, tests on v0, v1 and the MCP path as it exists), then the rate limit with a 429 and an exact `Retry-After` (use a frozen clock), then the meter, then idempotency, then the concurrency test, then the quota if approved.
- The **concurrency test** must fail if counting were check-then-insert or a non-atomic increment. Say how it produces real concurrency, since the default test database is sqlite in memory: for example, a file-based database with `Process` workers or `pcntl`, or a pgsql test connection. Verify what the project's `make test` runs against, and say what the test does and doesn't prove.
- Tests that a revoked key fails on its very next call, on all three surfaces; that the secret is never stored, logged or returned a second time; that the hash and prefix are right; that 429 carries `Retry-After` equal to the seconds until the window resets; and that failed calls are not billed.
- A **v0 guard run** as the first and last step of every task that touches shared code.
- Use `php artisan make:* --no-interaction` for every new file, and Boost `search-docs` before relying on Laravel 13 or Pest 5 APIs.
- Every task names its exact verification commands (below).

Show me the plan and wait for approval.

## Phase 3: Execute (superpowers `subagent-driven-development`, with `test-driven-development` per task)

- Use `using-git-worktrees`: work on a feature branch, `part-3-keys`, in an isolated worktree, branched from the branch I tell you in Phase 1.
- **Commits are explicitly authorized here.** Make one small commit per green task, with short imperative messages and no squashing. Do **not** push, merge or rebase without asking.
- Give each implementer subagent the relevant spec excerpt and the paths of the skills it must read: `.pi/skills/laravel-best-practices`, `.pi/skills/testing-best-practices`, and the v0 guard. Subagents don't inherit your context. Don't parallelise tasks that share the middleware stack or the migrations.
- RED must fail for the right reason. If a test passes on its first run, it isn't testing anything yet.
- After each task, run the per-task review (spec compliance against `ASSESSMENT.md` Part 3 first, then code quality, then a security pass: timing, secret leakage, mass assignment, logging). Handle feedback with `receiving-code-review`. Use `systematic-debugging` for any failure.
- **Never edit the v0 contract test.** If it fails, v0 broke. Fix the cause.
- Never print, log or commit a real secret. Test secrets are generated in tests.

Verification commands (check the repo's Makefile first; `make test`, `make format`, `make check` exist):

```bash
make test tests/Feature/Keys          # the narrowest test first
make format
make check                            # only when I say so
```

## Phase 4: Prove the tests bite

Break each by hand (one change at a time), watch its test fail, and revert that exact edit. Never use git to revert a mutation. Record the results as raw material for `AI_NOTES.md`:

- skip the revoked check; skip the scope check; hash comparison removed (plain compare);
- rate limit changed to 61, or keyed by IP; wrong `Retry-After`;
- billing a 4xx; billing twice for an idempotent replay; idempotency scope not per key;
- replace the atomic write with check-then-insert, and confirm the concurrency test catches it;
- quota off by one, if built.

## Phase 5: Finish

1. Run the v0 guard in full and end with its `## v0 guard:` report.
2. Use `verification-before-completion`. Show fresh output for the keys, rate-limit, metering and concurrency tests and the v0 and v1 tests, and a sample of each error kind (401, 403, 429 rate limit, quota) with its headers.
3. Add to `README.md`: the billable rule, how idempotency and MCP retries are handled, the rate-limit window, and every assumption. Keep a short list of AI mistakes caught and how, for `AI_NOTES.md`; don't write that file unless I ask.
4. Use `requesting-code-review` for a whole-branch review. Then use `finishing-a-development-branch`, presenting the options and letting me choose. Ask me to run the full suite.

Stop and ask if the spec is ambiguous in a way the design doc didn't settle, or if the v0 contract is silent on a case. Don't pick silently.
