<?php

namespace App\Http\Middleware;

use App\Enums\ApiErrorCode;
use App\Enums\Scope;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs after the throttle so a valid key spends a rate unit even when its scope is wrong.
 */
class RequireScope
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $key = $request->attributes->get('api_key');
        $required = Scope::from($scope);

        if (! $key instanceof ApiKey || ! $key->hasScope($required)) {
            if ($request->is('api/v0', 'api/v0/*')) {
                abort(400);
            }

            throw new ApiErrorException(ApiErrorCode::InsufficientScope, 'The API key does not have the '.$required->value.' scope.');
        }

        return $next($request);
    }
}
