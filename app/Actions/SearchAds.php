<?php

namespace App\Actions;

use App\DTO\AdSearchFilters;
use App\Models\Ad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchAds
{
    public function __construct(private AdSearchPredicates $predicates) {}

    /**
     * Newest first, with no tiebreaker: v0 callers depend on this exact ordering rule.
     *
     * @return LengthAwarePaginator<int, Ad>
     */
    public function handle(AdSearchFilters $filters): LengthAwarePaginator
    {
        $query = $this->matching($filters);
        $total = (clone $query)->count();
        $offset = ($filters->page - 1) * $filters->perPage;

        // A page past the end is empty. Skipping the query also stops huge page numbers overflowing the offset.
        $items = $offset >= $total
            ? []
            : $query->offset($offset)->limit($filters->perPage)->get()->all();

        return new LengthAwarePaginator($items, $total, $filters->perPage, $filters->page);
    }

    /**
     * @return Builder<Ad>
     */
    private function matching(AdSearchFilters $filters): Builder
    {
        $niches = $filters->niche === null ? [] : [$filters->niche->value];

        return $this->predicates
            ->apply(Ad::query(), $filters->q, $niches, $filters->format, $filters->status)
            ->orderByDesc('start_date');
    }
}
