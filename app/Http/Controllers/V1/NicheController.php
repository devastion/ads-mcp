<?php

namespace App\Http\Controllers\V1;

use App\Enums\Niche;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\ListNichesRequest;
use App\Http\Resources\V1\NicheResource;
use Illuminate\Http\JsonResponse;

class NicheController extends Controller
{
    public function __invoke(ListNichesRequest $request): JsonResponse
    {
        return response()->json(['data' => NicheResource::collection(Niche::cases())->resolve($request)]);
    }
}
