<?php

namespace App\Http\Resources\V1;

use App\Enums\Niche;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Niche
 */
class NicheResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return ['slug' => $this->value, 'name' => $this->displayName()];
    }
}
