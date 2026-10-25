<?php

use App\Actions\IssueApiKey;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/McpHelpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    config()->set('services.mcp.rest_base_url', 'https://rest.example');
    Http::preventStrayRequests();
    $this->bearer = app(IssueApiKey::class)->handle('getAd')->secret;
});

/**
 * @return array{text: string, isError: bool, json: array<string, mixed>}
 */
function getAd(array $arguments, string $secret, array $headers = []): array
{
    $response = mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_ad', 'arguments' => (object) $arguments]],
        'tools/call',
        ['Mcp-Name' => 'get_ad', ...$headers],
        $secret,
    )->assertOk();

    $text = (string) $response->json('result.content.0.text');

    return ['text' => $text, 'isError' => (bool) $response->json('result.isError'), 'json' => json_decode($text, true) ?? []];
}

function adPage(array $overrides = []): array
{
    return [
        'data' => [
            'id' => 7, 'brand_id' => 3, 'brand' => 'Acme', 'headline' => 'Headline', 'body_text' => 'Body text',
            'niche' => 'skincare', 'format' => 'image', 'status' => 'active', 'start_date' => '2026-10-01', 'end_date' => null,
            'days_active' => 24, 'impression_rank' => 2, 'countries' => ['DE', 'US'], 'discovered_at' => '2026-10-02T00:00:00+00:00',
        ],
        'body_offset' => 0, 'body_has_more' => false, 'body_next_offset' => null, 'body_total_characters' => 9,
        ...$overrides,
    ];
}

it('maps an id with no optional arguments to the bare detail URL', function () {
    Http::fake(['rest.example/api/v1/ads/7*' => Http::response(adPage())]);

    $result = getAd(['id' => 7], $this->bearer);

    expect($result['isError'])->toBeFalse()
        ->and($result['json']['data']['brand'])->toBe('Acme');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://rest.example/api/v1/ads/7');
});

it('passes body_offset and body_limit through and omits defaults', function () {
    Http::fake(['rest.example/api/v1/ads/7*' => Http::response(adPage(['body_offset' => 3, 'body_has_more' => true, 'body_next_offset' => 13]))]);

    $result = getAd(['id' => 7, 'body_offset' => 3, 'body_limit' => 10], $this->bearer);

    expect($result['json']['body_next_offset'])->toBe(13);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://rest.example/api/v1/ads/7?body_offset=3&body_limit=10');
});

it('returns the paging metadata so a caller can walk the whole body', function () {
    Http::fake(['rest.example/api/v1/ads/7*' => Http::response(adPage(['body_has_more' => true, 'body_next_offset' => 1000, 'body_total_characters' => 1500]))]);

    $result = getAd(['id' => 7], $this->bearer);

    expect($result['json'])->toHaveKeys(['data', 'body_offset', 'body_has_more', 'body_next_offset', 'body_total_characters'])
        ->and($result['json']['body_has_more'])->toBeTrue();
});

it('names search_ads when the ad does not exist', function () {
    Http::fake(['rest.example/api/v1/ads/999*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'The requested resource was not found.', 'next_step' => 'Check the request path.', 'retryable' => false]], 404)]);

    $result = getAd(['id' => 999], $this->bearer);

    expect($result['isError'])->toBeTrue()
        ->and($result['json']['error']['code'])->toBe('not_found')
        ->and($result['json']['error']['next_step'])->toContain('search_ads');
});

it('rejects a string id, a zero id and an unknown argument locally', function (array $arguments, string $code) {
    Http::fake();

    $result = getAd($arguments, $this->bearer);

    expect($result['isError'])->toBeTrue()
        ->and($result['json']['error']['code'])->toBe($code);

    Http::assertNothingSent();
})->with([
    'string id' => [['id' => '7'], 'invalid_parameter'],
    'zero id' => [['id' => 0], 'invalid_parameter'],
    'missing id' => [[], 'invalid_parameter'],
    'negative offset' => [['id' => 7, 'body_offset' => -1], 'invalid_parameter'],
    'limit over 1000' => [['id' => 7, 'body_limit' => 1001], 'invalid_parameter'],
    'float limit' => [['id' => 7, 'body_limit' => 10.5], 'invalid_parameter'],
    'unknown argument' => [['id' => 7, 'body' => 'x'], 'unknown_parameter'],
]);

it('keeps a maximum-size control-character page under the 14 KiB payload bound', function () {
    $body = str_repeat("\x01", 1000);
    Http::fake(['rest.example/api/v1/ads/7*' => Http::response(adPage(['body_text' => $body, 'body_total_characters' => 1000]))]);

    $response = mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_ad', 'arguments' => ['id' => 7]]],
        'tools/call',
        ['Mcp-Name' => 'get_ad'],
        $this->bearer,
    );

    expect(strlen((string) $response->json('result.content.0.text')))->toBeLessThanOrEqual(14336)
        ->and(strlen($response->getContent()))->toBeLessThanOrEqual(32768);
});

it('answers an oversized REST payload with a bounded internal_error', function () {
    Http::fake(['rest.example/api/v1/ads/7*' => Http::response(adPage(['body_text' => str_repeat('a', 20000)]))]);

    $result = getAd(['id' => 7], $this->bearer);

    expect($result['isError'])->toBeTrue()
        ->and($result['json']['error']['code'])->toBe('internal_error')
        ->and(strlen($result['text']))->toBeLessThanOrEqual(14336);
});
