<?php

use App\Actions\IssueApiKey;
use App\Models\ApiKey;
use Illuminate\Support\Carbon;

beforeEach(function () {
    asLocal();
    $this->withSession(['_token' => 'developer-test-token'])
        ->withHeader('X-CSRF-TOKEN', 'developer-test-token');
});

it('revokes a key with 204 and preserves the first revocation time on repeats', function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    $key = ApiKey::factory()->create();

    $this->deleteJson('/developer/keys/'.$key->id)->assertNoContent();

    expect($key->fresh()->revoked_at->toIso8601String())->toBe('2026-10-25T12:00:00+00:00');
    $this->assertDatabaseCount('api_usages', 0);
    $this->travel(10)->minutes();

    $this->deleteJson('/developer/keys/'.$key->id)->assertNoContent();

    expect($key->fresh()->revoked_at->toIso8601String())->toBe('2026-10-25T12:00:00+00:00');
    $this->assertDatabaseCount('api_keys', 1);
    $this->assertDatabaseCount('api_usages', 0);
});

it('returns 404 for an unknown key without writing', function () {
    $this->deleteJson('/developer/keys/999999')->assertNotFound();

    $this->assertDatabaseCount('api_keys', 0);
    $this->assertDatabaseCount('api_usages', 0);
});

it('immediately rejects the revoked key on its next v1 and v0 calls', function () {
    $issued = app(IssueApiKey::class)->handle('REST client');
    $headers = ['Authorization' => 'Bearer '.$issued->secret];
    $this->getJson('/api/v1/ads', $headers)->assertOk();
    $this->getJson('/api/v0/ads?api_key='.$issued->secret)->assertOk();
    $this->assertDatabaseCount('api_usages', 2);

    $this->deleteJson('/developer/keys/'.$issued->apiKey->id)->assertNoContent();

    $this->assertDatabaseCount('api_usages', 2);
    $this->getJson('/api/v1/ads', $headers)
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'api_key_invalid');
    $this->getJson('/api/v0/ads?api_key='.$issued->secret)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});

it('returns 404 without revoking a key outside local', function (string $environment) {
    $key = ApiKey::factory()->create();
    app()->detectEnvironment(fn (): string => $environment);

    $this->deleteJson('/developer/keys/'.$key->id)->assertNotFound();

    expect($key->fresh()->revoked_at)->toBeNull();
    $this->assertDatabaseCount('api_usages', 0);
})->with(['production', 'staging', 'testing']);
