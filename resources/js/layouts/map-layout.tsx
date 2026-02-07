import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

import LanguageSwitcher from '@/components/language-switcher';
import LocaleSync from '@/components/locale-sync';
import { Toaster } from '@/components/ui/sonner';
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
                    </nav>
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
