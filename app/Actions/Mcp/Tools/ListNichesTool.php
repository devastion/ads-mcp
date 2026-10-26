<?php

namespace App\Actions\Mcp\Tools;

use App\Actions\Mcp\ForwardRestRead;
use App\Actions\Mcp\ToolEnvelope;
use App\Actions\Mcp\ValidateToolArguments;
use App\DTO\McpToolError;
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
#[Description('List all supported niche slugs and display names. Use these exact slugs in search_ads.niche (an array); for example pet-supplies, not Pet Supplies. Use for vocabulary discovery, not ad search, brand ranking or impression totals. Returns the full fixed catalogue, no pagination or counts. A successful call is billable; retry protection requires a per-call Idempotency-Key HTTP header.')]
class ListNichesTool extends AdsTool
{
    protected string $name = 'list_niches';

    protected string $title = 'List niches';

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, ValidateToolArguments $validator, ForwardRestRead $forward): Response
    {
        $fault = $validator->handle($this->name(), $request->all());

        if ($fault instanceof McpToolError) {
            return ToolEnvelope::error($fault);
        }

        $result = $forward->handle('/api/v1/niches', []);

        return $result instanceof McpToolError
            ? ToolEnvelope::error($result)
            : ToolEnvelope::success($result);
    }
}
