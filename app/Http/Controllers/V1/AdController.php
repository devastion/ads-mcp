<?php

namespace App\Http\Controllers\V1;

use App\Actions\V1\CursorCodec;
use App\Actions\V1\SearchAdsV1;
use App\DTO\V1\AdSearchQuery;
use App\Enums\AdFormat;
use App\Enums\AdStatus;
use App\Enums\ApiErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Http\Requests\V1\SearchAdsRequest;
use App\Http\Resources\V1\AdResource;
use App\Models\Ad;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AdController extends Controller
{
    public function __invoke(SearchAdsRequest $request, SearchAdsV1 $search, CursorCodec $cursors): JsonResponse
    {
        $validated = $request->validated();

        $niches = array_values(array_unique($validated['niche'] ?? []));
        sort($niches);

        $query = new AdSearchQuery(
            q: $validated['q'] ?? null,
            niches: $niches,
            format: isset($validated['format']) ? AdFormat::from($validated['format']) : null,
            status: isset($validated['status']) ? AdStatus::from($validated['status']) : null,
            startedAfter: isset($validated['started_after']) ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['started_after']) ?: null : null,
            sort: $validated['sort'] ?? 'newest',
            perPage: (int) ($validated['per_page'] ?? 20),
            cursor: $validated['cursor'] ?? null,
        );

        $payload = $query->cursor !== null ? $cursors->decode($query->cursor) : null;

        if ($payload !== null && $payload['h'] !== $query->fingerprint()) {
            throw new ApiErrorException(ApiErrorCode::CursorMismatch, 'The cursor was issued for a different filter or sort.');
        }

        $cutoff = $payload !== null ? (int) $payload['c'] : (int) Ad::query()->max('id');
        $after = $payload !== null ? ['k' => $payload['k'], 'i' => (int) $payload['i']] : null;

        $result = $search->handle($query, $cutoff, $after);
        $ads = $result['ads'];
        $nextCursor = null;

        if ($result['hasMore'] && ($last = $ads->last()) !== null) {
            $nextCursor = $cursors->encode([
                'v' => 1,
                'h' => $query->fingerprint(),
                'c' => $cutoff,
                'k' => $query->sort === 'newest' ? $last->start_date->format('Y-m-d') : $last->impression_rank,
                'i' => $last->id,
            ]);
        }

        $response = [
            'data' => AdResource::collection($ads)->resolve($request),
            'applied_filters' => $query->appliedFilters(),
            'has_more' => $result['hasMore'],
            'next_cursor' => $nextCursor,
        ];

        if ($query->sort === 'impressions') {
            $response['notes'] = ['Impression rank is brand-relative: it orders ads within a brand, not across brands.'];
        }

        return response()->json($response);
    }
}
