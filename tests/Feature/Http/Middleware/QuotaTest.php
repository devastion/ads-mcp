<?php

use App\Actions\IssueApiKey;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    $this->issued = app(IssueApiKey::class)->handle('quota');
    $this->bearer = ['Authorization' => 'Bearer '.$this->issued->secret];

    ApiUsage::factory()->count(1000)->state(['api_key_id' => $this->issued->apiKey->id])->create();
});

it('refuses a v1 read over quota with a distinct non-retryable error and writes no row', function () {
    $this->getJson('/api/v1/niches', $this->bearer)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'quota_exceeded')
        ->assertJsonPath('error.retryable', false)
        ->assertJsonPath('error.details.resets_at', '2026-11-01T00:00:00+00:00');

    expect(ApiUsage::count())->toBe(1000);
});

it('refuses a v0 read over quota with the frozen 400', function () {
    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});

it('lets an already billed idempotency key through unbilled', function () {
    ApiUsage::factory()->create(['api_key_id' => $this->issued->apiKey->id, 'idempotency_hash' => hash('sha256', 'paid')]);

    $this->getJson('/api/v1/niches', [...$this->bearer, 'Idempotency-Key' => 'paid'])->assertOk();

    expect(ApiUsage::where('billed', false)->count())->toBe(1);
});
