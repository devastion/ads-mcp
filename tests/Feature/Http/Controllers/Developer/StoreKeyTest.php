<?php

use App\Models\ApiKey;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    asLocal();
    $this->withSession(['_token' => 'developer-test-token'])
        ->withHeader('X-CSRF-TOKEN', 'developer-test-token');
});

it('creates a key with a one-time secret and stores only its hash', function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));

    $response = $this->postJson('/developer/keys', [
        'name' => '  Local client  ',
        'scopes' => ['search:read', 'keys:manage'],
        'secret' => 'caller-chosen-secret',
        'revoked_at' => '2026-10-01T00:00:00Z',
    ])->assertCreated();

    $secret = $response->json('data.secret');
    expect($secret)->toBeString()->toStartWith('ghk_')->not->toBe('caller-chosen-secret');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    $key = ApiKey::query()->sole();
    $response->assertExactJson(['data' => [
        'id' => $key->id,
        'name' => 'Local client',
        'prefix' => substr($secret, 0, 12),
        'scopes' => ['search:read', 'keys:manage'],
        'created_at' => '2026-10-25T12:00:00+00:00',
        'last_used_at' => null,
        'revoked_at' => null,
        'last_mcp_request_at' => null,
        'secret' => $secret,
    ]]);
    expect($key->name)->toBe('Local client');
    expect($key->scopes)->toBe(['search:read', 'keys:manage']);
    expect($key->key_hash)->toBe(hash('sha256', $secret));
    expect(json_encode($key->getRawOriginal(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    $this->assertDatabaseCount('api_usages', 0);

    $page = $this->get('/developer')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('keys', 1)
        ->where('keys.0.id', $key->id)
        ->where('keys.0.name', 'Local client')
        ->where('keys.0.scopes', ['search:read', 'keys:manage'])
        ->missing('keys.0.secret')
        ->missing('keys.0.key_hash'));

    expect($page->getContent())->not->toContain($secret);
    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($secret);
});

it('creates a search-only key without adding management scope', function () {
    $this->postJson('/developer/keys', ['name' => str_repeat('a', 100), 'scopes' => ['search:read']])
        ->assertCreated()
        ->assertJsonPath('data.scopes', ['search:read']);

    expect(ApiKey::query()->sole()->scopes)->toBe(['search:read']);
});

it('rejects invalid creation input with 422 and does not write', function (array $payload, array $errors) {
    $this->postJson('/developer/keys', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    $this->assertDatabaseCount('api_keys', 0);
    $this->assertDatabaseCount('api_usages', 0);
})->with([
    'missing fields' => [[], ['name' => 'The name field is required.', 'scopes' => 'The scopes field is required.']],
    'empty name' => [['name' => '', 'scopes' => ['search:read']], ['name' => 'The name field is required.']],
    'blank name' => [['name' => '   ', 'scopes' => ['search:read']], ['name' => 'The name field is required.']],
    'non-string name' => [['name' => 123, 'scopes' => ['search:read']], ['name' => 'The name field must be a string.']],
    'long name' => [['name' => str_repeat('a', 101), 'scopes' => ['search:read']], ['name' => 'The name field must not be greater than 100 characters.']],
    'empty scopes' => [['name' => 'Client', 'scopes' => []], ['scopes' => 'The scopes field is required.']],
    'non-array scopes' => [['name' => 'Client', 'scopes' => 'search:read'], ['scopes' => 'The scopes field must be an array.']],
    'non-list scopes' => [['name' => 'Client', 'scopes' => ['scope' => 'search:read']], ['scopes' => 'The scopes field must be a list.']],
    'duplicate scopes' => [['name' => 'Client', 'scopes' => ['search:read', 'search:read']], ['scopes.0' => 'The scopes.0 field has a duplicate value.']],
    'unknown scope' => [['name' => 'Client', 'scopes' => ['unknown:read']], ['scopes.0' => 'The selected scopes.0 is invalid.']],
    'non-string scope' => [['name' => 'Client', 'scopes' => [123]], ['scopes.0' => 'The scopes.0 field must be a string.']],
]);

it('returns 404 without creating a key outside local', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    $this->postJson('/developer/keys', ['name' => 'Client', 'scopes' => ['search:read']])->assertNotFound();
    $this->postJson('/developer/keys', [])->assertNotFound();

    $this->assertDatabaseCount('api_keys', 0);
    $this->assertDatabaseCount('api_usages', 0);
})->with(['production', 'staging', 'testing']);
