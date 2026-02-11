import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBars, faXmark } from '@/icons';
import { Link, router, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

import { Footer } from '@/components/footer';
import LanguageSwitcher from '@/components/language-switcher';
import LocaleSync from '@/components/locale-sync';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Toaster } from '@/components/ui/sonner';
import { useTranslation } from '@/hooks/use-translation';
import { login, logout, map, methodology } from '@/routes';
import type { SharedData } from '@/types';

const TIER_LABELS: Record<number, string> = {
    0: 'Public',
    1: 'Free Account',
    3: 'Subscriber',
    99: 'Admin',
};

const TIER_OPTIONS = [
    { value: null, label: 'Admin', description: 'Full access' },
    { value: 3, label: 'Subscriber', description: 'Exact values' },
    { value: 1, label: 'Free Account', description: 'Band labels only' },
    { value: 0, label: 'Public', description: 'Locked indicators' },
] as const;

interface MapLayoutProps {
    children: ReactNode;
}

export default function MapLayout({ children }: MapLayoutProps) {
    const { t } = useTranslation();
    const { auth, viewingAs } = usePage<SharedData>().props;
    const user = auth?.user;
    const isAdmin = !!user?.is_admin;
    const isOverriding = isAdmin && viewingAs !== null && viewingAs !== undefined;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    const currentTierLabel = isOverriding
        ? TIER_LABELS[viewingAs as number] ?? 'Unknown'
        : 'Admin';

    const handleViewAs = (tier: number | null) => {
        if (tier === null) {
            router.delete('/admin/view-as', { preserveScroll: true });
        } else {
            router.post('/admin/view-as', { tier }, { preserveScroll: true });
        }
    };

    const loginUrl = login({ query: { redirect: window.location.pathname + window.location.search } }).url;

    return (
        <div className="flex h-screen flex-col">
            <LocaleSync />
            {isOverriding && (
                <div className="flex h-7 items-center justify-center gap-2 bg-amber-500 text-xs font-medium text-white">
                    <span>Viewing as: {currentTierLabel}</span>
                    <button
                        onClick={() => handleViewAs(null)}
                        className="rounded bg-white/20 px-1.5 py-0.5 text-[11px] transition-colors hover:bg-white/30"
                    >
                        Reset
                    </button>
                </div>
            )}
            <header className="relative z-30 flex h-12 shrink-0 items-center border-b border-border bg-background px-4">
                <div className="flex w-full items-center gap-4">
                    {/* Hamburger — mobile only */}
                    <button
                        onClick={() => setMobileMenuOpen((v) => !v)}
                        className="text-muted-foreground hover:text-foreground md:hidden"
                    >
                        {mobileMenuOpen ? <FontAwesomeIcon icon={faXmark} className="h-5 w-5" /> : <FontAwesomeIcon icon={faBars} className="h-5 w-5" />}
                    </button>

                    <span className="text-base font-semibold text-foreground">
                        {t('app.title')}
                    </span>

                    {/* Desktop nav */}
                    <nav className="hidden flex-1 items-center gap-6 md:flex">
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
                        {isAdmin && (
                            <Link
                                href="/admin/pipeline"
                                className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >
                                Pipeline
                            </Link>
                        )}
                    </nav>

                    {/* Spacer on mobile */}
                    <div className="flex-1 md:hidden" />

                    {/* Right side — always visible */}
                    <div className="flex items-center gap-3">
                        {user ? (
                            <>
                                <span className="hidden text-sm text-muted-foreground md:inline">
                                    {user.name}
                                </span>
                                {isAdmin && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button
                                                className={`hidden rounded-md border px-1.5 py-0.5 text-xs font-medium transition-colors md:inline-flex ${
                                                    isOverriding
                                                        ? 'border-amber-500 bg-amber-500/10 text-amber-600'
                                                        : 'border-amber-500/50 bg-amber-500/10 text-amber-600 hover:bg-amber-500/20'
                                                }`}
                                            >
                                                {isOverriding
                                                    ? `As: ${currentTierLabel}`
                                                    : 'Admin'}
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" className="w-48">
                                            {TIER_OPTIONS.map((option) => {
                                                const isActive = isOverriding
                                                    ? viewingAs === option.value
                                                    : option.value === null;
                                                return (
                                                    <DropdownMenuItem
                                                        key={option.label}
                                                        onClick={() => handleViewAs(option.value)}
                                                        className={isActive ? 'bg-accent' : ''}
                                                    >
                                                        <div>
                                                            <div className="text-sm font-medium">
                                                                {option.label}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {option.description}
                                                            </div>
                                                        </div>
                                                    </DropdownMenuItem>
                                                );
                                            })}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                                <button
                                    onClick={() => router.post(logout().url)}
                                    className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {t('nav.signOut')}
                                </button>
                            </>
                        ) : (
                            <Link
                                href={loginUrl}
                                className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >
                                {t('nav.signIn')}
                            </Link>
                        )}
                        <LanguageSwitcher />
                    </div>
                </div>
            </header>

            {/* Mobile nav dropdown */}
            {mobileMenuOpen && (
                <div className="z-20 border-b border-border bg-background px-4 py-3 md:hidden">
                    <nav className="flex flex-col gap-3">
                        <Link
                            href={map().url}
                            onClick={() => setMobileMenuOpen(false)}
                            className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {t('nav.map')}
                        </Link>
                        <Link
                            href={methodology().url}
                            onClick={() => setMobileMenuOpen(false)}
                            className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            {t('nav.methodology')}
                        </Link>
                        {isAdmin && (
                            <>
                                <Link
                                    href="/admin/pipeline"
                                    onClick={() => setMobileMenuOpen(false)}
                                    className="text-sm font-medium text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    Pipeline
                                </Link>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            className={`w-fit rounded-md border px-1.5 py-0.5 text-xs font-medium transition-colors ${
                                                isOverriding
                                                    ? 'border-amber-500 bg-amber-500/10 text-amber-600'
                                                    : 'border-amber-500/50 bg-amber-500/10 text-amber-600 hover:bg-amber-500/20'
                                            }`}
                                        >
                                            {isOverriding
                                                ? `As: ${currentTierLabel}`
                                                : 'Admin'}
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="start" className="w-48">
                                        {TIER_OPTIONS.map((option) => {
                                            const isActive = isOverriding
                                                ? viewingAs === option.value
                                                : option.value === null;
                                            return (
                                                <DropdownMenuItem
                                                    key={option.label}
                                                    onClick={() => handleViewAs(option.value)}
                                                    className={isActive ? 'bg-accent' : ''}
                                                >
                                                    <div>
                                                        <div className="text-sm font-medium">
                                                            {option.label}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {option.description}
                                                        </div>
                                                    </div>
                                                </DropdownMenuItem>
                                            );
                                        })}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </>
                        )}
                    </nav>
                </div>
            )}

            <main className="relative flex min-h-0 flex-1 flex-col bg-background md:flex-row">
                {children}
            </main>
            <Footer className="hidden md:block" />
            <Toaster position="bottom-left" />
        </div>
    );
}
