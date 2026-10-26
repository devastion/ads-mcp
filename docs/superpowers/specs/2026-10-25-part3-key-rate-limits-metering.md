# Part 3 design: keys, rate limits, metering

Date: 2026-10-25. Status: **approved** (recorded in `docs/superpowers/plans/2026-10-25-part3-keys-limits-metering.md`). **Implementation is in progress** on branch `part-3-keys-metering`. One change after approval: the limiter moves to Redis and fails closed (user, 2026-10-25), and that is not built yet. See "Sync notes" at the end (synced 2026-10-25). Spec: `ASSESSMENT.md` Part 3. Guard: `.agents/skills/v0-contract-guard/`. Consumers: v0, v1, and the Part 2 MCP server (`2026-10-25-part2-mcp-design.md`).

Labels: **[user]** chosen in brainstorming. **[my call]** my default, open to veto at this review.

## Intent

One key store, one limiter and one meter shared by v0, v1 and MCP. "How many billable calls did this key make?" has one exact answer, and a retry with the same `Idempotency-Key` is never billed twice. Part 4's usage page reads the same meter; no second counter.

## Base (verified 2026-10-25, worktree `part-2-mcp`, before Part 3 started)

`api_keys` holds `name`, `prefix` (12 chars), `key_hash` (sha256, unique). `IssueApiKey` returns the secret once. `AuthenticateV0ApiKey` (query `api_key`) and `AuthenticateV1ApiKey` (Bearer) both call `ApiKey::findBySecret`. `ApiErrorCode` has 11 codes; `bootstrap/app.php` renders v0 as `400 {"error":"Invalid request"}` and v1 as the envelope. No scopes, revocation, limiter, usage table or idempotency handling exist. Postgres is the database.

## Keys [MUST]

- Migration adds `api_keys.scopes` (json array, default `["search:read"]`), `revoked_at` (nullable timestamp), `last_used_at` (nullable timestamp). **[user]** JSON column, validated against a `Scope` enum (`search:read`, `keys:manage`). Existing keys migrate to `["search:read"]`.
- `IssueApiKey::handle(name, secret = null, scopes = [search:read])`. Secret is shown once by the caller; only the sha256 hash and the 12-char prefix are stored (unchanged).
- New `RevokeApiKey` Action sets `revoked_at` (idempotent). `findBySecret` returns revoked keys; auth rejects them, so revocation takes effect on the very next call with no caching. Artisan command `api-key:revoke {prefix}` for operators. HTTP key management is Part 4.
- Auth order per request: credential present, key found and not revoked, then rate limit (below), then scope. *Built as* four route middleware in this order: `AuthenticateV0ApiKey`/`AuthenticateV1ApiKey` (credential, lookup, revocation, `api_key` request attribute, `last_used_at`), `ThrottleApiKey`, `RequireScope:search:read`, `MeterApiUsage`. **[my call]** The limiter runs before the scope check so a valid key cannot hammer endpoints it may not use.
- Scope required for every data read (v0, v1 ads, detail, niches, MCP): `search:read`. `keys:manage` is stored and checkable but grants no data access.
- Failures: missing or invalid or revoked key, v1 `api_key_missing`/`api_key_invalid` 401; v0 400. Missing scope: new `ApiErrorCode::InsufficientScope`, 403, non-retryable, next_step "Use a key with the search:read scope."; v0 400. `last_used_at` is updated on every authenticated call. *Built:* it is written by the auth middleware with no throttling, before the limiter and the scope check run. Rate-limited and scope-denied calls with a valid key therefore update it too.

## Rate limit [MUST]

- 60 requests per minute per key, one bucket shared by v0, v1 and MCP. Bucket name `api-key:{id}`.
- **Store: Redis, failing closed [user, changed 2026-10-25; replaces the database store approved earlier].** Laravel's `RateLimiter` uses the store named by `cache.limiter`, so point that key at a `redis` store. The env variable's name is the builder's call. The app's general cache store stays as it is. Redis is shared by every worker and the MCP loopback.
  - **Fails closed:** if Redis cannot be reached, the request is refused, never served unlimited, and there is no fallback store. v1 returns `internal_error` (500, `retryable:true`) and v0 returns 400 `Invalid request`. The refusal spends no rate unit and is not billed. **[my call]** for the mapping onto existing codes; no new code is added.
  - Tests keep the `array` limiter store, because the limiter logic doesn't depend on the store. One test proves fail-closed by making the store throw.
  - *Not built yet:* the code uses the default cache store (`database` in `.env.example` and compose, `array` in `phpunit.xml`). `main` has uncommitted changes that add a `redis` service to `compose.yaml` and the `redis` extension to `Dockerfile`. Neither is on `part-3-keys-metering`.
