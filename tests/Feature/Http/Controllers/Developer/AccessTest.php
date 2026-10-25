<?php

use App\Http\Middleware\EnsureLocalEnvironment;
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
