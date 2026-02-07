import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowLeftRight,
    ArrowRight,
    ArrowUp,
    Crosshair,
    Landmark,
    Loader2,
    MapPin,
    Minus,
    Shield,
    ShieldAlert,
    TriangleAlert,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import ComparisonSidebar, { type CompareResult } from '@/components/comparison-sidebar';
import DesoMap, {
    type DesoMapHandle,
    type DesoProperties,
    type DesoScore,
    interpolateScoreColor,
} from '@/components/deso-map';
import {
    type IndicatorMeta,
    InfoTooltip,
    NoDataTooltip,
    SchoolStatTooltip,
    ScoreTooltip,
} from '@/components/info-tooltip';
import MapSearch from '@/components/map-search';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';
import MapLayout from '@/layouts/map-layout';
import {
    type SearchResult,
    getZoomForType,
    shouldAutoSelectDeso,
} from '@/services/geocoding';

interface MapPageProps {
    initialCenter: [number, number];
    initialZoom: number;
    indicatorScopes: Record<string, 'national' | 'urbanity_stratified'>;
    indicatorMeta: Record<string, IndicatorMeta>;
}

export interface School {
    school_unit_code: string;
    name: string;
    type: string | null;
    school_forms: string[];
    operator_type: string | null;
    lat: number | null;
    lng: number | null;
    merit_value: number | null;
    goal_achievement: number | null;
    teacher_certification: number | null;
    student_count: number | null;
}

type SchoolFilter = 'all' | 'grundskola' | 'gymnasie' | 'other';

function getSchoolFormCategory(forms: string[]): 'grundskola' | 'gymnasie' | 'other' {
    if (forms.some((f) => f === 'Grundskola')) return 'grundskola';
    if (forms.some((f) => f === 'Gymnasieskola')) return 'gymnasie';
    return 'other';
}

function schoolFormBadgeColor(forms: string[]): string {
    const cat = getSchoolFormCategory(forms);
    if (cat === 'grundskola') return 'bg-primary/10 text-primary border-primary/20';
    if (cat === 'gymnasie') return 'bg-blue-500/10 text-blue-600 border-blue-500/20';
    return 'bg-muted text-muted-foreground border-border';
}

function schoolFormLabel(forms: string[]): string {
    const priority = ['Grundskola', 'Gymnasieskola', 'Anpassad grundskola', 'Anpassad gymnasieskola', 'Specialskola', 'Sameskola', 'Förskoleklass', 'Komvux'];
    for (const p of priority) {
        if (forms.includes(p)) return p;
    }
    return forms[0] ?? '';
}

interface CrimeData {
    deso_code: string;
    kommun_code: string;
    kommun_name: string;
    year: number;
    estimated_rates: {
        violent: { rate: number | null; percentile: number | null };
        property: { rate: number | null; percentile: number | null };
        total: { rate: number | null; percentile: number | null };
    };
    perceived_safety: {
        percent_safe: number | null;
        percentile: number | null;
    };
    kommun_actual_rates: {
        total: number | null;
        person: number | null;
        theft: number | null;
    };
    vulnerability: {
        name: string;
        tier: string;
        tier_label: string;
        overlap_fraction: number;
        assessment_year: number;
        police_region: string;
    } | null;
}

interface FinancialData {
    deso_code: string;
    year: number | null;
    estimated_debt_rate: number | null;
    estimated_eviction_rate: number | null;
    kommun_actual_rate: number | null;
    kommun_name: string | null;
    kommun_median_debt: number | null;
    kommun_eviction_rate: number | null;
    national_avg_rate: number | null;
    is_high_distress: boolean;
    is_estimated: boolean;
}

interface IndicatorTrendData {
    direction: 'rising' | 'falling' | 'stable' | 'insufficient';
    percent_change: number | null;
    absolute_change: number | null;
    base_year: number;
    end_year: number;
    data_points: number;
    confidence: number | null;
}

interface IndicatorDataItem {
    slug: string;
    name: string;
    raw_value: number | null;
    normalized_value: number | null;
    unit: string | null;
    direction: 'positive' | 'negative' | 'neutral';
    normalization_scope: 'national' | 'urbanity_stratified';
    trend: IndicatorTrendData | null;
    history: Array<{ year: number; value: number | null }>;
}

interface IndicatorResponse {
    deso_code: string;
    year: number;
    indicators: IndicatorDataItem[];
    trend_eligible: boolean;
    trend_meta: {
        eligible: boolean;
        reason: string | null;
        indicators_with_trends: number;
        indicators_total: number;
        period: string | null;
    };
}

// Sweden bounding box for geolocation check
const SWEDEN_BOUNDS = {
    minLat: 55.0,
    maxLat: 69.1,
    minLng: 10.5,
    maxLng: 24.2,
};

function isInSweden(lat: number, lng: number): boolean {
    return (
        lat >= SWEDEN_BOUNDS.minLat &&
        lat <= SWEDEN_BOUNDS.maxLat &&
        lng >= SWEDEN_BOUNDS.minLng &&
        lng <= SWEDEN_BOUNDS.maxLng
    );
}

function getZoomForAccuracy(accuracy: number): number {
    if (accuracy < 50) return 15;
    if (accuracy <= 200) return 14;
    if (accuracy <= 500) return 13;
    if (accuracy <= 2000) return 12;
    return 11;
}

interface ComparePin {
    lat: number;
    lng: number;
    label: string;
}

interface CompareState {
    active: boolean;
    pinA: ComparePin | null;
    pinB: ComparePin | null;
    result: CompareResult | null;
    loading: boolean;
    error: boolean;
}

/** Returns an inline color style matching the score gradient (same as the map). */
function scoreColorStyle(score: number): React.CSSProperties {
    return { color: interpolateScoreColor(score) };
}

