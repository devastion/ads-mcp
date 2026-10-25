<?php

use App\Models\Ad;

it('sorts newest by start date descending', function () {
    Ad::factory()->withoutImpressions()->create(['brand_id' => 1, 'start_date' => '2026-09-01']);
    Ad::factory()->withoutImpressions()->create(['brand_id' => 2, 'start_date' => '2026-10-10']);

    expect(collect(v1Search('sort=newest')->json('data'))->pluck('start_date')->all())->toBe(['2026-10-10', '2026-09-01']);
});

it('puts ads without impression data last in the impressions sort', function () {
    Ad::factory()->create(['brand_id' => 1, 'brand_name' => 'A', 'impression_rank' => null, 'start_date' => '2026-10-20']);
    Ad::factory()->create(['brand_id' => 2, 'brand_name' => 'B', 'impression_rank' => 3, 'start_date' => '2026-01-01']);
    Ad::factory()->create(['brand_id' => 3, 'brand_name' => 'C', 'impression_rank' => 1, 'start_date' => '2026-01-01']);

    expect(collect(v1Search('sort=impressions')->json('data'))->pluck('brand')->all())->toBe(['C', 'B', 'A']);
});

it('breaks impressions ties by id ascending', function () {
    $first = Ad::factory()->create(['brand_id' => 10, 'brand_name' => 'X', 'impression_rank' => 1]);
    $second = Ad::factory()->create(['brand_id' => 11, 'brand_name' => 'Y', 'impression_rank' => 1]);

    expect(collect(v1Search('sort=impressions')->json('data'))->pluck('id')->all())->toBe([$first->id, $second->id]);
});

it('adds the brand-relative limitation note only for the impressions sort', function () {
    Ad::factory()->withoutImpressions()->create(['brand_id' => 1]);

    expect(v1Search('sort=impressions')->json('notes.0'))->toContain('brand-relative');
    expect(v1Search('sort=newest')->json())->not->toHaveKey('notes');
});

it('rejects an unknown sort as invalid_parameter', function () {
    v1Search('sort=popular')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'sort');
});
