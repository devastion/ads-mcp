# Part 3 Keys, Rate Limits and Metering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Scoped, revocable API keys, one 60/min per-key limiter, one exact usage meter with 24 h idempotency and a 1,000/month quota, shared by v0, v1 and (later) MCP.

**Architecture:** Auth middleware only authenticates (credential, found, not revoked) and stores the `ApiKey` on the request. Three further middleware run in fixed order: `ThrottleApiKey` (shared limiter), `RequireScope`, `MeterApiUsage` (idempotency check, quota gate, ledger write after a 2xx). Billing exactness comes from one Postgres transaction guarded by an advisory lock; the ledger table `api_usages` is the only counter.

**Tech Stack:** PHP 8.5, Laravel 13.38, Pest, Postgres, Larastan 7, Pint. No new packages.

**Spec:** `docs/superpowers/specs/2026-10-25-part3-key-rate-limits-metering.md` (approved 2026-10-25). Also read `ASSESSMENT.md` Part 3, `.agents/skills/v0-contract-guard/SKILL.md` and its `references/v0-contract.md`, and `2026-10-25-part2-mcp-design.md` (consumer).

## Global Constraints

- Limit `60` requests per `60` seconds per key, one bucket `api-key:{id}` across v0, v1, MCP. Quota `1000` billed rows per key per UTC month. Idempotency window `24` hours.
- Scopes: `search:read`, `keys:manage` (enum `App\Enums\Scope`). Default for new and existing keys: `["search:read"]`. Every data read requires `search:read`.
- Middleware order on every data route: auth, throttle, require-scope, meter. A valid key spends one rate unit before the scope check; unauthenticated requests spend none.
- v1 errors use the existing envelope. New codes: `InsufficientScope` (403, `insufficient_scope`), `QuotaExceeded` (403, `quota_exceeded`), both `retryable:false`. `rate_limited` gets `details.retry_after_seconds` (int, ≥1) and a `Retry-After` header.
- **v0 stays `400 {"error":"Invalid request"}` for every failure, including rate limit and quota (user decision).** A rate-limited v0 response also carries a `Retry-After` header. `tests/Feature/Http/Controllers/V0/AdControllerTest.php` is never edited; new v0 behaviour is pinned in a new file.
- Billable: an authenticated, authorized, not-limited 2xx read of `v0/ads`, `v1/ads`, `v1/ads/{id}`, `v1/niches`, including empty results. Never billable: 4xx, 5xx, 429, quota refusal.
- `Idempotency-Key`: 1–255 printable ASCII; stored only as sha256; same key within 24 h of the first billed row bills once regardless of endpoint or parameters; no replay. Invalid format: v1 `invalid_parameter` (details.parameter `Idempotency-Key`), v0 400.
- A failed ledger write fails the request (`internal_error`), never serves an unmetered read.
- Files via `php artisan make:* --no-interaction`. Stay inside existing roots (`app/Actions`, `app/Enums`, `app/Models`, `app/Http/Middleware`, `app/Console/Commands`). No new base folders. No new packages.
- Tests: Pest `it()`, factories with explicit values, `$this->travelTo(...)`. Test cache store is `array`; the limiter logic is store-agnostic, but production uses the `database` store.
- Run from the worktree root:

  ```bash
  php artisan test --compact <path-or-filter>
  vendor/bin/pint --dirty --format agent
  vendor/bin/phpstan analyse --memory-limit=1G
  ```
  Run the v0 guard (contract test + the new v0 pin file) before and after each task that touches shared code. The user runs the full suite. Commit only at the ends of tasks and only because the user said commits are expected in this plan flow; skip commits if the user has not asked.

## Review Focus

- Revoked key with a still-cached or still-valid lookup: next call must fail on v0, v1 and the MCP-forwarded path.
- Two overlapping requests with the same `Idempotency-Key` that both see "no billed row" and both bill.
- The 61st request in a window: `Retry-After` computed from the first hit, not "60", and never 0 or negative.
- Idempotency key reused after 23 h 59 m vs 24 h 01 m; and a key with unicode, empty string, 256 characters, or a repeated header.
- Month boundary at 23:59:59 vs 00:00:00 UTC; and an over-quota request carrying an already-billed idempotency key.

