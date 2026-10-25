<?php

use App\Enums\AdFormat;
use App\Models\Ad;

it('returns only ads of the requested format', function () {
    $video = Ad::factory()->withoutImpressions()->create(['format' => AdFormat::Video]);
    Ad::factory()->withoutImpressions()->create(['format' => AdFormat::Image]);

    expect(collect(v1Search('format=video')->json('data'))->pluck('id')->all())->toBe([$video->id])
        ->and(v1Search('format=video')->json('applied_filters.format'))->toBe('video');
});

it('rejects an unknown format and suggests the nearest value', function (string $value, string $expected) {
    v1Search('format='.$value)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'format')
        ->assertJsonPath('error.details.did_you_mean', $expected);
})->with(['capitalised' => ['Video', 'video'], 'typo' => ['vidoe', 'video']]);
