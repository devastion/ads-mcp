<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * v0 reads the key from the api_key query parameter. Every failure becomes 400, which the v0 error renderer shapes.
 */
class AuthenticateV0ApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = $request->query('api_key');

        if (! is_string($secret) || $secret === '' || ApiKey::findBySecret($secret) === null) {
            abort(400);
        }

        return $next($request);
    }
}
