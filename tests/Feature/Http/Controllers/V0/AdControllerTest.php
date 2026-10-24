<?php

use App\Actions\IssueApiKey;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The v0 contract (ASSESSMENT.md §0.4; .agents/skills/v0-contract-guard/references/v0-contract.md).
 * Quirks are frozen on purpose: do not "fix" an assertion here to make a change pass.
 */
function v0Key(): string
{
    return app(IssueApiKey::class)->handle('contract test')->secret;
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('returns exactly data, page and total at the top level', function () {
    Ad::factory()->create();

    $response = $this->getJson('/api/v0/ads?api_key='.v0Key())->assertOk();

    expect(array_keys($response->json()))->toBe(['data', 'page', 'total']);
});

it('returns the eight spec keys per ad with the spec values', function () {
    Ad::factory()->create([
        'brand_name' => 'Acme',
        'headline' => 'Dog gear',
        'niche' => Niche::PetSupplies,
        'format' => AdFormat::Video,
        'status' => AdStatus::Active,
        'start_date' => '2026-08-01',
        'end_date' => null,
    ]);

    $item = $this->getJson('/api/v0/ads?api_key='.v0Key())->assertOk()->json('data.0');

    expect(array_keys($item))->toBe(['id', 'brand', 'headline', 'niche', 'format', 'status', 'start_date', 'days_active'])
        ->and($item['id'])->toBeInt()
        ->and($item)->toMatchArray([
            'brand' => 'Acme',
            'headline' => 'Dog gear',
            'niche' => 'Pet Supplies',
            'format' => 'video',
            'status' => 'active',
            'start_date' => '2026-08-01',
            'days_active' => 85,
        ]);
});

it('computes days active from the end date for inactive ads', function () {
    Ad::factory()->inactive()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-11',
    ]);

    $this->getJson('/api/v0/ads?api_key='.v0Key())
        ->assertOk()
        ->assertJsonPath('data.0.status', 'inactive')
        ->assertJsonPath('data.0.days_active', 10);
});

it('sorts by start date descending', function () {
    Ad::factory()->count(3)->sequence(
        ['start_date' => '2026-09-01', 'impression_rank' => null],
        ['start_date' => '2026-10-10', 'impression_rank' => null],
        ['start_date' => '2026-07-04', 'impression_rank' => null],
    )->create();

    $starts = collect($this->getJson('/api/v0/ads?api_key='.v0Key())->assertOk()->json('data'))->pluck('start_date')->all();

    expect($starts)->toBe(['2026-10-10', '2026-09-01', '2026-07-04']);
});

it('returns 20 ads by default on page 1', function () {
    Ad::factory()->count(25)->withoutImpressions()->create();

    $this->getJson('/api/v0/ads?api_key='.v0Key())
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('page', 1)
        ->assertJsonPath('total', 25);
});

it('returns the next slice on page 2 with the oldest ads', function () {
    Ad::factory()->count(25)->sequence(fn ($sequence) => [
        'start_date' => Carbon::parse('2026-10-25')->subDays($sequence->index),
        'impression_rank' => null,
    ])->create();

    $page2 = $this->getJson('/api/v0/ads?api_key='.v0Key().'&page=2')->assertOk();

    expect($page2->json('data'))->toHaveCount(5)
        ->and($page2->json('page'))->toBe(2)
        ->and(collect($page2->json('data'))->pluck('start_date')->all())
        ->toBe(['2026-10-05', '2026-10-04', '2026-10-03', '2026-10-02', '2026-10-01']);
});

it('clamps a per page value above 100 to 100', function () {
    Ad::factory()->count(120)->withoutImpressions()->create();

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&per_page=500')
        ->assertOk()
        ->assertJsonCount(100, 'data')
        ->assertJsonPath('total', 120);
});

it('falls back to defaults for invalid page and per page values', function (string $query, int $expectedPage, int $expectedRows) {
    Ad::factory()->count(25)->withoutImpressions()->create();

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&'.$query)
        ->assertOk()
        ->assertJsonPath('page', $expectedPage)
        ->assertJsonCount($expectedRows, 'data');
})->with([
    'page zero' => ['page=0', 1, 20],
    'page negative' => ['page=-1', 1, 20],
    'page text' => ['page=abc', 1, 20],
    'page fraction' => ['page=1.5', 1, 20],
    'per page zero' => ['per_page=0', 1, 20],
    'per page negative' => ['per_page=-5', 1, 20],
    'per page text' => ['per_page=abc', 1, 20],
    'per page fraction' => ['per_page=1.5', 1, 20],
]);

