# Part 1 design: v1 search API

Date: 2026-10-25. Status: **draft, awaiting the user's approval**. Spec: `ASSESSMENT.md` Part 1 (quoted where it binds). Base: `part-0-v0` at `7ffe49a` (the Part 0 history; `main` differs only in docs). Guard: `.agents/skills/v0-contract-guard/`.

Decision labels: **[user]** = chosen by the user in brainstorming. **[my call]** = my default, marked so it can be vetoed at this review.

## Intent and why

v0 is frozen. Customers depend on its quirks, and it is the cause of the five complaints in the scenario: unknown filters return 200, errors say `Invalid request`, paging while ingest runs repeats and skips ads, null impression ranks sort to the top, and retries are billed twice (billing is Part 3). Part 1 builds `GET /api/v1/ads` so that an agent gets a correct, explainable answer on the first call, while v0 stays byte-for-byte the same for every caller who has one.

## Goals (MUST, from the spec)

1. Keyword `q`, and filters `niche` (one or more slugs), `format`, `status`, `started_after` (a date).
2. Sorts `newest` (`start_date`) and `impressions`. Null `impression_rank` always last. Ties broken deterministically.
3. Unknown parameters and invalid values are rejected. The error says what the allowed values are and, where possible, what the caller probably meant (`nich` → `niche`, `Skincare` → `skincare`).
4. Every success echoes the filters actually applied, after normalisation.
5. Cursor pagination for both sorts that stays correct while ingest runs. A walk never repeats an ad and never skips one that existed at page 1 and still matches. The guarantees are written down (see "Walk guarantees") and proved by a test that mutates data between pages.
6. One error shape on every `api/v1/*` failure: 401, 404, 405, 422, 429, 500. Each has a machine-readable code, a message, a next step, and `retryable`. The codes are reusable by the MCP server.
7. An OpenAPI spec kept true by generation, with a test that fails on drift.
8. One test per filter that proves the filter changes the result, and fails if the filter is silently ignored.
9. SHOULD: `Authorization: Bearer` auth. The same key works on v0 (query) and v1 (header).

## Non-goals

MCP, scopes, revocation, rate limiting, metering and quotas, the developer page, `get_ad`, `list_niches`, and any v1 niche endpoint. The 429 renderer is built so that Part 3's limiter can use it, but no limiter is built here.

## Anti-goals

- Any change to v0's observable behaviour: no tiebreaker, no strictness, no cursor fields, no new error text, no `Authorization` requirement.
- A walk test that depends on the order of ads with equal sort keys. Tests compare sets for ties.
- Silent fallbacks in v1: clamping, ignoring unknown parameters, or accepting values it cannot honour.
- A spec that is hand-maintained without a check.

## Constraints

- Laravel 13.38, PHP 8.5.12, Pest 5.3.3, PHPUnit 13.4.3, Larastan 3.13.2 at level 7, Carbon 3.15.1. Postgres 18.6 is used for the app **and** the tests (Part 0 decision), so there is no sqlite divergence to design around.
- `AGENTS.md` layering: Controllers coordinate, Requests validate, Middleware filters, Resources shape, Actions hold single-task logic, DTOs and Enums hold data. No new top-level folders. Subfolders under existing folders are fine.
- Every new PHP file comes from `php artisan make:* --no-interaction`.
- No composer scripts beyond the existing ones. There is no Makefile on this branch, so verification uses `composer test` (pint `--test`, `phpstan analyse --memory-limit=1G`, `artisan test`) and the per-task commands listed below.
- Pint and Larastan run on the changed files. Don't change factory defaults, because v0 tests depend on them. `AdFactory` defaults `brand_id = 1` and `impression_rank = 1`, and the table has `unique(brand_id, impression_rank)`, so v1 tests set ranks explicitly.

## Approaches and decisions

### 1. Sharing search code with v0 [user: "Shared predicates"]

Options considered:

