<?php

use App\Models\Ad;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('returns only ads whose headline or body contains q, case-insensitively', function () {
    $headline = Ad::factory()->withoutImpressions()->create(['headline' => 'Glow SERUM', 'body_text' => 'x']);
    $body = Ad::factory()->withoutImpressions()->create(['headline' => 'Shampoo', 'body_text' => 'with serum']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'Tent', 'body_text' => 'backpack']);

    $ids = collect(v1Search('q=serum')->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$headline->id, $body->id])->sort()->values()->all())
        ->and(v1Search('q=serum')->json('applied_filters.q'))->toBe('serum');
});

it('treats percent and underscore in q as literal characters', function () {
    Ad::factory()->withoutImpressions()->create(['headline' => '50% off', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => '500 off', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'snake_case', 'body_text' => 'x']);
    Ad::factory()->withoutImpressions()->create(['headline' => 'snakeXcase', 'body_text' => 'x']);

    v1Search('q='.rawurlencode('50%'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.headline', '50% off');
    v1Search('q='.rawurlencode('snake_case'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.headline', 'snake_case');
});

it('rejects a q shorter than two characters', function () {
    v1Search('q=a')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_parameter')
        ->assertJsonPath('error.details.parameter', 'q')
        ->assertJsonPath('error.message', 'Query parameter "q" must be at least 2 characters.');
});

it('rejects a q longer than 200 characters', function () {
    v1Search('q='.str_repeat('a', 201))
        ->assertStatus(422)
        ->assertJsonPath('error.details.parameter', 'q')
        ->assertJsonPath('error.message', 'Query parameter "q" must be at most 200 characters.');
});
