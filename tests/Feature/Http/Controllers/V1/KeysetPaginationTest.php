<?php

use App\Actions\V1\CursorCodec;
use App\Models\Ad;

it('walks every newest page once and returns every matching ad', function () {
    $ids = collect(range(1, 7))->map(fn (int $day): int => Ad::factory()->withoutImpressions()->create([
        'brand_id' => $day,
        'brand_name' => "Brand {$day}",
        'start_date' => "2026-10-0{$day}",
    ])->id)->all();

    $walked = walkAll('sort=newest&per_page=3');

    expect($walked)->toHaveCount(7)
        ->and(collect($walked)->unique()->count())->toBe(7)
        ->and(collect($walked)->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
});

it('walks newest ads that share a start date without repeats or skips', function () {
    $ids = collect(range(1, 5))->map(fn (int $brand): int => Ad::factory()->withoutImpressions()->create([
        'brand_id' => $brand,
        'brand_name' => "Brand {$brand}",
        'start_date' => '2026-10-05',
    ])->id)->all();

    $walked = walkAll('sort=newest&per_page=2');

    expect(collect($walked)->unique()->count())->toBe(5)
        ->and(collect($walked)->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
});

it('walks every impressions page once, with null ranks last', function () {
    Ad::factory()->create(['brand_id' => 1, 'brand_name' => 'A', 'impression_rank' => 2]);
    Ad::factory()->create(['brand_id' => 2, 'brand_name' => 'B', 'impression_rank' => 1]);
    Ad::factory()->create(['brand_id' => 3, 'brand_name' => 'C', 'impression_rank' => 2]);
    Ad::factory()->create(['brand_id' => 4, 'brand_name' => 'D', 'impression_rank' => 3]);
    Ad::factory()->withoutImpressions()->create(['brand_id' => 5, 'brand_name' => 'E']);
    Ad::factory()->withoutImpressions()->create(['brand_id' => 6, 'brand_name' => 'F']);
    Ad::factory()->withoutImpressions()->create(['brand_id' => 7, 'brand_name' => 'G']);

    $walked = walkAll('sort=impressions&per_page=2');
    $singlePage = collect(v1Search('sort=impressions&per_page=100')->json('data'))->pluck('id')->map(fn ($id): int => (int) $id)->all();

    expect(count($walked))->toBe(7)
        ->and(collect($walked)->unique()->count())->toBe(7)
        ->and($walked)->toBe($singlePage);
});

it('returns the id of the last returned row in next_cursor, not the extra fetched row', function () {
    $ids = collect(range(1, 3))->map(fn (int $brand): int => Ad::factory()->withoutImpressions()->create([
        'brand_id' => $brand,
        'brand_name' => "Brand {$brand}",
        'start_date' => now()->subDays($brand)->toDateString(),
    ])->id)->all();

    $response = v1Search('sort=newest&per_page=2');
    $payload = app(CursorCodec::class)->decode($response->json('next_cursor'));

    expect($response->json('has_more'))->toBeTrue()
        ->and($payload['i'])->toBe(collect($response->json('data'))->last()['id'])
        ->and($payload['i'])->toBe($ids[1]);
});

it('returns next_cursor null on the last page', function () {
    Ad::factory()->withoutImpressions()->create();

    v1Search('per_page=5')->assertJsonPath('has_more', false)->assertJsonPath('next_cursor', null);
});

it('keeps ads inserted after page 1 out of the walk', function () {
    $existing = collect(range(1, 4))->map(fn (int $brand): int => Ad::factory()->withoutImpressions()->create([
        'brand_id' => $brand,
        'brand_name' => "Brand {$brand}",
        'start_date' => now()->subDays($brand)->toDateString(),
    ])->id)->all();

    $first = v1Search('sort=newest&per_page=2');
    $late = Ad::factory()->withoutImpressions()->create([
        'brand_id' => 9,
        'brand_name' => 'Late',
        'start_date' => now()->subDays(2)->toDateString(),
    ])->id;

    $rest = walkFrom($first->json('next_cursor'), 'sort=newest&per_page=2');
    $seen = array_merge(collect($first->json('data'))->pluck('id')->all(), $rest);

    expect($seen)->not->toContain($late)
        ->and(collect($existing)->diff($seen)->values()->all())->toBe([]);
});
