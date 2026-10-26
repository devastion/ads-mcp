# User decisions

Every decision the user made across `docs/superpowers/specs/` and `docs/superpowers/plans/`, plus the open items still waiting on the user. Compiled 2026-10-26 from the specs and plans as they stand on `main` and their worktree branches.

**Status:** `Active` = in force. `Superseded` = replaced by a later decision (the replacement is named). `Pending` = not yet answered or not yet built.

**Sources:** Spec files are under `specs/`. A reference such as `P3 §Quota` points to that section of the Part 3 spec. Decisions marked *(session)* were made in conversation and recorded in the Part 3/4 sync notes, not in a spec header.

Labels used in the specs: **[user]** = chosen by the user. **[my call]** = the assistant's default, which the user may veto. Only **[user]** items appear below as decisions. [my call] items are listed separately at the end, for information only.

---

## Part 0: v0 endpoint, data and seed

Spec: `specs/2026-10-24-part0-v0-design.md`. Status: approved and implemented.

| # | Decision | Status | Source |
|---|---|---|---|
| P0-1 | **Search code shape: option B.** One shared `SearchAds` Action, used by v0 now and extended later. The v0 HTTP layer (controller, input parsing, resource, auth, error rendering) stays v0-specific. | Active (narrowed by P1-1) | P0 §Approaches, "Decision: B, the user's call" |
| P0-2 | **Minimal skeleton:** restore only the boot, migration, seed and check files from `950121b`. Leave out `resources/`, the `Makefile` and `950121b`'s tests. | Active | P0 §Skeleton |
| P0-3 | **Delete the starter `tests/Feature/ExampleTest.php`** in the skeleton commit. It can't pass without front-end files. | Active | P0 §Skeleton, plan line 44 |
| P0-4 | **Seed dates are relative to today.** Two runs on the same day give identical data, and `README.md` states that assumption. | Active | P0 §Seed |
| P0-5 | **v0 edge-case table is frozen.** Every row is user-approved: invalid `page`/`per_page` fall back to defaults; `per_page` clamps to 100; `q` is trimmed; array-valued parameters are ignored; filter values are case-sensitive; `niche` accepts slug or display name; unknown parameters are ignored; a repeated parameter takes the last value; any failure under `api/v0/*` returns `400 {"error":"Invalid request"}`. | Active | P0 §"v0 edge cases, frozen (user-approved)" |
| P0-6 | **Local dev key only.** `DatabaseSeeder` creates a fixed, documented key, and only when `APP_ENV=local`. | Active | P0 §Data model/seed |

---

## Part 1: v1 search API

Spec: `specs/2026-10-24-part1-v1-design.md`. Status: approved and implemented. Index migration not built (see P1-3).

| # | Decision | Status | Source |
|---|---|---|---|
| P1-1 | **Shared predicates.** Share the filter builder (`q`, `niche`, `format`, `status`, `started_after`). v0 keeps its own `start_date desc` ordering and offset paging. This narrows P0-1 to the predicates only. | Active | P1 §1 "Shared predicates" |
| P1-2 | **Impressions sort: "Rank, then id".** `ORDER BY impression_rank ASC NULLS LAST, id ASC`, with the stated limitation that rank is brand-relative. | Active | P1 §2 "Rank, then id" |
| P1-3 | **Snapshot by id.** Opaque, signed keyset cursor with a snapshot cutoff (`id <= c`). Ads inserted during a walk are not returned. The indexes that this relies on were **not built**. | Active (indexes: Pending) | P1 §3 "Snapshot by id"; P1 Sync notes "Not built" |
| P1-4 | **Scramble for OpenAPI, generated.** Add `dedoc/scramble` (approved dependency). The spec covers v1 only. A drift test checks the committed `docs/openapi/v1.json`. | Active | P1 §4 "Scramble, generated"; plan line 9 |
| P1-5 | **Bearer auth only** on v1. A query-string `api_key` is rejected with `api_key_in_query`. | Active | P1 §5 "Bearer only" |
| P1-6 | **No total count.** v1 returns `has_more` and `next_cursor`, not `total`. | Active | P1 §6 "Omit total" |
| P1-7 | **Repeated `niche` parameter only.** `niche=a&niche=b` is accepted. The comma form is rejected with 422. | Active | P1 §7 "Repeated only" |

