<?php

namespace App\Actions\V1;

use App\Actions\AdSearchPredicates;
use App\DTO\V1\AdSearchQuery;
use App\Models\Ad;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SearchAdsV1
{
    public function __construct(private AdSearchPredicates $predicates) {}

    /**
     * Fetches one row more than the page size, so has_more is known without a count.
     *
     * @param  array{k: mixed, i: int}|null  $after  The last row of the previous page.
     * @return array{ads: Collection<int, Ad>, hasMore: bool}
     */
    public function handle(AdSearchQuery $query, int $cutoff, ?array $after): array
    {
        $ads = $this->predicates
            ->apply(Ad::query(), $query->q, $query->niches, $query->format, $query->status)
            ->when($query->startedAfter, fn (Builder $builder, CarbonImmutable $date): Builder => $builder->where('start_date', '>', $date->format('Y-m-d')))
            ->where('id', '<=', $cutoff)
            ->when($query->sort === 'impressions', fn (Builder $builder): Builder => $this->impressionsAfter($builder, $after))
            ->when($query->sort === 'newest', fn (Builder $builder): Builder => $this->newestAfter($builder, $after))
            ->when($query->sort === 'impressions', fn (Builder $builder): Builder => $builder->orderByRaw('impression_rank ASC NULLS LAST, id ASC'))
            ->when($query->sort === 'newest', fn (Builder $builder): Builder => $builder->orderByDesc('start_date')->orderByDesc('id'))
            ->limit($query->perPage + 1)
            ->get();

        return [
            'ads' => $ads->take($query->perPage)->values(),
            'hasMore' => $ads->count() > $query->perPage,
        ];
    }

    /**
     * @param  Builder<Ad>  $builder
     * @param  array{k: mixed, i: int}|null  $after
     * @return Builder<Ad>
     */
    private function newestAfter(Builder $builder, ?array $after): Builder
    {
        if ($after === null) {
            return $builder;
        }

        $startDate = (string) $after['k'];
        $id = (int) $after['i'];

        return $builder->where(fn (Builder $inner): Builder => $inner
            ->where('start_date', '<', $startDate)
            ->orWhere(fn (Builder $tied): Builder => $tied
                ->where('start_date', '=', $startDate)
                ->where('id', '<', $id)));
    }

    /**
     * @param  Builder<Ad>  $builder
     * @param  array{k: mixed, i: int}|null  $after
     * @return Builder<Ad>
     */
    private function impressionsAfter(Builder $builder, ?array $after): Builder
    {
        if ($after === null) {
            return $builder;
        }

        $id = (int) $after['i'];

        if ($after['k'] === null) {
            return $builder->whereNull('impression_rank')->where('id', '>', $id);
        }

        $rank = (int) $after['k'];

        return $builder->where(fn (Builder $inner): Builder => $inner
            ->where('impression_rank', '>', $rank)
            ->orWhere(fn (Builder $tied): Builder => $tied
                ->where('impression_rank', '=', $rank)
                ->where('id', '>', $id))
            ->orWhereNull('impression_rank'));
    }
}