| | Approach | Result |
|---|---|---|
| A | **Shared predicates (chosen).** Move `q`, `niche`, `format`, `status` and `started_after` into one shared predicate builder. v0's `SearchAds` keeps its start_date ordering and offset paging. v1 gets its own Action for keyset ordering and the cursor. | Narrows Part 0 decision B: the shared unit is the predicates, not the whole `SearchAds`. v0's emitted output is unchanged, and the contract test covers each shared predicate. |
| B | Flags on `SearchAds::handle()`. | Puts every v1 branch on v0's path, and the flag default becomes a contract. Rejected. |
| C | Fully separate v1 search. | Two copies of q escaping and niche matching, which can drift. A drifted filter is the silent-ignore failure the spec tests for. Rejected. |

Notes for the builder:
- The shared builder can live beside `SearchAds` (it is a query builder, not a new top-level folder). Its name is the builder's call.
- `niche` becomes `whereIn` in the shared builder, and v0 passes a single-element array. v0's rows and order are unchanged. Its emitted SQL changes, so the contract test's ORDER BY pin must stay green.
- The change to v0 code is a behaviour-preserving extraction. It is its own commit, with the v0 contract test green before and after it (guard Step 2).

### 2. Impressions sort [user: "Rank, then id"]

`impression_rank` ranks ads within one brand. We store no impression counts, so there is no global "most impressions" order. Chosen order:

`ORDER BY impression_rank ASC NULLS LAST, id ASC`

- Each brand's rank-1 ad comes before any brand's rank-2 ad. Ties at the same rank order by id ascending. The user chose "then id" and did not state a direction, so ascending is **[my call]**.
- Null ranks (about 40%) come last, ordered by id ascending.
- **Stated limitation** (README, OpenAPI description, and a `notes` entry in the response when `sort=impressions`): "This sort orders by brand-relative rank. Rank 1 at a small brand is not comparable to rank 1 at a large one, so results interleave brands by rank, not by absolute impressions. Ads without impression data come last."

### 3. Cursor and walk guarantees [user: "Snapshot by id"]

The cursor is an opaque, signed, keyset cursor, with a snapshot cutoff.

**Cursor contents** (base64url of JSON, then `.`, then HMAC-SHA256 over the payload):

| Key | Meaning |
|---|---|
| `v` | Format version, `1`. |
| `h` | Hash of the normalised applied filters and sort. Changed filters or sort are rejected. |
| `c` | Snapshot cutoff: the max `id` at the time page 1 was served. Every page filters `id <= c`. |
| `k` | Last row's sort key: `start_date` (`Y-m-d`) for `newest`, or `impression_rank` (int or null) for `impressions`. |
| `i` | Last row's `id`. |

- Signed with HMAC-SHA256 over the payload. The key is derived from `APP_KEY` (a dedicated purpose string). Verify with `hash_equals`. **[my call]** Signing stops a caller forging a cursor that skips or repeats rows. A forged cursor could not reveal anything the same filters cannot already reach, so this is integrity, not secrecy.
- `per_page` is **not** bound to the cursor, so a caller may change the page size between pages. **[my call]**
- No expiry. A cursor held for a long time keeps its snapshot cutoff, and deactivations still apply (see below). **[my call]**
- Malformed or badly signed cursor: `invalid_cursor` (422). Cursor from different filters or sort: `cursor_mismatch` (422).