---

## Part 2: MCP over v1 REST

Spec: `specs/2026-10-25-part2-mcp-design.md`. Status: approved 2026-10-25. Tasks 1–2 (REST reads) built. The MCP server (Tasks 3–12) is not started.

| # | Decision | Status | Source |
|---|---|---|---|
| P2-1 | **Laravel MCP with Streamable HTTP.** No stdio transport. `laravel/mcp` becomes a direct runtime dependency. | Active. The dependency is still transitive, not added (Pending build). | P2 §"Selected approaches" 1; plan line 80 |
| P2-2 | **Tools call the v1 HTTP endpoints, not Actions directly.** The extra hop is accepted. The hybrid and direct approaches were rejected. | Active | P2 §"Selected approaches" 2 |
| P2-3 | **Add `GET /api/v1/ads/{id}` and `GET /api/v1/niches`.** Existing endpoint contracts are not changed. | Active (built on `part-2-mcp`, `6c3a8bb`) | P2 §"Selected approaches" 3 |
| P2-4 | **Compact JSON text output.** One text block per tool result. No `structuredContent` and no `outputSchema`. | Active | P2 §"Selected approaches" 4 |
| P2-5 | **One rate debit per handled operation.** Data tools are debited once, in REST. Discovery and control requests are debited once by MCP's shared limiter. | Active | P2 §Auth "One rate debit per handled operation" |
| P2-6 | **Rate-limited data tool result.** MCP returns HTTP 200 with `isError:true`, code `rate_limited`, and integer `retry_after_seconds`. This is a translation, not an HTTP 429. Locally throttled discovery returns a real 429. | Active | P2 §Auth, "For data tools" paragraph |
| P2-7 | **Billing commit point is the successful REST read.** Empty searches are billable. Local failures and unsuccessful REST calls are not. | Active | P2 §Auth "Billable commit point" |
| P2-8 | **Header-only idempotency.** There is no `idempotency_key` tool argument and no `_meta` fallback. Calls without a key can be billed again on retry. | Active | P2 §Auth "Header-only idempotency" |
| P2-9 | **Real Pi-Bolt evaluation, user-run.** The agent does not run it, and no mocked or role-played transcripts count. The five preregistered tasks are frozen before any baseline. Model spend stays within the assessment's few-dollar budget. | Active. Not authorised to start. | P2 §"Real Pi-Bolt evaluation [user]"; plan Task 12 |

---

## Part 3: keys, rate limits, metering

Spec: `specs/2026-10-25-part3-key-rate-limits-metering.md`. Status: approved. Implementation in progress on `part-3-keys-metering`.

| # | Decision | Status | Source |
|---|---|---|---|
| P3-1 | **Scopes stored as a JSON column,** validated against the `Scope` enum: `search:read` and `keys:manage`. Existing keys migrate to `["search:read"]`. | Active | P3 §Keys |
| P3-2 | **v1 denial is 429** with `Retry-After`, the `rate_limited` code and `details.retry_after_seconds`. | Active | P3 §Rate limit |
| P3-3 | **v0 denial is 400 `{"error":"Invalid request"}` with a `Retry-After` header, not 429.** Every v0 error keeps the same 400 body. This departs from the assessment's "429" wording, and `DESIGN.md` must record it as a deliberate trade-off. | Active | P3 §Rate limit, "[user] v0 denial"; plan line 19; P0 sync notes |
| P3-4 | **Quota is included,** at 1,000 billed calls per key per UTC month. | Active | P3 §Quota "[SHOULD, user: include]" |
| P3-5 | **One table, `api_usages`,** feeds billing, quota and the Part 4 page. | Active | P3 §Ledger |
| P3-6 | **MCP client retries are header-only.** The caller's `Idempotency-Key` is forwarded unchanged. No key is derived from JSON-RPC ids or arguments. | Active | P3 §Idempotency "MCP client retries [user, Part 2]" |
| P3-7 | **Rate-limit store is Redis, failing closed.** Refused requests are not served unlimited, and there is no fallback store. | **Active. Changed on 2026-10-25, replacing the earlier database store.** Not built on `part-3-keys-metering`. | P3 §Rate limit "Store: Redis, failing closed [user, changed 2026-10-25]" |
| P3-8 | **Quota is per key, with no owner or account.** Each key has its own 1,000 billed calls per month. A user can raise their effective limit by creating more keys. `DESIGN.md` should say so. | Active. This replaces the owner-based model in the earlier Part 4 draft. | P3 §"Quota is per key [user, confirmed 2026-10-25 while syncing Part 4]"; P4 §Access |
| P3-9 | **`keys:manage` is stored and checkable but grants no data access.** | Active | P3 §Keys, "Scope required" bullet |

