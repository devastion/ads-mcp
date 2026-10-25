# Part 4 design: developer page

Date: 2026-10-25. Status: **draft, awaiting the user's approval**. Synced on 2026-10-25 to the Part 3 spec and code. The sync removed the owner/account model: keys and quota are per key, and the single local user owns every key [user]. It also fixed the Part 3 file reference and the enum name. Spec: `ASSESSMENT.md` Part 4 (quoted where it binds). Guard: `.agents/skills/v0-contract-guard/`.

**Base [user].** The design is written against the Part 1–3 specs. The relevant pieces are:
- from Part 3 (`2026-10-25-part3-key-rate-limits-metering.md`): the `App\Enums\Scope` enum, `IssueApiKey`, `RevokeApiKey`, the `api_usages` ledger and the per-key monthly count in `CheckApiQuota`;
- from Part 2 (`2026-10-25-part2-mcp-design.md`): the MCP endpoint.

There is no owner or user model in Part 3.

**Execution gate:** the plan and the build start from the real repository with Parts 1–3 present. Before Task 1, re-read the actual Actions, the routes and the MCP auth path. Where the code differs from the specs, follow the code and record the difference in the plan.

**Order [my call, from builder check].** Part 4 can't start until Part 3 is built, because the page has nothing to show without scopes, revocation, `api_usages` and the quota count. Part 3 is still in progress on `part-3-keys-metering`. Part 2's MCP endpoint is needed to verify the panel's "Connected" check. If Part 2 isn't built by then, the panel ships and the README says its check is unverified. Today only Part 2's REST reads exist (`part-2-mcp`), not the MCP endpoint.

**Frontend base [user].** `resources/` does not exist on `main`. Part 4 therefore scaffolds the Inertia + React + Tailwind entry files, even though `vite.config.ts` and `Route::inertia('/', 'welcome')` already reference them.

Decision labels: **[user]** = chosen by the user in this brainstorm. **[Part 2]/[Part 3]** = decided in that part's design. **[my call]** = my default, marked so it can be vetoed at this review.

## Intent and why

> **MUST** A React page to create keys (secret shown once, with a copy button), list them (prefix, scopes, created, last used) and revoke them, and to see usage against the plan quota for the current period. Usage must come from the same meter that bills; do not keep a second counter. A single hard-coded local user is fine; no login is needed.
>
> **SHOULD** A "Connect your AI tool" panel: a copy-paste MCP config for one client, and a check that tells the user whether their first MCP call has arrived. A non-technical marketer should be able to finish setup in under five minutes.

The page is where a customer sees what Parts 2 and 3 built: their keys, what they've used, and a working AI-tool connection. It doesn't add any new rules. It is a thin view over the same Actions and the same ledger that REST and MCP use.

## Goals

1. **Create a key.** The name and scopes are chosen on the page. The secret is shown exactly once, with a copy button.
2. **List keys.** Each key shows its prefix, scopes, created time, last used time and status (active or revoked).
3. **Revoke a key.** A revoked key fails on its next REST or MCP call (Part 3).
4. **Show usage.** Each key's usage is shown against its own 1,000-call quota for the current calendar month (UTC), with the reset time. It is read from `api_usages` through the same count `CheckApiQuota` uses (see "Usage count").
5. **"Connect your AI tool" panel for Pi-Bolt [user].** The panel creates a key, gives a copy-paste config with the key inline, and switches from "Waiting" to "Connected" once Pi-Bolt's first request arrives.

## Non-goals

- **[user] `DESIGN.md` and `AI_NOTES.md` are not written by the agent.** `AI_NOTES.md` says agents must never edit it. The user writes `DESIGN.md` as well. This doc only collects raw material for both (see the appendix).
- Login, multiple users, or plans other than the 1,000/month quota.
- Clients other than Pi-Bolt.
- Changes to billing, limits or key rules.
- An `MCP_EVAL.md` (Part 2).