it('returns an empty page with the real total past the last page', function () {
    Ad::factory()->count(25)->withoutImpressions()->create();

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&page=9')
        ->assertOk()
        ->assertExactJson(['data' => [], 'page' => 9, 'total' => 25]);
});

it('returns 400 Invalid request for a missing, empty, unknown or array api key', function (string $url) {
    $this->getJson($url)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
})->with([
    'missing' => ['/api/v0/ads'],
    'empty' => ['/api/v0/ads?api_key='],
    'unknown' => ['/api/v0/ads?api_key=ghk_wrong'],
    'array' => ['/api/v0/ads?api_key[]=x'],
]);

it('returns 400 Invalid request for wrong methods and unknown paths under v0', function () {
    $key = v0Key();

    $this->postJson('/api/v0/ads?api_key='.$key)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);

    $this->getJson('/api/v0/nope?api_key='.$key)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});

it('returns 400 Invalid request when v0 throws unexpectedly', function () {
    Route::get('api/v0/boom', fn () => throw new RuntimeException('boom'));

    $this->getJson('/api/v0/boom?api_key='.v0Key())
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});

it('leaves error rendering outside v0 untouched', function () {
    $this->getJson('/api/v1/nope')
        ->assertNotFound()
        ->assertJsonMissing(['error' => 'Invalid request']);
});

it('ignores unknown and misspelled parameters', function () {
    Ad::factory()->count(3)->sequence(
        ['niche' => Niche::Skincare, 'start_date' => '2026-10-01', 'impression_rank' => 1],
        ['niche' => Niche::Kitchen, 'start_date' => '2026-10-10', 'impression_rank' => 2],
        ['niche' => Niche::Kitchen, 'start_date' => '2026-09-01', 'impression_rank' => null],
    )->create();
    $key = v0Key();
    $newestFirst = ['2026-10-10', '2026-10-01', '2026-09-01'];

    $this->getJson('/api/v0/ads?api_key='.$key.'&nich=skincare&sort=impressions&foo=1')
        ->assertOk()
        ->assertJsonPath('total', 3)
        ->assertJsonPath('data.0.start_date', $newestFirst[0])
        ->assertJsonPath('data.1.start_date', $newestFirst[1])
        ->assertJsonPath('data.2.start_date', $newestFirst[2]);
});

it('matches q case-insensitively in the headline or the body text', function () {
    Ad::factory()->withoutImpressions()->create(['headline' => 'Glow serum', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'Shampoo', 'body_text' => 'our serum here']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'Other', 'body_text' => 'Other']);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&q=SERUM')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonCount(2, 'data');
});

it('matches q as a literal substring so percent and underscore are not wildcards', function (string $q, string $expectedHeadline) {
    Ad::factory()->withoutImpressions()->create(['headline' => '50% off', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => '500 off', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'a_b', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'axb', 'body_text' => 'x']);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&q='.rawurlencode($q))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.headline', $expectedHeadline);
})->with([
    'percent' => ['50%', '50% off'],
    'underscore' => ['a_b', 'a_b'],
]);

it('ignores an empty or whitespace q', function (string $query) {
    Ad::factory()->count(3)->withoutImpressions()->create();

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&'.$query)
        ->assertOk()
        ->assertJsonPath('total', 3);
})->with(['empty' => ['q='], 'whitespace' => ['q=%20%20']]);

it('trims surrounding whitespace from q', function () {
    Ad::factory()->withoutImpressions()->create(['headline' => 'Glow serum', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'Shampoo', 'body_text' => 'x']);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&q=%20serum%20')
        ->assertOk()
        ->assertJsonPath('total', 1);
});

it('filters niche by slug or display name in any case', function (string $niche) {
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::PetSupplies]);
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Kitchen]);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&niche='.rawurlencode($niche))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.niche', 'Pet Supplies');
})->with(['slug' => ['pet-supplies'], 'display name' => ['Pet Supplies'], 'upper case' => ['PET SUPPLIES'], 'lower case display' => ['pet supplies']]);

