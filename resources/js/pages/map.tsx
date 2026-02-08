import { Head } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    Bus,
    GraduationCap,
    Loader2,
    MapPin,
    Search,
    ShoppingCart,
    ShieldAlert,
    Sparkles,
    TreePine,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import HeatmapMap, {
    type HeatmapMapHandle,
    type PoiCategoryMeta,
    type PoiMarker,
    type SchoolMarker,
    interpolateScoreColor,
} from '@/components/deso-map';
import { type IndicatorMeta, InfoTooltip } from '@/components/info-tooltip';
import MapSearch from '@/components/map-search';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import MapLayout from '@/layouts/map-layout';
import {
    type SearchResult,
    getZoomForType,
} from '@/services/geocoding';

interface MapPageProps {
    initialCenter: [number, number];
    initialZoom: number;
    indicatorScopes: Record<string, 'national' | 'urbanity_stratified'>;
    indicatorMeta: Record<string, IndicatorMeta>;
    userTier: number;
    isAuthenticated: boolean;
}

interface ProximityFactor {
    slug: string;
    score: number | null;
    details: Record<string, unknown>;
}

interface ProximityData {
    composite: number;
    factors: ProximityFactor[];
}

interface LocationData {
    location: {
        lat: number;
        lng: number;
        deso_code: string;
        kommun: string;
        lan_code: string;
        area_km2: number;
        urbanity_tier: string | null;
    };
    score: {
        value: number;
        area_score: number | null;
        proximity_score: number;
        trend_1y: number | null;
        label: string;
        top_positive: string[] | null;
        top_negative: string[] | null;
        factor_scores: Record<string, number> | null;
    } | null;
    tier: number;
    proximity: ProximityData | null;
    indicators: Array<{
        slug: string;
        name: string;
        raw_value: number;
        normalized_value: number;
        unit: string | null;
        direction: 'positive' | 'negative' | 'neutral';
        category: string | null;
        normalization_scope: 'national' | 'urbanity_stratified';
    }>;
    schools: SchoolMarker[];
    pois: PoiMarker[];
    poi_categories: Record<string, PoiCategoryMeta>;
}

function formatIndicatorValue(value: number, unit: string | null): string {
    if (unit === '%') return `${value.toFixed(1)}%`;
    if (unit === 'SEK') return `${Math.round(value).toLocaleString()} SEK`;
    if (unit === '/100k') return `${value.toFixed(1)}/100k`;
    if (unit === '/1000') return `${value.toFixed(2)}/1000`;
    return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
}

function formatDistance(meters: number): string {
    if (meters < 1000) return `${meters}m`;
    return `${(meters / 1000).toFixed(1)}km`;
}

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

function IndicatorBar({
    indicator,
    scope,
    urbanityTier,
    meta,
}: {
    indicator: LocationData['indicators'][0];
    scope: 'national' | 'urbanity_stratified';
    urbanityTier: string | null;
    meta?: IndicatorMeta;
}) {
    const { t } = useTranslation();
    const rawPct = Math.round(indicator.normalized_value * 100);
    const effectivePct = indicator.direction === 'negative' ? 100 - rawPct : rawPct;
    const isStratified = scope === 'urbanity_stratified' && urbanityTier;
    const tierLabel = urbanityTier
        ? t(`sidebar.urbanity.${urbanityTier}`)
        : '';

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-xs">
                <span className="flex items-center gap-1 font-medium text-foreground">
                    {indicator.name}
                    {meta && <InfoTooltip indicator={meta} />}
                </span>
                <span className="tabular-nums font-semibold text-foreground">
                    {isStratified
                        ? t('sidebar.indicators.percentile_stratified', { value: effectivePct, tier: tierLabel })
                        : t('sidebar.indicators.percentile_national', { value: effectivePct })}
                </span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full transition-all"
                    style={{ width: `${effectivePct}%`, ...scoreBgStyle(effectivePct) }}
                />
            </div>
            <div className="text-[11px] text-muted-foreground">
                ({formatIndicatorValue(indicator.raw_value, indicator.unit)})
            </div>
        </div>
    );
}

