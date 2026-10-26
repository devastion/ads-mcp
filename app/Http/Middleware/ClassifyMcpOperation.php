<?php

namespace App\Http\Middleware;

use App\Actions\ConsumeRateLimit;
use App\Actions\Mcp\Tools\AdsTool;
use App\Actions\Mcp\ValidateToolArguments;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charges the shared limiter once for each control operation handled by MCP itself. Notifications cost nothing.
 * A known tool call with valid arguments is forwarded to REST, which charges it there. Known tools with invalid arguments are rejected here and charged here once.
 */
class ClassifyMcpOperation
{
    public function __construct(private readonly ConsumeRateLimit $limiter) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $body = json_decode((string) $request->getContent(), true);
        $isObject = is_array($body) && ! array_is_list($body);

        if ($isObject && ! array_key_exists('id', $body) && is_string($body['method'] ?? null)) {
            return $next($request);
        }

        $toolName = $isObject && ($body['method'] ?? null) === 'tools/call' ? ($body['params']['name'] ?? null) : null;
        $isKnownTool = is_string($toolName) && in_array($toolName, AdsTool::NAMES, true);

        if ($isKnownTool && app(ValidateToolArguments::class)->handle($toolName, $body['params']['arguments'] ?? []) === null) {
            return $next($request);
        }

        $key = $request->attributes->get('api_key');

        if ($key instanceof ApiKey) {
            $this->limiter->handle($key);
        }

        if ($toolName !== null && ! $isKnownTool) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $body['id'],
                'error' => ['code' => -32602, 'message' => 'Unknown tool. Valid tools: '.implode(', ', AdsTool::NAMES).'.'],
            ], 400);
        }

        return $next($request);
    }
}
