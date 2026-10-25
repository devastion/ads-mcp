<?php

namespace App\Actions\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One GET to the configured REST API. Base URL comes from configuration only; redirects are off; nothing is retried.
 */
class CallRestApi
{
    /**
     * @param  list<array{0: string, 1: string}>  $query  ordered name/value pairs; repeated names stay repeated
     *
     * @throws ConnectionException when the connect or total timeout elapses
     */
    public function get(string $path, array $query, string $bearer, ?string $idempotencyKey): Response
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$bearer,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $url = rtrim((string) config('services.mcp.rest_base_url'), '/').$path;

        if ($query !== []) {
            $url .= '?'.implode('&', array_map(
                fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
                $query,
            ));
        }

        return Http::withHeaders($headers)
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout((int) ceil((float) config('services.mcp.connect_timeout', 2)))
            ->timeout((int) ceil((float) config('services.mcp.timeout', 10)))
            ->get($url);
    }
}
