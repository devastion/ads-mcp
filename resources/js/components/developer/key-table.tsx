import { router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import KeyTime from '@/components/developer/key-time';
import { destroy } from '@/routes/developer/keys';
import type { DeveloperKey } from '@/types/developer';

function KeyRow({
    apiKey,
    onRevoked,
}: {
    apiKey: DeveloperKey;
    onRevoked: (id: number) => void;
}) {
    const request = useHttp({});
    const [confirming, setConfirming] = useState(false);
    const [error, setError] = useState('');
    const cancel = request.cancel;
    const requestVersion = useRef(0);

    useEffect(() => {
        const unsubscribe = router.on('start', (event) => {
            if (
                event.detail.visit.only.length === 0 &&
                event.detail.visit.except.length === 0
            ) {
                requestVersion.current += 1;
                cancel();
            }
        });

        return () => {
            requestVersion.current += 1;
            cancel();
            unsubscribe();
        };
    }, [cancel]);

    async function revoke() {
        setError('');
        const version = requestVersion.current;

        try {
            await request.delete(destroy.url(apiKey.id));
            if (version !== requestVersion.current) {
                return;
            }

            setConfirming(false);
            onRevoked(apiKey.id);
        } catch {
            if (version === requestVersion.current) {
                setError('Could not revoke this key. Please try again.');
            }
        }
    }

    return (
        <tr
            className={
                apiKey.revoked_at
                    ? 'bg-slate-50 text-slate-500'
                    : 'text-slate-700'
            }
        >
            <th
                scope="row"
                className="px-4 py-4 text-left align-top font-medium"
            >
                <span className="block max-w-44 wrap-break-word">
                    {apiKey.name}
                </span>
                <code className="text-xs font-normal text-slate-500">
                    {apiKey.prefix}…
                </code>
            </th>
            <td className="px-3 py-4 align-top">
                <div className="flex flex-wrap gap-1">
                    {apiKey.scopes.map((scope) => (
                        <span
                            key={scope}
                            className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs text-slate-600"
                        >
                            {scope}
                        </span>
                    ))}
                </div>
            </td>
            <td className="px-3 py-4 align-top text-xs">
                <KeyTime value={apiKey.created_at} />
            </td>
            <td className="px-3 py-4 align-top text-xs">
                <KeyTime value={apiKey.last_used_at} />
            </td>
            <td className="px-3 py-4 align-top">
                <span
                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${apiKey.revoked_at ? 'bg-slate-200 text-slate-600' : 'bg-emerald-50 text-emerald-700'}`}
                >
                    {apiKey.revoked_at ? 'Revoked' : 'Active'}
                </span>
                {apiKey.revoked_at && (
                    <div className="mt-1 text-xs">
                        <KeyTime value={apiKey.revoked_at} />
                    </div>
                )}
            </td>
            <td className="px-4 py-4 text-right align-top">
                {!apiKey.revoked_at &&
                    (confirming ? (
                        <div className="grid justify-items-end gap-2">
                            <p className="text-xs text-slate-700">
                                Revoke this key? It will stop working
                                immediately.
                            </p>
                            <div className="flex gap-3">
                                <button
                                    type="button"
                                    onClick={() => setConfirming(false)}
                                    disabled={request.processing}
                                    className="rounded text-xs font-medium underline underline-offset-4 disabled:opacity-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={() => void revoke()}
                                    disabled={request.processing}
                                    className="rounded text-xs font-medium text-red-700 underline underline-offset-4 disabled:opacity-50"
                                >
                                    {request.processing
                                        ? 'Revoking…'
                                        : 'Confirm revoke'}
                                </button>
                            </div>
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setConfirming(true)}
                            aria-label={`Revoke ${apiKey.name} (${apiKey.prefix})`}
                            className="rounded text-xs font-medium text-red-700 underline underline-offset-4"
                        >
                            Revoke
                        </button>
                    ))}
                {error && (
                    <p role="alert" className="mt-2 text-xs text-red-700">
                        {error}
                    </p>
                )}
            </td>
        </tr>
    );
}

export default function KeyTable({
    keys,
    onRevoked,
}: {
    keys: DeveloperKey[];
    onRevoked: (id: number) => void;
}) {
    if (keys.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                No API keys yet. Connect Pi-Bolt above or create your first key
                below.
            </p>
        );
    }

    return (
        <div
            className="overflow-x-auto rounded-xl border border-slate-200"
            tabIndex={0}
            role="region"
            aria-label="API keys table"
        >
            <table className="w-full min-w-180 text-sm">
                <caption className="sr-only">
                    API keys, their permissions, activity and revocation
                    controls
                </caption>
                <thead className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                    <tr>
                        <th scope="col" className="px-4 py-3 font-medium">
                            Name / prefix
                        </th>
                        <th scope="col" className="px-3 py-3 font-medium">
                            Scopes
                        </th>
                        <th scope="col" className="px-3 py-3 font-medium">
                            Created
                        </th>
                        <th scope="col" className="px-3 py-3 font-medium">
                            Last used
                        </th>
                        <th scope="col" className="px-3 py-3 font-medium">
                            Status
                        </th>
                        <th
                            scope="col"
                            className="px-4 py-3 text-right font-medium"
                        >
                            Actions
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {keys.map((apiKey) => (
                        <KeyRow
                            key={apiKey.id}
                            apiKey={apiKey}
                            onRevoked={onRevoked}
                        />
                    ))}
                </tbody>
            </table>
        </div>
    );
}
