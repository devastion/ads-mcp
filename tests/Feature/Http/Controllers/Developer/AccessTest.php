<?php

use App\Http\Middleware\EnsureLocalEnvironment;
use App\Models\ApiKey;
use Inertia\Testing\AssertableInertia;

it('renders the developer page when the app runs locally', function () {
    asLocal();

    $this->get('/developer')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('developer'));
});

it('hides the developer page outside the local environment', function (string $environment) {
    $this->withoutVite();
    app()->detectEnvironment(fn (): string => $environment);

    $this->get('/developer')->assertNotFound();
})->with(['production', 'staging', 'testing']);

it('hides developer mutations outside local even without a CSRF token', function (string $environment) {
    $key = ApiKey::factory()->create();
    app()->detectEnvironment(fn (): string => $environment);

    $this->postJson('/developer/keys', ['name' => 'Hidden', 'scopes' => ['search:read']])->assertNotFound();
    $this->deleteJson('/developer/keys/'.$key->id)->assertNotFound();

    $this->assertDatabaseCount('api_keys', 1);
    expect($key->fresh()->revoked_at)->toBeNull();
})->with(['production', 'staging']);

it('requires a CSRF token for developer key mutations while local', function () {
    asLocal();
    $key = ApiKey::factory()->create();

    $this->postJson('/developer/keys', ['name' => 'Unsigned', 'scopes' => ['search:read']])->assertStatus(419);
    $this->deleteJson('/developer/keys/'.$key->id)->assertStatus(419);

    $this->assertDatabaseCount('api_keys', 1);
    expect($key->fresh()->revoked_at)->toBeNull();
});

it('guards every developer route with the local-only middleware', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'developer'));

    expect($routes)->not->toBeEmpty()
        ->and($routes->every(fn ($route): bool => in_array(EnsureLocalEnvironment::class, $route->gatherMiddleware(), true)))->toBeTrue();
});

it('links to the developer page from the welcome page only when local', function () {
    asLocal();
    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->component('welcome')->where('isLocal', true));

    app()->detectEnvironment(fn (): string => 'production');
    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->component('welcome')->where('isLocal', false));
});
