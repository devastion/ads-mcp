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

## Unfinished

- Parts 1–4: v1 search, MCP server, scopes, revocation, rate limits, metering and quotas, the developer page, `DESIGN.md`, `MCP_EVAL.md`, `AI_NOTES.md`.
- An end-to-end run of `docker compose up` on a fresh volume, and of the `ingest` profile under compose.
