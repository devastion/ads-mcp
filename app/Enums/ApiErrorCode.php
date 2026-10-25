<?php

namespace App\Enums;

enum ApiErrorCode: string
{
    case ApiKeyMissing = 'api_key_missing';
    case ApiKeyInvalid = 'api_key_invalid';
    case ApiKeyInQuery = 'api_key_in_query';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case UnknownParameter = 'unknown_parameter';
    case InvalidParameter = 'invalid_parameter';
    case InvalidCursor = 'invalid_cursor';
    case CursorMismatch = 'cursor_mismatch';
    case InsufficientScope = 'insufficient_scope';
    case QuotaExceeded = 'quota_exceeded';
    case RateLimited = 'rate_limited';
    case InternalError = 'internal_error';

    public function status(): int
    {
        return match ($this) {
            self::ApiKeyMissing, self::ApiKeyInvalid, self::ApiKeyInQuery => 401,
            self::InsufficientScope, self::QuotaExceeded => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::UnknownParameter, self::InvalidParameter, self::InvalidCursor, self::CursorMismatch => 422,
            self::RateLimited => 429,
            self::InternalError => 500,
        };
    }

    public function retryable(): bool
    {
        return match ($this) {
            self::RateLimited, self::InternalError => true,
            default => false,
        };
    }

    public function defaultNextStep(): string
    {
        return match ($this) {
            self::ApiKeyMissing => 'Add an Authorization header with your API key.',
            self::ApiKeyInvalid => 'Check your API key and try again.',
            self::ApiKeyInQuery => 'Send the API key in the Authorization header, not as a query parameter.',
            self::InsufficientScope => 'Use a key with the search:read scope.',
            self::QuotaExceeded => 'Wait until the quota resets (see details.resets_at) or use a key on a larger plan.',
            self::NotFound => 'Check the request path.',
            self::MethodNotAllowed => 'Use one of the methods listed in the Allow header.',
            self::UnknownParameter => 'Remove the unknown parameter and try again.',
            self::InvalidParameter => 'Check the parameter value and try again.',
            self::InvalidCursor => 'Request a fresh cursor and try again.',
            self::CursorMismatch => 'Request a fresh cursor and try again.',
            self::RateLimited => 'Wait the number of seconds in the Retry-After header, then retry.',
            self::InternalError => 'Try again. If this keeps happening, contact support.',
        };
    }
}
