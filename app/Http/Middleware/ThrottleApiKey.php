<?php

namespace App\Http\Middleware;

use App\Actions\ConsumeRateLimit;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after authentication: every authenticated attempt spends one unit of the key's shared bucket.
 */
class ThrottleApiKey
{
    public function __construct(private readonly ConsumeRateLimit $limiter) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->attributes->get('api_key');

        if ($key instanceof ApiKey) {
            $this->limiter->handle($key);
        }

        return $next($request);
    }
}
