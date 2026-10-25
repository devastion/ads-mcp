import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { index } from '@/routes/developer';

export default function Welcome({ isLocal }: { isLocal: boolean }) {
    return (
        <AppLayout>
            <Head title="Ads API" />
            <h1 className="text-2xl font-semibold">Ads API</h1>
            {isLocal && (
                <Link href={index.url()} className="text-blue-700 underline">
                    Developer page
                </Link>
            )}
        </AppLayout>
    );
}
