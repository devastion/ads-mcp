<?php

namespace App\Http\Resources\Developer;

use App\Models\ApiKey;

class McpPanelResource
{
    public const CLIENT = 'Pi-Bolt';

    /**
     * @return array{endpoint_url: string, client: string, config_template: string, connection: array{key_id: int, prefix: string, connected_at: ?string}|null}
     */
    public static function from(?ApiKey $key): array
    {
        $endpoint = url('/api/mcp');

        return [
            'endpoint_url' => $endpoint,
            'client' => self::CLIENT,
            'config_template' => json_encode([
                'mcpServers' => ['gethookd' => [
                    'url' => $endpoint,
                    'headers' => ['Authorization' => 'Bearer <YOUR_KEY>'],
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'connection' => $key === null ? null : [
                'key_id' => $key->id,
                'prefix' => $key->prefix,
                'connected_at' => $key->last_mcp_request_at?->utc()->toIso8601String(),
            ],
        ];
    }
}
