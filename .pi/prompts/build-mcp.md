---
description: Build ASSESSMENT.md Part 2 (MCP server with search_ads, get_ad, list_niches, agent-friendly errors, real-client evaluation) with the superpowers workflow
argument-hint: "[extra notes]"
thinking: high
---

Build **Part 2 of `ASSESSMENT.md`**: the MCP server on top of v1, without changing anything v0 or v1 callers observe. Parts 0 and 1 are done; their decisions are recorded in the docs below.

Extra notes from the user (may be empty): $@

Use the superpowers skills (https://github.com/obra/superpowers, installed under `.agents/skills`) for the workflow and this project's skills for the Laravel details. Read each `SKILL.md` before you act on it, and announce which skill you are using at each phase.

## Ground truth

Read these first, completely:

1. `ASSESSMENT.md`: "The scenario", "Part 2", the Part 3 bullets that MCP must share (auth, 60/min rate limit, metering, MCP retries), and "How we assess" (Agent experience is 25%). Quote the spec; don't paraphrase from memory.
2. `docs/superpowers/specs/*` and `docs/superpowers/plans/*` for Part 0 and Part 1. They record the shared `app/Actions/SearchAds`, `Ad::daysActive()`, the v1 error catalogue (`code`, `message`, `next_step`, `retryable`), cursor design, `applied_filters`, and the auth/throttle/metering middleware. Check the code on the current branch against them (`git log`, `git status`). If a part lives on another branch, or metering (`ThrottleApiKey` and `api_usages`) is uncommitted or untested, tell me before you start.
3. `AGENTS.md`: code structure, TDD, "no speculative behaviour". No new top-level folders. Subfolders are fine.
4. `.agents/skills/v0-contract-guard/SKILL.md` and its `references/v0-contract.md`. MCP shares keys, rate limits and metering with v0, so the guard applies.
5. `.ai/rules/index.md`, if it exists, and every rule file it maps to the paths you will touch.
6. `.agents/skills/mcp-builder/SKILL.md` for tool and response design. Verify the installed `laravel/mcp` package (or the alternative chosen) with `composer show` and Boost `search-docs`, not from memory.

Out of scope: the developer page and the "Connect your AI tool" panel (Part 4), the monthly quota (Part 3 SHOULD), new key features. Don't build them. Do reuse the key, scope, throttle and metering code that exists; MCP must not get a second counter or a second auth path.

## Requirements (from the spec)

MUST:
- `search_ads`, `get_ad`, `list_niches`, built on v1 (stdio or Streamable HTTP). The server shares auth, rate limits and metering with REST.
- **Tool design:** names, descriptions and input schemas that a model uses correctly on the first try. Each tool says what it is for, when not to use it, the valid values and where to get them (niche slugs come from `list_niches`). Be honest about what the data can and cannot answer (for example, `impression_rank` only compares ads within one brand, so "which brand gets the most impressions" has limits; say so).
- **Response design:** compact output sized for a context window, with a clear way to get more (a cursor, or `get_ad` for full detail). State the size budget chosen per result and how it is kept (field selection, truncated text, a hard per-page cap), and test it.
- **Errors for a literal reader:** what was wrong, the corrected call, and whether retrying will help. A rate-limit error says how long to wait. Reuse the v1 error codes and `next_step` content; adapt the wording so it names MCP tool arguments, not HTTP query parameters.
- **Evaluate with a real model through a real MCP client** I already use (ask me which, see Phase 5). Run 5 tasks and record whether the model picked the right tool and arguments on the first try. Two are fixed:
  1. "Find active video ads in skincare that started in the last 30 days."
  2. "Which pet supplies brand gets the most impressions?"
  You propose the other three, and at least one is ambiguous.
- `MCP_EVAL.md`: the 5 tasks with results before and after the changes, plus one full transcript where the model misused a tool, the description or error change made, and the transcript after.

COULD: a script that replays the task set automatically.

## Phase 1: Brainstorm (superpowers `brainstorming`)

Don't brainstorm what the spec decides. Spend the questions on the gaps, one at a time, with a recommended option first:

- **Package and transport:** `laravel/mcp` versus another route; Streamable HTTP versus stdio. Which fits "shares auth, rate limits and metering" with the least duplication (HTTP goes through the same middleware; stdio has no request, so how does a key get in)? Adding a package is a dependency change and needs my sign-off.
- **Reuse of v1:** do tools call the v1 Actions directly, or go through the v1 controllers/resources? What must stay single-sourced (validation, error codes, `applied_filters`, cursor)?
- **Auth, throttle and metering on MCP:** how the key is passed (Bearer), which scope MCP needs (`search:read`), where throttling and metering hook in per JSON-RPC call versus per HTTP request (a batch or `tools/list` should not be billed as a search), and what counts as billable on MCP. Decide and write down how an MCP client's retries are handled with respect to `Idempotency-Key` (the spec asks for this in Part 3; MCP clients have no header, so what is the key?). If this already exists from the metering work, reuse it and confirm.
- **Tool schemas:** the exact arguments for `search_ads` (mirror v1 filters, `niche` as an array or a single slug, enums for `format`, `status`, `sort`, `started_after` as ISO date, `cursor`, `limit`) and how each description tells the model the valid values and where to get them. Decide the limit default and max.
- **Response size budget:** a concrete number per result (for example, fields kept, ad text truncated to N characters, and `limit` max), the format (JSON text versus a compact text table versus structured content), and how the next page and `get_ad` are signposted.
- **"Honest about the data":** what `search_ads` and `list_niches` descriptions say about impressions, rank comparability, active versus inactive, and date semantics (`days_active`).
- **Errors:** protocol error versus tool result with `isError: true`. A model only reads the latter, so choose and justify. Define the error text template (what was wrong, corrected call, retry advice, `Retry-After` seconds for 429) and the mapping from the v1 catalogue.
- **Unknown or invalid arguments:** how "did you mean" from v1 surfaces in MCP, and what happens when the model passes a bad niche slug.
- **Evaluation method:** which client, how transcripts are captured, how "right first try" is scored (right tool, right arguments, no retries), and how the replay script (COULD) would work. Write the task list and expected tool calls **before** running anything, so the "before" result is honest.

Write the design doc to `docs/superpowers/specs/YYYY-MM-DD-part2-mcp-design.md`. Respect the brainstorming hard gate: **nothing is built until I approve the written doc.**

## Phase 2: Plan (superpowers `writing-plans`)

Save the plan to `docs/superpowers/plans/YYYY-MM-DD-part2-mcp.md`. Requirements:

- Bite-sized tasks, each with a failing Pest test first, using factories with explicit values rather than seeded data. Tests must not depend on row order among equal sort keys.
- Early tasks: the MCP endpoint with auth (valid key, missing key, revoked key, wrong scope), then `list_niches`, `get_ad`, `search_ads`, the response size budget, error mapping, then throttle and metering sharing, and tool-description assertions.
- Include a test that **rate limit and metering are shared**: calls through MCP and REST with one key draw down one counter and write to the same `api_usages` meter.
- Include tests for the size budget (a worst-case ad stays under the stated size), the `isError` shape, and a 429 that names the wait time.
- Include a **v0 guard run** as the first and last step of every task that touches shared code (middleware, `bootstrap/app.php`, Actions, resources).
- Use `php artisan make:* --no-interaction` for every new file, and Boost `search-docs` before relying on Laravel 13, Pest 5 or MCP package APIs.
- Every task names its exact verification commands (below).

Show me the plan and wait for approval.

## Phase 3: Execute (superpowers `subagent-driven-development`, with `test-driven-development` per task)

- Use `using-git-worktrees`: work on a feature branch, `part-2-mcp`, in an isolated worktree.
- **Commits are explicitly authorized here.** Make one small commit per green task, with short imperative messages and no squashing. Do **not** push, merge or rebase without asking.
- Give each implementer subagent the relevant spec excerpt and the paths of the skills it must read: `.pi/skills/laravel-best-practices`, `.pi/skills/testing-best-practices`, `.agents/skills/mcp-builder`, and the v0 guard. Subagents don't inherit your context. Parallelise only truly independent tasks.
- RED must fail for the right reason. If a test passes on its first run, it isn't testing anything yet.
- After each task, run the per-task review (spec compliance against `ASSESSMENT.md` Part 2 first, then code quality). Handle feedback with `receiving-code-review`. Use `systematic-debugging` for any failure.
- **Never edit the v0 contract test.** If it fails, v0 broke. Fix the cause.

Verification commands (check the repo's Makefile first; `make test`, `make format`, `make check` exist):

```bash
make test tests/Feature/Mcp            # the narrowest test first
make format
make check                             # only when I say so
```

## Phase 4: Prove the tests bite

For each tool, the size budget, the error mapping, the shared throttle and the shared meter: break it by hand (one change at a time), watch its test fail, and revert that exact edit. Never use git to revert a mutation. Record the results as raw material for `AI_NOTES.md`.

## Phase 5: Evaluate with a real client (needs me)

1. Ask me which MCP client to use and give me the exact config to paste (server URL or command, Bearer key). Create a key through the existing key code, and never print the secret anywhere but to me, once.
2. Commit the 5-task list and expected calls first. Then I run the tasks in the client and paste or export the transcripts. Don't simulate a model and call it a result.
3. Write `MCP_EVAL.md`: tasks, first-try results **before**, the one full transcript of a misuse, the description or error change you made, then the results and transcript **after**. Report honestly if nothing needed to change or if something still fails.
4. COULD: a replay script, only if time allows and I ask.

## Phase 6: Finish

1. Run the v0 guard in full and end with its `## v0 guard:` report.
2. Use `verification-before-completion`. Show fresh command output for the MCP tests, the v1 tests and the v0 tests, plus a sample of each tool's response and of each error kind (invalid argument, not found, unauthorized or wrong scope, rate limited).
3. Use `requesting-code-review` for a whole-branch review. Then use `finishing-a-development-branch`, presenting the options and letting me choose. Ask me to run the full suite.

Add to `README.md` how to connect an MCP client, the size budget, and every assumption you made. Keep a short list of AI mistakes caught and how, for `AI_NOTES.md`; don't write that file unless I ask.

Stop and ask if the spec is ambiguous in a way the design doc didn't settle. Don't pick silently.
