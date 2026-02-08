import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import { formatIndicatorValue, scoreBgStyle } from '@/pages/explore/utils';

export function PercentileBadge({
    percentile,
    direction,
    rawValue,
    unit,
    name,
    scope = 'national',
    urbanityTier,
}: {
    percentile: number;
    direction: 'positive' | 'negative' | 'neutral';
    rawValue?: number;
    unit?: string | null;
    name?: string;
    scope?: 'national' | 'urbanity_stratified';
    urbanityTier?: string | null;
}) {
    const { t } = useTranslation();

    const effectivePct =
        direction === 'negative' ? 100 - percentile : percentile;

    const isStratified = scope === 'urbanity_stratified' && urbanityTier;
    const tierLabel = urbanityTier
        ? t(`sidebar.urbanity.${urbanityTier}`)
        : '';

    const label = isStratified
        ? t('sidebar.indicators.percentile_stratified', {
              value: effectivePct,
              tier: tierLabel,
          })
        : t('sidebar.indicators.percentile_national', {
              value: effectivePct,
          });

    const hasTooltipContent = rawValue !== undefined || name;

    const badge = (
        <span
            className="shrink-0 text-xs font-semibold tabular-nums"
            style={{ color: scoreBgStyle(effectivePct).backgroundColor }}
        >
            {label}
        </span>
    );

    if (!hasTooltipContent) return badge;

    return (
        <Tooltip>
            <TooltipTrigger asChild>{badge}</TooltipTrigger>
            <TooltipContent side="left" align="center">
                <div className="space-y-1">
                    {name && <p className="font-medium">{name}</p>}
                    {rawValue !== undefined && (
                        <p className="text-muted-foreground">
                            {formatIndicatorValue(rawValue, unit ?? null)}
                        </p>
                    )}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}
