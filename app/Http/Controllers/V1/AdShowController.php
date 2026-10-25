<?php

namespace App\Http\Controllers\V1;

use App\Actions\V1\PageAdBody;
use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Http\Requests\V1\ShowAdRequest;
use App\Http\Resources\V1\AdDetailResource;
use App\Models\Ad;
use Illuminate\Http\JsonResponse;

class AdShowController extends Controller
{
    public function __invoke(ShowAdRequest $request, PageAdBody $pager): JsonResponse
    {
        $validated = $request->validated();
        $ad = Ad::query()->find((int) $validated['id']);

        if ($ad === null) {
            throw new ApiErrorException(
                ApiErrorCode::NotFound,
                'No ad exists with id '.(int) $validated['id'].'.',
                'Use an id returned by GET /api/v1/ads.',
                ['parameter' => 'id'],
            );
        }

        $offset = (int) ($validated['body_offset'] ?? 0);
        $page = $pager->handle((string) $ad->body_text, $offset, (int) ($validated['body_limit'] ?? 1000));

        return response()->json([
            'data' => [...AdDetailResource::make($ad)->resolve($request), 'body_text' => $page['text']],
            'body_offset' => $offset,
            'body_has_more' => $page['hasMore'],
            'body_next_offset' => $page['nextOffset'],
            'body_total_characters' => $page['total'],
        ]);
    }
}
