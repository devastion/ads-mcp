<?php

namespace App\Actions;

use App\DTO\AdSearchFilters;
use App\Models\Ad;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchAds
{
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
        return Ad::query()
            ->when($filters->q !== null, fn ($query) => $query->where(function ($query) use ($filters): void {
                $pattern = '%'.addcslashes((string) $filters->q, '\\%_').'%';

                $query->whereLike('headline', $pattern)->orWhereLike('body_text', $pattern);
            }))
            ->when($filters->niche, fn ($query, $niche) => $query->where('niche', $niche))
            ->when($filters->format, fn ($query, $format) => $query->where('format', $format))
            ->when($filters->status, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('start_date');
    }
}
