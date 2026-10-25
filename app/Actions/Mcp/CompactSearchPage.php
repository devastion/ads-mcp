<?php

namespace App\Actions\Mcp;

use App\DTO\McpToolError;
use App\Enums\ApiErrorCode;
use JsonException;

/**
 * Shapes a REST search page for a model. Every fetched row is kept; only brand and headline previews are shortened,
 * and each shortened field is named in truncated_fields. Byte limits are checked on the serialized JSON.
 */
class CompactSearchPage
{
    public const MAX_ITEM_BYTES = 1024;

    public const MAX_METADATA_BYTES = 4096;

    private const BRAND_CHARS = 64;

    private const BRAND_JSON_BYTES = 192;

    private const HEADLINE_CHARS = 120;

    private const HEADLINE_JSON_BYTES = 512;

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|McpToolError
     */
    public function handle(array $body): array|McpToolError
    {
        if (! isset($body['data']) || ! is_array($body['data']) || ! array_is_list($body['data'])) {
            return $this->internal();
        }

        $items = [];

        foreach ($body['data'] as $ad) {
            $item = $this->item($ad);

            if ($item === null) {
                return $this->internal();
            }

            $items[] = $item;
        }

        $page = [
            'data' => $items,
            'applied_filters' => $body['applied_filters'] ?? null,
            'has_more' => $body['has_more'] ?? null,
            'next_cursor' => $body['next_cursor'] ?? null,
            'detail_tool' => 'get_ad',
        ];

        if (isset($body['notes'])) {
            $page['notes'] = $body['notes'];
        }

        try {
            $metadata = $page;
            unset($metadata['data']);

            if (strlen(ToolEnvelope::encode($metadata)) > self::MAX_METADATA_BYTES) {
                return $this->internal();
            }
        } catch (JsonException) {
            return $this->internal();
        }

        return $page;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function item(mixed $ad): ?array
    {
        if (! is_array($ad) || ! is_string($ad['brand'] ?? null) || ! is_string($ad['headline'] ?? null)) {
            return null;
        }

        $truncated = [];
        [$brand, $brandCut] = $this->fit($ad['brand'], self::BRAND_CHARS, self::BRAND_JSON_BYTES);
        [$headline, $headlineCut] = $this->fit($ad['headline'], self::HEADLINE_CHARS, self::HEADLINE_JSON_BYTES);

        if ($brandCut) {
            $truncated[] = 'brand';
        }

        if ($headlineCut) {
            $truncated[] = 'headline';
        }

        $item = [
            'id' => $ad['id'] ?? null,
            'brand' => $brand,
            'headline' => $headline,
            'niche' => $ad['niche'] ?? null,
            'format' => $ad['format'] ?? null,
            'status' => $ad['status'] ?? null,
            'start_date' => $ad['start_date'] ?? null,
            'days_active' => $ad['days_active'] ?? null,
        ];

        if ($truncated !== []) {
            $item['truncated_fields'] = $truncated;
        }

        try {
            return strlen(ToolEnvelope::encode($item)) <= self::MAX_ITEM_BYTES ? $item : null;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Shortens a value to a character budget and then to a serialized-byte budget, never splitting a character.
     *
     * @return array{0: string, 1: bool}
     */
    private function fit(string $value, int $chars, int $jsonBytes): array
    {
        if (mb_strlen($value) <= $chars && $this->jsonBytes($value) <= $jsonBytes) {
            return [$value, false];
        }

        $kept = mb_substr($value, 0, $chars - 1);

        while ($kept !== '' && $this->jsonBytes($kept.'…') > $jsonBytes) {
            $kept = mb_substr($kept, 0, -1);
        }

        return [$kept.'…', true];
    }

    private function jsonBytes(string $value): int
    {
        return strlen(ToolEnvelope::encode([$value])) - 2;
    }

    private function internal(): McpToolError
    {
        return new McpToolError(ApiErrorCode::InternalError, 'The REST search page did not match its contract.', ApiErrorCode::InternalError->defaultNextStep());
    }
}
