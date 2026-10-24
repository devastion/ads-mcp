<?php

use App\Enums\Niche;
use App\Models\Ad;
use Database\Seeders\AdSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('seeds 20000 ads across 300 brands and all 12 niches', function () {
    $this->seed(AdSeeder::class);

    expect(Ad::count())->toBe(20000)
        ->and(Ad::query()->distinct()->count('brand_id'))->toBe(300)
        ->and(Ad::query()->distinct()->count('niche'))->toBe(12);
});

it('seeds roughly 60% active ads with an end date only on inactive ads', function () {
    $this->seed(AdSeeder::class);

    $active = Ad::query()->where('status', 'active')->count();

    expect($active / 20000)->toBeBetween(0.55, 0.65)
        ->and(Ad::query()->where('status', 'active')->whereNull('end_date')->count())->toBe($active)
        ->and(Ad::query()->whereNotNull('end_date')->whereColumn('end_date', '<', 'start_date')->count())->toBe(0);
});

it('spreads start dates over the last two years', function () {
    $this->seed(AdSeeder::class);

    $earliest = Carbon::parse(Ad::query()->min('start_date'));
    $latest = Carbon::parse(Ad::query()->max('start_date'));

    expect($earliest->gte(Carbon::parse('2026-10-25')->subDays(729)))->toBeTrue()
        ->and($latest->lte(Carbon::parse('2026-10-25')))->toBeTrue();

    foreach (range(0, 7) as $bucket) {
        $from = Carbon::parse('2026-10-25')->subDays(729 - $bucket * 91);
        $to = $from->copy()->addDays(90);

        expect(Ad::query()->whereBetween('start_date', [$from->toDateString(), $to->toDateString()])->exists())
            ->toBeTrue("no ads started between {$from->toDateString()} and {$to->toDateString()}");
    }
});

it('leaves roughly 40% of impression ranks empty and ranks each brand from 1 without gaps', function () {
    $this->seed(AdSeeder::class);

    $nullShare = Ad::query()->whereNull('impression_rank')->count() / 20000;

    expect($nullShare)->toBeBetween(0.35, 0.45);

    $brands = DB::select('select brand_id, count(impression_rank) as ranked, max(impression_rank) as top from ads group by brand_id');

    expect($brands)->toHaveCount(300);

    foreach ($brands as $brand) {
        expect((int) $brand->top)->toBe((int) $brand->ranked, "brand {$brand->brand_id} has a rank gap");
    }
});

it('uses every niche keyword in some seeded headline', function () {
    $this->seed(AdSeeder::class);

    foreach (Niche::cases() as $niche) {
        $text = mb_strtolower(implode(' ', Ad::query()->where('niche', $niche)->pluck('headline')->all()));

        foreach ($niche->keywords() as $keyword) {
            expect(str_contains($text, mb_strtolower($keyword)))
                ->toBeTrue("keyword [{$keyword}] missing from {$niche->value} headlines");
        }
    }
});

it('produces identical data when run twice on the same day', function () {
    $checksum = fn () => DB::selectOne(
        "select md5(string_agg(concat_ws('|', id, brand_id, brand_name, headline, body_text, niche, format, status, start_date, coalesce(end_date::text, ''), coalesce(impression_rank::text, ''), countries::text, discovered_at), ',' order by id)) as hash from ads",
    )->hash;

    $this->seed(AdSeeder::class);
    $first = $checksum();

    $this->seed(AdSeeder::class);

    expect($checksum())->toBe($first)
        ->and(Ad::min('id'))->toBe(1);
});

it('refuses to replace ads in production and leaves them untouched', function () {
    Ad::factory()->create(['brand_id' => 1, 'impression_rank' => 1]);
    $this->app->detectEnvironment(fn () => 'production');

    expect(fn () => app(AdSeeder::class)->run())->toThrow(RuntimeException::class);

    expect(Ad::count())->toBe(1);
});

it('keeps every brand within a narrow band of the average size', function () {
    $this->seed(AdSeeder::class);

    $sizes = Ad::query()->selectRaw('count(*) as n')->groupBy('brand_id')->pluck('n')->map(fn ($n) => (int) $n);

    expect($sizes->max())->toBeLessThan(100)
        ->and($sizes->min())->toBeGreaterThan(30);
});
