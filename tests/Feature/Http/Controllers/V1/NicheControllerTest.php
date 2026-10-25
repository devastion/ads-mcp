<?php

use App\Enums\Niche;
use Illuminate\Testing\TestResponse;

function v1Niches(string $query = ''): TestResponse
{
    return test()->getJson('/api/v1/niches'.($query === '' ? '' : '?'.$query), ['Authorization' => 'Bearer '.v1Key()]);
}

it('lists every niche as slug and display name in enum order', function () {
    $expected = array_map(
        fn (Niche $niche): array => ['slug' => $niche->value, 'name' => $niche->displayName()],
        Niche::cases(),
    );

    v1Niches()->assertOk()->assertExactJson(['data' => $expected]);
});

it('returns all twelve niches', function () {
    expect(v1Niches()->assertOk()->json('data'))->toHaveCount(12);
});

it('stays within the two KiB niche budget', function () {
    expect(strlen((string) v1Niches()->assertOk()->getContent()))->toBeLessThanOrEqual(2048);
});

it('requires a Bearer key', function () {
    $this->getJson('/api/v1/niches')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'api_key_missing');
});

it('rejects any query parameter as unknown', function () {
    v1Niches('niche=skincare')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'unknown_parameter')
        ->assertJsonPath('error.details.parameter', 'niche');
});

it('rejects other methods with the v1 error shape', function () {
    $this->postJson('/api/v1/niches', [], ['Authorization' => 'Bearer '.v1Key()])
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});
