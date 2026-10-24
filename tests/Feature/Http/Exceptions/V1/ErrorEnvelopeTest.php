<?php

use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

it('renders a thrown ApiErrorException with the envelope', function () {
    Route::get('api/v1/probe-error', fn () => throw new ApiErrorException(
        ApiErrorCode::InvalidParameter, 'Bad value.', 'Use skincare.', ['parameter' => 'niche'],
    ));

    $this->getJson('/api/v1/probe-error')
        ->assertStatus(422)
        ->assertExactJson(['error' => [
            'code' => 'invalid_parameter',
            'message' => 'Bad value.',
            'next_step' => 'Use skincare.',
            'retryable' => false,
            'details' => ['parameter' => 'niche'],
        ]]);
});

it('does not report ApiErrorException through the exception handler', function () {
    $exception = new ApiErrorException(ApiErrorCode::InvalidParameter, 'Bad value.');

    $shouldReport = app(ExceptionHandler::class)->shouldReport($exception);

    expect($shouldReport)->toBeFalse();
});

it('gives a 405 with the Allow header and the envelope', function () {
    Route::get('api/v1/probe-method', fn () => 'ok');

    $this->postJson('/api/v1/probe-method')
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET, HEAD')
        ->assertJsonPath('error.code', 'method_not_allowed')
        ->assertJsonPath('error.retryable', false);
});

it('gives a 404 with the envelope for an unknown v1 path', function () {
    $this->getJson('/api/v1/nope')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.retryable', false);
});

it('gives a 429 with Retry-After and retryable true', function () {
    Route::get('api/v1/probe-limit', fn () => throw new TooManyRequestsHttpException(60));

    $this->getJson('/api/v1/probe-limit')
        ->assertStatus(429)
        ->assertHeader('Retry-After', '60')
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertJsonPath('error.retryable', true);
});

it('gives a generic 500 with no internal detail', function () {
    Route::get('api/v1/probe-boom', fn () => throw new RuntimeException('secret db password 123'));

    $this->getJson('/api/v1/probe-boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'internal_error')
        ->assertJsonPath('error.retryable', true)
        ->assertDontSee('secret db password');
});

it('keeps every code\'s status and retryable rule in one place', function (ApiErrorCode $code, int $status, bool $retryable) {
    expect($code->status())->toBe($status)->and($code->retryable())->toBe($retryable);
})->with([
    'api key missing' => [ApiErrorCode::ApiKeyMissing, 401, false],
    'rate limited' => [ApiErrorCode::RateLimited, 429, true],
    'internal error' => [ApiErrorCode::InternalError, 500, true],
    'invalid cursor' => [ApiErrorCode::InvalidCursor, 422, false],
]);