**Keyset SQL** (Postgres `NULLS LAST` is written explicitly in a raw order clause, because Laravel 13's builder has no null-ordering helper; verified by grep of the vendored builder):

- `newest`: `ORDER BY start_date DESC, id DESC`. After `(k, i)`: `start_date < k OR (start_date = k AND id < i)`.
- `impressions`: `ORDER BY impression_rank ASC NULLS LAST, id ASC`. After `(k, i)` with `k` not null: `impression_rank > k OR (impression_rank = k AND id > i) OR impression_rank IS NULL`. After a null `k`: `impression_rank IS NULL AND id > i`.
- Every query also has `id <= c`.

**Walk guarantees** (these go in the README and the OpenAPI description). For one walk, meaning a chain of requests linked by `next_cursor` with the same filters and sort:

1. **No repeats.** No ad appears twice in a walk.
2. **No skips of what existed.** An ad that existed when page 1 was served, matched then, and still matches when the walk reaches its place is returned. This holds while its `start_date`, `impression_rank` and `id` are unchanged. Ingest never changes them after insert, and a test checks that.
3. **Inserts are not shown.** Ads inserted after page 1 are excluded by `id <= c`, including backdated ones. One exception: an insert whose transaction was still open when page 1 was served can commit later with an `id <= c`, and then appears. Ids are assigned on insert, not on commit.
4. **Deactivations.** A deactivated ad still matches when the query has no `status` filter, so it still appears, with `status: inactive` in its item. With `status=active`, it no longer matches and drops out of later pages. Dropping out is allowed by (2).
5. **No promise about the order of equal sort keys** beyond the deterministic id tiebreak. Tests assert sets for ties.
6. **No total** (see decision 6). The walk ends when `has_more` is false.

### 4. OpenAPI [user: "Scramble, generated"]

- Add `dedoc/scramble`. Its v0.13.50 requires `illuminate/contracts ^13.0`, so it supports Laravel 13. The dependency was approved by the user's choice. Install the exact version with `composer require dedoc/scramble`, then check the installed version in `composer.lock`.
- The spec documents **v1 only**. v0 is frozen and not documented here. **[my call]**
- The **FormRequest rules** are the single source of truth for allowed parameter names and value rules. The unknown-parameter check and the did-you-mean suggestions read the same lists, so the spec cannot diverge from the code.
- Scramble cannot infer the custom error envelope. Add the error component through Scramble's extension hook, verified against the installed package's API after install. The builder must verify the hook names in `vendor/dedoc/scramble`; nothing is assumed here.
- **Drift test:** the committed `docs/openapi/v1.json` must equal the regenerated spec. The test also checks that every code in `ApiErrorCode` appears in the error component. The failure message says how to regenerate.

### 5. Auth [user: "Bearer only"]

- `AuthenticateV1ApiKey` middleware on the v1 group only. It reads `$request->bearerToken()`, then `ApiKey::findBySecret` (the shared key store, already used by v0).
- A query-string `api_key` on v1 gives 401 `api_key_in_query`, even when a Bearer header is also sent. The v0 middleware is unchanged.
- No Bearer header: 401 `api_key_missing`. Unknown key: 401 `api_key_invalid`.

### 6. Total count [user: "Omit total"]

v1 returns no `total`. It returns `has_more` (the query fetches `per_page + 1` rows) and `next_cursor` (null when `has_more` is false). A count over ILIKE matches is a full scan on every request, and this feeds `DESIGN.md`.

### 7. Multiple niches [user: "Repeated only"]

`niche=skincare&niche=haircare`. The comma form `niche=skincare,haircare` gets 422 `invalid_parameter` with the next step "repeat the parameter". The echoed `applied_filters.niche` is a sorted, de-duplicated list.

## Validation rules [my calls]

| Parameter | Rule | Invalid example and result |
|---|---|---|
| `q` | Trimmed. 2–200 characters. Literal substring against `headline` OR `body_text`, case-insensitive. `\`, `%` and `_` are escaped. | `q=a` → `invalid_parameter`: "q needs at least 2 characters". |
| `niche` | Repeated form only. Each value must be an exact slug from the 12 in `App\Enums\Niche`. Unlike v0, display names are not accepted. | `niche=Skincare` → `invalid_parameter`, did you mean `skincare`. |
| `format` | Exact enum value, `image`, `video` or `carousel`. Repeats are rejected. | `format=Video` → did you mean `video`. |
| `status` | Exact enum value, `active` or `inactive`. Repeats are rejected. | `status=paused` → allowed values listed. |
| `started_after` | Strict `YYYY-MM-DD` calendar date. Exclusive: matches `start_date > started_after`. A future date is valid and returns no rows. | `10/01/2026`, `2026-02-30` → `invalid_parameter`, example `2026-08-01`. |
| `sort` | `newest` (default) or `impressions`. | `sort=popular` → allowed values listed. |
| `per_page` | Integer 1–100, default 20. Out of range or not an integer is rejected, not clamped. | `per_page=500` → "must be between 1 and 100". |
| `cursor` | Opaque. Must be a value returned by this API for the same filters and sort. | See `invalid_cursor` and `cursor_mismatch`. |
| any parameter | An empty value is invalid, because the caller should omit the parameter. A repeated scalar is invalid. Array syntax such as `niche[]=` is invalid. | `q=` → `invalid_parameter`, "omit the parameter". |

**Reading empty values:** the global `TrimStrings` and `ConvertEmptyStringsToNull` middleware rewrite `$request->query()`, so an empty `q=` or `q=%20%20` looks absent. v1 reads the unmodified query string, for example with `parse_str` over the raw `QUERY_STRING`. The builder must verify this with a test that fails before the fix.

**Unknown parameters:** any key not in the FormRequest's rules is rejected with `unknown_parameter`, and the first failure is reported.

## Did you mean [my call]

- Parameter names: a case-insensitive exact match, or a Levenshtein distance within the threshold, against the allowed names. The threshold is ≤ 2 for names of 5 characters or more, and ≤ 1 for shorter names. `nich` → `niche` (distance 1), `niches` → `niche`.
- Values (`niche`, `format`, `status`, `sort`): normalise the caller's value (lowercase, and spaces or underscores become hyphens), then exact match, then Levenshtein with the same threshold. `Pet Supplies` → `pet-supplies`, `Skincare` → `skincare`.
- At most one suggestion, the nearest, with ties resolved by the allowed-list order. The suggestion goes in `details.did_you_mean`, and the allowed values go in `details.allowed`.

## Error catalogue [my call]

**Envelope** (`api/v1/*` only):

```json
{
  "error": {
    "code": "unknown_parameter",
    "message": "Unknown query parameter \"nich\".",
    "next_step": "Use \"niche\" instead. Allowed parameters: q, niche, format, status, started_after, sort, per_page, cursor.",
    "retryable": false,
    "details": { "parameter": "nich", "did_you_mean": "niche" }
  }
}
```

- `details` is optional. A 422 with several bad parameters lists them all in `details.errors`, and the top-level fields describe the first.
- `retryable` is true only where waiting or retrying can change the outcome: 429 (with `Retry-After`) and 500.

| Code | HTTP | `retryable` | When | `next_step` (summary) |
|---|---|---|---|---|
| `api_key_missing` | 401 | false | No Bearer header. | Send `Authorization: Bearer <key>`. |
| `api_key_invalid` | 401 | false | Bearer key not recognised. | Check the key. Use a key issued for this API. |
| `api_key_in_query` | 401 | false | `api_key` in the query string. | Move the key to the Authorization header. Query keys are not accepted on v1. |
| `not_found` | 404 | false | Unknown v1 path. | The search endpoint is `GET /api/v1/ads`. |
| `method_not_allowed` | 405 | false | Wrong method. | Use `GET`. The `Allow` header is kept. |
| `unknown_parameter` | 422 | false | Unknown query key. | Use the named parameter, or remove it. |
| `invalid_parameter` | 422 | false | Invalid, empty or repeated value. | The allowed values, or the format to use. |
| `invalid_cursor` | 422 | false | Malformed or badly signed cursor. | Start again without `cursor`. |
| `cursor_mismatch` | 422 | false | Cursor from different filters or sort. | Send the same parameters, or start again without `cursor`. |
| `rate_limited` | 429 | true | Limiter (Part 3) reports too many requests. Renderer only. | Wait `Retry-After` seconds, then retry. |
| `internal_error` | 500 | true | Unexpected failure. Message is generic. | Retry after a short wait. If it keeps failing, report it with the time of the request. |

- Codes live in one backed enum, `App\Enums\ApiErrorCode`, with `status()`, `retryable()` and a `nextStep()` builder. The MCP server reuses it.
- Renderer: `bootstrap/app.php` gets a **new** render callback scoped to `api/v1` and `api/v1/*`, registered **after** the v0 callback. The v0 callback is not edited. Its test (`leaves error rendering outside v0 untouched`) and the v0 contract suite must stay green.
- `ValidationException` and our own exception class map to the table above. `NotFoundHttpException`, `MethodNotAllowedHttpException` and `TooManyRequestsHttpException` map to 404, 405 and 429. Any other `Throwable` maps to 500 with a generic message. Debug detail never reaches the body.

## Response shape [my call]

```json
{
  "data": [
    {
      "id": 18342,
      "brand": "Acme",
      "headline": "…",
      "niche": "pet-supplies",
      "format": "video",
      "status": "active",
      "start_date": "2026-08-01",
      "days_active": 85
    }
  ],
  "applied_filters": {
    "q": "serum",
    "niche": ["skincare"],
    "format": null,
    "status": "active",
    "started_after": null,
    "sort": "newest"
  },
  "has_more": true,
  "next_cursor": "eyJ2IjoxLC4uLn0.…"
}
```

- Item keys are exactly eight: `id`, `brand` (brand name), `headline`, `niche` (**slug**, the same identifier the filter takes; v0 returns the display name), `format`, `status`, `start_date`, `days_active`. `body_text` is left out to keep responses small. Up to 100 items are returned at a few hundred bytes each.
- `days_active` comes from `Ad::daysActive()`, the single shared implementation, so v0 and v1 agree.
- `applied_filters` always has all six keys. Unapplied filters are `null`. `niche` is a sorted, de-duplicated list. `q` is the trimmed value. `started_after` is `Y-m-d`.
- `notes` is included only for `sort=impressions`, carrying the brand-relative rank limitation.
- An empty page is `data: []`, `has_more: false`, `next_cursor: null`.

## Indexes [my call]

Add an additive migration with `(start_date, id)` and `(impression_rank, id)`. Both keep the sort and the keyset in index order. The existing single-column `start_date` index stays. A later Phase 4 step runs `EXPLAIN` on the keyset query to check that it uses the index. Additive indexes are allowed by the guard. They do not change v0's rows or its ORDER BY.

## Testing strategy

- Pest `it()` tests, one behaviour each. Every test creates its own data with factories and **explicit values**: sort keys, ranks, brands and dates. Time is frozen with `travelTo`.
- Tests on ties compare sets, not order.
- **Filter tests** (one per filter, for `q`, `niche`, `format`, `status`, `started_after`): each seeds ads that differ in that filter, and asserts that the result is exactly the matching subset. Each would fail if the filter were ignored, because the count would be wrong.
- **Walk tests** for both sorts: walk with a small page size, and between pages run the real `IngestAds` action (with a seeded `Randomizer`) and deactivate and insert ads by hand. Assert: no repeats, every pre-existing matching ad seen, no ads inserted after page 1 seen (guarantee 3), deactivations shown or dropped per guarantee 4.
- **Cursor tests:** tampered signature, malformed base64, changed filter, changed sort, and a valid cursor from a different query.
- **Error-shape tests:** each code in the catalogue, with its status, `retryable`, `next_step` non-empty, and the `Retry-After` header for 429.
- **v0 guard run** as the first and last step of every task that touches shared code. The v0 contract test is never edited.
- **Mutation check** (Phase 4): disable each filter, the sort, the cursor, and unknown-parameter rejection, one at a time, and record the failing test.

## Verification commands

```bash
php artisan test --compact <path-or-filter>    # narrowest first
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse --memory-limit=1G
composer test                                   # the full gate, run by the user
```

## What's left to the builder

Class and file names (the shared predicate builder, the v1 Action, `ApiErrorCode`, the middleware, the FormRequest, the renderer), the exact Scramble extension hook, the migration name, the Levenshtein helper placement, the wording of each `message` and `next_step`, and the chunk size of the ingest-driven walk tests.

## Assumptions for the README

- The impressions sort is brand-relative (see the limitation above).
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
