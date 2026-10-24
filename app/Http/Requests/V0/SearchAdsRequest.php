<?php

namespace App\Http\Requests\V0;

use App\DTO\AdSearchFilters;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use Illuminate\Foundation\Http\FormRequest;

/**
 * v0 never rejects input. Invalid values are ignored, so do not add rules here.
 */
class SearchAdsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function filters(): AdSearchFilters
    {
        $niche = $this->stringOrNull('niche');
        $format = $this->stringOrNull('format');
        $status = $this->stringOrNull('status');

        return new AdSearchFilters(
            q: $this->stringOrNull('q'),
            niche: $niche === null ? null : Niche::fromSlugOrName($niche),
            format: $format === null ? null : AdFormat::tryFrom($format),
            status: $status === null ? null : AdStatus::tryFrom($status),
            page: $this->positiveInt('page', 1),
            perPage: min($this->positiveInt('per_page', 20), 100),
        );
    }

    /**
     * Arrays and empty values are invalid, so they are ignored rather than rejected.
     */
    private function stringOrNull(string $key): ?string
    {
        $value = $this->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function positiveInt(string $key, int $default): int
    {
        $value = filter_var($this->query($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($value) ? $value : $default;
    }
}