const PROXIMITY_FACTOR_CONFIG: Record<string, {
    icon: typeof GraduationCap;
    nameKey: string;
    detailKey: 'nearest_school' | 'nearest_park' | 'nearest_stop' | 'nearest_store' | 'nearest' | 'count';
    distanceKey: string;
}> = {
    prox_school: { icon: GraduationCap, nameKey: 'sidebar.proximity.school', detailKey: 'nearest_school', distanceKey: 'nearest_distance_m' },
    prox_green_space: { icon: TreePine, nameKey: 'sidebar.proximity.green_space', detailKey: 'nearest_park', distanceKey: 'distance_m' },
    prox_transit: { icon: Bus, nameKey: 'sidebar.proximity.transit', detailKey: 'nearest_stop', distanceKey: 'nearest_distance_m' },
    prox_grocery: { icon: ShoppingCart, nameKey: 'sidebar.proximity.grocery', detailKey: 'nearest_store', distanceKey: 'distance_m' },
    prox_negative_poi: { icon: ShieldAlert, nameKey: 'sidebar.proximity.negative_poi', detailKey: 'count', distanceKey: 'nearest_distance_m' },
    prox_positive_poi: { icon: Sparkles, nameKey: 'sidebar.proximity.positive_poi', detailKey: 'count', distanceKey: '' },
};

function ProximityFactorRow({ factor }: { factor: ProximityFactor }) {
    const { t } = useTranslation();
    const config = PROXIMITY_FACTOR_CONFIG[factor.slug];
    if (!config || factor.score === null) return null;

    const Icon = config.icon;
    const score = factor.score;
    const details = factor.details;

    // For negative POI, 100 = good (no negatives nearby)
    const isNegativeType = factor.slug === 'prox_negative_poi';
    const displayName = details.nearest_school
        ?? details.nearest_park
        ?? details.nearest_stop
        ?? details.nearest_store
        ?? details.nearest
        ?? null;
    const distanceM = config.distanceKey ? (details[config.distanceKey] as number | undefined) : undefined;

    // Special label for negative/positive POI counts
    let subtitle: string | null = null;
    if (factor.slug === 'prox_negative_poi') {
        const count = (details.count as number) ?? 0;
        subtitle = count === 0
            ? t('sidebar.proximity.no_negative')
            : `${count} ${t('sidebar.proximity.negative_count')}`;
    } else if (factor.slug === 'prox_positive_poi') {
        const count = (details.count as number) ?? 0;
        subtitle = `${count} ${t('sidebar.proximity.positive_count')}`;
    } else if (displayName) {
        subtitle = String(displayName);
    }

    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between text-xs">
                <span className="flex items-center gap-1.5 font-medium text-foreground">
                    <Icon className="h-3.5 w-3.5 text-muted-foreground" />
                    {t(config.nameKey)}
                </span>
                <span className="tabular-nums font-semibold text-foreground">{score}</span>
            </div>
            <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full transition-all"
                    style={{
                        width: `${score}%`,
                        ...scoreBgStyle(isNegativeType ? score : score),
                    }}
                />
            </div>
            {(subtitle || distanceM !== undefined) && (
                <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                    <span className="truncate">{subtitle}</span>
                    {distanceM !== undefined && distanceM > 0 && (
                        <span className="ml-2 shrink-0 tabular-nums">{formatDistance(distanceM)}</span>
                    )}
                </div>
            )}
        </div>
    );
}

function DefaultSidebar({ onTrySearch }: { onTrySearch: (query: string) => void }) {
    const { t } = useTranslation();

    const suggestions = [
        'Sveavägen, Stockholm',
        'Kungsbacka',
        'Lomma',
    ];

    return (
        <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
            <MapPin className="mb-3 h-8 w-8 text-muted-foreground" />
            <h3 className="mb-1 text-sm font-semibold text-foreground">
                {t('sidebar.default.title')}
            </h3>
            <p className="mb-6 text-xs text-muted-foreground">
                {t('sidebar.default.subtitle')}
            </p>
            <div className="w-full space-y-2">
                {suggestions.map((s) => (
                    <button
                        key={s}
                        onClick={() => onTrySearch(s)}
                        className="w-full rounded-md border border-border px-3 py-2 text-left text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                    >
                        {t('sidebar.default.try')}: {s}
                    </button>
                ))}
            </div>
            <p className="mt-6 text-[11px] text-muted-foreground">
                {t('sidebar.default.legend_hint')}
            </p>
        </div>
    );
}

