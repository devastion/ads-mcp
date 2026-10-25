<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
});

it('returns 401 api_key_missing without a Bearer header', function () {
    $this->getJson('/api/v1/ads')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_missing')
        ->assertJsonPath('error.retryable', false);
});

it('returns 401 api_key_invalid for an unknown key', function () {
    $this->getJson('/api/v1/ads', ['Authorization' => 'Bearer ghk_wrong'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_invalid');
});

it('rejects a key sent in the query string with a message that says where to put it', function () {
    $response = $this->getJson('/api/v1/ads?api_key='.v1Key(), ['Authorization' => 'Bearer '.v1Key()])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_in_query');

    expect($response->json('error.next_step'))->toContain('Authorization');
});

it('accepts the same key on v0 by query and on v1 by header', function () {
    $key = v1Key();

    $this->getJson('/api/v0/ads?api_key='.$key)->assertOk();
    $this->getJson('/api/v1/ads', ['Authorization' => 'Bearer '.$key])->assertOk();
});

it('keeps v0 errors as the single 400 body, even for a bad key', function () {
    $this->getJson('/api/v0/ads?api_key=ghk_wrong')
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request']);
});
