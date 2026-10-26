# Claude Code Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a Claude Code plugin, `gethookd`, installable from this repository, that connects Claude Code to the existing `laravel/mcp` server at `/api/mcp` and bundles one usage skill.

**Architecture:** The repository root becomes a Claude Code marketplace (`.claude-plugin/marketplace.json`) that lists one plugin under `plugins/gethookd/`. The plugin declares a Streamable HTTP server pointing at `/api/mcp`, and the API key goes in a Bearer header. It takes the key and the endpoint URL through `userConfig`; the key is `sensitive`, so Claude Code keeps it in secure storage and it is never written into the repo. No application code changes: the plugin is a client of the existing server, auth, rate limit and metering.

**Tech Stack:** Claude Code plugin format (`plugin.json`, `.mcp.json`, `skills/`), Claude Code CLI 2.1.310 (`claude plugin validate`), Pest 5 for contract tests.

**Spec:** No separate spec. The decisions below were made in conversation on 2026-10-26 and are recorded in `docs/superpowers/USER_DECISIONS.md` by Task 3:

- **[user] Location:** a repo marketplace. Add `.claude-plugin/marketplace.json` at the root and the plugin in `plugins/gethookd/`, which approves the two new top-level folders. Add no root `.mcp.json`.
- **[user] Contents:** the MCP server plus one usage skill.
- **[user] Scope:** plugin only. Pi-Bolt stays the Part 2 evaluation client (P2-9) and the developer-panel client (`McpPanelResource::CLIENT`).

## Global Constraints

- Do not change `app/`, `routes/`, `config/`, `database/` or the frontend. If a task seems to need one, stop and ask. That change could reach v0 and would trigger `.agents/skills/v0-contract-guard`.
- Commit no API key secret, including `LocalApiKeySeeder::SECRET`. Keys match `ghk_` followed by 40 alphanumerics.
- Plugin name `gethookd`, marketplace name `gethookd`, plugin id `gethookd@gethookd`. MCP server name `gethookd`, the same key as the `McpPanelResource` config template.
- Server entry: `"type": "http"`, `"url": "${user_config.mcp_url}"`, `"headers": {"Authorization": "Bearer ${user_config.api_key}"}`.
- `userConfig.api_key`: `type` string, `sensitive: true`, `required: true`. `userConfig.mcp_url`: `type` string, `default` `http://localhost:8000/api/mcp`.
- `/api/mcp` authenticates only with `Authorization: Bearer`. A query-string key is refused (`api_key_in_query`). A request without an `Origin` header passes `EnforceMcpOrigin`.
- Claude Code sends no `Idempotency-Key` on MCP calls, so a retried tool call is billed again (P2-8). The skill and README must say so; nothing in this plan works around it.
- Tool descriptions in `app/Actions/Mcp/Tools/*` stay the single source of truth for parameters and valid values. The skill covers workflow and limits only. It restates no schemas and lists no niche slugs.
- `claude plugin validate --strict` must print `Validation passed` for both `.` (the marketplace) and `plugins/gethookd`.

## Review Focus

1. **Empty key at install.** A user skips the key prompt and every call fails with `api_key_missing` and no hint. Expected: the dialog won't accept an empty key (`required: true`). Pinned in Task 1.
2. **Server on another host or port** (eval mode on `127.0.0.1:8000`, a deployed URL). Expected: `mcp_url` overrides the default, and the default path is the real MCP route. Pinned in Task 1.
3. **Header template drift.** Someone changes the Bearer header format (a casing typo, a missing `Bearer `, or a key moved to the URL) and the server rejects every call. Expected: the template, filled with a real key and sent without `Origin`, passes `initialize`. Pinned in Task 1.
4. **Secret pasted into a committed plugin file**, such as the local dev key used as a convenience default. Expected: no `ghk_` key anywhere under the plugin or the marketplace. Pinned in Task 1.
5. **Skill drifts from the server.** It names a tool that was renamed or hard-codes a niche slug the catalogue no longer has. Expected: the skill names only `GethookdTool::NAMES` and sends the model to `list_niches` for slugs. Pinned in Task 2.

---

## File Structure

