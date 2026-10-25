<?php

namespace App\Actions;

use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Models\ApiKey;

class CheckApiQuota
{
    public const MONTHLY_BILLED_CALLS = 1000;

    public function __construct(
        private readonly RecordApiUsage $usage,
        private readonly CountBillableCalls $counter,
    ) {}

    /**
     * Refuse the call when the key has used its billed calls for this UTC month. A repeat of an already
     * billed Idempotency-Key is admitted, because it would not be billed.
     *
     * @throws ApiErrorException
     */
    public function handle(ApiKey $key, ?string $idempotencyKey): void
    {
        $now = now()->utc();

        $used = $this->counter->handle([$key->getKey()], $now)[$key->getKey()];

        if ($used < self::MONTHLY_BILLED_CALLS) {
            return;
        }

        if ($idempotencyKey !== null && $this->usage->alreadyBilled($key, hash('sha256', $idempotencyKey))) {
            return;
        }

        $resetsAt = $this->counter->resetsAt($now)->toIso8601String();

        throw new ApiErrorException(
            ApiErrorCode::QuotaExceeded,
            'The monthly quota of '.self::MONTHLY_BILLED_CALLS.' billable calls is used up.',
            'Retrying will not help before the quota resets at '.$resetsAt.'.',
            ['resets_at' => $resetsAt],
        );
    }
}
