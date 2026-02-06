import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

import { map, methodology } from '@/routes';

interface MapLayoutProps {
    children: ReactNode;
}

export default function MapLayout({ children }: MapLayoutProps) {
    return (
        <div className="flex h-screen flex-col">
            <header className="bg-background border-b px-4 py-2">
                <div className="flex items-center gap-6">
                    <h1 className="text-sm font-semibold tracking-tight">
                        Swedish Real Estate Platform
                    </h1>
                    <nav className="flex items-center gap-4">
                        <Link
                            href={map().url}
                            className="text-muted-foreground hover:text-foreground text-sm transition-colors"
                        >
                            Map
                        </Link>
                        <Link
                            href={methodology().url}
                            className="text-muted-foreground hover:text-foreground text-sm transition-colors"
                        >
                            Methodology
                        </Link>
                    </nav>
                </div>
            </header>
            <main className="relative flex min-h-0 flex-1 flex-col md:flex-row">
                {children}
            </main>
        </div>
    );
}
