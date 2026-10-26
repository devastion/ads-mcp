<?php

use App\Actions\IssueApiKey;
use App\Actions\Mcp\Tools\AdsTool;
use App\Enums\ApiErrorCode;
use App\Enums\Niche;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Http/Controllers/Mcp/McpHelpers.php';

const CLAUDE_PLUGIN_ROOT = 'plugins/ads';

/**
 * @return array<string, mixed>
 */
function claudePluginJson(string $relativePath): array
{
    return json_decode((string) File::get(base_path($relativePath)), true, flags: JSON_THROW_ON_ERROR);
}

it('lists the ads plugin in the repository marketplace', function () {
    $marketplace = claudePluginJson('.claude-plugin/marketplace.json');

    expect($marketplace['name'])->toBe('ads')
        ->and($marketplace['plugins'])->toHaveCount(1)
        ->and($marketplace['plugins'][0])->toMatchArray(['name' => 'ads', 'source' => './'.CLAUDE_PLUGIN_ROOT]);
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

it('connects the ads server over HTTP with a Bearer header built from the key option', function () {
    expect(claudePluginJson(CLAUDE_PLUGIN_ROOT.'/.mcp.json')['mcpServers'])->toBe(['ads' => [
        'type' => 'http',
        'url' => '${user_config.mcp_url}',
        'headers' => ['Authorization' => 'Bearer ${user_config.api_key}'],
    ]]);
});

it('authenticates an initialize request sent with the plugin header template and no Origin', function () {
    $issued = app(IssueApiKey::class)->handle('claude-code');
    $template = claudePluginJson(CLAUDE_PLUGIN_ROOT.'/.mcp.json')['mcpServers']['ads']['headers']['Authorization'];

    mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'claude-code', 'version' => '1']]],
        'initialize',
        headers: ['Authorization' => str_replace('${user_config.api_key}', $issued->secret, $template)],
    )->assertOk()->assertJsonPath('result.serverInfo.name', 'ads');
});

it('commits no API key in the plugin or marketplace files', function () {
    $files = [
        ...File::allFiles(base_path(CLAUDE_PLUGIN_ROOT), true),
        ...File::allFiles(base_path('.claude-plugin'), true),
    ];

    foreach ($files as $file) {
        expect($file->getContents())->not->toMatch('/ghk_[A-Za-z0-9]{40}/');
    }
});

const CLAUDE_PLUGIN_SKILL = CLAUDE_PLUGIN_ROOT.'/skills/ad-research/SKILL.md';

it('describes the ad-research skill in its frontmatter', function () {
    expect(File::get(base_path(CLAUDE_PLUGIN_SKILL)))->toMatch('/\A---\nname: ad-research\ndescription: Use when .+\n---\n/');
});

it('names only tools the MCP server exposes', function () {
    preg_match_all('/\b(?:search|get|list)_[a-z_]+\b/', File::get(base_path(CLAUDE_PLUGIN_SKILL)), $matches);

    expect(array_values(array_unique($matches[0])))->each->toBeIn(AdsTool::NAMES)
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
    $responseFields = ['next_cursor', 'body_next_offset', 'body_has_more', 'retry_after_seconds', 'resets_at', 'next_step', 'started_after', 'impression_rank', 'search_ads', 'get_ad', 'list_niches', 'start_date'];
    $codes = array_values(array_diff($matches[1], $responseFields));

    expect($codes)->each->toBeIn(array_column(ApiErrorCode::cases(), 'value'));
});
