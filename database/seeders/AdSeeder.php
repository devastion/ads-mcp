<?php

namespace Database\Seeders;

use App\Actions\GenerateAdCopy;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

class AdSeeder extends Seeder
{
    private const int SEED = 20261025;

    private const int BRAND_COUNT = 300;

    private const int TOTAL_ADS = 20000;

    private const int LOOKBACK_DAYS = 729;

    private const int ACTIVE_PERCENT = 60;

    private const int NULL_RANK_PERCENT = 40;

    private const int CHUNK_SIZE = 1000;

    private const array ADJECTIVES = [
        'Bright', 'Pure', 'Golden', 'Urban', 'Wild', 'Modern', 'Classic', 'Fresh',
        'Bold', 'Cozy', 'Swift', 'Gentle', 'Lumen', 'North', 'Coastal', 'Velvet',
        'Atlas', 'Ember', 'Harbor', 'Sol',
    ];

    private const array NOUNS = [
        'Co', 'Labs', 'Supply', 'House', 'Works', 'Goods', 'Studio', 'Market',
        'Trail', 'Forge', 'Kin', 'Crate', 'Nest', 'Grove', 'Bay',
    ];

    private const array COUNTRIES = [
        'US', 'GB', 'DE', 'FR', 'CA', 'AU', 'NL', 'SE', 'ES', 'IT', 'BR', 'MX', 'JP', 'IN',
    ];

    /**
     * Replaces all ads with the same deterministic data for any given day.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Refusing to replace ads in production: seeding truncates the ads table.');
        }

        $random = new Randomizer(new Mt19937(self::SEED));
        $copy = new GenerateAdCopy;
        $today = Carbon::today();

        $counts = $this->adCountsPerBrand($random);

        DB::transaction(function () use ($random, $copy, $today, $counts): void {
            Ad::query()->truncate();

            $rows = [];
            $nextRank = [];

            foreach ($counts as $index => $count) {
                $brandId = $index + 1;
                $niche = Niche::cases()[$index % count(Niche::cases())];
                $brandName = self::ADJECTIVES[$index % count(self::ADJECTIVES)].' '.self::NOUNS[intdiv($index, count(self::ADJECTIVES))];

                for ($i = 0; $i < $count; $i++) {
                    $rows[] = $this->row($random, $copy, $today, $brandId, $brandName, $niche, $nextRank);

                    if (count($rows) === self::CHUNK_SIZE) {
                        DB::table('ads')->insert($rows);
                        $rows = [];
                    }
                }
            }

            if ($rows !== []) {
                DB::table('ads')->insert($rows);
            }
        });
    }

    /**
     * @return list<int> ads per brand, summing to TOTAL_ADS, each at least 1
     */
    private function adCountsPerBrand(Randomizer $random): array
    {
        $average = intdiv(self::TOTAL_ADS, self::BRAND_COUNT);
        $counts = [];
        for ($i = 0; $i < self::BRAND_COUNT; $i++) {
            $counts[] = $average + $random->getInt(-30, 30);
        }

        $difference = self::TOTAL_ADS - array_sum($counts);
        $step = $difference >= 0 ? 1 : -1;

        // Spread the rounding residual one ad at a time across brands, so no single brand absorbs it.
        for ($i = 0; $difference !== 0; $i++) {
            $counts[$i % self::BRAND_COUNT] += $step;
            $difference -= $step;
        }

        return array_values($counts);
    }

    /**
     * @param  array<int, int>  $nextRank  highest impression rank handed out so far, per brand
     * @return array<string, mixed>
     */
    private function row(
        Randomizer $random,
        GenerateAdCopy $copy,
        Carbon $today,
        int $brandId,
        string $brandName,
        Niche $niche,
        array &$nextRank,
    ): array {
        $startOffset = $random->getInt(0, self::LOOKBACK_DAYS);
        $start = $today->copy()->subDays($startOffset);
        $active = $random->getInt(1, 100) <= self::ACTIVE_PERCENT;
        $end = $active ? null : $start->copy()->addDays($random->getInt(0, $startOffset));
        $discovered = $start->copy()->addDays($random->getInt(0, $startOffset));

        $rank = null;
        if ($random->getInt(1, 100) > self::NULL_RANK_PERCENT) {
            $rank = ($nextRank[$brandId] ?? 0) + 1;
            $nextRank[$brandId] = $rank;
        }

        $copyText = $copy->handle($random, $niche);
        $formats = AdFormat::cases();
        $countries = array_slice($random->shuffleArray(self::COUNTRIES), 0, $random->getInt(1, 4));

        return [
            'brand_id' => $brandId,
            'brand_name' => $brandName,
            'headline' => $copyText['headline'],
            'body_text' => $copyText['body_text'],
            'niche' => $niche->value,
            'format' => $formats[$random->getInt(0, count($formats) - 1)]->value,
            'status' => ($active ? AdStatus::Active : AdStatus::Inactive)->value,
            'start_date' => $start->toDateString(),
            'end_date' => $end?->toDateString(),
            'impression_rank' => $rank,
            'countries' => json_encode($countries, JSON_THROW_ON_ERROR),
            'discovered_at' => $discovered->startOfDay()->toDateTimeString(),
            'created_at' => $discovered->startOfDay()->toDateTimeString(),
            'updated_at' => $discovered->startOfDay()->toDateTimeString(),
        ];
    }
}
