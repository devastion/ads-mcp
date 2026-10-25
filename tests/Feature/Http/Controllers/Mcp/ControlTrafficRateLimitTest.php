<?php

use App\Actions\IssueApiKey;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/McpHelpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    $this->issued = app(IssueApiKey::class)->handle('control');
});

/**
 * @return array{0: array<string, mixed>, 1: array<string, string>}
 */
function controlRequest(string $kind): array
{
    return match ($kind) {
        'initialize' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'test', 'version' => '1']]], ['Mcp-Method' => 'initialize']],
        'tools/list' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass], ['Mcp-Method' => 'tools/list']],
        'ping' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Mcp-Method' => 'ping']],
        'unknown tool' => [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nope', 'arguments' => new stdClass]], ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'nope']],
    };
}

function sendControl(array $body, array $headers, string $secret): TestResponse
{
    return test()->call('POST', MCP_PATH, server: mcpServer($headers, $secret), content: json_encode($body));
}

it('debits each control request exactly once', function (string $kind) {
    [$body, $headers] = controlRequest($kind);

    foreach (range(1, 60) as $_) {
        expect(sendControl($body, $headers, $this->issued->secret)->status())->not->toBe(429);
    }

    sendControl($body, $headers, $this->issued->secret)->assertStatus(429);
})->with(['initialize', 'tools/list', 'ping', 'unknown tool']);

it('charges notifications nothing', function () {
    $notification = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

    foreach (range(1, 100) as $_) {
        expect(sendControl(json_decode($notification, true), ['Mcp-Method' => 'notifications/initialized'], $this->issued->secret)->status())->not->toBe(429);
    }

    mcpPost(listToolsBody(), secret: $this->issued->secret)->assertOk();
});

it('answers the 61st control request with 429, Retry-After and a bounded actionable body', function () {
    foreach (range(1, 60) as $_) {
        mcpPost(listToolsBody(), secret: $this->issued->secret)->assertOk();
    }

    $this->travelTo(Carbon::parse('2026-10-25 12:00:20', 'UTC'));

    mcpPost(listToolsBody(), secret: $this->issued->secret)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '40')
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.retryable', true)
        ->assertJsonPath('error.details.retry_after_seconds', 40);
});

it('writes no usage rows for control traffic', function () {
    foreach (range(1, 5) as $_) {
        mcpPost(listToolsBody(), secret: $this->issued->secret)->assertOk();
    }

    expect(ApiUsage::count())->toBe(0);
});

it('lists the valid tools for an unknown tool without echoing the name', function () {
    $response = mcpPost(
        ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'drop_everything', 'arguments' => new stdClass]],
        'tools/call',
        ['Mcp-Name' => 'drop_everything'],
        $this->issued->secret,
    );

    $response->assertStatus(400)
        ->assertJsonPath('error.code', -32602)
        ->assertJsonPath('id', 7);

    expect($response->json('error.message'))->toContain('search_ads, get_ad, list_niches')
        ->and($response->getContent())->not->toContain('drop_everything');
});
