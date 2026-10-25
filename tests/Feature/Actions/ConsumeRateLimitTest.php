<?php

use App\Actions\ConsumeRateLimit;
use App\Models\ApiKey;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('allows 60 units a minute and refuses the 61st with the seconds to wait', function () {
    $key = ApiKey::factory()->create();

    foreach (range(1, 60) as $_) {
        app(ConsumeRateLimit::class)->handle($key);
    }

    $this->travelTo(Carbon::parse('2026-10-25 12:00:20'));

    try {
        app(ConsumeRateLimit::class)->handle($key);
        $this->fail('The 61st unit should have been refused.');
    } catch (TooManyRequestsHttpException $exception) {
        expect($exception->getHeaders()['Retry-After'])->toBe(40);
    }
});

it('lets the key through again once the window has passed', function () {
    $key = ApiKey::factory()->create();

    foreach (range(1, 61) as $_) {
        try {
            app(ConsumeRateLimit::class)->handle($key);
        } catch (TooManyRequestsHttpException) {
        }
    }

    $this->travelTo(Carbon::parse('2026-10-25 12:00:59'));
    expect(fn () => app(ConsumeRateLimit::class)->handle($key))->toThrow(TooManyRequestsHttpException::class);

    $this->travelTo(Carbon::parse('2026-10-25 12:01:00'));
    app(ConsumeRateLimit::class)->handle($key);
    expect(true)->toBeTrue();
});

it('keeps one bucket per key', function () {
    [$first, $second] = [ApiKey::factory()->create(), ApiKey::factory()->create()];

    foreach (range(1, 60) as $_) {
        app(ConsumeRateLimit::class)->handle($first);
    }

    app(ConsumeRateLimit::class)->handle($second);
    expect(fn () => app(ConsumeRateLimit::class)->handle($first))->toThrow(TooManyRequestsHttpException::class);
});
