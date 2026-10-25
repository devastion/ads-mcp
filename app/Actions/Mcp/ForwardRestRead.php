<?php

namespace App\Actions\Mcp;

use App\DTO\McpToolError;
use App\Enums\ApiErrorCode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Forwards one read to REST with the caller's own credential and returns either the decoded REST body or a tool error.
 * REST charges the rate limit and billing itself, so this adds no accounting.
 */
class ForwardRestRead
{
    public function __construct(private readonly CallRestApi $rest) {}

    /**
     * @param  list<array{0: string, 1: string}>  $query
     * @return array<string, mixed>|McpToolError
     */
    public function handle(string $path, array $query): array|McpToolError
    {
        $request = request();
        $bearer = (string) $request->bearerToken();
        $idempotencyKey = $request->header('Idempotency-Key');

        try {
            $response = $this->rest->get($path, $query, $bearer, is_string($idempotencyKey) ? $idempotencyKey : null);
        } catch (ConnectionException) {
            return $this->internal('The REST read timed out. It may already have been billed; retry only with the same Idempotency-Key.');
        }

        return $this->interpret($response);
    }

    /**
     * @return array<string, mixed>|McpToolError
     */
    private function interpret(Response $response): array|McpToolError
    {
        if ($response->status() === 429) {
            $seconds = $response->json('error.details.retry_after_seconds') ?? $response->header('Retry-After');
            $seconds = is_numeric($seconds) ? max(1, (int) $seconds) : 1;

            return new McpToolError(
                ApiErrorCode::RateLimited,
                'The API key has reached its rate limit.',
                'Wait '.$seconds.' seconds, then repeat the same call.',
                ['retry_after_seconds' => $seconds],
            );
        }

        if ($response->successful()) {
            $body = $response->json();

            return is_array($body) ? $body : $this->internal('The REST read returned an unexpected body.');
        }

        $code = ApiErrorCode::tryFrom((string) $response->json('error.code'));

        if ($code !== null && $response->status() >= 400 && $response->status() < 500 && $code !== ApiErrorCode::RateLimited) {
            return new McpToolError($code, (string) $response->json('error.message'), (string) $response->json('error.next_step'));
        }

        return $this->internal('The REST read failed with status '.$response->status().'.');
    }

    private function internal(string $message): McpToolError
    {
        return new McpToolError(ApiErrorCode::InternalError, $message, ApiErrorCode::InternalError->defaultNextStep());
    }
}
