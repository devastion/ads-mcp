# Part 4 Developer Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A local-only Inertia + React page at `/developer` that creates, lists and revokes API keys (secret shown once with a copy button), shows per-key usage against the 1,000/month quota from the billing ledger, and has a "Connect your AI tool (Pi-Bolt)" panel that flips to "Connected" on the key's first MCP request.

**Architecture:** One Inertia page fed by server props built from Part 3's Actions and the `api_usages` ledger. Create and revoke are local-only JSON routes called with `useHttp`; afterwards only the `keys`, `usage` and `mcp` props reload. The monthly count is extracted from `CheckApiQuota` into one Action that both the quota and the page call (no second counter). The first-MCP signal is one nullable timestamp on `api_keys`.

**Tech Stack:** PHP 8.5, Laravel 13.38, Inertia v3 (`inertiajs/inertia-laravel` ^3, `@inertiajs/react` ^3), React 19, Tailwind 4, Wayfinder, Vite+ (`vp`), Pest 5 on Postgres, Larastan 7, Pint.

**Spec:** `docs/superpowers/specs/2026-10-25-part4-developer-page-design.md` (draft; get the user's approval before Task 1). Also read `ASSESSMENT.md` Part 4, `docs/superpowers/specs/2026-10-25-part3-key-rate-limits-metering.md`, `docs/superpowers/specs/2026-10-25-part2-mcp-design.md`, `docs/superpowers/USER_DECISIONS.md` (Part 4 and cross-part sections), and `.agents/skills/v0-contract-guard/SKILL.md`. Skills to activate: `inertia-react-development`, `tailwindcss-development`, `wayfinder-development`, `laravel-best-practices`, `testing-best-practices`.

## Global Constraints

- Local only: every developer route returns 404 unless `app()->isLocal()`, via middleware `EnsureLocalEnvironment` (not conditional route registration).
- No login, no user table, no owner column. The single local user owns every key. Quota is **per key**: 1,000 billed calls per UTC month.
- Usage always recounted from `api_usages` (`billed = true`, `created_at >= start of UTC month`). No counter column, cache or client tally.
- The secret appears only in the `201` create response (`Cache-Control: no-store`) and React state. Never in Inertia props, session or flash data, `localStorage`, logs, or the database. `key_hash` is never serialized.
- No `keys:manage` bearer key in the browser. The page calls local `web` routes, not `/api/*`.
- Developer routes are in the `web` group (CSRF applies), are not under `api/`, are not rate-limited per key and are never billed.
- The Pi-Bolt panel's key: name `Pi-Bolt`, scope `search:read` only. The config with the real key inline is shown only right after creation. Never write it to the repo's `.pi/mcp.json`. Later visits show `<YOUR_KEY>`.
- Panel status: `mcp.connection` = newest *active* key named `Pi-Bolt`, as `{key_id, prefix, connected_at|null}`, else `null`. Polling every 3 s with a partial reload of `keys` and `mcp`, stopped once connected.
- `api_keys.last_mcp_request_at`: nullable `timestamp`. `TouchMcpRequest` writes at most once per minute (first write always).
- Do not write `DESIGN.md` or `AI_NOTES.md` (protected; the user writes them). No new packages, no JS test runner, no browser tests.
- Times shown in browser local time with UTC in a tooltip; the quota reset is shown in UTC.
- No v0 change. `tests/Feature/Http/Controllers/V0/AdControllerTest.php` is never edited.
- Layers per `AGENTS.md`; no new top-level folders. PHP files via `php artisan make:* --no-interaction`. Use Boost `search-docs` before relying on any Inertia v3 API (`useHttp`, polling, partial reloads, `@inertiajs/vite`).
- Test style: Pest `it()`, factories with explicit values, `$this->travelTo(...)`. Page tests run with `config(['app.env' => 'local'])`-equivalent (use `app()->detectEnvironment(fn () => 'local')` in a shared helper).
- Commands from the worktree root:

  ```bash
  php artisan test --compact <path-or-filter>
  vendor/bin/pint --dirty --format agent
  vendor/bin/phpstan analyse --memory-limit=1G
  npm run types:check && vp check && npm run build   # frontend tasks
  ```
  The user runs the full suite (`composer test`). Commit only if the user asks; each task ends at a "ready to commit" point.

## Review Focus

- Secret leak: the secret in a later `GET /developer` prop, the session, a log line, or after the dismiss (test: create, then reload props and session; assert absent).
- Revoking the panel's key mid-wait: panel must return to step 1 (revoked keys skipped), not poll forever.
- Month and year boundary: 31 Oct 23:59:59 vs 1 Nov 00:00:00, and `resets_at` in December (1 Jan of next year).
- A key with zero ledger rows must show `used: 0`, not be missing from `per_key`; unbilled rows (`billed = false`) must not count.
- A second `Pi-Bolt` key, or a revoked one with a newer id: the newest *active* one wins.
- Double-submit or invalid create (empty name, 101 chars, duplicate scopes, unknown scope): 422, no key row written.
- Production: `APP_ENV=production` with `route:cache` must still 404, not 500.

---

## Task 0: Gate and baseline (no code)

- [ ] Confirm the user approved the Part 4 spec. Ask the user which base to use. Part 3 (throttle, meter, quota, `CheckApiQuota`) must be merged, and Part 2's MCP endpoint should exist for the "Connected" check. If MCP is absent, Task 6's MCP-auth call and its test are skipped and the README says the check is unverified.
- [ ] Create the worktree with `using-git-worktrees`. Re-read the real `IssueApiKey`, `RevokeApiKey`, `CheckApiQuota`, `ApiKey`, `Scope`, the routes and (if present) the MCP auth path. Record every difference from the spec at the top of this plan; follow the code.
- [ ] Verify with Boost `search-docs` the Inertia v3 APIs used below, and read Pi-Bolt's installed `docs/mcp.md` for the **user-level** config path and the Streamable HTTP `headers` JSON shape. Record both here.
- [ ] Resolve `url()` for the MCP endpoint with Boost `get-absolute-url`.
- [ ] Run `php artisan test --compact` and the v0 guard; record the baseline. Check `.ai/rules/index.md` if it exists.

## Task 1: Shared monthly count (`CountBillableCalls`)

Behaviour-preserving extraction, its own commit. v0 guard before and after.

**Files:**
- Create: `app/Actions/CountBillableCalls.php`; test `tests/Feature/Actions/CountBillableCallsTest.php`
- Modify: `app/Actions/CheckApiQuota.php`

**Interfaces:**
- Produces: `CountBillableCalls::handle(Collection|array $keyIds, CarbonInterface $now): array<int,int>` returning `[api_key_id => count]` with an entry (0) for every requested id. One grouped query: `billed = true` and `created_at >= $now->utc()->startOfMonth()` and `< next month`.
- Produces: `CountBillableCalls::resetsAt(CarbonInterface $now): CarbonImmutable` (first instant of next UTC month).
- Consumes: `ApiUsage` model.

- [ ] Write failing tests (frozen time): unbilled rows excluded; last month's rows excluded; another key's rows excluded; a key with no rows returns 0; a row at `2026-11-01 00:00:00` counts for November, one at `2026-10-31 23:59:59` for October; `resetsAt` in December is `2027-01-01 00:00:00 UTC`.
- [ ] Run, confirm RED. Implement.
- [ ] Change `CheckApiQuota` to call it for its one key; its existing tests must stay green unedited.
- [ ] GREEN: `php artisan test --compact tests/Feature/Actions tests/Feature/Http/Middleware/QuotaTest.php`, v0 contract test, pint, phpstan.

## Task 2: Local-only guard and frontend scaffold

**Files:**
- Create: `app/Http/Middleware/EnsureLocalEnvironment.php`, `resources/views/app.blade.php`, `resources/js/app.tsx`, `resources/css/app.css`, `resources/js/pages/welcome.tsx`, `resources/js/layouts/app-layout.tsx`
- Create: `app/Http/Controllers/Developer/DeveloperController.php` (stub `index` returning `Inertia::render('developer')` for now), `resources/js/pages/developer.tsx` (placeholder heading)
- Modify: `routes/web.php`; test `tests/Feature/Http/Controllers/Developer/AccessTest.php`

**Interfaces:**
- Produces: route group `Route::middleware(EnsureLocalEnvironment::class)->prefix('developer')` with names `developer.index` (`GET /developer`), later `developer.keys.store`, `developer.keys.destroy`.
- Produces: `EnsureLocalEnvironment::handle(Request, Closure): Response` aborting 404 unless `app()->isLocal()`.
- Produces: shared test helper in `tests/Pest.php`: `asLocal(): void` (sets the environment to `local`).

- [ ] Write failing tests: `GET /developer` is 200 and renders Inertia component `developer` when local; 404 when the environment is `production`; the same 404 for `POST /developer/keys` and `DELETE /developer/keys/1` (add these two as the routes appear in Tasks 4–5; here assert the GET and that the middleware class is on the group).
- [ ] RED, then implement the middleware, routes, and scaffold per the spec: `app.blade.php` with `@vite` and `@inertia`; `createInertiaApp` as the installed `@inertiajs/vite` plugin expects (verified in Task 0); Tailwind 4 `@import 'tailwindcss'`; `welcome` shows a heading and a link to `/developer` only when `app()->isLocal()` (pass `isLocal` as a prop). Frontend uses Wayfinder route helpers, never hard-coded URLs.
- [ ] Run `php artisan wayfinder:generate` (or the Vite plugin) so `developer` route helpers exist.
- [ ] GREEN: access tests, `npm run types:check`, `vp check`, `npm run build`, pint, phpstan.
- [ ] Verify `php artisan route:cache && php artisan route:clear` works (Review Focus: production 404, no 500).

## Task 3: Page props (`keys`, `usage`)

**Files:**
- Create: `app/Http/Resources/Developer/ApiKeyResource.php`; test `tests/Feature/Http/Controllers/Developer/IndexPropsTest.php`
- Modify: `app/Http/Controllers/Developer/DeveloperController.php`

**Interfaces:**
- Consumes: `CountBillableCalls` (Task 1), `ApiKey`.
- Produces: `ApiKeyResource` whitelist: `id, name, prefix, scopes, created_at, last_used_at, revoked_at, last_mcp_request_at` (ISO 8601 UTC, nullable). `last_mcp_request_at` is null until Task 6 adds the column; the resource reads it with `->getAttribute()` so Task 3 stays green.
- Produces: props `keys` (newest first, revoked included) and `usage` = `{limit: 1000, period_start, resets_at, per_key: [{key_id, used}]}`, one `per_key` entry per listed key.

- [ ] Write failing tests with Inertia assertions (`assertInertia`):
  - keys appear newest first, revoked ones included, with exactly the whitelisted fields (no `key_hash`, no `secret`);
  - `usage.per_key[].used` equals billed rows this month per key (own-key only, unbilled and last-month rows excluded, zero-row key present with 0);
  - inserting an `ApiUsage` row directly and reloading raises `used` by one (proves no second counter);
  - frozen on `2026-12-15`, `resets_at` is `2027-01-01T00:00:00Z`; `limit` is `1000`.
- [ ] RED, implement. Props are plain (not lazy) here; Task 8 changes `keys`/`usage`/`mcp` loading for partial reloads if needed.
- [ ] GREEN, pint, phpstan.

## Task 4: Create key endpoint

**Files:**
- Create: `app/Http/Requests/Developer/StoreApiKeyRequest.php`; test `tests/Feature/Http/Controllers/Developer/StoreKeyTest.php`
- Modify: `app/Http/Controllers/Developer/DeveloperController.php` (`storeKey`), `routes/web.php`

**Interfaces:**
- Consumes: `IssueApiKey::handle(string $name, ?string $secret = null, array $scopes = [...]): IssuedApiKey` and `Scope`.
- Produces: `POST /developer/keys` (`developer.keys.store`), JSON body `{name, scopes}`; rules `name` required string 1–100, `scopes` required non-empty array of distinct `Scope` values. Response `201 {data: {...ApiKeyResource fields, secret}}` with `Cache-Control: no-store`.

- [ ] Write failing tests: 201 with `data.secret` starting `ghk_` and `Cache-Control` containing `no-store`; the stored row has the given scopes and `key_hash` equals `sha256(secret)`; the secret string appears in no `api_keys` column, not in the session, and not in the next `GET /developer` props; 422 and no row for empty name, 101-char name, empty scopes, duplicate scopes, unknown scope; a key created with `["search:read"]` has only that scope; 404 outside `local`; the new key appears in the next page's `keys`.
- [ ] RED, implement (FormRequest validates, controller delegates to `IssueApiKey`).
- [ ] GREEN, pint, phpstan.

## Task 5: Revoke key endpoint

**Files:**
- Modify: `app/Http/Controllers/Developer/DeveloperController.php` (`destroyKey`), `routes/web.php`; test `tests/Feature/Http/Controllers/Developer/RevokeKeyTest.php`

**Interfaces:**
- Consumes: `RevokeApiKey::handle(ApiKey): ApiKey`.
- Produces: `DELETE /developer/keys/{apiKey}` (`developer.keys.destroy`, route model binding), `204`, idempotent.

- [ ] Write failing tests: 204 and `revoked_at` set; a second revoke keeps the first `revoked_at` (frozen times apart); unknown id is 404; after revoking, the key's very next `GET /api/v1/ads` is 401 `api_key_invalid` and `GET /api/v0/ads?api_key=` is 400 `Invalid request` (through Part 3's pipeline); 404 outside `local`.
- [ ] RED, implement. GREEN, v0 guard (this task touches routes), pint, phpstan.

