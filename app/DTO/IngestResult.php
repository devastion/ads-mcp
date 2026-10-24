<?php

namespace App\DTO;

final readonly class IngestResult
{
    public function __construct(
        public int $inserted,
        public int $backdated,
        public int $deactivated,
    ) {}
}
