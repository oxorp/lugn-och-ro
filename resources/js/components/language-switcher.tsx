import { router } from '@inertiajs/react';

import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export default function LanguageSwitcher() {
    const { i18n } = useTranslation();
    const currentLang = i18n.language;

    const switchTo = (lang: string) => {
        const currentPath = window.location.pathname;
        let newPath: string;

        if (lang === 'en') {
            // Swedish → English: add /en/ prefix
            newPath = '/en' + currentPath;
        } else {
            // English → Swedish: remove /en prefix
            newPath = currentPath.replace(/^\/en/, '') || '/';
        }

        document.cookie = `locale=${lang};path=/;max-age=31536000`;
        router.visit(newPath);
    };

    return (
        <div className="flex items-center gap-1 text-sm">
            <button
                onClick={() => switchTo('sv')}
                className={cn(
                    'rounded px-1.5 py-0.5',
                    currentLang === 'sv'
                        ? 'text-foreground font-semibold'
                        : 'text-muted-foreground hover:text-foreground',
                )}
            >
                SV
            </button>
            <span className="text-muted-foreground">|</span>
            <button
                onClick={() => switchTo('en')}
                className={cn(
                    'rounded px-1.5 py-0.5',
                    currentLang === 'en'
                        ? 'text-foreground font-semibold'
                        : 'text-muted-foreground hover:text-foreground',
                )}
            >
                EN
            </button>
        </div>
    );
}
