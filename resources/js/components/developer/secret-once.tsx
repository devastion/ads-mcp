import CopyField from '@/components/developer/copy-field';

export default function SecretOnce({
    secret,
    onDismiss,
}: {
    secret: string;
    onDismiss: () => void;
}) {
    return (
        <div
            className="grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4"
            aria-label="Save your new API key"
        >
            <div>
                <h3 className="font-semibold text-slate-900">
                    Save your API key
                </h3>
                <p className="text-sm text-slate-700">
                    You won't see this key again.
                </p>
            </div>
            <CopyField value={secret} label="New API key" />
            <button
                type="button"
                onClick={onDismiss}
                className="justify-self-start rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
            >
                I've saved it
            </button>
        </div>
    );
}
