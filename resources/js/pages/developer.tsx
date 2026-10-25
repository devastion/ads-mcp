import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConnectPanel from '@/components/developer/connect-panel';
import CreateKeyForm from '@/components/developer/create-key-form';
import KeyTable from '@/components/developer/key-table';
import SecretOnce from '@/components/developer/secret-once';
import UsagePanel from '@/components/developer/usage-panel';
import AppLayout from '@/layouts/app-layout';
import type { CreatedKey, DeveloperProps } from '@/types/developer';

export default function Developer({ keys, usage, mcp }: DeveloperProps) {
    const [issued, setIssued] = useState<{
        key: CreatedKey;
        forPiBolt: boolean;
    } | null>(null);
    const [createVersion, setCreateVersion] = useState(0);
    const [revokedIds, setRevokedIds] = useState<number[]>([]);
    const [refreshError, setRefreshError] = useState('');
    const [creating, setCreating] = useState(false);

    useEffect(
        () =>
            router.on('start', (event) => {
                if (
                    event.detail.visit.only.length === 0 &&
                    event.detail.visit.except.length === 0
                ) {
                    setIssued(null);
                    setCreating(false);
                    setCreateVersion((version) => version + 1);
                }
            }),
        [],
    );

    const issuedRow = keys.find((apiKey) => apiKey.id === issued?.key.id);
    const issuedIsInvalid = Boolean(
        issued &&
        (issuedRow?.revoked_at ||
            revokedIds.includes(issued.key.id) ||
            (issued.forPiBolt &&
                issuedRow &&
                mcp.connection?.key_id !== issued.key.id)),
    );

    useEffect(() => {
        if (issuedIsInvalid) {
            setIssued(null);
        }
    }, [issuedIsInvalid]);

    const visibleIssued = issued && !issuedIsInvalid ? issued : null;
    const panelSecret =
        visibleIssued?.forPiBolt &&
        (!issuedRow || mcp.connection?.key_id === visibleIssued.key.id)
            ? visibleIssued.key.secret
            : null;
    const panelMcp =
        mcp.connection && revokedIds.includes(mcp.connection.key_id)
            ? { ...mcp, connection: null }
            : mcp;

    function reloadProps() {
        setRefreshError('');
        router.reload({
            only: ['keys', 'usage', 'mcp'],
            onHttpException: () => {
                setRefreshError(
                    'The latest key details could not be loaded. Save your new key before refreshing this page.',
                );
                return false;
            },
            onNetworkError: () => {
                setRefreshError(
                    'The latest key details could not be loaded. Save your new key before refreshing this page.',
                );
                return false;
            },
        });
    }

    function keyCreated(key: CreatedKey, forPiBolt: boolean) {
        setIssued({ key, forPiBolt });
        setCreateVersion((version) => version + 1);
        reloadProps();
    }

    function keyRevoked(id: number) {
        setRevokedIds((ids) => [...ids, id]);
        setIssued((current) => (current?.key.id === id ? null : current));
        reloadProps();
    }

    return (
        <AppLayout>
            <Head title="Developer" />
            <header className="grid gap-2">
                <p className="text-xs font-semibold tracking-widest text-slate-500 uppercase">
                    Developer tools
                </p>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-3xl font-semibold tracking-tight text-slate-900">
                        Your API workspace
                    </h1>
                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                        Local environment
                    </span>
                </div>
                <p className="text-sm text-slate-500">
                    Connect Pi-Bolt, track your usage, and manage API access.
                </p>
            </header>
            {refreshError && (
                <p
                    role="alert"
                    className="rounded-lg bg-amber-50 p-3 text-sm text-amber-900"
                >
                    {refreshError}
                </p>
            )}
            <ConnectPanel
                mcp={panelMcp}
                keys={keys}
                secret={panelSecret}
                onCreated={(key) => keyCreated(key, true)}
                onDismiss={() => setIssued(null)}
                createVersion={createVersion}
                creationDisabled={Boolean(visibleIssued) || creating}
                onProcessingChange={setCreating}
            />
            <UsagePanel keys={keys} usage={usage} />
            <section
                aria-labelledby="keys-heading"
                className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6"
            >
                <div>
                    <h2
                        id="keys-heading"
                        className="text-lg font-semibold text-slate-900"
                    >
                        API keys
                    </h2>
                    <p className="text-sm text-slate-500">
                        Give each integration its own key. Revoke access
                        whenever you need.
                    </p>
                </div>
                <KeyTable keys={keys} onRevoked={keyRevoked} />
                {visibleIssued && !visibleIssued.forPiBolt && (
                    <SecretOnce
                        key={visibleIssued.key.id}
                        secret={visibleIssued.key.secret}
                        onDismiss={() => setIssued(null)}
                    />
                )}
                <CreateKeyForm
                    key={createVersion}
                    onCreated={(key) => keyCreated(key, false)}
                    disabled={Boolean(visibleIssued) || creating}
                    hasUnsavedKey={Boolean(visibleIssued)}
                    onProcessingChange={setCreating}
                />
            </section>
        </AppLayout>
    );
}
