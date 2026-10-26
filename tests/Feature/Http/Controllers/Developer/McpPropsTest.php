<?php

use App\Actions\IssueApiKey;
use App\Actions\TouchMcpRequest;
use App\Models\ApiKey;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    asLocal();
    $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
});

it('provides a secret-free Pi-Bolt user config and no connection before a key exists', function () {
    $issued = app(IssueApiKey::class)->handle('Other client');

    $response = $this->get('/developer')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('mcp.client', 'Pi-Bolt')
        ->where('mcp.endpoint_url', url('/api/mcp'))
        ->where('mcp.connection', null)
        ->where('mcp.config_template', function (string $template): bool {
            return json_decode($template, true, flags: JSON_THROW_ON_ERROR) === ['mcpServers' => ['ads' => [
                'url' => url('/api/mcp'),
                'headers' => ['Authorization' => 'Bearer <YOUR_KEY>'],
            ]]];
        }));

    expect($response->getContent())->not->toContain($issued->secret, $issued->apiKey->key_hash);
});

it('selects the newest active Pi-Bolt key and reflects its MCP timestamp in both props', function () {
    ApiKey::factory()->create(['name' => 'Pi-Bolt']);
    $current = ApiKey::factory()->create(['name' => 'Pi-Bolt']);
    ApiKey::factory()->create(['name' => 'Pi-Bolt', 'revoked_at' => now()]);
    ApiKey::factory()->create(['name' => 'Other client']);
    app(TouchMcpRequest::class)->handle($current);

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('mcp.connection', [
            'key_id' => $current->id,
            'prefix' => $current->prefix,
            'connected_at' => '2026-10-26T12:00:00+00:00',
        ])
        ->where('keys.2.last_mcp_request_at', '2026-10-26T12:00:00+00:00'));
});

it('resumes waiting after a reload and returns to setup after the last Pi-Bolt key is revoked', function () {
    $key = ApiKey::factory()->create(['name' => 'Pi-Bolt']);

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('mcp.connection.key_id', $key->id)
        ->where('mcp.connection.connected_at', null));

    $key->forceFill(['revoked_at' => now()])->save();

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page->where('mcp.connection', null));
});

it('returns only the requested connection props on a polling reload', function () {
    ApiKey::factory()->create(['name' => 'Pi-Bolt']);
    $version = $this->get('/developer')->viewData('page')['version'];

    $this->get('/developer', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'developer',
        'X-Inertia-Partial-Data' => 'keys,mcp',
    ])->assertOk()->assertJsonMissingPath('props.usage')->assertJsonPath('props.mcp.client', 'Pi-Bolt');
});
