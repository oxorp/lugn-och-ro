import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

import {
    faArrowLeft,
    faPrint,
    faLink,
    faGraduationCap,
    faShieldHalved,
    faChartColumn,
    faTree,
    faLocationDot,
    faCheck,
    faCircleCheck,
    faTriangleExclamation,
    faBinoculars,
    faFileLines,
    farCopy,
    faBus,
    faCartShopping,
    faPersonWalking,
    faCar,
    faClock,
} from '@/icons';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { scoreToColor, scoreToLabel } from '@/lib/score-colors';
import { Sparkline } from '@/pages/explore/components/sparkline';
import { TrendArrow } from '@/pages/explore/components/trend-arrow';
import { PercentileBar } from '@/components/percentile-bar';

// ── Types ───────────────────────────────────────────────────────────

interface IndicatorTrend {
    years: number[];
    percentiles: number[];
    raw_values: number[];
    change_1y: number | null;
    change_3y: number | null;
    change_5y: number | null;
}

interface SnapshotIndicator {
    slug: string;
    name: string;
    category: string;
    source: string;
    unit: string | null;
    direction: 'positive' | 'negative' | 'neutral';
    raw_value: number;
    formatted_value: string;
    normalized_value: number;
    percentile: number;
    description: string | null;
    trend: IndicatorTrend;
}

interface CategoryVerdict {
    label: string;
    score: number | null;
    grade: string;
    color: string;
    verdict_sv: string;
    trend_direction: string;
    indicator_count: number;
}

interface SchoolData {
    name: string;
    type: string | null;
    operator_type: string | null;
    distance_m: number;
    merit_value: number | null;
    goal_achievement: number | null;
    teacher_certification: number | null;
    student_count: string | null;
    lat: number;
    lng: number;
}

interface MapSnapshot {
    center: [number, number];
    zoom: number;
    deso_geojson: object | null;
    pin: [number, number];
    school_markers: { lat: number; lng: number; name: string; merit: number | null }[];
    surrounding_desos: { deso_code: string; geojson: object }[];
}

interface RingDefinition {
    ring: number;
    minutes: number;
    mode: 'pedestrian' | 'auto';
    label: string;
    color: string;
}

interface ReachabilityRingsData {
    rings: RingDefinition[];
    geojson: {
        type: 'FeatureCollection';
        features: Array<{
            type: 'Feature';
            geometry: object;
            properties: {
                ring: number;
                contour: number;
                mode: string;
                label: string;
                color: string;
                area_km2?: number;
            };
        }>;
    };
}

interface OutlookData {
    outlook: string;
    outlook_label: string;
    total_change: number | null;
    years_span: number;
    improving_count: number;
    declining_count: number;
    total_categories: number;
    text_sv: string;
    disclaimer: string;
}

interface StrengthWeakness {
    category: string;
    slug: string;
    text_sv: string;
    percentile: number;
}

interface DesoMeta {
    deso_code: string;
    deso_name: string | null;
    kommun_name: string | null;
    lan_name: string | null;
    area_km2: number | null;
    population: number | null;
    urbanity_tier: string | null;
}

interface ScoreHistoryPoint {
    year: number;
    score: number;
}

interface ProximityFactorDetails {
    nearest_school?: string;
    nearest_distance_m?: number;
    distance_m?: number;
    travel_seconds?: number | null;
    travel_minutes?: number | null;
    schools?: Array<{
        name: string;
        type: string | null;
        distance_m: number;
        travel_minutes: number | null;
    }>;
    nearest_park?: string;
    nearest_store?: string;
    nearest_stop?: string;
    nearest_type?: string;
    stops_found?: number;
    count?: number;
    types?: string[];
    nearest?: string;
    scoring_mode?: string;
    costing?: string;
    message?: string;
}

interface ProximityFactorData {
    slug: string;
    score: number | null;
    details: ProximityFactorDetails;
}

interface ProximityFactors {
    composite: number;
    safety_score: number;
    safety_zone: { level: string; label: string };
    urbanity_tier: string;
    factors: ProximityFactorData[];
}

interface ReportData {
    uuid: string;
    address: string | null;
    kommun_name: string | null;
    lan_name: string | null;
    deso_code: string | null;
    score: number | null;
    score_label: string;
    created_at: string;
    view_count: number;
    lat: number;
    lng: number;
    default_score: number | null;
    personalized_score: number | null;
    trend_1y: number | null;
    area_indicators: SnapshotIndicator[];
    proximity_factors: ProximityFactors | null;
    schools: SchoolData[];
    category_verdicts: Record<string, CategoryVerdict>;
    score_history: ScoreHistoryPoint[];
    deso_meta: DesoMeta | null;
    national_references: Record<string, { median: number | null; formatted: string | null }>;
    map_snapshot: MapSnapshot | null;
    reachability_rings: ReachabilityRingsData | null;
    outlook: OutlookData | null;
    top_positive: StrengthWeakness[];
    top_negative: StrengthWeakness[];
    priorities: string[];
    model_version: string | null;
    indicator_count: number;
    year: number | null;
}

// ── Utilities ───────────────────────────────────────────────────────

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('sv-SE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function formatPercentile(pctl: number): string {
    const suffix =
        [1, 2].includes(pctl % 10) && ![11, 12].includes(pctl % 100)
            ? ':a'
            : ':e';
    return `${pctl}${suffix}`;
}

function trendLabel(dir: string): string {
    switch (dir) {
        case 'improving':
            return 'Förbättras';
        case 'declining':
            return 'Försämras';
        case 'stable':
            return 'Stabil';
        default:
            return '\u2014';
    }
}

function groupBy<T>(arr: T[], key: keyof T): Record<string, T[]> {
    return arr.reduce(
        (acc, item) => {
            const k = String(item[key]);
            (acc[k] ??= []).push(item);
            return acc;
        },
        {} as Record<string, T[]>,
    );
}

