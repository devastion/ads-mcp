<?php

use App\Actions\IssueApiKey;
use App\Enums\Niche;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/McpHelpers.php';

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    config()->set('services.mcp.rest_base_url', 'https://rest.example');
    Http::preventStrayRequests();
    $this->issued = app(IssueApiKey::class)->handle('nichesTool');
    $this->bearer = $this->issued->secret;
});

/**
 * @param  array<string, mixed>  $arguments
 * @param  array<string, string>  $headers
 */
function callTool(string $name, array $arguments, array $headers, string $secret): TestResponse
{
    return mcpPost(
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]],
        'tools/call',
        ['Mcp-Name' => $name, ...$headers],
        $secret,
    );
}

/**
 * @return array{text: string, isError: bool}
 */
function toolResult(TestResponse $response): array
{
    return ['text' => $response->json('result.content.0.text'), 'isError' => $response->json('result.isError') ?? false];
}

it('returns the full niche catalogue as one compact text block', function () {
    Http::fake(['rest.example/api/v1/niches*' => Http::response(['data' => array_map(fn (Niche $niche): array => ['slug' => $niche->value, 'name' => $niche->displayName()], Niche::cases())])]);

    $response = callTool('list_niches', [], [], $this->bearer)->assertOk();
    $result = toolResult($response);

    expect($result['isError'])->toBeFalse()
        ->and(json_decode($result['text'], true)['data'])->toHaveCount(12)
        ->and(json_decode($result['text'], true)['data'][0])->toBe(['slug' => 'skincare', 'name' => Niche::Skincare->displayName()])
        ->and(strlen($result['text']))->toBeLessThanOrEqual(2048)
        ->and($response->json('result.content'))->toHaveCount(1);
});

it('forwards only the Bearer key and the Idempotency-Key header to REST', function () {
    Http::fake(['rest.example/api/v1/niches*' => Http::response(['data' => []])]);

    callTool('list_niches', [], ['Idempotency-Key' => 'call-1', 'X-Forwarded-Host' => 'evil.example', 'Cookie' => 'session=secret'], $this->bearer)->assertOk();

    Http::assertSent(function (HttpRequest $request): bool {
        return $request->hasHeader('Authorization', 'Bearer '.$this->bearer)
            && $request->hasHeader('Idempotency-Key', 'call-1')
            && ! $request->hasHeader('X-Forwarded-Host')
            && ! $request->hasHeader('Cookie')
            && $request->url() === 'https://rest.example/api/v1/niches';
    });
});

it('rejects any argument for list_niches with unknown_parameter and no REST call', function () {
    Http::fake();

    $result = toolResult(callTool('list_niches', ['q' => 'skincare'], [], $this->bearer));

    expect($result['isError'])->toBeTrue()
        ->and(json_decode($result['text'], true)['error']['code'])->toBe('unknown_parameter');

    Http::assertNothingSent();
});

it('charges a locally rejected list_niches call once against the shared limit', function () {
    foreach (range(1, 60) as $_) {
        callTool('list_niches', ['q' => 'x'], [], $this->bearer)->assertOk();
    }

    callTool('list_niches', ['q' => 'x'], [], $this->bearer)->assertStatus(429);
});

it('turns a REST rate denial into a tool error with an integer retry_after_seconds over HTTP 200', function () {
    Http::fake(['rest.example/api/v1/niches*' => Http::response(
        ['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.', 'next_step' => 'Wait.', 'retryable' => true, 'details' => ['retry_after_seconds' => 42]]],
        429,
        ['Retry-After' => '42'],
    )]);

    $response = callTool('list_niches', [], [], $this->bearer)->assertOk();
    $error = json_decode(toolResult($response)['text'], true)['error'];

    expect(toolResult($response)['isError'])->toBeTrue()
        ->and($error['code'])->toBe('rate_limited')
        ->and($error['retryable'])->toBeTrue()
        ->and($error['details']['retry_after_seconds'])->toBeInt()->toBe(42);
});

it('reports a REST timeout as internal_error with the possible-billing warning', function () {
    Http::fake(['rest.example/api/v1/niches*' => function () {
        throw new ConnectionException('Operation timed out');
    }]);

    $result = toolResult(callTool('list_niches', [], [], $this->bearer)->assertOk());
    $error = json_decode($result['text'], true)['error'];

    expect($result['isError'])->toBeTrue()
        ->and($error['code'])->toBe('internal_error')
        ->and($error['message'])->toContain('may already have been billed');
});

it('does not follow a REST redirect', function () {
    Http::fake(['rest.example/api/v1/niches*' => Http::response('', 302, ['Location' => 'https://evil.example/api/v1/niches'])]);

    $result = toolResult(callTool('list_niches', [], [], $this->bearer)->assertOk());

    expect($result['isError'])->toBeTrue()
        ->and(json_decode($result['text'], true)['error']['code'])->toBe('internal_error');

    Http::assertSentCount(1);
});
