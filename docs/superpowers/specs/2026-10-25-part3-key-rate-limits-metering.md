# Part 3 design: keys, rate limits, metering

Date: 2026-10-25. Status: **draft, awaiting the user's approval**. Spec: `ASSESSMENT.md` Part 3 (quoted where it binds). Guard: `.agents/skills/v0-contract-guard/`.

**Base.** The inspected snapshot (an uploaded zip, no `.git`) holds Part 0 code only, plus the Part 1 design and plan and the Part 2 design. **[user]** This design is written against those specs: Part 1's `ApiErrorCode` catalogue, renderer and `AuthenticateV1ApiKey`, and Part 2's MCP decisions. **Execution gate:** the plan and the build start from the real repository with Part 1 present (branches `part-0-v0` and `part-1-v1`, and `part-2-mcp` if it exists). Before Task 1, re-read the actual `ApiErrorCode`, the renderer, the v1 middleware and the routes. Where the code differs from the Part 1 spec, follow the code and record the difference in the plan.

Decision labels: **[user]** = chosen by the user in this brainstorm. **[Part 2]** = already decided in `2026-10-25-part2-mcp-design.md`, which the user confirmed as settled. **[my call]** = my default, marked so it can be vetoed at this review.

## Intent and why

> 5. Nobody can say exactly how many billable calls a key made, and agents that retry after a timeout are charged twice.

Part 3 makes one key store, one limiter and one meter that v0, v1 and MCP all share. The aim is that "how many billable calls did this key make" has one exact answer, and that a retry carrying the same `Idempotency-Key` is never charged twice. v0 callers must not notice, apart from the changes Part 3 itself demands (a 429, and revocation).

"Security and metering" is 15% of the grade: "Key handling, scopes, revocation, exact counting under concurrency and retries."

## Goals (from the spec)

> - **MUST** API keys stored hashed and shown exactly once. Each key has a visible prefix so a user can tell keys apart, one or more scopes (at least `search:read` and `keys:manage`), and can be revoked. A revoked key fails on its very next call, on REST and MCP alike.
> - **MUST** A per-key rate limit (use **60 requests per minute**) shared across v0, v1 and MCP, returning 429 with an accurate `Retry-After`.
> - **MUST** Define what counts as a billable call, write the rule down, and count it exactly. Requests sent with the same `Idempotency-Key` within 24 hours are billed once. Decide, and explain, how an MCP client's retries are handled. Include a test that fires concurrent requests and proves the count is exact.
> - **SHOULD** A monthly plan quota (use **1,000 billable calls**). Going over it returns a distinct, non-retryable error that says when the quota resets.

Also binding: Part 1, "One error shape everywhere, including 401, 404, 422, 429 and 500 … The MCP server reuses the same codes". Part 2, "The MCP server shares auth, rate limits and metering with REST". Part 4, "Usage must come from the same meter that bills; do not keep a second counter."

## Non-goals

The developer page and the "Connect your AI tool" panel (Part 4). The MCP server itself (Part 2). OAuth, login and multi
