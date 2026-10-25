<?php

namespace App\Http\Controllers\Developer;

use App\Actions\CountBillableCalls;
use App\Http\Controllers\Controller;
use App\Http\Resources\Developer\ApiKeyResource;
use App\Models\ApiKey;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DeveloperController extends Controller
{
    public function __construct(private readonly CountBillableCalls $counter) {}

    public function index(): Response
    {
        $now = Carbon::now('UTC');
        $keys = ApiKey::query()->latest('id')->get();
        $used = $this->counter->handle($keys->pluck('id'), $now);

        return Inertia::render('developer', [
            'keys' => $keys->map(fn (ApiKey $key): array => ApiKeyResource::from($key))->values(),
            'usage' => [
                'limit' => CountBillableCalls::MONTHLY_LIMIT,
                'period_start' => $now->startOfMonth()->toIso8601String(),
                'resets_at' => $this->counter->resetsAt($now)->toIso8601String(),
                'per_key' => $keys->map(fn (ApiKey $key): array => ['key_id' => $key->id, 'used' => $used[$key->id]])->values(),
            ],
        ]);
    }
}