## Task 0: Branch and baseline

- [ ] Commit the Part 3 spec and this plan on `part-2-mcp`. Create worktree `/Users/devastion/Projects/gethookd-worktrees/part-3-keys` on branch `part-3-keys-metering` from that commit. Copy `.env`; copy (not symlink) `vendor/` from `part-2-mcp`; run `composer dump-autoload`.
- [ ] Run `php artisan test --compact` and the v0 guard. Record the baseline (272 tests passing at planning time).

## Task 1: Scopes, key columns, issuing

**Files:** `app/Enums/Scope.php`; migration `add_scopes_and_revocation_to_api_keys_table`; modify `app/Models/ApiKey.php`, `database/factories/ApiKeyFactory.php`, `app/Actions/IssueApiKey.php`, `app/Console/Commands/CreateApiKey.php`; tests `tests/Feature/Models/ApiKeyTest.php`, extend `tests/Feature/Actions/IssueApiKeyTest.php`.

**Interfaces — Produces:**
- `enum Scope: string { SearchRead = 'search:read'; KeysManage = 'keys:manage' }`
- `ApiKey`: casts `scopes` array, `revoked_at` and `last_used_at` immutable_datetime; `isRevoked(): bool`; `hasScope(Scope $scope): bool`.
- `IssueApiKey::handle(string $name, ?string $secret = null, array $scopes = [Scope::SearchRead]): IssuedApiKey` (`list<Scope> $scopes`).
- `api-key:create {name} {--scope=* }` (default `search:read`; invalid scope fails with the valid list).

- [ ] Failing tests: new key defaults to `["search:read"]`; `hasScope` true/false; `isRevoked` follows `revoked_at`; `IssueApiKey` stores given scopes, stores only hash and 12-char prefix, and the secret is not in any column; command rejects `--scope=admin`.
- [ ] Run, confirm RED for the right reason. Implement. Migration backfills existing rows to `["search:read"]` through the column default.
- [ ] GREEN, pint, phpstan.

## Task 2: Revocation and authenticated key on the request

**Files:** `app/Actions/RevokeApiKey.php`, `app/Console/Commands/RevokeApiKeyCommand.php` (`api-key:revoke {prefix}`), modify `app/Http/Middleware/AuthenticateV0ApiKey.php`, `AuthenticateV1ApiKey.php`; tests `tests/Feature/Actions/RevokeApiKeyTest.php`, `tests/Feature/Http/Middleware/RevocationTest.php`.

**Interfaces — Produces:**
- `RevokeApiKey::handle(ApiKey $key): ApiKey` (idempotent; keeps the first `revoked_at`).
- Both auth middleware set `$request->attributes->set('api_key', ApiKey)` and update `last_used_at` on success. Revoked behaves as unknown: v0 400, v1 401 `api_key_invalid`.

- [ ] Failing tests: a key works, is revoked, and the very next call fails on v0 (400 `Invalid request`) and v1 (401 `api_key_invalid`), with no cache between; revoking twice keeps the first timestamp; command revokes by prefix and reports an unknown prefix; `last_used_at` is set after a successful call and not after a failed one.
- [ ] RED, implement, GREEN. v0 guard (contract test must still pass unchanged).

## Task 3: Shared rate limiter

**Files:** `app/Actions/ConsumeRateLimit.php`, `app/Http/Middleware/ThrottleApiKey.php`, modify `bootstrap/app.php` (renderers), `routes/api.php`; tests `tests/Feature/Actions/ConsumeRateLimitTest.php`, `tests/Feature/Http/Middleware/ThrottleApiKeyTest.php`.

