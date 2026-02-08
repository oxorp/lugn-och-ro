import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { Database, GraduationCap, Lock } from 'lucide-react';
import { useMemo } from 'react';

import type {
    PreviewCategory,
    PreviewCtaSummary,
    PreviewData,
    PreviewFreeIndicator,
} from '../types';

const SOURCE_LABELS: Record<string, string> = {
    scb: 'SCB',
    skolverket: 'Skolverket',
    gtfs: 'Trafiklab',
    osm: 'OpenStreetMap',
    bra: 'BRA',
    kronofogden: 'Kronofogden',
    kolada: 'Kolada',
};

/** Swedish ordinal: 1:a, 2:a, then :e for everything else */
function ordinal(n: number): string {
    if (n === 1 || n === 2) return `${n}:a`;
    return `${n}:e`;
}

function formatIndicatorValue(value: number, unit: string | null): string {
    switch (unit) {
        case 'SEK':
            return `${Math.round(value).toLocaleString('sv-SE')} kr`;
        case 'percent':
        case '%':
            return `${value.toFixed(1)}%`;
        case 'per_1000':
        case '/1000':
            return `${value.toFixed(1)}/1000`;
        case 'per_100k':
        case '/100k':
            return `${value.toFixed(1)}/100k`;
        case 'points':
            return `${Math.round(value)}`;
        case 'index':
            return value.toFixed(1);
        default:
            return value.toFixed(1);
    }
}

export function DataSummary({
    dataPointCount,
    sourceCount,
}: {
    dataPointCount: number;
    sourceCount: number;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex items-center gap-2 py-2 text-sm text-muted-foreground">
            <Database className="h-3.5 w-3.5" />
            <span>
                <strong className="text-foreground">{dataPointCount}</strong>{' '}
                {t('sidebar.preview.data_points')}
                {' \u00b7 '}
                <strong className="text-foreground">{sourceCount}</strong>{' '}
                {t('sidebar.preview.sources')}
            </span>
        </div>
    );
}

export function SourceBadges({ sources }: { sources: string[] }) {
    return (
        <div className="mb-3 flex flex-wrap gap-1.5">
            {sources.map((source) => (
                <span
                    key={source}
                    className="rounded bg-muted px-1.5 py-0.5 text-[10px] text-muted-foreground"
                >
                    {SOURCE_LABELS[source] ?? source}
                </span>
            ))}
        </div>
    );
}

function FreeIndicatorRow({
    indicator,
}: {
    indicator: PreviewFreeIndicator;
}) {
    const percentile = indicator.percentile ?? 0;

    const isGood =
        indicator.direction === 'positive'
            ? percentile >= 50
            : percentile < 50;

    const barColor = isGood ? 'bg-emerald-500' : 'bg-amber-500';
    const textColor = isGood ? 'text-emerald-700' : 'text-amber-700';

    return (
        <div
            className="flex items-center gap-3 py-1.5"
            title={`${indicator.name}: ${formatIndicatorValue(indicator.raw_value, indicator.unit)}`}
        >
            <span className="min-w-0 flex-1 truncate text-sm">
                {indicator.name}
            </span>
            <div className="h-2 w-20 shrink-0 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full ${barColor}`}
                    style={{ width: `${percentile}%` }}
                />
            </div>
            <span
                className={`w-10 shrink-0 text-right text-xs font-medium ${textColor}`}
            >
                {indicator.percentile !== null
                    ? ordinal(indicator.percentile)
                    : '\u2014'}
            </span>
        </div>
    );
}

function LockedIndicatorRows({ count }: { count: number }) {
    const barWidths = useMemo(() => {
        return Array.from({ length: count }, (_, i) => {
            const base = ((i * 37 + 13) % 35) + 55;
            return `${base}%`;
        });
    }, [count]);

    return (
        <div className="mt-2 space-y-2 opacity-50">
            {barWidths.map((_, i) => (
                <div key={i} className="flex items-center gap-3 py-1">
                    <div
                        className="h-3 flex-1 rounded bg-muted"
                        style={{ maxWidth: '45%' }}
                    />
                    <div className="h-2 w-20 shrink-0 rounded-full bg-muted" />
                    <div className="h-3 w-10 shrink-0 rounded bg-muted" />
                </div>
            ))}
        </div>
    );
}

