<?php

namespace App\Actions;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Models\Ad;
use Illuminate\Database\Eloquent\Builder;

class AdSearchPredicates
{
    /**
     * Filters shared by v0 and v1. Ordering and v1-only filters stay with each caller.
     *
     * @param  Builder<Ad>  $query
     * @param  list<string>  $niches
     * @return Builder<Ad>
     */
    public function apply(Builder $query, ?string $q, array $niches, ?AdFormat $format, ?AdStatus $status): Builder
    {
        return $query
            ->when($q !== null, fn ($query) => $query->where(function ($query) use ($q): void {
                $pattern = '%'.addcslashes((string) $q, '\\%_').'%';

                $query->whereLike('headline', $pattern)->orWhereLike('body_text', $pattern);
            }))
            ->when($niches !== [], fn ($query) => $query->whereIn('niche', $niches))
            ->when($format !== null, fn ($query) => $query->where('format', $format))
            ->when($status !== null, fn ($query) => $query->where('status', $status));
    }
}
