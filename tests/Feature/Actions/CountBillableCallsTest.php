<?php

use App\Actions\CountBillableCalls;
use App\Models\ApiKey;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;

function usageRow(ApiKey $key, string $at, bool $billed = true): void
{
    ApiUsage::factory()->create(['api_key_id' => $key->id, 'billed' => $billed, 'created_at' => Carbon::parse($at, 'UTC')]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    $this->key = ApiKey::factory()->create();
});

it('counts only billed rows of the requested key this month', function () {
    $other = ApiKey::factory()->create();
    usageRow($this->key, '2026-10-02 08:00:00');
    usageRow($this->key, '2026-10-03 08:00:00');
    usageRow($this->key, '2026-10-04 08:00:00', billed: false);
    usageRow($this->key, '2026-09-30 23:59:59');
    usageRow($other, '2026-10-05 08:00:00');

    $counts = app(CountBillableCalls::class)->handle([$this->key->id], now());

    expect($counts)->toBe([$this->key->id => 2]);
});

it('returns zero for a key with no rows', function () {
    $counts = app(CountBillableCalls::class)->handle([$this->key->id], now());

    expect($counts)->toBe([$this->key->id => 0]);
});

it('counts several keys in one call', function () {
    $other = ApiKey::factory()->create();
    usageRow($other, '2026-10-05 08:00:00');

    $counts = app(CountBillableCalls::class)->handle([$this->key->id, $other->id], now());

    expect($counts)->toBe([$this->key->id => 0, $other->id => 1]);
});

it('splits months at midnight UTC', function () {
    usageRow($this->key, '2026-10-31 23:59:59');
    usageRow($this->key, '2026-11-01 00:00:00');

    $counts = app(CountBillableCalls::class);

    expect($counts->handle([$this->key->id], Carbon::parse('2026-10-31 23:59:59', 'UTC')))->toBe([$this->key->id => 1])
        ->and($counts->handle([$this->key->id], Carbon::parse('2026-11-01 00:00:00', 'UTC')))->toBe([$this->key->id => 1]);
});

it('resets at the first instant of the next UTC month, across the year end', function () {
    $action = app(CountBillableCalls::class);

    expect($action->resetsAt(Carbon::parse('2026-10-25 12:00:00', 'UTC'))->toIso8601String())->toBe('2026-11-01T00:00:00+00:00')
        ->and($action->resetsAt(Carbon::parse('2026-12-15 12:00:00', 'UTC'))->toIso8601String())->toBe('2027-01-01T00:00:00+00:00');
});
