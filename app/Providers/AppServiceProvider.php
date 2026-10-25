<?php

namespace App\Providers;

use App\Enums\ApiErrorCode;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\InfoObject;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Illuminate\Auth\RequestGuard;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Auth is not installed, but the Guard contract is still aliased to
        // 'auth.driver'. Bind an always-guest guard so the database session
        // handler can resolve it and store a null user_id.
        $this->app->singleton(
            'auth.driver',
            fn ($app) => new RequestGuard(fn () => null, $app['request']),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOpenApi();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

    }

    /**
     * Document the v1 API only, with no public docs routes. The committed spec is docs/openapi/v1.json.
     */
    protected function configureOpenApi(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
            ->expose(false)
            ->withDocumentTransformers($this->applyV1Document(...));
    }

    /**
     * Set the info block, name the repeated niche parameter as the API takes it, and add the error envelope component built from ApiErrorCode.
     */
    protected function applyV1Document(OpenApi $openApi): void
    {
        $openApi->setInfo(
            InfoObject::make('Gethookd API v1')->setVersion('1.0.0'),
        );

        foreach ($openApi->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->parameters as $parameter) {
                    if ($parameter instanceof Parameter && $parameter->name === 'niche[]') {
                        $parameter->name = 'niche';
                    }
                }
            }
        }

        $code = (new StringType)->enum(
            collect(ApiErrorCode::cases())->pluck('value')->all(),
        );

        $error = (new ObjectType)
            ->addProperty('code', $code)
            ->addProperty('message', new StringType)
            ->addProperty('next_step', new StringType)
            ->addProperty('retryable', new BooleanType)
            ->setRequired(['code', 'message', 'next_step', 'retryable']);

        $envelope = (new ObjectType)
            ->addProperty('error', $error)
            ->setRequired(['error']);

        $errorReference = $openApi->components->addSchema('ApiError', Schema::fromType($envelope));

        $openApi->components->addSecurityScheme(
            'bearerAuth',
            SecurityScheme::http('bearer')->setDescription('Send the API key as `Authorization: Bearer <key>`. A key in the query string is refused.'),
        );

        foreach ($openApi->paths as $path) {
            if ($path->path !== 'v1/ads') {
                continue;
            }

            foreach ($path->operations as $operation) {
                $operation->addResponse(
                    Response::make(401)
                        ->setDescription('Missing, invalid or query-string API key. The `error.code` says which.')
                        ->setContent('application/json', $errorReference),
                );
                $operation->addSecurity(new SecurityRequirement(['bearerAuth' => []]));
            }
        }
    }
}
