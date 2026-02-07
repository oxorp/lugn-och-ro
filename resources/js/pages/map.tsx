import { Head } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowRight,
    ArrowUp,
    Landmark,
    MapPin,
    Shield,
    ShieldAlert,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import DesoMap, {
    type DesoMapHandle,
    type DesoProperties,
    type DesoScore,
} from '@/components/deso-map';
import MapSearch from '@/components/map-search';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
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
}

export interface School {
    school_unit_code: string;
    name: string;
    type: string | null;
    operator_type: string | null;
    lat: number | null;
    lng: number | null;
    merit_value: number | null;
    goal_achievement: number | null;
    teacher_certification: number | null;
    student_count: number | null;
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

function scoreColor(score: number): string {
    if (score >= 70) return 'text-green-700';
    if (score >= 40) return 'text-yellow-700';
    return 'text-purple-800';
}

function scoreBgColor(score: number): string {
    if (score >= 70) return 'bg-green-600';
    if (score >= 40) return 'bg-yellow-500';
    return 'bg-purple-700';
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

function TrendIcon({ trend }: { trend: number | null }) {
    if (trend === null) return <ArrowRight className="h-4 w-4 text-gray-400" />;
    if (trend > 1)
        return <ArrowUp className="h-4 w-4 text-green-600" />;
    if (trend < -1)
        return <ArrowDown className="h-4 w-4 text-red-600" />;
    return <ArrowRight className="h-4 w-4 text-gray-400" />;
}

function FactorBar({
    label,
    value,
    scope,
    urbanityTier,
}: {
    label: string;
    value: number;
    scope?: 'national' | 'urbanity_stratified';
    urbanityTier?: string | null;
}) {
    const { t } = useTranslation();
    const pct = Math.round(value * 100);
    const isStratified = scope === 'urbanity_stratified' && urbanityTier;
    const tierLabel = urbanityTier
        ? t(`sidebar.urbanity.${urbanityTier}`, { defaultValue: urbanityTier })
        : '';

    return (
        <div className="space-y-0.5">
            <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium">
                    {isStratified
                        ? t('sidebar.indicators.percentile_stratified', { value: pct, tier: tierLabel })
                        : t('sidebar.indicators.percentile_national', { value: pct })}
                </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div
                    className={`h-full rounded-full transition-all ${scoreBgColor(pct)}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
        </div>
    );
}

function meritColor(merit: number | null): string {
    if (merit === null) return 'bg-gray-400';
    if (merit > 230) return 'bg-green-500';
    if (merit >= 200) return 'bg-yellow-500';
    return 'bg-orange-500';
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
            className={`rounded-lg border p-3 transition-colors ${highlighted ? 'border-orange-400 bg-orange-50' : 'bg-card'}`}
        >
            <div className="mb-1 flex items-start justify-between">
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium">{school.name}</div>
                    <div className="text-muted-foreground flex items-center gap-1.5 text-xs">
                        {school.type && <span>{school.type}</span>}
                        {school.type && school.operator_type && <span>&middot;</span>}
                        {school.operator_type && (
                            <Badge variant="outline" className="text-[10px] px-1 py-0">
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
                            <span className="text-muted-foreground">{t('sidebar.schools.merit_value')}</span>
                            <span className="font-medium">{school.merit_value.toFixed(0)}</span>
                        </div>
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                            <div
                                className={`h-full rounded-full ${meritColor(school.merit_value)}`}
                                style={{ width: `${Math.min(100, (school.merit_value / 340) * 100)}%` }}
                            />
                        </div>
                    </div>
                )}
                {school.goal_achievement !== null && (
                    <div className="space-y-0.5">
                        <div className="flex justify-between text-xs">
                            <span className="text-muted-foreground">{t('sidebar.schools.goal_achievement')}</span>
                            <span className="font-medium">{school.goal_achievement.toFixed(0)}%</span>
                        </div>
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                            <div
                                className="h-full rounded-full bg-blue-500"
                                style={{ width: `${school.goal_achievement}%` }}
                            />
                        </div>
                    </div>
                )}
                {school.teacher_certification !== null && (
                    <div className="space-y-0.5">
                        <div className="flex justify-between text-xs">
                            <span className="text-muted-foreground">{t('sidebar.schools.teachers')}</span>
                            <span className="font-medium">{school.teacher_certification.toFixed(0)}%</span>
                        </div>
                        <div className="h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
                            <div
                                className="h-full rounded-full bg-indigo-500"
                                style={{ width: `${school.teacher_certification}%` }}
                            />
                        </div>
                    </div>
                )}
                {school.student_count !== null && (
                    <div className="text-muted-foreground mt-1 text-xs">
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
                <span className="font-medium">{t('sidebar.indicators.percentile_national', { value: Math.round(safeness) })}</span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div
                    className={`h-full rounded-full transition-all ${scoreBgColor(safeness)}`}
                    style={{ width: `${safeness}%` }}
                />
            </div>
            <div className="text-muted-foreground text-[10px]">
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
                <div className="h-16 animate-pulse rounded-lg bg-gray-100" />
                <div className="h-24 animate-pulse rounded-lg bg-gray-100" />
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
                                    <span className="font-medium">
                                        {t('sidebar.indicators.percentile_national', { value: Math.round(crimeData.perceived_safety.percentile) })}
                                    </span>
                                </div>
                                <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                                    <div
                                        className={`h-full rounded-full transition-all ${scoreBgColor(crimeData.perceived_safety.percentile)}`}
                                        style={{
                                            width: `${crimeData.perceived_safety.percentile}%`,
                                        }}
                                    />
                                </div>
                                <div className="text-muted-foreground text-[10px]">
                                    {t('sidebar.crime.feel_safe', { value: crimeData.perceived_safety.percent_safe })}
                                </div>
                            </div>
                        )}
                </div>
            </div>

            <div className="rounded border border-dashed border-gray-200 px-2.5 py-2 text-[10px] text-muted-foreground">
                {t('sidebar.crime.disclaimer', {
                    kommun: crimeData.kommun_name,
                    total: crimeData.kommun_actual_rates.total?.toLocaleString() ?? '',
                })}
            </div>

            <div className="rounded-lg border border-dashed p-3 text-center">
                <div className="text-xs font-medium text-muted-foreground">
                    {t('sidebar.crime.recent_incidents')}
                </div>
                <div className="mt-0.5 text-[10px] text-muted-foreground">
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
    return (
        <div className="space-y-0.5">
            <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium">
                    {value.toLocaleString(undefined, { maximumFractionDigits: 1 })}
                    {suffix}
                </span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div
                    className={`h-full rounded-full transition-all ${scoreBgColor(100 - pct)}`}
                    style={{ width: `${pct}%` }}
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
                <div className="h-16 animate-pulse rounded-lg bg-gray-100" />
                <div className="h-24 animate-pulse rounded-lg bg-gray-100" />
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
                        <div className="text-muted-foreground -mt-1.5 text-[10px]">
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
                                <span className="font-medium">
                                    {t('sidebar.financial.sek_thousands', {
                                        value: Math.round(data.kommun_median_debt / 1000).toLocaleString(),
                                    })}
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <div className="rounded border border-dashed border-gray-200 px-2.5 py-2 text-[10px] text-muted-foreground">
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

export default function MapPage({ initialCenter, initialZoom, indicatorScopes }: MapPageProps) {
    const { t } = useTranslation();
    const scoreLabel = useScoreLabel();

    const [selectedDeso, setSelectedDeso] = useState<DesoProperties | null>(null);
    const [selectedScore, setSelectedScore] = useState<DesoScore | null>(null);
    const [schools, setSchools] = useState<School[]>([]);
    const [schoolsLoading, setSchoolsLoading] = useState(false);
    const [crimeData, setCrimeData] = useState<CrimeData | null>(null);
    const [crimeLoading, setCrimeLoading] = useState(false);
    const [financialData, setFinancialData] = useState<FinancialData | null>(null);
    const [financialLoading, setFinancialLoading] = useState(false);
    const [highlightedSchool, setHighlightedSchool] = useState<string | null>(null);
    const [searchNotInDeso, setSearchNotInDeso] = useState(false);
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
                />
                <MapSearch
                    onResultSelect={handleSearchResult}
                    onClear={handleSearchClear}
                />
            </div>

            <aside className="border-t md:border-t-0 md:border-l bg-background h-[40vh] w-full shrink-0 md:h-full md:w-[400px]">
                <ScrollArea className="h-full">
                    {selectedDeso ? (
                        <div className="space-y-4 p-4">
                            {/* Header */}
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="flex items-center gap-1.5">
                                        <MapPin className="text-muted-foreground h-3.5 w-3.5" />
                                        <span className="font-mono text-sm font-medium">
                                            {selectedDeso.deso_code}
                                        </span>
                                    </div>
                                    {selectedDeso.deso_name && (
                                        <div className="mt-0.5 text-sm text-gray-600">
                                            {selectedDeso.deso_name}
                                        </div>
                                    )}
                                    <div className="text-muted-foreground mt-1 text-xs">
                                        {selectedDeso.kommun_name ?? t('sidebar.header.unknown')} &middot; {selectedDeso.lan_name ?? t('sidebar.header.unknown')}
                                        {selectedDeso.area_km2 !== null && (
                                            <> &middot; {selectedDeso.area_km2.toFixed(2)} km&sup2;</>
                                        )}
                                    </div>
                                </div>

                                {selectedScore && (
                                    <div className="text-right">
                                        <div
                                            className={`text-3xl font-bold ${scoreColor(selectedScore.score)}`}
                                        >
                                            {selectedScore.score.toFixed(0)}
                                        </div>
                                        <div className="flex items-center justify-end gap-1">
                                            <TrendIcon trend={selectedScore.trend_1y} />
                                            <span className="text-muted-foreground text-xs">
                                                {selectedScore.trend_1y !== null
                                                    ? `${selectedScore.trend_1y > 0 ? '+' : ''}${selectedScore.trend_1y.toFixed(1)}`
                                                    : t('sidebar.score.na')}
                                            </span>
                                        </div>
                                        <div className="text-muted-foreground mt-0.5 text-[11px]">
                                            {scoreLabel(selectedScore.score)}
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Indicator Breakdown */}
                            {selectedScore?.factor_scores && (
                                <>
                                    <Separator />
                                    <div>
                                        <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                            {t('sidebar.indicators.title')}
                                        </div>
                                        <div className="space-y-2">
                                            {Object.entries(selectedScore.factor_scores).map(
                                                ([slug, value]) => (
                                                    <FactorBar
                                                        key={slug}
                                                        label={indicatorLabel(slug)}
                                                        value={value}
                                                        scope={indicatorScopes[slug]}
                                                        urbanityTier={selectedScore.urbanity_tier}
                                                    />
                                                ),
                                            )}
                                        </div>
                                    </div>
                                </>
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
                                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    {schoolsLoading
                                        ? t('sidebar.schools.title_loading')
                                        : t('sidebar.schools.title_count', { count: schools.length })}
                                </div>
                                {schoolsLoading ? (
                                    <div className="space-y-3">
                                        {[1, 2].map((i) => (
                                            <div key={i} className="h-24 animate-pulse rounded-lg bg-gray-100" />
                                        ))}
                                    </div>
                                ) : schools.length > 0 ? (
                                    <div className="space-y-2">
                                        {schools.map((school) => (
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
                                    <div className="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground">
                                        {t('sidebar.schools.empty')}
                                    </div>
                                )}
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
                                                    <div className="mb-1 text-xs font-medium text-green-700">
                                                        {t('sidebar.strengths')}
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_positive.map((slug) => (
                                                            <Badge
                                                                key={slug}
                                                                className="bg-green-100 text-green-800"
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
                                                    <div className="mb-1 text-xs font-medium text-purple-700">
                                                        {t('sidebar.weaknesses')}
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_negative.map((slug) => (
                                                            <Badge
                                                                key={slug}
                                                                className="bg-purple-100 text-purple-800"
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
                        </div>
                    ) : (
                        <div className="flex h-full items-center justify-center p-8 text-center">
                            <div>
                                <MapPin className="text-muted-foreground mx-auto mb-3 h-8 w-8" />
                                {searchNotInDeso ? (
                                    <>
                                        <div className="text-sm font-medium">
                                            {t('sidebar.empty_no_deso.title')}
                                        </div>
                                        <div className="text-muted-foreground mt-1 text-xs">
                                            {t('sidebar.empty_no_deso.subtitle')}
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        <div className="text-sm font-medium">{t('sidebar.empty.title')}</div>
                                        <div className="text-muted-foreground mt-1 text-xs">
                                            {t('sidebar.empty.subtitle')}
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    )}
                </ScrollArea>
            </aside>
        </MapLayout>
    );
}