const CATEGORY_ICONS: Record<string, typeof faShieldHalved> = {
    safety: faShieldHalved,
    economy: faChartColumn,
    education: faGraduationCap,
    environment: faTree,
    proximity: faLocationDot,
};

const CATEGORY_ORDER = ['safety', 'economy', 'education', 'environment'];

// Priority labels mapping for Swedish display
const PRIORITY_LABELS: Record<string, string> = {
    schools: 'Bra skolor',
    safety: 'Trygghet & säkerhet',
    green_areas: 'Grönområden & natur',
    shopping: 'Butiker & service',
    transit: 'Kollektivtrafik',
    healthcare: 'Sjukvård & vårdcentral',
    dining: 'Restauranger & matställen',
    quiet: 'Lugnt & fridfullt',
};

function formatPriorityLabel(key: string): string {
    return PRIORITY_LABELS[key] ?? key;
}

// ── Sub-components ──────────────────────────────────────────────────

function ReportNav({ uuid }: { uuid: string }) {
    const [copied, setCopied] = useState(false);

    const copyLink = async () => {
        await navigator.clipboard.writeText(
            `${window.location.origin}/reports/${uuid}`,
        );
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <nav className="sticky top-0 z-10 border-b bg-background/95 backdrop-blur print:hidden">
            <div className="mx-auto flex max-w-4xl items-center justify-between px-4 py-3">
                <Link
                    href="/"
                    className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
                >
                    <FontAwesomeIcon icon={faArrowLeft} className="h-3 w-3" />
                    Tillbaka till kartan
                </Link>
                <div className="flex gap-2">
                    <Button variant="outline" size="sm" onClick={copyLink}>
                        <FontAwesomeIcon
                            icon={copied ? faCheck : faLink}
                            className="mr-1.5 h-3 w-3"
                        />
                        {copied ? 'Kopierad!' : 'Kopiera länk'}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => window.print()}
                    >
                        <FontAwesomeIcon
                            icon={faPrint}
                            className="mr-1.5 h-3 w-3"
                        />
                        Skriv ut
                    </Button>
                </div>
            </div>
        </nav>
    );
}

function ReportHeader({ report }: { report: ReportData }) {
    const meta = report.deso_meta;
    return (
        <header className="space-y-1">
            <p className="text-sm font-medium uppercase tracking-wide text-muted-foreground">
                Områdesrapport
            </p>
            <h1 className="text-2xl font-bold">
                {report.address ?? 'Vald plats'}
            </h1>
            <p className="text-muted-foreground">
                {report.kommun_name} &middot; {report.lan_name}
            </p>
            {meta && (
                <p className="text-sm text-muted-foreground">
                    DeSO: {meta.deso_name ?? report.deso_code}
                    {meta.area_km2 != null && (
                        <>
                            {' \u00b7 '}
                            {meta.area_km2.toFixed(2).replace('.', ',')} km²
                        </>
                    )}
                    {meta.population != null && (
                        <>
                            {' \u00b7 '}
                            {meta.population.toLocaleString('sv-SE')} invånare
                        </>
                    )}
                </p>
            )}
            <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1 border-t pt-2 text-xs text-muted-foreground">
                <span>Genererad {formatDate(report.created_at)}</span>
                <span>&middot;</span>
                <span>Rapport {report.uuid.slice(0, 8)}</span>
                <span>&middot;</span>
                <span>{report.indicator_count} indikatorer</span>
                {report.year && (
                    <>
                        <span>&middot;</span>
                        <span>Dataår {report.year}</span>
                    </>
                )}
            </div>
        </header>
    );
}

