<?php

namespace App\Http\Exceptions\V1;

use App\Enums\ApiErrorCode;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class ApiErrorException extends RuntimeException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        private readonly ApiErrorCode $errorCode,
        private readonly string $errorMessage,
        private readonly ?string $nextStep = null,
        private readonly array $details = [],
    ) {
        parent::__construct($errorMessage);
    }

    public function code(): ApiErrorCode
    {
        return $this->errorCode;
    }

    public function message(): string
    {
        return $this->errorMessage;
    }

    public function nextStep(): string
    {
        return $this->nextStep ?? $this->errorCode->defaultNextStep();
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }
}
