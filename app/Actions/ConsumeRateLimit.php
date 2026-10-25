<?php

namespace App\Actions;

use App\Models\ApiKey;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ConsumeRateLimit
{
    public const MAX_ATTEMPTS = 60;

    public const WINDOW_SECONDS = 60;

    /**
     * Spend one unit of the key's shared bucket. The increment is atomic, so concurrent workers cannot overspend.
     *
     * @throws TooManyRequestsHttpException carrying the whole seconds to wait in Retry-After
     */
    public function handle(ApiKey $key): void
    {
        $bucket = 'api-key:'.$key->getKey();

        if (RateLimiter::hit($bucket, self::WINDOW_SECONDS) > self::MAX_ATTEMPTS) {
            throw new TooManyRequestsHttpException(max(1, RateLimiter::availableIn($bucket)));
        }
    }
}
