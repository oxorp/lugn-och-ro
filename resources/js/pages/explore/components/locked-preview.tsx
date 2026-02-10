import { type IndicatorMeta, InfoTooltip } from '@/components/info-tooltip';
import { PercentileBadge } from '@/components/percentile-badge';
import { PercentileBar } from '@/components/percentile-bar';
import { SourceMarquee } from '@/components/source-marquee';
import { Button } from '@/components/ui/button';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faChartColumn, faGraduationCap, faLocationDot, faLock, faShieldHalved, faTree } from '@/icons';
import { useTranslation } from '@/hooks/use-translation';

import type {
    PreviewCategory,
    PreviewCtaSummary,
    PreviewData,
    PreviewFreeIndicator,
} from '../types';
import { formatIndicatorValue } from '../utils';

function FreeIndicatorRow({
    indicator,
    meta,
}: {
    indicator: PreviewFreeIndicator;
    meta?: IndicatorMeta;
}) {
    const percentile = indicator.percentile ?? 0;
    const effectivePct =
        indicator.direction === 'negative' ? 100 - percentile : percentile;

    return (
        <div className="space-y-1 py-1.5">
            <div className="flex items-center justify-between text-sm">
                <span className="flex min-w-0 flex-1 items-center truncate">
                    {indicator.name}
                    {meta && <InfoTooltip indicator={meta} />}
                </span>
                {indicator.percentile !== null ? (
                    <PercentileBadge
                        percentile={percentile}
                        direction={indicator.direction}
                        rawValue={indicator.raw_value}
                        unit={indicator.unit}
                        name={indicator.name}
                    />
                ) : (
                    <span className="shrink-0 text-xs text-muted-foreground">
                        &mdash;
                    </span>
                )}
            </div>
            <PercentileBar effectivePct={effectivePct} className="h-1.5" />
            <div className="text-[11px] text-muted-foreground">
                ({formatIndicatorValue(indicator.raw_value, indicator.unit)})
            </div>
        </div>
    );
}


function LockedCountLabel({ count }: { count: number }) {
    return (
        <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
            <FontAwesomeIcon icon={faLock} className="h-3 w-3" />+ {count} indikatorer i rapporten
        </p>
    );
}

const CATEGORY_ICONS: Record<string, import('@fortawesome/fontawesome-svg-core').IconDefinition> = {
    'shield-halved': faShieldHalved,
    'chart-column': faChartColumn,
    'graduation-cap': faGraduationCap,
    'tree': faTree,
    'location-dot': faLocationDot,
};

function CategorySection({
    category,
    indicatorMeta,
}: {
    category: PreviewCategory;
    indicatorMeta?: Record<string, IndicatorMeta>;
}) {
    const icon = CATEGORY_ICONS[category.icon];

    return (
        <div className="pt-12 first:pt-0">
            {/* Category header */}
            <div className="mb-3 flex items-center gap-2">
                {icon && (
                    <FontAwesomeIcon icon={icon} className="h-4 w-4 text-muted-foreground" />
                )}
                <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {category.label}
                </h3>
            </div>

            {/* Free preview indicators with real values */}
            {category.free_indicators.length > 0 ? (
                <>
                    {category.free_indicators.map((indicator) => (
                        <FreeIndicatorRow
                            key={indicator.slug}
                            indicator={indicator}
                            meta={indicatorMeta?.[indicator.slug]}
                        />
                    ))}
                    {category.locked_count > 0 && (
                        <LockedCountLabel count={category.locked_count} />
                    )}
                </>
            ) : (
                <LockedCountLabel count={category.indicator_count} />
            )}
        </div>
    );
}

function LockedSchoolCard() {
    return (
        <div className="rounded-lg border p-3 opacity-60">
            <div className="mb-2 flex items-center gap-2">
                <FontAwesomeIcon icon={faGraduationCap} className="h-4 w-4 text-muted-foreground" />
                <div className="h-4 w-32 rounded bg-muted" />
            </div>
            <div className="mb-2 flex items-center gap-2">
                <div className="h-3 w-20 rounded bg-muted" />
                <span className="text-muted-foreground">&middot;</span>
                <div className="h-3 w-16 rounded bg-muted" />
            </div>
            <div className="flex items-center gap-2">
                <span className="text-xs text-muted-foreground">
                    Meritv&auml;rde
                </span>
                <div className="h-2 w-16 rounded-full bg-muted" />
            </div>
        </div>
    );
}

