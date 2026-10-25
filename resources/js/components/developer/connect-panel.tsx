import { router, useHttp, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import CopyField from '@/components/developer/copy-field';
import { store } from '@/routes/developer/keys';
import type {
    ApiKeyScope,
    CreatedKey,
    CreateKeyResponse,
    DeveloperKey,
    DeveloperMcp,
    McpConnection,
} from '@/types/developer';

function CreatePiBoltKey({
    onCreated,
    disabled,
    hasConnection,
    onProcessingChange,
}: {
    onCreated: (key: CreatedKey) => void;
    disabled: boolean;
    hasConnection: boolean;
    onProcessingChange: (processing: boolean) => void;
}) {
    const request = useHttp<
        { name: string; scopes: ApiKeyScope[] },
        CreateKeyResponse
    >({
        name: 'Pi-Bolt',
        scopes: ['search:read'],
    });
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

    async function create() {
        setError('');
        request.clearErrors();
        const version = requestVersion.current;

        try {
            await request.post(store.url(), {
                onStart: () => onProcessingChange(true),
                onFinish: () => {
                    if (version === requestVersion.current) {
                        onProcessingChange(false);
                    }
                },
                onSuccess: (response, httpResponse) => {
                    if (version !== requestVersion.current) {
                        return;
                    }

                    if (httpResponse.status === 201) {
                        onCreated(response.data);
                    } else {
                        setError(
                            'The key could not be created. Please try again.',
                        );
                    }
                },
            });
        } catch {
            if (version === requestVersion.current) {
                setError(
                    'The key could not be created. Check your connection and try again.',
                );
            }
        }
    }

    return (
        <div className="grid gap-2">
            <button
                type="button"
                onClick={() => void create()}
                disabled={disabled || request.processing}
                className="justify-self-start rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50"
            >
                {request.processing
                    ? 'Creating…'
                    : hasConnection
                      ? 'Create a new key'
                      : 'Create key for Pi-Bolt'}
            </button>
            {(error || request.hasErrors) && (
                <p role="alert" className="text-sm text-red-700">
                    {error ||
                        request.errors.name ||
                        request.errors.scopes ||
                        'Check the key details and try again.'}
                </p>
            )}
        </div>
    );
}

function WaitingStatus({
    connection,
    createdAt,
    endpointUrl,
}: {
    connection: McpConnection;
    createdAt?: string;
    endpointUrl: string;
}) {
    usePoll(3000, { only: ['keys', 'mcp'] });
    const [showTips, setShowTips] = useState(false);

    useEffect(() => {
        const elapsed = createdAt
            ? Date.now() - new Date(createdAt).getTime()
            : 0;
        const timeout = window.setTimeout(
            () => setShowTips(true),
            Math.max(0, 60_000 - elapsed),
        );

        return () => window.clearTimeout(timeout);
    }, [createdAt]);

    return (
        <div className="grid gap-3">
            <p
                role="status"
                className="flex items-center gap-2 text-sm text-slate-700"
            >
                <span
                    className="size-2 rounded-full bg-amber-500 motion-safe:animate-pulse"
                    aria-hidden="true"
                />
                Waiting for Pi-Bolt (key {connection.prefix}…)
            </p>
            {showTips && (
                <div className="rounded-lg bg-amber-50 p-3 text-xs leading-5 text-slate-700">
                    <p className="font-medium">
                        Still waiting? Check these three things:
                    </p>
                    <ul className="list-disc pl-4">
                        <li>Restart Pi-Bolt after saving its config.</li>
                        <li>
                            Paste the entire config, including the Authorization
                            header.
                        </li>
                        <li>
                            Keep this app running at{' '}
                            <code className="wrap-anywhere">{endpointUrl}</code>
                            .
                        </li>
                    </ul>
                </div>
            )}
        </div>
    );
}

export default function ConnectPanel({
    mcp,
    keys,
    secret,
    onCreated,
    onDismiss,
    createVersion,
    creationDisabled,
    onProcessingChange,
}: {
    mcp: DeveloperMcp;
    keys: DeveloperKey[];
    secret: string | null;
    onCreated: (key: CreatedKey) => void;
    onDismiss: () => void;
    createVersion: number;
    creationDisabled: boolean;
    onProcessingChange: (processing: boolean) => void;
}) {
    const connection = mcp.connection;
    const config = secret
        ? mcp.config_template.replaceAll('<YOUR_KEY>', secret)
        : mcp.config_template;
    const connectedAt = connection?.connected_at
        ? new Date(connection.connected_at)
        : null;

    return (
        <section
            aria-labelledby="connect-heading"
            className="overflow-hidden rounded-2xl border border-slate-200 bg-white"
        >
            <div className="border-b border-slate-100 bg-slate-50 px-5 py-5 sm:px-6">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="connect-heading"
                        className="text-lg font-semibold text-slate-900"
                    >
                        Connect your AI tool
                    </h2>
                    <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-600">
                        {mcp.client}
                    </span>
                </div>
                <p className="mt-1 text-sm text-slate-500">
                    Create a key, save the config, then make your first
                    connection.
                </p>
            </div>
            <ol className="grid gap-6 p-5 sm:p-6">
                <li className="grid grid-cols-[1.5rem_1fr] gap-3">
                    <span
                        className="flex size-6 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600"
                        aria-hidden="true"
                    >
                        1
                    </span>
                    <div className="grid gap-3">
                        <h3 className="text-sm font-semibold text-slate-900">
                            Create a key for Pi-Bolt
                        </h3>
                        <p className="text-xs text-slate-500">
                            This key can search ads and niches. It cannot manage
                            other keys.
                        </p>
                        <CreatePiBoltKey
                            key={createVersion}
                            onCreated={onCreated}
                            disabled={creationDisabled}
                            hasConnection={Boolean(connection)}
                            onProcessingChange={onProcessingChange}
                        />
                    </div>
                </li>
                <li className="grid grid-cols-[1.5rem_1fr] gap-3">
                    <span
                        className="flex size-6 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600"
                        aria-hidden="true"
                    >
                        2
                    </span>
                    <div className="grid min-w-0 gap-3">
                        <h3 className="text-sm font-semibold text-slate-900">
                            Copy the config
                        </h3>
                        <p className="text-xs leading-5 text-slate-600">
                            Paste into Pi-Bolt's user-level{' '}
                            <code>~/.pi/agent/mcp.json</code>, never the repo's{' '}
                            <code>.pi/mcp.json</code>.
                        </p>
                        <CopyField
                            key={secret ? 'filled' : 'template'}
                            value={config}
                            label={
                                secret
                                    ? 'Config with your new key'
                                    : 'Config template'
                            }
                            multiline
                        />
                        {secret ? (
                            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-slate-700">
                                <p>You won't see this key again.</p>
                                <button
                                    type="button"
                                    onClick={onDismiss}
                                    className="rounded font-semibold underline underline-offset-4"
                                >
                                    I've saved it
                                </button>
                            </div>
                        ) : (
                            <p className="text-xs text-slate-500">
                                Replace <code>&lt;YOUR_KEY&gt;</code> with a key
                                you've saved, or create a new key above to see a
                                filled config.
                            </p>
                        )}
                    </div>
                </li>
                <li className="grid grid-cols-[1.5rem_1fr] gap-3">
                    <span
                        className="flex size-6 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600"
                        aria-hidden="true"
                    >
                        3
                    </span>
                    <div className="grid gap-1">
                        <h3 className="text-sm font-semibold text-slate-900">
                            Restart Pi-Bolt and ask:
                        </h3>
                        <p className="text-sm text-slate-600">
                            “Which niches can I search?”
                        </p>
                    </div>
                </li>
                <li className="grid grid-cols-[1.5rem_1fr] gap-3">
                    <span
                        className="flex size-6 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600"
                        aria-hidden="true"
                    >
                        4
                    </span>
                    <div className="grid gap-2">
                        <h3 className="text-sm font-semibold text-slate-900">
                            Connection status
                        </h3>
                        {!connection ? (
                            <p role="status" className="text-sm text-slate-500">
                                Create a key in step 1 to get started.
                            </p>
                        ) : connectedAt ? (
                            <p
                                role="status"
                                className="text-sm font-medium text-emerald-700"
                            >
                                Connected at{' '}
                                <time
                                    dateTime={
                                        connection.connected_at ?? undefined
                                    }
                                    title={connectedAt.toUTCString()}
                                >
                                    {connectedAt.toLocaleTimeString(undefined, {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </time>
                                <span className="ml-2 font-mono text-xs font-normal">
                                    {connection.prefix}…
                                </span>
                            </p>
                        ) : (
                            <WaitingStatus
                                key={connection.key_id}
                                connection={connection}
                                createdAt={
                                    keys.find(
                                        (apiKey) =>
                                            apiKey.id === connection.key_id,
                                    )?.created_at
                                }
                                endpointUrl={mcp.endpoint_url}
                            />
                        )}
                        <p className="text-xs text-slate-500">
                            MCP endpoint:{' '}
                            <code className="wrap-anywhere">
                                {mcp.endpoint_url}
                            </code>
                        </p>
                    </div>
                </li>
            </ol>
        </section>
    );
}