function LockedCountLabel({ count }: { count: number }) {
    return (
        <p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
            <Lock className="h-3 w-3" />+ {count} indikatorer i rapporten
        </p>
    );
}

function CategorySection({ category }: { category: PreviewCategory }) {
    return (
        <div className="pt-5 first:pt-0">
            {/* Category header */}
            <div className="mb-3 flex items-center gap-2">
                <span className="text-base">{category.emoji}</span>
                <h3 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                    {category.label}
                </h3>
            </div>

            {/* Free preview indicators with real values */}
            {category.free_indicators.length > 0 ? (
                category.free_indicators.map((indicator) => (
                    <FreeIndicatorRow
                        key={indicator.slug}
                        indicator={indicator}
                    />
                ))
            ) : (
                <LockedIndicatorRows
                    count={Math.min(category.indicator_count, 3)}
                />
            )}

            {/* Locked indicators (gray bars matching layout) */}
            {category.free_indicators.length > 0 &&
                category.locked_count > 0 && (
                    <>
                        <LockedIndicatorRows
                            count={Math.min(category.locked_count, 3)}
                        />
                        <LockedCountLabel count={category.locked_count} />
                    </>
                )}

            {/* Data scale stat line */}
            <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
                {category.stat_line}
            </p>
        </div>
    );
}

function LockedSchoolCard() {
    return (
        <div className="rounded-lg border p-3 opacity-60">
            <div className="mb-2 flex items-center gap-2">
                <GraduationCap className="h-4 w-4 text-muted-foreground" />
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
    lat,
    lng,
}: {
    summary: PreviewCtaSummary;
    lat: number;
    lng: number;
}) {
    const { t } = useTranslation();

    return (
        <div className="mt-6 border-t pt-5">
            <div className="rounded-xl bg-gradient-to-br from-primary/5 to-primary/10 p-5 text-center">
                <Lock className="mx-auto mb-3 h-5 w-5 text-primary" />
                <h3 className="mb-3 text-base font-semibold">
                    {t('sidebar.preview.unlock_title')}
                </h3>

                <div className="mb-4 space-y-1.5 text-sm text-muted-foreground">
                    <p>
                        <strong className="text-foreground">
                            {summary.indicator_count}
                        </strong>{' '}
                        {t('sidebar.preview.cta_indicators')}
                    </p>
                    <p>
                        <strong className="text-foreground">
                            {summary.insight_count}
                        </strong>{' '}
                        {t('sidebar.preview.cta_insights')}
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
}: {
    preview: PreviewData;
    lat: number;
    lng: number;
    ctaRef: React.RefObject<HTMLDivElement | null>;
}) {
    const { t } = useTranslation();

    return (
        <>
            {/* Data summary */}
            <DataSummary
                dataPointCount={preview.data_point_count}
                sourceCount={preview.source_count}
            />

            {/* Source badges */}
            {preview.sources.length > 0 && (
                <SourceBadges sources={preview.sources} />
            )}

            {/* First CTA */}
            <div ref={ctaRef} className="mt-4">
                <a href={`/purchase/${lat},${lng}`}>
                    <Button className="w-full" size="lg">
                        {t('sidebar.preview.unlock_button')}
                    </Button>
                </a>
                <p className="mt-1.5 text-center text-xs text-muted-foreground">
                    {t('sidebar.preview.one_time')}
                </p>
            </div>

            {/* Category sections with free + locked indicators */}
            <div className="mt-6 divide-y divide-border">
                {preview.categories
                    .filter((cat) => cat.has_data)
                    .map((category) => (
                        <CategorySection
                            key={category.slug}
                            category={category}
                        />
                    ))}
            </div>

            {/* Schools teaser */}
            {preview.nearby_school_count > 0 && (
                <div className="mt-5 border-t pt-5">
                    <div className="mb-3 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <GraduationCap className="h-4 w-4 text-muted-foreground" />
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
                lat={lat}
                lng={lng}
            />
        </>
    );
}
