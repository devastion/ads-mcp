# Ads API: Part 0

Part 0 of `ASSESSMENT.md`: the ads data model, a deterministic seed of 20,000 ads, a live ingest command, and the frozen `GET /api/v0/ads` endpoint, pinned by a contract test.

Design: `docs/superpowers/specs/2026-10-25-part0-v0-design.md`. Plan: `docs/superpowers/plans/2026-10-25-part0-v0.md`.

## Run everything

```bash
docker compose up
```

The `ads-api` service installs dependencies, runs migrations, seeds the 20,000 ads on the first start only (a restart keeps the ingested ads), and serves on `http://127.0.0.1:8000`.

> Verified: the commands below were run against this branch. A fresh-volume `docker compose up` has not been run end to end yet.

## Ingest

Ingest runs only when you start it, using an opt-in compose profile:

```bash
docker compose --profile ingest up ingest
```

Or from the host:

```bash
php artisan ads:ingest            # runs until Ctrl-C or SIGTERM
php artisan ads:ingest --ticks=5  # stops after 5 ticks
```

Each tick inserts 1–5 ads for existing brands (about 30% are backdated by 1–90 days) and deactivates 1–2 running ads. Stop it with Ctrl-C. The loop finishes the current tick, then exits.

## Tests

Tests run on Postgres, not SQLite, so the v0 contract is proven on the engine customers use.

```bash
docker compose up -d postgres
docker compose exec postgres createdb -U ads ads_testing   # once, for an existing pgdata volume
php artisan test --compact
composer test                                              # pint, phpstan, then tests
```

The v0 contract lives in `tests/Feature/Http/Controllers/V0/AdControllerTest.php`.

## API key

Create a key. The secret is printed once and only its hash is stored:

```bash
php artisan api-key:create "my key"
```

The local development key exists only when `APP_ENV=local`. It is a fixed, documented, insecure key, and must never be used outside local development:

```
ghk_LocalDevOnlyNotForProd000000000000000000
```

Call v0 with the key as a query parameter:

```bash
curl "http://127.0.0.1:8000/api/v0/ads?api_key=ghk_LocalDevOnlyNotForProd000000000000000000&niche=Pet%20Supplies&per_page=2"
```

## Assumptions

These are the decisions made for Part 0. Each one is also in the design doc.

- **Seed determinism is per day.** The seed uses a fixed random seed, and all dates are relative to today. Running it twice on the same day produces identical data, including ids. On a later day, the dates shift but the shape stays the same.
- **Tests need Postgres.** `phpunit.xml` targets the `ads_testing` database.
- **The dev key is local only.** It is created only when `APP_ENV=local`, and its literal value is committed on purpose so a reviewer can call v0 immediately.
- **Brands are denormalised.** There is no `brands` table. `brand_id` and `brand_name` are stored on each ad. Brand sizes vary from 37 to 97 ads (average 67). The rounding residual is spread across brands, so no single brand absorbs it.
- **`id` is a JSON integer** in both the database and the v0 response.
- **Ingested ads use the brand's niche**, and their `countries` is fixed to `["US"]`. The spec does not say how countries should be chosen for ingested ads.
- **Edge cases in v0 are frozen as follows:**
  - An invalid `page` or `per_page` falls back to its default (`page` 1, `per_page` 20). `per_page` above 100 is clamped to 100.
  - A page past the last one returns an empty `data` array with the real `total`.
  - An empty or whitespace `q` is ignored. Surrounding whitespace is trimmed.
  - Array-valued parameters (`niche[]`, `format[]`, `status[]`, `q[]`) are ignored.
  - `format` and `status` are case-sensitive, so `format=Video` is ignored.
  - `niche` matches the slug or display name in any case, and returns the display name.
  - `q` is a literal substring. `%` and `_` are escaped.
  - Unknown parameters are ignored. A repeated parameter uses its last value.
  - Every failure under `api/v0/*` returns `400 {"error":"Invalid request"}`. This includes server exceptions, because §0.4 says "used for every error". Other paths keep Laravel's default errors.
