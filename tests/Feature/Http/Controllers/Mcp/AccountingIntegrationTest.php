<?php

use App\Actions\IssueApiKey;
use App\Models\Ad;
use App\Models\ApiUsage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/McpHelpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    config()->set('services.mcp.rest_base_url', 'https://rest.example');
    fakeRestLoopback();
    $this->issued = app(IssueApiKey::class)->handle('accounting');
    $this->bearer = $this->issued->secret;
});

function mcpListNiches(string $secret, array $headers = []): TestResponse
{
    return mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_niches', 'arguments' => new stdClass]],
        'tools/call',
        ['Mcp-Name' => 'list_niches', ...$headers],
        $secret,
    );
}

it('bills one forwarded tool call as exactly one usage row and charges one rate unit', function () {
    mcpListNiches($this->bearer)->assertOk()->assertJsonPath('result.isError', false);

    expect(ApiUsage::count())->toBe(1)
        ->and(ApiUsage::first()->endpoint)->toBe('api.v1.niches');

    foreach (range(2, 60) as $_) {
        mcpListNiches($this->bearer)->assertOk();
    }

    mcpListNiches($this->bearer)
        ->assertOk()
        ->assertJsonPath('result.isError', true);
});

it('shares one 60-per-minute window between REST v0, REST v1 and MCP', function () {
    foreach (range(1, 20) as $_) {
        $this->getJson('/api/v0/ads?api_key='.$this->bearer)->assertOk();
        $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer '.$this->bearer])->assertOk();
        mcpListNiches($this->bearer)->assertOk();
    }

    $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer '.$this->bearer])->assertStatus(429);
});

it('surfaces REST rate denial inside a forwarded call as rate_limited and writes no row for the denied call', function () {
    foreach (range(1, 60) as $_) {
        mcpListNiches($this->bearer)->assertOk();
    }

    $response = mcpListNiches($this->bearer)
        ->assertOk()
        ->assertJsonPath('result.isError', true);

    expect(ApiUsage::count())->toBe(60)
        ->and(json_decode($response->json('result.content.0.text'), true)['error']['code'])->toBe('rate_limited');
});

it('bills an empty search as a successful billable read with result_count 0', function () {
    Ad::factory()->create(['niche' => 'skincare']);

    mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_ads', 'arguments' => ['q' => 'zzzzzz-no-match']]],
        'tools/call',
        ['Mcp-Name' => 'search_ads'],
        $this->bearer,
    )->assertOk()->assertJsonPath('result.isError', false);

    expect(ApiUsage::where('billed', true)->where('result_count', 0)->count())->toBe(1);
});

it('does not bill a REST not-found reached through get_ad', function () {
    mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_ad', 'arguments' => ['id' => 999999]]],
        'tools/call',
        ['Mcp-Name' => 'get_ad'],
        $this->bearer,
    )->assertOk()->assertJsonPath('result.isError', true);

    expect(ApiUsage::count())->toBe(0);
});

it('bills a retry that reuses the Idempotency-Key from REST and then MCP only once', function () {
    $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer '.$this->bearer, 'Idempotency-Key' => 'same-call'])->assertOk();

    mcpListNiches($this->bearer, ['Idempotency-Key' => 'same-call'])->assertOk();

    expect(ApiUsage::where('billed', true)->count())->toBe(1)
        ->and(ApiUsage::where('billed', false)->count())->toBe(1);
});

it('bills repeated calls without an Idempotency-Key every time', function () {
    mcpListNiches($this->bearer)->assertOk();
    mcpListNiches($this->bearer)->assertOk();

    expect(ApiUsage::where('billed', true)->count())->toBe(2);
});

it('forwards the caller Idempotency-Key unchanged and never invents one', function () {
    Http::preventStrayRequests();
    Http::fake(['rest.example/api/v1/niches*' => Http::response(['data' => []])]);

    mcpListNiches($this->bearer)->assertOk();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Idempotency-Key'));
});
