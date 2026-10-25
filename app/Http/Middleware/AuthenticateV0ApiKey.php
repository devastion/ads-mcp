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

        $key = is_string($secret) && $secret !== '' ? ApiKey::findBySecret($secret) : null;

        if ($key === null || $key->isRevoked()) {
            abort(400);
        }

        $key->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