---

## Part 4: developer page

Spec: `specs/2026-10-25-part4-developer-page-design.md`. Status: **draft, awaiting the user's approval.** Not yet approved, so Task 0 of the plan is blocked on this.

| # | Decision | Status | Source |
|---|---|---|---|
| P4-1 | **Approach A.** One Inertia page with server props, `useHttp` mutations and Pest feature tests. | Active | P4 §Approaches "[user: A]" |
| P4-2 | **No JS test runner, and no browser tests.** | Active | P4 §Constraints "[user: approach A]" |
| P4-3 | **Local only, no login, no owner.** One hard-coded local user owns every key. There is no owner column, no user table and no default-owner Action. | Active | P4 §Access "[user: local only]" |
| P4-4 | **Part 4 builds the frontend scaffold.** `resources/` does not exist on `main`, so Part 4 creates it. | Active | P4 §"Frontend base [user]"; §Scaffold |
| P4-5 | **The panel targets Pi-Bolt,** not Claude Desktop. The panel creates a key named `Pi-Bolt` with `search:read` only. | Active (from the session). Not in the spec header. | P4 §"Connect your AI tool (Pi-Bolt) [user]" |
| P4-6 | **The real key appears inline only right after creation.** Later visits show `<YOUR_KEY>`, because the secret can't be shown again. | Active | P4 §Design, "Copy the config" |
| P4-7 | **Secret shown once** in the create response, with a copy button. Never in props, session, `localStorage` or the database. | Active | P4 §Goals 1, §Anti-goals |
| P4-9 | **`DESIGN.md` and `AI_NOTES.md` are not written by the agent.** `AI_NOTES.md` is protected. The user writes both files. | Active | P4 §Non-goals; P4 Appendix |
| P4-10 | **The panel's "Connected" check uses the key's `last_mcp_request_at`,** set on every authenticated MCP request, `initialize` included. | Active. The check can't be verified until Part 2's MCP endpoint exists. | P4 §"First-MCP-call signal [user: key's last MCP request]" |
| P4-11 | **Usage comes from the same ledger.** No second counter. | Active | P4 §Anti-goals; P3 §Intent |
| P4-12 | **Developer routes go through the `web` middleware group,** so CSRF applies. Not under `api/`, not billed, not rate-limited per key. | Active | P4 §Access, §Routes |

### Decision on the execution method (from the session)

| # | Decision | Status | Source |
|---|---|---|---|
| P4-13 | **Execute the plan natively,** in the current session, and not with subagent-driven development. | Active (session: "Use native"). Applies to Part 4 execution. | Session, after plan review |

---

## Claude Code plugin

Plan: `plans/2026-10-26-claude-code-plugin.md`. Status: decided 2026-10-26 in conversation, built on `claude/ads-plugin-plan`.

| # | Decision | Status | Source |
|---|---|---|---|
| CC-1 | **Repo marketplace.** `.claude-plugin/marketplace.json` at the root, plugin in `plugins/ads/`. No root `.mcp.json`. | Active | (session) 2026-10-26 |
| CC-2 | **Contents: MCP server plus one usage skill** (`ads:ad-research`). | Active | (session) 2026-10-26 |
| CC-3 | **Plugin only.** Pi-Bolt stays the Part 2 evaluation client (P2-9) and the developer-panel client. | Active | (session) 2026-10-26 |

---

## Cross-part decisions

