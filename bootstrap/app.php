<?php

use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // v0 callers depend on this exact body for every failure. Scoped to v0 so v1 can use its own errors.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/v0', 'api/v0/*')) {
                return response()->json(['error' => 'Invalid request'], 400);
            }

            return null;
        });

        // v1 callers get a single error envelope for every failure, with fixed codes and no leaked exception detail.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }

            $headers = [];

            if ($exception instanceof ApiErrorException) {
                $code = $exception->code();
                $message = $exception->message();
                $nextStep = $exception->nextStep();
                $details = $exception->details();
            } elseif ($exception instanceof NotFoundHttpException) {
                $code = ApiErrorCode::NotFound;
                $message = 'The requested resource was not found.';
                $nextStep = $code->defaultNextStep();
                $details = [];
            } elseif ($exception instanceof MethodNotAllowedHttpException) {
                $code = ApiErrorCode::MethodNotAllowed;
                $message = 'The HTTP method is not allowed for this endpoint.';
                $nextStep = $code->defaultNextStep();
                $details = [];
                $headers = $exception->getHeaders();
            } elseif ($exception instanceof TooManyRequestsHttpException) {
                $code = ApiErrorCode::RateLimited;
                $message = 'Too many requests.';
                $nextStep = $code->defaultNextStep();
                $details = [];
                $headers = $exception->getHeaders();
            } else {
                $code = ApiErrorCode::InternalError;
                $message = 'Something went wrong on our side.';
                $nextStep = $code->defaultNextStep();
                $details = [];
            }

            $error = [
                'code' => $code->value,
                'message' => $message,
                'next_step' => $nextStep,
                'retryable' => $code->retryable(),
            ];

            if ($details !== []) {
                $error['details'] = $details;
            }

            return response()->json(['error' => $error], $code->status(), $headers);
        });
    })->create();
