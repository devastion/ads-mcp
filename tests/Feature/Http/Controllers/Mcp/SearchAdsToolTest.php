<?php

use App\Actions\IssueApiKey;
use App\Models\ApiUsage;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/McpHelpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    config()->set('services.mcp.rest_base_url', 'https://rest.example');
    Http::preventStrayRequests();
    $this->bearer = app(IssueApiKey::class)->handle('search')->secret;
});

/**
 * @return array{text: string, isError: bool, json: array<string, mixed>}
 */
function searchAds(array $arguments, string $secret): array
{
    $response = mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_ads', 'arguments' => (object) $arguments]],
        'tools/call',
        ['Mcp-Name' => 'search_ads'],
        $secret,
    )->assertOk();

    $text = (string) $response->json('result.content.0.text');

    return ['text' => $text, 'isError' => (bool) $response->json('result.isError'), 'json' => json_decode($text, true) ?? []];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function restSearchPage(array $overrides = []): array
{
    $ads = [];

    for ($id = 1; $id <= 2; $id++) {
        $ads[] = ['id' => $id, 'brand' => 'Brand '.$id, 'headline' => 'Headline '.$id, 'niche' => 'skincare', 'format' => 'image', 'status' => 'active', 'start_date' => '2026-10-01', 'days_active' => 24];
    }

    return [
        'data' => $ads,
        'applied_filters' => ['q' => null, 'niche' => [], 'format' => null, 'status' => null, 'started_after' => null, 'sort' => 'newest'],
        'has_more' => false,
        'next_cursor' => null,
        ...$overrides,
    ];
}

function restUrl(string $query = ''): string
{
    return 'https://rest.example/api/v1/ads'.($query === '' ? '' : '?'.$query);
}

it('maps the arguments to the exact encoded REST query with repeated niche and per_page', function () {
    Http::fake([restUrl('*') => Http::response(restSearchPage())]);

    searchAds(['q' => '  glow  ', 'niche' => ['skincare', 'haircare'], 'format' => 'video', 'status' => 'active', 'started_after' => '2026-09-30', 'sort' => 'impressions', 'limit' => 7, 'cursor' => 'abc.DEF_123'], $this->bearer);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === restUrl('q=glow&niche=skincare&niche=haircare&format=video&status=active&started_after=2026-09-30&sort=impressions&per_page=7&cursor=abc.DEF_123'));
});

it('sends per_page 5 when limit is omitted and no bracket syntax', function () {
    Http::fake([restUrl('*') => Http::response(restSearchPage())]);

    searchAds([], $this->bearer);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === restUrl('per_page=5')
        && ! str_contains($request->url(), '%5B')
        && ! str_contains($request->url(), '['));
});

it('rejects unknown, misspelled and idempotency arguments without echoing the value', function (array $arguments, string $code) {
    Http::fake();

    $result = searchAds($arguments, $this->bearer);

    expect($result['isError'])->toBeTrue()
        ->and($result['json']['error']['code'])->toBe($code)
        ->and($result['text'])->not->toContain('secret-value');

    Http::assertNothingSent();
})->with([
    'misspelled niche' => [['nich' => ['skincare']], 'unknown_parameter'],
    'REST per_page name' => [['per_page' => 5], 'unknown_parameter'],
    'idempotency as argument' => [['idempotency_key' => 'secret-value'], 'unknown_parameter'],
]);

it('suggests the niche parameter for a misspelled argument name', function () {
    Http::fake();

    $result = searchAds(['nich' => ['skincare']], $this->bearer);

    expect($result['json']['error']['details']['did_you_mean'])->toBe('niche');
});

it('rejects out-of-range limits and wrong types instead of clamping', function (array $arguments) {
    Http::fake();

    $result = searchAds($arguments, $this->bearer);

    expect($result['isError'])->toBeTrue()
        ->and($result['json']['error']['code'])->toBe('invalid_parameter');

    Http::assertNothingSent();
})->with([
    'limit 11' => [['limit' => 11]],
    'limit 0' => [['limit' => 0]],
    'limit string' => [['limit' => '5']],
    'empty niche list' => [['niche' => []]],
    'display name niche' => [['niche' => ['Skincare']]],
    'q trimmed to one char' => [['q' => '  a  ']],
    'q 201 chars' => [['q' => str_repeat('a', 201)]],
    'impossible date' => [['started_after' => '2026-02-30']],
    'unknown format' => [['format' => 'gif']],
    'unknown sort' => [['sort' => 'random']],
    'cursor empty' => [['cursor' => '']],
]);

