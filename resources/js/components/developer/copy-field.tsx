import { useId, useRef, useState } from 'react';

export default function CopyField({
    value,
    label,
    multiline = false,
}: {
    value: string;
    label: string;
    multiline?: boolean;
}) {
    const id = useId();
    const field = useRef<HTMLTextAreaElement>(null);
    const [feedback, setFeedback] = useState('');

    async function copy() {
        try {
            await navigator.clipboard.writeText(value);
            setFeedback('Copied');
        } catch {
            field.current?.focus();
            field.current?.select();
            setFeedback(
                'Clipboard unavailable. The full text is selected; copy it manually.',
            );
        }
    }

    return (
        <div className="grid gap-2">
            <div className="flex items-center justify-between gap-3">
                <label
                    htmlFor={id}
                    className="text-sm font-medium text-slate-700"
                >
                    {label}
                </label>
                <button
                    type="button"
                    onClick={() => void copy()}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                >
                    Copy
                </button>
            </div>
            <textarea
                ref={field}
                id={id}
                value={value}
                readOnly
                rows={multiline ? 12 : 2}
                spellCheck={false}
                wrap="off"
                aria-describedby={`${id}-feedback`}
                className="w-full resize-none rounded-lg border border-slate-200 bg-slate-950 p-3 font-mono text-xs leading-6 text-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
            />
            <p
                id={`${id}-feedback`}
                role="status"
                className="min-h-5 text-xs text-slate-600"
            >
                {feedback}
            </p>
        </div>
    );
}