**Interfaces — Produces:**
- `ConsumeRateLimit::handle(ApiKey $key): void` — spends one unit of `api-key:{id}` (60 per 60 s) using `RateLimiter::attempt`; when exhausted throws `TooManyRequestsHttpException($seconds)` where `$seconds = max(1, RateLimiter::availableIn(...))`. This class is the entry point MCP will call for local control traffic.
- `ThrottleApiKey` calls it with `$request->attributes->get('api_key')`.
- v1 renderer: for `TooManyRequestsHttpException` also sets `details.retry_after_seconds` from the `Retry-After` header. v0 renderer: keeps the 400 body, and passes through a `Retry-After` header when the exception carries one.

- [ ] Failing tests (frozen time): calls 1–60 pass, call 61 on v1 is 429 with `error.code=rate_limited`, `retryable=true`, header `Retry-After` equal to `details.retry_after_seconds`; after waiting exactly that long the call passes, one second earlier it does not; call 61 on v0 is 400 `{"error":"Invalid request"}` with a `Retry-After` header; 30 v0 calls plus 30 v1 calls with one key exhaust one shared bucket; a second key is unaffected; invalid-credential requests spend nothing.
- [ ] RED, implement, wire `routes/api.php`, GREEN, v0 guard.

## Task 4: Scope enforcement

**Files:** `app/Http/Middleware/RequireScope.php`, `app/Enums/ApiErrorCode.php` (add `InsufficientScope`, `QuotaExceeded` with status, retryable, default next step), `routes/api.php`; tests `tests/Feature/Http/Middleware/RequireScopeTest.php`, extend any `ApiErrorCode` enum test.

**Interfaces — Produces:** `RequireScope` takes the scope value as middleware parameter (`RequireScope::class.':search:read'` form chosen by the implementer after checking how colons in middleware parameters parse). v1 failure: 403 `insufficient_scope`, next step "Use a key with the search:read scope."; v0 failure: 400 `Invalid request`.

- [ ] Failing tests: key with only `keys:manage` gets 403 on every v1 data route and 400 on v0; a scope-denied call still spends one rate unit (61st denied call is 429, not 403); key with both scopes works.
- [ ] RED, implement, GREEN, v0 guard.

## Task 5: Usage ledger and metering

**Files:** migration `create_api_usages_table`; `app/Models/ApiUsage.php` + factory; `app/Actions/RecordApiUsage.php`; `app/Http/Middleware/MeterApiUsage.php`; `routes/api.php`; tests `tests/Feature/Actions/RecordApiUsageTest.php`, `tests/Feature/Http/Middleware/MeterApiUsageTest.php`.

**Interfaces — Produces:**
- Table `api_usages`: `id`, `api_key_id` fk cascade, `surface` (`v0|v1|mcp`), `endpoint` string, `status` smallint, `result_count` nullable int, `latency_ms` int, `billed` bool, `idempotency_hash` nullable char(64), `created_at`. Indexes `(api_key_id, created_at)` and `(api_key_id, idempotency_hash, created_at)`.
- `RecordApiUsage::handle(ApiKey $key, string $surface, string $endpoint, int $status, ?int $resultCount, int $latencyMs, ?string $idempotencyKey): ApiUsage`. In one transaction: when a key is given, `pg_advisory_xact_lock(hashtextextended("{id}:{hash}", 0))`, then `billed = no billed row for (key, hash) newer than now()-24h`; without a key `billed = true`. Inserts exactly one row.
- `MeterApiUsage`: before the handler validates `Idempotency-Key` (rejects invalid or repeated header); after a 2xx response calls `RecordApiUsage` with `surface` from the route prefix (`v0`/`v1`), `endpoint` as the route name, `result_count` from the JSON `data` array length for list responses (1 for detail, null otherwise), latency from a monotonic timer.
- `surface = mcp` is set only from a server-side marker the Part 2 forwarder adds; this task defines the constant `MeterApiUsage::ORIGIN_HEADER = 'X-Ads-Origin'` and strips that header from any request not from the trusted forwarder (decided in Part 2; until then it is always stripped).

