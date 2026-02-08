import { ArrowDown, ArrowUp } from 'lucide-react';

import { useTranslation } from '@/hooks/use-translation';

import { useScoreLabel } from '../hooks/use-score-label';
import type { LocationData } from '../types';
import { scoreBgStyle } from '../utils';

export function ScoreCard({
    score,
    urbanityTier,
}: {
    score: NonNullable<LocationData['score']>;
    urbanityTier: string | null;
}) {
    const { t } = useTranslation();
    const getScoreLabel = useScoreLabel();

    return (
        <div className="mb-4 rounded-lg border border-border p-3">
            <div className="flex items-center gap-3">
                <div
                    className="flex h-14 w-14 flex-col items-center justify-center rounded-lg text-white"
                    style={scoreBgStyle(score.value)}
                >
                    <span className="text-lg leading-tight font-bold">
                        {score.value}
                    </span>
                    {score.trend_1y !== null &&
                        score.trend_1y !== 0 && (
                            <span className="flex items-center text-[10px] leading-none opacity-90">
                                {score.trend_1y > 0 ? (
                                    <ArrowUp className="mr-0.5 h-2.5 w-2.5" />
                                ) : (
                                    <ArrowDown className="mr-0.5 h-2.5 w-2.5" />
                                )}
                                {score.trend_1y > 0 ? '+' : ''}
                                {score.trend_1y.toFixed(1)}
                            </span>
                        )}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold text-foreground">
                        {getScoreLabel(score.value)}
                    </div>
                    {urbanityTier && (
                        <div className="text-[11px] text-muted-foreground capitalize">
                            {t(`sidebar.urbanity.${urbanityTier}`)}
                        </div>
                    )}
                    {/* Area vs Proximity breakdown */}
                    {score.area_score !== null && (
                        <div className="mt-1 flex gap-3 text-[11px] text-muted-foreground tabular-nums">
                            <span>
                                {t('sidebar.proximity.area_label')}:{' '}
                                {score.area_score}
                            </span>
                            <span>
                                {t('sidebar.proximity.location_label')}:{' '}
                                {score.proximity_score}
                            </span>
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
