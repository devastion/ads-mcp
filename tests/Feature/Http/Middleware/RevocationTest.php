<?php

use App\Actions\IssueApiKey;
use App\Actions\RevokeApiKey;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('fails a revoked key on its very next v1 call', function () {
    $key = app(IssueApiKey::class)->handle('v1 key');
    $headers = ['Authorization' => 'Bearer '.$key->secret];

    $this->getJson('/api/v1/niches', $headers)->assertOk();

    app(RevokeApiKey::class)->handle($key->apiKey);

    $this->getJson('/api/v1/niches', $headers)
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_invalid');
});

it('fails a revoked key on its very next v0 call with the frozen body', function () {
    $key = app(IssueApiKey::class)->handle('v0 key');

    $this->getJson('/api/v0/ads?api_key='.$key->secret)->assertOk();

    app(RevokeApiKey::class)->handle($key->apiKey);

    $this->getJson('/api/v0/ads?api_key='.$key->secret)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});

it('records last_used_at on success and not on failure', function () {
    $key = app(IssueApiKey::class)->handle('tracked');

    $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    expect($key->apiKey->fresh()->last_used_at)->toBeNull();

    $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer '.$key->secret])->assertOk();
    expect($key->apiKey->fresh()->last_used_at->toDateTimeString())->toBe('2026-10-25 12:00:00');

    $this->travelTo(Carbon::parse('2026-10-25 12:05:00'));
    $this->getJson('/api/v0/ads?api_key='.$key->secret)->assertOk();
    expect($key->apiKey->fresh()->last_used_at->toDateTimeString())->toBe('2026-10-25 12:05:00');
});
