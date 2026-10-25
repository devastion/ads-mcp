# Part 2 design: MCP over v1 REST

Date: 2026-10-25. Status: **draft — awaiting approval of this written design**.

Only Phase 1 is authorized. This document does not authorize implementation, dependency changes, a plan, commits, or evaluation runs. Decisions labelled **user** were selected during brainstorming; details labelled **proposal** become approved only if this document is approved.

## Intent and assessment contract

Expose a small, honest, recoverable interface for models searching synthetic ads. Agent experience, not transport novelty, is the priority.

`ASSESSMENT.md`, Part 2:

> **MUST** Build `search_ads`, `get_ad` and `list_niches` on top of v1 (stdio or Streamable HTTP). The MCP server shares auth, rate limits and metering with REST.

> **MUST** **Response design:** compact output sized for a context window, with a clear way to get more (a cursor, or `get_ad` for full detail). State the size budget you chose per result and how you keep to it.

> **MUST** **Errors for a literal reader:** what was wrong, the corrected call, and whether retrying will help. A rate-limit error says how long to wait.

Part 3 requires a shared per-key **60 requests/minute**, accurate `Retry-After`, and billing once for requests with the same `Idempotency-Key` within 24 hours. Part 2 also requires five real-model/client tasks, before/after results, and a genuine misuse/change/retest transcript in `MCP_EVAL.md`.

## Ground truth and prerequisite gate

Read Part 0's design/plan and Part 1's design/plan, not just this document. Relevant paths:

- `docs/superpowers/specs/2026-10-24-part0-v0-design.md`
- `docs/superpowers/plans/2026-10-24-part0-v0.md`
- Part 1 worktree: `docs/superpowers/specs/2026-10-25-part1-v1-design.md` and `docs/superpowers/plans/2026-10-25-part1-v1.md`.

Inspection is a snapshot, not a claim that another session has finished:

| Surface | Verified state during design |
| --- | --- |
| Main checkout | `c25232f`; unrelated staged/unstaged notes and untracked prompt templates. Leave them untouched. |
| Part 0 worktree | `7ffe49a`. |
| Part 1 worktree | Advanced from `f9fe568` to clean `1d08123` during discussion. Latest commits add v1 errors, not the search endpoint. |
| Actual REST routes at final inspection | Only `GET /api/v0/ads`; no v1 search, detail, or niches routes. |
| Part 1 design | Header still says draft/awaiting approval. It records choices but is not evidence of completed implementation or current approval. |
| Actual shared foundation | `Ad`, `Ad::daysActive()`, `Niche`, hashed `ApiKey` lookup, v0 search/auth. V1 error enum/exception/renderer now exist in the v1 worktree. |
| Missing prerequisites | Completed v1 search/Bearer integration, scoped/revocable keys, the shared limiter, and concurrent/idempotent metering. |
| Packages | Main vendor has Laravel 13.38.0 and `laravel/mcp` 1.1.1. MCP is transitive through development dependency `laravel/boost` 2.10.3, not a direct production requirement. |
| Area rules | `.ai/rules` absent in both inspected checkouts. Recheck the eventual implementation worktree. |

**Implementation gate:** first confirm/integrate a completed, approved v1 base and the shared Part 3 facilities. Reinspect actual interfaces and dependency versions there. Do not create MCP-owned auth, scopes, revocation, limiter storage, or billing storage to hide missing prerequisites. The Part 3 work must already document its sanctioned v0 rate-limit/metering behavior; this design does not authorize changing frozen v0 responses.

Approved design work can proceed now. Full Part 2 integration cannot be declared complete while those prerequisites are absent. Approval of this design is not approval to change the running v1 worktree.

## Scope and approaches

### Selected approaches

1. **Laravel MCP + Streamable HTTP [user].** Existing PHP ecosystem and protocol implementation. Declare `laravel/mcp` as a direct runtime dependency during authorized implementation; this dependency change was approved in brainstorming. Resolve/verify its actual version with Composer; never edit a lockfile manually. No stdio transport in this part.
2. **HTTP calls into REST [user], rather than direct Action calls.** All three tools forward to v1 HTTP endpoints. REST remains authoritative for domain validation, normalized filters, cursors, data reads, and successful-read accounting. Accept the extra hop, latency, duplicate authentication checks, and operational worker requirement. Direct application-layer reuse was recommended but not selected; it would avoid the hop but require extracting a shared orchestration boundary. A hybrid HTTP/direct adapter was also rejected.
3. **Add v1 detail and niches reads [user].** `GET /api/v1/ads/{id}` and `GET /api/v1/niches`, alongside the eventual existing `GET /api/v1/ads`. These additive endpoints resolve the missing HTTP coverage. Do not alter existing endpoint contracts.
4. **Compact JSON text [user].** One text block per tool result, no mirrored `structuredContent`. The alternative structured-content-plus-text format was rejected because it duplicates the payload. No `outputSchema` advertised while results are text-only.

