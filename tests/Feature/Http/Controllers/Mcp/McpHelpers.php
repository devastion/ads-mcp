<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const MCP_PATH = '/api/mcp';

/**
 * @param  array<string, string>  $headers
 */
function mcpPost(mixed $body, string $method = 'tools/list', array $headers = [], ?string $secret = null, string $uri = MCP_PATH): TestResponse
{
    $headers = [
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2025-11-25',
        'Mcp-Method' => $method,
        ...($secret === null ? [] : ['Authorization' => 'Bearer '.$secret]),
        ...$headers,
    ];

    return test()->call('POST', $uri, server: mcpServer($headers), content: is_string($body) ? $body : json_encode($body));
}

/**
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function mcpServer(array $headers, ?string $secret = null): array
{
    $headers = [
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2025-11-25',
        ...($secret === null ? [] : ['Authorization' => 'Bearer '.$secret]),
        ...$headers,
    ];

    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $server;
}

function listToolsBody(): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => new stdClass];
}

/**
 * Sends every outgoing REST call through the real application kernel, so REST's own limiter and meter run.
 */
function fakeRestLoopback(): void
{
    Http::fake(function (Illuminate\Http\Client\Request $request) {
        $server = [];

        foreach ($request->headers() as $name => $values) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $values[0] ?? '';
        }

        $inner = Request::create($request->url(), $request->method(), server: $server);
        $response = app(Kernel::class)->handle($inner);

        return Http::response((string) $response->getContent(), $response->getStatusCode(), $response->headers->all());
    });
}