function ActiveSidebar({
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
    const getScoreLabel = useScoreLabel();

    if (loading) {
        return (
            <div className="flex items-center justify-center py-20">
                <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
        );
    }

    const { location, score, proximity, indicators, schools, pois, poi_categories, tier } = data;
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
                        <X className="h-4 w-4" />
                    </button>
                </div>

                {/* Score card with area/proximity breakdown */}
                {score && (
                    <div className="mb-4 rounded-lg border border-border p-3">
                        <div className="flex items-center gap-3">
                            <div
                                className="flex h-14 w-14 flex-col items-center justify-center rounded-lg text-white"
                                style={scoreBgStyle(score.value)}
                            >
                                <span className="text-lg font-bold leading-tight">
                                    {score.value}
                                </span>
                                {score.trend_1y !== null && score.trend_1y !== 0 && (
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
                                {location.urbanity_tier && (
                                    <div className="text-[11px] text-muted-foreground capitalize">
                                        {t(`sidebar.urbanity.${location.urbanity_tier}`)}
                                    </div>
                                )}
                                {/* Area vs Proximity breakdown */}
                                {score.area_score !== null && (
                                    <div className="mt-1 flex gap-3 text-[11px] tabular-nums text-muted-foreground">
                                        <span>
                                            {t('sidebar.proximity.area_label')}: {score.area_score}
                                        </span>
                                        <span>
                                            {t('sidebar.proximity.location_label')}: {score.proximity_score}
                                        </span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}

                {/* Public CTA - show when no detail data */}
                {isPublicTier && (
                    <div className="rounded-lg border border-primary/20 bg-primary/5 p-4 text-center">
                        <Search className="mx-auto mb-2 h-5 w-5 text-primary" />
                        <p className="mb-1 text-sm font-semibold text-foreground">
                            {t('sidebar.cta.title')}
                        </p>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {t('sidebar.cta.subtitle')}
                        </p>
                        <a
                            href="/login"
                            className="inline-block rounded-md bg-primary px-4 py-2 text-xs font-medium text-primary-foreground transition-colors hover:bg-primary/90"
                        >
                            {t('sidebar.cta.button')}
                        </a>
                    </div>
                )}

                {/* Proximity Analysis (paid tiers only) */}
                {!isPublicTier && proximity && proximity.factors.length > 0 && (
                    <>
                        <Separator className="my-3" />
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {t('sidebar.proximity.title')}
                        </h3>
                        <div className="space-y-3">
                            {proximity.factors.map((factor) => (
                                <ProximityFactorRow key={factor.slug} factor={factor} />
                            ))}
                        </div>
                    </>
                )}

                {/* Indicators (paid tiers only) */}
                {!isPublicTier && indicators.length > 0 && (
                    <>
                        <Separator className="my-3" />
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {t('sidebar.indicators.title')}
                        </h3>
                        <div className="space-y-3">
                            {indicators.map((ind) => (
                                <IndicatorBar
                                    key={ind.slug}
                                    indicator={ind}
                                    scope={indicatorScopes[ind.slug] ?? 'national'}
                                    urbanityTier={location.urbanity_tier}
                                    meta={indicatorMeta[ind.slug]}
                                />
                            ))}
                        </div>
                    </>
                )}

                {/* Schools (paid tiers only) */}
                {!isPublicTier && schools.length > 0 && (
                    <>
                        <Separator className="my-3" />
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            <GraduationCap className="mr-1 inline h-3.5 w-3.5" />
                            {t('sidebar.schools.title')} ({schools.length}{' '}
                            {t('sidebar.schools.within')})
                        </h3>
                        <div className="space-y-2.5">
                            {schools.map((school, i) => (
                                <div key={i} className="rounded-md border border-border p-2.5">
                                    <div className="flex items-start justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-medium text-foreground">
                                                {school.name}
                                            </div>
                                            <div className="text-[11px] text-muted-foreground">
                                                {school.type ?? 'Grundskola'}
                                                {school.operator_type && (
                                                    <> &middot; {school.operator_type === 'KOMMUN' ? 'Kommunal' : 'Fristående'}</>
                                                )}
                                            </div>
                                        </div>
                                        <span className="ml-2 shrink-0 text-xs tabular-nums text-muted-foreground">
                                            {school.distance_m < 1000
                                                ? `${school.distance_m}m`
                                                : `${(school.distance_m / 1000).toFixed(1)}km`}
                                        </span>
                                    </div>
                                    {school.merit_value !== null && (
                                        <div className="mt-1 text-[11px] text-muted-foreground">
                                            {t('sidebar.schools.merit')}: {school.merit_value}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </>
                )}

                {/* POIs (paid tiers only) */}
                {!isPublicTier && pois.length > 0 && (
                    <>
                        <Separator className="my-3" />
                        <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {t('sidebar.pois.title')}
                        </h3>
                        <div className="space-y-1.5">
                            {Object.entries(
                                pois.reduce<Record<string, number>>((acc, p) => {
                                    acc[p.category] = (acc[p.category] || 0) + 1;
                                    return acc;
                                }, {}),
                            ).map(([category, count]) => (
                                <div key={category} className="flex items-center justify-between text-xs">
                                    <span className="flex items-center gap-1.5">
                                        <span
                                            className="inline-block h-2.5 w-2.5 rounded-full"
                                            style={{ backgroundColor: poi_categories[category]?.color ?? '#94a3b8' }}
                                        />
                                        <span className="text-foreground">
                                            {poi_categories[category]?.name ?? category}
                                        </span>
                                    </span>
                                    <span className="tabular-nums text-muted-foreground">{count}</span>
                                </div>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </ScrollArea>
    );
}

export default function MapPage({
    initialCenter,
    initialZoom,
    indicatorScopes,
    indicatorMeta,
}: MapPageProps) {
    const { t } = useTranslation();
    const mapRef = useRef<HeatmapMapHandle>(null);
    const [locationData, setLocationData] = useState<LocationData | null>(null);
    const [locationName, setLocationName] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [pinActive, setPinActive] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    // Parse initial coordinates from URL
    useEffect(() => {
        const path = window.location.pathname;
        const match = path.match(/\/explore\/([-\d.]+),([-\d.]+)/);
        if (match) {
            const lat = parseFloat(match[1]);
            const lng = parseFloat(match[2]);
            if (!isNaN(lat) && !isNaN(lng)) {
                // Drop pin and fetch data for URL coordinates
                setTimeout(() => {
                    mapRef.current?.dropPin(lat, lng);
                    mapRef.current?.zoomToPoint(lat, lng, 14);
                    mapRef.current?.setRadiusCircle(lat, lng, 3000);
                    handlePinDrop(lat, lng);
                }, 500);
            }
        }
    }, []);

    const fetchLocationData = useCallback(async (lat: number, lng: number) => {
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setLoading(true);
        setPinActive(true);

        try {
            const response = await fetch(`/api/location/${lat.toFixed(6)},${lng.toFixed(6)}`, {
                signal: controller.signal,
            });

            if (!response.ok) {
                if (response.status === 404) {
                    setLocationData(null);
                    setPinActive(false);
                    mapRef.current?.clearPin();
                    return;
                }
                throw new Error(`API error: ${response.status}`);
            }

            const data: LocationData = await response.json();
            setLocationData(data);

            // Zoom to neighborhood level and show radius
            mapRef.current?.zoomToPoint(lat, lng, 14);
            mapRef.current?.setRadiusCircle(lat, lng, 3000);

            // Set school markers on map
            mapRef.current?.setSchoolMarkers(data.schools);

            // Set POI markers within 3km radius (with icons)
            if (data.pois?.length > 0) {
                mapRef.current?.setPoiMarkers(data.pois, data.poi_categories);
            }

            // Reverse geocode for location name
            reverseGeocode(lat, lng);
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') return;
            console.error('Failed to fetch location data:', err);
        } finally {
            setLoading(false);
        }
    }, []);

    const reverseGeocode = useCallback(async (lat: number, lng: number) => {
        try {
            const response = await fetch(
                `https://photon.komoot.io/reverse?lat=${lat}&lon=${lng}&lang=default`,
            );
            const data = await response.json();
            const feature = data.features?.[0];
            if (feature) {
                const props = feature.properties;
                const parts: string[] = [];
                if (props.name) parts.push(props.name);
                else if (props.street) parts.push(props.street);
                if (props.city && !parts.includes(props.city)) parts.push(props.city);
                setLocationName(parts.join(', ') || null);
            }
        } catch {
            // Ignore reverse geocode failures, fall back to kommun name
        }
    }, []);

    const handlePinDrop = useCallback((lat: number, lng: number) => {
        // Update URL
        window.history.pushState(null, '', `/explore/${lat.toFixed(4)},${lng.toFixed(4)}`);
        fetchLocationData(lat, lng);
    }, [fetchLocationData]);

    const handlePinClear = useCallback(() => {
        setPinActive(false);
        setLocationData(null);
        setLocationName(null);
        mapRef.current?.clearSchoolMarkers();
        mapRef.current?.clearPoiMarkers();
        window.history.pushState(null, '', '/');
    }, []);

    const handleSearchResult = useCallback((result: SearchResult) => {
        mapRef.current?.dropPin(result.lat, result.lng);

        if (result.extent) {
            mapRef.current?.zoomToExtent(
                result.extent[0],
                result.extent[3],
                result.extent[2],
                result.extent[1],
            );
        } else {
            mapRef.current?.zoomToPoint(result.lat, result.lng, getZoomForType(result.type));
        }

        handlePinDrop(result.lat, result.lng);
    }, [handlePinDrop]);

    const handleSearchClear = useCallback(() => {
        handlePinClear();
        mapRef.current?.clearPin();
    }, [handlePinClear]);

    const handleTrySearch = useCallback((query: string) => {
        // Focus the search input and set query
        const input = document.querySelector<HTMLInputElement>('input[type="text"]');
        if (input) {
            input.focus();
            // Trigger the search by dispatching an input event
            const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
                window.HTMLInputElement.prototype, 'value',
            )?.set;
            nativeInputValueSetter?.call(input, query);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, []);

    return (
        <MapLayout>
            <Head title={t('map.title')} />
            <div className="absolute inset-0 flex">
                {/* Map */}
                <div className="relative flex-1">
                    <MapSearch
                        onResultSelect={handleSearchResult}
                        onClear={handleSearchClear}
                    />
                    <HeatmapMap
                        ref={mapRef}
                        initialCenter={initialCenter}
                        initialZoom={initialZoom}
                        onPinDrop={handlePinDrop}
                        onPinClear={handlePinClear}
                    />
                </div>

                {/* Sidebar */}
                <div className="hidden w-[360px] shrink-0 border-l border-border bg-background md:block">
                    {pinActive && locationData ? (
                        <ActiveSidebar
                            data={locationData}
                            locationName={locationName}
                            loading={loading}
                            indicatorScopes={indicatorScopes}
                            indicatorMeta={indicatorMeta}
                            onClose={handlePinClear}
                        />
                    ) : loading ? (
                        <div className="flex items-center justify-center py-20">
                            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
                        </div>
                    ) : (
                        <DefaultSidebar onTrySearch={handleTrySearch} />
                    )}
                </div>

                {/* Mobile bottom sheet (simplified) */}
                {pinActive && locationData && (
                    <div className="fixed inset-x-0 bottom-0 z-50 md:hidden">
                        <div className="rounded-t-xl border-t border-border bg-background p-4 shadow-lg">
                            <div className="mb-2 flex items-center justify-between">
                                <div>
                                    <h2 className="text-sm font-semibold">
                                        {locationName || locationData.location.kommun}
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        {locationData.location.kommun}
                                    </p>
                                </div>
                                <button
                                    onClick={() => {
                                        handlePinClear();
                                        mapRef.current?.clearPin();
                                    }}
                                    className="rounded-md p-1 text-muted-foreground"
                                >
                                    <X className="h-4 w-4" />
                                </button>
                            </div>
                            {locationData.score && (
                                <div className="flex items-center gap-2">
                                    <div
                                        className="flex h-10 w-10 items-center justify-center rounded-md text-sm font-bold text-white"
                                        style={scoreBgStyle(locationData.score.value)}
                                    >
                                        {locationData.score.value}
                                    </div>
                                    <div>
                                        <span className="text-sm text-foreground">
                                            {locationData.score.label}
                                        </span>
                                        {locationData.score.area_score !== null && (
                                            <div className="flex gap-2 text-[10px] tabular-nums text-muted-foreground">
                                                <span>{t('sidebar.proximity.area_label')}: {locationData.score.area_score}</span>
                                                <span>{t('sidebar.proximity.location_label')}: {locationData.score.proximity_score}</span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </MapLayout>
    );
}