| # | Decision | Status | Source |
|---|---|---|---|
| X-1 | **The agent does not edit protected or user-owned docs.** `DESIGN.md` and `AI_NOTES.md` are the user's. | Active | P4 §Non-goals; `AI_NOTES.md` header |
| X-2 | **Only approved new dependencies:** `dedoc/scramble` (P1-4) and `laravel/mcp` as a direct requirement (P2-1). Anything else needs asking first. | Active | P1 §4; P2 §1 |
| X-3 | **The user runs the full suite (`composer test`).** The agent runs targeted tests only, and claims nothing about the full run. | Active | P1 Verification; P2 Verification; P3 Commands; P4 Constraints |
| X-4 | **Do not change the v0 contract test** (`tests/Feature/Http/Controllers/V0/AdControllerTest.php`). Sanctioned v0 behaviour goes in a separate test file. | Active | P0; P3 §Verification 7; P2 §Verification |
| X-5 | **Manual checks belong to the user,** and the agent must not claim them. These include the copy button, the secret disappearing after reload, a real Pi-Bolt connection, and a timed five-minute setup. | Active | P4 §Testing, "Checked by hand" |
| X-6 | **Per-key quota, no owner** (see P3-8). | Active | P3 |
| X-7 | **Redis fail-closed** (see P3-7). | Active | P3 |

---

## Open questions and pending decisions

These are not answered. The user needs to decide them before the work they block.

| # | Question | Blocks | Options and default |
|---|---|---|---|
| Q-1 | **Approve the Part 4 spec?** It is still a draft. | Part 4 Task 0 gate | Approve, or request changes. |
| Q-2 | **Confirm the Redis outage mapping.** When Redis is down, v1 returns `internal_error` (500, retryable) and v0 returns 400 `Invalid request`. | Part 3 Redis change | Confirm, or pick another code. Default: confirm. |
| Q-3 | **MCP surface marker mechanism.** `X-Ads-Origin` is currently stripped, so MCP calls are metered as `v1`. Options: a server-side secret or a loopback-only check. | Part 2 Task 3, accurate `surface = mcp` | Choose one. No default yet. |
| Q-4 | **Which base for the `part-2-mcp` worktree?** The plan requires Part 3 complete first. | Part 2 Task 3 onward | Wait for Part 3 to merge, or use the current base. |
| Q-5 | **Part 2 evaluation timing and inputs.** Which `get_ad` id, and which application date D? | Part 2 Task 12 | User decides. The baseline must be frozen before any run. |
| Q-6 | **Part 4 base.** Part 3 must be merged, and Part 2's MCP endpoint should exist for the "Connected" check. If Part 2 is not built, the README says the check is unverified. | Part 4 Task 0 | Wait, or ship with the check marked unverified. |
| Q-7 | **v0 server-error mapping.** The spec reads §0.4 literally: any server error under `api/v0/*` returns 400. Confirm this is the intent. | Part 0 behaviour, already built | Confirm. Default: 400, as built. |

### Manual checks the user owns (Part 4, X-5)

- The copy button works.
- The secret is gone after dismiss and after reload.
- The status changes to "Connected" when a real Pi-Bolt connects.
- A timed run of the five-minute setup.

---

## Decisions the assistant made (listed for review only)

These are [my call] defaults recorded in the specs. None of them is a user decision. They are listed so the user can veto them.

- Part 1: ascending id as the tiebreak for rank 1 (P1-2 says "then id" but not the direction).
- Part 1: no expiry on cursors; `per_page` not bound to the cursor; cursors signed with a key derived from `APP_KEY`.
- Part 3: the limiter runs before the scope check. The same `Idempotency-Key` bills once regardless of endpoint or parameters. A metering failure fails the request rather than serving an unmetered read.
- Part 3: all three surfaces bill the same way, one unit per read.
- Part 4: `EnsureLocalEnvironment` middleware, not conditional route registration (so `route:cache` stays safe). Shared count extracted into `CountBillableCalls`. Resources are whitelisted through an API Resource.
- Part 4: the panel's key is the newest active `Pi-Bolt` key. Polling runs every 3 s, stopping once connected.
- Part 4: Pi-Bolt's user-level config path, not the repo's `.pi/mcp.json`, because that file is project-level and not gitignored. The exact path still needs verifying against Pi-Bolt's docs.
- Part 2: never configure one fixed idempotency key for every tool call. This is a design rule from the spec, not an explicit user choice.

---

## Change log

- 2026-10-26: Regenerated this file. The earlier copy was not on `main`. Sources: all five specs and five plans on `main`, plus the Part 3 and Part 4 sync notes.
