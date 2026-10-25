<?php

use App\Models\Ad;

it('returns only ads that started strictly after the date', function () {
    $after = Ad::factory()->withoutImpressions()->create(['start_date' => '2026-10-02']);
    Ad::factory()->withoutImpressions()->create(['start_date' => '2026-10-01']);
    Ad::factory()->withoutImpressions()->create(['start_date' => '2026-09-01']);

    expect(collect(v1Search('started_after=2026-10-01')->json('data'))->pluck('id')->all())->toBe([$after->id]);
});

it('echoes the started_after filter in applied_filters', function () {
    Ad::factory()->withoutImpressions()->create(['start_date' => '2026-10-02']);

    v1Search('started_after=2026-10-01')
        ->assertOk()
        ->assertJsonPath('applied_filters.started_after', '2026-10-01');
});

it('returns no ads for a future date without an error', function () {
    Ad::factory()->withoutImpressions()->create(['start_date' => '2026-10-01']);

    v1Search('started_after=2030-01-01')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects a date that is not a real calendar day', function (string $value) {
    v1Search('started_after='.$value)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'started_after');
})->with([
    'impossible day' => ['2026-02-30'],
    'impossible month' => ['2026-13-01'],
    'us format' => ['10/01/2026'],
    'no zero padding' => ['2026-1-1'],
]);
