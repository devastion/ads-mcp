<?php

/**
 * The changes Part 3 sanctions on v0 (.agents/skills/v0-contract-guard/SKILL.md). The frozen contract stays
 * in AdControllerTest.php; this file only pins what Part 3 adds, and keeps the single 400 body for every failure.
 */

use App\Actions\IssueApiKey;
use App\Actions\RevokeApiKey;
use App\Enums\Scope;
use App\Models\Ad;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    $this->issued = app(IssueApiKey::class)->handle('v0 part 3');
});

it('returns a metered response body identical to what the unmetered contract promises', function () {
    Ad::factory()->create();

    $response = $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'page', 'total'])
        ->and(ApiUsage::where('surface', 'v0')->where('billed', true)->count())->toBe(1);
});

it('fails a revoked key on its next call with the single 400 body', function () {
    app(RevokeApiKey::class)->handle($this->issued->apiKey);

    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});

it('answers rate limiting with the 400 body and a Retry-After header, never a 429', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)->assertOk();
    }

    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request'])
        ->assertHeader('Retry-After', '60');
});

it('answers a key without search:read and an over-quota key with the same 400 body', function () {
    $managing = app(IssueApiKey::class)->handle('manage only', null, [Scope::KeysManage]);
    $this->getJson('/api/v0/ads?api_key='.$managing->secret)->assertStatus(400)->assertExactJson(['error' => 'Invalid request']);

    ApiUsage::factory()->count(1000)->state(['api_key_id' => $this->issued->apiKey->id])->create();
    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)->assertStatus(400)->assertExactJson(['error' => 'Invalid request']);
});
