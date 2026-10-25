<?php

use App\Enums\Scope;
use App\Http\Controllers\Mcp\AdsServer;
use App\Http\Middleware\AuthenticateV1ApiKey;
use App\Http\Middleware\ClassifyMcpOperation;
use App\Http\Middleware\EnforceMcpOrigin;
use App\Http\Middleware\RequireScope;
use App\Http\Middleware\ValidateMcpEnvelope;
use Laravel\Mcp\Facades\Mcp;

// Auth reuses the v1 middleware and the shared scope check, so MCP has no second key store.
Mcp::web('/api/mcp', AdsServer::class)->middleware([
    EnforceMcpOrigin::class,
    ValidateMcpEnvelope::class,
    AuthenticateV1ApiKey::class,
    RequireScope::class.':'.Scope::SearchRead->value,
    ClassifyMcpOperation::class,
]);
