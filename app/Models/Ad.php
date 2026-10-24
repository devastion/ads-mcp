<?php

namespace App\Models;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use Carbon\CarbonImmutable;
use Database\Factories\AdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property Niche $niche
 * @property AdFormat $format
 * @property AdStatus $status
 * @property CarbonImmutable $start_date
 * @property CarbonImmutable|null $end_date
 */
#[Fillable([
    'brand_id',
    'brand_name',
    'headline',
    'body_text',
    'niche',
    'format',
    'status',
    'start_date',
    'end_date',
    'impression_rank',
    'countries',
    'discovered_at',
])]
class Ad extends Model
{
    /** @use HasFactory<AdFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'niche' => Niche::class,
            'format' => AdFormat::class,
            'status' => AdStatus::class,
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'impression_rank' => 'integer',
            'countries' => 'array',
            'discovered_at' => 'immutable_datetime',
        ];
    }

    /**
     * Whole days the ad has run: start to today while active, start to end date once inactive.
     */
    public function daysActive(): int
    {
        $end = $this->status === AdStatus::Active
            ? Carbon::today()
            : ($this->end_date ?? throw new LogicException("Inactive ad {$this->id} has no end_date."));

        return max(0, (int) $this->start_date->startOfDay()->diffInDays($end->startOfDay(), false));
    }
}
