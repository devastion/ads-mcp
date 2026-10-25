<?php

namespace App\Http\Controllers\Developer;

use App\Actions\CountBillableCalls;
use App\Actions\IssueApiKey;
use App\Actions\RevokeApiKey;
use App\Enums\Scope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Developer\StoreApiKeyRequest;
use App\Http\Resources\Developer\ApiKeyResource;
use App\Http\Resources\Developer\McpPanelResource;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DeveloperController extends Controller
{
    public function __construct(private readonly CountBillableCalls $counter) {}

    public function storeKey(StoreApiKeyRequest $request, IssueApiKey $issuer): JsonResponse
    {
        $issued = $issuer->handle(
            $request->validated('name'),
            scopes: array_values($request->enums('scopes', Scope::class)),
        );

        return response()->json([
            'data' => [...ApiKeyResource::from($issued->apiKey), 'secret' => $issued->secret],
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function destroyKey(ApiKey $apiKey, RevokeApiKey $revoker): HttpResponse
    {
        $revoker->handle($apiKey);

        return response()->noContent();
    }

    public function index(): Response
    {
        $now = Carbon::now('UTC');
        $keys = ApiKey::query()->latest('id')->get();

        return Inertia::render('developer', [
            'keys' => fn () => $keys->map(fn (ApiKey $key): array => ApiKeyResource::from($key))->values(),
            'usage' => function () use ($keys, $now): array {
                $used = $this->counter->handle($keys->pluck('id'), $now);

                return [
                    'limit' => CountBillableCalls::MONTHLY_LIMIT,
                    'period_start' => $now->copy()->startOfMonth()->toIso8601String(),
                    'resets_at' => $this->counter->resetsAt($now)->toIso8601String(),
                    'per_key' => $keys->map(fn (ApiKey $key): array => ['key_id' => $key->id, 'used' => $used[$key->id]])->values(),
                ];
            },
            'mcp' => fn (): array => McpPanelResource::from($keys->first(
                fn (ApiKey $key): bool => $key->name === McpPanelResource::CLIENT && ! $key->isRevoked(),
            )),
        ]);
    }
}
