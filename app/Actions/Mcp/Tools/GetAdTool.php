<?php

namespace App\Actions\Mcp\Tools;

use App\Actions\Mcp\ForwardRestRead;
use App\Actions\Mcp\ToolEnvelope;
use App\Actions\Mcp\ValidateToolArguments;
use App\DTO\McpToolError;
use App\Enums\ApiErrorCode;
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
#[Description('Fetch one ad by its exact id from search_ads. Returns complete brand/headline, running dates, countries, brand-relative impression_rank, discovered_at and derived days_active, plus a body_text page. Use search_ads to find ads, not this tool. body_offset counts Unicode characters from zero; body_limit defaults to 1000, maximum 1000. Follow body_next_offset while body_has_more is true to retrieve all copy without gaps. For active ads days_active is today minus start_date; for inactive ads it is end_date minus start_date, whole days, using the same implementation as REST/v0. A rank cannot compare different brands. Each successful detail page is billable; retry protection requires the per-call Idempotency-Key HTTP header.')]
class GetAdTool extends GethookdTool
{
    protected string $name = 'get_ad';

    protected string $title = 'Get ad';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->min(1)->required()->description('Exact stable ad id returned by search_ads; not a brand id.'),
            'body_offset' => $schema->integer()->min(0)->default(0)->description('Zero-based Unicode-character offset; use body_next_offset for continuation.'),
            'body_limit' => $schema->integer()->min(1)->max(1000)->default(1000)->description('Maximum body characters in this page; not a byte or token count.'),
        ];
    }

    public function handle(Request $request, ValidateToolArguments $validator, ForwardRestRead $forward): Response
    {
        $arguments = $request->all();
        $fault = $validator->handle($this->name(), $arguments);

        if ($fault instanceof McpToolError) {
            return ToolEnvelope::error($fault);
        }

        $query = [];

        foreach (['body_offset', 'body_limit'] as $optional) {
            if (array_key_exists($optional, $arguments)) {
                $query[] = [$optional, (string) $arguments[$optional]];
            }
        }

        $result = $forward->handle('/api/v1/ads/'.$arguments['id'], $query);

        if ($result instanceof McpToolError && $result->code === ApiErrorCode::NotFound) {
            $result = new McpToolError(
                ApiErrorCode::NotFound,
                'No ad has this id.',
                'Use search_ads to find an ad, then call get_ad with an id it returned.',
            );
        }

        return $result instanceof McpToolError
            ? ToolEnvelope::error($result)
            : ToolEnvelope::success($result);
    }
}