| Path | Responsibility |
| --- | --- |
| `.claude-plugin/marketplace.json` | Makes the repo root a marketplace that lists `gethookd`. |
| `plugins/gethookd/.claude-plugin/plugin.json` | Plugin metadata and the `api_key` and `mcp_url` options. |
| `plugins/gethookd/.mcp.json` | The `gethookd` HTTP server entry. |
| `plugins/gethookd/skills/ad-research/SKILL.md` | How to use the three tools well, and what the data cannot answer. |
| `tests/Feature/Documentation/ClaudePluginTest.php` | Contract tests that tie the plugin files to the live MCP route and auth. |
| `README.md` | A "Connect Claude Code" section next to "Connect Pi-Bolt". |
| `docs/superpowers/USER_DECISIONS.md` | Records the three decisions above. |

---

### Task 1: Marketplace, manifest and MCP server entry

**Files:**
- Create: `.claude-plugin/marketplace.json`
- Create: `plugins/gethookd/.claude-plugin/plugin.json`
- Create: `plugins/gethookd/.mcp.json`
- Test: `tests/Feature/Documentation/ClaudePluginTest.php` (create with `php artisan make:test --pest Documentation/ClaudePluginTest --no-interaction`, then match the style of `OpenApiV1Test.php` in that folder)

**Interfaces:**
- Consumes: `mcpPost()` and `MCP_PATH` from `tests/Feature/Http/Controllers/Mcp/McpHelpers.php` (`require_once`), `App\Actions\IssueApiKey::handle(string $name)`, and the `api/mcp` route registered in `routes/ai.php`.
- Produces: the test-file helper `claudePluginJson(string $relativePath): array`, which decodes a repo-relative JSON file with `JSON_THROW_ON_ERROR`, and the constant `CLAUDE_PLUGIN_ROOT = 'plugins/gethookd'`. Task 2 uses both.

- [ ] **Step 1: Write the failing tests**

```php
it('lists the gethookd plugin in the repository marketplace', function () {
    $marketplace = claudePluginJson('.claude-plugin/marketplace.json');

    expect($marketplace['name'])->toBe('gethookd')
        ->and($marketplace['plugins'])->toHaveCount(1)
        ->and($marketplace['plugins'][0])->toMatchArray(['name' => 'gethookd', 'source' => './'.CLAUDE_PLUGIN_ROOT]);
});

it('asks for the API key as a required sensitive option', function () {
    $option = claudePluginJson(CLAUDE_PLUGIN_ROOT.'/.claude-plugin/plugin.json')['userConfig']['api_key'];

    expect($option)->toMatchArray(['type' => 'string', 'sensitive' => true, 'required' => true]);
});

it('defaults the endpoint to the MCP route on the local server', function () {
    $default = claudePluginJson(CLAUDE_PLUGIN_ROOT.'/.claude-plugin/plugin.json')['userConfig']['mcp_url']['default'];
    $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'api/mcp' && in_array('POST', $route->methods(), true));

    expect($default)->toBe('http://localhost:8000/api/mcp')
        ->and($route)->not->toBeNull()
        ->and(parse_url($default, PHP_URL_PATH))->toBe(MCP_PATH);
});

it('connects the gethookd server over HTTP with a Bearer header built from the key option', function () {
    expect(claudePluginJson(CLAUDE_PLUGIN_ROOT.'/.mcp.json')['mcpServers'])->toBe(['gethookd' => [
        'type' => 'http',
        'url' => '${user_config.mcp_url}',
        'headers' => ['Authorization' => 'Bearer ${user_config.api_key}'],
    ]]);
});

it('authenticates an initialize request sent with the plugin header template and no Origin', function () {
    $issued = app(IssueApiKey::class)->handle('claude-code');
    $template = claudePluginJson(CLAUDE_PLUGIN_ROOT.'/.mcp.json')['mcpServers']['gethookd']['headers']['Authorization'];

    mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'claude-code', 'version' => '1']]],
        'initialize',
        headers: ['Authorization' => str_replace('${user_config.api_key}', $issued->secret, $template)],
    )->assertOk()->assertJsonPath('result.serverInfo.name', 'gethookd-ads');
});

it('commits no API key in the plugin or marketplace files', function () {
    $files = [...File::allFiles(base_path(CLAUDE_PLUGIN_ROOT), hidden: true), ...File::allFiles(base_path('.claude-plugin'), hidden: true)];

    foreach ($files as $file) {
        expect($file->getContents())->not->toMatch('/ghk_[A-Za-z0-9]{40}/');
    }
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --compact tests/Feature/Documentation/ClaudePluginTest.php`
Expected: all six fail. The JSON tests fail with a `JsonException` or file-not-found error, and the secret scan fails because the directory is missing.

- [ ] **Step 3: Create the three JSON files**

