<?php

namespace App\Http\Resources\Developer;

use App\Models\ApiKey;
use Illuminate\Support\Carbon;

/**
 * Whitelist of key fields shown on the developer page. Never includes key_hash or a secret.
 */
class ApiKeyResource
{
    /**
     * @return array{id: int, name: string, prefix: string, scopes: list<string>, created_at: ?string, last_used_at: ?string, revoked_at: ?string, last_mcp_request_at: ?string}
     */
    public static function from(ApiKey $key): array
    {
        $lastMcpRequestAt = $key->getAttribute('last_mcp_request_at');

        return [
            'id' => $key->id,
            'name' => $key->name,
            'prefix' => $key->prefix,
            'scopes' => $key->scopes,
            'created_at' => $key->created_at?->utc()->toIso8601String(),
            'last_used_at' => $key->last_used_at?->utc()->toIso8601String(),
            'revoked_at' => $key->revoked_at?->utc()->toIso8601String(),
            'last_mcp_request_at' => $lastMcpRequestAt === null ? null : Carbon::parse($lastMcpRequestAt)->utc()->toIso8601String(),
        ];
    }
}
