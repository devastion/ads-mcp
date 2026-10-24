<?php

use App\Models\ApiKey;
use Illuminate\Support\Str;

afterEach(fn () => Str::createRandomStringsNormally());

it('prints the secret once when creating a key from the console', function () {
    Str::createRandomStringsUsing(fn (int $length) => str_repeat('a', $length));

    $this->artisan('api-key:create "my key"')
        ->expectsOutputToContain('ghk_'.str_repeat('a', 40))
        ->assertSuccessful();

    expect(ApiKey::count())->toBe(1)
        ->and(ApiKey::first()->name)->toBe('my key');
});
