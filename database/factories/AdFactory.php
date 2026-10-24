<?php

namespace Database\Factories;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Ad>
 */
class AdFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::today();

        return [
            'brand_id' => 1,
            'brand_name' => 'Acme',
            'headline' => 'Acme headline',
            'body_text' => 'Acme body text',
            'niche' => Niche::Skincare,
            'format' => AdFormat::Image,
            'status' => AdStatus::Active,
            'start_date' => $start,
            'end_date' => null,
            'impression_rank' => 1,
            'countries' => ['US'],
            'discovered_at' => $start,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => AdStatus::Active,
            'end_date' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AdStatus::Inactive,
            'end_date' => $attributes['start_date'],
        ]);
    }

    public function withoutImpressions(): static
    {
        return $this->state(fn (): array => ['impression_rank' => null]);
    }
}
