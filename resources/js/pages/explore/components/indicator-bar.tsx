import { type IndicatorMeta, InfoTooltip } from '@/components/info-tooltip';
import { PercentileBadge } from '@/components/percentile-badge';
import { PercentileBar } from '@/components/percentile-bar';

import type { LocationData } from '../types';
import { formatIndicatorValue } from '../utils';

import { Sparkline } from './sparkline';
import { TrendArrow } from './trend-arrow';

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

    const trend = indicator.trend;
    const hasTrend = trend && trend.percentiles.length >= 2;

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-xs">
                <span className="flex items-center gap-1 font-medium text-foreground">
                    {indicator.name}
                    {meta && (
                        <InfoTooltip
                            indicator={meta}
                            sparkline={
                                hasTrend ? (
                                    <Sparkline
                                        values={trend.percentiles}
                                        years={trend.years}
                                        width={220}
                                        height={36}
                                    />
                                ) : undefined
                            }
                        />
                    )}
                </span>
                <div className="flex items-center gap-1.5">
                    <PercentileBadge
                        percentile={rawPct}
                        direction={indicator.direction}
                        rawValue={indicator.raw_value}
                        unit={indicator.unit}
                        name={indicator.name}
                        scope={scope}
                        urbanityTier={urbanityTier}
                    />
                    <TrendArrow
                        change={trend?.change_1y ?? null}
                        direction={indicator.direction}
                    />
                </div>
            </div>
            <PercentileBar effectivePct={effectivePct} />
            <div className="text-[11px] text-muted-foreground">
                ({formatIndicatorValue(indicator.raw_value, indicator.unit)})
            </div>
        </div>
    );
}
