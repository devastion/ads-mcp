<?php

use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('returns only ads in the requested niches when several are repeated', function () {
    $skin = Ad::factory()->withoutImpressions()->create(['niche' => Niche::Skincare]);
    $hair = Ad::factory()->withoutImpressions()->create(['niche' => Niche::Haircare]);
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Kitchen]);

    $ids = collect(v1Search('niche=skincare&niche=haircare')->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$skin->id, $hair->id])->sort()->values()->all())
        ->and(v1Search('niche=haircare&niche=skincare')->json('applied_filters.niche'))->toBe(['haircare', 'skincare']);
});

it('returns the single niche when it is sent once', function () {
    $skin = Ad::factory()->withoutImpressions()->create(['niche' => Niche::Skincare]);
    Ad::factory()->withoutImpressions()->create(['niche' => Niche::Haircare]);

    v1Search('niche=skincare')
        ->assertOk()
        ->assertJsonPath('applied_filters.niche', ['skincare'])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $skin->id);
});

it('rejects the comma-separated form and says how to repeat it', function () {
    v1Search('niche=skincare,haircare')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'niche');

    expect(v1Search('niche=skincare,haircare')->json('error.next_step'))
        ->toContain('niche=skincare&niche=haircare');
});

it('suggests the slug for a capitalised slug or display name', function (string $value, string $expected) {
    v1Search('niche='.rawurlencode($value))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.did_you_mean', $expected);
})->with(['capitalised' => ['Skincare', 'skincare'], 'display name' => ['Pet Supplies', 'pet-supplies']]);

it('rejects an unknown niche without a suggestion when nothing is close', function () {
    v1Search('niche=zzzzzz')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'niche')
        ->assertJsonMissingPath('error.details.did_you_mean');
});
