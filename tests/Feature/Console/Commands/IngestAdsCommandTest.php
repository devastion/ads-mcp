<?php

use App\Models\Ad;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('runs the requested number of ticks and sleeps one second between them', function () {
    Sleep::fake();
    Ad::factory()->count(2)->sequence(
        ['brand_id' => 1, 'impression_rank' => 1],
        ['brand_id' => 2, 'impression_rank' => 1],
    )->create();
    $before = Ad::count();

    $this->artisan('ads:ingest --ticks=3')
        ->expectsOutputToContain('deactivated')
        ->assertSuccessful();

    Sleep::assertSleptTimes(2);
    expect(Ad::count() - $before)->toBeBetween(3, 15);
});

it('fails with a hint when there are no ads to ingest from', function () {
    Sleep::fake();

    $this->artisan('ads:ingest --ticks=1')
        ->expectsOutputToContain('db:seed')
        ->assertFailed();

    Sleep::assertNeverSlept();
});
