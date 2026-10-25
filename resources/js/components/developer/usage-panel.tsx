import type { DeveloperKey, DeveloperUsage } from '@/types/developer';

export default function UsagePanel({
    keys,
    usage,
}: {
    keys: DeveloperKey[];
    usage: DeveloperUsage;
}) {
    const reset = new Date(usage.resets_at);
    const resetDate = reset.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
    const resetTime = reset.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'UTC',
    });

    return (
        <section
            aria-labelledby="usage-heading"
            className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6"
        >
            <div>
                <h2
                    id="usage-heading"
                    className="text-lg font-semibold text-slate-900"
                >
                    Usage this month
                </h2>
                <p className="text-sm text-slate-500">
                    Each key has its own {usage.limit.toLocaleString('en-US')}
                    -call monthly quota.
                </p>
            </div>
            {keys.length === 0 ? (
                <p className="text-sm text-slate-500">
                    Create a key to start tracking billable calls.
                </p>
            ) : (
                <div className="grid gap-5">
                    {keys.map((apiKey) => {
                        const used =
                            usage.per_key.find(
                                (entry) => entry.key_id === apiKey.id,
                            )?.used ?? 0;

                        return (
                            <div
                                key={apiKey.id}
                                className={`grid gap-2 ${apiKey.revoked_at ? 'opacity-60' : ''}`}
                            >
                                <p className="text-sm text-slate-700">
                                    <span className="font-medium wrap-anywhere">
                                        {apiKey.name}
                                    </span>{' '}
                                    <span className="font-mono text-xs text-slate-500">
                                        ({apiKey.prefix}…)
                                    </span>
                                    :{' '}
                                    <strong className="font-semibold">
                                        {used.toLocaleString('en-US')} of{' '}
                                        {usage.limit.toLocaleString('en-US')}
                                    </strong>{' '}
                                    billable calls this month
                                    {apiKey.revoked_at && (
                                        <span className="ml-2 text-xs">
                                            Revoked
                                        </span>
                                    )}
                                </p>
                                <div
                                    role="progressbar"
                                    aria-label={`${apiKey.name} monthly quota`}
                                    aria-valuemin={0}
                                    aria-valuemax={usage.limit}
                                    aria-valuenow={Math.min(used, usage.limit)}
                                    aria-valuetext={`${used} of ${usage.limit} billable calls`}
                                    className="h-1.5 overflow-hidden rounded-full bg-slate-100"
                                >
                                    <div
                                        className={`h-full rounded-full ${used >= usage.limit ? 'bg-amber-500' : 'bg-blue-600'}`}
                                        style={{
                                            width: `${Math.min(100, (used / usage.limit) * 100)}%`,
                                        }}
                                    />
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
            <div className="grid gap-2 border-t border-slate-100 pt-4 text-xs text-slate-500">
                <p>
                    Resets{' '}
                    <time dateTime={usage.resets_at}>
                        {resetDate}, {resetTime} UTC
                    </time>
                </p>
                <p>
                    Successful authorized data reads count, including empty
                    results.{' '}
                    <a
                        href="#billing-rule"
                        className="font-medium text-blue-700 underline underline-offset-2"
                    >
                        What counts as billable?
                    </a>
                </p>
                <details id="billing-rule" className="scroll-mt-4">
                    <summary className="cursor-pointer font-medium text-slate-700">
                        Billing rule
                    </summary>
                    <p className="pt-2 leading-5">
                        Authorized, authenticated data reads returning 2xx
                        count, including empty results. Each MCP tool call
                        counts through its forwarded REST read. Validation
                        errors, 404s, rate limits, quota refusals, server
                        errors, MCP discovery and local errors are free. A
                        repeated Idempotency-Key within 24 hours of the first
                        billed call is unbilled; retries without that header are
                        billed again. Usage comes from the billing ledger.
                    </p>
                </details>
            </div>
        </section>
    );
}
