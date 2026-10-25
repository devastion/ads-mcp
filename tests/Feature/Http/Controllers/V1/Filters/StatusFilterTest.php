<?php

use App\Enums\AdStatus;
use App\Models\Ad;

it('returns only ads with the requested status', function () {
    $active = Ad::factory()->withoutImpressions()->create(['status' => AdStatus::Active]);
    Ad::factory()->withoutImpressions()->inactive()->create();

    expect(collect(v1Search('status=active')->json('data'))->pluck('id')->all())->toBe([$active->id]);
});

it('returns inactive ads when status=inactive is requested', function () {
    Ad::factory()->withoutImpressions()->create(['status' => AdStatus::Active]);
    $inactive = Ad::factory()->withoutImpressions()->inactive()->create();

    expect(collect(v1Search('status=inactive')->json('data'))->pluck('id')->all())->toBe([$inactive->id]);
});

it('rejects an unknown status and suggests the nearest value', function () {
    v1Search('status=ACTIVE')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'status')
        ->assertJsonPath('error.details.did_you_mean', 'active');
});
