---
name: v0-contract-guard
description: Protect the frozen v0 ad search API (GET /api/v0/ads) while changing v1, MCP, keys, rate limits, metering, shared models, middleware or exception rendering. Use before and after any change that can reach v0, and before committing such work.
---

# v0 contract guard

v0 is a customer-facing contract with its quirks frozen on purpose. Do not fix it.

Full contract: `references/v0-contract.md`. The pinned tests are the authority:
`tests/Feature/Http/Controllers/V0/AdControllerTest.php`.

## When to run

- Before starting a change that touches a shared surface (see the script's list).
- After the change, before committing.
- Always before declaring a task done.

## Procedure

1. Run the contract test. It must pass:
   `php artisan test --compact tests/Feature/Http/Controllers/V0/AdControllerTest.php`
2. Run the blast-radius check against the base ref (default `HEAD`):
   `bash .agents/skills/v0-contract-guard/scripts/v0_blast_radius.sh <base>`
   It lists changed shared files and fails if the contract test file changed.
3. Read every changed shared file and ask whether v0 output, status codes or error bodies could differ.
4. Mutation check: break one v0 behaviour on purpose (e.g. the `per_page` clamp) and confirm the contract test fails. Revert it and confirm it passes again.
5. Run `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse --memory-limit=1G` on changed files.

## Rules

- Never edit the v0 contract test to make a change pass. Changing it needs explicit user sign-off.
- Never "fix" a v0 quirk (clamping, ignored filters, single 400 body, sort without tiebreaker).
- Do not move v0's error rendering; v1 gets its own callback after the v0 one.

## Report

End with:

```
## v0 guard: PASS | FAIL
- Contract test: <pass/fail, count>
- Blast radius: <changed shared files, or none>
- Contract test file unchanged: yes/no
- Mutation check: <behaviour broken, result>
- Pint/PHPStan: <result>
- Concerns: <none or list>
```
