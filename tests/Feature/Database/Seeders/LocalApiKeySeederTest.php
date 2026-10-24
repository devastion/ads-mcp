<?php

use App\Models\ApiKey;
use Database\Seeders\AdSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalApiKeySeeder;

it('creates the fixed local dev key idempotently', function () {
    $this->seed(LocalApiKeySeeder::class);
    $this->seed(LocalApiKeySeeder::class);

    expect(ApiKey::count())->toBe(1)
        ->and(ApiKey::findBySecret(LocalApiKeySeeder::SECRET))->not->toBeNull();
});

it('creates the dev key through the full seeder only in the local environment', function () {
    $this->app->detectEnvironment(fn () => 'local');
    $this->seed(AdSeeder::class);
    expect(ApiKey::count())->toBe(0);

    $this->seed(DatabaseSeeder::class);

    expect(ApiKey::findBySecret(LocalApiKeySeeder::SECRET))->not->toBeNull();
});

it('does not create the dev key outside the local environment', function () {
    $this->seed(DatabaseSeeder::class);

    expect(ApiKey::count())->toBe(0);
});
