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
    type DesoProperties,
    type DesoScore,
} from '@/components/deso-map';
import { Badge } from '@/components/ui/badge';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import MapLayout from '@/layouts/map-layout';

interface MapPageProps {
    initialCenter: [number, number];
    initialZoom: number;
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

const INDICATOR_LABELS: Record<string, string> = {
    median_income: 'Median Income',
    low_economic_standard_pct: 'Low Economic Standard',
    employment_rate: 'Employment Rate',
    education_post_secondary_pct: 'Post-Secondary Education',
    education_below_secondary_pct: 'Below Secondary Education',
    foreign_background_pct: 'Foreign Background',
    population: 'Population',
    rental_tenure_pct: 'Rental Housing',
    school_merit_value_avg: 'School Merit Value',
    school_goal_achievement_avg: 'School Goal Achievement',
    school_teacher_certification_avg: 'Teacher Certification',
    crime_violent_rate: 'Violent Crime Rate',
    crime_property_rate: 'Property Crime Rate',
    crime_total_rate: 'Total Crime Rate',
    perceived_safety: 'Perceived Safety',
    vulnerability_flag: 'Vulnerability Area',
    debt_rate_pct: 'Debt Rate (KFM)',
    eviction_rate: 'Eviction Rate',
    median_debt_sek: 'Median Debt',
};

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

function scoreLabel(score: number): string {
    if (score >= 80) return 'Strong Growth Area';
    if (score >= 60) return 'Positive Indicators';
    if (score >= 40) return 'Mixed Signals';
    if (score >= 20) return 'Challenging Area';
    return 'High Risk Area';
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
}: {
    label: string;
    value: number;
}) {
    const pct = Math.round(value * 100);
    return (
        <div className="space-y-0.5">
            <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium">{pct}th pctl</span>
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
                        {school.type && school.operator_type && <span>·</span>}
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
                            <span className="text-muted-foreground">Meritvärde</span>
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
                            <span className="text-muted-foreground">Goal ach.</span>
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
                            <span className="text-muted-foreground">Teachers</span>
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
                        {school.student_count} students
                    </div>
                )}
            </div>
        </div>
    );
}

