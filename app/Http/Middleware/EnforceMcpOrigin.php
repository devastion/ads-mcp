<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a browser Origin that is not configured. Requests without an Origin header are not affected.
 */
class EnforceMcpOrigin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin !== null && ! in_array($origin, config('services.mcp.allowed_origins', []), true)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'The Origin is not allowed.'],
            ], 403);
        }

        return $next($request);
    }
}
