<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\V1\SearchAdsRequest;
use Illuminate\Http\JsonResponse;

class AdController extends Controller
{
    public function __invoke(SearchAdsRequest $request): JsonResponse
    {
        return response()->json(['data' => []]);
    }
}
