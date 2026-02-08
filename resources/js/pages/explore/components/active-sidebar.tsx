import type { IndicatorMeta } from '@/components/info-tooltip';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faGraduationCap, faSpinnerThird, faXmark } from '@/icons';
import { useTranslation } from '@/hooks/use-translation';
import { useEffect, useRef, useState } from 'react';

import type { LocationData } from '../types';

import { IndicatorBar } from './indicator-bar';
import {
    LockedPreviewContent,
    StickyUnlockBar,
} from './locked-preview';
import { ProximityFactorRow } from './proximity-factor-row';
import { ScoreCard } from './score-card';

export function ActiveSidebar({
    data,
    locationName,
    loading,
    indicatorScopes,
    indicatorMeta,
    onClose,
}: {
    data: LocationData;
    locationName: string | null;
    loading: boolean;
    indicatorScopes: Record<string, 'national' | 'urbanity_stratified'>;
    indicatorMeta: Record<string, IndicatorMeta>;
    onClose: () => void;
}) {
    const { t } = useTranslation();
    const [showStickyBar, setShowStickyBar] = useState(false);
    const firstCtaRef = useRef<HTMLDivElement>(null);

    // IntersectionObserver for sticky CTA visibility
    useEffect(() => {
        if (!firstCtaRef.current) return;

        const observer = new IntersectionObserver(
            ([entry]) => setShowStickyBar(!entry.isIntersecting),
            { threshold: 0 },
        );

        observer.observe(firstCtaRef.current);

        return () => observer.disconnect();
    }, [data]);

    if (loading) {
        return (
            <div className="flex items-center justify-center py-20">
                <FontAwesomeIcon icon={faSpinnerThird} spin className="h-6 w-6 text-muted-foreground" />
            </div>
        );
    }

    const {
        location,
        score,
        proximity,
        indicators,
        schools,
        pois,
        poi_categories,
        tier,
        preview,
    } = data;
    const isPublicTier = tier === 0;

    return (
        <ScrollArea className="h-full">
            <div className="p-4">
                {/* Header */}
                <div className="mb-3 flex items-start justify-between">
                    <div>
                        <h2 className="text-sm font-semibold text-foreground">
                            {locationName || location.kommun}
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            {location.kommun}
                        </p>
                    </div>
                    <button
                        onClick={onClose}
                        className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    >
                        <FontAwesomeIcon icon={faXmark} className="h-4 w-4" />
                    </button>
                </div>

                {/* Score card */}
                {score && (
                    <ScoreCard
                        score={score}
                        urbanityTier={location.urbanity_tier}
                    />
                )}

                {/* PUBLIC TIER: Locked preview content */}
                {isPublicTier && preview && (
                    <LockedPreviewContent
                        preview={preview}
                        lat={location.lat}
                        lng={location.lng}
                        ctaRef={firstCtaRef}
                        indicatorMeta={indicatorMeta}
                    />
                )}

                {/* PAID TIERS: Full content */}
                {!isPublicTier && (
                    <>
                        {/* Proximity Analysis */}
                        {proximity && proximity.factors.length > 0 && (
                            <>
                                <Separator className="my-3" />
                                <div className="mb-3 flex items-center justify-between">
                                    <h3 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                        {t('sidebar.proximity.title')}
                                    </h3>
                                    {proximity.safety_zone && (
                                        <span
                                            className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                                                proximity.safety_zone.level ===
                                                'high'
                                                    ? 'bg-green-100 text-green-800'
                                                    : proximity.safety_zone
                                                            .level === 'medium'
                                                      ? 'bg-yellow-100 text-yellow-800'
                                                      : 'bg-red-100 text-red-800'
                                            }`}
                                        >
                                            {t(
                                                'sidebar.proximity.safety_zone',
                                            )}
                                            :{' '}
                                            {t(
                                                `sidebar.proximity.safety_${proximity.safety_zone.level}`,
                                            )}
                                        </span>
                                    )}
                                </div>
                                {proximity.safety_zone &&
                                    proximity.safety_zone.level !== 'high' && (
                                        <p className="mb-2 text-[11px] text-orange-600">
                                            {t(
                                                `sidebar.proximity.safety_note_${proximity.safety_zone.level}`,
                                            )}
                                        </p>
                                    )}
                                <div className="space-y-3">
                                    {proximity.factors.map((factor) => (
                                        <ProximityFactorRow
                                            key={factor.slug}
                                            factor={factor}
                                        />
                                    ))}
                                </div>
                            </>
                        )}

                        {/* Indicators */}
                        {indicators.length > 0 && (
                            <>
                                <Separator className="my-3" />
                                <h3 className="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    {t('sidebar.indicators.title')}
                                </h3>
                                <div className="space-y-3">
                                    {indicators.map((ind) => (
                                        <IndicatorBar
                                            key={ind.slug}
                                            indicator={ind}
                                            scope={
                                                indicatorScopes[ind.slug] ??
                                                'national'
                                            }
                                            urbanityTier={
                                                location.urbanity_tier
                                            }
                                            meta={indicatorMeta[ind.slug]}
                                        />
                                    ))}
                                </div>
                            </>
                        )}

                        {/* Schools */}
                        {schools.length > 0 && (
                            <>
                                <Separator className="my-3" />
                                <h3 className="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    <FontAwesomeIcon icon={faGraduationCap} className="mr-1 inline h-3.5 w-3.5" />
                                    {t('sidebar.schools.title')} (
                                    {schools.length}{' '}
                                    {t('sidebar.schools.within')})
                                </h3>
                                <div className="space-y-2.5">
                                    {schools.map((school, i) => (
                                        <div
                                            key={i}
                                            className="rounded-md border border-border p-2.5"
                                        >
                                            <div className="flex items-start justify-between">
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm font-medium text-foreground">
                                                        {school.name}
                                                    </div>
                                                    <div className="text-[11px] text-muted-foreground">
                                                        {school.type ??
                                                            'Grundskola'}
                                                        {school.operator_type && (
                                                            <>
                                                                {' '}
                                                                &middot;{' '}
                                                                {school.operator_type ===
                                                                'KOMMUN'
                                                                    ? 'Kommunal'
                                                                    : 'Frist\u00e5ende'}
                                                            </>
                                                        )}
                                                    </div>
                                                </div>
                                                <span className="ml-2 shrink-0 text-xs text-muted-foreground tabular-nums">
                                                    {school.distance_m < 1000
                                                        ? `${school.distance_m}m`
                                                        : `${(school.distance_m / 1000).toFixed(1)}km`}
                                                </span>
                                            </div>
                                            {school.merit_value !== null && (
                                                <div className="mt-1 text-[11px] text-muted-foreground">
                                                    {t(
                                                        'sidebar.schools.merit',
                                                    )}
                                                    : {school.merit_value}
                                                </div>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}

                        {/* POIs */}
                        {pois.length > 0 && (
                            <>
                                <Separator className="my-3" />
                                <h3 className="mb-3 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                    {t('sidebar.pois.title')}
                                </h3>
                                <div className="space-y-1.5">
                                    {Object.entries(
                                        pois.reduce<Record<string, number>>(
                                            (acc, p) => {
                                                acc[p.category] =
                                                    (acc[p.category] || 0) + 1;
                                                return acc;
                                            },
                                            {},
                                        ),
                                    ).map(([category, count]) => (
                                        <div
                                            key={category}
                                            className="flex items-center justify-between text-xs"
                                        >
                                            <span className="flex items-center gap-1.5">
                                                <span
                                                    className="inline-block h-2.5 w-2.5 rounded-full"
                                                    style={{
                                                        backgroundColor:
                                                            poi_categories[
                                                                category
                                                            ]?.color ??
                                                            '#94a3b8',
                                                    }}
                                                />
                                                <span className="text-foreground">
                                                    {poi_categories[category]
                                                        ?.name ?? category}
                                                </span>
                                            </span>
                                            <span className="text-muted-foreground tabular-nums">
                                                {count}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </>
                        )}
                    </>
                )}
            </div>

            {/* Sticky bottom CTA (public tier only) */}
            {isPublicTier && preview && (
                <StickyUnlockBar
                    lat={location.lat}
                    lng={location.lng}
                    visible={showStickyBar}
                />
            )}
        </ScrollArea>
    );
}
