<?php

namespace App\Actions;

use App\Models\ApiUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class CountBillableCalls
{
    /**
     * Billed ledger rows per key in the UTC month containing $now, with an entry (possibly 0) for every requested key.
     *
     * @param  iterable<int>  $keyIds
     * @return array<int, int>
     */
    public function handle(iterable $keyIds, CarbonInterface $now): array
    {
        $ids = collect($keyIds)->map(fn (int|string $id): int => (int) $id)->unique()->values();
        $start = CarbonImmutable::instance($now)->utc()->startOfMonth();

        /** @var array<int, int> $counts */
        $counts = ApiUsage::query()
            ->whereIn('api_key_id', $ids)
            ->where('billed', true)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $start->addMonth())
            ->groupBy('api_key_id')
            ->selectRaw('api_key_id, count(*) as used')
            ->pluck('used', 'api_key_id')
            ->map(fn (mixed $used): int => (int) $used)
            ->all();

        return $ids->mapWithKeys(fn (int $id): array => [$id => $counts[$id] ?? 0])->all();
    }

    public function resetsAt(CarbonInterface $now): CarbonImmutable
    {
        return CarbonImmutable::instance($now)->utc()->startOfMonth()->addMonth();
    }
}
