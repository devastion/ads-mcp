<?php

use App\Actions\IssueApiKey;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function v1Key(): string
{
    return app(IssueApiKey::class)->handle('v1 contract test')->secret;
}

function v1Search(string $query = ''): TestResponse
{
    return test()->getJson('/api/v1/ads'.($query === '' ? '' : '?'.$query), ['Authorization' => 'Bearer '.v1Key()]);
}

/**
 * Follows next_cursor from $cursor (or from page 1 when null) and returns every id in order.
 *
 * @return list<int>
 */
function walkPages(string $query, ?string $cursor): array
{
    $ids = [];

    for ($page = 1; ; $page++) {
        if ($page > 1000) {
            test()->fail('The walk did not end within 1000 pages.');
        }

        $parts = array_filter([$query, $cursor === null ? null : 'cursor='.urlencode($cursor)], fn (?string $part): bool => $part !== null && $part !== '');
        $response = v1Search(implode('&', $parts));
        $response->assertOk();

        $ids = array_merge($ids, collect($response->json('data'))->pluck('id')->map(fn ($id): int => (int) $id)->all());

        if ($response->json('has_more') !== true) {
            return $ids;
        }

        $cursor = $response->json('next_cursor');

        if (! is_string($cursor)) {
            test()->fail('The page has more results but no next_cursor.');
        }
    }
}

/**
 * @return list<int>
 */
function walkAll(string $query): array
{
    return walkPages($query, null);
}

/**
 * @return list<int>
 */
function walkFrom(string $cursor, string $query): array
{
    return walkPages($query, $cursor);
}

function something()
{
    // ..
}
