<?php

namespace App\DTO;

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;

final readonly class AdSearchFilters
{
    public function __construct(
        public ?string $q = null,
        public ?Niche $niche = null,
        public ?AdFormat $format = null,
        public ?AdStatus $status = null,
        public int $page = 1,
        public int $perPage = 20,
    ) {}
}