function ReportHeroScore({ report }: { report: ReportData }) {
    const displayScore = report.personalized_score ?? report.default_score ?? report.score;
    if (displayScore == null) return null;

    const hasPersonalization =
        report.personalized_score != null && report.default_score != null;
    const diff = hasPersonalization
        ? report.personalized_score! - report.default_score!
        : 0;

    // Format priorities as Swedish labels
    const priorityLabels = report.priorities.map(formatPriorityLabel);

    return (
        <Card>
            <CardContent className="p-8">
                <div className="flex flex-col items-center gap-6 sm:flex-row sm:items-start">
                    {/* Score circle */}
                    <div
                        className="flex h-28 w-28 shrink-0 items-center justify-center rounded-full text-4xl font-bold text-white"
                        style={{ backgroundColor: scoreToColor(displayScore) }}
                    >
                        {Math.round(displayScore)}
                    </div>

                    <div className="space-y-2 text-center sm:text-left">
                        <p className="text-lg font-semibold">
                            {scoreToLabel(displayScore)}
                        </p>
                        {report.trend_1y != null && (
                            <p className="text-sm text-muted-foreground">
                                <TrendArrow
                                    change={report.trend_1y}
                                    direction="positive"
                                />{' '}
                                vs {(report.year ?? 2024) - 1}
                            </p>
                        )}

                        {report.score_history.length >= 2 && (
                            <Sparkline
                                values={report.score_history.map((h) => h.score)}
                                years={report.score_history.map((h) => h.year)}
                                width={200}
                                height={40}
                            />
                        )}
                    </div>
                </div>

                {report.priorities.length > 0 && (
                    <div className="mt-6 border-t pt-4">
                        <p className="text-sm font-medium text-muted-foreground">
                            Baserat på dina prioriteringar:
                        </p>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {priorityLabels.map((label) => (
                                <span
                                    key={label}
                                    className="inline-flex items-center rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary"
                                >
                                    {label}
                                </span>
                            ))}
                        </div>

                        {hasPersonalization && (
                            <div className="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                                {/* Personalized score badge */}
                                <div className="flex items-center gap-2">
                                    <div
                                        className="flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold text-white"
                                        style={{
                                            backgroundColor: scoreToColor(
                                                report.personalized_score!,
                                            ),
                                        }}
                                    >
                                        {Math.round(report.personalized_score!)}
                                    </div>
                                    <span className="text-sm">
                                        Din personliga poäng
                                    </span>
                                </div>

                                {/* Comparison arrow */}
                                {diff !== 0 && (
                                    <span
                                        className={`text-sm font-medium ${
                                            diff > 0
                                                ? 'text-green-600'
                                                : 'text-red-600'
                                        }`}
                                    >
                                        {diff > 0 ? '+' : ''}
                                        {diff.toFixed(0)} poäng
                                    </span>
                                )}

                                {/* Default score badge */}
                                <div className="flex items-center gap-2 text-muted-foreground">
                                    <div
                                        className="flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold text-white opacity-60"
                                        style={{
                                            backgroundColor: scoreToColor(
                                                report.default_score!,
                                            ),
                                        }}
                                    >
                                        {Math.round(report.default_score!)}
                                    </div>
                                    <span className="text-sm">
                                        Standardpoäng
                                    </span>
                                </div>
                            </div>
                        )}

                        {/* Show note when diff is 0 but priorities exist */}
                        {hasPersonalization && diff === 0 && (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Dina prioriteringar påverkar inte poängen för
                                detta område.
                            </p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ReportMap({
    mapData,
    score,
    reachabilityRings,
}: {
    mapData: MapSnapshot | null;
    score: number | null;
    reachabilityRings: ReachabilityRingsData | null;
}) {
    const mapRef = useRef<HTMLDivElement>(null);
    const tooltipRef = useRef<HTMLDivElement>(null);
    const mapInstanceRef = useRef<unknown>(null);
    const [tooltipContent, setTooltipContent] = useState<string | null>(null);

    useEffect(() => {
        if (!mapRef.current || !mapData || mapInstanceRef.current) return;

        // Lazy-load OpenLayers to avoid SSR issues
        Promise.all([
            import('ol/Map'),
            import('ol/View'),
            import('ol/layer/Tile'),
            import('ol/source/OSM'),
            import('ol/layer/Vector'),
            import('ol/source/Vector'),
            import('ol/Feature'),
            import('ol/geom/Point'),
            import('ol/geom/Polygon'),
            import('ol/style/Style'),
            import('ol/style/Fill'),
            import('ol/style/Stroke'),
            import('ol/style/Circle'),
            import('ol/style/Icon'),
            import('ol/proj'),
            import('ol/format/GeoJSON'),
            import('ol/Overlay'),
        ]).then(
            ([
                { default: OlMap },
                { default: View },
                { default: TileLayer },
                { default: OSM },
                { default: VectorLayer },
                { default: VectorSource },
                { default: Feature },
                { default: PointGeom },
                _polygon,
                { default: Style },
                { default: Fill },
                { default: Stroke },
                { default: CircleStyle },
                _icon,
                { fromLonLat },
                { default: GeoJSON },
                { default: Overlay },
            ]) => {
                if (!mapRef.current) return;

                const layers = [
                    new TileLayer({
                        source: new OSM({
                            url: 'https://{a-c}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                        }),
                    }),
                ];

                // Surrounding DeSOs
                if (mapData.surrounding_desos?.length) {
                    const surroundingFeatures = mapData.surrounding_desos
                        .filter((d) => d.geojson)
                        .map((d) => {
                            try {
                                return new GeoJSON().readFeature(
                                    { type: 'Feature', geometry: d.geojson, properties: {} },
                                    { featureProjection: 'EPSG:3857' },
                                );
                            } catch {
                                return null;
                            }
                        })
                        .filter(Boolean) as Feature[];

                    if (surroundingFeatures.length) {
                        layers.push(
                            new VectorLayer({
                                source: new VectorSource({
                                    features: surroundingFeatures,
                                }),
                                style: new Style({
                                    fill: new Fill({ color: 'rgba(229,229,229,0.3)' }),
                                    stroke: new Stroke({ color: '#d4d4d4', width: 1 }),
                                }),
                            }),
                        );
                    }
                }

                // Reachability rings (reverse order so inner rings render on top)
                if (reachabilityRings?.geojson?.features?.length) {
                    const ringFeatures = reachabilityRings.geojson.features
                        .slice()
                        .reverse()
                        .map((f) => {
                            try {
                                const feature = new GeoJSON().readFeature(f, {
                                    featureProjection: 'EPSG:3857',
                                });
                                // Preserve properties for styling
                                feature.setProperties(f.properties);
                                return feature;
                            } catch {
                                return null;
                            }
                        })
                        .filter(Boolean) as Feature[];

                    if (ringFeatures.length) {
                        layers.push(
                            new VectorLayer({
                                source: new VectorSource({
                                    features: ringFeatures,
                                }),
                                style: (feature) => {
                                    const props = feature.getProperties();
                                    const ringColor = props.color ?? '#3b82f6';
                                    const ringNumber = props.ring ?? 1;

                                    // Opacity decreases for outer rings
                                    const fillOpacity = ringNumber === 1 ? 0.15 : ringNumber === 2 ? 0.10 : 0.06;
                                    const strokeOpacity = ringNumber === 1 ? 0.6 : ringNumber === 2 ? 0.4 : 0.25;

                                    // Parse hex color to rgba
                                    const r = parseInt(ringColor.slice(1, 3), 16);
                                    const g = parseInt(ringColor.slice(3, 5), 16);
                                    const b = parseInt(ringColor.slice(5, 7), 16);

                                    return new Style({
                                        fill: new Fill({
                                            color: `rgba(${r}, ${g}, ${b}, ${fillOpacity})`,
                                        }),
                                        stroke: new Stroke({
                                            color: `rgba(${r}, ${g}, ${b}, ${strokeOpacity})`,
                                            width: ringNumber === 1 ? 2 : 1.5,
                                            lineDash: ringNumber >= 3 ? [6, 4] : undefined,
                                        }),
                                    });
                                },
                            }),
                        );
                    }
                }

                // Main DeSO polygon
                if (mapData.deso_geojson) {
                    try {
                        const desoFeature = new GeoJSON().readFeature(
                            { type: 'Feature', geometry: mapData.deso_geojson, properties: {} },
                            { featureProjection: 'EPSG:3857' },
                        );
                        const color = scoreToColor(score);
                        layers.push(
                            new VectorLayer({
                                source: new VectorSource({
                                    features: [desoFeature],
                                }),
                                style: new Style({
                                    fill: new Fill({
                                        color: color + '55',
                                    }),
                                    stroke: new Stroke({
                                        color: color,
                                        width: 2,
                                    }),
                                }),
                            }),
                        );
                    } catch {
                        // Skip if geometry parsing fails
                    }
                }

                // School markers
                if (mapData.school_markers?.length) {
                    const schoolFeatures = mapData.school_markers.map((s) => {
                        const f = new Feature({
                            geometry: new PointGeom(fromLonLat([s.lng, s.lat])),
                        });
                        f.setStyle(
                            new Style({
                                image: new CircleStyle({
                                    radius: 5,
                                    fill: new Fill({ color: '#3b82f6' }),
                                    stroke: new Stroke({ color: '#fff', width: 1.5 }),
                                }),
                            }),
                        );
                        return f;
                    });
                    layers.push(
                        new VectorLayer({
                            source: new VectorSource({ features: schoolFeatures }),
                        }),
                    );
                }

                // Pin
                const pinFeature = new Feature({
                    geometry: new PointGeom(
                        fromLonLat([mapData.pin[1], mapData.pin[0]]),
                    ),
                });
                pinFeature.setStyle(
                    new Style({
                        image: new CircleStyle({
                            radius: 7,
                            fill: new Fill({ color: '#ef4444' }),
                            stroke: new Stroke({ color: '#fff', width: 2 }),
                        }),
                    }),
                );
                layers.push(
                    new VectorLayer({
                        source: new VectorSource({ features: [pinFeature] }),
                    }),
                );

                // Create tooltip overlay
                let tooltipOverlay: InstanceType<typeof Overlay> | null = null;
                if (tooltipRef.current && reachabilityRings?.geojson?.features?.length) {
                    tooltipOverlay = new Overlay({
                        element: tooltipRef.current,
                        positioning: 'bottom-center',
                        offset: [0, -10],
                        stopEvent: false,
                    });
                }

                const map = new OlMap({
                    target: mapRef.current,
                    interactions: [],
                    controls: [],
                    layers,
                    view: new View({
                        center: fromLonLat([mapData.center[1], mapData.center[0]]),
                        zoom: mapData.zoom,
                    }),
                });

                // Add tooltip overlay if we have rings
                if (tooltipOverlay) {
                    map.addOverlay(tooltipOverlay);

                    // Add pointer move handler for ring tooltips
                    map.on('pointermove', (evt) => {
                        const pixel = map.getEventPixel(evt.originalEvent);
                        let foundRingFeature = false;

                        map.forEachFeatureAtPixel(pixel, (feature) => {
                            const props = feature.getProperties();
                            // Check if this is a ring feature (has ring and mode properties)
                            if (props.ring != null && props.mode != null) {
                                foundRingFeature = true;
                                const minutes = props.contour ?? props.ring * 5;
                                const modeText = props.mode === 'pedestrian' ? 'promenad' : 'körning';
                                const text = `Nåbart inom ${minutes} min ${modeText}`;
                                setTooltipContent(text);
                                tooltipOverlay?.setPosition(evt.coordinate);
                                return true; // Stop iterating
                            }
                            return false;
                        });

                        if (!foundRingFeature) {
                            setTooltipContent(null);
                            tooltipOverlay?.setPosition(undefined);
                        }
                    });
                }

                mapInstanceRef.current = map;
            },
        );

        return () => {
            if (mapInstanceRef.current) {
                (mapInstanceRef.current as { setTarget: (t: undefined) => void }).setTarget(undefined);
                mapInstanceRef.current = null;
            }
        };
    }, [mapData, score, reachabilityRings]);

    if (!mapData) return null;

    const hasRings = reachabilityRings?.geojson?.features?.length;

    return (
        <section className="relative">
            <div
                ref={mapRef}
                className="h-[300px] w-full overflow-hidden rounded-lg border"
                style={{ pointerEvents: hasRings ? 'auto' : 'none' }}
            />
            {/* Tooltip element for ring hover */}
            <div
                ref={tooltipRef}
                className={`pointer-events-none rounded bg-foreground/90 px-2 py-1 text-xs text-background shadow-lg transition-opacity ${
                    tooltipContent ? 'opacity-100' : 'opacity-0'
                }`}
            >
                {tooltipContent}
            </div>
        </section>
    );
}

function ReportVerdictGrid({
    verdicts,
}: {
    verdicts: Record<string, CategoryVerdict>;
}) {
    if (!verdicts || Object.keys(verdicts).length === 0) return null;

    return (
        <section>
            <h2 className="mb-4 text-lg font-semibold">
                Sammanfattning per kategori
            </h2>
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4 print:grid-cols-4">
                {CATEGORY_ORDER.map((key) => {
                    const v = verdicts[key];
                    if (!v) return null;
                    const icon = CATEGORY_ICONS[key];

                    return (
                        <Card key={key} className="p-4">
                            <div className="space-y-3">
                                <div className="flex items-center gap-2">
                                    {icon && (
                                        <FontAwesomeIcon
                                            icon={icon}
                                            className="h-3.5 w-3.5 text-muted-foreground"
                                        />
                                    )}
                                    <span className="text-sm font-medium">
                                        {v.label}
                                    </span>
                                </div>

                                <div className="flex items-center gap-3">
                                    <div
                                        className="flex h-10 w-10 items-center justify-center rounded-full text-sm font-bold text-white"
                                        style={{ backgroundColor: v.color }}
                                    >
                                        {v.grade}
                                    </div>
                                    <div>
                                        {v.score != null && (
                                            <p className="text-sm">
                                                {formatPercentile(v.score)} pctl
                                            </p>
                                        )}
                                        <p className="text-xs text-muted-foreground">
                                            {trendLabel(v.trend_direction)}
                                        </p>
                                    </div>
                                </div>

                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    {v.verdict_sv}
                                </p>

                                <p className="text-[10px] text-muted-foreground">
                                    {v.indicator_count} indikatorer
                                </p>
                            </div>
                        </Card>
                    );
                })}
            </div>
        </section>
    );
}

function ReportIndicatorBreakdown({
    indicators,
    verdicts,
    nationalRefs,
}: {
    indicators: SnapshotIndicator[];
    verdicts: Record<string, CategoryVerdict>;
    nationalRefs: Record<string, { median: number | null; formatted: string | null }>;
}) {
    if (!indicators.length) return null;

    const grouped = groupBy(indicators, 'category');

    return (
        <section className="space-y-8">
            <h2 className="text-lg font-semibold">
                Detaljerad indikatoranalys
            </h2>

            {CATEGORY_ORDER.map((cat) => {
                const catIndicators = grouped[cat];
                if (!catIndicators?.length) return null;

                const catVerdict = verdicts[cat];
                const catLabel =
                    catVerdict?.label ??
                    cat.charAt(0).toUpperCase() + cat.slice(1);

                return (
                    <div key={cat} className="space-y-3">
                        <div className="flex items-center justify-between border-b pb-2">
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                {catLabel}
                            </h3>
                            {catVerdict && (
                                <span
                                    className="rounded-full px-2 py-0.5 text-xs font-medium text-white"
                                    style={{
                                        backgroundColor: catVerdict.color,
                                    }}
                                >
                                    Betyg: {catVerdict.grade}
                                </span>
                            )}
                        </div>

                        {catIndicators.map((ind) => (
                            <ReportIndicatorRow
                                key={ind.slug}
                                indicator={ind}
                                nationalRef={nationalRefs[ind.slug]}
                            />
                        ))}
                    </div>
                );
            })}
        </section>
    );
}

function ReportIndicatorRow({
    indicator,
    nationalRef,
}: {
    indicator: SnapshotIndicator;
    nationalRef?: { median: number | null; formatted: string | null };
}) {
    const effectivePct =
        indicator.direction === 'negative'
            ? 100 - indicator.percentile
            : indicator.percentile;

    return (
        <div className="space-y-1 py-2">
            {/* Row 1: Name + bar + percentile + trend + value */}
            <div className="flex items-center gap-3">
                <span className="w-44 shrink-0 text-sm">{indicator.name}</span>
                <div className="flex-1">
                    <PercentileBar effectivePct={effectivePct} />
                </div>
                <span className="w-14 shrink-0 text-right text-sm tabular-nums">
                    {formatPercentile(indicator.percentile)}
                </span>
                <div className="w-14 shrink-0">
                    <TrendArrow
                        change={indicator.trend.change_1y}
                        direction={indicator.direction}
                    />
                </div>
                <span className="w-24 shrink-0 text-right text-sm tabular-nums text-muted-foreground">
                    {indicator.formatted_value}
                </span>
            </div>

            {/* Row 2: Sparkline + negative indicator note */}
            <div className="flex items-center gap-3 pl-44">
                {indicator.trend.percentiles.length >= 2 && (
                    <Sparkline
                        values={indicator.trend.percentiles}
                        years={indicator.trend.years}
                        width={160}
                        height={24}
                    />
                )}
                {indicator.direction === 'negative' && (
                    <span className="text-[10px] italic text-muted-foreground">
                        Lägre = bättre för området
                    </span>
                )}
            </div>

            {/* Row 3: National reference */}
            {nationalRef?.formatted && (
                <p className="pl-44 text-[10px] text-muted-foreground">
                    Riksgenomsnitt: {nationalRef.formatted}
                </p>
            )}
        </div>
    );
}

function ReportSchoolSection({ schools }: { schools: SchoolData[] }) {
    if (!schools.length) {
        return (
            <section>
                <h2 className="mb-4 text-lg font-semibold">
                    Skolor i närheten
                </h2>
                <p className="text-sm text-muted-foreground">
                    Inga skolor inom 2 km från den valda platsen.
                </p>
            </section>
        );
    }

    return (
        <section className="print:break-before-page">
            <h2 className="mb-4 text-lg font-semibold">
                Skolor i närheten ({schools.length} inom 2 km)
            </h2>
            <div className="space-y-3">
                {schools.map((school) => (
                    <Card key={`${school.name}-${school.distance_m}`} className="p-4">
                        <div className="space-y-3">
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <FontAwesomeIcon
                                            icon={faGraduationCap}
                                            className="h-3.5 w-3.5 text-blue-500"
                                        />
                                        <span className="font-medium">
                                            {school.name}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        {school.type ?? 'Grundskola'}
                                        {school.operator_type &&
                                            ` \u00b7 ${school.operator_type}`}
                                    </p>
                                </div>
                                <span className="shrink-0 text-sm font-medium text-muted-foreground">
                                    {school.distance_m < 1000
                                        ? `${school.distance_m} m`
                                        : `${(school.distance_m / 1000).toFixed(1)} km`}
                                </span>
                            </div>

                            <div className="grid grid-cols-3 gap-4 text-sm">
                                <SchoolStat
                                    label="Meritvärde"
                                    value={
                                        school.merit_value != null
                                            ? Math.round(school.merit_value).toString()
                                            : null
                                    }
                                    note={school.merit_value == null ? 'Ej åk 9' : undefined}
                                />
                                <SchoolStat
                                    label="Måluppfyllelse"
                                    value={
                                        school.goal_achievement != null
                                            ? `${school.goal_achievement.toFixed(0)} %`
                                            : null
                                    }
                                    note={school.goal_achievement == null ? 'Ej åk 9' : undefined}
                                />
                                <SchoolStat
                                    label="Lärarbehörighet"
                                    value={
                                        school.teacher_certification != null
                                            ? `${school.teacher_certification.toFixed(0)} %`
                                            : null
                                    }
                                />
                            </div>

                            {school.student_count && (
                                <p className="text-xs text-muted-foreground">
                                    Antal elever: {school.student_count}
                                </p>
                            )}
                        </div>
                    </Card>
                ))}
            </div>
        </section>
    );
}

function SchoolStat({
    label,
    value,
    note,
}: {
    label: string;
    value: string | null;
    note?: string;
}) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            {value != null ? (
                <p className="font-medium">{value}</p>
            ) : (
                <p className="text-muted-foreground">
                    &mdash;{' '}
                    {note && (
                        <span className="text-[10px] italic">({note})</span>
                    )}
                </p>
            )}
        </div>
    );
}

// ── POI Item type for categorization ────────────────────────────────
interface POIItem {
    name: string;
    category: string;
    icon: typeof faLocationDot;
    travelMinutes: number | null;
    distanceM: number | null;
    ringNumber: number; // 1, 2, 3, or 4 (beyond)
}

const POI_CATEGORY_CONFIG: Record<
    string,
    { label: string; icon: typeof faLocationDot }
> = {
    school: { label: 'Skola', icon: faGraduationCap },
    green_space: { label: 'Grönområde', icon: faTree },
    transit: { label: 'Kollektivtrafik', icon: faBus },
    grocery: { label: 'Matbutik', icon: faCartShopping },
    positive_poi: { label: 'Service', icon: faLocationDot },
    negative_poi: { label: 'Störning', icon: faTriangleExclamation },
};

function assignToRing(
    travelMinutes: number | null,
    rings: RingDefinition[],
): number {
    if (travelMinutes == null || rings.length === 0) return 4; // Beyond

    // Sort rings by minutes ascending
    const sortedRings = [...rings].sort((a, b) => a.minutes - b.minutes);

    for (const ring of sortedRings) {
        if (travelMinutes <= ring.minutes) {
            return ring.ring;
        }
    }

    return 4; // Beyond all rings
}

function extractPOIItems(
    schools: SchoolData[],
    proximityFactors: ProximityFactors | null,
    rings: RingDefinition[],
): POIItem[] {
    const items: POIItem[] = [];

    // Add schools
    for (const school of schools) {
        // Estimate travel time from distance (assume ~80m/min walking)
        const estimatedMinutes = school.distance_m / 80;
        const ringNumber = assignToRing(estimatedMinutes, rings);

        items.push({
            name: school.name,
            category: 'school',
            icon: faGraduationCap,
            travelMinutes: Math.round(estimatedMinutes * 10) / 10,
            distanceM: school.distance_m,
            ringNumber,
        });
    }

    // Add POIs from proximity factors
    if (proximityFactors?.factors) {
        for (const factor of proximityFactors.factors) {
            const config = POI_CATEGORY_CONFIG[factor.slug.replace('prox_', '')];
            if (!config) continue;

            // Skip schools (already handled above) and negative POIs
            if (factor.slug === 'prox_school' || factor.slug === 'prox_negative_poi') {
                continue;
            }

            const details = factor.details;
            const travelMinutes = details.travel_minutes ?? null;
            const distanceM = details.nearest_distance_m ?? details.distance_m ?? null;

            // Get the nearest item name
            let name =
                details.nearest_park ??
                details.nearest_store ??
                details.nearest_stop ??
                details.nearest ??
                config.label;

            // Skip if no valid data
            if (factor.score === 0 || details.message) continue;

            const ringNumber = assignToRing(travelMinutes, rings);

            items.push({
                name,
                category: factor.slug.replace('prox_', ''),
                icon: config.icon,
                travelMinutes,
                distanceM: typeof distanceM === 'number' ? distanceM : null,
                ringNumber,
            });
        }
    }

    return items;
}

function ReportPOICategorization({
    schools,
    proximityFactors,
    reachabilityRings,
}: {
    schools: SchoolData[];
    proximityFactors: ProximityFactors | null;
    reachabilityRings: ReachabilityRingsData | null;
}) {
    // If no rings data, don't show the section
    if (!reachabilityRings?.rings?.length) return null;

    const rings = reachabilityRings.rings;
    const poiItems = extractPOIItems(schools, proximityFactors, rings);

    if (poiItems.length === 0) return null;

    // Group items by ring number
    const byRing: Record<number, POIItem[]> = { 1: [], 2: [], 3: [], 4: [] };
    for (const item of poiItems) {
        byRing[item.ringNumber].push(item);
    }

    // Get ring labels
    const ringLabels: Record<number, { label: string; mode: 'pedestrian' | 'auto' }> = {
        4: { label: 'Utom räckhåll', mode: 'pedestrian' },
    };
    for (const ring of rings) {
        ringLabels[ring.ring] = { label: ring.label, mode: ring.mode };
    }

    // Define ring colors
    const ringColors: Record<number, string> = {
        1: '#22c55e', // green
        2: '#3b82f6', // blue
        3: '#8b5cf6', // purple
        4: '#94a3b8', // gray
    };

    return (
        <section>
            <div className="mb-3 flex items-center gap-2">
                <FontAwesomeIcon
                    icon={faLocationDot}
                    className="h-4 w-4 text-muted-foreground"
                />
                <h2 className="text-lg font-semibold">Närhet &amp; tillgänglighet</h2>
            </div>
            <p className="mb-4 text-sm text-muted-foreground">
                Platser i närheten grupperade efter hur snabbt du når dem.
            </p>

            <div className="space-y-6">
                {[1, 2, 3, 4].map((ringNum) => {
                    const ringItems = byRing[ringNum];
                    if (ringItems.length === 0) return null;

                    const ringInfo = ringLabels[ringNum];
                    const ringColor = ringColors[ringNum];

                    return (
                        <div key={ringNum} className="space-y-2">
                            <div className="flex items-center gap-2">
                                <div
                                    className="h-3 w-3 rounded-full"
                                    style={{ backgroundColor: ringColor }}
                                />
                                <h3 className="text-sm font-medium">
                                    {ringNum === 4 ? 'Utom räckhåll' : `Ring ${ringNum}`}
                                </h3>
                                {ringInfo && ringNum !== 4 && (
                                    <span className="flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                        <FontAwesomeIcon
                                            icon={
                                                ringInfo.mode === 'pedestrian'
                                                    ? faPersonWalking
                                                    : faCar
                                            }
                                            className="h-2.5 w-2.5"
                                        />
                                        {ringInfo.label.replace('Nåbart inom ', '').replace(' promenad', '').replace(' bil', '')}
                                    </span>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                {ringItems.map((item, idx) => (
                                    <Card key={`${item.category}-${idx}`} className="p-3">
                                        <div className="flex items-start gap-3">
                                            <div
                                                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                                style={{
                                                    backgroundColor: `${ringColor}20`,
                                                    color: ringColor,
                                                }}
                                            >
                                                <FontAwesomeIcon
                                                    icon={item.icon}
                                                    className="h-3.5 w-3.5"
                                                />
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">
                                                    {item.name}
                                                </p>
                                                <div className="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground">
                                                    <span>
                                                        {POI_CATEGORY_CONFIG[item.category]?.label ??
                                                            item.category}
                                                    </span>
                                                    {item.travelMinutes != null && (
                                                        <>
                                                            <span>&middot;</span>
                                                            <span className="flex items-center gap-1">
                                                                <FontAwesomeIcon
                                                                    icon={faClock}
                                                                    className="h-2.5 w-2.5"
                                                                />
                                                                {item.travelMinutes < 1
                                                                    ? '<1'
                                                                    : Math.round(item.travelMinutes)}{' '}
                                                                min
                                                            </span>
                                                        </>
                                                    )}
                                                    {item.distanceM != null && (
                                                        <>
                                                            <span>&middot;</span>
                                                            <span>
                                                                {item.distanceM < 1000
                                                                    ? `${Math.round(item.distanceM)} m`
                                                                    : `${(item.distanceM / 1000).toFixed(1)} km`}
                                                            </span>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </Card>
                                ))}
                            </div>
                        </div>
                    );
                })}
            </div>
        </section>
    );
}

function ReportStrengthsWeaknesses({
    positive,
    negative,
}: {
    positive: StrengthWeakness[];
    negative: StrengthWeakness[];
}) {
    if (!positive.length && !negative.length) return null;

    return (
        <section className="grid gap-6 md:grid-cols-2">
            {/* Strengths */}
            <div>
                <h2 className="mb-3 text-lg font-semibold">Styrkor</h2>
                {positive.length > 0 ? (
                    <ul className="space-y-2">
                        {positive.map((item) => (
                            <li
                                key={item.slug}
                                className="flex items-start gap-2 text-sm"
                            >
                                <FontAwesomeIcon
                                    icon={faCircleCheck}
                                    className="mt-0.5 h-4 w-4 shrink-0 text-green-600"
                                />
                                <span>{item.text_sv}</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Inga tydliga styrkor identifierade.
                    </p>
                )}
            </div>

            {/* Weaknesses */}
            <div>
                <h2 className="mb-3 text-lg font-semibold">Svagheter</h2>
                {negative.length > 0 ? (
                    <ul className="space-y-2">
                        {negative.map((item) => (
                            <li
                                key={item.slug}
                                className="flex items-start gap-2 text-sm"
                            >
                                <FontAwesomeIcon
                                    icon={faTriangleExclamation}
                                    className="mt-0.5 h-4 w-4 shrink-0 text-amber-500"
                                />
                                <span>{item.text_sv}</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Inga tydliga svagheter identifierade.
                    </p>
                )}
            </div>
        </section>
    );
}

function ReportOutlook({ outlook }: { outlook: OutlookData | null }) {
    if (!outlook) return null;

    const outlookColor: Record<string, string> = {
        strong_positive: '#1a7a2e',
        positive: '#27ae60',
        neutral: '#94a3b8',
        cautious: '#f39c12',
        negative: '#e74c3c',
    };

    return (
        <section>
            <div className="mb-3 flex items-center gap-2">
                <FontAwesomeIcon
                    icon={faBinoculars}
                    className="h-4 w-4 text-muted-foreground"
                />
                <h2 className="text-lg font-semibold">Utsikter</h2>
                <span
                    className="rounded-full px-2 py-0.5 text-xs font-medium text-white"
                    style={{
                        backgroundColor:
                            outlookColor[outlook.outlook] ?? '#94a3b8',
                    }}
                >
                    {outlook.outlook_label}
                </span>
            </div>
            <Card>
                <CardContent className="p-6">
                    <p className="text-sm leading-relaxed">
                        {outlook.text_sv}
                    </p>
                    <p className="mt-4 border-t pt-3 text-xs italic text-muted-foreground">
                        {outlook.disclaimer}
                    </p>
                </CardContent>
            </Card>
        </section>
    );
}

function ReportMethodology({ report }: { report: ReportData }) {
    const sources = [
        { name: 'SCB', indicators: 8, data: report.year ?? 2024 },
        { name: 'Skolverket', indicators: 3, data: '2024/25' },
        { name: 'BRÅ', indicators: 3, data: report.year ?? 2024 },
        { name: 'Kolada', indicators: 3, data: report.year ?? 2024 },
        { name: 'OpenStreetMap', indicators: 8, data: 'Feb 2026' },
        { name: 'NTU', indicators: 1, data: report.year ?? 2024 },
        { name: 'Polisen', indicators: 1, data: 2025 },
    ];

    return (
        <section>
            <div className="mb-3 flex items-center gap-2">
                <FontAwesomeIcon
                    icon={faFileLines}
                    className="h-4 w-4 text-muted-foreground"
                />
                <h2 className="text-lg font-semibold">
                    Metod &amp; datakällor
                </h2>
            </div>
            <Card>
                <CardContent className="space-y-4 p-6">
                    <p className="text-sm leading-relaxed">
                        Denna rapport bygger på {report.indicator_count}{' '}
                        indikatorer från {sources.length} offentliga
                        datakällor, aggregerade till DeSO-nivå (demografiska
                        statistikområden).
                    </p>

                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b text-left text-xs text-muted-foreground">
                                <th className="pb-2">Källa</th>
                                <th className="pb-2 text-right">
                                    Indikatorer
                                </th>
                                <th className="pb-2 text-right">
                                    Senaste data
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {sources.map((s) => (
                                <tr key={s.name} className="border-b last:border-0">
                                    <td className="py-1.5">{s.name}</td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {s.indicators}
                                    </td>
                                    <td className="py-1.5 text-right tabular-nums">
                                        {s.data}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    <div className="space-y-2 text-xs text-muted-foreground">
                        <p>
                            Poängen beräknas som ett viktat genomsnitt av
                            percentilrankning per indikator. Områdespoäng (70 %)
                            baseras på DeSO-aggregerade data. Närhetspoäng
                            (30 %) baseras på avstånd till specifika platser
                            från den exakta adressen.
                        </p>
                        <p>
                            All data är offentlig statistik på aggregerad nivå.
                            Inga personuppgifter eller individuella data lagras
                            eller behandlas.
                        </p>
                        {report.model_version && (
                            <p>Beräkningsmodell: {report.model_version}</p>
                        )}
                    </div>
                </CardContent>
            </Card>
        </section>
    );
}

// ── Main Page ───────────────────────────────────────────────────────

export default function ReportShow({ report }: { report: ReportData }) {
    const hasSnapshot = report.area_indicators.length > 0;

    return (
        <div className="min-h-screen bg-muted/30 print:bg-white">
            <Head
                title={`Rapport \u2014 ${report.address ?? report.kommun_name ?? 'Område'}`}
            />

            <ReportNav uuid={report.uuid} />

            <main className="mx-auto max-w-4xl space-y-8 px-4 py-8 print:max-w-none print:space-y-4 print:px-0">
                {/* 1. Header */}
                <ReportHeader report={report} />

                {/* 2. Hero Score */}
                <ReportHeroScore report={report} />

                {/* 3. Map Snapshot */}
                <ReportMap
                    mapData={report.map_snapshot}
                    score={report.default_score ?? report.score}
                    reachabilityRings={report.reachability_rings}
                />

                {hasSnapshot ? (
                    <>
                        {/* 4. Category Verdicts */}
                        <ReportVerdictGrid verdicts={report.category_verdicts} />

                        {/* 5. Full Indicator Breakdown */}
                        <ReportIndicatorBreakdown
                            indicators={report.area_indicators}
                            verdicts={report.category_verdicts}
                            nationalRefs={report.national_references}
                        />

                        {/* 6. Schools */}
                        <ReportSchoolSection schools={report.schools} />

                        {/* 6b. POI Categorization by Ring */}
                        <ReportPOICategorization
                            schools={report.schools}
                            proximityFactors={report.proximity_factors}
                            reachabilityRings={report.reachability_rings}
                        />

                        {/* 7. Strengths & Weaknesses */}
                        <ReportStrengthsWeaknesses
                            positive={report.top_positive}
                            negative={report.top_negative}
                        />

                        {/* 8. Outlook */}
                        <ReportOutlook outlook={report.outlook} />

                        {/* 9. Methodology */}
                        <ReportMethodology report={report} />
                    </>
                ) : (
                    <Card>
                        <CardContent className="p-8 text-center">
                            <p className="text-muted-foreground">
                                Den fullständiga rapporten med detaljerade
                                indikatorer, skolanalys och närhetsanalys
                                genereras...
                            </p>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Du kommer att få ett e-postmeddelande när
                                rapporten är komplett.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Footer */}
                <footer className="mt-12 border-t pt-6 print:hidden">
                    <p className="text-center text-[10px] text-muted-foreground">
                        Alla data är hämtade från offentliga svenska myndigheter och öppna datakällor.
                    </p>
                    <div className="mt-4 space-y-2 text-center text-xs text-muted-foreground">
                        <p>
                            Rapport-ID: {report.uuid} &middot; Visningar:{' '}
                            {report.view_count}
                        </p>
                        <Link
                            href={`/explore/${report.lat},${report.lng}`}
                            className="text-sm font-medium text-primary hover:underline"
                        >
                            Visa på kartan &rarr;
                        </Link>
                    </div>
                </footer>
            </main>
        </div>
    );
}
