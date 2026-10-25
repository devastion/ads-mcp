<?php

namespace App\Actions\Mcp\Tools;

use Laravel\Mcp\Server\Tool;

/**
 * Base for the read-only ad tools. The package builds input schemas without `additionalProperties`,
 * so this adds the closed-object rule the contract requires.
 */
abstract class GethookdTool extends Tool
{
    /** The only tool names the endpoint accepts, in the order they are advertised. */
    public const NAMES = ['search_ads', 'get_ad', 'list_niches'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['inputSchema']['additionalProperties'] = false;

        return $array;
    }
}
