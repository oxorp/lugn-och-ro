import { Link, router, usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

import LanguageSwitcher from '@/components/language-switcher';
import LocaleSync from '@/components/locale-sync';
import { Toaster } from '@/components/ui/sonner';
import { useTranslation } from '@/hooks/use-translation';
import { map, methodology } from '@/routes';
import type { SharedData } from '@/types';

interface MapLayoutProps {
    children: ReactNode;
}

export default function MapLayout({ children }: MapLayoutProps) {
    const { t } = useTranslation();
    const { auth, appEnv } = usePage<SharedData & { appEnv: string }>().props;
    const isLocal = appEnv === 'local';
    const user = auth?.user;
    const isAdmin = !!user?.is_admin;

    return (
        <div className="flex h-screen flex-col">
            <LocaleSync />
            <header className="bg-background flex h-12 shrink-0 items-center border-b border-border px-4">
                <div className="flex w-full items-center gap-6">
                    <span className="text-base font-semibold text-foreground">
                        {t('app.title')}
                    </span>
                    <nav className="flex flex-1 items-center gap-6">
                        <Link
                            href={map().url}
                            className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {t('nav.map')}
                        </Link>
                        <Link
                            href={methodology().url}
                            className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {t('nav.methodology')}
                        </Link>
                        <Link
                            href="/admin/pipeline"
                            className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Pipeline
                        </Link>
                    </nav>
                    {isLocal && user && (
                        <button
                            onClick={() => router.post('/dev/toggle-admin', {}, { preserveScroll: true })}
                            className={`rounded-md border px-2 py-0.5 text-xs font-medium transition-colors ${
                                isAdmin
                                    ? 'border-amber-500/50 bg-amber-500/10 text-amber-600'
                                    : 'border-border bg-muted text-muted-foreground'
                            }`}
                        >
                            {isAdmin ? 'Admin' : 'User'}
                        </button>
                    )}
                    <LanguageSwitcher />
                </div>
            </header>
            <main className="relative flex min-h-0 flex-1 flex-col md:flex-row">
                {children}
            </main>
            <Toaster position="bottom-left" />
        </div>
    );
}
