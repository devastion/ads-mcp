import type { PropsWithChildren } from 'react';

export default function AppLayout({ children }: PropsWithChildren) {
    return (
        <main className="mx-auto max-w-4xl space-y-8 px-4 py-10">
            {children}
        </main>
    );
}
