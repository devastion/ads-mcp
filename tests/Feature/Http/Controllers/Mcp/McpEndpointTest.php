<?php

use App\Actions\IssueApiKey;
use App\Actions\RevokeApiKey;
use App\Enums\Scope;
use Illuminate\Support\Carbon;

require_once __DIR__.'/McpHelpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    config()->set('services.mcp.allowed_origins', ['https://client.example']);
    $this->issued = app(IssueApiKey::class)->handle('mcp');
});

it('answers initialize with the server identity and a negotiated protocol version', function () {
    $response = mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'test', 'version' => '1']]],
        'initialize',
        secret: $this->issued->secret,
    );

    $response->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-11-25')
        ->assertJsonPath('result.serverInfo.name', 'gethookd-ads');
});

it('lists exactly the three read-only tools by name', function () {
    $response = mcpPost(listToolsBody(), secret: $this->issued->secret)->assertOk();

    expect(collect($response->json('result.tools'))->pluck('name')->sort()->values()->all())
        ->toBe(['get_ad', 'list_niches', 'search_ads']);
});

it('rejects a request without a Bearer key with the shared api_key_missing code', function () {
    mcpPost(listToolsBody())
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_missing')
        ->assertJsonPath('error.retryable', false);
});

it('rejects an unknown Bearer key with api_key_invalid', function () {
    mcpPost(listToolsBody(), secret: 'ghk_not-a-real-key')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_invalid');
});

it('rejects a revoked key on its very next call', function () {
    mcpPost(listToolsBody(), secret: $this->issued->secret)->assertOk();

    app(RevokeApiKey::class)->handle($this->issued->apiKey);

    mcpPost(listToolsBody(), secret: $this->issued->secret)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_invalid');
});

it('rejects a key without search:read with insufficient_scope', function () {
    $managing = app(IssueApiKey::class)->handle('manage only', null, [Scope::KeysManage]);

    mcpPost(listToolsBody(), secret: $managing->secret)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope');
});

it('rejects a key sent in the query string', function () {
    mcpPost(listToolsBody(), secret: $this->issued->secret)->assertOk();

    mcpPost(listToolsBody(), secret: $this->issued->secret, uri: MCP_PATH.'?api_key='.$this->issued->secret)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_in_query');
});

it('rejects a batch array with a JSON-RPC invalid request error and no tool work', function () {
    mcpPost([listToolsBody(), listToolsBody()], secret: $this->issued->secret)
        ->assertOk()
        ->assertJsonPath('error.code', -32600);
});

it('answers malformed JSON with a JSON-RPC parse error', function () {
    mcpPost('{"jsonrpc":', secret: $this->issued->secret)
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32700);
});

it('rejects a disallowed Origin and accepts an allowed one', function () {
    mcpPost(listToolsBody(), headers: ['Origin' => 'https://evil.example'], secret: $this->issued->secret)
        ->assertStatus(403);

    mcpPost(listToolsBody(), headers: ['Origin' => 'https://client.example'], secret: $this->issued->secret)
        ->assertOk();
});

it('rejects an oversized string id without echoing it', function () {
    $oversized = str_repeat('x', 129);

    $response = mcpPost(['jsonrpc' => '2.0', 'id' => $oversized, 'method' => 'tools/list'], secret: $this->issued->secret)
        ->assertOk()
        ->assertJsonPath('error.code', -32600);

    expect($response->json('id'))->toBeNull()
        ->and($response->getContent())->not->toContain($oversized);
});

it('rejects a request body over the size bound', function () {
    $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['pad' => str_repeat('a', 70000)]]);

    mcpPost($body, secret: $this->issued->secret)
        ->assertOk()
        ->assertJsonPath('error.code', -32600)
        ->assertJsonMissing(['pad' => str_repeat('a', 100)]);
});
