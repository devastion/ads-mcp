<?php

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Support\Carbon;

it('counts days from start date to today for an active ad', function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));

    $ad = Ad::factory()->active()->create(['start_date' => '2026-08-01']);

    expect($ad->daysActive())->toBe(85);
});

it('counts days from start date to end date for an inactive ad', function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));

    $ad = Ad::factory()->inactive()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-03-01',
    ]);

    expect($ad->daysActive())->toBe(59);
});

it('returns zero for an active ad that started today', function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));

    $ad = Ad::factory()->active()->create(['start_date' => '2026-10-25']);

    expect($ad->daysActive())->toBe(0);
});

it('keeps counting for an active ad as days pass', function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
    $ad = Ad::factory()->active()->create(['start_date' => '2026-10-20']);

    $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));

    expect($ad->daysActive())->toBe(8);
});

it('casts niche, format, status and countries', function () {
    $ad = Ad::factory()->create([
        'niche' => Niche::PetSupplies,
        'format' => AdFormat::Video,
        'countries' => ['US', 'GB'],
    ])->refresh();

    expect($ad->niche)->toBe(Niche::PetSupplies)
        ->and($ad->format)->toBe(AdFormat::Video)
        ->and($ad->status)->toBe(AdStatus::Active)
        ->and($ad->countries)->toBe(['US', 'GB']);
});

it('builds active ads with no end date and inactive ads with one from the factory', function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));

    $active = Ad::factory()->active()->create();
    $inactive = Ad::factory()->inactive()->create(['impression_rank' => 2]);

    expect($active->end_date)->toBeNull()
        ->and($inactive->status)->toBe(AdStatus::Inactive)
        ->and($inactive->end_date)->not->toBeNull()
        ->and($inactive->end_date->gte($inactive->start_date))->toBeTrue();
});

it('stores a null impression rank for ads without impression data', function () {
    $ad = Ad::factory()->withoutImpressions()->create();

    expect($ad->refresh()->impression_rank)->toBeNull();
});
