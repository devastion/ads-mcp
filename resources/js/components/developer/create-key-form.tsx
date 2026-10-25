import { router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { store } from '@/routes/developer/keys';
import type {
    ApiKeyScope,
    CreatedKey,
    CreateKeyResponse,
} from '@/types/developer';

const scopes: { value: ApiKeyScope; label: string }[] = [
    { value: 'search:read', label: 'Search ads and niches' },
    { value: 'keys:manage', label: 'Create and revoke keys' },
];

export default function CreateKeyForm({
    onCreated,
    disabled,
    hasUnsavedKey,
    onProcessingChange,
}: {
    onCreated: (key: CreatedKey) => void;
    disabled: boolean;
    hasUnsavedKey: boolean;
    onProcessingChange: (processing: boolean) => void;
}) {
    const form = useHttp<
        { name: string; scopes: ApiKeyScope[] },
        CreateKeyResponse
    >({
        name: '',
        scopes: ['search:read'],
    });
    const [error, setError] = useState('');
    const cancel = form.cancel;
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

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        form.clearErrors();
        const version = requestVersion.current;

        try {
            await form.post(store.url(), {
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
        <form
            onSubmit={(event) => void submit(event)}
            className="grid gap-4 rounded-xl bg-slate-50 p-4"
        >
            <div className="grid gap-1.5">
                <label
                    htmlFor="key-name"
                    className="text-sm font-medium text-slate-800"
                >
                    Key name
                </label>
                <input
                    id="key-name"
                    name="name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    placeholder="e.g. Reporting dashboard"
                    required
                    maxLength={100}
                    disabled={disabled || form.processing}
                    aria-invalid={Boolean(form.errors.name)}
                    aria-describedby={
                        form.errors.name ? 'key-name-error' : undefined
                    }
                    className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-blue-600 disabled:opacity-60"
                />
                {form.errors.name && (
                    <p
                        id="key-name-error"
                        role="alert"
                        className="text-sm text-red-700"
                    >
                        {form.errors.name}
                    </p>
                )}
            </div>
            <fieldset
                disabled={disabled || form.processing}
                className="grid gap-2"
            >
                <legend className="mb-2 text-sm font-medium text-slate-800">
                    Scopes
                </legend>
                {scopes.map((scope) => (
                    <label
                        key={scope.value}
                        className="flex items-center gap-2 text-sm text-slate-700"
                    >
                        <input
                            type="checkbox"
                            name="scopes"
                            value={scope.value}
                            checked={form.data.scopes.includes(scope.value)}
                            onChange={(event) =>
                                form.setData(
                                    'scopes',
                                    event.target.checked
                                        ? [...form.data.scopes, scope.value]
                                        : form.data.scopes.filter(
                                              (value) => value !== scope.value,
                                          ),
                                )
                            }
                            className="size-4 rounded accent-blue-700"
                        />
                        <code className="text-xs">{scope.value}</code>
                        <span className="text-slate-500">{scope.label}</span>
                    </label>
                ))}
                {form.errors.scopes && (
                    <p role="alert" className="text-sm text-red-700">
                        {form.errors.scopes}
                    </p>
                )}
            </fieldset>
            {error && (
                <p role="alert" className="text-sm text-red-700">
                    {error}
                </p>
            )}
            <button
                type="submit"
                disabled={disabled || form.processing}
                className="justify-self-start rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600 disabled:cursor-not-allowed disabled:opacity-50"
            >
                {form.processing ? 'Creating…' : 'Create API key'}
            </button>
            {hasUnsavedKey && (
                <p className="text-xs text-slate-600">
                    Save and dismiss your new key before creating another.
                </p>
            )}
        </form>
    );
}
