# Part 0 design: data model, seed, live ingest, v0 endpoint

Date: 2026-10-25. Status: **approved by the user**. Spec: `ASSESSMENT.md` §0.1–0.4 (quoted below where it binds). Guard: `.agents/skills/v0-contract-guard/` (`references/v0-contract.md` is the v0 contract in checklist form).

## Intent and why

v0 stands in for "an integration that real customers already depend on, quirks included. **Do not fix it.**" Part 0 builds the world v0 reads from and v0 itself, pinned by a contract test that "must still pass when you submit". Everything later (v1, MCP, keys, limits, metering) is built *around* this without changing what v0 callers observe. The graders "will break a few things on purpose and see whether your tests notice", so the contract test has to bite, not just pass.

## Goals

1. `ads` data model per §0.1, with `days_active` derived, never stored, and implemented once so v0 and v1 agree.
2. A deterministic seed: ≥ 20,000 ads, ≥ 300 brands, all 12 niches, ~60% active, start dates spread over the last two years, ~40% null `impression_rank`.
3. A live ingest command that runs until stopped. Every second it inserts 1–5 ads (~30% with `start_date` up to 90 days in the past) and deactivates 1–2 active ads.
4. `GET /api/v0/ads`, exactly per §0.4, with `?api_key=` auth.
5. A v0 contract test that covers at least every item of the guard checklist (§6) plus every edge-case ruling below.
6. Restore the missing Laravel skeleton so `php artisan` and the one-command docker start work again.

## Non-goals (this part)

v1, MCP, scopes, revocation, `last_used_at`, rate limiting, metering, quotas, the developer page. The key model is the minimum v0 needs. Part 3 grows it.

## Anti-goals (failure even if it "works")

- Any v0 behaviour that is "nicer" than the spec: validation errors, a tiebreaker, cursor fields, `meta`/`links`, helpful error messages, Bearer-only auth.
- A contract test that depends on seeded data or on the order of ads that share a `start_date`.
- Ingest tests that really sleep, or that pass whatever the random numbers are.
- A seed whose output changes between two runs on the same day.

## Constraints

- Laravel 13.38, PHP 8.5, Pest 5.3, Postgres 18 (compose). Larastan at level 7 over `app/`, `config/`, `database/`, `routes/`, `bootstrap/app.php`.
- `AGENTS.md` code structure: Controllers coordinate, Requests validate/authorize, Middleware filters, Resources shape, Actions hold single-task business logic, DTOs carry structured data, Enums hold domain values. No new top-level folders. Subfolders such as `V0/` inside existing folders are fine.
- TDD, nothing speculative. Every new file goes through `php artisan make:* --no-interaction`.

## Approaches considered

| | Approach | Trade-off |
|---|---|---|
| A | A dedicated v0 slice: v0 controller, DTO, Action and Resource, sharing only enums, `Ad::daysActive()` and `ApiKey` | The most isolation from v1. The most files. The guard's default. |
| **B (picked)** | **One shared `app/Actions/SearchAds`, called by v0 now and extended for v1 later. The v0 HTTP layer (controller, input parsing, resource, auth middleware, error rendering) stays v0-specific.** | Less code, and one search path. The risk: every later change to `SearchAds` reaches v0. |
| C | Eloquent scopes called from the controller | The smallest amount of code. Bypasses Actions and DTOs, and invites v1 to reuse v0's scopes. |

**Decision: B, the user's call.** Mitigation: the v0 contract test is the primary defence, and the guard's Step 1 row "Shared search query or Action" applies to every v1 change to `SearchAds`. For Part 0, `SearchAds` gets **no** v1 options (no strictness flags, no tiebreaker switch). v1 adds what it needs later, behind the contract test.

## Decisions (made with the user)

### Skeleton (minimal boot, user's call)
Restore only what booting, migrating, seeding and the PHP checks need, from commit `950121b`, as the first commit on the branch:
- `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Controller.php`, `app/Http/Middleware/HandleInertiaRequests.php` (`bootstrap/app.php` references it);
- the three `0001_01_01_*` migrations, `database/seeders/DatabaseSeeder.php`, `docker/entrypoint.sh`.

The front end (`resources/`), the `Makefile` and `950121b`'s tests are **not** restored. `main`'s stock `welcome` route stays as it is.

*My call (veto at approval):* also take `950121b`'s `config/app.php` change that removes the auth providers, and delete the stock `config/auth.php`. It references a `App\Models\User` that doesn't exist, which fails Larastan (which scans `config/`). Add no `User` model.

*User decision:* delete the starter's `tests/Feature/ExampleTest.php` (`GET /` returns 200) in the skeleton commit. It can't pass without front-end files, which are out of scope, and it's a starter placeholder. Deletion approved by the user. Part 4 adds real page tests.

### Data model