- **The v0 key check does not attach the key to the request.** Nothing reads it before Part 3.
- **`ads:ingest` has no `--interval` option.** Tests use `Sleep::fake()` instead.
- **Ingest picks deactivations with the database's random order**, so which ads get deactivated is not seeded. The tests check counts and invariants, not which ads are chosen.
- **Ads sharing a `start_date` have no defined order** in v0, and the spec forbids a tiebreaker. The contract test pins the emitted `ORDER BY start_date desc` directly, because output order for ties is not a stable thing to assert.
- **Ingested ads take `max(impression_rank) + 1` for their brand**, so new ads sit at the bottom of that brand's impression ranking.
- **Seeding is refused in production.** `AdSeeder` throws when `APP_ENV=production`, because it truncates the `ads` table.

## Part 1: v1 search

Design: `docs/superpowers/specs/2026-10-25-part1-v1-design.md`. Plan: `docs/superpowers/plans/2026-10-25-part1-v1.md`.

`GET /api/v1/ads` takes a Bearer key and returns one error shape on every failure. The OpenAPI spec is `docs/openapi/v1.json`, generated by Scramble.

### Run and test

```bash
# $API_KEY is your key from the API key section above (the local dev key or one from api-key:create)
curl -H "Authorization: Bearer $API_KEY" "http://127.0.0.1:8000/api/v1/ads?sort=impressions&per_page=5"

php artisan test --compact tests/Feature/Http/Controllers/V1   # v1 behaviour
php artisan test --compact tests/Feature/Documentation         # OpenAPI drift test
composer test                                                 # full gate: pint, phpstan, tests
```

To refresh the spec after changing the API: `php artisan scramble:export --path=docs/openapi/v1.json`. The drift test fails on a stale file.

### Walk guarantees

These apply to one walk: a chain of requests linked by `next_cursor`, with the same filters and sort.

1. **No repeats.** No ad appears twice in a walk.
2. **No skips of what existed.** An ad that existed when page 1 was served, matched then, and still matches when the walk reaches its place is returned. This holds while its `start_date`, `impression_rank` and `id` are unchanged. Ingest never changes them after insert, and a test checks that.
3. **Inserts are not shown.** Ads inserted after page 1 are excluded by `id <= c`, including backdated ones. One exception: an insert whose transaction was still open when page 1 was served can commit later with an `id <= c`, and then appears. Ids are assigned on insert, not on commit.
4. **Deactivations.** A deactivated ad still matches when the query has no `status` filter, so it still appears, with `status: inactive` in its item. With `status=active`, it no longer matches and drops out of later pages. Dropping out is allowed by (2).
5. **No promise about the order of equal sort keys** beyond the deterministic id tiebreak. Tests assert sets for ties.
6. **No total.** The walk ends when `has_more` is false.

### Limitation: impressions

`sort=impressions` orders by brand-relative rank, not absolute impressions. Rank 1 at a small brand is not comparable to rank 1 at a large one, so results interleave brands by rank, not by absolute impressions. Ads without impression data come last. Responses to this sort carry a `notes` entry that says so.

### Assumptions

- Cursors are signed with a key derived from `APP_KEY`. Rotating `APP_KEY` invalidates outstanding cursors.
- Cursors do not expire. A walk keeps its snapshot cutoff.
- Ads inserted during a walk are not returned by it. An insert in flight at page 1 can appear.
- `started_after` is exclusive. Future dates are valid and return no rows.
- `niche` takes slugs only, repeated. The v1 `niche` field is the slug, not the display name.
- `per_page` is validated, not clamped.
- Empty values, repeated scalars and array syntax are rejected.
- `sort=impressions` orders by brand-relative rank, with id ascending as the tiebreak.
- v1 returns no `total`.
- The OpenAPI spec covers v1 only and is generated by Scramble. The drift test fails on a stale file.
- Tests and the app run on Postgres 18.6. There is no sqlite test path.

