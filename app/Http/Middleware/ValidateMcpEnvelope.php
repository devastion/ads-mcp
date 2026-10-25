<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses oversized bodies, batch arrays and unusable ids before any operation runs. Errors never echo the input.
 */
class ValidateMcpEnvelope
{
    public const MAX_BODY_BYTES = 65536;

    public const MAX_ID_BYTES = 128;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $raw = (string) $request->getContent();

        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->invalidRequest('The request body is too large.');
        }

        $body = json_decode($raw, true);

        if (! is_array($body)) {
            return $next($request);
        }

        if (array_is_list($body)) {
            return $this->invalidRequest('Batch requests are not supported. Send one request per POST.');
        }

        if (array_key_exists('id', $body) && ! $this->isUsableId($body['id'])) {
            return $this->invalidRequest('The request id must be an integer or a string of at most 128 bytes.');
        }

        return $next($request);
    }

    private function isUsableId(mixed $id): bool
    {
        if (is_int($id)) {
            return true;
        }

        return is_string($id) && $id !== '' && strlen($id) <= self::MAX_ID_BYTES && mb_check_encoding($id, 'UTF-8');
    }

    private function invalidRequest(string $message): Response
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32600, 'message' => $message],
        ]);
    }
}
