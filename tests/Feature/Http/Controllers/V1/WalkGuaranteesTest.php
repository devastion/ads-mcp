<?php

use App\Actions\IngestAds;
use App\Models\Ad;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('does not show, skip or repeat ads when ingest runs between pages (newest)', function () {
    $existing = Ad::factory()->count(6)->withoutImpressions()->sequence(fn ($sequence) => [
        'start_date' => now()->subDays($sequence->index + 1)->toDateString(),
        'brand_id' => $sequence->index + 1,
        'brand_name' => 'B'.$sequence->index,
    ])->create()->pluck('id')->all();

    $first = v1Search('sort=newest&per_page=2');
    $cursor = $first->json('next_cursor');

    Ad::factory()->withoutImpressions()->create(['start_date' => now()->subDays(3)->toDateString(), 'brand_id' => 99, 'brand_name' => 'Late']);
    app(IngestAds::class)->handle(new Randomizer(new Mt19937(7)));
    Ad::query()->whereIn('id', [$existing[3]])->update(['status' => 'inactive', 'end_date' => now()->toDateString()]);

    $rest = walkFrom($cursor, 'sort=newest&per_page=2');
    $seen = array_merge($first->json('data.*.id'), $rest);

    expect($seen)->toHaveCount(collect($seen)->unique()->count())
        ->and(collect($existing)->diff($seen)->values()->all())->toBe([])
        ->and(collect($seen)->diff($existing)->all())->toBe([]);
});

it('does not show, skip or repeat ads when ingest runs between pages (impressions)', function () {
    $existing = collect([2, 1, 4, 3])->map(fn (int $rank, int $index): int => Ad::factory()->create([
        'brand_id' => $index + 1,
        'brand_name' => 'R'.$index,
        'impression_rank' => $rank,
    ])->id)->all();
    $unranked = Ad::factory()->withoutImpressions()->create(['brand_id' => 10, 'brand_name' => 'Unranked'])->id;
    $existing[] = $unranked;

    $first = v1Search('sort=impressions&per_page=2');
    $cursor = $first->json('next_cursor');

    Ad::factory()->create(['brand_id' => 20, 'brand_name' => 'Fresh', 'impression_rank' => 1]);
    app(IngestAds::class)->handle(new Randomizer(new Mt19937(11)));

    $rest = walkFrom($cursor, 'sort=impressions&per_page=2');
    $seen = array_merge($first->json('data.*.id'), $rest);

    expect($seen)->toHaveCount(collect($seen)->unique()->count())
        ->and(collect($existing)->diff($seen)->values()->all())->toBe([])
        ->and(collect($seen)->diff($existing)->all())->toBe([]);
});

it('keeps a backdated insert out of a newest walk that is already in progress', function () {
    $existing = Ad::factory()->count(4)->withoutImpressions()->sequence(fn ($sequence) => [
        'start_date' => now()->subDays($sequence->index + 1)->toDateString(),
        'brand_id' => $sequence->index + 1,
        'brand_name' => 'B'.$sequence->index,
    ])->create()->pluck('id')->all();

    $first = v1Search('sort=newest&per_page=1');
    $backdated = Ad::factory()->withoutImpressions()->create(['start_date' => now()->subDays(4)->toDateString(), 'brand_id' => 50, 'brand_name' => 'Old']);

    $seen = array_merge($first->json('data.*.id'), walkFrom($first->json('next_cursor'), 'sort=newest&per_page=1'));

    expect($seen)->toBe($existing)
        ->and($seen)->not->toContain($backdated->id);
});

it('still shows a deactivated ad to a walk with no status filter', function () {
    $existing = Ad::factory()->count(4)->withoutImpressions()->sequence(fn ($sequence) => [
        'start_date' => now()->subDays($sequence->index + 1)->toDateString(),
        'brand_id' => $sequence->index + 1,
        'brand_name' => 'B'.$sequence->index,
    ])->create()->pluck('id')->all();

    $first = v1Search('sort=newest&per_page=2');
    Ad::query()->whereIn('id', [$existing[3]])->update(['status' => 'inactive', 'end_date' => now()->toDateString()]);

    $seen = array_merge($first->json('data.*.id'), walkFrom($first->json('next_cursor'), 'sort=newest&per_page=2'));
    $deactivated = collect(v1Search('sort=newest&per_page=100')->json('data'))->firstWhere('id', $existing[3]);

    expect($seen)->toBe($existing)
        ->and($deactivated['status'])->toBe('inactive');
});

it('drops a deactivated ad from a walk with status=active', function () {
    $existing = Ad::factory()->count(4)->withoutImpressions()->sequence(fn ($sequence) => [
        'start_date' => now()->subDays($sequence->index + 1)->toDateString(),
        'brand_id' => $sequence->index + 1,
        'brand_name' => 'B'.$sequence->index,
    ])->create()->pluck('id')->all();

    $first = v1Search('sort=newest&status=active&per_page=2');
    Ad::query()->whereIn('id', [$existing[3]])->update(['status' => 'inactive', 'end_date' => now()->toDateString()]);

    $rest = walkFrom($first->json('next_cursor'), 'sort=newest&status=active&per_page=2');
    $seen = array_merge($first->json('data.*.id'), $rest);

    expect($seen)->toBe(array_slice($existing, 0, 3))
        ->and($seen)->not->toContain($existing[3]);
});

it('keeps a deactivated impressions ad in an unfiltered walk and drops it from status=active', function () {
    $existing = collect([1, 2, 3, 4])->map(fn (int $rank, int $index): int => Ad::factory()->create([
        'brand_id' => $index + 1,
        'brand_name' => 'R'.$index,
        'impression_rank' => $rank,
    ])->id)->all();

    $first = v1Search('sort=impressions&per_page=2');
    Ad::query()->whereIn('id', [$existing[2]])->update(['status' => 'inactive', 'end_date' => now()->toDateString()]);

    $unfiltered = array_merge($first->json('data.*.id'), walkFrom($first->json('next_cursor'), 'sort=impressions&per_page=2'));

    $activeFirst = v1Search('sort=impressions&status=active&per_page=2');
    $active = array_merge($activeFirst->json('data.*.id'), walkFrom($activeFirst->json('next_cursor'), 'sort=impressions&status=active&per_page=2'));

    expect($unfiltered)->toBe($existing)
        ->and($active)->toBe([$existing[0], $existing[1], $existing[3]]);
});
