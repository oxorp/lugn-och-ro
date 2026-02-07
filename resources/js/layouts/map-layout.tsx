import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

import LanguageSwitcher from '@/components/language-switcher';
import LocaleSync from '@/components/locale-sync';
import { useTranslation } from '@/hooks/use-translation';
import { map, methodology } from '@/routes';

interface MapLayoutProps {
    children: ReactNode;
}

export default function MapLayout({ children }: MapLayoutProps) {
    const { t } = useTranslation();

    return (
        <div className="flex h-screen flex-col">
            <LocaleSync />
            <header className="bg-background border-b px-4 py-2">
                <div className="flex items-center gap-6">
                    <h1 className="text-sm font-semibold tracking-tight">
                        {t('app.title')}
                    </h1>
                    <nav className="flex flex-1 items-center gap-4">
                        <Link
                            href={map().url}
                            className="text-muted-foreground hover:text-foreground text-sm transition-colors"
                        >
                            {t('nav.map')}
                        </Link>
                        <Link
                            href={methodology().url}
                            className="text-muted-foreground hover:text-foreground text-sm transition-colors"
                        >
                            {t('nav.methodology')}
                        </Link>
                    </nav>
                    <LanguageSwitcher />
                </div>
            </header>
            <main className="relative flex min-h-0 flex-1 flex-col md:flex-row">
                {children}
            </main>
        </div>
    );
}
