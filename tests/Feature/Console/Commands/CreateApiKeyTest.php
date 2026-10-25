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

it('stores the scopes passed with --scope', function () {
    $this->artisan('api-key:'.'create', ['name' => 'manager', '--scope' => ['search:read', 'keys:manage']])->assertSuccessful();

    expect(ApiKey::first()->scopes)->toBe(['search:read', 'keys:manage']);
});

it('defaults to the search:read scope', function () {
    $this->artisan('api-key:'.'create', ['name' => 'plain'])->assertSuccessful();

    expect(ApiKey::first()->scopes)->toBe(['search:read']);
});

it('refuses an unknown scope and lists the valid ones', function () {
    $this->artisan('api-key:'.'create', ['name' => 'bad', '--scope' => ['admin']])
        ->expectsOutputToContain('search:read, keys:manage')
        ->assertFailed();

    expect(ApiKey::count())->toBe(0);
});