it('keeps every fetched row and the filters, cursor and notes intact while compacting previews', function () {
    $ads = [];

    for ($id = 1; $id <= 10; $id++) {
        $ads[] = ['id' => $id, 'brand' => str_repeat('B😀"\\', 80), 'headline' => str_repeat("H\x01é\"", 200), 'niche' => 'skincare', 'format' => 'image', 'status' => 'active', 'start_date' => '2026-10-01', 'days_active' => 24];
    }

    Http::fake([restUrl('*') => Http::response(restSearchPage([
        'data' => $ads,
        'applied_filters' => ['q' => 'glow', 'niche' => ['skincare'], 'format' => null, 'status' => null, 'started_after' => null, 'sort' => 'impressions'],
        'has_more' => true,
        'next_cursor' => 'cursor-token-123',
        'notes' => ['Impression rank is brand-relative: it orders ads within a brand, not across brands.'],
    ]))]);

    $result = searchAds(['limit' => 10, 'sort' => 'impressions'], $this->bearer);

    expect($result['isError'])->toBeFalse()
        ->and($result['json']['data'])->toHaveCount(10)
        ->and($result['json']['next_cursor'])->toBe('cursor-token-123')
        ->and($result['json']['has_more'])->toBeTrue()
        ->and($result['json']['applied_filters']['q'])->toBe('glow')
        ->and($result['json']['detail_tool'])->toBe('get_ad')
        ->and($result['json']['notes'])->toHaveCount(1);

    foreach ($result['json']['data'] as $item) {
        expect(strlen(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))->toBeLessThanOrEqual(1024)
            ->and($item['truncated_fields'])->toContain('brand', 'headline');
    }

    expect(strlen($result['text']))->toBeLessThanOrEqual(14336);
});

it('leaves short items untouched and marks no truncated fields', function () {
    Http::fake([restUrl('*') => Http::response(restSearchPage())]);

    $result = searchAds([], $this->bearer);

    expect($result['json']['data'][0])->toBe(['id' => 1, 'brand' => 'Brand 1', 'headline' => 'Headline 1', 'niche' => 'skincare', 'format' => 'image', 'status' => 'active', 'start_date' => '2026-10-01', 'days_active' => 24]);
});

it('answers an oversized REST metadata block with a bounded internal_error', function () {
    Http::fake([restUrl('*') => Http::response(restSearchPage(['next_cursor' => str_repeat('c', 5000), 'has_more' => true]))]);

    $result = searchAds([], $this->bearer);

    expect($result['isError'])->toBeTrue()
        ->and($result['json']['error']['code'])->toBe('internal_error')
        ->and(strlen($result['text']))->toBeLessThanOrEqual(14336);
});

it('translates an invalid cursor and offers a restart call without the cursor', function () {
    Http::fake([restUrl('*') => Http::response(['error' => ['code' => 'invalid_cursor', 'message' => 'The cursor is not valid.', 'next_step' => 'Request a fresh cursor and try again.', 'retryable' => false]], 422)]);

    $result = searchAds(['q' => 'glow', 'cursor' => 'tampered'], $this->bearer);

    expect($result['json']['error']['code'])->toBe('invalid_cursor')
        ->and($result['json']['error']['details']['corrected_call'])->toBe(['name' => 'search_ads', 'arguments' => ['q' => 'glow']]);
});

it('reports a cursor used with different filters as cursor_mismatch', function () {
    Http::fake([restUrl('*') => Http::response(['error' => ['code' => 'cursor_mismatch', 'message' => 'The cursor was issued for a different filter or sort.', 'next_step' => 'Request a fresh cursor and try again.', 'retryable' => false]], 422)]);

    $result = searchAds(['cursor' => 'abc', 'status' => 'inactive'], $this->bearer);

    expect($result['json']['error']['code'])->toBe('cursor_mismatch')
        ->and($result['json']['error']['next_step'])->toContain('filters');
});

it('suggests the exact niche slug only when the correction is deterministic', function () {
    Http::fake();

    $result = searchAds(['niche' => ['skincare ']], $this->bearer);

    expect($result['json']['error']['code'])->toBe('invalid_parameter')
        ->and($result['json']['error']['details']['corrected_call'])->toBe(['name' => 'search_ads', 'arguments' => ['niche' => ['skincare']]]);

    Http::assertNothingSent();
});

it('charges a rejected search call once against the shared limit and writes no usage row', function () {
    Http::fake([restUrl('*') => Http::response(restSearchPage())]);

    foreach (range(1, 60) as $_) {
        searchAds(['limit' => 0], $this->bearer);
    }

    mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'search_ads', 'arguments' => ['limit' => 0]]],
        'tools/call',
        ['Mcp-Name' => 'search_ads'],
        $this->bearer,
    )->assertStatus(429);

    expect(ApiUsage::count())->toBe(0);
});

it('offers a restart call whose arguments are a JSON object even when no other argument remains', function () {
    Http::fake([restUrl('*') => Http::response(['error' => ['code' => 'invalid_cursor', 'message' => 'The cursor is not valid.', 'next_step' => 'Request a fresh cursor and try again.', 'retryable' => false]], 422)]);

    $result = searchAds(['cursor' => 'tampered'], $this->bearer);

    expect($result['text'])->toContain('"arguments":{}')
        ->and($result['text'])->not->toContain('"arguments":[]');
});
