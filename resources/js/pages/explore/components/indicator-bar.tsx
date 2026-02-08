import { type IndicatorMeta, InfoTooltip } from '@/components/info-tooltip';
import { useTranslation } from '@/hooks/use-translation';

import type { LocationData } from '../types';
import { formatIndicatorValue, scoreBgStyle } from '../utils';

export function IndicatorBar({
    indicator,
    scope,
    urbanityTier,
    meta,
}: {
    indicator: LocationData['indicators'][0];
    scope: 'national' | 'urbanity_stratified';
    urbanityTier: string | null;
    meta?: IndicatorMeta;
}) {
    const { t } = useTranslation();
    const rawPct = Math.round(indicator.normalized_value * 100);
    const effectivePct =
        indicator.direction === 'negative' ? 100 - rawPct : rawPct;
    const isStratified = scope === 'urbanity_stratified' && urbanityTier;
    const tierLabel = urbanityTier ? t(`sidebar.urbanity.${urbanityTier}`) : '';

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-xs">
                <span className="flex items-center gap-1 font-medium text-foreground">
                    {indicator.name}
                    {meta && <InfoTooltip indicator={meta} />}
                </span>
                <span className="font-semibold text-foreground tabular-nums">
                    {isStratified
                        ? t('sidebar.indicators.percentile_stratified', {
                              value: effectivePct,
                              tier: tierLabel,
                          })
                        : t('sidebar.indicators.percentile_national', {
                              value: effectivePct,
                          })}
                </span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full transition-all"
                    style={{
                        width: `${effectivePct}%`,
                        ...scoreBgStyle(effectivePct),
                    }}
                />
            </div>
            <div className="text-[11px] text-muted-foreground">
                ({formatIndicatorValue(indicator.raw_value, indicator.unit)})
            </div>
        </div>
    );
}
