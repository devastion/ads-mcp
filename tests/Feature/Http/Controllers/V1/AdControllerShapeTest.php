<?php

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\Niche;
use App\Models\Ad;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('returns exactly data, applied_filters, has_more and next_cursor at the top level', function () {
    Ad::factory()->withoutImpressions()->create();

    expect(array_keys(v1Search()->assertOk()->json()))
        ->toBe(['data', 'applied_filters', 'has_more', 'next_cursor']);
});

it('returns the eight item keys with the spec values', function () {
    Ad::factory()->withoutImpressions()->create([
        'brand_name' => 'Acme',
        'headline' => 'Dog gear',
        'niche' => Niche::PetSupplies,
        'format' => AdFormat::Video,
        'status' => AdStatus::Active,
        'start_date' => '2026-08-01',
    ]);

    $item = v1Search()->assertOk()->json('data.0');

    expect(array_keys($item))->toBe(['id', 'brand', 'headline', 'niche', 'format', 'status', 'start_date', 'days_active'])
        ->and($item)->toMatchArray([
            'brand' => 'Acme',
            'niche' => 'pet-supplies',
            'format' => 'video',
            'status' => 'active',
            'start_date' => '2026-08-01',
            'days_active' => 85,
        ]);
});

it('echoes every applied filter with all six keys, unapplied ones as null', function () {
    v1Search()->assertOk()->assertExactJson([
        'data' => [],
        'has_more' => false,
        'next_cursor' => null,
        'applied_filters' => [
            'q' => null,
            'niche' => [],
            'format' => null,
            'status' => null,
            'started_after' => null,
            'sort' => 'newest',
        ],
    ]);
});

it('rejects a per_page outside 1 to 100', function (string $query) {
    v1Search($query)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'per_page')
        ->assertJsonPath('error.retryable', false);
})->with(['zero' => ['per_page=0'], 'too big' => ['per_page=500'], 'text' => ['per_page=abc']]);

it('uses 20 items by default and returns has_more when there are more', function () {
    Ad::factory()->count(21)->sequence(fn ($sequence) => [
        'brand_id' => $sequence->index + 1,
        'brand_name' => 'B'.$sequence->index,
        'impression_rank' => null,
    ])->create();

    v1Search()->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('has_more', true);
});

it('honours an explicit per_page', function () {
    Ad::factory()->count(3)->sequence(fn ($sequence) => [
        'brand_id' => $sequence->index + 1,
        'brand_name' => 'B'.$sequence->index,
        'impression_rank' => null,
    ])->create();

    v1Search('per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('has_more', true);
});