## Task 6: First-MCP-call signal and `mcp` props

**Files:**
- Create: migration `add_last_mcp_request_at_to_api_keys_table`; `app/Actions/TouchMcpRequest.php`; `app/Http/Resources/Developer/McpPanelResource.php` (or build in the controller if tiny); tests `tests/Feature/Actions/TouchMcpRequestTest.php`, `tests/Feature/Http/Controllers/Developer/McpPropsTest.php`
- Modify: `app/Models/ApiKey.php` (cast `last_mcp_request_at` to `immutable_datetime`, `@property`), `app/Http/Controllers/Developer/DeveloperController.php`, the MCP auth path (Part 2) if present

**Interfaces:**
- Produces: `TouchMcpRequest::handle(ApiKey $key): void` — conditional update, writes only when `last_mcp_request_at` is null or older than 60 s.
- Produces: prop `mcp` = `{endpoint_url, client: 'Pi-Bolt', config_template, connection}`. `config_template` is the verified Pi-Bolt user-level JSON (Task 0) with the literal `<YOUR_KEY>` placeholder. `connection` = `{key_id, prefix, connected_at}` for the newest active key named `Pi-Bolt`, else `null`.

- [ ] Write failing tests: `TouchMcpRequest` writes on the first call, does not write again within 59 s, writes again after 60 s (frozen time, assert `updated` count via the timestamp); `keys[].last_mcp_request_at` reflects it; `mcp.connection` picks the newest active `Pi-Bolt` key and skips a newer revoked one and a newer key with another name; is `null` with no such key; `connected_at` mirrors the column; `config_template` contains `endpoint_url` and `<YOUR_KEY>` and no real key hash/secret.
- [ ] RED, implement migration, model cast, Action, props.
- [ ] If the MCP endpoint exists: write a failing test that an authenticated MCP `initialize` sets `last_mcp_request_at` (and an unauthenticated one does not), then add the single `TouchMcpRequest` call to the MCP auth path. Run the MCP tests and v0 guard around it. If it does not exist, add the call to the Part 2 plan's Task 3 note instead and record "unverified" in the README task.
- [ ] GREEN, pint, phpstan, v0 guard (migration touches `api_keys`).

