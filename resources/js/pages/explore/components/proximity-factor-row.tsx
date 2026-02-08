import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { useTranslation } from '@/hooks/use-translation';

import { PROXIMITY_FACTOR_CONFIG } from '../constants';
import type { ProximityFactor } from '../types';
import { formatDistance, scoreBgStyle } from '../utils';

export function ProximityFactorRow({ factor }: { factor: ProximityFactor }) {
    const { t } = useTranslation();
    const config = PROXIMITY_FACTOR_CONFIG[factor.slug];
    if (!config || factor.score === null) return null;

    const score = factor.score;
    const details = factor.details;

    // For negative POI, 100 = good (no negatives nearby)
    const isNegativeType = factor.slug === 'prox_negative_poi';
    const displayName =
        details.nearest_school ??
        details.nearest_park ??
        details.nearest_stop ??
        details.nearest_store ??
        details.nearest ??
        null;
    const distanceM = config.distanceKey
        ? (details[config.distanceKey] as number | undefined)
        : undefined;
    const effectiveDistanceM = details.effective_distance_m as
        | number
        | undefined;

    // Special label for negative/positive POI counts
    let subtitle: string | null = null;
    if (factor.slug === 'prox_negative_poi') {
        const count = (details.count as number) ?? 0;
        subtitle =
            count === 0
                ? t('sidebar.proximity.no_negative')
                : `${count} ${t('sidebar.proximity.negative_count')}`;
    } else if (factor.slug === 'prox_positive_poi') {
        const count = (details.count as number) ?? 0;
        subtitle = `${count} ${t('sidebar.proximity.positive_count')}`;
    } else if (displayName) {
        subtitle = String(displayName);
    }

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-xs">
                <span className="flex items-center gap-1.5 font-medium text-foreground">
                    <FontAwesomeIcon icon={config.icon} className="h-3.5 w-3.5 text-muted-foreground" />
                    {t(config.nameKey)}
                </span>
                <span className="font-semibold text-foreground tabular-nums">
                    {score}
                </span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full transition-all"
                    style={{
                        width: `${score}%`,
                        ...scoreBgStyle(isNegativeType ? score : score),
                    }}
                />
            </div>
            {(subtitle || distanceM !== undefined) && (
                <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                    <span className="truncate">{subtitle}</span>
                    {distanceM !== undefined && distanceM > 0 && (
                        <span className="ml-2 shrink-0 tabular-nums">
                            {formatDistance(distanceM)}
                            {effectiveDistanceM !== undefined &&
                                effectiveDistanceM > distanceM + 10 && (
                                    <span className="ml-1 text-orange-600">
                                        (
                                        {t(
                                            'sidebar.proximity.effective_distance',
                                            {
                                                distance:
                                                    formatDistance(
                                                        effectiveDistanceM,
                                                    ),
                                            },
                                        )}
                                        )
                                    </span>
                                )}
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}
