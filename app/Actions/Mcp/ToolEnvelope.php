<?php

namespace App\Actions\Mcp;

use App\DTO\McpToolError;
use App\Enums\ApiErrorCode;
use Laravel\Mcp\Response;

/**
 * Builds the single compact JSON text block every tool returns.
 */
class ToolEnvelope
{
    /** Serialized JSON text budget for search and detail results. */
    public const MAX_TEXT_BYTES = 14336;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function success(array $payload): Response
    {
        $text = self::encode($payload);

        if (strlen($text) > self::MAX_TEXT_BYTES) {
            return self::error(new McpToolError(
                ApiErrorCode::InternalError,
                'The result exceeded its size contract and was not returned.',
                ApiErrorCode::InternalError->defaultNextStep(),
            ));
        }

        return Response::text($text);
    }

    public static function error(McpToolError $error): Response
    {
        return Response::error(self::encode($error->toEnvelope()));
    }

    /**
     * @param  array<mixed>  $value
     */
    public static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