/** Returns an inline background color style matching the score gradient. */
function scoreBgStyle(score: number): React.CSSProperties {
    return { backgroundColor: interpolateScoreColor(score) };
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

function IndicatorTrendArrow({
    trend,
    indicatorDirection,
    showLabel,
}: {
    trend: IndicatorTrendData | null;
    indicatorDirection: 'positive' | 'negative' | 'neutral';
    showLabel?: boolean;
}) {
    const { t } = useTranslation();
    const minConfidence = 0.5;

    if (!trend || trend.direction === 'insufficient' || (trend.confidence !== null && trend.confidence < minConfidence)) {
        if (showLabel) {
            return (
                <span className="flex items-center gap-0.5 text-[11px] text-muted-foreground">
                    <Minus className="h-3 w-3" />
                    <span className="italic">{t('sidebar.indicators.no_trend_data')}</span>
                </span>
            );
        }
        return null;
    }

    // Determine if improving or worsening based on indicator direction
    const isImproving =
        (indicatorDirection === 'positive' && trend.direction === 'rising') ||
        (indicatorDirection === 'negative' && trend.direction === 'falling');
    const isWorsening =
        (indicatorDirection === 'positive' && trend.direction === 'falling') ||
        (indicatorDirection === 'negative' && trend.direction === 'rising');

    const Icon = trend.direction === 'stable' ? ArrowRight : isImproving ? ArrowUp : ArrowDown;
    const colorClass = trend.direction === 'stable'
        ? 'text-muted-foreground'
        : isImproving
            ? 'text-trend-positive'
            : 'text-trend-negative';

    const pctStr = trend.percent_change !== null
        ? `${trend.percent_change > 0 ? '+' : ''}${trend.percent_change.toFixed(1)}%`
        : '';

    const opacity = trend.confidence !== null && trend.confidence < 1 ? 'opacity-70' : '';

    return (
        <span className={`flex items-center gap-0.5 tabular-nums text-[11px] ${colorClass} ${opacity}`}>
            <Icon className="h-3 w-3" />
            {pctStr && <span>{pctStr}</span>}
        </span>
    );
}

function TrendDetailTooltip({
    indicator,
}: {
    indicator: IndicatorDataItem;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    if (!indicator.trend && indicator.history.length < 2) return null;

    const trend = indicator.trend;
    const expectedYears = trend ? trend.end_year - trend.base_year + 1 : 3;

    const confidenceLabel = !trend?.confidence
        ? ''
        : trend.confidence >= 1
            ? t('sidebar.trend_tooltip.confidence_high', { points: trend.data_points, total: expectedYears })
            : trend.confidence >= 0.5
                ? t('sidebar.trend_tooltip.confidence_medium', { points: trend.data_points, total: expectedYears })
                : t('sidebar.trend_tooltip.confidence_low', { points: trend.data_points, total: expectedYears });

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip open={open} onOpenChange={setOpen}>
                <TooltipTrigger asChild>
                    <button
                        className="ml-0.5 inline-flex items-center text-muted-foreground transition-colors hover:text-foreground"
                        onClick={() => setOpen(!open)}
                    >
                        <IndicatorTrendArrow
                            trend={indicator.trend}
                            indicatorDirection={indicator.direction}
                            showLabel={true}
                        />
                    </button>
                </TooltipTrigger>
                <TooltipContent
                    className="max-w-72 text-sm"
                    side="left"
                    align="start"
                    collisionPadding={16}
                >
                    <div className="space-y-2">
                        <p className="font-medium">
                            {t('sidebar.trend_tooltip.title', { name: indicator.name })}
                        </p>
                        <div className="space-y-0.5 text-xs">
                            {indicator.history.map((h) => (
                                <div key={h.year} className="flex justify-between gap-4">
                                    <span className="text-muted-foreground">{h.year}:</span>
                                    <span className="tabular-nums font-medium">
                                        {h.value !== null
                                            ? formatIndicatorValue(h.value, indicator.unit)
                                            : t('sidebar.trend_tooltip.no_data_year', { defaultValue: 'No data' })}
                                    </span>
                                </div>
                            ))}
                        </div>
                        {trend && trend.absolute_change !== null && trend.percent_change !== null && (
                            <p className="text-xs text-muted-foreground">
                                {t('sidebar.trend_tooltip.change', {
                                    change: formatIndicatorValue(trend.absolute_change, indicator.unit),
                                    percent: `${trend.percent_change > 0 ? '+' : ''}${trend.percent_change.toFixed(1)}`,
                                })}
                            </p>
                        )}
                        {confidenceLabel && (
                            <p className="text-xs text-muted-foreground">{confidenceLabel}</p>
                        )}
                    </div>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

function formatIndicatorValue(value: number, unit: string | null): string {
    if (unit === '%') return `${value.toFixed(1)}%`;
    if (unit === 'SEK') return `${Math.round(value).toLocaleString()} SEK`;
    if (unit === '/100k') return `${value.toFixed(1)}/100k`;
    if (unit === '/1000') return `${value.toFixed(2)}/1000`;
    return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
}

function FactorBar({
    label,
    value,
    scope,
    urbanityTier,
    meta,
    trend,
    indicatorDirection,
    showTrend,
}: {
    label: string;
    value: number;
    scope?: 'national' | 'urbanity_stratified';
    urbanityTier?: string | null;
    meta?: IndicatorMeta;
    trend?: IndicatorTrendData | null;
    indicatorDirection?: 'positive' | 'negative' | 'neutral';
    showTrend?: boolean;
}) {
    const { t } = useTranslation();
    const rawPct = Math.round(value * 100);
    // For negative-direction indicators, flip so the bar reflects "how favorable"
    // e.g. 98th pctl crime → 2nd effective pctl (bad), shown with red/short bar
    const effectivePct = indicatorDirection === 'negative' ? 100 - rawPct : rawPct;
    const isStratified = scope === 'urbanity_stratified' && urbanityTier;
    const tierLabel = urbanityTier
        ? t(`sidebar.urbanity.${urbanityTier}`, { defaultValue: urbanityTier })
        : '';

    return (
        <div className="space-y-0.5">
            <div className="flex items-center justify-between text-xs">
                <span className="flex items-center font-medium uppercase tracking-wide text-muted-foreground">
                    {label}
                    {meta && <InfoTooltip indicator={meta} />}
                </span>
                <span className="flex items-center gap-1.5">
                    {showTrend && trend && indicatorDirection && (
                        <IndicatorTrendArrow
                            trend={trend}
                            indicatorDirection={indicatorDirection}
                        />
                    )}
                    <span className="tabular-nums font-semibold text-foreground">
                        {isStratified
                            ? t('sidebar.indicators.percentile_stratified', { value: effectivePct, tier: tierLabel })
                            : t('sidebar.indicators.percentile_national', { value: effectivePct })}
                    </span>
                </span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full transition-all"
                    style={{ width: `${effectivePct}%`, ...scoreBgStyle(effectivePct) }}
                />
            </div>
        </div>
    );
}

function DataFreshness({ meta }: { meta: Record<string, IndicatorMeta> }) {
    const sources = new Map<string, { name: string; vintage: string | null }>();
    for (const ind of Object.values(meta)) {
        if (ind.source_name && !sources.has(ind.source_name)) {
            sources.set(ind.source_name, {
                name: ind.source_name,
                vintage: ind.data_vintage,
            });
        }
    }

    if (sources.size === 0) return null;

    return (
        <div className="space-y-1 text-[11px] text-muted-foreground">
            <div className="text-xs font-medium text-foreground">Data sources</div>
            {Array.from(sources.values()).map((src) => (
                <div key={src.name} className="flex justify-between">
                    <span>{src.name}</span>
                    {src.vintage && <span className="tabular-nums">Data: {src.vintage}</span>}
                </div>
            ))}
        </div>
    );
}

function meritColor(merit: number | null): string {
    if (merit === null) return 'rgba(148, 163, 184, 0.6)'; // muted
    if (merit > 230) return interpolateScoreColor(80);
    if (merit >= 200) return interpolateScoreColor(55);
    return interpolateScoreColor(30);
}

function SchoolCard({
    school,
    highlighted,
    onRef,
}: {
    school: School;
    highlighted: boolean;
    onRef: (el: HTMLDivElement | null) => void;
}) {
    const { t } = useTranslation();

    return (
        <div
            ref={onRef}
            className={`rounded-lg border p-3 transition-colors ${highlighted ? 'border-primary/50 bg-primary/5' : 'border-border bg-background'}`}
        >
            <div className="mb-1 flex items-start justify-between">
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold text-foreground">{school.name}</div>
                    <div className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                        {school.school_forms?.length > 0 && (
                            <Badge variant="outline" className={`px-1.5 py-0 text-[10px] ${schoolFormBadgeColor(school.school_forms)}`}>
                                {schoolFormLabel(school.school_forms)}
                            </Badge>
                        )}
                        {school.operator_type && (
                            <Badge variant="outline" className="px-1 py-0 text-[10px]">
                                {school.operator_type}
                            </Badge>
                        )}
                    </div>
                </div>
            </div>
            <div className="mt-2 space-y-1.5">
                {school.merit_value !== null && (
                    <div className="space-y-0.5">
                        <div className="flex justify-between text-xs">
                            <span className="flex items-center text-muted-foreground">
                                {t('sidebar.schools.merit_value')}
                                <SchoolStatTooltip stat="merit_value" />
                            </span>
                            <span className="tabular-nums font-medium">{school.merit_value.toFixed(0)}</span>
                        </div>
                        <div className="h-1 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full"
                                style={{
                                    width: `${Math.min(100, (school.merit_value / 340) * 100)}%`,
                                    backgroundColor: meritColor(school.merit_value),
                                }}
                            />
                        </div>
                    </div>
                )}
                {school.goal_achievement !== null && (
                    <div className="space-y-0.5">
                        <div className="flex justify-between text-xs">
                            <span className="flex items-center text-muted-foreground">
                                {t('sidebar.schools.goal_achievement')}
                                <SchoolStatTooltip stat="goal_achievement" />
                            </span>
                            <span className="tabular-nums font-medium">{school.goal_achievement.toFixed(0)}%</span>
                        </div>
                        <div className="h-1 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-primary"
                                style={{ width: `${school.goal_achievement}%` }}
                            />
                        </div>
                    </div>
                )}
                {school.teacher_certification !== null && (
                    <div className="space-y-0.5">
                        <div className="flex justify-between text-xs">
                            <span className="flex items-center text-muted-foreground">
                                {t('sidebar.schools.teachers')}
                                <SchoolStatTooltip stat="teacher_certification" />
                            </span>
                            <span className="tabular-nums font-medium">{school.teacher_certification.toFixed(0)}%</span>
                        </div>
                        <div className="h-1 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-primary/70"
                                style={{ width: `${school.teacher_certification}%` }}
                            />
                        </div>
                    </div>
                )}
                {school.student_count !== null && (
                    <div className="mt-1 text-xs text-muted-foreground">
                        {t('sidebar.schools.students_count', { count: school.student_count })}
                    </div>
                )}
            </div>
        </div>
    );
}

function VulnerabilityCard({ vulnerability }: { vulnerability: CrimeData['vulnerability'] }) {
    const { t } = useTranslation();

    if (!vulnerability) return null;

    const isSarskilt = vulnerability.tier === 'sarskilt_utsatt';
    const Icon = isSarskilt ? ShieldAlert : AlertTriangle;

    return (
        <div
            className={`rounded-lg border-2 p-3 ${
                isSarskilt
                    ? 'border-red-300 bg-red-50'
                    : 'border-amber-300 bg-amber-50'
            }`}
        >
            <div className="flex items-start gap-2">
                <Icon
                    className={`mt-0.5 h-5 w-5 shrink-0 ${
                        isSarskilt ? 'text-red-600' : 'text-amber-600'
                    }`}
                />
                <div>
                    <div
                        className={`text-sm font-semibold ${
                            isSarskilt ? 'text-red-800' : 'text-amber-800'
                        }`}
                    >
                        {t('sidebar.vulnerability.police_label', { label: vulnerability.tier_label })}
                    </div>
                    <div
                        className={`mt-1 text-xs ${
                            isSarskilt ? 'text-red-700' : 'text-amber-700'
                        }`}
                    >
                        {t('sidebar.vulnerability.overlap_description', { name: vulnerability.name })}{' '}
                        <span className="font-semibold uppercase">
                            {isSarskilt ? t('sidebar.vulnerability.sarskilt_utsatt') : t('sidebar.vulnerability.utsatt')}
                        </span>{' '}
                        ({vulnerability.assessment_year})
                    </div>
                </div>
            </div>
        </div>
    );
}

function CrimeRateBar({
    label,
    rate,
    percentile,
}: {
    label: string;
    rate: number | null;
    percentile: number | null;
}) {
    const { t } = useTranslation();

    if (rate === null || percentile === null) return null;

    const safeness = 100 - percentile;
    return (
        <div className="space-y-0.5">
            <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="tabular-nums font-medium">{t('sidebar.indicators.percentile_national', { value: Math.round(safeness) })}</span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full transition-all"
                    style={{ width: `${safeness}%`, ...scoreBgStyle(safeness) }}
                />
            </div>
            <div className="text-[11px] text-muted-foreground">
                {t('sidebar.crime.estimated_rate', { value: rate.toLocaleString() })}
            </div>
        </div>
    );
}

function CrimeSection({
    crimeData,
    loading,
}: {
    crimeData: CrimeData | null;
    loading: boolean;
}) {
    const { t } = useTranslation();

    if (loading) {
        return (
            <div className="space-y-3">
                <div className="h-16 animate-pulse rounded-lg bg-muted" />
                <div className="h-24 animate-pulse rounded-lg bg-muted" />
            </div>
        );
    }

    if (!crimeData) return null;

    return (
        <div className="space-y-3">
            {crimeData.vulnerability && (
                <VulnerabilityCard vulnerability={crimeData.vulnerability} />
            )}

            <div>
                <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    <Shield className="h-3.5 w-3.5" />
                    {t('sidebar.crime.title')}
                </div>
                <div className="space-y-2.5">
                    <CrimeRateBar
                        label={t('sidebar.crime.violent')}
                        rate={crimeData.estimated_rates.violent.rate}
                        percentile={crimeData.estimated_rates.violent.percentile}
                    />
                    <CrimeRateBar
                        label={t('sidebar.crime.property')}
                        rate={crimeData.estimated_rates.property.rate}
                        percentile={crimeData.estimated_rates.property.percentile}
                    />
                    {crimeData.perceived_safety.percent_safe !== null &&
                        crimeData.perceived_safety.percentile !== null && (
                            <div className="space-y-0.5">
                                <div className="flex justify-between text-xs">
                                    <span className="text-muted-foreground">
                                        {t('sidebar.crime.perceived_safety')}
                                    </span>
                                    <span className="tabular-nums font-medium">
                                        {t('sidebar.indicators.percentile_national', { value: Math.round(crimeData.perceived_safety.percentile) })}
                                    </span>
                                </div>
                                <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full transition-all"
                                        style={{
                                            width: `${crimeData.perceived_safety.percentile}%`,
                                            ...scoreBgStyle(crimeData.perceived_safety.percentile),
                                        }}
                                    />
                                </div>
                                <div className="text-[11px] text-muted-foreground">
                                    {t('sidebar.crime.feel_safe', { value: crimeData.perceived_safety.percent_safe })}
                                </div>
                            </div>
                        )}
                </div>
            </div>

            <div className="rounded border border-dashed border-border px-2.5 py-2 text-[11px] text-muted-foreground">
                {t('sidebar.crime.disclaimer', {
                    kommun: crimeData.kommun_name,
                    total: crimeData.kommun_actual_rates.total?.toLocaleString() ?? '',
                })}
            </div>

            <div className="rounded-lg border border-dashed border-border p-3 text-center">
                <div className="text-xs font-medium text-muted-foreground">
                    {t('sidebar.crime.recent_incidents')}
                </div>
                <div className="mt-0.5 text-[11px] text-muted-foreground">
                    {t('sidebar.crime.recent_incidents_hint')}
                </div>
            </div>
        </div>
    );
}

function FinancialRateBar({
    label,
    value,
    suffix,
    maxValue,
}: {
    label: string;
    value: number | null;
    suffix: string;
    maxValue: number;
}) {
    if (value === null) return null;

    const pct = Math.min(100, (value / maxValue) * 100);
    const score = 100 - pct; // Invert for color (higher rate = worse)
    return (
        <div className="space-y-0.5">
            <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="tabular-nums font-medium">
                    {value.toLocaleString(undefined, { maximumFractionDigits: 1 })}
                    {suffix}
                </span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full transition-all"
                    style={{ width: `${pct}%`, ...scoreBgStyle(score) }}
                />
            </div>
        </div>
    );
}

function FinancialSection({
    data,
    loading,
}: {
    data: FinancialData | null;
    loading: boolean;
}) {
    const { t } = useTranslation();

    if (loading) {
        return (
            <div className="space-y-3">
                <div className="h-16 animate-pulse rounded-lg bg-muted" />
                <div className="h-24 animate-pulse rounded-lg bg-muted" />
            </div>
        );
    }

    if (!data || data.estimated_debt_rate === null) return null;

    return (
        <div className="space-y-3">
            {data.is_high_distress && (
                <div className="rounded-lg border-2 border-orange-300 bg-orange-50 p-3">
                    <div className="flex items-start gap-2">
                        <TriangleAlert className="mt-0.5 h-5 w-5 shrink-0 text-orange-600" />
                        <div>
                            <div className="text-sm font-semibold text-orange-800">
                                {t('sidebar.financial.elevated_distress')}
                            </div>
                            <div className="mt-1 text-xs text-orange-700">
                                {t('sidebar.financial.elevated_distress_desc', {
                                    rate: data.estimated_debt_rate,
                                    avg: data.national_avg_rate,
                                })}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <div>
                <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    <Landmark className="h-3.5 w-3.5" />
                    {t('sidebar.financial.title')}
                </div>
                <div className="space-y-2.5">
                    <FinancialRateBar
                        label={t('sidebar.financial.debt_rate')}
                        value={data.estimated_debt_rate}
                        suffix="%"
                        maxValue={10}
                    />
                    {data.kommun_actual_rate !== null && (
                        <div className="-mt-1.5 text-[11px] text-muted-foreground">
                            {t('sidebar.financial.kommun_avg', { value: data.kommun_actual_rate })}
                        </div>
                    )}
                    <FinancialRateBar
                        label={t('sidebar.financial.evictions')}
                        value={data.estimated_eviction_rate}
                        suffix="/100k"
                        maxValue={80}
                    />
                    {data.kommun_median_debt !== null && (
                        <div className="space-y-0.5">
                            <div className="flex justify-between text-xs">
                                <span className="text-muted-foreground">
                                    {t('sidebar.financial.median_debt')}
                                </span>
                                <span className="tabular-nums font-medium">
                                    {t('sidebar.financial.sek_thousands', {
                                        value: Math.round(data.kommun_median_debt / 1000).toLocaleString(),
                                    })}
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <div className="rounded border border-dashed border-border px-2.5 py-2 text-[11px] text-muted-foreground">
                {data.kommun_actual_rate !== null
                    ? t('sidebar.financial.disclaimer', {
                          kommun: data.kommun_name ?? '',
                          rate: data.kommun_actual_rate,
                      })
                    : t('sidebar.financial.disclaimer_no_rate', {
                          kommun: data.kommun_name ?? '',
                      })}
            </div>
        </div>
    );
}

export default function MapPage({ initialCenter, initialZoom, indicatorScopes, indicatorMeta }: MapPageProps) {
    const { t } = useTranslation();
    const scoreLabel = useScoreLabel();

    const [selectedDeso, setSelectedDeso] = useState<DesoProperties | null>(null);
    const [selectedScore, setSelectedScore] = useState<DesoScore | null>(null);
    const [schools, setSchools] = useState<School[]>([]);
    const [schoolsLoading, setSchoolsLoading] = useState(false);
    const [schoolFilter, setSchoolFilter] = useState<SchoolFilter>('all');
    const [crimeData, setCrimeData] = useState<CrimeData | null>(null);
    const [crimeLoading, setCrimeLoading] = useState(false);
    const [financialData, setFinancialData] = useState<FinancialData | null>(null);
    const [financialLoading, setFinancialLoading] = useState(false);
    const [indicatorData, setIndicatorData] = useState<IndicatorResponse | null>(null);
    const [indicatorLoading, setIndicatorLoading] = useState(false);
    const [highlightedSchool, setHighlightedSchool] = useState<string | null>(null);
    const [searchNotInDeso, setSearchNotInDeso] = useState(false);
    const [userLocation, setUserLocation] = useState<{ lat: number; lng: number } | null>(null);
    const [locating, setLocating] = useState(false);
    const [compareState, setCompareState] = useState<CompareState>({
        active: false,
        pinA: null,
        pinB: null,
        result: null,
        loading: false,
        error: false,
    });
    const schoolRefs = useRef<Record<string, HTMLDivElement | null>>({});
    const mapRef = useRef<DesoMapHandle | null>(null);

    const indicatorLabel = useCallback(
        (slug: string) => t(`sidebar.indicators.labels.${slug}`, { defaultValue: slug }),
        [t],
    );

    const handleFeatureSelect = useCallback(
        (properties: DesoProperties | null, score: DesoScore | null) => {
            setSelectedDeso(properties);
            setSelectedScore(score);
            setHighlightedSchool(null);
            setSearchNotInDeso(false);

            if (properties) {
                setSchoolsLoading(true);
                setCrimeLoading(true);
                setFinancialLoading(true);
                setIndicatorLoading(true);

                fetch(`/api/deso/${properties.deso_code}/indicators?year=2024`)
                    .then((r) => r.json())
                    .then((data: IndicatorResponse) => {
                        setIndicatorData(data);
                        setIndicatorLoading(false);
                    })
                    .catch(() => {
                        setIndicatorData(null);
                        setIndicatorLoading(false);
                    });

                fetch(`/api/deso/${properties.deso_code}/schools`)
                    .then((r) => r.json())
                    .then((data: School[]) => {
                        setSchools(data);
                        setSchoolsLoading(false);
                        mapRef.current?.setSchoolMarkers(data);
                    })
                    .catch(() => {
                        setSchools([]);
                        setSchoolsLoading(false);
                        mapRef.current?.clearSchoolMarkers();
                    });

                fetch(`/api/deso/${properties.deso_code}/crime?year=2024`)
                    .then((r) => r.json())
                    .then((data: CrimeData) => {
                        setCrimeData(data);
                        setCrimeLoading(false);
                    })
                    .catch(() => {
                        setCrimeData(null);
                        setCrimeLoading(false);
                    });

                fetch(`/api/deso/${properties.deso_code}/financial?year=2024`)
                    .then((r) => r.json())
                    .then((data: FinancialData) => {
                        setFinancialData(data);
                        setFinancialLoading(false);
                    })
                    .catch(() => {
                        setFinancialData(null);
                        setFinancialLoading(false);
                    });
            } else {
                setSchools([]);
                setCrimeData(null);
                setFinancialData(null);
                setIndicatorData(null);
                mapRef.current?.clearSchoolMarkers();
            }
        },
        [],
    );

    const handleSchoolClick = useCallback((schoolCode: string) => {
        setHighlightedSchool(schoolCode);
        const el = schoolRefs.current[schoolCode];
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, []);

    const handleSearchResult = useCallback(
        (result: SearchResult) => {
            setSearchNotInDeso(false);

            mapRef.current?.clearSearchMarker();

            if (result.extent) {
                const [west, north, east, south] = result.extent;
                mapRef.current?.zoomToExtent(west, south, east, north);
            } else {
                const zoom = getZoomForType(result.type);
                mapRef.current?.zoomToPoint(result.lat, result.lng, zoom);
            }

            mapRef.current?.placeSearchMarker(result.lat, result.lng);

            if (shouldAutoSelectDeso(result.type)) {
                fetch(
                    `/api/geocode/resolve-deso?lat=${result.lat}&lng=${result.lng}`,
                )
                    .then((r) => r.json())
                    .then((data: { deso: { deso_code: string; deso_name: string; kommun_name: string; lan_name: string } | null }) => {
                        if (data.deso) {
                            mapRef.current?.selectDesoByCode(
                                data.deso.deso_code,
                            );

                            const url = new URL(window.location.href);
                            url.searchParams.set('q', result.name);
                            url.searchParams.set('deso', data.deso.deso_code);
                            window.history.replaceState({}, '', url.toString());
                        } else {
                            setSearchNotInDeso(true);
                            const url = new URL(window.location.href);
                            url.searchParams.set('q', result.name);
                            url.searchParams.delete('deso');
                            window.history.replaceState({}, '', url.toString());
                        }
                    })
                    .catch(() => {
                        // Don't block the UI if resolve fails
                    });
            } else {
                const url = new URL(window.location.href);
                url.searchParams.set('q', result.name);
                url.searchParams.delete('deso');
                window.history.replaceState({}, '', url.toString());
            }
        },
        [],
    );

    const handleSearchClear = useCallback(() => {
        setSearchNotInDeso(false);
        mapRef.current?.clearSearchMarker();
        mapRef.current?.clearSelection();
        mapRef.current?.clearSchoolMarkers();

        const url = new URL(window.location.href);
        url.searchParams.delete('q');
        url.searchParams.delete('deso');
        window.history.replaceState({}, '', url.toString());
    }, []);

    // --- Geolocation ---
    const handleLocateMe = useCallback(() => {
        if (!navigator.geolocation) {
            toast.error(t('geolocation.not_available'));
            return;
        }

        setLocating(true);

        navigator.geolocation.getCurrentPosition(
            (position) => {
                setLocating(false);
                const { latitude, longitude, accuracy } = position.coords;

                if (!isInSweden(latitude, longitude)) {
                    toast.info(t('geolocation.outside_sweden'));
                    return;
                }

                if (accuracy > 2000) {
                    toast.info(t('geolocation.approximate'));
                }

                setUserLocation({ lat: latitude, lng: longitude });

                // In compare mode, use location as pin A
                if (compareState.active) {
                    handleCompareClick(latitude, longitude);
                    return;
                }

                // Clear search marker, place location marker
                mapRef.current?.clearSearchMarker();
                mapRef.current?.placeLocationMarker(latitude, longitude, accuracy);

                const zoom = getZoomForAccuracy(accuracy);
                mapRef.current?.zoomToPoint(latitude, longitude, zoom);

                // Auto-select containing area
                fetch(`/api/geocode/resolve-deso?lat=${latitude}&lng=${longitude}`)
                    .then((r) => r.json())
                    .then((data: { deso: { deso_code: string } | null }) => {
                        if (data.deso) {
                            mapRef.current?.selectDesoByCode(data.deso.deso_code);
                        }
                    })
                    .catch(() => {});
            },
            (error) => {
                setLocating(false);
                if (error.code === error.PERMISSION_DENIED) {
                    toast.error(t('geolocation.denied'));
                } else {
                    toast.error(t('geolocation.error'));
                }
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 60000,
            },
        );
    }, [t, compareState.active]);

    // --- Compare mode ---
    const fetchComparison = useCallback((pinA: ComparePin, pinB: ComparePin) => {
        setCompareState((prev) => ({ ...prev, loading: true, error: false }));

        fetch('/api/compare', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                point_a: { lat: pinA.lat, lng: pinA.lng },
                point_b: { lat: pinB.lat, lng: pinB.lng },
            }),
        })
            .then((r) => {
                if (!r.ok) throw new Error('Compare failed');
                return r.json();
            })
            .then((data: CompareResult) => {
                setCompareState((prev) => ({
                    ...prev,
                    result: data,
                    loading: false,
                }));

                // Update pin labels from response
                setCompareState((prev) => ({
                    ...prev,
                    pinA: prev.pinA ? { ...prev.pinA, label: data.location_a.label } : null,
                    pinB: prev.pinB ? { ...prev.pinB, label: data.location_b.label } : null,
                }));
            })
            .catch(() => {
                setCompareState((prev) => ({ ...prev, loading: false, error: true }));
            });
    }, []);

    const handleCompareClick = useCallback(
        (lat: number, lng: number) => {
            if (!isInSweden(lat, lng)) {
                toast.info(t('geolocation.outside_sweden'));
                return;
            }

            setCompareState((prev) => {
                if (!prev.pinA) {
                    // Place pin A
                    const pinA: ComparePin = { lat, lng, label: 'A' };
                    mapRef.current?.placeComparePin('a', lat, lng);
                    return { ...prev, pinA, pinB: null, result: null };
                }
                // Place or replace pin B
                const pinB: ComparePin = { lat, lng, label: 'B' };
                mapRef.current?.placeComparePin('b', lat, lng);

                // Auto-fit to show both pins
                mapRef.current?.fitToPoints([prev.pinA, pinB]);

                // Fetch comparison
                fetchComparison(prev.pinA, pinB);

                return { ...prev, pinB, result: null };
            });
        },
        [fetchComparison, t],
    );

    const toggleCompareMode = useCallback(() => {
        setCompareState((prev) => {
            if (prev.active) {
                // Exit compare mode
                mapRef.current?.clearComparePins();
                return { active: false, pinA: null, pinB: null, result: null, loading: false, error: false };
            }
            // Enter compare mode - clear normal selection
            mapRef.current?.clearSelection();
            mapRef.current?.clearSchoolMarkers();
            mapRef.current?.clearLocationMarker();
            return { active: true, pinA: null, pinB: null, result: null, loading: false, error: false };
        });
    }, []);

    const exitCompareMode = useCallback(() => {
        mapRef.current?.clearComparePins();
        setCompareState({ active: false, pinA: null, pinB: null, result: null, loading: false, error: false });
    }, []);

    const swapPins = useCallback(() => {
        setCompareState((prev) => {
            if (!prev.pinA || !prev.pinB) return prev;
            const newA = prev.pinB;
            const newB = prev.pinA;
            mapRef.current?.clearComparePins();
            mapRef.current?.placeComparePin('a', newA.lat, newA.lng);
            mapRef.current?.placeComparePin('b', newB.lat, newB.lng);
            fetchComparison(newA, newB);
            return { ...prev, pinA: newA, pinB: newB, result: null };
        });
    }, [fetchComparison]);

    const enterCompareWithCurrent = useCallback(() => {
        if (!selectedDeso || !selectedScore) return;
        // Use the centroid of the selected DeSO as pin A
        // We don't have the centroid directly, so resolve via backend
        mapRef.current?.clearSelection();
        mapRef.current?.clearSchoolMarkers();
        mapRef.current?.clearLocationMarker();

        setCompareState({
            active: true,
            pinA: null,
            pinB: null,
            result: null,
            loading: false,
            error: false,
        });

        // Use the resolve-deso endpoint in reverse: we know the deso_code,
        // but we need a point. For now, use the first indicator lat/lng or just enter compare mode.
        // Actually, the simplest approach: enter compare mode and tell user to click again.
        // The "compare with" button is more of a UX hint.
    }, [selectedDeso, selectedScore]);

    // Escape key exits compare mode
    useEffect(() => {
        function handleKeyDown(e: KeyboardEvent) {
            if (e.key === 'Escape' && compareState.active) {
                exitCompareMode();
            }
        }

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [compareState.active, exitCompareMode]);

    // Load comparison from URL on mount
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const compareParam = params.get('compare');
        if (compareParam) {
            const parts = compareParam.split('|');
            if (parts.length === 2) {
                const [aStr, bStr] = parts;
                const [aLat, aLng] = aStr.split(',').map(Number);
                const [bLat, bLng] = bStr.split(',').map(Number);
                if (!isNaN(aLat) && !isNaN(aLng) && !isNaN(bLat) && !isNaN(bLng)) {
                    const pinA: ComparePin = { lat: aLat, lng: aLng, label: 'A' };
                    const pinB: ComparePin = { lat: bLat, lng: bLng, label: 'B' };

                    // Small delay to let map initialize
                    setTimeout(() => {
                        setCompareState({
                            active: true,
                            pinA,
                            pinB,
                            result: null,
                            loading: true,
                            error: false,
                        });
                        mapRef.current?.placeComparePin('a', aLat, aLng);
                        mapRef.current?.placeComparePin('b', bLat, bLng);
                        mapRef.current?.fitToPoints([pinA, pinB]);
                        fetchComparison(pinA, pinB);
                    }, 1500);
                }
            }
        }
    }, [fetchComparison]);

    useEffect(() => {
        const timer = setTimeout(() => {
            mapRef.current?.updateSize();
        }, 50);
        return () => clearTimeout(timer);
    }, [selectedDeso]);

    return (
        <MapLayout>
            <Head title={t('map.head_title')} />

            <div className="relative min-h-0 flex-1">
                <DesoMap
                    ref={mapRef}
                    initialCenter={initialCenter}
                    initialZoom={initialZoom}
                    onFeatureSelect={handleFeatureSelect}
                    onSchoolClick={handleSchoolClick}
                    compareMode={compareState.active}
                    onCompareClick={handleCompareClick}
                />
                <MapSearch
                    onResultSelect={handleSearchResult}
                    onClear={handleSearchClear}
                />

                {/* Map control buttons (right side, below layer control) */}
                <div className="absolute top-[120px] right-4 z-10 flex flex-col gap-1.5">
                    {/* Compare button */}
                    <button
                        onClick={toggleCompareMode}
                        className={`flex h-9 w-9 items-center justify-center rounded-lg border shadow-sm backdrop-blur-sm transition-colors ${
                            compareState.active
                                ? 'border-blue-400 bg-blue-500 text-white'
                                : 'border-border bg-background/90 text-muted-foreground hover:text-foreground'
                        }`}
                        title={t('compare.button_title')}
                    >
                        <ArrowLeftRight className="h-4 w-4" />
                    </button>

                    {/* Locate me button */}
                    <button
                        onClick={handleLocateMe}
                        disabled={locating}
                        className={`flex h-9 w-9 items-center justify-center rounded-lg border shadow-sm backdrop-blur-sm transition-colors ${
                            locating
                                ? 'border-blue-400 bg-blue-50 text-blue-500'
                                : userLocation
                                    ? 'border-border bg-background/90 text-blue-500 hover:text-blue-600'
                                    : 'border-border bg-background/90 text-muted-foreground hover:text-foreground'
                        }`}
                        title={t('geolocation.you_are_here')}
                    >
                        {locating ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <Crosshair className="h-4 w-4" />
                        )}
                    </button>
                </div>

                {/* Compare mode banner */}
                {compareState.active && (
                    <div className="absolute top-4 left-1/2 z-20 -translate-x-1/2">
                        <div className="flex items-center gap-2 rounded-lg border border-blue-300 bg-blue-50/95 px-4 py-2 text-sm text-blue-800 shadow-sm backdrop-blur-sm">
                            <ArrowLeftRight className="h-4 w-4 shrink-0" />
                            <span>
                                {!compareState.pinA
                                    ? t('compare.banner')
                                    : !compareState.pinB
                                        ? t('compare.banner_pin_a_placed')
                                        : ''}
                            </span>
                            <button
                                onClick={exitCompareMode}
                                className="ml-1 rounded p-0.5 text-blue-600 transition-colors hover:bg-blue-200"
                            >
                                <X className="h-3.5 w-3.5" />
                            </button>
                        </div>
                    </div>
                )}
            </div>

            <aside className="h-[40vh] w-full shrink-0 border-t border-border bg-background md:h-full md:w-[400px] md:border-l md:border-t-0">
                {/* Comparison sidebar */}
                {compareState.active && compareState.result ? (
                    <ComparisonSidebar
                        result={compareState.result}
                        pinALabel={compareState.pinA?.label}
                        pinBLabel={compareState.pinB?.label}
                        onClose={exitCompareMode}
                        onSwapPins={swapPins}
                    />
                ) : compareState.active && compareState.loading ? (
                    <div className="flex h-full items-center justify-center p-8">
                        <div className="text-center">
                            <Loader2 className="mx-auto mb-3 h-6 w-6 animate-spin text-muted-foreground" />
                            <div className="text-sm text-muted-foreground">{t('compare.loading')}</div>
                        </div>
                    </div>
                ) : compareState.active && compareState.error ? (
                    <div className="flex h-full items-center justify-center p-8">
                        <div className="text-center">
                            <div className="text-sm text-muted-foreground">{t('compare.error')}</div>
                            <button
                                onClick={() => {
                                    if (compareState.pinA && compareState.pinB) {
                                        fetchComparison(compareState.pinA, compareState.pinB);
                                    }
                                }}
                                className="mt-2 text-xs font-medium text-primary hover:underline"
                            >
                                {t('common.retry')}
                            </button>
                        </div>
                    </div>
                ) : compareState.active ? (
                    <div className="flex h-full items-center justify-center p-8 text-center">
                        <div>
                            <ArrowLeftRight className="mx-auto mb-3 h-8 w-8 text-blue-400" />
                            <div className="text-sm font-medium text-foreground">{t('compare.banner')}</div>
                        </div>
                    </div>
                ) : (
                <ScrollArea className="h-full">
                    {selectedDeso ? (
                        <div className="space-y-6 p-5">
                            {/* Header */}
                            <div>
                                <div className="flex items-start justify-between">
                                    <div>
                                        <div className="flex items-baseline gap-2">
                                            {selectedDeso.deso_name && (
                                                <span className="text-lg font-semibold text-foreground">
                                                    {selectedDeso.deso_name}
                                                </span>
                                            )}
                                            <span className="text-xs font-medium text-muted-foreground">
                                                {selectedDeso.deso_code}
                                            </span>
                                        </div>
                                        <div className="mt-0.5 text-sm text-muted-foreground">
                                            {selectedDeso.kommun_name ?? t('sidebar.header.unknown')} &middot; {selectedDeso.lan_name ?? t('sidebar.header.unknown')}
                                        </div>
                                        {selectedDeso.area_km2 !== null && (
                                            <div className="text-xs text-muted-foreground">
                                                {selectedDeso.area_km2.toFixed(2)} km&sup2;
                                            </div>
                                        )}
                                        {userLocation && (
                                            <Badge className="mt-1 rounded-full border-blue-200 bg-blue-50 px-2 py-0.5 text-[10px] font-medium text-blue-700" variant="secondary">
                                                {t('geolocation.you_are_here')}
                                            </Badge>
                                        )}
                                    </div>

                                    {selectedScore && (
                                        <div className="text-right">
                                            <div className="flex items-start justify-end gap-1">
                                                <div
                                                    className="text-4xl font-bold tabular-nums"
                                                    style={scoreColorStyle(selectedScore.score)}
                                                >
                                                    {selectedScore.score.toFixed(0)}
                                                </div>
                                                <ScoreTooltip
                                                    score={selectedScore.score}
                                                    scoreLabel={scoreLabel(selectedScore.score)}
                                                />
                                            </div>
                                            <div className="mt-0.5 text-sm font-medium text-muted-foreground">
                                                {scoreLabel(selectedScore.score)}
                                            </div>
                                        </div>
                                    )}
                                </div>
                                <Separator className="mt-4" />
                            </div>

                            {/* Indicator Breakdown */}
                            {(indicatorData || selectedScore?.factor_scores) && (
                                <div>
                                    <div className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                        {t('sidebar.indicators.title')}
                                    </div>
                                    {indicatorData?.trend_meta?.eligible && indicatorData.trend_meta.period && (
                                        <p className="mb-2 text-[11px] text-muted-foreground">
                                            {t('sidebar.indicators.trend_subtitle', { period: indicatorData.trend_meta.period })}
                                        </p>
                                    )}
                                    {indicatorData && !indicatorData.trend_eligible && (
                                        <p className="mb-2 text-[11px] italic text-muted-foreground">
                                            {t('sidebar.indicators.trend_ineligible')}
                                        </p>
                                    )}
                                    {indicatorLoading ? (
                                        <div className="space-y-2">
                                            {[1, 2, 3, 4, 5].map((i) => (
                                                <div key={i} className="space-y-1">
                                                    <div className="h-3 w-2/3 animate-pulse rounded bg-muted" />
                                                    <div className="h-1.5 w-full animate-pulse rounded-full bg-muted" />
                                                </div>
                                            ))}
                                        </div>
                                    ) : indicatorData ? (
                                        <div className="space-y-2">
                                            {indicatorData.indicators
                                                .filter((ind) => ind.normalized_value !== null)
                                                .map((ind) => (
                                                    <div key={ind.slug} className="flex items-start gap-1">
                                                        <div className="min-w-0 flex-1">
                                                            <FactorBar
                                                                label={indicatorLabel(ind.slug)}
                                                                value={ind.normalized_value!}
                                                                scope={ind.normalization_scope}
                                                                urbanityTier={selectedScore?.urbanity_tier}
                                                                meta={indicatorMeta[ind.slug]}
                                                                trend={ind.trend}
                                                                indicatorDirection={ind.direction}
                                                                showTrend={indicatorData.trend_eligible}
                                                            />
                                                        </div>
                                                        {indicatorData.trend_eligible && ind.history.length >= 2 && (
                                                            <TrendDetailTooltip indicator={ind} />
                                                        )}
                                                    </div>
                                                ))}
                                        </div>
                                    ) : selectedScore?.factor_scores ? (
                                        <div className="space-y-2">
                                            {Object.entries(selectedScore.factor_scores).map(
                                                ([slug, value]) => (
                                                    <FactorBar
                                                        key={slug}
                                                        label={indicatorLabel(slug)}
                                                        value={value}
                                                        scope={indicatorScopes[slug]}
                                                        urbanityTier={selectedScore.urbanity_tier}
                                                        meta={indicatorMeta[slug]}
                                                    />
                                                ),
                                            )}
                                        </div>
                                    ) : null}
                                </div>
                            )}

                            {/* Crime & Safety Section */}
                            <Separator />
                            <CrimeSection crimeData={crimeData} loading={crimeLoading} />

                            {/* Financial Health Section */}
                            <Separator />
                            <FinancialSection data={financialData} loading={financialLoading} />

                            {/* Schools Section */}
                            <Separator />
                            <div>
                                {(() => {
                                    const filteredSchools = schools.filter((s) => {
                                        if (schoolFilter === 'all') return true;
                                        const cat = getSchoolFormCategory(s.school_forms ?? []);
                                        return cat === schoolFilter;
                                    });
                                    return (
                                        <>
                                            <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                                {schoolsLoading
                                                    ? t('sidebar.schools.title_loading')
                                                    : t('sidebar.schools.title_count', { count: filteredSchools.length })}
                                            </div>
                                            {!schoolsLoading && schools.length > 0 && (
                                                <div className="mb-2 flex gap-1">
                                                    {(['all', 'grundskola', 'gymnasie', 'other'] as SchoolFilter[]).map((f) => (
                                                        <button
                                                            key={f}
                                                            onClick={() => setSchoolFilter(f)}
                                                            className={`rounded-full px-2.5 py-0.5 text-[11px] font-medium transition-colors ${
                                                                schoolFilter === f
                                                                    ? 'bg-primary text-primary-foreground'
                                                                    : 'bg-muted text-muted-foreground hover:bg-muted/80'
                                                            }`}
                                                        >
                                                            {t(`sidebar.schools.filter_${f}`)}
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                            {schoolsLoading ? (
                                                <div className="space-y-3">
                                                    {[1, 2].map((i) => (
                                                        <div key={i} className="h-24 animate-pulse rounded-lg bg-muted" />
                                                    ))}
                                                </div>
                                            ) : filteredSchools.length > 0 ? (
                                                <div className="space-y-2">
                                                    {filteredSchools.map((school) => (
                                                        <SchoolCard
                                                            key={school.school_unit_code}
                                                            school={school}
                                                            highlighted={highlightedSchool === school.school_unit_code}
                                                            onRef={(el) => {
                                                                schoolRefs.current[school.school_unit_code] = el;
                                                            }}
                                                        />
                                                    ))}
                                                </div>
                                            ) : (
                                                <div className="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                                                    {t('sidebar.schools.empty')}
                                                    <NoDataTooltip reason="no_schools" />
                                                </div>
                                            )}
                                        </>
                                    );
                                })()}
                            </div>

                            {/* Strengths / Weaknesses */}
                            {(selectedScore?.top_positive?.length ||
                                selectedScore?.top_negative?.length) && (
                                <>
                                    <Separator />
                                    <div className="space-y-2">
                                        {selectedScore?.top_positive &&
                                            selectedScore.top_positive.length > 0 && (
                                                <div>
                                                    <div className="mb-1 text-xs font-medium text-trend-positive">
                                                        {t('sidebar.strengths')}
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_positive.map((slug) => (
                                                            <Badge
                                                                key={slug}
                                                                className="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700"
                                                                variant="secondary"
                                                            >
                                                                {indicatorLabel(slug)}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                        {selectedScore?.top_negative &&
                                            selectedScore.top_negative.length > 0 && (
                                                <div>
                                                    <div className="mb-1 text-xs font-medium text-score-0">
                                                        {t('sidebar.weaknesses')}
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_negative.map((slug) => (
                                                            <Badge
                                                                key={slug}
                                                                className="rounded-full border border-purple-200 bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700"
                                                                variant="secondary"
                                                            >
                                                                {indicatorLabel(slug)}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}
                                    </div>
                                </>
                            )}

                            {/* Data Freshness */}
                            <Separator />
                            <DataFreshness meta={indicatorMeta} />

                            {/* Compare with button */}
                            <Separator />
                            <button
                                onClick={enterCompareWithCurrent}
                                className="flex w-full items-center justify-center gap-2 rounded-lg border border-border px-3 py-2.5 text-xs font-medium text-foreground transition-colors hover:bg-muted"
                            >
                                <ArrowLeftRight className="h-4 w-4" />
                                {t('compare.compare_with')}
                            </button>
                        </div>
                    ) : (
                        <div className="flex h-full items-center justify-center p-8 text-center">
                            <div>
                                <MapPin className="mx-auto mb-3 h-8 w-8 text-muted-foreground" />
                                {searchNotInDeso ? (
                                    <>
                                        <div className="text-sm font-medium text-foreground">
                                            {t('sidebar.empty_no_deso.title')}
                                        </div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {t('sidebar.empty_no_deso.subtitle')}
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <div className="text-sm font-medium text-foreground">{t('sidebar.empty.title')}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            {t('sidebar.empty.subtitle')}
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </ScrollArea>
                )}
            </aside>
        </MapLayout>
    );
}
