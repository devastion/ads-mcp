<?php

use App\Models\ApiKey;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    asLocal();
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
});

function billedRows(ApiKey $key, int $count, string $at = '2026-10-10 09:00:00', bool $billed = true): void
{
    ApiUsage::factory()->count($count)->create(['api_key_id' => $key->id, 'billed' => $billed, 'created_at' => Carbon::parse($at, 'UTC')]);
}

it('lists keys newest first with revoked ones and only the whitelisted fields', function () {
    $older = ApiKey::factory()->create(['name' => 'Older', 'prefix' => 'gh_old00000']);
    $revoked = ApiKey::factory()->create(['name' => 'Revoked', 'prefix' => 'gh_rev00000', 'revoked_at' => now()]);

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('developer')
        ->has('keys', 2)
        ->where('keys.0.id', $revoked->id)
        ->where('keys.0.name', 'Revoked')
        ->where('keys.0.revoked_at', $revoked->revoked_at->toIso8601String())
        ->where('keys.1.id', $older->id)
        ->has('keys.0', fn (AssertableInertia $key) => $key
            ->hasAll(['id', 'name', 'prefix', 'scopes', 'created_at', 'last_used_at', 'revoked_at', 'last_mcp_request_at'])
            ->missing('key_hash')
            ->missing('secret')
            ->etc()));
});

it('counts billed rows of this month per key, including a key with zero rows', function () {
    $key = ApiKey::factory()->create();
    $idle = ApiKey::factory()->create();
    $other = ApiKey::factory()->create();
    billedRows($key, 3);
    billedRows($key, 2, '2026-09-30 23:59:59');
    billedRows($key, 1, billed: false);
    billedRows($other, 5);

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('usage.limit', 1000)
        ->where('usage.period_start', '2026-10-01T00:00:00+00:00')
        ->where('usage.resets_at', '2026-11-01T00:00:00+00:00')
        ->where('usage.per_key', [
            ['key_id' => $other->id, 'used' => 5],
            ['key_id' => $idle->id, 'used' => 0],
            ['key_id' => $key->id, 'used' => 3],
        ]));
});

it('reflects a newly inserted billed row on the next load without a stored counter', function () {
    $key = ApiKey::factory()->create();
    billedRows($key, 1);

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page->where('usage.per_key.0.used', 1));

    billedRows($key, 1);

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page->where('usage.per_key.0.used', 2));
});

it('resets at the first instant of next year when frozen in December', function () {
    $this->travelTo(Carbon::parse('2026-12-15 12:00:00', 'UTC'));

    $this->get('/developer')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('usage.period_start', '2026-12-01T00:00:00+00:00')
        ->where('usage.resets_at', '2027-01-01T00:00:00+00:00'));
});
