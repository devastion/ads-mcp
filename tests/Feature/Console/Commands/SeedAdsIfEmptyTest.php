<?php

use App\Models\Ad;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('seeds the database when there are no ads', function () {
    $this->artisan('ads:seed-if-empty')->assertSuccessful();

    expect(Ad::count())->toBe(20000);
});

it('leaves existing ads untouched when ads already exist', function () {
    Ad::factory()->count(3)->sequence(
        ['brand_id' => 1, 'impression_rank' => 1],
        ['brand_id' => 2, 'impression_rank' => 1],
        ['brand_id' => 3, 'impression_rank' => 1],
    )->create();

    $this->artisan('ads:seed-if-empty')
        ->expectsOutputToContain('already seeded')
        ->assertSuccessful();

    expect(Ad::count())->toBe(3);
});
