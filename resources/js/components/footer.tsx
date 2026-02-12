import { Link } from '@inertiajs/react';

import { SourceMarquee } from '@/components/source-marquee';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

interface FooterProps {
    className?: string;
}

export function Footer({ className }: FooterProps) {
    const { t } = useTranslation();
    const currentYear = new Date().getFullYear();

    return (
        <footer className={cn('bg-background', className)}>
            <SourceMarquee />
            <div className="flex flex-col items-center gap-2 px-4 py-6 text-center text-xs text-muted-foreground md:flex-row md:justify-between md:text-left">
                <p>
                    © {currentYear} {t('footer.company')}. {t('footer.rights')}
                </p>
                <nav className="flex gap-4">
                    <Link
                        href="/for-maklare"
                        className="transition-colors hover:text-foreground"
                    >
                        {t('footer.for_maklare')}
                    </Link>
                    <Link
                        href="/privacy"
                        className="transition-colors hover:text-foreground"
                    >
                        {t('footer.privacy')}
                    </Link>
                    <Link
                        href="/terms"
                        className="transition-colors hover:text-foreground"
                    >
                        {t('footer.terms')}
                    </Link>
                </nav>
            </div>
        </footer>
    );
}
