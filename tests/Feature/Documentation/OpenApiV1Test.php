<?php

use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\ApiErrorCode;
use App\Enums\Niche;
use Illuminate\Support\Facades\Artisan;

const OPENAPI_V1_PATH = 'docs/openapi/v1.json';
const OPENAPI_V1_DRIFT_MESSAGE = 'docs/openapi/v1.json is stale. Run php artisan scramble:export --path=docs/openapi/v1.json';

/**
 * @return array<string, mixed>
 */
function exportedOpenApiV1(string $path): array
{
    if (file_exists($path)) {
        unlink($path);
    }

    Artisan::call('scramble:export', ['--path' => $path, '--api' => 'default']);

    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $document;
}

/**
 * @return array<string, mixed>
 */
function committedOpenApiV1(): array
{
    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents(base_path(OPENAPI_V1_PATH)), true, flags: JSON_THROW_ON_ERROR);

    return $document;
}

/**
 * @return array<string, mixed>
 */
function searchOperationParameters(array $document): array
{
    return collect($document['paths']['/v1/ads']['get']['parameters'])
        ->keyBy('name')
        ->all();
}

it('matches the committed OpenAPI document exactly', function () {
    $tempPath = sys_get_temp_dir().'/openapi-v1-check-'.uniqid().'.json';

    try {
        $exported = exportedOpenApiV1($tempPath);
    } finally {
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
    }

    $this->assertEquals(committedOpenApiV1(), $exported, OPENAPI_V1_DRIFT_MESSAGE);
})->group('openapi');

it('documents only the v1 paths', function () {
    $document = committedOpenApiV1();
    $paths = array_keys($document['paths']);

    expect($paths)->not->toBeEmpty();
    expect($document['servers'])->toEqual([['url' => 'http://localhost:8000/api']]);

    foreach ($paths as $path) {
        expect(str_starts_with($path, '/v1/'))->toBeTrue("Path {$path} is not a v1 path");
    }
});

it('documents every error code', function () {
    $codes = committedOpenApiV1()['components']['schemas']['ApiError']['properties']['error']['properties']['code']['enum'];

    expect($codes)->toEqual(collect(ApiErrorCode::cases())->pluck('value')->all());
});

it('documents every query parameter of the search endpoint', function () {
    $parameters = searchOperationParameters(committedOpenApiV1());

    expect(array_keys($parameters))->toEqual([
        'q', 'niche', 'format', 'status', 'started_after', 'sort', 'per_page', 'cursor',
    ]);
});

it('documents the allowed values of the enumerated query parameters', function () {
    $parameters = searchOperationParameters(committedOpenApiV1());

    expect($parameters['niche']['schema']['items']['enum'])->toEqual(array_column(Niche::cases(), 'value'));
    expect($parameters['format']['schema']['enum'])->toEqual(array_column(AdFormat::cases(), 'value'));
    expect($parameters['status']['schema']['enum'])->toEqual(array_column(AdStatus::cases(), 'value'));
    expect($parameters['sort']['schema']['enum'])->toEqual(['newest', 'impressions']);
});

it('documents the 401 error envelope on the search endpoint with Bearer auth', function () {
    $document = committedOpenApiV1();
    $operation = $document['paths']['/v1/ads']['get'];

    expect($operation['responses']['401']['description'])->toBeString()->not->toBeEmpty();
    expect($operation['responses']['401']['content']['application/json']['schema'])
        ->toEqual(['$ref' => '#/components/schemas/ApiError']);
    expect($operation['security'])->toEqual([['bearerAuth' => []]]);
    expect($document['components']['securitySchemes']['bearerAuth'])
        ->toMatchArray(['type' => 'http', 'scheme' => 'bearer']);
});