## Anti-goals

- **A second counter.** No cached count, no counter column, no client-side tally. Usage is always recounted from the ledger.
- **The secret in a durable place.** It must never reach Inertia props, session or flash data, `localStorage`, logs, or any response other than the create response.
- **A `keys:manage` bearer key in the browser.**
- **The page being reachable outside `local`** while it has no login.
- **Any v0 change.**

## Constraints

- Inertia v3 (`@inertiajs/react` ^3, `inertiajs/inertia-laravel` ^3), React 19, Tailwind 4, Wayfinder, Vite+ (`vp`). Tests run with Pest 5 on Postgres.
- There is no JS test runner, and none is added **[user: approach A]**.
- The `.pi/skills` to follow: `inertia-react-development`, `tailwindcss-development`, `wayfinder-development`, `laravel-best-practices` and `testing-best-practices`.
- Use Boost `search-docs` before relying on an Inertia v3 API (`useHttp`, `usePoll`, partial reloads, the `@inertiajs/vite` setup).
- `AGENTS.md` layering. No new top-level folders. PHP files are made with `php artisan make:* --no-interaction`.
- The guard applies: Part 4 touches `routes/web.php`, an additive migration on `api_keys`, and the MCP auth path. A guard run is the first and last step of any task that touches shared code.

## Approaches [user: A]

