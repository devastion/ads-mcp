<?php

namespace App\Http\Middleware;

use App\Actions\CheckApiQuota;
use App\Actions\RecordApiUsage;
use App\Enums\ApiErrorCode;
use App\Http\Exceptions\V1\ApiErrorException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes one ledger row for each successful read. A failed write fails the request rather than serve an unmetered read.
 */
class MeterApiUsage
{
    /** Set only by the trusted server-side MCP forwarder; stripped from every other request. */
    public const ORIGIN_HEADER = 'X-Gethookd-Origin';

    public function __construct(
        private readonly RecordApiUsage $recorder,
        private readonly CheckApiQuota $quota,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->remove(self::ORIGIN_HEADER);

        $idempotencyKey = $this->idempotencyKey($request);
        $key = $request->attributes->get('api_key');

        if ($key instanceof ApiKey) {
            $this->quota->handle($key, $idempotencyKey);
        }

        $startedAt = hrtime(true);
        $response = $next($request);

        if ($key instanceof ApiKey && $response->isSuccessful()) {
            $this->recorder->handle(
                $key,
                $request->is('api/v0', 'api/v0/*') ? 'v0' : 'v1',
                (string) $request->route()?->getName(),
                $response->getStatusCode(),
                $this->resultCount($response),
                (int) ((hrtime(true) - $startedAt) / 1_000_000),
                $idempotencyKey,
            );
        }

        return $response;
    }

    private function idempotencyKey(Request $request): ?string
    {
        $values = $request->headers->all('Idempotency-Key');

        if ($values === []) {
            return null;
        }

        if (count($values) === 1 && is_string($values[0]) && preg_match('/\A[\x20-\x7E]{1,255}\z/', $values[0]) === 1) {
            return $values[0];
        }

        if ($request->is('api/v0', 'api/v0/*')) {
            abort(400);
        }

        throw new ApiErrorException(
            ApiErrorCode::InvalidParameter,
            'Header "Idempotency-Key" must be 1 to 255 printable ASCII characters and sent once.',
            'Send a single printable ASCII value of up to 255 characters, or omit the header.',
            ['parameter' => 'Idempotency-Key'],
        );
    }

    private function resultCount(Response $response): ?int
    {
        $body = json_decode((string) $response->getContent(), true);
        $data = is_array($body) ? ($body['data'] ?? null) : null;

        if (! is_array($data)) {
            return null;
        }

        return array_is_list($data) ? count($data) : 1;
    }
}
