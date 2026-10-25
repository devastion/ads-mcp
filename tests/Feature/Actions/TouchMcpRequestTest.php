<?php

use App\Actions\TouchMcpRequest;
use App\Models\ApiKey;
use Illuminate\Support\Carbon;

it('records the first MCP request and waits a full minute before writing again', function () {
    $this->travelTo(Carbon::parse('2026-10-26 12:00:00', 'UTC'));
    $key = ApiKey::factory()->create();
    $touch = app(TouchMcpRequest::class);

    $touch->handle($key);

    expect($key->fresh()->last_mcp_request_at->toIso8601String())->toBe('2026-10-26T12:00:00+00:00');

    $this->travelTo(Carbon::parse('2026-10-26 12:00:59', 'UTC'));
    $touch->handle($key);

    expect($key->fresh()->last_mcp_request_at->toIso8601String())->toBe('2026-10-26T12:00:00+00:00');

    $this->travelTo(Carbon::parse('2026-10-26 12:01:00', 'UTC'));
    $touch->handle($key);

    expect($key->fresh()->last_mcp_request_at->toIso8601String())->toBe('2026-10-26T12:01:00+00:00');
});
