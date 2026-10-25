<?php

namespace App\Actions\Mcp\Tools;

use App\Actions\Mcp\CompactSearchPage;
use App\Actions\Mcp\ForwardRestRead;
use App\Actions\Mcp\ToolEnvelope;
use App\Actions\Mcp\ValidateToolArguments;
use App\DTO\McpToolError;
use App\Enums\ApiErrorCode;
use App\Enums\Niche;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Search synthetic ads by literal keyword and exact filters; returns compact previews, applied_filters and a next_cursor. Use get_ad for complete copy and dates, and list_niches to discover niche slugs. Omitted status includes active AND inactive ads. started_after is exclusive YYYY-MM-DD (start_date > date), not discovery date. sort=impressions orders brand-relative rank, missing ranks last; it cannot identify the brand with the most impressions or compare impression totals. Do not use for brand-performance rankings. Repeat the same filters/sort with next_cursor to continue; cursor results exclude later inserts subject to v1\'s documented in-flight-insert caveat. limit defaults to 5, maximum 10. Successful reads, including empty results, are billable; retry billing protection requires a stable per-call Idempotency-Key HTTP header.')]
class SearchAdsTool extends GethookdTool
{
    protected string $name = 'search_ads';

    protected string $title = 'Search ads';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $niches = array_column(Niche::cases(), 'value');

        return [
            'q' => $schema->string()->min(2)->max(200)->description('Literal case-insensitive substring in headline OR body_text. Trimmed length must be 2–200; omit if unused. No wildcard syntax.'),
            'niche' => $schema->array()->min(1)->items($schema->string()->enum($niches))->description('One or more exact slugs from list_niches; OR between slugs. REST sorts and deduplicates the applied list. Display names are not accepted.'),
            'format' => $schema->string()->enum(['image', 'video', 'carousel'])->description('Exact ad format; omit for all formats.'),
            'status' => $schema->string()->enum(['active', 'inactive'])->description('Active means still running; inactive means stopped. Omit for both.'),
            'started_after' => $schema->string()->format('date')->description('Real YYYY-MM-DD date; exclusive start_date lower bound, not discovered_at. Future dates are valid. For the last 30 days, use the supplied application date minus 30 days.'),
            'sort' => $schema->string()->enum(['newest', 'impressions'])->default('newest')->description('newest: start_date descending, deterministic id tie-break. impressions: within-brand rank ascending, null last, deterministic id tie-break; NOT global impression counts.'),
            'limit' => $schema->integer()->min(1)->max(10)->default(5)->description('Ads per page; maps to REST per_page. Out-of-range values are rejected, not clamped.'),
            'cursor' => $schema->string()->min(1)->max(2048)->description('Opaque next_cursor from the previous page. Preserve all filters and sort; limit may change. Omit to start a new walk.'),
        ];
    }

    public function handle(Request $request, ValidateToolArguments $validator, ForwardRestRead $forward, CompactSearchPage $compact): Response
    {
        $arguments = $request->all();
        $fault = $validator->handle($this->name(), $arguments);

        if ($fault instanceof McpToolError) {
            return ToolEnvelope::error($fault);
        }

        $result = $forward->handle('/api/v1/ads', $this->restQuery($arguments));

        if ($result instanceof McpToolError) {
            return ToolEnvelope::error($this->translate($result, $arguments));
        }

        $page = $compact->handle($result);

        return $page instanceof McpToolError
            ? ToolEnvelope::error($page)
            : ToolEnvelope::success($page);
    }

    /**
     * Maps MCP arguments to REST query pairs. Niche stays repeated; limit becomes per_page with a default of 5.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array{0: string, 1: string}>
     */
    private function restQuery(array $arguments): array
    {
        $query = [];

        if (isset($arguments['q'])) {
            $query[] = ['q', trim((string) $arguments['q'])];
        }

        foreach ($arguments['niche'] ?? [] as $slug) {
            $query[] = ['niche', (string) $slug];
        }

        foreach (['format', 'status', 'started_after', 'sort'] as $filter) {
            if (isset($arguments[$filter])) {
                $query[] = [$filter, (string) $arguments[$filter]];
            }
        }

        $query[] = ['per_page', (string) ($arguments['limit'] ?? 5)];

        if (isset($arguments['cursor'])) {
            $query[] = ['cursor', (string) $arguments['cursor']];
        }

        return $query;
    }

    /**
     * Cursor errors name the fix: an invalid cursor restarts from a corrected call; a mismatch keeps the same filters.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function translate(McpToolError $error, array $arguments): McpToolError
    {
        if ($error->code === ApiErrorCode::CursorMismatch) {
            return new McpToolError(
                $error->code,
                $error->message,
                'Repeat the same filters and sort with this cursor, or start a new walk without cursor.',
                ['parameter' => 'cursor'],
            );
        }

        if ($error->code !== ApiErrorCode::InvalidCursor) {
            return $error;
        }

        unset($arguments['cursor']);

        return new McpToolError(
            $error->code,
            $error->message,
            'Start a new walk: repeat the call without cursor.',
            ['corrected_call' => ['name' => $this->name(), 'arguments' => $arguments === [] ? new \stdClass : $arguments]],
        );
    }
}