it('matches a display name containing an ampersand', function () {
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::BabyKids]);
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Kitchen]);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&niche='.rawurlencode('Baby & Kids'))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.niche', 'Baby & Kids');
});

it('filters format and status by exact value', function () {
    Ad::factory()->withoutImpressions()->create(['format' => AdFormat::Video, 'status' => AdStatus::Active]);
    Ad::factory()->withoutImpressions()->inactive()->create(['format' => AdFormat::Video]);
    Ad::factory()->withoutImpressions()->create(['format' => AdFormat::Image, 'status' => AdStatus::Active]);
    $key = v0Key();

    $this->getJson('/api/v0/ads?api_key='.$key.'&format=video')
        ->assertOk()
        ->assertJsonPath('total', 2);

    $this->getJson('/api/v0/ads?api_key='.$key.'&status=inactive')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.status', 'inactive');
});

it('ignores invalid filter values and returns unfiltered results', function (string $query) {
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Skincare, 'format' => AdFormat::Video, 'status' => AdStatus::Active, 'headline' => 'serum']);
    Ad::factory()->withoutImpressions()->inactive()->create(['niche' => Niche::Kitchen, 'format' => AdFormat::Image]);
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Outdoor, 'format' => AdFormat::Carousel, 'status' => AdStatus::Active]);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&'.$query)
        ->assertOk()
        ->assertJsonPath('total', 3);
})->with([
    'unknown format' => ['format=gif'],
    'capitalised format' => ['format=Video'],
    'capitalised status' => ['status=ACTIVE'],
    'unknown status' => ['status=paused'],
    'unknown niche' => ['niche=nope'],
    'array niche' => ['niche[]=skincare'],
    'array format' => ['format[]=video'],
    'array q' => ['q[]=serum'],
]);

it('applies the last value of a repeated parameter', function () {
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Skincare]);
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Kitchen]);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&niche=skincare&niche=kitchen')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.niche', 'Kitchen');
});

it('combines q, niche, format and status filters', function () {
    Ad::factory()->withoutImpressions()->create(['headline' => 'Glow serum', 'niche' => Niche::Skincare, 'format' => AdFormat::Video, 'status' => AdStatus::Active]);
    Ad::factory()->withoutImpressions()->create(['headline' => 'Glow serum', 'niche' => Niche::Skincare, 'format' => AdFormat::Image, 'status' => AdStatus::Active]);
    Ad::factory()->withoutImpressions()->create(['headline' => 'Glow serum', 'niche' => Niche::Kitchen, 'format' => AdFormat::Video, 'status' => AdStatus::Active]);
    Ad::factory()->withoutImpressions()->inactive()->create(['headline' => 'Glow serum', 'niche' => Niche::Skincare, 'format' => AdFormat::Video]);

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&q=serum&niche=skincare&format=video&status=active')
        ->assertOk()
        ->assertJsonPath('total', 1);
});

it('returns an empty page, not an error, for a page number whose offset overflows', function () {
    Ad::factory()->count(25)->withoutImpressions()->create();

    $this->getJson('/api/v0/ads?api_key='.v0Key().'&page=9223372036854775807')
        ->assertOk()
        ->assertExactJson(['data' => [], 'page' => 9223372036854775807, 'total' => 25]);
});

it('orders v0 results by start date only, with no tiebreaker', function () {
    // The spec says "start_date descending (no tiebreaker)". Ties are unspecified, so this pins the
    // emitted ORDER BY rather than the output order, which a tiebreaker would change only for equal dates.
    Ad::factory()->count(2)->withoutImpressions()->create(['start_date' => '2026-10-01']);
    $orders = [];
    DB::listen(function (QueryExecuted $query) use (&$orders) {
        if (str_contains($query->sql, 'from "ads"') && str_contains($query->sql, 'limit')) {
            $orders[] = trim(Str::after(Str::before($query->sql, ' limit'), 'order by'));
        }
    });

    $this->getJson('/api/v0/ads?api_key='.v0Key())->assertOk();

    expect($orders)->toBe(['"start_date" desc']);
});
