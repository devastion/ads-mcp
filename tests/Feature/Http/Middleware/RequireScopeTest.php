<?php

use App\Actions\IssueApiKey;
use App\Enums\Scope;
use App\Models\Ad;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
    $this->manager = app(IssueApiKey::class)->handle('manage only', null, [Scope::KeysManage]);
    $this->bearer = ['Authorization' => 'Bearer '.$this->manager->secret];
});

it('refuses a key without search:read on every v1 data route', function (string $path) {
    $ad = Ad::factory()->create();

    $this->getJson(str_replace('{id}', (string) $ad->id, $path), $this->bearer)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'insufficient_scope')
        ->assertJsonPath('error.retryable', false)
        ->assertJsonPath('error.next_step', 'Use a key with the search:read scope.');
})->with(['/api/v1/ads', '/api/v1/ads/{id}', '/api/v1/niches']);

it('refuses a key without search:read on v0 with the frozen 400', function () {
    $this->getJson('/api/v0/ads?api_key='.$this->manager->secret)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});

it('still spends a rate unit on a scope-denied call', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/niches', $this->bearer)->assertStatus(403);
    }

    $this->getJson('/api/v1/niches', $this->bearer)->assertStatus(429);
});

it('lets a key with both scopes read', function () {
    $both = app(IssueApiKey::class)->handle('both', null, [Scope::SearchRead, Scope::KeysManage]);

    $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer '.$both->secret])->assertOk();
    $this->getJson('/api/v0/ads?api_key='.$both->secret)->assertOk();
});