## Task 7: Page UI: keys, create form, secret-once, revoke

**Files:**
- Create: `resources/js/pages/developer.tsx` (replace placeholder), `resources/js/components/developer/key-table.tsx`, `create-key-form.tsx`, `secret-once.tsx`, `resources/js/types/developer.ts`

**Interfaces:**
- Consumes: props `keys`, `usage`, `mcp` (Tasks 3, 6); Wayfinder helpers for `developer.keys.store` and `developer.keys.destroy`.
- Produces: components; `SecretOnce` takes `{secret: string, onDismiss: () => void}`.

- [ ] Types first: `types/developer.ts` mirrors the prop shapes exactly; run `npm run types:check`.
- [ ] Create form: name input and scope checkboxes (`search:read` checked by default); submits with `useHttp` to the store route; on 201 keeps the secret only in component state, then reloads `keys` and `usage` (`router.reload({only: [...]})`).
- [ ] `SecretOnce`: read-only field, **Copy** button using `navigator.clipboard.writeText` with select-all fallback on denial and "Copied" feedback, the text "You won't see this key again.", stays until "I've saved it" is clicked; state cleared on dismiss and on navigation.
- [ ] Key table: name, prefix `ghk_AbCd1234…`, scope badges, created, last used ("Never" if empty), status; Revoke button with inline confirm calling the destroy route; revoked rows dimmed with revoked time. Times in local zone with UTC `title`.
- [ ] Empty state for no keys.
- [ ] Verify: `npm run types:check`, `vp check`, `npm run build`. Manual check recorded in Task 10.