| | Approach | Trade-off |
|---|---|---|
| **A (chosen)** | **One Inertia page, server props, `useHttp` mutations, Pest feature tests.** Props come from the Part 3 Actions. Create and revoke go through local-only JSON routes, and only the changed props are reloaded afterwards. The panel polls a prop. | No new dependencies. The server side (props, JSON routes, the secret's handling, the ledger as the only source) is fully covered by tests. The copy button and the "Connected" switch are checked by hand. |
| B | A, plus Pest browser tests (`pestphp/pest-plugin-browser` with Playwright). | The UI is automated, but it brings a new dev dependency and a new test setup. |
| C | Separate pages for Keys, Usage and Connect. | More navigation, and the panel would need a key from another page, so setup has more steps. |

Also rejected: Inertia `<Form>` with the secret in flash data, because flash data lands in the session store. Also rejected: the React page calling `/api/v1/keys` with a bearer key held in the browser.

## Design

### Scaffold [user: Part 4 builds it; details my call]

- `resources/views/app.blade.php` is the root view that `HandleInertiaRequests` already names (`$rootView = 'app'`). It loads `@vite` and `@inertia`.
- `resources/js/app.tsx`: `createInertiaApp`, set up the way the installed `@inertiajs/vite` plugin expects (verified with `search-docs`).
- `resources/css/app.css`: Tailwind 4 (`@import 'tailwindcss'`), with the Instrument Sans font already configured in `vite.config.ts`.
- A minimal shared layout, and the `welcome` page for the existing `/` route. That page shows a heading and a link to `/developer` when local.
- Wayfinder generates the route helpers, and the page uses them, not hard-coded URLs.
- The scaffold must pass `npm run build`, `npm run types:check` and `vp check`.

### Access [user: local only]

- One middleware, `EnsureLocalEnvironment`, is applied to every developer route. It returns 404 unless `app()->isLocal()`. A middleware is used rather than a conditional route registration, so `route:cache` stays safe **[my call]**.
- The single hard-coded local user owns every key. There is no owner column, no user table and no default-owner Action **[user]**. The page runs in the `web` middleware group, so CSRF applies to mutations.
- `DESIGN.md` (written by the user) should note that production needs real auth before this page can ship.

### Routes [my call]

| Route | Purpose |
|---|---|
| `GET /developer` | The Inertia page `developer`. |
| `POST /developer/keys` | JSON, via `useHttp`. The body is `{name, scopes}`, validated by a FormRequest: `name` 1–100 characters, `scopes` a non-empty, distinct list of `App\Enums\Scope` values. It calls `IssueApiKey::handle($name, null, $scopes)`. Returns `201 {data: {…key fields, secret}}` with `Cache-Control: no-store`. The page can grant either scope, because it acts as the local user, not as a key. |
| `DELETE /developer/keys/{id}` | JSON, via `useHttp`. Calls `RevokeApiKey`. Returns `204`, and is idempotent (the first `revoked_at` is kept). An unknown id gives 404. |

- These routes are not under `api/`. They are not rate-limited per key and are not billed, the same as Part 3's key management.
- After a mutation, the page reloads only the `keys` and `usage` props.

### Props on `GET /developer`

| Prop | Content |
|---|---|
| `keys` | All keys, newest first, revoked ones included: `id, name, prefix, scopes, created_at, last_used_at, revoked_at, last_mcp_request_at`. Never `key_hash` or the secret. Built by an API Resource with a whitelist **[my call]**. |
| `usage` | `{limit: 1000, period_start, resets_at, per_key: [{key_id, used}]}` (UTC ISO 8601). There is one entry per key in `keys`, and `used` comes from the shared count (see "Usage count"). Nothing is cached or stored. |
| `mcp` | `{endpoint_url, client: "Pi-Bolt", config_template, connection}` (`connection` is described under the page). The template has a `<YOUR_KEY>` placeholder and never a real secret. `endpoint_url` comes from the MCP route's name (resolved with `url()`/APP_URL, and checked with Boost `get-absolute-url` during the build). |

### Usage count [my call, from the sync]

Part 3's `CheckApiQuota` counts a key's billed rows since the start of the UTC month inline. Part 4 moves that query into one Action, for example `CountBillableCalls`, which both `CheckApiQuota` and the page call. The move is a separate, behaviour-preserving step: the Part 3 quota tests stay green, and the v0 guard runs before and after it. The Action may count several keys in one grouped query, so the page doesn't run one query per key.

### The page [my call unless marked]

One page with three sections, in this order. The panel comes first because a new user's first job is to connect.

**1. Connect your AI tool (Pi-Bolt) [user].** Numbered steps a marketer can follow:

1. A **"Create key for Pi-Bolt"** button. It creates a key named `Pi-Bolt` with `search:read` only.
2. **Copy the config.** The block is filled with the endpoint URL and the **real key inline**, with a copy button, and it appears only right after creation **[user]**. The step tells the user to paste it into Pi-Bolt's **user-level** config.
   - **Never into the repo's `.pi/mcp.json` [my call, from builder check].** That file is project-level and isn't gitignored, so a pasted key would end up in git. The panel says this in one line.
   - Before building, the exact user-level path and JSON shape are verified against Pi-Bolt's installed `docs/mcp.md` (Streamable HTTP plus `headers`), not assumed.
   - Part 2's rule (an env-supplied credential, never a literal secret) still applies to the README and any committed config. This panel is the only place the key appears inline.
3. **Restart Pi-Bolt and ask it something simple** ("Which niches can I search?").
4. **Status:** "Waiting for Pi-Bolt…" switches to "Connected at 14:32" when that key's `last_mcp_request_at` appears. After 60 seconds of waiting, short troubleshooting tips appear: Pi-Bolt restarted, the config pasted whole, the app running at `endpoint_url`.

On later visits the block shows `<YOUR_KEY>` and a "Create a new key" button, because the secret can't be shown again.

**Panel status survives a reload [my call, from builder check].**
- The server picks the panel's key: the newest *active* key named `Pi-Bolt`. Revoked keys are skipped, so revoking it puts the panel back to step 1.
- It goes out as the `mcp.connection` prop: `{key_id, prefix, connected_at | null}`, or `null` when there's no such key.
- After a reload mid-wait, which is likely while the user restarts Pi-Bolt, the panel shows "Waiting for Pi-Bolt (key ghk_AbCd1234…)" and polling resumes.
- A returning user who has connected sees "Connected at …".

**2. Usage.** One row per key, for example "**Pi-Bolt** (ghk_AbCd1234…): **312 of 1,000** billable calls this month", with a progress bar. Revoked keys are dimmed. Below the rows: "Resets 1 Nov 2026, 00:00 UTC", and one line saying what counts as billable, linking to the README rule. Each key has its own quota.

**3. API keys.**
- A create form with a name and scope checkboxes (`search:read` checked by default).
- **Secret shown once:** after creation, a panel shows the secret in a read-only field with a **Copy** button (`navigator.clipboard.writeText`, with a select-all fallback if clipboard access is denied) and "Copied" feedback. It also says plainly: "You won't see this key again." It stays until the user clicks "I've saved it". The secret lives only in React state and is cleared on dismiss and on navigation.
- **Table:** name, prefix (`ghk_AbCd1234…`), scope badges, created, last used ("Never" if empty), and status. A Revoke button asks for inline confirmation. Revoked rows are dimmed and show the revoked time.

Times are shown in the browser's local time zone, with UTC in a tooltip. The usage reset is shown in UTC, because that's when it happens.

### First-MCP-call signal [user: key's last MCP request]

- An additive migration adds `api_keys.last_mcp_request_at` (`timestamp`, nullable, matching Part 3's `revoked_at` and `last_used_at`).
- One Action, `TouchMcpRequest(ApiKey)`, does a conditional update: at most one write per minute, so the first request (null to a value) is always written. Part 3's `last_used_at` is different: it is written on every authenticated call.
- The MCP endpoint's shared auth (Part 2) calls it on **every authenticated MCP request, `initialize` included**, so "Connected" appears as soon as Pi-Bolt connects, before any billed tool call.
  - If Part 2 is already built, Part 4 adds that one call to its auth path, and the guard and MCP tests run around it.
  - If not, Part 2's plan adds it.
- It is a timestamp, not a count, so "do not keep a second counter" still holds.
- **Polling:** while the panel is waiting, the page uses Inertia v3 polling every 3 seconds with a partial reload of `keys` and `mcp`. It stops once the key is connected, or when the panel is closed. This is not an API call, and it isn't billed or rate-limited.

## Testing strategy

Pest `it()` feature tests on Postgres, with factories and explicit values. Time is frozen with `travelTo`. The environment is set to `local` for page tests. There is one test outside `local`.

- **Access:** every developer route returns 404 outside `local`.
- **Props:**
  - Every key appears, newest first.
  - The fields are exactly the whitelist, with no `key_hash` and no secret.
  - Revoked keys are included.
- **Usage comes from the ledger:**
  - Each key's `used` equals its billed `api_usages` rows this month. Unbilled rows, rows from last month, another key's rows, and rows on the month boundary are handled correctly.
  - The page and `CheckApiQuota` get the same number from the one shared count.
  - Inserting a ledger row directly changes `used`, which proves there's no second counter.
  - `resets_at` is correct under a frozen clock, including in December.
- **Create:**
  - 201 with the secret, and `no-store`.
  - The secret is absent from the database, the session and the next `GET /developer` props.
  - Validation errors for an empty name, a bad scope or duplicate scopes.
  - The scopes are stored.
  - The Pi-Bolt button's request creates `search:read` only.
- **Revoke:**
  - 204, and the key is revoked.
  - Its very next v1 (and v0) call fails, through Part 3's pipeline.
  - An unknown id gives 404.
  - A second revoke keeps the first `revoked_at`.
- **MCP signal:**
  - `TouchMcpRequest` writes the first time, and doesn't write again within a minute.
  - An authenticated MCP `initialize` sets it, if Part 2 is present.
  - The `keys` prop reflects it.
- **Config template:** it contains the endpoint URL and the placeholder, never a real secret.
- **Panel connection:**
  - `mcp.connection` picks the newest active `Pi-Bolt` key, skipping revoked keys and other names.
  - It is `null` when there's no such key.
  - `connected_at` mirrors `last_mcp_request_at`.
- **Frontend checks:** `npm run types:check`, `vp check` and `npm run build` pass.
- **Checked by hand,** recorded in the plan's final verification:
  - The copy button works.
  - The secret disappears on dismiss and on reload.
  - The status switches to "Connected" when a real Pi-Bolt connects.
  - A timed run-through of the five-minute setup, done by the user. The under-five-minutes claim isn't made without it.
- **Mutation checks (Phase 4):**
  - Usage read from a separate counter.
  - The secret put in props.
  - The local-only check removed.
  - Usage counted across all keys instead of per key.
  - Revoke not saved.
  - `TouchMcpRequest` never called.
- **v0 guard run** before and after each task that touches routes, migrations or the MCP auth path.

## What's left to the builder

- Component and file names under `resources/js`, and the layout structure.
- Tailwind styling, within plain, readable defaults.
- The exact Inertia v3 API calls (`useHttp`, polling, partial reload) after `search-docs`.
- The Resource and FormRequest names.
- The migration name.
- The troubleshooting wording.
- Pi-Bolt's exact config file path and JSON shape, verified from its docs.

## Assumptions for the README

- The developer page is at `http://localhost:8000/developer`. It works only when `APP_ENV=local` and has no login. The single local user owns every key.
- The quota is per key: each key has its own 1,000 billable calls per month.
- Usage on the page is recounted from the billing ledger on every load. There is no separate counter.
- The "Connect your AI tool" panel targets Pi-Bolt. The config shows the real key only right after it is created.
- "Connected" means Pi-Bolt's first authenticated MCP request has arrived (including `initialize`), not that a billed search has happened.
- Times are shown in local time. The quota resets at 00:00 UTC on the first day of each month.

## Appendix: raw material for the user's `DESIGN.md` and `AI_NOTES.md`

The user writes both files. These are notes only.

- **Scale candidates at 110M ads (5–6M active):**
  1. Unindexed `ILIKE '%q%'` keyword search scans every row. Fix: a `pg_trgm` GIN index, then a dedicated search index fed by ingest.
  2. v0's exact `total` (`COUNT(*)` over all matches) plus OFFSET paging, both frozen by the contract. Fix: serve v0 from a search index with fast counts, or cache totals briefly.
  3. Ingest's `ORDER BY random() LIMIT 2` over the active ads every second. Fix: sample by random id range or `TABLESAMPLE`.
- **How v0 and v1 coexist:** they share predicates, `days_active`, the niche catalogue, the key store, the limiter and the ledger. Each has its own controller, response shaping, errors and pagination. v0 is pinned by an unedited contract test.
- **Shipping a breaking change to v1:** add `/api/v2` alongside it. Send `Deprecation` and `Sunset` headers on v1. Run both against the same contract tests. Version MCP tool behaviour behind the same switch. Watch usage per route in the ledger before removing v1.
- **Trade-offs to discuss:**
  - The fixed rate-limit window allows bursts at the edge.
  - The ledger is committed before the 2xx is sent, so a lost response stays billed.
  - Header-only idempotency, so MCP retries without a key are billed.
  - The advisory lock serialises billing per (key, `Idempotency-Key`). Calls without the header take no lock.
  - The quota is per key, so a user can raise their effective limit by creating more keys.
  - The limiter runs on Redis and fails closed, so a Redis outage refuses every API call.
  - The developer page is local only.
- **For `AI_NOTES.md`:** decisions where your answer differed from my recommendation in Parts 3 and 4:
  - Part 3: Redis instead of the database store; the limiter fails closed.
  - Part 4: Pi-Bolt instead of Claude Desktop; scaffolding the frontend; `DESIGN.md` not written by the agent.
