import { type IndicatorMeta, InfoTooltip } from '@/components/info-tooltip';
import { PercentileBadge } from '@/components/percentile-badge';
import { PercentileBar } from '@/components/percentile-bar';

import type { LocationData } from '../types';
import { formatIndicatorValue } from '../utils';

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
    const rawPct = Math.round(indicator.normalized_value * 100);
    const effectivePct =
        indicator.direction === 'negative' ? 100 - rawPct : rawPct;

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-xs">
                <span className="flex items-center gap-1 font-medium text-foreground">
                    {indicator.name}
                    {meta && <InfoTooltip indicator={meta} />}
                </span>
                <PercentileBadge
                    percentile={rawPct}
                    direction={indicator.direction}
                    rawValue={indicator.raw_value}
                    unit={indicator.unit}
                    name={indicator.name}
                    scope={scope}
                    urbanityTier={urbanityTier}
                />
            </div>
            <PercentileBar effectivePct={effectivePct} />
            <div className="text-[11px] text-muted-foreground">
                ({formatIndicatorValue(indicator.raw_value, indicator.unit)})
            </div>
        </div>
    );
}
