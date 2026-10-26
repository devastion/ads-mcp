---
name: ad-research
description: Use when the user asks about ads, brands, niches or ad copy in the ads synthetic ad library, or wants ads searched, compared or read in full.
---

# Ad research

The data is synthetic. Read it only through the `ads` MCP tools `list_niches`, `search_ads` and `get_ad`. Do not invent ads, brands, counts or dates.

## Workflow

1. **Niches first.** Call `list_niches` to get exact slugs. Never guess a slug or use a display name in `niche`.
2. **Relative dates.** Convert "started in the last 30 days" into `started_after` as today's date minus 30 days, as `YYYY-MM-DD`. The bound is exclusive: `start_date` must be after it.
3. **Pages.** Continue a list with `next_cursor`, repeating the same filters and sort. Stop when `next_cursor` is absent.
4. **Full copy.** Call `get_ad` for complete text and dates. While `body_has_more` is true, call again with `body_next_offset`.

## What the data cannot answer

- `impression_rank` is relative to each brand. Do not rank brands by impressions or call one brand the biggest. Say the data cannot answer that question.
- If the user asks for "the best" ads without saying what that means, ask what they mean before searching.

## Errors

Read `error.code`, `error.next_step` and `error.retryable`.

- `retryable` is false: do not retry the same call.
- `rate_limited`: wait `retry_after_seconds`, then retry.
- `quota_exceeded`: stop and report `resets_at`.
- `api_key_missing` or `api_key_invalid`: tell the user to set a valid key with `search:read` scope in the plugin options.
- `unknown_parameter` or `invalid_parameter`: make the one corrected call that `next_step` describes.

## Billing

Each successful read is billable, including an empty result. Do not repeat identical calls. Claude Code sends no `Idempotency-Key`, so a retried call is billed again.
