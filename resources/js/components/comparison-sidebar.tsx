import { ArrowLeftRight, ArrowLeft, Copy, Lock, X } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import { interpolateScoreColor } from '@/components/deso-map';

export interface CompareLocation {
    lat: number;
    lng: number;
    deso_code: string | null;
    deso_name: string | null;
    kommun_name: string | null;
    lan_name: string | null;
    urbanity_tier: string | null;
    label: string;
    composite_score: number | null;
    score_label: string | null;
    indicators: Record<string, CompareIndicator>;
}

export interface CompareIndicator {
    name: string;
    raw_value: number | null;
    normalized: number;
    percentile: number;
    unit: string | null;
    direction: 'positive' | 'negative' | 'neutral';
    weight: number;
    normalization_scope: string;
}

export interface CompareResult {
    location_a: CompareLocation;
    location_b: CompareLocation;
    distance_km: number;
    comparison: {
        score_difference: number | null;
        a_stronger: string[];
        b_stronger: string[];
        similar: string[];
    };
}

interface ComparisonSidebarProps {
    result: CompareResult;
    pinALabel?: string;
    pinBLabel?: string;
    onClose: () => void;
    onSwapPins: () => void;
}

function scoreColorStyle(score: number): React.CSSProperties {
    return { color: interpolateScoreColor(score) };
}

function scoreBgStyle(score: number): React.CSSProperties {
    return { backgroundColor: interpolateScoreColor(score) };
}

function formatValue(value: number | null, unit: string | null): string {
    if (value === null) return '-';
    if (unit === '%') return `${value.toFixed(1)}%`;
    if (unit === 'SEK') return `${Math.round(value).toLocaleString()} SEK`;
    if (unit === '/100k') return `${value.toFixed(1)}/100k`;
    if (unit === '/1000') return `${value.toFixed(2)}/1000`;
    return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
}

function useScoreLabel(): (score: number) => string {
    const { t } = useTranslation();
    return (score: number) => {
        if (score >= 80) return t('sidebar.score.strong_growth');
        if (score >= 60) return t('sidebar.score.positive');
        if (score >= 40) return t('sidebar.score.mixed');
        if (score >= 20) return t('sidebar.score.challenging');
        return t('sidebar.score.high_risk');
    };
}

function IndicatorCompareBar({
    slug,
    a,
    b,
}: {
    slug: string;
    a: CompareIndicator | undefined;
    b: CompareIndicator | undefined;
}) {
    const { t } = useTranslation();
    const indicatorLabel = t(`sidebar.indicators.labels.${slug}`, { defaultValue: slug });

    if (!a && !b) return null;

    const aPctl = a ? (a.direction === 'negative' ? 100 - a.percentile : a.percentile) : null;
    const bPctl = b ? (b.direction === 'negative' ? 100 - b.percentile : b.percentile) : null;

    const diff = aPctl !== null && bPctl !== null ? aPctl - bPctl : null;
    const isSimilar = diff !== null && Math.abs(diff) <= 5;

    let comparisonText = '';
    if (diff !== null) {
        if (isSimilar) {
            comparisonText = t('compare.similar_label');
        } else if (diff > 0) {
            comparisonText = t('compare.a_higher', { pct: Math.abs(diff) });
        } else {
            comparisonText = t('compare.b_higher', { pct: Math.abs(diff) });
        }
    }

    return (
        <div className="space-y-1">
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {indicatorLabel}
            </div>
            {/* Bar A */}
            <div className="flex items-center gap-2">
                <span className="w-4 text-[10px] font-bold text-blue-500">A</span>
                <div className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                    {aPctl !== null && (
                        <div
                            className="h-full rounded-full transition-all"
                            style={{
                                width: `${aPctl}%`,
                                backgroundColor: isSimilar ? 'rgba(100,116,139,0.5)' : (diff !== null && diff > 0 ? '#3b82f6' : 'rgba(100,116,139,0.4)'),
                            }}
                        />
                    )}
                </div>
                <span className="w-16 text-right text-[11px] tabular-nums font-medium">
                    {aPctl !== null ? `${aPctl}th` : '-'}
                </span>
                <span className="w-20 truncate text-right text-[10px] text-muted-foreground">
                    {a ? formatValue(a.raw_value, a.unit) : '-'}
                </span>
            </div>
            {/* Bar B */}
            <div className="flex items-center gap-2">
                <span className="w-4 text-[10px] font-bold text-amber-500">B</span>
                <div className="h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-muted">
                    {bPctl !== null && (
                        <div
                            className="h-full rounded-full transition-all"
                            style={{
                                width: `${bPctl}%`,
                                backgroundColor: isSimilar ? 'rgba(100,116,139,0.5)' : (diff !== null && diff < 0 ? '#f59e0b' : 'rgba(100,116,139,0.4)'),
                            }}
                        />
                    )}
                </div>
                <span className="w-16 text-right text-[11px] tabular-nums font-medium">
                    {bPctl !== null ? `${bPctl}th` : '-'}
                </span>
                <span className="w-20 truncate text-right text-[10px] text-muted-foreground">
                    {b ? formatValue(b.raw_value, b.unit) : '-'}
                </span>
            </div>
            {comparisonText && (
                <div className="text-[11px] text-muted-foreground">{comparisonText}</div>
            )}
        </div>
    );
}

