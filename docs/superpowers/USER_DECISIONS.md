## Which skeleton should Part 0 restore?

- [x] Restore only what boot, migrations, seeding and PHP checks need

## Should the starter ExampleTest be kept in Part 0?

- [x] Delete tests/Feature/ExampleTest.php in the skeleton commit

## How should Part 0 handle dates in the seed?

- [x] All seed dates are relative to today, with same-day seed determinism

## How should v0 handle invalid or edge-case inputs?

- [x] Keep the frozen v0 edge-case rulings from the Part 0 table

## Which approach should v0 use for search?

- [ ] Dedicated v0 slice with its own controller, DTO, Action and Resource
- [x] Shared SearchAds Action, with the v0 HTTP layer kept v0-specific
- [ ] Eloquent scopes called from the controller

## How should v1 share search code with v0?

- [ ] Flags on SearchAds::handle()
- [ ] Fully separate v1 search
- [x] Shared predicates for q, niche, format, status and started_after

## How should the impressions sort order results?

- [x] Rank, then id

## How should cursor pagination handle ads inserted during a walk?

- [x] Snapshot by id, using a signed cursor with a cutoff id

## How should the v1 OpenAPI spec be produced?

- [x] Generated with Scramble

## Which authentication methods should v1 accept?

- [x] Bearer header only
- [ ] Query-string api_key also accepted on v1

## Should v1 return a total count?

- [x] Omit total; return has_more and next_cursor
- [ ] Return total count (rejected: a full scan on every request)

## How should v1 accept multiple niches?

- [x] Repeated niche parameters only
- [ ] Comma-separated niche values

## Which transport should the MCP server use?

- [x] Laravel MCP over Streamable HTTP
- [ ] stdio transport

## How should MCP tools reach the data?

- [ ] Direct application-layer reuse (Recommended)
- [x] HTTP calls into the v1 REST endpoints
- [ ] Hybrid HTTP/direct adapter

## Should v1 add detail and niches reads?

- [x] Add GET /api/v1/ads/{id} and GET /api/v1/niches

## What response format should MCP tool results use?

- [ ] Structured content plus text
- [x] Compact JSON text, with no mirrored structuredContent

## How should MCP rate debits be applied?

- [x] One rate debit per handled operation; data reads debited once by REST, control and discovery requests debited by MCP
- [ ] Data tools debited at both MCP and REST (rejected: double debit)

## How should MCP report a REST rate-limit denial?

- [x] isError:true tool result with rate_limited code and retry_after_seconds, over HTTP 200
- [ ] HTTP 429 response from MCP (not chosen)

## Which reads are billable?

- [x] All successful search, detail and niches reads, including empty results

## How should MCP idempotency keys be supplied?

- [ ] idempotency_key tool argument or _meta fallback
- [x] Header-only Idempotency-Key, forwarded unchanged

## How should the MCP tool behaviour be evaluated?

- [x] Real model through Pi-Bolt, with user-supplied transcripts
- [ ] Mocked model or Inspector alone

## Which value types should API key scopes use?

- [x] JSON column validated against a Scope enum (search:read, keys:manage)

## Which store should back the API rate limiter?

- [ ] Database store (Recommended)
- [x] Redis store, failing closed when Redis is unreachable

## What should a v0 rate-limit denial return?

- [ ] 429 with Retry-After, as the assessment states
- [x] 400 {"error":"Invalid request"} with Retry-After

## Which table should record API usage?

- [x] One api_usages table feeds billing, quota and the developer page

## Should API keys have a monthly quota?

- [x] Include a 1,000 billed-calls monthly quota

## How should the monthly quota be scoped?

- [x] Each key has its own 1,000 billed calls per UTC month
- [ ] Per owner or account

## Which frontend base should the developer page use?

- [x] Scaffold the Inertia, React and Tailwind entry files in Part 4

## Who may access the developer page?

- [x] Local environment only, with no login; one local user owns every key
- [ ] Login-based access with multiple users (out of scope for this part)

## Which AI tool should the connect panel target?

- [ ] Claude Desktop (Recommended)
- [x] Pi-Bolt

## When should the real key appear in the connect panel?

- [x] Inline in the connect config, only right after creation
- [ ] Shown on every visit (rejected: the secret is never stored)

## How should the connect panel status persist across reloads?

- [x] Use the newest active key named Pi-Bolt

## What should mark Pi-Bolt as connected?

- [x] The key's first authenticated MCP request, including initialize
- [ ] A billed search call (explicitly not the definition)

## Who writes DESIGN.md and AI_NOTES.md?

- [x] The user, not the agent
- [ ] The agent (rejected: AI_NOTES.md forbids agent edits)

## Should laravel/mcp be added as a direct runtime dependency?

- [x] Yes, approved in brainstorming; resolve the version with Composer
- [ ] Keep transitive through laravel/boost (current state, not a direct requirement)

## Should a JS test runner be added for the developer page?

- [x] No; none is added (approach A)
- [ ] Add Pest browser tests with Playwright (approach B, rejected: new dev dependency and test setup)

## What must MCP errors tell a literal reader?

- [x] What was wrong, the corrected call, and whether retrying will help

## What response size budgets should MCP tool results meet?

- [x] Serialized search item at most 1,024 bytes; search metadata at most 4 KiB; search and detail JSON text at most 14 KiB; niches JSON text at most 2 KiB; complete JSON-RPC tool response at most 32 KiB
- [ ] Estimate size from character counts or tokens (rejected: bytes are measured after serialization)