### Non-goals and anti-goals

No developer page, connection panel, OAuth rollout, monthly quota, new key-management features, new counters, aggregation endpoint, impression totals, speculative performance ranking, replay script, or simulated model evaluation. No scraping. No global middleware change, v0 cleanup, model serialization changes, or edits to the v0 contract test. Preserve the existing v1 search's validation, filter echo, cursor behavior, errors, ordering and response fields.

## Architecture and HTTP boundary

**Proposal:** register a stateless Streamable HTTP endpoint at relative path `/api/mcp`, using Laravel MCP's route registration. Keep Laravel's discovery/initialize negotiation compatible with the actual Pi-Bolt client; the installed package has multiple protocol revisions and handshake paths. Test the negotiated revision, not an assumed universal version. Use normal JSON responses, no subscription/session state or streaming tool output.

```text
Pi-Bolt -- Bearer + optional Idempotency-Key --> MCP endpoint
    known valid tool envelope --> one v1 REST request
                                 --> shared auth/scope --> shared limiter
                                 --> v1 operation --> shared meter --> response
    locally handled request --> shared auth/scope --> shared limiter --> response
```

- Use the original Bearer credential, never a privileged server key. Recheck current revocation/scope on every incoming authenticated operation and at REST. Require `search:read`; never add a separate `mcp:use` permission.
- Reuse the shared auth implementation. An HTTP adapter may repeat the same check; it must not implement a second token store or cache authorization across calls.
- The MCP route must not have blanket metering/throttling middleware that also charges forwarded calls. Classify into the local or REST path before consuming rate capacity. Do not use a caller-controlled bypass header to skip REST middleware.
- Restrict the REST base URL to trusted server configuration. Tool arguments cannot choose a host or path. Use normal TLS verification outside local development, disable redirects, and do not forward arbitrary incoming headers. Forward only required auth, idempotency and safe content-negotiation/correlation headers.
- Search arguments map to query parameters, not a JSON GET body. `niche:["skincare","haircare"]` becomes repeated `niche=skincare&niche=haircare`, not bracket syntax. `limit` becomes `per_page`. Other supported filters and the opaque cursor pass through without reinterpretation. Test the actual encoded URL; default query serializers may emit incompatible bracket notation.
- The configured REST host needs independent request capacity: a single-worker PHP server synchronously calling itself can deadlock. Use separate MCP/REST listeners or a verified multi-worker server. Keep one shared database and limiter backend. The future run instructions must prove the setup works from a clean clone.
- Bound connection/response timeouts and disable automatic downstream retries. **Proposal:** 2-second connect and 10-second total timeout, configurable for deployment. A timeout becomes `internal_error`, with an explicit warning that REST may already have completed and billed the read.
- Validate Origin against configured allowed origins when present; reject disallowed values. Authenticate all supported HTTP entry points; do not invent a GET event stream. Preserve the package's method/header/protocol validation.
- Use one JSON-RPC request per POST. Reject batch arrays rather than silently execute or bill them as one operation. Notifications do not invoke data tools and do not produce billable reads.

### Placement without changing code structure [proposal]

Stay under existing roots: protocol coordination in `app/Http/Controllers/Mcp/`, single-operation tool/HTTP actions in `app/Actions/Mcp/`, REST controllers/requests/resources under their existing v1 roots, and any transport middleware under `app/Http/Middleware/`. DTOs remain in `app/DTO/`; routes remain in `routes/`. No new `app/Mcp` root merely because the package generator defaults to it. Class names and generator options are builder decisions, verified before execution.

Existing v0/v1 search Actions are not reused directly by the MCP adapter: the selected reuse boundary is HTTP. New REST reads use Actions and Resources according to `AGENTS.md`, `Niche::cases()` and `Ad::daysActive()`. They do not add global scopes or model appends.

