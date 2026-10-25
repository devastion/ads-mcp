<?php

use App\Actions\IssueApiKey;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
    $this->issued = app(IssueApiKey::class)->handle('limited');
    $this->bearer = ['Authorization' => 'Bearer '.$this->issued->secret];
});

it('answers the 61st v1 call with 429, Retry-After and the same wait in the body', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/niches', $this->bearer)->assertOk();
    }

    $this->travelTo(Carbon::parse('2026-10-25 12:00:15'));

    $response = $this->getJson('/api/v1/niches', $this->bearer)
        ->assertStatus(429)
        ->assertHeader('Retry-After', '45')
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.retryable', true)
        ->assertJsonPath('error.details.retry_after_seconds', 45);

    expect($response->json('error.next_step'))->not->toBeEmpty();
});

it('accepts the call again exactly when Retry-After says', function () {
    foreach (range(1, 61) as $_) {
        $this->getJson('/api/v1/niches', $this->bearer);
    }

    $this->travelTo(Carbon::parse('2026-10-25 12:00:59'));
    $this->getJson('/api/v1/niches', $this->bearer)->assertStatus(429)->assertHeader('Retry-After', '1');

    $this->travelTo(Carbon::parse('2026-10-25 12:01:00'));
    $this->getJson('/api/v1/niches', $this->bearer)->assertOk();
});

it('refuses the 61st v0 call with the frozen 400 body plus Retry-After', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)->assertOk();
    }

    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)
        ->assertStatus(400)
        ->assertExactJson(['error' => 'Invalid request'])
        ->assertHeader('Retry-After', '60');
});

it('shares one bucket across v0 and v1', function () {
    foreach (range(1, 30) as $_) {
        $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)->assertOk();
        $this->getJson('/api/v1/niches', $this->bearer)->assertOk();
    }

    $this->getJson('/api/v1/niches', $this->bearer)->assertStatus(429);
    $this->getJson('/api/v0/ads?api_key='.$this->issued->secret)->assertStatus(400)->assertHeader('Retry-After');
});

it('does not touch another key and does not spend units on bad credentials', function () {
    foreach (range(1, 70) as $_) {
        $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    }

    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/niches', $this->bearer)->assertOk();
    }

    $other = app(IssueApiKey::class)->handle('other');
    $this->getJson('/api/v1/niches', ['Authorization' => 'Bearer '.$other->secret])->assertOk();
});

it('spends a unit on failed requests from a valid key too', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('/api/v1/ads?nope=1', $this->bearer)->assertStatus(422);
    }

    $this->getJson('/api/v1/niches', $this->bearer)->assertStatus(429);
});
