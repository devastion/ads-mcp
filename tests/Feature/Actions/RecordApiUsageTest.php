<?php

use App\Actions\RecordApiUsage;
use App\Models\ApiKey;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
    $this->key = ApiKey::factory()->create();
});

function recordUsage(ApiKey $key, ?string $idempotencyKey = null): ApiUsage
{
    return app(RecordApiUsage::class)->handle($key, 'v1', 'api.v1.ads', 200, 3, 12, $idempotencyKey);
}

it('records the call with its surface, endpoint, status, result count and latency', function () {
    $usage = recordUsage($this->key)->fresh();

    expect($usage->api_key_id)->toBe($this->key->id)
        ->and($usage->surface)->toBe('v1')
        ->and($usage->endpoint)->toBe('api.v1.ads')
        ->and($usage->status)->toBe(200)
        ->and($usage->result_count)->toBe(3)
        ->and($usage->latency_ms)->toBe(12)
        ->and($usage->billed)->toBeTrue()
        ->and($usage->idempotency_hash)->toBeNull();
});

it('bills every call that carries no idempotency key', function () {
    recordUsage($this->key);
    recordUsage($this->key);

    expect(ApiUsage::where('billed', true)->count())->toBe(2);
});

it('bills a repeated idempotency key once and stores only its sha256', function () {
    $first = recordUsage($this->key, 'retry-me');
    $second = recordUsage($this->key, 'retry-me');

    expect($first->billed)->toBeTrue()
        ->and($second->billed)->toBeFalse()
        ->and(ApiUsage::count())->toBe(2)
        ->and($first->idempotency_hash)->toBe(hash('sha256', 'retry-me'))
        ->and(json_encode(ApiUsage::all()))->not->toContain('retry-me');
});

it('bills different idempotency keys separately', function () {
    recordUsage($this->key, 'one');
    recordUsage($this->key, 'two');

    expect(ApiUsage::where('billed', true)->count())->toBe(2);
});

it('does not let one api key suppress billing for another', function () {
    $other = ApiKey::factory()->create();

    recordUsage($this->key, 'shared-name');

    expect(recordUsage($other, 'shared-name')->billed)->toBeTrue();
});

it('bills the same key again once 24 hours have passed', function () {
    recordUsage($this->key, 'daily');

    $this->travelTo(Carbon::parse('2026-10-26 11:59:00'));
    expect(recordUsage($this->key, 'daily')->billed)->toBeFalse();

    $this->travelTo(Carbon::parse('2026-10-26 12:01:00'));
    expect(recordUsage($this->key, 'daily')->billed)->toBeTrue();
});