## Task 8: Page UI: usage section and Pi-Bolt connect panel

**Files:**
- Create: `resources/js/components/developer/usage-panel.tsx`, `connect-panel.tsx`
- Modify: `resources/js/pages/developer.tsx`; controller if props need `Inertia::optional`/partial-reload tweaks

**Interfaces:**
- Consumes: `usage.per_key`, `mcp.connection`, `mcp.config_template`; Inertia v3 polling (verified API from Task 0).

- [ ] Usage: one row per key "name (prefix…): **N of 1,000** billable calls this month" with a progress bar; revoked rows dimmed; below, "Resets 1 Nov 2026, 00:00 UTC" and one line on what counts as billable linking to the README rule.
- [ ] Panel order: first section. Steps: (1) "Create key for Pi-Bolt" posts `{name: 'Pi-Bolt', scopes: ['search:read']}`; (2) config block with the real key substituted for `<YOUR_KEY>` plus Copy, shown only right after creation, with the one-line warning to paste into Pi-Bolt's **user-level** config and never into the repo's `.pi/mcp.json`; later visits show `<YOUR_KEY>` and "Create a new key"; (3) "Restart Pi-Bolt and ask: Which niches can I search?"; (4) status.
- [ ] Status: no `connection` → step 1; `connected_at` null → "Waiting for Pi-Bolt (key ghk_…)" and poll every 3 s with `only: ['keys', 'mcp']`; stop polling when connected; after 60 s waiting show troubleshooting tips (restarted, config pasted whole, app running at `endpoint_url`); connected → "Connected at HH:MM".
- [ ] Reload mid-wait resumes polling from the server's `mcp.connection`; revoking the Pi-Bolt key returns the panel to step 1.
- [ ] Verify: `npm run types:check`, `vp check`, `npm run build`.

