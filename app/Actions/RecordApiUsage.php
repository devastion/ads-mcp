<?php

namespace App\Actions;

use App\Models\ApiKey;
use App\Models\ApiUsage;
use Illuminate\Support\Facades\DB;

class RecordApiUsage
{
    public const IDEMPOTENCY_WINDOW_HOURS = 24;

    /**
     * Write one ledger row. Requests sharing an Idempotency-Key serialize on a Postgres advisory lock,
     * so overlapping retries cannot both see "not billed yet" and both bill.
     */
    public function handle(ApiKey $key, string $surface, string $endpoint, int $status, ?int $resultCount, int $latencyMs, ?string $idempotencyKey): ApiUsage
    {
        $hash = $idempotencyKey === null ? null : hash('sha256', $idempotencyKey);

        return DB::transaction(function () use ($key, $surface, $endpoint, $status, $resultCount, $latencyMs, $hash): ApiUsage {
            $billed = true;

            if ($hash !== null) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key->getKey().':'.$hash]);

                $billed = ! ApiUsage::query()
                    ->where('api_key_id', $key->getKey())
                    ->where('idempotency_hash', $hash)
                    ->where('billed', true)
                    ->where('created_at', '>', now()->subHours(self::IDEMPOTENCY_WINDOW_HOURS))
                    ->exists();
            }

            return ApiUsage::query()->create([
                'api_key_id' => $key->getKey(),
                'surface' => $surface,
                'endpoint' => $endpoint,
                'status' => $status,
                'result_count' => $resultCount,
                'latency_ms' => $latencyMs,
                'billed' => $billed,
                'idempotency_hash' => $hash,
            ]);
        });
    }
}
