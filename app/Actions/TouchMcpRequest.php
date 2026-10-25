<?php

namespace App\Actions;

use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Builder;

class TouchMcpRequest
{
    public function handle(ApiKey $key): void
    {
        $now = now();

        ApiKey::query()
            ->whereKey($key->getKey())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('last_mcp_request_at')
                ->orWhere('last_mcp_request_at', '<=', $now->copy()->subMinute()))
            ->update(['last_mcp_request_at' => $now]);
    }
}