- Fixed window. A new `ThrottleApiKey` middleware calls `App\Actions\ConsumeRateLimit`. *Built:* it uses `RateLimiter::hit()` and compares the result with 60. That is one atomic increment, where `attempt` would make two calls. The denial computes `Retry-After` from `availableIn()`, rounded up and never 0. The same service is callable from code, so MCP control traffic debits the identical bucket (Part 2 contract).
- Every authenticated attempt that reaches the middleware spends one unit, including validation failures and 404s. Unauthenticated requests spend none.
- v1 denial: HTTP 429, `Retry-After`, envelope `rate_limited` with `retryable:true`, `details.retry_after_seconds`.
- **[user]** v0 denial: **400 `{"error":"Invalid request"}` with a `Retry-After` header, not 429.** Every v0 error stays the same 400 and body. This does not meet the assessment's "429" wording on v0; DESIGN.md must record it as a deliberate trade-off to keep v0 clients unbroken, and the contract test pins it.

## Metering [MUST]

### Billable rule (to be copied into README)

A call is billable when it is an authenticated, authorized, not-rate-limited read of `GET /api/v0/ads`, `GET /api/v1/ads`, `GET /api/v1/ads/{id}` or `GET /api/v1/niches` that returns 2xx, including an empty result set. Validation errors, 404, 429, quota refusal and 5xx are never billable. Each MCP tool call is one billable read through the REST call it forwards; MCP discovery, ping and local errors are free. **[my call]** all three surfaces bill the same, one unit per read.

### Ledger

`api_usages`: `id`, `api_key_id` (fk), `surface` (`v0|v1|mcp`), `endpoint`, `status`, `result_count` (nullable), `latency_ms`, `billed` (bool), `idempotency_hash` (nullable char 64, sha256), `created_at`. Indexes: `(api_key_id, created_at)`, `(api_key_id, idempotency_hash, created_at)`. **[user]** One table feeds billing, quota and the Part 4 page.

### Idempotency [MUST]

- Header `Idempotency-Key` on any billable endpoint. Valid: 1–255 printable ASCII characters; otherwise v1 `invalid_parameter` on header `Idempotency-Key` (v0: 400). Stored only as sha256.
- Within 24 hours of the first billed row for the same `(api_key_id, hash)`, further successful requests are recorded with `billed=false`. **[my call]** The key is not tied to endpoint or parameters: same key means billed once; no replay of the first response and no conflict error. Documented.
- Exactness under concurrency: the meter runs in one transaction that first takes `pg_advisory_xact_lock(hashtextextended(api_key_id || ':' || hash, 0))`, then looks for a billed row newer than 24 h and inserts `billed` accordingly. Overlapping requests with the same key serialize; exactly one is billed. Requests without a key skip the lock and are always billed.
- MCP client retries **[user, Part 2]**: header-only. MCP forwards the caller's `Idempotency-Key` unchanged to REST, using the same key and namespace. A retry without a key is billed again. No key is derived from JSON-RPC ids or arguments.
- Recording happens in a `MeterApiUsage` middleware after the handler returns a 2xx. If the write fails the request fails with `internal_error` rather than serve an unmetered read. `surface` comes from route/forwarded origin; an MCP-origin marker is a trusted internal header only set by the server-side forwarder, never client-controlled.

### Quota [SHOULD, user: include]

- 1,000 billed rows per key per UTC calendar month, counted from `api_usages`.
- Checked before the handler, after rate limiting. Over quota: v1 `ApiErrorCode::QuotaExceeded`, 403, `retryable:false`, `details.resets_at` (start of next UTC month, ISO 8601), next_step names the reset time. v0: 400 `{"error":"Invalid request"}` (same stance as other v0 errors).
- A request whose `Idempotency-Key` already has a billed row inside 24 h is not refused over quota, since it would not be billed.
- Honest limit: admission is checked before the handler and billing happens after, so concurrent in-flight requests can overshoot 1,000 by at most the number in flight (≤ 60 per minute). The count itself stays exact.

## Structure (no new base folders)

- `app/Enums/Scope.php`; `ApiErrorCode` gains `InsufficientScope` (403) and `QuotaExceeded` (403).
- `app/Models/ApiKey.php` (casts, `isRevoked()`, `hasScope()`), `app/Models/ApiUsage.php` (+ factory).
- `app/Actions/RevokeApiKey.php`, `app/Actions/RecordApiUsage.php` (advisory lock and insert), `app/Actions/CheckApiQuota.php`, `app/Actions/ConsumeRateLimit.php` (shared with MCP).
- `app/Http/Middleware/ThrottleApiKey.php`, `app/Http/Middleware/MeterApiUsage.php`, `app/Http/Middleware/RequireScope.php` (scope as a middleware parameter). The v0 and v1 auth middleware gain the revocation check and attach the key. The scope check sits in `RequireScope`, not in the auth middleware. Each surface keeps its own error format.
- Console command `api-key:revoke {prefix}` (`RevokeApiKeyCommand`); `api-key:create` accepts `--scope=*`.
- Part 4 needs the same monthly count for its usage page, so it extracts the count in `CheckApiQuota` into one shared query (see the Part 4 spec).
- Routes: middleware order auth, throttle, meter on every data route (v0, v1; MCP forwards through the v1 routes).