export default function ComparisonSidebar({
    result,
    pinALabel,
    pinBLabel,
    onClose,
    onSwapPins,
}: ComparisonSidebarProps) {
    const { t } = useTranslation();
    const scoreLabel = useScoreLabel();
    const { location_a: a, location_b: b, distance_km, comparison } = result;

    const handleShare = () => {
        const url = new URL(window.location.href);
        url.search = '';
        url.searchParams.set('compare', `${a.lat},${a.lng}|${b.lat},${b.lng}`);
        navigator.clipboard.writeText(url.toString()).then(() => {
            toast.success(t('compare.share_copied'));
        });
    };

    const handlePdfClick = () => {
        toast.info(t('compare.pdf_locked'));
    };

    // Get sorted indicator slugs by weight
    const allSlugs = Array.from(
        new Set([...Object.keys(a.indicators), ...Object.keys(b.indicators)]),
    ).sort((x, y) => {
        const wX = a.indicators[x]?.weight ?? b.indicators[x]?.weight ?? 0;
        const wY = a.indicators[y]?.weight ?? b.indicators[y]?.weight ?? 0;
        return wY - wX;
    });

    return (
        <ScrollArea className="h-full">
            <div className="space-y-5 p-5">
                {/* Header */}
                <div>
                    <div className="flex items-center justify-between">
                        <button
                            onClick={onClose}
                            className="flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            {t('compare.back_to_map')}
                        </button>
                        <div className="flex gap-1">
                            <button
                                onClick={onSwapPins}
                                className="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                title={t('compare.swap')}
                            >
                                <ArrowLeftRight className="h-3.5 w-3.5" />
                            </button>
                            <button
                                onClick={onClose}
                                className="rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                title={t('compare.clear')}
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>

                    {/* Location labels */}
                    <div className="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <div className="flex items-center gap-1.5">
                                <div className="h-2.5 w-2.5 rounded-full bg-blue-500" />
                                <span className="truncate text-sm font-semibold text-foreground">
                                    {pinALabel || a.label}
                                </span>
                            </div>
                            <div className="ml-4 text-[11px] text-muted-foreground">
                                {a.kommun_name ?? ''}{a.lan_name ? ` \u00b7 ${a.lan_name}` : ''}
                            </div>
                        </div>
                        <div>
                            <div className="flex items-center gap-1.5">
                                <div className="h-2.5 w-2.5 rounded-full bg-amber-500" />
                                <span className="truncate text-sm font-semibold text-foreground">
                                    {pinBLabel || b.label}
                                </span>
                            </div>
                            <div className="ml-4 text-[11px] text-muted-foreground">
                                {b.kommun_name ?? ''}{b.lan_name ? ` \u00b7 ${b.lan_name}` : ''}
                            </div>
                        </div>
                    </div>

                    {/* Distance */}
                    <div className="mt-2 flex items-center justify-center gap-2 text-[11px] text-muted-foreground">
                        <div className="h-px flex-1 bg-border" />
                        <span>{distance_km.toFixed(1)} km</span>
                        <div className="h-px flex-1 bg-border" />
                    </div>
                </div>

                <Separator />

                {/* Composite scores */}
                <div>
                    <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {t('compare.composite_score')}
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="text-center">
                            {a.composite_score !== null ? (
                                <>
                                    <div
                                        className="text-3xl font-bold tabular-nums"
                                        style={scoreColorStyle(a.composite_score)}
                                    >
                                        {a.composite_score.toFixed(0)}
                                    </div>
                                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full"
                                            style={{ width: `${a.composite_score}%`, ...scoreBgStyle(a.composite_score) }}
                                        />
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {scoreLabel(a.composite_score)}
                                    </div>
                                </>
                            ) : (
                                <div className="text-sm text-muted-foreground">{t('compare.no_data')}</div>
                            )}
                        </div>
                        <div className="text-center">
                            {b.composite_score !== null ? (
                                <>
                                    <div
                                        className="text-3xl font-bold tabular-nums"
                                        style={scoreColorStyle(b.composite_score)}
                                    >
                                        {b.composite_score.toFixed(0)}
                                    </div>
                                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full"
                                            style={{ width: `${b.composite_score}%`, ...scoreBgStyle(b.composite_score) }}
                                        />
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {scoreLabel(b.composite_score)}
                                    </div>
                                </>
                            ) : (
                                <div className="text-sm text-muted-foreground">{t('compare.no_data')}</div>
                            )}
                        </div>
                    </div>
                    {comparison.score_difference !== null && (
                        <div className="mt-2 text-center text-xs text-muted-foreground">
                            {comparison.score_difference > 0
                                ? `A +${comparison.score_difference.toFixed(0)} points`
                                : comparison.score_difference < 0
                                    ? `B +${Math.abs(comparison.score_difference).toFixed(0)} points`
                                    : 'Equal score'}
                        </div>
                    )}
                </div>

                <Separator />

                {/* Indicator Breakdown */}
                <div>
                    <div className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {t('compare.indicator_breakdown')}
                    </div>
                    <div className="space-y-3">
                        {allSlugs.map((slug) => (
                            <IndicatorCompareBar
                                key={slug}
                                slug={slug}
                                a={a.indicators[slug]}
                                b={b.indicators[slug]}
                            />
                        ))}
                    </div>
                </div>

                <Separator />

                {/* Verdict */}
                <div className="space-y-3">
                    <div className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        {t('compare.verdict')}
                    </div>

                    {comparison.a_stronger.length > 0 && (
                        <div>
                            <div className="mb-1 text-xs font-medium text-blue-600">
                                {t('compare.a_stronger')}:
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {comparison.a_stronger.map((slug) => (
                                    <Badge
                                        key={slug}
                                        className="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700"
                                        variant="secondary"
                                    >
                                        {t(`sidebar.indicators.labels.${slug}`, { defaultValue: slug })}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}

                    {comparison.b_stronger.length > 0 && (
                        <div>
                            <div className="mb-1 text-xs font-medium text-amber-600">
                                {t('compare.b_stronger')}:
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {comparison.b_stronger.map((slug) => (
                                    <Badge
                                        key={slug}
                                        className="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700"
                                        variant="secondary"
                                    >
                                        {t(`sidebar.indicators.labels.${slug}`, { defaultValue: slug })}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}

                    {comparison.similar.length > 0 && (
                        <div>
                            <div className="mb-1 text-xs font-medium text-muted-foreground">
                                {t('compare.similar_in')}:
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {comparison.similar.map((slug) => (
                                    <Badge
                                        key={slug}
                                        className="rounded-full border border-border bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground"
                                        variant="secondary"
                                    >
                                        {t(`sidebar.indicators.labels.${slug}`, { defaultValue: slug })}
                                    </Badge>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                <Separator />

                {/* Actions */}
                <div className="flex gap-2">
                    <button
                        onClick={handleShare}
                        className="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                    >
                        <Copy className="h-3.5 w-3.5" />
                        {t('compare.share')}
                    </button>
                    <button
                        onClick={handlePdfClick}
                        className="flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted"
                    >
                        <Lock className="h-3.5 w-3.5" />
                        {t('compare.save_pdf')}
                    </button>
                </div>
            </div>
        </ScrollArea>
    );
}