## Keys, limits and billing

- **Scopes.** Every key has scopes: `search:read` (default, needed for every data read) and `keys:manage`. Issue with `php artisan api-key:create "name" --scope=search:read --scope=keys:manage`. Revoke with `php artisan api-key:revoke <prefix>`; a revoked key fails on its very next call on v0 and v1.
- **Rate limit.** 60 requests per minute per key, one bucket shared by v0, v1 and MCP. v1 answers 429 with `Retry-After` and `error.details.retry_after_seconds`. **v0 answers its usual `400 {"error":"Invalid request"}` plus a `Retry-After` header, not 429.** That is a deliberate trade-off to keep v0 clients working; the same 400 covers revoked keys, missing scope and quota. Every authenticated attempt spends one unit, including failed ones.
- **What is billable.** A call is billable when it is an authenticated, authorized, not-rate-limited read of `GET /api/v0/ads`, `GET /api/v1/ads`, `GET /api/v1/ads/{id}` or `GET /api/v1/niches` that returns 2xx, including an empty result. Validation errors, 404, 429, quota refusals and 5xx are never billable. Each MCP tool call is one billable read through the REST call it forwards; MCP discovery and local errors are free.
- **Idempotency.** Requests with the same `Idempotency-Key` header (1 to 255 printable ASCII characters, sent once) within 24 hours of the first billed one are recorded unbilled. The key is stored only as a sha256 hash and is not tied to endpoint or parameters; there is no response replay. Without the header every successful read is billed again, so an MCP client that retries without a stable per-call `Idempotency-Key` is charged twice.
- **Quota.** 1,000 billed calls per key per UTC month. Over quota, v1 answers 403 `quota_exceeded` (not retryable) with `details.resets_at`. The check runs before the read and billing happens after it, so concurrent in-flight requests can overshoot 1,000 by a few calls; the billed count itself is exact.
- **Exactness.** All counting comes from the `api_usages` table. Overlapping requests with the same `Idempotency-Key` serialize on a Postgres advisory lock, proven by `tests/Feature/Concurrency`.

## Developer page

