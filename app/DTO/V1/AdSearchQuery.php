<?php

namespace App\DTO\V1;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use Carbon\CarbonImmutable;

/**
 * @phpstan-type AppliedFilters array{q: ?string, niche: list<string>, format: ?string, status: ?string, started_after: ?string, sort: string}
 */
final readonly class AdSearchQuery
{
    /**
     * @param  list<string>  $niches
     */
    public function __construct(
        public ?string $q,
        public array $niches,
        public ?AdFormat $format,
        public ?AdStatus $status,
        public ?CarbonImmutable $startedAfter,
        public string $sort,
        public int $perPage,
        public ?string $cursor,
    ) {}

    /**
     * Binds a cursor to the filters and sort it was issued for. Excludes per_page and cursor.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->appliedFilters(), JSON_THROW_ON_ERROR));
    }

    /**
     * The filters as the caller effectively applied them. Every key is always present.
     *
     * @return AppliedFilters
     */
    public function appliedFilters(): array
    {
        $niches = array_values(array_unique($this->niches));
        sort($niches);

        return [
            'q' => $this->q,
            'niche' => $niches,
            'format' => $this->format?->value,
            'status' => $this->status?->value,
            'started_after' => $this->startedAfter?->format('Y-m-d'),
            'sort' => $this->sort,
        ];
    }
}
