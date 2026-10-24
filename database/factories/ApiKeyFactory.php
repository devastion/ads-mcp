<?php

namespace Database\Factories;

use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $secret = 'ghk_'.fake()->unique()->lexify(str_repeat('?', 40));

        return [
            'name' => 'Factory key',
            'prefix' => substr($secret, 0, 12),
            'key_hash' => ApiKey::hashSecret($secret),
        ];
    }
}