## Verification

Tests first (TDD), Pest, Postgres:

1. Keys: secret stored hashed and shown once; prefix visible; scopes validated; revoked key fails on the next call on v0 (400), v1 (401), with no caching; missing scope gives 403 `insufficient_scope` on v1 and 400 on v0.
2. Limiter: calls 1–60 pass, 61st is refused on v0 (400 + `Retry-After`) and v1 (429 + `Retry-After` + `retry_after_seconds`); wait value accurate to the second with frozen time; mixed v0/v1 calls share one bucket; two keys are independent; the window resets.
3. Billable rule: each endpoint bills on 2xx including empty; 422/404/429/5xx/quota refusals do not; a row records surface, endpoint, status, result_count, latency.
4. Idempotency: same key twice is billed once; again after 24 h is billed; different keys bill separately; no key bills every time; invalid key format is rejected; the raw header never appears in the table.
5. Concurrency: genuinely overlapping requests (separate processes or connections, committed fixtures, not one transaction-wrapped process) with and without a shared key prove an exact billed count.
6. Quota: 1,000th call passes, next is refused with `resets_at`; month rollover resets; duplicate idempotent request over quota is allowed; failures do not count.
7. v0 guard before and after every task: the v0 contract test is never edited; sanctioned changes (revocation 400, `Retry-After`, metering without body change, quota 400) are pinned as new tests.

Commands: `php artisan test --compact <path>`, `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse --memory-limit=1G`. The user runs the full suite.

## Non-goals

Developer page and "Connect your AI tool" panel (Part 4), key-management HTTP endpoints, OAuth and login, multiple plans, billing integrations, a second counter, caching of key lookups, per-endpoint prices, burst or sliding-window algorithms.

## Open items for review

These were accepted when the spec was approved. Item 6 came later.

1. v0 rate-limit status is 400 + `Retry-After`, not 429 (your decision, deviates from the assessment text).
2. Limiter runs before the scope check.
3. Same `Idempotency-Key` bills once regardless of endpoint or parameters.
4. Metering failure fails the request rather than serving an unmetered read.
5. Quota can overshoot by in-flight requests, though the count stays exact.
6. The limiter runs on Redis and fails closed (user, 2026-10-25). An outage of Redis therefore takes every API surface down, by choice.

## Sync notes (2026-10-25)

State of `part-3-keys-metering`, by plan task. Test results are not recorded here; run them on that branch.

| Plan task | State |
| --- | --- |
| 1. Scopes, key columns, issuing | Committed (`d7a1f8d`): `Scope` enum, `scopes`/`revoked_at`/`last_used_at` columns (`timestamp`), `IssueApiKey` scopes, `--scope`. |
| 2. Revocation, key on the request | Staged, not committed: `RevokeApiKey`, `api-key:revoke`, the auth middleware changes. |
| 3. Shared limiter | Staged: `ConsumeRateLimit`, `ThrottleApiKey`, v0 `Retry-After` passthrough, v1 `details.retry_after_seconds`. Still on the default cache store, so the Redis/fail-closed change is not built. |
| 4. Scope enforcement | Staged: `RequireScope`, `insufficient_scope`, `quota_exceeded` codes. |
| 5. Ledger and metering | Staged: `api_usages`, `ApiUsage`, `RecordApiUsage`, `MeterApiUsage`. `surface` is `v0` or `v1` from the path. `X-Ads-Origin` is always stripped, so `mcp` is never recorded yet (open in Part 2). |
| 6. Concurrency proof | In progress: `tests/Feature/Concurrency/MeteringConcurrencyTest.php`, untracked. |
| 7. Quota | In progress: `CheckApiQuota` (per key, using `RecordApiUsage::alreadyBilled`) and its tests, untracked. Further unstaged edits sit in `RecordApiUsage` and `MeterApiUsage`. |
| 8. v0 pins, README, final checks | Not started. |

**Quota is per key [user, confirmed 2026-10-25 while syncing Part 4].** There is no owner or account. Each key has its own 1,000 billed calls per UTC month. `DESIGN.md` should note that a user can raise their effective limit by creating more keys.
