<?php

namespace App\DTO;

use App\Models\ApiKey;

final readonly class IssuedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        public string $secret,
    ) {}
}