## Auth, shared throttling, metering, and retries

### One rate debit per handled operation [user]

| Operation | Owner of shared rate debit | Billable |
| --- | --- | --- |
| Known tool dispatched to REST | REST only, once per attempted REST operation | Successful REST data read only |
| REST validation failure, not found, or server error | REST only | No |
| Discovery/initialize/ping/control request, unknown tool, or locally rejected tool arguments | MCP invokes the same shared limiter service, once | No |
| Notification | None; no data operation dispatched | No |
| Missing/invalid/revoked key or denied scope | Reject before data dispatch; use the shared auth ordering | No |

A throttled attempt follows the shared limiter's established denial semantics. The 60/min window and wait calculation are those of the shared Part 3 implementation, not a second MCP algorithm. Initialize/tools-list traffic can consume budget even though it is free. Data-tool traffic must not be debited both at MCP and REST. Dispatch failures that never reach REST are not accepted backend data operations; do not issue a speculative second debit after an ambiguous timeout.

For data tools, REST denial produces HTTP 429 and accurate `Retry-After`. MCP translates this into a normal tool-result envelope over HTTP 200 with `isError:true`, the shared `rate_limited` code, and integer `retry_after_seconds` in its JSON error details [user]. This is an explicit protocol translation, not a claim that the MCP HTTP response is 429. Locally throttled discovery/control requests remain HTTP 429 with `Retry-After` and a bounded, actionable error body.

### Billable commit point [user rule; proposal clarifies delivery ambiguity]

All successful search, detail-page, and niches reads are billable, including empty searches. Each forwarded call creates at most one billing decision, owned by REST's shared meter. MCP never writes a second usage row. Local protocol failures and unsuccessful REST operations are not billable.

The commit point is the successful REST operation, not proof that the model received it. A lost response or unexpected adapter failure after REST success does not undo a completed read. Document that distinction; otherwise timeout billing cannot be explained truthfully. Usage labels identify the canonical REST read and may carry safe origin metadata, but origin must not partition the limiter or idempotency namespace.

### Header-only idempotency [user]

- Forward a per-call `Idempotency-Key` unchanged. Its format, hashed persistence, 24-hour boundary, same-key conflicts and concurrency behavior are defined once by the shared meter. MCP does not extend its lifetime, cache results, or reinterpret it.
- A REST call and its MCP-forwarded equivalent use the same API-key/idempotency namespace. No endpoint-, transport-, model-, or JSON-RPC-ID-based second deduplication system.
- No `idempotency_key` tool argument or `_meta` fallback. If passed as an argument, report `unknown_parameter` and explain the HTTP-header requirement without echoing its value.
- Never derive a billing key from JSON-RPC IDs, reconnect/session IDs, or argument hashes. Equal searches can be separate legitimate billable calls.
- Calls without a per-call key can be billed again on retry. This limitation was explicitly chosen over exposing a tool argument. Native Pi-Bolt configuration supports static headers but does not provide a verified per-tool dynamic idempotency mechanism; never configure one fixed key for every tool call.
- A retry with a preserved key is billed once under the shared 24-hour rule, but every accepted attempt still competes for rate capacity. Billing deduplication does not promise identical replayed data.
- There is no automatic retry in the MCP-to-REST adapter. Record the real Pi-Bolt version's actual retry behavior during evaluation rather than relying on assumptions about client retries.

### Shared prerequisite details [proposal]

Missing/invalid/revoked Bearer credentials reuse the shared catalogue (`api_key_missing`, `api_key_invalid` for invalid/revoked credentials); query-string credentials are rejected with `api_key_in_query`. Scope denial needs a shared 403 entry, proposed `insufficient_scope`, with `retryable:false` and a request for a `search:read` key. If Part 3 has already established another scope code, adopt that exact code in the final plan and both surfaces instead of adding an MCP alias. Resolving this with the shared foundation is a prerequisite, not permission to implement key features in Part 2.

## Exact model-facing tool contract [proposal]

All tools are read-only, non-destructive and closed to external-world writes. Set appropriate package-supported annotations, but do not imply that repeat reads are free: billing is a documented side effect. Every tool accepts exactly the listed object properties (`additionalProperties:false`) and also checks them at runtime. Null/empty values are invalid; omit unused arguments. Never accept an API key in tool arguments.

### `search_ads`

Description:

> Search synthetic ads by literal keyword and exact filters; returns compact previews, applied_filters and a next_cursor. Use get_ad for complete copy and dates, and list_niches to discover niche slugs. Omitted status includes active AND inactive ads. started_after is exclusive YYYY-MM-DD (start_date > date), not discovery date. sort=impressions orders brand-relative rank, missing ranks last; it cannot identify the brand with the most impressions or compare impression totals. Do not use for brand-performance rankings. Repeat the same filters/sort with next_cursor to continue; cursor results exclude later inserts subject to v1's documented in-flight-insert caveat. limit defaults to 5, maximum 10. Successful reads, including empty results, are billable; retry billing protection requires a stable per-call Idempotency-Key HTTP header.

Input schema:

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {
    "q": {"type": "string", "description": "Literal case-insensitive substring in headline OR body_text. Trimmed length must be 2–200; omit if unused. No wildcard syntax."},
    "niche": {"type": "array", "minItems": 1, "items": {"type": "string", "enum": ["skincare", "haircare", "supplements", "fitness", "pet-supplies", "home-decor", "kitchen", "apparel", "jewelry", "baby-kids", "outdoor", "tech-accessories"]}, "description": "One or more exact slugs from list_niches; OR between slugs. REST sorts and deduplicates the applied list. Display names are not accepted."},
    "format": {"type": "string", "enum": ["image", "video", "carousel"], "description": "Exact ad format; omit for all formats."},
    "status": {"type": "string", "enum": ["active", "inactive"], "description": "Active means still running; inactive means stopped. Omit for both."},
    "started_after": {"type": "string", "format": "date", "description": "Real YYYY-MM-DD date; exclusive start_date lower bound, not discovered_at. Future dates are valid. For the last 30 days, use the supplied application date minus 30 days."},
    "sort": {"type": "string", "enum": ["newest", "impressions"], "default": "newest", "description": "newest: start_date descending, deterministic id tie-break. impressions: within-brand rank ascending, null last, deterministic id tie-break; NOT global impression counts."},
    "limit": {"type": "integer", "minimum": 1, "maximum": 10, "default": 5, "description": "Ads per page; maps to REST per_page. Out-of-range values are rejected, not clamped."},
    "cursor": {"type": "string", "minLength": 1, "maxLength": 2048, "description": "Opaque next_cursor from the previous page. Preserve all filters and sort; limit may change. Omit to start a new walk."}
  }
}
```

Enum values are generated from the same installed application enums, not copied into a second maintained catalogue. The schema above records the inspected values. Do not silently change v1's normalization or raw-query validation to suit MCP. V1 remains authoritative for semantic values/dates/cursors; the adapter owns JSON types, unknown tool arguments, the MCP limit cap, and representation mapping. Match v1's trim-then-length behavior for `q`: its length constraint is described in the schema and enforced semantically after trimming, not imposed as a raw-string `maxLength` that rejects otherwise valid normalized input.

Success JSON text has exactly the v1 eight summary fields inside `data`, plus optional per-item `truncated_fields:["brand","headline"]`. Top-level fields: `data`, unchanged `applied_filters`, `has_more`, `next_cursor`, `detail_tool:"get_ad"`, and v1 `notes` when present. Do not add totals, body text, invented impression counts, or a false global ranking.

### `get_ad`

Description:

> Fetch one ad by its exact id from search_ads. Returns complete brand/headline, running dates, countries, brand-relative impression_rank, discovered_at and derived days_active, plus a body_text page. Use search_ads to find ads, not this tool. body_offset counts Unicode characters from zero; body_limit defaults to 1000, maximum 1000. Follow body_next_offset while body_has_more is true to retrieve all copy without gaps. For active ads days_active is today minus start_date; for inactive ads it is end_date minus start_date, whole days, using the same implementation as REST/v0. A rank cannot compare different brands. Each successful detail page is billable; retry protection requires the per-call Idempotency-Key HTTP header.

```json
{
  "type": "object",
  "additionalProperties": false,
  "required": ["id"],
  "properties": {
    "id": {"type": "integer", "minimum": 1, "description": "Exact stable ad id returned by search_ads; not a brand id."},
    "body_offset": {"type": "integer", "minimum": 0, "default": 0, "description": "Zero-based Unicode-character offset; use body_next_offset for continuation."},
    "body_limit": {"type": "integer", "minimum": 1, "maximum": 1000, "default": 1000, "description": "Maximum body characters in this page; not a byte or token count."}
  }
}
```

New REST endpoint accepts `body_offset` and `body_limit` only, with the same rules. Its response is `{data:{id,brand_id,brand,headline,body_text,niche,format,status,start_date,end_date,days_active,impression_rank,countries,discovered_at},body_offset,body_has_more,body_next_offset,body_total_characters}`. Niche is the slug, dates are ISO calendar dates, discovered_at is an ISO timestamp, and nullable end_date/rank remain null. The body is explicitly a slice, not falsely labelled complete.

Offsets at or beyond the end return an empty body with no continuation; empty bodies behave the same way. No silent clamping or surrogate-byte slicing. The next offset is the current offset plus characters actually returned. Body copy is not a snapshot across edits; ingest does not modify it, and a new walk is needed if some future writer does. This is not a new stored-version feature.

`countries` is a sorted, unique list of uppercase ISO-2 codes in this new resource; duplicates do not represent extra countries. Brand and headline respect the existing varchar(255) schema, and their detail values are not truncated. Malformed stored data fails safely rather than silently discarding metadata. No existing REST resource changes to impose these new endpoint rules.

### `list_niches`

Description:

> List all supported niche slugs and display names. Use these exact slugs in search_ads.niche (an array); for example pet-supplies, not Pet Supplies. Use for vocabulary discovery, not ad search, brand ranking or impression totals. Returns the full fixed catalogue, no pagination or counts. A successful call is billable; retry protection requires a per-call Idempotency-Key HTTP header.

```json
{"type":"object","additionalProperties":false,"properties":{}}
```

Return `{data:[{slug,name},...]}` in `Niche::cases()` order, all 12 entries. Generate from the existing enum. New REST endpoint accepts no query filters and returns the same catalogue. No second hard-coded list.

## Response budgets and enforcement [user targets; proposal mechanics]

Use UTF-8 compact JSON with unescaped Unicode/slashes and proper escaping. Bytes are measured **after serialization**, not estimated from character counts or equated with tokens.

| Bound | Target |
| --- | --- |
| Each serialized search item | At most 1,024 bytes |
| Search limit | Default 5; maximum 10 |
| Search metadata, outside items | At most 4 KiB |
| Search/detail JSON text | At most 14 KiB |
| Niches JSON text | At most 2 KiB |
| Complete serialized JSON-RPC tool response | At most 32 KiB |
| Error JSON text | At most 14 KiB, generally much smaller |

Search preview mechanics: initially at most 64 Unicode characters for brand and 120 for headline; then cap their serialized JSON string values to 192 and 512 bytes respectively, including an ellipsis when truncated. The remaining fixed fields/keys/flags must fit the item's 1,024-byte cap. Never cut UTF-8 or serialized JSON bytes in the middle. `truncated_fields` truthfully names every changed field, including brand; use get_ad for original values.

Never drop already-fetched rows to make a page fit: REST's cursor would then skip them. Never drop filters, next_cursor, or the rank warning. An unexpected contract/budget violation is a bounded `internal_error`, logged without credentials; after REST success, that read may already be billed. This is a fault path, not an excuse to accept oversized normal records. Preflight fixtures and worst-case tests must make valid responses fit.

For detail, a 1,000-character body costs at most 6,000 JSON bytes even with control-character escaping. The two varchar(255) fields cost at most 3,060 combined; unique two-letter country codes are finite; remaining fields/continuation metadata must fit the 14 KiB payload budget. Tests prove the actual serializer's bound rather than relying only on this arithmetic. Do not reduce body characters behind the caller's back; fail a violated contract and fix the design/implementation instead.

Measure the entire MCP envelope too, including the second escaping layer of JSON text and request-id overhead. **Proposal:** accepted string JSON-RPC ids are bounded to 128 UTF-8 bytes (integers also accepted); oversized/unsupported ids receive a bounded invalid-request error with null id. This input bound keeps the complete-response claim meaningful. Bound incoming HTTP request size as well; do not echo oversized input in errors.

These budgets are **not tested yet**. Phase 1 contains no implementation or measurement result. They are acceptance criteria for later RED/GREEN tests, including maximum-length brands/headlines, emoji, quotes, backslashes, control characters, full niche filters, cursor/notes, maximum country coverage, and multi-page body reconstruction. Bytes provide a concrete context safeguard, not an exact model-token guarantee.

## Errors for a literal reader [user framing; proposal mapping]

Known-tool execution/validation errors use `isError:true` and one JSON text block:

```json
{
  "error": {
    "code": "invalid_parameter",
    "message": "Invalid niche slug: Skincare.",
    "next_step": "Use the exact slug skincare in the niche array. Retry only after correcting the arguments.",
    "retryable": false,
    "details": {
      "parameter": "niche",
      "did_you_mean": "skincare",
      "corrected_call": {"name":"search_ads","arguments":{"niche":["skincare"],"limit":5}}
    }
  }
}
```

Preserve the shared `ApiErrorCode` string and `retryable` meaning. Translate v1 query terminology into MCP argument names (`per_page` becomes `limit`); preserve normalized validation details/allowed values. Do not run a separate fuzzy-matching algorithm for shared domain values. Unknown MCP argument names are checked against the schema and use the existing suggestion helper where available.

| Failure | MCP behavior |
| --- | --- |
| Unknown argument (`nich`, `per_page`, or `idempotency_key`) | `unknown_parameter`, actionable argument/header correction; no silent ignoring. |
| Wrong JSON type, invalid enum/date, empty value, wrong limit, bad niche | `invalid_parameter`; retain allowed values and safe suggestions. |
| Tampered/malformed cursor | `invalid_cursor`; show a call without cursor to restart. |
| Changed filters/sort with cursor | `cursor_mismatch`; preserve the previous filters or explicitly start a new walk. Never silently restart. |
| Missing ad | `not_found`; name get_ad and suggest searching for an existing id, not checking an irrelevant HTTP path. |
| REST rate denial | `rate_limited`, `retryable:true`, exact `details.retry_after_seconds`, corrected same call, wait instruction. |
| Unexpected/backend/adapter failure | `internal_error`, generic bounded message; no stack traces even in debug mode. Timeout advice notes possible prior billing. |
| Auth failure before dispatch | HTTP 401/403 using shared codes; actionable client configuration guidance, not a misleading successful tool result. |
| Malformed JSON, invalid request envelope, unknown method/tool, batch array | JSON-RPC protocol error, not fabricated successful tool execution. Unknown tool lists the three valid names. Put bounded recovery details in error data where possible. |

Missing/invalid tool arguments for a known valid `tools/call` envelope count as tool-execution errors; a non-object arguments envelope is a protocol structure error. Use the package's standard numeric JSON-RPC codes, not REST strings in the numeric code slot. Validation errors within a known tool remain visible tool results rather than relying on clients to expose protocol errors to models.

A corrected call preserves valid supplied intent. Supply executable `corrected_call` only when the correction is deterministic (a unique typo suggestion, for example). When intent is unknown, explain the required choice and provide a clearly labelled valid example, not a guessed “correction” that broadens results. Multiple errors remain explicit; do not present a still-invalid call as corrected. Avoid echoing cursor contents, credentials, headers, idempotency values, arbitrary unknown values, or oversized strings.

## Real Pi-Bolt evaluation [user]

Use an actual model through Pi-Bolt, not Inspector alone, a mocked model, or the implementer role-playing the answers. The user supplies/exports real before/after transcripts. Keep model usage to the assessment's few-dollar budget. No evaluation runs or live keys are authorized in this phase.

### Preregistered tasks and acceptable behavior

Commit the final task/expected-behavior record **before baseline runs** in the later authorized evaluation phase. Do not create `MCP_EVAL.md` now. Resolve the fixed detail ID and application date first, then freeze the record before observing results.

| # | Exact task | Preregistered acceptable behavior |
| --- | --- | --- |
| 1 | “Find active video ads in skincare that started in the last 30 days.” | Optional initial list_niches; first search has niche `["skincare"]`, format `video`, status `active`, started_after = application date D minus 30 days, sort `newest`, limit 5 (defaults may be omitted). Correctly treats the date as exclusive. |
| 2 | “Which pet supplies brand gets the most impressions?” | Explain that brand totals cannot be determined: only within-brand ranks exist. No tool call is required; niche discovery is acceptable. No purported winning brand or unsupported cross-brand rank comparison. |
| 3 | “Which niche slugs should I use for pet products and kitchen products?” | list_niches with `{}`, identify pet-supplies and kitchen. No invented slugs or unnecessary ad query. |
| 4 | “Show the complete copy and running dates for ad [fixed ID].” | Replace placeholder before preregistration with one existing deterministic long-copy ad id. First get_ad uses that exact id; subsequent calls use body_next_offset until complete. Explain active/inactive dates without inventing an end date. |
| 5 | “Find the best skincare ads.” | Clarify what “best” means rather than assert a performance ranking. Fixed user follow-up in both runs: “Newest active ads.” Then search niche skincare, status active, sort newest, limit 5, no invented format or keyword. |

For task 1, disclose D and the app timezone to the model as neutral evaluation context. Example only: if D is 2026-10-25, the exclusive lower bound is 2026-09-25. No hard-coded “today” in tool descriptions. Defaults omitted from calls are valid if semantically equivalent.

The detail fixture has more than 1,000 body characters. Choose/prepare it through approved existing seed/factory workflows before baseline, with explicit data; do not fabricate a successful get_ad call or silently change it between runs. Pin a dataset snapshot and stop ingest during evaluation; reset to the same state if necessary. Do not change source data or clock through undocumented production hooks.

### Method and scoring

- Fresh session per task and per before/after attempt; same model/version, settings, tool exposure and neutral instructions. Use only the three target MCP tools for data access so repository files or a database tool cannot answer the task instead.
- Use direct exposure for the three tools if supported by the installed Pi-Bolt adapter; record actual exposure and negotiated protocol revision. A namespaced client display name still refers to the exact server tool name.
- Capture raw user messages, clarification, tool names/arguments, response text/errors, assistant answer, timestamps, model/client versions and safe server commit identifiers. Redact credentials only; mark redactions.
- Score first-try tool selection, first data-call arguments, absence of correction/retry, and honest final interpretation separately. Optional niche discovery, default omission, legitimate pagination, and the preregistered clarification are not errors/retries. Correct restraint on task 2 and initial task 5 is a success, not a missing-call failure.
- Baseline means the first functional implementation before evaluation-driven description/error refinements, not v0 and not an intentionally misleading tool set. Its descriptions remain honest. Freeze baseline evidence before changing anything.
- After a genuine misuse, retain the complete transcript; change the relevant description or actionable error, identify that change/commit, then rerun all five tasks unchanged with the same model/settings/data in fresh sessions. Preserve before/after evidence, including failures.
- If the five tasks produce no misuse, report that honestly and perform additional labelled exploratory real-client tasks to look for a genuine issue. Never manufacture a misuse or call instructed invalid calls model-discovered misuse. If none occurs, the required misuse/improvement evidence remains unfulfilled; ask the user how to proceed instead of claiming completion.
- The later `MCP_EVAL.md` contains the frozen tasks, expected calls, setup/versions, before/after score table, one full real misuse transcript, the exact description/error change, the after transcript, limitations, and any unmet requirement. Do not invent numbers or transcripts now.

Pi-Bolt documentation supports Streamable HTTP and `headers` with environment-variable substitution. The later README/config instructions reference an environment-supplied Bearer credential, never a literal secret or a static universal idempotency key. Resolve an actual local URL with Boost's `get-absolute-url` before giving the user connection instructions. Do not install/configure an MCP server, issue a key, or edit user-level Pi settings during Phase 1.

## Verification requirements for the later plan

Use the project's Laravel/testing skills and TDD. No test results are claimed here. Tests must prove behavior rather than just repeat schema/description text.

1. Authenticating through the real MCP HTTP route: valid, missing, invalid, revoked-next-call, denied scope, query credentials, and malformed inputs; no auth caching between calls.
2. Exact HTTP mapping for every tool, all filter combinations that exercise encoding, multiple niche values, cursor passthrough, default/maximum limit, and header forwarding. Fake exact outbound endpoints and prevent stray requests for isolated adapter tests; assert the outgoing request rather than a made-up v1 response alone.
3. New REST detail/niches feature tests against real Postgres data; exact resource fields, derived days, empty/long/Unicode bodies, invalid id/offset/limit/unknown parameters, complete body reconstruction, and truthful niche vocabulary.
4. Search parity with REST: normalized applied_filters, both cursor walks under ingest, no skipped rows from preview budgeting, brand-relative warning, no implicit active filter, and suggestions that preserve intent. Do not duplicate v1's internal search algorithm in tests.
5. Byte budgets at actual serializer boundaries, including fully serialized MCP envelopes and adversarial valid strings. Prove that bypassing truncation/caps fails the test; verify get_ad recovers original preview text and complete body.
6. Error recovery through the real route: `isError`, exact shared code/retryability, meaningful corrected call, scope/auth separation, rate-limit wait in the model-visible body, generic debug-safe internal errors, and protocol errors for malformed/batch/unknown-tool requests.
7. Shared accounting integration: mixed direct v0/v1 and MCP requests use one key/window and the same usage ledger; one REST dispatch is one debit/billing decision, not two. Include discovery and local-error debits, free notifications, unbilled failures, successful empty reads, all three billable tools, and detail continuations. Existing v0 output remains frozen, including previously sanctioned Part 3 behavior.
8. Genuine overlapping requests with and without a stable idempotency header, across REST and MCP, including a lost-response/retry case and the 24-hour boundary. Assert exact persisted billing, not a mocked counter. If the shared meter already owns exhaustive races, reuse those tests and add the MCP forwarding/integration proof.
9. Actual HTTP integration for loopback, protocol compatibility, transport status translation and concurrency. Use isolated test listeners and a dedicated Postgres database with committed fixtures visible to worker processes, explicit startup/readiness/cleanup, and no production data. Transaction-wrapped in-process fixtures are not visible to separate workers. HTTP fakes cannot prove this path.
10. Mutation evidence: one deliberate fault at a time for ignored filter, incorrect argument mapping, skipped row, oversized output, error code/wait loss, double rate debit, double billing, and dropped idempotency header. Revert the exact edit without destructive git operations. Never mutate the frozen v0 test.

Read sibling tests before choosing names/helpers. Use factory records with explicit values, isolated time per test, no reliance on seeded data or order among equal sort keys. Separate deterministic unit/feature tests from genuine transport/concurrency integration. The plan must choose exact test paths and verify available commands; there is no Makefile in the inspected branch, despite the original prompt's assumption.

Known verification entry points from the v1 design are `php artisan test --compact <path-or-filter>`, `vendor/bin/pint --dirty --format agent`, and `vendor/bin/phpstan analyse --memory-limit=1G`. Recheck installed options/config in the implementation worktree. The user runs/authorizes the complete suite (`composer test` in the inspected design); do not run `make check` by assumption.

For every later shared-code task: run the v0 guard before and after, preserve `tests/Feature/Http/Controllers/V0/AdControllerTest.php` unchanged, and also run affected v1 tests. No “SAFE” report without fresh passing contract evidence. Phase 1 modifies documentation only and does not run application tests.

## Approval and handoff

The approved choices above do not approve the as-yet-unreviewed written details. Review especially the REST-success billing commit point, local/control rate debits, header-only retry limitation, extra REST reads, paged detail, and byte-budget/error mechanics.

After explicit approval of this document, the next separately authorized phase is writing the implementation plan, then waiting for plan approval. No worktree/branch switch, implementation or package installation now. The eventual isolated `part-2-mcp` worktree must start from the agreed completed v1/shared-foundation base, not current main by default.

Builder-owned details: class names, generator invocations/custom namespaces, exact HTTP client APIs after documentation verification, serializer helper implementation, configured origin/base-URL names, test file grouping, and process harness mechanics within the stated contracts. Do not leave shared accounting semantics or an unsupported protocol handshake to guesswork.

## Sources consulted

- Local assessment, `AGENTS.md`, Part 0/1 designs/plans, actual route/model/enum/error code, and `.agents/skills/v0-contract-guard` contract reference.
- Laravel Boost's version-aware MCP documentation search: custom HTTP authentication, tool schemas/validation, and response behavior; [Laravel MCP documentation](https://laravel.com/docs/13.x/mcp).
- Installed `vendor/laravel/mcp`: `Server`, `Registrar`, `ToolInvoker`, `CallTool`, `HttpTransport`, `ProtocolVersion`, response/error conversion. Verify again against the runtime dependency actually selected.
- [MCP tools, 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28/server/tools) and [Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http); also checked 2025-06-18 behavior for older handshake compatibility. Do not impose the newest revision on clients without negotiation.
- Installed Pi-Bolt `docs/mcp.md`: HTTP headers, tool exposure, result handling, and retry cautions. The document mentions both transport retries and restrictions on tool retries; confirm actual observed behavior rather than claiming exactly-once transport delivery.
