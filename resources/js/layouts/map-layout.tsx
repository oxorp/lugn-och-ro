import { type ReactNode } from 'react';

interface MapLayoutProps {
    children: ReactNode;
}

export default function MapLayout({ children }: MapLayoutProps) {
    return (
        <div className="flex h-screen flex-col">
            <header className="bg-background border-b px-4 py-2">
                <div className="flex items-center gap-3">
                    <h1 className="text-sm font-semibold tracking-tight">
                        Swedish Real Estate Platform
                    </h1>
                </div>
            </header>
            <main className="relative min-h-0 flex-1">{children}</main>
        </div>
    );
}
