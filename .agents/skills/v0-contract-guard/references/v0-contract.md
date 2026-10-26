# v0 contract

Source: ASSESSMENT.md §0.4 and tests/Feature/Http/Controllers/V0/AdControllerTest.php.
Reconstructed after the original `.agents/` folder was lost. Where this file and the
contract test disagree, the contract test (which has the quirks pinned) wins.

## Endpoint

`GET /api/v0/ads?q=&niche=&format=&status=&page=&per_page=&api_key=`

- `api_key` is a query parameter. v0 does not accept the Bearer header.
- Unknown parameters are ignored.
- Invalid filter values are ignored (that filter is not applied).
- `page`: 1-based, default 1. Invalid values (0, negative, text, fraction) fall back to 1.
- `per_page`: default 20. Above 100 is clamped to 100. Invalid values fall back to 20.
- Past the last page: empty `data`, real `page`, real `total`.
- Sort: `start_date` descending, no tiebreaker.

## Filters

| Param    | Behaviour                                                       |
|----------|-----------------------------------------------------------------|
| `q`      | case-insensitive substring on `headline` or `body_text`          |
| `niche`  | slug OR display name, case-insensitive                           |
| `format` | exact match                                                      |
| `status` | exact match                                                      |

## Response

200: `{"data": [ad...], "page": n, "total": n}` with exactly these top-level keys.

Each ad has exactly these keys, in order:
`id` (int), `brand`, `headline`, `niche` (display name), `format`, `status`,
`start_date` (Y-m-d), `days_active`.

`days_active`: active ad = today - start_date; inactive ad = end_date - start_date,
whole days.

## Errors

Every failure, including a missing, empty, unknown or array `api_key`, returns
400 `{"error":"Invalid request"}`. Rendering is scoped to `api/v0/*` in
`bootstrap/app.php`; that callback must stay ahead of, and unchanged by, any v1 callback.

## Shared code

v0 and v1 share filter predicates (`App\Actions\AdSearchPredicates`). Changing a
predicate changes v0. Also shared: `App\Http\Resources`, `App\DTO`, `App\Actions`,
middleware, the `api_keys` table and rate limiting (60/min per key, shared with v1 and MCP).
