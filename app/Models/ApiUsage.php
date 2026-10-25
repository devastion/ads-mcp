<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ApiUsageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per metered read. `billed` is false for an idempotent repeat of an earlier billed read.
 *
 * @property int $api_key_id
 * @property string $surface
 * @property string $endpoint
 * @property int $status
 * @property int|null $result_count
 * @property int $latency_ms
 * @property bool $billed
 * @property string|null $idempotency_hash
 * @property CarbonImmutable $created_at
 */
#[Fillable(['api_key_id', 'surface', 'endpoint', 'status', 'result_count', 'latency_ms', 'billed', 'idempotency_hash'])]
class ApiUsage extends Model
{
    /** @use HasFactory<ApiUsageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'api_key_id' => 'integer',
            'status' => 'integer',
            'result_count' => 'integer',
            'latency_ms' => 'integer',
            'billed' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
