<?php

/*
 * These tests run real requests in separate PHP processes against committed rows, because a transaction-wrapped
 * test process would hide its fixtures from the workers. The database is cleaned by hand in afterEach so the
 * committed rows never leak into other tests.
 */

use App\Actions\IssueApiKey;
use App\Models\ApiUsage;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

const CONCURRENT_CALLS = 20;

function cleanCommittedRows(): void
{
    DB::statement('truncate api_usages, api_keys restart identity cascade');
}

beforeEach(function () {
    cleanCommittedRows();
});

afterEach(function () {
    cleanCommittedRows();
});

function nicheRequest(string $secret, ?string $idempotencyKey): Closure
{
    return function () use ($secret, $idempotencyKey): int {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'HTTP_ACCEPT' => 'application/json'];

        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        return app(Kernel::class)->handle(Request::create('/api/v1/niches', 'GET', server: $server))->getStatusCode();
    };
}

/**
 * Sends $count identical v1 niche reads from $count separate processes and returns their status codes.
 *
 * @return list<int>
 */
function fireConcurrently(string $secret, int $count, ?string $idempotencyKey): array
{
    $closures = [];

    for ($i = 0; $i < $count; $i++) {
        $closures[] = nicheRequest($secret, $idempotencyKey);
    }

    return array_values(Concurrency::driver('process')->run($closures));
}

function fireWithDistinctKeys(string $secret, int $count): void
{
    $closures = [];

    for ($i = 1; $i <= $count; $i++) {
        $closures[] = nicheRequest($secret, 'key-'.$i);
    }

    Concurrency::driver('process')->run($closures);
}

/**
 * Commits the key so worker processes can see it.
 */
function committedSecret(): string
{
    $secret = app(IssueApiKey::class)->handle('concurrent')->secret;

    while (DB::transactionLevel() > 0) {
        DB::commit();
    }

    return $secret;
}

it('bills exactly one of many overlapping requests that share an Idempotency-Key', function () {
    $statuses = fireConcurrently(committedSecret(), CONCURRENT_CALLS, 'same-retry');

    expect($statuses)->each->toBe(200)
        ->and(ApiUsage::count())->toBe(CONCURRENT_CALLS)
        ->and(ApiUsage::where('billed', true)->count())->toBe(1)
        ->and(ApiUsage::where('billed', false)->count())->toBe(CONCURRENT_CALLS - 1);
});

it('bills every overlapping request that carries no Idempotency-Key', function () {
    $statuses = fireConcurrently(committedSecret(), CONCURRENT_CALLS, null);

    expect($statuses)->each->toBe(200)
        ->and(ApiUsage::where('billed', true)->count())->toBe(CONCURRENT_CALLS);
});

it('bills every overlapping request that carries its own Idempotency-Key', function () {
    fireWithDistinctKeys(committedSecret(), CONCURRENT_CALLS);

    expect(ApiUsage::where('billed', true)->count())->toBe(CONCURRENT_CALLS);
});
