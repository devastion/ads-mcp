<?php

namespace App\Http\Controllers\V0;

use App\Actions\SearchAds;
use App\Http\Controllers\Controller;
use App\Http\Requests\V0\SearchAdsRequest;
use App\Http\Resources\V0\AdResource;
use Illuminate\Http\JsonResponse;

class AdController extends Controller
{
    public function __invoke(SearchAdsRequest $request, SearchAds $searchAds): JsonResponse
    {
        $filters = $request->filters();
        $ads = $searchAds->handle($filters);

        return response()->json([
            'data' => AdResource::collection($ads->items())->resolve(),
            'page' => $filters->page,
            'total' => $ads->total(),
        ]);
    }
}
