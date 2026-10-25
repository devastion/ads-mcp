<?php

namespace App\DTO;

use App\Enums\ApiErrorCode;

/**
 * A tool-level failure, rendered as the shared error envelope inside an isError tool result.
 */
final readonly class McpToolError
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public ApiErrorCode $code,
        public string $message,
        public string $nextStep,
        public array $details = [],
    ) {}

    /**
     * @return array{error: array{code: string, message: string, next_step: string, retryable: bool, details?: array<string, mixed>}}
     */
    public function toEnvelope(): array
    {
        $error = [
            'code' => $this->code->value,
            'message' => $this->message,
            'next_step' => $this->nextStep,
            'retryable' => $this->code->retryable(),
        ];

        if ($this->details !== []) {
            $error['details'] = $this->details;
        }

        return ['error' => $error];
    }
}
