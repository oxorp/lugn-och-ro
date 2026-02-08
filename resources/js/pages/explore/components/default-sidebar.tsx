import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faLocationDot } from '@/icons';
import MapSearch from '@/components/map-search';
import { useTranslation } from '@/hooks/use-translation';
import type { SearchResult } from '@/services/geocoding';

export function DefaultSidebar({
    onResultSelect,
    onSearchClear,
}: {
    onResultSelect: (result: SearchResult) => void;
    onSearchClear: () => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex h-full flex-col items-center justify-center overflow-y-auto px-6 py-6 text-center md:py-12">
            <FontAwesomeIcon icon={faLocationDot} className="mb-3 h-8 w-8 text-muted-foreground" />
            <h3 className="mb-1 text-sm font-semibold text-foreground">
                {t('sidebar.default.title')}
            </h3>
            <p className="mb-6 text-xs text-muted-foreground">
                {t('sidebar.default.subtitle')}
            </p>
            <MapSearch
                onResultSelect={onResultSelect}
                onClear={onSearchClear}
                className="w-full"
            />
        </div>
    );
}
