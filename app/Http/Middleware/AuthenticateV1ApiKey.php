<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v1 reads the key from the Authorization Bearer header. A key in the query string is refused even when a header is also sent.
 */
class AuthenticateV1ApiKey
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->query->has('api_key')) {
            throw new ApiErrorException(ApiErrorCode::ApiKeyInQuery, 'API keys are not accepted in the query string.');
        }

        $secret = $request->bearerToken();

        if (! is_string($secret) || $secret === '') {
            throw new ApiErrorException(ApiErrorCode::ApiKeyMissing, 'An API key is required.');
        }

        if (ApiKey::findBySecret($secret) === null) {
            throw new ApiErrorException(ApiErrorCode::ApiKeyInvalid, 'The API key is not valid.');
        }

        return $next($request);
    }
}