- [ ] Failing tests: every billable endpoint writes one billed row, including an empty search; 422, 404, 429, 400 and 500 write none; row fields are correct; same key twice → one billed, one unbilled; a different key bills; same key after 24 h → billed again (23 h 59 m → not); no key → billed every time; two keys of different API keys do not collide; hash column holds sha256 and never the raw header; invalid format (empty, 256 chars, non-ASCII, repeated header) → v1 422 / v0 400 and no row; ledger write failure (forced exception) → 500 `internal_error`, no body data.
- [ ] RED, implement, GREEN, v0 guard (v0 body unchanged under metering).

## Task 6: Concurrency proof

**Files:** `tests/Feature/Concurrency/MeteringConcurrencyTest.php`, `tests/Pest.php` (scope the committed-data traits to this directory).

- [ ] Write a test that fires genuinely overlapping requests from separate processes (use Laravel's `Concurrency` facade with the process driver, or `Process::pool`) against the test database with **committed** fixtures (not the transaction-wrapped `LazilyRefreshDatabase`; use truncation for cleanup). Cases: 20 parallel calls with one `Idempotency-Key` → exactly 1 billed row and 19 unbilled; 20 parallel calls with distinct keys → 20 billed; 20 parallel without a key → 20 billed. Assertion is on `api_usages` counts, not a mocked counter.
- [ ] Verify the harness works first (child processes use the testing database and environment). If the Pest `uses()` setup conflicts with the global `LazilyRefreshDatabase`, resolve it in `tests/Pest.php` by directory and record the choice at the top of the file.
- [ ] Prove the test can fail: temporarily remove the advisory lock from `RecordApiUsage`, run the test, confirm it fails with more than one billed row, then restore the lock by exact edit.

## Task 7: Monthly quota

**Files:** `app/Actions/CheckApiQuota.php`, modify `app/Http/Middleware/MeterApiUsage.php`; tests `tests/Feature/Actions/CheckApiQuotaTest.php`, `tests/Feature/Http/Middleware/QuotaTest.php`.

**Interfaces — Produces:** `CheckApiQuota::handle(ApiKey $key, ?string $idempotencyKey): void` — throws `ApiErrorException(QuotaExceeded, ..., details: ['resets_at' => <first instant of next UTC month, ISO 8601>])` when billed rows this UTC month ≥ 1000 and the idempotency key has no billed row in the last 24 h. v0 renders 400. Called from `MeterApiUsage` before the handler.

- [ ] Failing tests (frozen time): with 999 billed rows the next call passes and is billed; with 1000 the next is refused with `quota_exceeded`, non-retryable, `resets_at` of the next month at 00:00:00 UTC, and writes no row; unbilled duplicate rows do not count; rows from last month do not count; on 31 Oct 23:59:59 the key is refused, on 1 Nov 00:00:00 it works; an over-quota call whose idempotency key was billed in the last 24 h passes and is recorded unbilled; v0 over quota is 400 `Invalid request`.
- [ ] RED, implement, GREEN, v0 guard.

## Task 8: v0 pins, docs, final verification

**Files:** `tests/Feature/Http/Controllers/V0/AdControllerPart3Test.php`, `README.md` section "Keys, limits and billing".

- [ ] Add a new v0 test file pinning the sanctioned changes: revoked key → 400 `Invalid request`; 61st call → 400 `Invalid request` + `Retry-After`; metered call body identical to unmetered; quota refusal 400. Do not edit the original contract test.
- [ ] README: the billable rule verbatim from Global Constraints, the idempotency rules, the MCP retry rule (header-only, retry without a key bills again), the v0 400-instead-of-429 trade-off, and the quota overshoot caveat.
- [ ] Run pint, phpstan, all new tests, the v0 contract test and all v1 tests; update `docs/openapi/v1.json` if Scramble output changed (`php artisan scramble:export --path=docs/openapi/v1.json`). Ask the user to run `composer test`.
- [ ] Mutation spot-checks, one at a time, each must fail a test, then revert by exact edit: skip revocation check, limiter off, `Retry-After` hard-coded, billing on 4xx, advisory lock removed, quota off-by-one.
