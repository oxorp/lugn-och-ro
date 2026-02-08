import { MapPin } from 'lucide-react';

import { useTranslation } from '@/hooks/use-translation';

export function DefaultSidebar({
    onTrySearch,
}: {
    onTrySearch: (query: string) => void;
}) {
    const { t } = useTranslation();

    const suggestions = ['Sveavägen, Stockholm', 'Kungsbacka', 'Lomma'];

    return (
        <div className="flex h-full flex-col items-center justify-center overflow-y-auto px-6 py-6 text-center md:py-12">
            <MapPin className="mb-3 h-8 w-8 text-muted-foreground" />
            <h3 className="mb-1 text-sm font-semibold text-foreground">
                {t('sidebar.default.title')}
            </h3>
            <p className="mb-6 text-xs text-muted-foreground">
                {t('sidebar.default.subtitle')}
            </p>
            <div className="w-full space-y-2">
                {suggestions.map((s) => (
                    <button
                        key={s}
                        onClick={() => onTrySearch(s)}
                        className="w-full rounded-md border border-border px-3 py-2 text-left text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        {t('sidebar.default.try')}: {s}
                    </button>
                ))}
            </div>
            <p className="mt-6 text-[11px] text-muted-foreground">
                {t('sidebar.default.legend_hint')}
            </p>
        </div>
    );
}