## Task 9: Mutation checks

One fault at a time; each must fail a test; revert by exact edit; never touch the v0 test. Record each result in this task.

- [ ] Usage read from a separate counter, or summed across all keys instead of per key.
- [ ] Secret added to `ApiKeyResource` or to props.
- [ ] `EnsureLocalEnvironment` removed from the group.
- [ ] `RevokeApiKey` call removed from `destroyKey`.
- [ ] `TouchMcpRequest` never called (only if the MCP endpoint exists).
- [ ] `mcp.connection` stops skipping revoked keys.
- [ ] `CountBillableCalls` counts `billed = false` rows.

## Task 10: README, verification and handoff

**Files:** Modify `README.md` (a "Developer page" section only; no other docs).

- [ ] README: URL `http://localhost:8000/developer`, local-only and no login, single local user owns every key, per-key quota, usage recounted from the ledger on every load, the panel targets Pi-Bolt and shows the real key only right after creation, "Connected" means the first authenticated MCP request (including `initialize`) arrived, time zones, quota resets 00:00 UTC on the 1st. If MCP is absent, state that the Connected check is unverified.
- [ ] Run pint, phpstan, all Part 4 tests, Part 3 quota tests, the v0 contract test and v1 tests, `npm run types:check`, `vp check`, `npm run build`. Ask the user to run `composer test`.
- [ ] Hand to the user for the manual checks (record outcomes here, do not claim them): copy button works; secret gone after dismiss and after reload; status flips to "Connected" with a real Pi-Bolt; a **timed** run of the five-minute setup. The under-five-minutes claim is not made without it.
- [ ] Report verified vs unverified. Do not write `DESIGN.md` or `AI_NOTES.md`.