function VulnerabilityCard({ vulnerability }: { vulnerability: CrimeData['vulnerability'] }) {
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
                        Polisens {vulnerability.tier_label}
                    </div>
                    <div
                        className={`mt-1 text-xs ${
                            isSarskilt ? 'text-red-700' : 'text-amber-700'
                        }`}
                    >
                        This area overlaps with &ldquo;{vulnerability.name}&rdquo; &mdash;
                        classified as{' '}
                        <span className="font-semibold uppercase">
                            {isSarskilt ? 'SÄRSKILT UTSATT' : 'UTSATT'}
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
    if (rate === null || percentile === null) return null;

    // For crime: low percentile = low crime = good, high = bad
    // Invert display: show "safeness" where 100 = safest
    const safeness = 100 - percentile;
    return (
        <div className="space-y-0.5">
            <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium">{Math.round(safeness)}th pctl</span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div
                    className={`h-full rounded-full transition-all ${scoreBgColor(safeness)}`}
                    style={{ width: `${safeness}%` }}
                />
            </div>
            <div className="text-muted-foreground text-[10px]">
                est. {rate.toLocaleString()}/100k
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
                    Crime & Safety
                </div>
                <div className="space-y-2.5">
                    <CrimeRateBar
                        label="Violent crime"
                        rate={crimeData.estimated_rates.violent.rate}
                        percentile={crimeData.estimated_rates.violent.percentile}
                    />
                    <CrimeRateBar
                        label="Property crime"
                        rate={crimeData.estimated_rates.property.rate}
                        percentile={crimeData.estimated_rates.property.percentile}
                    />
                    {crimeData.perceived_safety.percent_safe !== null &&
                        crimeData.perceived_safety.percentile !== null && (
                            <div className="space-y-0.5">
                                <div className="flex justify-between text-xs">
                                    <span className="text-muted-foreground">
                                        Perceived safety
                                    </span>
                                    <span className="font-medium">
                                        {Math.round(crimeData.perceived_safety.percentile)}th pctl
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
                                    {crimeData.perceived_safety.percent_safe}% feel safe at night
                                </div>
                            </div>
                        )}
                </div>
            </div>

            <div className="rounded border border-dashed border-gray-200 px-2.5 py-2 text-[10px] text-muted-foreground">
                Crime rates are estimated from kommun-level data ({crimeData.kommun_name}) using
                demographic weighting. Kommun total: {crimeData.kommun_actual_rates.total?.toLocaleString()}/100k.
            </div>

            {/* Future: Recent Incidents placeholder */}
            <div className="rounded-lg border border-dashed p-3 text-center">
                <div className="text-xs font-medium text-muted-foreground">
                    Recent Incidents
                </div>
                <div className="mt-0.5 text-[10px] text-muted-foreground">
                    Coming soon &mdash; real-time tracking of police reports and news.
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
                                Elevated Financial Distress
                            </div>
                            <div className="mt-1 text-xs text-orange-700">
                                Estimated debt rate of {data.estimated_debt_rate}%,
                                significantly above the national average of{' '}
                                {data.national_avg_rate}%.
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <div>
                <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    <Landmark className="h-3.5 w-3.5" />
                    Financial Health
                </div>
                <div className="space-y-2.5">
                    <FinancialRateBar
                        label="Debt rate"
                        value={data.estimated_debt_rate}
                        suffix="%"
                        maxValue={10}
                    />
                    {data.kommun_actual_rate !== null && (
                        <div className="text-muted-foreground -mt-1.5 text-[10px]">
                            kommun avg: {data.kommun_actual_rate}%
                        </div>
                    )}
                    <FinancialRateBar
                        label="Evictions"
                        value={data.estimated_eviction_rate}
                        suffix="/100k"
                        maxValue={80}
                    />
                    {data.kommun_median_debt !== null && (
                        <div className="space-y-0.5">
                            <div className="flex justify-between text-xs">
                                <span className="text-muted-foreground">
                                    Median debt
                                </span>
                                <span className="font-medium">
                                    {Math.round(
                                        data.kommun_median_debt / 1000,
                                    ).toLocaleString()}
                                    k SEK
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <div className="rounded border border-dashed border-gray-200 px-2.5 py-2 text-[10px] text-muted-foreground">
                Estimated from kommun-level Kronofogden data
                {data.kommun_name && <> ({data.kommun_name})</>} using demographic
                weighting.
                {data.kommun_actual_rate !== null && (
                    <> Kommun actual rate: {data.kommun_actual_rate}%.</>
                )}
            </div>
        </div>
    );
}

export default function MapPage({ initialCenter, initialZoom }: MapPageProps) {
    const [selectedDeso, setSelectedDeso] = useState<DesoProperties | null>(null);
    const [selectedScore, setSelectedScore] = useState<DesoScore | null>(null);
    const [schools, setSchools] = useState<School[]>([]);
    const [schoolsLoading, setSchoolsLoading] = useState(false);
    const [crimeData, setCrimeData] = useState<CrimeData | null>(null);
    const [crimeLoading, setCrimeLoading] = useState(false);
    const [financialData, setFinancialData] = useState<FinancialData | null>(null);
    const [financialLoading, setFinancialLoading] = useState(false);
    const [highlightedSchool, setHighlightedSchool] = useState<string | null>(null);
    const schoolRefs = useRef<Record<string, HTMLDivElement | null>>({});
    const mapRef = useRef<{ updateSize: () => void; clearSchoolMarkers: () => void; setSchoolMarkers: (schools: School[]) => void } | null>(null);

    const handleFeatureSelect = useCallback(
        (properties: DesoProperties | null, score: DesoScore | null) => {
            setSelectedDeso(properties);
            setSelectedScore(score);
            setHighlightedSchool(null);

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

    // Notify map to resize when sidebar content changes
    useEffect(() => {
        const timer = setTimeout(() => {
            mapRef.current?.updateSize();
        }, 50);
        return () => clearTimeout(timer);
    }, [selectedDeso]);

    return (
        <MapLayout>
            <Head title="Map" />

            <div className="min-h-0 flex-1">
                <DesoMap
                    ref={mapRef}
                    initialCenter={initialCenter}
                    initialZoom={initialZoom}
                    onFeatureSelect={handleFeatureSelect}
                    onSchoolClick={handleSchoolClick}
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
                                        {selectedDeso.kommun_name ?? 'Unknown'} · {selectedDeso.lan_name ?? 'Unknown'}
                                        {selectedDeso.area_km2 !== null && (
                                            <> · {selectedDeso.area_km2.toFixed(2)} km²</>
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
                                                    : 'N/A'}
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
                                            Indicator Breakdown
                                        </div>
                                        <div className="space-y-2">
                                            {Object.entries(selectedScore.factor_scores).map(
                                                ([slug, value]) => (
                                                    <FactorBar
                                                        key={slug}
                                                        label={INDICATOR_LABELS[slug] || slug}
                                                        value={value}
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
                                    Schools in this area ({schoolsLoading ? '...' : schools.length})
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
                                        No schools are located in this DeSO area.
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
                                                        Strengths
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_positive.map((slug) => (
                                                            <Badge
                                                                key={slug}
                                                                className="bg-green-100 text-green-800"
                                                                variant="secondary"
                                                            >
                                                                {INDICATOR_LABELS[slug] || slug}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                </div>
                                            )}

                                        {selectedScore?.top_negative &&
                                            selectedScore.top_negative.length > 0 && (
                                                <div>
                                                    <div className="mb-1 text-xs font-medium text-purple-700">
                                                        Weaknesses
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_negative.map((slug) => (
                                                            <Badge
                                                                key={slug}
                                                                className="bg-purple-100 text-purple-800"
                                                                variant="secondary"
                                                            >
                                                                {INDICATOR_LABELS[slug] || slug}
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
                                <div className="text-sm font-medium">Click a DeSO area</div>
                                <div className="text-muted-foreground mt-1 text-xs">
                                    Select an area on the map to view demographic details, scores, and schools
                                </div>
                            </div>
                        </div>
                    )}
                </ScrollArea>
            </aside>
        </MapLayout>
    );
}