`ads` table:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint auto-increment | JSON **integer** everywhere (`"id": 18342`) |
| `brand_id` | unsigned int | **Denormalised. No brands table.** The same `brand_id` always carries the same `brand_name`. |
| `brand_name` | string | |
| `headline` | string | |
| `body_text` | text | |
| `niche` | string (slug) | Cast to `App\Enums\Niche` |
| `format` | string | Cast to `App\Enums\AdFormat`: `image`, `video`, `carousel` |
| `status` | string | Cast to `App\Enums\AdStatus`: `active`, `inactive` |
| `start_date` | date | The day the ad started running |
| `end_date` | date, nullable | Null **iff** active |
| `impression_rank` | unsigned int, nullable | ≥ 1, unique within a brand among non-null values, 1 = the most impressions. ~40% null |
| `countries` | json | Array cast, ISO-2 codes |
| `discovered_at` | timestamp | When ingest first saw the ad |
| `created_at`, `updated_at` | timestamps | |

Indexes: `start_date` (the v0 sort), and `(niche, start_date)`, `(status, start_date)`, `(format, start_date)` for the v0 filters. Text search uses `ilike` with no index; the scale concern goes to `DESIGN.md` later.

`App\Enums\Niche` is a string-backed enum with the 12 slugs from the §0.1 table as values. It provides `displayName(): string` (exact names from the table, e.g. `Baby & Kids`) and `fromSlugOrName(string $value): ?self`, which matches case-insensitively (`mb_strtolower`) against either the slug or the display name. Matching happens in PHP; the database only stores slugs.

`days_active` is one method, `Ad::daysActive(): int`:
- active: whole days from `start_date` to today;
- inactive: whole days from `start_date` to `end_date`.

It uses the app timezone (UTC) and returns a non-negative int. Both v0 and v1 call this method. Nothing else computes it.

### API keys (minimum for v0)
- The `api_keys` table: `id`, `name`, `prefix` (string, the first 12 characters of the secret), `key_hash` (sha256 hex, unique), timestamps. Model `App\Models\ApiKey`.
- The secret format is `ghk_` followed by 40 random alphanumeric characters. sha256 is enough because secrets are long and random; lookup is `where key_hash = sha256(input)`. The plain-text secret is never stored.
- `php artisan api-key:create {name}` prints the secret once.
- The local dev key comes from `DatabaseSeeder`, **only when `APP_ENV=local`**. It creates a fixed, documented key (written in `README.md`) and is idempotent across reruns. It is never created in testing or production.

