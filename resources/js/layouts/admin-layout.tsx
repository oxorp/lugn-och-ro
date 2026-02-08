import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type ReactNode } from 'react';

import LocaleSync from '@/components/locale-sync';
import { Toaster } from '@/components/ui/sonner';
import { cn } from '@/lib/utils';

const navItems = [
    { label: 'Indicators', href: '/admin/indicators' },
    { label: 'POI Safety', href: '/admin/poi-categories' },
    { label: 'Pipeline', href: '/admin/pipeline' },
    { label: 'Data Quality', href: '/admin/data-quality' },
];

interface AdminLayoutProps {
    children: ReactNode;
}

export default function AdminLayout({ children }: AdminLayoutProps) {
    const { url } = usePage();
    const currentPath = new URL(url, window?.location.origin).pathname;

    function isActive(href: string): boolean {
        if (href === currentPath) return true;
        // Pipeline sub-pages (e.g. /admin/pipeline/scb) keep Pipeline tab active
        if (href === '/admin/pipeline' && currentPath.startsWith('/admin/pipeline/')) return true;
        return false;
    }

    return (
        <div className="min-h-screen bg-muted">
            <LocaleSync />
            <header className="border-b bg-background">
                <div className="mx-auto flex max-w-[3000px] items-center gap-4 px-6 py-3">
                    <Link
                        href="/"
                        className="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Map
                    </Link>
                    <span className="text-sm text-muted-foreground">/</span>
                    <span className="text-sm font-semibold">Admin</span>
                </div>
                <nav className="mx-auto flex max-w-[3000px] gap-6 px-6">
                    {navItems.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className={cn(
                                'border-b-2 px-1 pb-2 text-sm font-medium transition-colors',
                                isActive(item.href)
                                    ? 'border-foreground text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>
            </header>
            <main className="mx-auto max-w-[3000px] p-6">
                {children}
            </main>
            <Toaster position="bottom-left" />
        </div>
    );
}
