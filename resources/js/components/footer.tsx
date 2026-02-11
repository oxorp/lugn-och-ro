import { Link } from '@inertiajs/react';

import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

interface FooterProps {
    className?: string;
}

export function Footer({ className }: FooterProps) {
    const { t } = useTranslation();
    const currentYear = new Date().getFullYear();

    return (
        <footer
            className={cn(
                'border-t border-border bg-background px-4 py-6',
                className,
            )}
        >
            <div className="flex flex-col items-center gap-2 text-center text-xs text-muted-foreground md:flex-row md:justify-between md:text-left">
                <p>
                    © {currentYear} {t('footer.company')}. {t('footer.rights')}
                </p>
                <nav className="flex gap-4">
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
