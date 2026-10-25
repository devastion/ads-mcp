<?php

use App\Actions\IssueApiKey;
use App\Enums\Scope;
use Illuminate\Support\Carbon;

require_once __DIR__.'/McpHelpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
});

it('signals a connection on authenticated initialize without billing a tool read', function () {
    $issued = app(IssueApiKey::class)->handle('Pi-Bolt');

    mcpPost([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new stdClass,
            'clientInfo' => ['name' => 'Pi-Bolt', 'version' => '1.4.0'],
        ],
    ], 'initialize', secret: $issued->secret)->assertOk();

    expect($issued->apiKey->fresh()->last_mcp_request_at?->toIso8601String())->toBe('2026-10-26T12:00:00+00:00');
    $this->assertDatabaseCount('api_usages', 0);
});

it('does not signal a connection for missing or revoked MCP credentials', function () {
    $issued = app(IssueApiKey::class)->handle('Pi-Bolt');

    mcpPost(listToolsBody())->assertUnauthorized();
    $issued->apiKey->forceFill(['revoked_at' => now()])->save();
    mcpPost(listToolsBody(), secret: $issued->secret)->assertUnauthorized();

    expect($issued->apiKey->fresh()->last_mcp_request_at)->toBeNull();
});

it('signals authenticated MCP traffic even if the key lacks tool-read scope', function () {
    $issued = app(IssueApiKey::class)->handle('Pi-Bolt', scopes: [Scope::KeysManage]);

    mcpPost(listToolsBody(), secret: $issued->secret)->assertForbidden();

    expect($issued->apiKey->fresh()->last_mcp_request_at?->toIso8601String())->toBe('2026-10-26T12:00:00+00:00');
});

it('does not signal an MCP connection for REST reads with the same key', function (string $surface) {
    $issued = app(IssueApiKey::class)->handle('Pi-Bolt');

    if ($surface === 'v0') {
        $this->getJson('/api/v0/ads?api_key='.$issued->secret)->assertOk();
    } else {
        $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer '.$issued->secret])->assertOk();
    }

    expect($issued->apiKey->fresh()->last_mcp_request_at)->toBeNull();
    $this->assertDatabaseCount('api_usages', 1);
})->with(['v0', 'v1']);