Open [Developer tools](http://localhost:8000/developer) with `APP_ENV=local`. There is no login: the single local user owns every key. All developer routes return 404 outside the local environment, including when routes are cached. After pulling this change, apply the additive migration with `php artisan migrate --no-interaction`.

Create keys with a name and scopes, copy the secret, then click **I've saved it**. The secret is shown only in the creation response and transient page state; it cannot be recovered after dismissal, navigation, or reload. The table lists prefixes, scopes, creation time, last use, and revocation status. Revoking a key blocks its next REST or MCP call. Developer page requests are never billed.

Each key has its own quota of **1,000 billable calls per UTC month**. Usage is recounted from the same `api_usages` ledger used by billing on each page load; there is no separate counter. The [billing rule](#keys-limits-and-billing) applies to these counts. Dates display in your browser's local time with UTC tooltips; quota resets are shown in UTC and occur at 00:00 on the first day of the next month.

### Connect Pi-Bolt

1. Click **Create key for Pi-Bolt**. This creates a key named `Pi-Bolt` with only `search:read` scope.
2. Copy the filled configuration into your **user-level** `~/.pi/agent/mcp.json`, preserving any other servers already configured there. Never put the secret in this repository's `.pi/mcp.json`.
3. Restart Pi-Bolt and ask **Which niches can I search?**
4. The page polls while waiting and shows **Connected** when the key's first authenticated MCP request arrives, including `initialize`. A billable search is not required. The timestamp updates at most once per minute.

The configuration contains the real secret only immediately after creation. Later visits show `<YOUR_KEY>` instead. The newest active key named `Pi-Bolt` controls the status, and waiting resumes after reloading the page. Revoked keys are skipped. Troubleshooting tips appear after one minute of waiting.

The generated template has this shape; this committed example contains no credential:

```json
{
    "mcpServers": {
        "ads": {
            "url": "http://localhost:8000/api/mcp",
            "headers": {
                "Authorization": "Bearer <YOUR_KEY>"
            }
        }
    }
}
```

Run the page tests with `php artisan test --compact tests/Feature/Http/Controllers/Developer`. Manual checks remain: clipboard success and denial fallback, secret removal after dismissal/reload/navigation, connection with a real Pi-Bolt client, and a timed setup walkthrough. No under-five-minute setup claim has been verified.

### Connect Claude Code

1. Start the app and create a key with only `search:read` on the developer page. Copy the secret.
2. In Claude Code, run `/plugin marketplace add devastion/ads-mcp` (or `/plugin marketplace add ./` from a clone), then `/plugin install ads@ads`.
3. Paste the key when asked. Leave the endpoint at its default unless the app runs elsewhere (eval mode: `http://127.0.0.1:8000/api/mcp`).
4. Run `/mcp`, check that `ads` is connected with 3 tools, then ask **Which niches can I search?**

Notes:

- The developer panel's **Connected** status follows only the newest key named `Pi-Bolt`. For a Claude Code key, check its **Last used** column.
- Claude Code sends no `Idempotency-Key`, so a retried call is billed again.
- The Part 2 evaluation still runs in Pi-Bolt.
- To load the plugin from a clone without installing it, run `claude --plugin-dir plugins/ads`.

## Unfinished

Status by part, against `ASSESSMENT.md`:

| Part | Status | Still open |
| --- | --- | --- |
| 0. Data, seed, ingest, v0 | Done, frozen by the v0 contract test | Fresh-volume `docker compose up` not yet run end to end |
| 1. v1 search | Done, with the OpenAPI drift test | Index migration for `(start_date, id)` and `(impression_rank, id)` and the `EXPLAIN` check were not built, so large-table performance is unproven |
| 2. MCP server | Code is on `main` (`laravel/mcp`, the three tools, origin and envelope middleware, `routes/ai.php`). Its commit says the tests have not run as a suite | Run the MCP tests. Not built: the forwarded-call integration tests, the loopback run, and the `X-Ads-Origin` marker so MCP calls are metered as `mcp` (Part 2 open question Q-3). Evaluation (`MCP_EVAL.md`): not started |
| 3. Keys, limits, metering | Scopes, revocation, the shared limiter, metering, idempotency, quota, and the concurrency test are on `main` | The limiter still uses the default cache store, not Redis. The Redis service was removed from `compose.yaml` in `b76463f`, though the PHP extension is still in the Dockerfile. The fail-closed change is not built |
| 4. Developer page | Implemented: local-only key creation, secret-once copy, listing, revocation, ledger usage and Pi-Bolt connection panel | Manual clipboard, secret-lifetime, real-client and timed setup checks remain; see [Developer page](#developer-page) |
| Docs | `AI_NOTES.md` is drafted; `DESIGN.md` is a 5-line stub | `DESIGN.md` (trade-offs, v0/v1 coexistence, breaking-change plan, what breaks at ~110M ads / 5–6M active) is not written. `MCP_EVAL.md` does not exist |

### What I would do next

1. Build the v1 indexes and run `EXPLAIN` on both sort orders at production scale. Without them, the first two parts of the design that break at 110 million ads are the `start_date` and `impression_rank` sorts with a cursor.
2. Finish the MCP evaluation: run the frozen tasks in `docs/superpowers/eval/2026-10-25-mcp-frozen-tasks.md` through a real client and write `MCP_EVAL.md`.
3. Confirm the Redis-backed, fail-closed rate limiter, then add a test that proves a Redis outage returns an error rather than an unlimited pass.
4. Finish the developer page panels and write `DESIGN.md`.
5. Run a clean-clone check of `docker compose up` and the test commands above, then update this section.
