<?php

namespace App\Http\Controllers\Mcp;

use App\Actions\Mcp\Tools\GetAdTool;
use App\Actions\Mcp\Tools\ListNichesTool;
use App\Actions\Mcp\Tools\SearchAdsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('ads')]
#[Version('1.0.0')]
#[Instructions('Read-only access to synthetic ad data. Discover niche slugs with list_niches, find ads with search_ads, and read full copy with get_ad. Every successful read is billable.')]
class AdsServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SearchAdsTool::class,
        GetAdTool::class,
        ListNichesTool::class,
    ];
}
