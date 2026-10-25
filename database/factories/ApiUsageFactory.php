<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\ApiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiUsage>
 */
class ApiUsageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'api_key_id' => ApiKey::factory(),
            'surface' => 'v1',
            'endpoint' => 'api.v1.ads',
            'status' => 200,
            'result_count' => 0,
            'latency_ms' => 1,
            'billed' => true,
            'idempotency_hash' => null,
        ];
    }
}
