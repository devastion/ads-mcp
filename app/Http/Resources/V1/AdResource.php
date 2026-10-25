<?php

namespace App\Http\Resources\V1;

use App\Models\Ad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ad
 */
class AdResource extends JsonResource
{
    /**
     * Exactly the eight keys v1 callers read. The niche is the slug the filter accepts.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand' => $this->brand_name,
            'headline' => $this->headline,
            'niche' => $this->niche->value,
            'format' => $this->format->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date->format('Y-m-d'),
            'days_active' => $this->daysActive(),
        ];
    }
}