### Seed
- Running it twice **on the same day** gives identical data, ids included. All dates are relative to *today*; that was the user's choice. `README.md` states this assumption, and the determinism test freezes time.
- It uses a constant random seed (Faker plus PHP's `Random\Randomizer`/`Mt19937`, whichever the builder needs). It truncates `ads` with an identity reset (Postgres `truncate … restart identity`) before inserting, and inserts in chunks.
- `DatabaseSeeder` calls the ad seeder (and, locally, the dev key). **On start**, `docker/entrypoint.sh` seeds only when `ads` is empty, so a clean clone gets data and a restart keeps the ingested ads. `php artisan db:seed` resets to the deterministic data. The builder picks the mechanism for the emptiness check. Tests never run the full seeder except in the dedicated seed tests.
- Volumes: 20,000 ads, 300 brands. Every niche has ads. ~60% active. `start_date` is spread uniformly over the last 730 days. Inactive ads get `end_date` in `[start_date, today]`. ~40% of ads have null `impression_rank`. Within each brand, the non-null ranks are `1..k` with no gaps or duplicates.
- Headlines draw on per-niche templates, so the §0.1 keywords appear in some headlines of their niche: serum, glow, SPF; shampoo, curls; protein, gummies; leggings, dumbbell; collar, kibble; sofa, candle; blender, knife; hoodie, denim; necklace, ring; stroller, onesie; tent, backpack; charger, phone case.

### Live ingest
- `php artisan ads:ingest` loops every second until it receives SIGINT or SIGTERM (Laravel's `trap()`). It finishes the current tick and exits 0.
- `--ticks=N` (0 = forever, the default) and `--interval=SECONDS` (default 1) exist for tests and demos. Tests run with `--interval=0`; nothing sleeps for real in tests.
- Each tick is one Action (`app/Actions/IngestAdsTick` or similar), run in one transaction, with an injected random source so tests are exact:
  - Insert 1–5 ads for existing `brand_id`/`brand_name` pairs, with `discovered_at = now`. ~30% get `start_date = today - (1..90) days`; the rest get `start_date = today`.
  - *My call:* an ingested ad gets a null `impression_rank` with the same ~40% probability as the seed. Otherwise it gets its brand's current max rank + 1, which keeps ranks unique within a brand.
  - Deactivate 1–2 random active ads: `status=inactive`, `end_date=today`.
- It prints one line per tick, e.g. `+3 ads (1 backdated), -2 deactivated`.
- A compose service `ingest` runs it under an **opt-in profile**: `docker compose --profile ingest up`.

### Database for tests
Tests run on **Postgres**, not SQLite. `phpunit.xml` points at an `ads_testing` database on the compose `postgres` service, which a Postgres init script creates. Tests need `docker compose up -d postgres` first. *My call:* `phpunit.xml` sets only `DB_CONNECTION=pgsql` and `DB_DATABASE=ads_testing`, and the host comes from the environment. Tests therefore run on the host (`127.0.0.1`, from `.env`) or in the container (`postgres`, from compose). The init script only runs on a fresh volume, so the README gives a one-line `createdb` for an existing `pgdata` volume. The frozen contract is therefore proven on the engine customers hit, and `ilike` behaves the same in tests as in production.

### `q` matching
`q` is case-insensitive via `whereLike(col, value, caseSensitive: false)`, which compiles to `ilike` on Postgres, against `headline` OR `body_text`. `q` is a **literal** substring. `\`, `%` and `_` are backslash-escaped, Postgres's default `LIKE` escape, so `q=50%` matches only the literal `50%`.

### v0 endpoint
- The route is `GET /api/v0/ads` in a new `routes/api.php`, registered in `bootstrap/app.php`. The v0 group gets a v0-only key middleware, `App\Http\Middleware\AuthenticateV0ApiKey`. It reads the `api_key` query param, which must be a string; on a miss it returns 400. The matched `ApiKey` is made available on the request for Part 3.
- The v0 HTTP layer turns the query string into an `App\DTO\AdSearchFilters`: `?string $q`, `?Niche $niche`, `?AdFormat $format`, `?AdStatus $status`, `int $page`, `int $perPage`. Invalid values become null or the default. This parsing must never return 422. It is v0-specific; the DTO itself is shared.
- `App\Actions\SearchAds::handle(AdSearchFilters)` returns the page of ads plus `total`. The sort is `start_date desc` **with no tiebreaker**. Paging is offset `(page-1)*perPage`, and `total` comes from a count over the same filters.
- An `App\Http\Resources\V0\AdResource` whitelists exactly 8 keys: `id`, `brand` (= `brand_name`), `headline`, `niche` (the **display name**), `format`, `status`, `start_date` (`Y-m-d`), `days_active`. The controller builds the body `{"data": […], "page": <int>, "total": <int>}` itself, so no paginator `meta` or `links` appear.
- Errors: `bootstrap/app.php` registers a render callback **scoped to `api/v0` and `api/v0/*`** that returns `400 {"error":"Invalid request"}` for every exception there. Other paths are untouched.

### v0 edge cases, frozen (user-approved)

| Input | v0 behaviour |
|---|---|
| `page=0`, `-1`, `abc`, `1.5` | Invalid, so the default applies: `page: 1` in the response |
| a page past the last one | 200, `data: []`, `page` = the requested page, `total` = the real count |
| `per_page=0`, `-5`, `abc`, `1.5` | Invalid, so the default of 20 applies. `1..100` is honoured; `>100` is clamped to 100 |
| `q=`, or `q` of only whitespace | No filter. The global `TrimStrings` middleware trims `q`, so `" serum "` behaves like `"serum"`. Pinned. |
| `niche[]=…`, `format[]=…`, `status[]=…`, `q[]=…` | Not a string, so invalid, ignored, unfiltered |
| `format=Video`, `status=ACTIVE` | "Exact match" means case-sensitive, so invalid, ignored, **unfiltered** |
| `niche=PET SUPPLIES`, `pet-supplies`, `Pet Supplies` | All match (slug or display name, case-insensitive) |
| `sort=…`, or any unknown parameter | Ignored |
| a repeated parameter (`niche=a&niche=b`) | The last value wins (PHP behaviour) |
| ads sharing a `start_date` | Relative order unspecified; tests never assert it |
| a missing, unknown or array `api_key` | `400 {"error":"Invalid request"}` |
| any other failure under `api/v0/*` (wrong method, unknown sub-path, server exception) | `400 {"error":"Invalid request"}` |

## Testing

- The v0 contract test is written **red against this doc and §0.4, before the controller exists**. It covers every guard §6 item and every row of the edge-case table. It uses factories with explicit values and freezes time for `days_active`. Ordering tests use distinct `start_date`s.
- Unit-level tests cover `Niche::fromSlugOrName`, `Ad::daysActive` (active, inactive, started today), seed determinism (two runs on a frozen day give identical rows: compare counts plus a checksum) and seed distribution (counts within tolerance, keywords present, ranks `1..k` per brand), and the ingest tick (a seeded random source; exact insert, backdate and deactivation counts; invariants such as `end_date` null iff active).
- Before finishing, the guard's mutation check runs against the contract test.

## Left to the builder

File and test names (following `testing-best-practices`); factory states; the exact headline and body templates and brand-name generation; chunk size; how the random source is injected; the dev key's literal value; the ingest output wording; the Postgres init-script mechanics; whether v0 input parsing sits in a FormRequest or the controller (as long as it never fails validation).

## Assumptions to record in `README.md`

The same-day seed determinism; tests requiring Postgres; the dev key existing only in local; denormalised brands; `id` as a JSON integer; ingested ads for existing brands only; every edge-case ruling above; server errors under v0 returning 400 by literal reading of §0.4.
