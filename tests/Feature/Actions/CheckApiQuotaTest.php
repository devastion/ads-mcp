<?php

use App\Actions\CheckApiQuota;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Models\ApiKey;
use App\Models\ApiUsage;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00', 'UTC'));
    $this->key = ApiKey::factory()->create();
});

function fillQuota(ApiKey $key, int $billed, array $attributes = []): void
{
    $now = now();
    $rows = array_fill(0, $billed, ['api_key_id' => $key->id, 'surface' => 'v1', 'endpoint' => 'api.v1.niches', 'status' => 200, 'result_count' => 12, 'latency_ms' => 1, 'billed' => true, 'idempotency_hash' => null, 'created_at' => $now, ...$attributes]);

    foreach (array_chunk($rows, 500) as $chunk) {
        ApiUsage::query()->insert($chunk);
    }
}

it('admits the 1000th billed call and refuses the next', function () {
    fillQuota($this->key, 999);
    app(CheckApiQuota::class)->handle($this->key, null);

    fillQuota($this->key, 1);

    try {
        app(CheckApiQuota::class)->handle($this->key, null);
        $this->fail('The 1001st call should have been refused.');
    } catch (ApiErrorException $exception) {
        expect($exception->code()->value)->toBe('quota_exceeded')
            ->and($exception->code()->retryable())->toBeFalse()
            ->and($exception->details()['resets_at'])->toBe('2026-11-01T00:00:00+00:00')
            ->and($exception->nextStep())->toContain('2026-11-01');
    }
});

it('does not count unbilled repeats or other keys', function () {
    fillQuota($this->key, 1000, ['billed' => false]);
    fillQuota(ApiKey::factory()->create(), 1000);

    app(CheckApiQuota::class)->handle($this->key, null);
    expect(true)->toBeTrue();
});

it('does not count last month and starts a new count at the month boundary', function () {
    fillQuota($this->key, 1000, ['created_at' => Carbon::parse('2026-09-30 23:59:59', 'UTC')]);
    app(CheckApiQuota::class)->handle($this->key, null);

    $this->travelTo(Carbon::parse('2026-10-31 23:59:59', 'UTC'));
    fillQuota($this->key, 1000);
    expect(fn () => app(CheckApiQuota::class)->handle($this->key, null))->toThrow(ApiErrorException::class);

    $this->travelTo(Carbon::parse('2026-11-01 00:00:00', 'UTC'));
    app(CheckApiQuota::class)->handle($this->key, null);
});

it('admits a repeat of an idempotency key already billed in the last 24 hours even over quota', function () {
    fillQuota($this->key, 1000);
    fillQuota($this->key, 1, ['idempotency_hash' => hash('sha256', 'paid')]);

    app(CheckApiQuota::class)->handle($this->key, 'paid');
    expect(fn () => app(CheckApiQuota::class)->handle($this->key, 'unpaid'))->toThrow(ApiErrorException::class);
});
