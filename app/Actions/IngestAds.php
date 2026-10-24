<?php

namespace App\Actions;

use App\DTO\IngestResult;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Models\Ad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

class IngestAds
{
    public function __construct(private GenerateAdCopy $copy) {}

    /**
     * Discover 1–5 new ads for existing brands and deactivate 1–2 running ads.
     */
    public function handle(Randomizer $random): IngestResult
    {
        $brands = Ad::query()
            ->select('brand_id', 'brand_name', 'niche')
            ->distinct()
            ->orderBy('brand_id')
            ->get();

        if ($brands->isEmpty()) {
            return new IngestResult(inserted: 0, backdated: 0, deactivated: 0);
        }

        return DB::transaction(function () use ($random, $brands): IngestResult {
            $now = Carbon::now();
            $today = CarbonImmutable::today();

            $deactivated = $this->deactivate($random->getInt(1, 2), $today, $now);

            $rows = [];
            $backdated = 0;
            $nextRank = [];
            $insertCount = $random->getInt(1, 5);

            for ($i = 0; $i < $insertCount; $i++) {
                $brand = $brands[$random->getInt(0, $brands->count() - 1)];
                $niche = $brand->niche;
                $isBackdated = $random->getInt(1, 100) <= 30;
                $start = $isBackdated ? $today->subDays($random->getInt(1, 90)) : $today;
                if ($isBackdated) {
                    $backdated++;
                }

                $rank = null;
                if ($random->getInt(1, 100) > 40) {
                    $nextRank[$brand->brand_id] ??= (int) Ad::query()->where('brand_id', $brand->brand_id)->max('impression_rank');
                    $rank = ++$nextRank[$brand->brand_id];
                }

                $copy = $this->copy->handle($random, $niche);

                $rows[] = [
                    'brand_id' => $brand->brand_id,
                    'brand_name' => $brand->brand_name,
                    'headline' => $copy['headline'],
                    'body_text' => $copy['body_text'],
                    'niche' => $niche->value,
                    'format' => AdFormat::cases()[$random->getInt(0, count(AdFormat::cases()) - 1)]->value,
                    'status' => AdStatus::Active->value,
                    'start_date' => $start->toDateString(),
                    'end_date' => null,
                    'impression_rank' => $rank,
                    'countries' => json_encode(['US'], JSON_THROW_ON_ERROR),
                    'discovered_at' => $now->toDateTimeString(),
                    'created_at' => $now->toDateTimeString(),
                    'updated_at' => $now->toDateTimeString(),
                ];
            }

            DB::table('ads')->insert($rows);

            return new IngestResult(inserted: $insertCount, backdated: $backdated, deactivated: $deactivated);
        });
    }

    private function deactivate(int $count, CarbonImmutable $today, Carbon $now): int
    {
        $ids = Ad::query()
            ->where('status', AdStatus::Active)
            ->inRandomOrder()
            ->limit($count)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        Ad::query()->whereIn('id', $ids)->update([
            'status' => AdStatus::Inactive->value,
            'end_date' => $today->toDateString(),
            'updated_at' => $now->toDateTimeString(),
        ]);

        return $ids->count();
    }
}