- `marketplace.json`: `name` `gethookd`, `owner.name` `devastion`, a `description`, and one entry `{name: "gethookd", source: "./plugins/gethookd", description}`.
- `plugin.json`: `name` `gethookd`, `displayName` `Gethookd Ads`, `version` `0.1.0`, `description`, `author.name` `devastion`, and `userConfig` from Global Constraints. The `api_key` description reads: "A key with search:read scope, created on the developer page (http://localhost:8000/developer). Stored in secure storage, not settings.json." The `mcp_url` description reads: "Full URL of the gethookd MCP endpoint. Change it only if the app does not run on localhost:8000."
- `.mcp.json`: exactly the object the fourth test asserts, wrapped in `mcpServers`.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php artisan test --compact tests/Feature/Documentation/ClaudePluginTest.php`
Expected: 6 passed.

- [ ] **Step 5: Validate with the Claude Code CLI**

Run: `claude plugin validate --strict . && claude plugin validate --strict plugins/gethookd`
Expected: `Validation passed` for both.

If validation rejects `${user_config.mcp_url}` as "not a valid absolute URL", replace `url` with the literal `http://localhost:8000/api/mcp` and remove the `mcp_url` option. Then change tests 3 and 4 to assert the literal URL, and report the change at the task review. Do not suppress the error any other way.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add .claude-plugin plugins/gethookd/.claude-plugin plugins/gethookd/.mcp.json tests/Feature/Documentation/ClaudePluginTest.php
git commit -m "Add gethookd Claude Code plugin connecting to /api/mcp"
```

---

### Task 2: `ad-research` skill

**Files:**
- Create: `plugins/gethookd/skills/ad-research/SKILL.md`
- Modify: `tests/Feature/Documentation/ClaudePluginTest.php`

**Interfaces:**
- Consumes: `claudePluginJson()` and `CLAUDE_PLUGIN_ROOT` from Task 1, `App\Actions\Mcp\Tools\GethookdTool::NAMES`, `App\Enums\Niche::cases()` and `App\Enums\ApiErrorCode`.
- Produces: the skill `gethookd:ad-research`.

- [ ] **Step 1: Write the failing tests**

```php
const CLAUDE_PLUGIN_SKILL = CLAUDE_PLUGIN_ROOT.'/skills/ad-research/SKILL.md';

it('describes the ad-research skill in its frontmatter', function () {
    $contents = File::get(base_path(CLAUDE_PLUGIN_SKILL));

    expect($contents)->toMatch('/\A---\nname: ad-research\ndescription: Use when .+\n---\n/');
});

it('names only tools the MCP server exposes', function () {
    preg_match_all('/\b(?:search|get|list)_[a-z_]+\b/', File::get(base_path(CLAUDE_PLUGIN_SKILL)), $matches);

    expect(array_values(array_unique($matches[0])))->each->toBeIn(GethookdTool::NAMES)
        ->and($matches[0])->toContain('list_niches', 'search_ads', 'get_ad');
});

it('leaves niche slugs to list_niches instead of hard-coding them', function () {
    $contents = File::get(base_path(CLAUDE_PLUGIN_SKILL));

    foreach (Niche::cases() as $niche) {
        expect($contents)->not->toContain('`'.$niche->value.'`')->not->toContain('"'.$niche->value.'"');
    }
});