function CTASummary({
    summary,
    dataPointCount,
    sourceCount,
    lat,
    lng,
}: {
    summary: PreviewCtaSummary;
    dataPointCount: number;
    sourceCount: number;
    lat: number;
    lng: number;
}) {
    const { t } = useTranslation();

    return (
        <div className="mt-6 border-t pt-5">
            <div className="rounded-xl bg-linear-to-br from-primary/5 to-primary/10 p-5 text-center">
                <FontAwesomeIcon icon={faLock} className="mx-auto mb-3 h-5 w-5 text-primary" />
                <h3 className="mb-3 text-base font-semibold">
                    {t('sidebar.preview.unlock_title')}
                </h3>

                <div className="mb-4 space-y-1.5 text-sm text-muted-foreground">
                    <p>
                        <strong className="text-foreground">
                            {dataPointCount}
                        </strong>{' '}
                        {t('sidebar.preview.data_points')}
                    </p>
                    <p>
                        <strong className="text-foreground">
                            {sourceCount}
                        </strong>{' '}
                        {t('sidebar.preview.sources')}
                    </p>
                    <p>
                        <strong className="text-foreground">
                            {summary.indicator_count}
                        </strong>{' '}
                        {t('sidebar.preview.cta_indicators')}
                    </p>
                    {summary.poi_count > 0 && (
                        <p>
                            <strong className="text-foreground">
                                {summary.poi_count}
                            </strong>{' '}
                            {t('sidebar.preview.cta_pois')}
                        </p>
                    )}
                </div>

                <a href={`/purchase/${lat},${lng}`}>
                    <Button className="w-full" size="lg">
                        {t('sidebar.preview.unlock_button')}
                    </Button>
                </a>
                <p className="mt-2 text-[11px] text-muted-foreground">
                    {t('sidebar.preview.one_time')}
                </p>
            </div>
        </div>
    );
}

export function StickyUnlockBar({
    lat,
    lng,
    visible,
}: {
    lat: number;
    lng: number;
    visible: boolean;
}) {
    const { t } = useTranslation();

    if (!visible) return null;

    return (
        <div className="sticky bottom-0 border-t bg-background/95 p-3 backdrop-blur">
            <a href={`/purchase/${lat},${lng}`} className="block">
                <Button className="w-full" size="sm">
                    {t('sidebar.preview.unlock_full_report')}
                </Button>
            </a>
        </div>
    );
}

export function LockedPreviewContent({
    preview,
    lat,
    lng,
    ctaRef,
    indicatorMeta,
}: {
    preview: PreviewData;
    lat: number;
    lng: number;
    ctaRef: React.RefObject<HTMLDivElement | null>;
    indicatorMeta?: Record<string, IndicatorMeta>;
}) {
    const { t } = useTranslation();

    return (
        <>
            {/* Anchor for sticky CTA visibility observer */}
            <div ref={ctaRef} />

            {/* Category sections with free + locked indicators */}
            <div className="mt-6 divide-y divide-border">
                {preview.categories
                    .filter((cat) => cat.has_data)
                    .map((category) => (
                        <CategorySection
                            key={category.slug}
                            category={category}
                            indicatorMeta={indicatorMeta}
                        />
                    ))}
            </div>

            {/* Schools teaser */}
            {preview.nearby_school_count > 0 && (
                <div className="mt-5 border-t pt-5">
                    <div className="mb-3 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <FontAwesomeIcon icon={faGraduationCap} className="h-4 w-4 text-muted-foreground" />
                            <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                {t('sidebar.preview.schools_section')}
                            </h3>
                        </div>
                        <span className="text-xs text-muted-foreground">
                            {t('sidebar.preview.schools_nearby', {
                                count: preview.nearby_school_count,
                            })}
                        </span>
                    </div>
                    <div className="space-y-2">
                        {Array.from({
                            length: Math.min(preview.nearby_school_count, 3),
                        }).map((_, i) => (
                            <LockedSchoolCard key={i} />
                        ))}
                    </div>
                    {preview.nearby_school_count > 3 && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            {t('sidebar.preview.more_schools', {
                                count: preview.nearby_school_count - 3,
                            })}
                        </p>
                    )}
                </div>
            )}

            {/* Final CTA with summary stats */}
            <CTASummary
                summary={preview.cta_summary}
                dataPointCount={preview.data_point_count}
                sourceCount={preview.source_count}
                lat={lat}
                lng={lng}
            />

            {/* Source attribution marquee */}
            <div className="mt-4">
                <SourceMarquee />
            </div>
        </>
    );
}
