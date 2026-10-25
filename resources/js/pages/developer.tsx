import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';

export default function Developer() {
    return (
        <AppLayout>
            <Head title="Developer" />
            <h1 className="text-2xl font-semibold">Developer</h1>
        </AppLayout>
    );
}