it('names only error codes the API returns', function () {
    preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', File::get(base_path(CLAUDE_PLUGIN_SKILL)), $matches);
    $codes = array_diff($matches[1], [...GethookdTool::NAMES, 'retry_after_seconds', 'resets_at', 'next_step', 'next_cursor', 'body_next_offset', 'body_has_more', 'started_after', 'impression_rank']);

    expect(array_values($codes))->each(fn ($code) => $code->toBeIn(array_column(ApiErrorCode::cases(), 'value')));
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `php artisan test --compact tests/Feature/Documentation/ClaudePluginTest.php --filter='skill|tools the MCP|niche slugs|error codes'`
Expected: 4 fail on the missing file.

- [ ] **Step 3: Write `SKILL.md`**

If the skill backticks another real response field (for example `body_limit`), add it to the allow-list in the error-code test; never add an invented code.

Frontmatter: `name: ad-research`. The description starts "Use when the user asks about ads, advertisers/brands, niches or ad copy in the gethookd synthetic ad library". The body is short (under ~60 lines), uses no hard-coded slugs, restates no schemas, and covers only these points:

1. **Scope.** The data is synthetic. It is read only through the plugin's `gethookd` server tools `list_niches`, `search_ads` and `get_ad`.
2. **Workflow.** Map niche words to slugs by calling `list_niches` first. Turn relative dates into `started_after` from today's date, and note that the bound is exclusive. Continue a list with `next_cursor`, keeping the same filters and sort. For full copy, call `get_ad` and follow `body_next_offset` while `body_has_more` is true.
3. **Honest limits.** `impression_rank` is brand-relative, so "which brand gets the most impressions" can't be answered. Say so instead of ranking brands. Ask what "best" means before searching.
4. **Errors.** Read `code`, `next_step` and `retryable`. Never retry when `retryable` is false. On `rate_limited`, wait `retry_after_seconds`. On `quota_exceeded`, stop and report `resets_at`. On `api_key_missing` or `api_key_invalid`, tell the user to set a valid search:read key in the plugin's options. On `unknown_parameter` or `invalid_parameter`, make the corrected call the error suggests once.
5. **Billing.** Every successful read, including an empty result, is billable. Don't repeat identical calls. Claude Code sends no `Idempotency-Key`, so a retried call is billed again.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `php artisan test --compact tests/Feature/Documentation/ClaudePluginTest.php`
Expected: 10 passed.

- [ ] **Step 5: Validate and commit**

Run: `claude plugin validate --strict plugins/gethookd`
Expected: `Validation passed`.

```bash
vendor/bin/pint --dirty --format agent
git add plugins/gethookd/skills tests/Feature/Documentation/ClaudePluginTest.php
git commit -m "Add ad-research skill to the gethookd plugin"
```

---

### Task 3: README, decision record and a real-client check

**Files:**
- Modify: `README.md`, adding `### Connect Claude Code` directly after the `### Connect Pi-Bolt` section
- Modify: `docs/superpowers/USER_DECISIONS.md`, adding a `## Claude Code plugin` section before the `[my call]` list, with rows CC-1 to CC-3 for the three decisions in this plan's header

**Interfaces:**
- Consumes: the plugin id `gethookd@gethookd`, the options `api_key` and `mcp_url`, and the skill `gethookd:ad-research`.

- [ ] **Step 1: Write the README section**

Cover these points as numbered steps, matching the tone of "Connect Pi-Bolt":

1. Start the app, then create a key with only `search:read` on the developer page and copy it.
2. In Claude Code, run `/plugin marketplace add devastion/ads-mcp` (or `./` from a clone), then `/plugin install gethookd@gethookd`.
3. Paste the key when asked. Leave the endpoint at its default unless the app runs elsewhere (eval mode: `http://127.0.0.1:8000/api/mcp`).
4. Run `/mcp`, check that `gethookd` is connected with 3 tools, then ask **Which niches can I search?**

Add these notes:

- The developer panel's **Connected** status follows only the newest key named `Pi-Bolt`. For a Claude Code key, check its **Last used** column instead.
- Retries are billed twice (no `Idempotency-Key`).
- The Part 2 evaluation still runs in Pi-Bolt, because the plugin's skill would add instructions that the frozen tasks do not allow.
- For local development of the plugin, use `claude --plugin-dir plugins/gethookd`.

- [ ] **Step 2: Add the decision rows**

Add three rows (CC-1 location, CC-2 contents, CC-3 Pi-Bolt unchanged), all with status `Active` and source `(session) 2026-10-26`, in the table format of the Part 2 section.

- [ ] **Step 3: Real-client check (user-run)**

This needs the app running and an interactive Claude Code session, so the user runs it and records the result in the task review:

`docker compose up -d`, then `claude --plugin-dir plugins/gethookd`. Enter the key, run `/mcp`, and ask "Which niches can I search?"

Expected: `gethookd` shows as connected, `list_niches` is called once, the answer lists 12 niches, and the key's **Last used** updates on the developer page.

If `/mcp` shows a failure, capture the error text; don't change server code (Global Constraints).

- [ ] **Step 4: Run the plugin and MCP tests, confirm v0 is untouched, then commit**

Run: `php artisan test --compact tests/Feature/Documentation/ClaudePluginTest.php tests/Feature/Http/Controllers/Mcp`
Expected: all pass.

v0 is unaffected because no task changes app code. Confirm that with `git diff --stat main -- app routes config database` (expected: empty) instead of re-running the guard.

```bash
git add README.md docs/superpowers/USER_DECISIONS.md
git commit -m "Document connecting Claude Code through the gethookd plugin"
```

After the branch is complete, ask the user to run the full suite with `php artisan test --compact`.
