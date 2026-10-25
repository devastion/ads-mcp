<?php

namespace App\Http\Resources\V1;

use App\Models\Ad;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ad
 */
class AdDetailResource extends JsonResource
{
    /**
     * The full ad apart from the body, which the controller replaces with one page of text.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $countries = array_values(array_unique(array_map(
            fn (mixed $country): string => strtoupper((string) $country),
            $this->countries ?? [],
        )));
        sort($countries);

        return [
            'id' => $this->id,
            'brand_id' => $this->brand_id,
            'brand' => $this->brand_name,
            'headline' => $this->headline,
            'body_text' => $this->body_text,
            'niche' => $this->niche->value,
            'format' => $this->format->value,
            'status' => $this->status->value,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'days_active' => $this->daysActive(),
            'impression_rank' => $this->impression_rank,
            'countries' => $countries,
            'discovered_at' => $this->discovered_at->toIso8601String(),
        ];
    }
}
