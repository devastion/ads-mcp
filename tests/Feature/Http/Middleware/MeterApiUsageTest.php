<?php

use App\Actions\IssueApiKey;
use App\Actions\RecordApiUsage;
use App\Models\Ad;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
    $this->issued = app(IssueApiKey::class)->handle('metered');
    $this->bearer = ['Authorization' => 'Bearer '.$this->issued->secret];
});

it('bills one row for each billable read, including an empty search', function () {
    $ad = Ad::factory()->create();

    $this->getJson('/api/v1/ads?q=zzzznothing', $this->bearer)->assertOk()->assertJsonPath('data', []);
    $this->getJson('/api/v1/ads', $this->bearer)->assertOk();
    $this->getJson('/api/v1/ads/'.$ad->id, $this->bearer)->assertOk();
    $this->getJson('/api/v1/niches', $this->bearer)->assertOk();
    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)->assertOk();

    $rows = ApiUsage::orderBy('id')->get();

    expect($rows->pluck('endpoint')->all())->toBe(['api.v1.ads', 'api.v1.ads', 'api.v1.ads.show', 'api.v1.niches', 'api.v0.ads'])
        ->and($rows->pluck('surface')->all())->toBe(['v1', 'v1', 'v1', 'v1', 'v0'])
        ->and($rows->pluck('result_count')->all())->toBe([0, 1, 1, 12, 1])
        ->and($rows->every(fn (ApiUsage $row): bool => $row->billed && $row->status === 200 && $row->latency_ms >= 0))->toBeTrue();
});

it('does not bill validation errors, unknown ads, rate limits or bad credentials', function () {
    $this->getJson('/api/v1/ads?nope=1', $this->bearer)->assertStatus(422);
    $this->getJson('/api/v1/ads/999999', $this->bearer)->assertStatus(404);
    $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    $this->getJson('/api/v0/ads?api_key=wrong')->assertStatus(400);

    expect(ApiUsage::count())->toBe(0);
});

it('does not bill a rate-limited call', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/niches', $this->bearer)->assertOk();
    }

    $this->getJson('/api/v1/niches', $this->bearer)->assertStatus(429);

    expect(ApiUsage::count())->toBe(60);
});

it('bills a retried Idempotency-Key once across v0 and v1', function () {
    $headers = [...$this->bearer, 'Idempotency-Key' => 'abc-123'];

    $this->getJson('/api/v1/niches', $headers)->assertOk();
    $this->getJson('/api/v1/ads', $headers)->assertOk();
    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret, ['Idempotency-Key' => 'abc-123'])->assertOk();

    expect(ApiUsage::where('billed', true)->count())->toBe(1)
        ->and(ApiUsage::where('billed', false)->count())->toBe(2);
});

it('rejects an invalid Idempotency-Key and writes nothing', function (string $value) {
    $this->getJson('/api/v1/niches', [...$this->bearer, 'Idempotency-Key' => $value])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'Idempotency-Key');

    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret, ['Idempotency-Key' => $value])
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);

    expect(ApiUsage::count())->toBe(0);
})->with([
    'empty' => '',
    'too long' => str_repeat('a', 256),
    'non-ascii' => 'clé-é',
    'control character' => "ab\x01c",
]);

it('accepts an Idempotency-Key of exactly 255 characters', function () {
    $this->getJson('/api/v1/niches', [...$this->bearer, 'Idempotency-Key' => str_repeat('a', 255)])->assertOk();

    expect(ApiUsage::count())->toBe(1);
});

it('fails the request instead of serving an unmetered read when the ledger write fails', function () {
    $this->mock(RecordApiUsage::class)->shouldReceive('handle')->andThrow(new RuntimeException('disk full'));

    $response = $this->getJson('/api/v1/niches', $this->bearer)
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error');

    expect($response->json('data'))->toBeNull();
});
