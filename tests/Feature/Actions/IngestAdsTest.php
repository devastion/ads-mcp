<?php

use App\Actions\IngestAds;
use App\DTO\IngestResult;
use App\Enums\AdStatus;
use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Three brands with explicit, valid data: 10 active ads, ranks 1..k per brand.
 */
function seedThreeBrands(): void
{
    foreach ([[1, 'Acme', Niche::Skincare], [2, 'Bolt', Niche::Kitchen], [3, 'Cove', Niche::Outdoor]] as [$brandId, $brandName, $niche]) {
        Ad::factory()->count(3)->sequence(
            ['impression_rank' => 1],
            ['impression_rank' => 2],
            ['impression_rank' => 3],
        )->create([
            'brand_id' => $brandId,
            'brand_name' => $brandName,
            'niche' => $niche,
            'start_date' => '2026-10-01',
        ]);
    }
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('inserts between one and five new active ads for existing brands per tick', function () {
    seedThreeBrands();
    $existing = Ad::query()->get(['brand_id', 'brand_name', 'niche'])
        ->map(fn (Ad $ad) => $ad->brand_id.'|'.$ad->brand_name.'|'.$ad->niche->value)
        ->unique()
        ->all();
    $maxBefore = Ad::max('id');

    $result = app(IngestAds::class)->handle(new Randomizer(new Mt19937(7)));

    $new = Ad::query()->where('id', '>', $maxBefore)->get();

    expect($result->inserted)->toBeBetween(1, 5)
        ->and($new)->toHaveCount($result->inserted)
        ->and($new->every(fn (Ad $ad) => in_array($ad->brand_id.'|'.$ad->brand_name.'|'.$ad->niche->value, $existing, true)))->toBeTrue()
        ->and($new->every(fn (Ad $ad) => $ad->status === AdStatus::Active))->toBeTrue();
});

it('backdates about 30% of new ads by up to 90 days and discovers them today', function () {
    seedThreeBrands();
    $maxBefore = Ad::max('id');
    $random = new Randomizer(new Mt19937(11));
    $ingest = app(IngestAds::class);

    $backdatedFromResults = 0;
    for ($tick = 0; $tick < 300; $tick++) {
        $backdatedFromResults += $ingest->handle($random)->backdated;
    }

    $new = Ad::query()->where('id', '>', $maxBefore)->get();
    $today = Carbon::today();
    $backdated = $new->filter(fn (Ad $ad) => $ad->start_date->lt($today));

    expect($backdated->count() / $new->count())->toBeBetween(0.25, 0.35)
        ->and($backdated->count())->toBe($backdatedFromResults)
        ->and($backdated->every(fn (Ad $ad) => $ad->start_date->gte($today->copy()->subDays(90))))->toBeTrue()
        ->and($new->every(fn (Ad $ad) => $ad->discovered_at->isSameDay($today)))->toBeTrue();
});

it('deactivates one or two active ads per tick with today as the end date', function () {
    seedThreeBrands();
    $activeBefore = Ad::where('status', AdStatus::Active)->count();

    $result = app(IngestAds::class)->handle(new Randomizer(new Mt19937(5)));

    $deactivated = Ad::query()->where('status', AdStatus::Inactive)->get();

    expect($result->deactivated)->toBeBetween(1, 2)
        ->and($deactivated)->toHaveCount($result->deactivated)
        ->and(Ad::where('status', AdStatus::Active)->count())->toBe($activeBefore + $result->inserted - $result->deactivated)
        ->and($deactivated->every(fn (Ad $ad) => $ad->end_date->isSameDay(Carbon::today())))->toBeTrue();
});

it('keeps end date null exactly for active ads after many ticks', function () {
    seedThreeBrands();
    $random = new Randomizer(new Mt19937(21));
    $ingest = app(IngestAds::class);

    for ($tick = 0; $tick < 50; $tick++) {
        $ingest->handle($random);
    }

    expect(Ad::where('status', AdStatus::Active)->whereNotNull('end_date')->count())->toBe(0)
        ->and(Ad::where('status', AdStatus::Inactive)->whereNull('end_date')->count())->toBe(0);
});

it('continues each brand ranking after its highest rank without gaps', function () {
    seedThreeBrands();
    $random = new Randomizer(new Mt19937(99));
    $ingest = app(IngestAds::class);

    for ($tick = 0; $tick < 100; $tick++) {
        $ingest->handle($random);
    }

    $brands = Ad::query()
        ->selectRaw('brand_id, count(impression_rank) as ranked, max(impression_rank) as top')
        ->groupBy('brand_id')
        ->get();

    foreach ($brands as $brand) {
        expect((int) $brand->top)->toBe((int) $brand->ranked, "brand {$brand->brand_id} has a rank gap");
    }
});

it('does nothing when there are no ads to take brands from', function () {
    $result = app(IngestAds::class)->handle(new Randomizer(new Mt19937(1)));

    expect($result)->toEqual(new IngestResult(inserted: 0, backdated: 0, deactivated: 0))
        ->and(Ad::count())->toBe(0);
});
