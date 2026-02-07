import { cellToBoundary } from 'h3-js';
import Feature from 'ol/Feature';
import Map from 'ol/Map';
import Overlay from 'ol/Overlay';
import View from 'ol/View';
import GeoJSON from 'ol/format/GeoJSON';
import LineString from 'ol/geom/LineString';
import Point from 'ol/geom/Point';
import Polygon from 'ol/geom/Polygon';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import { createEmpty, extend } from 'ol/extent';
import { fromLonLat, toLonLat, transformExtent } from 'ol/proj';
import OSM from 'ol/source/OSM';
import VectorSource from 'ol/source/Vector';
import XYZ from 'ol/source/XYZ';
import CircleStyle from 'ol/style/Circle';
import Fill from 'ol/style/Fill';
import RegularShape from 'ol/style/RegularShape';
import Stroke from 'ol/style/Stroke';
import Style from 'ol/style/Style';
import Text from 'ol/style/Text';
import {
    forwardRef,
    useCallback,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from 'react';

import { useTranslation } from '@/hooks/use-translation';
import type { School } from '@/pages/map';

import 'ol/ol.css';

export interface DesoProperties {
    deso_code: string;
    deso_name: string | null;
    kommun_code: string;
    kommun_name: string | null;
    lan_code: string;
    lan_name: string | null;
    area_km2: number | null;
}

export interface DesoScore {
    deso_code: string;
    score: number;
    trend_1y: number | null;
    factor_scores: Record<string, number> | null;
    top_positive: string[] | null;
    top_negative: string[] | null;
    urbanity_tier: 'urban' | 'semi_urban' | 'rural' | null;
}

export interface DesoMapHandle {
    updateSize: () => void;
    clearSchoolMarkers: () => void;
    setSchoolMarkers: (schools: School[]) => void;
    placeSearchMarker: (lat: number, lng: number) => void;
    clearSearchMarker: () => void;
    zoomToPoint: (lat: number, lng: number, zoom: number) => void;
    zoomToExtent: (
        west: number,
        south: number,
        east: number,
        north: number,
    ) => void;
    selectDesoByCode: (desoCode: string) => void;
    clearSelection: () => void;
    placeComparePin: (id: 'a' | 'b', lat: number, lng: number) => void;
    clearComparePins: () => void;
    placeLocationMarker: (lat: number, lng: number, accuracy: number) => void;
    clearLocationMarker: () => void;
    fitToPoints: (points: Array<{ lat: number; lng: number }>) => void;
}

type LayerMode = 'hexagons' | 'deso';
type BasemapType = 'clean' | 'detailed' | 'satellite';

interface DesoMapProps {
    initialCenter: [number, number];
    initialZoom: number;
    onFeatureSelect: (
        properties: DesoProperties | null,
        score: DesoScore | null,
    ) => void;
    onSchoolClick?: (schoolCode: string) => void;
    compareMode?: boolean;
    onCompareClick?: (lat: number, lng: number) => void;
    onMapReady?: (map: Map) => void;
    onPoiClick?: (feature: Feature) => void;
}

// Color stops: purple(0) -> red-purple(25) -> yellow(50) -> light-green(75) -> deep-green(100)
const COLOR_STOPS = [
    { score: 0, r: 74, g: 0, b: 114 }, // #4a0072
    { score: 25, r: 156, g: 29, b: 110 }, // #9c1d6e
    { score: 50, r: 240, g: 192, b: 64 }, // #f0c040
    { score: 75, r: 106, g: 191, b: 75 }, // #6abf4b
    { score: 100, r: 26, g: 122, b: 46 }, // #1a7a2e
];

function interpolateColor(score: number): [number, number, number] {
    const s = Math.max(0, Math.min(100, score));
    let lower = COLOR_STOPS[0];
    let upper = COLOR_STOPS[COLOR_STOPS.length - 1];

    for (let i = 0; i < COLOR_STOPS.length - 1; i++) {
        if (s >= COLOR_STOPS[i].score && s <= COLOR_STOPS[i + 1].score) {
            lower = COLOR_STOPS[i];
            upper = COLOR_STOPS[i + 1];
            break;
        }
    }

    const t =
        upper.score === lower.score
            ? 0
            : (s - lower.score) / (upper.score - lower.score);

    return [
        Math.round(lower.r + (upper.r - lower.r) * t),
        Math.round(lower.g + (upper.g - lower.g) * t),
        Math.round(lower.b + (upper.b - lower.b) * t),
    ];
}

function getScoreOpacity(zoom: number): number {
    if (zoom >= 17) return 0.25;
    if (zoom >= 14) return 0.30;
    if (zoom >= 12) return 0.35;
    if (zoom >= 9) return 0.40;
    return 0.45;
}

function getStrokeStyle(
    zoom: number,
    r: number,
    g: number,
    b: number,
    fillOpacity: number,
): Stroke | undefined {
    if (zoom >= 14) {
        return new Stroke({
            color: `rgba(${r}, ${g}, ${b}, ${Math.min(1, fillOpacity + 0.2)})`,
            width: 1,
        });
    } else if (zoom >= 10) {
        return new Stroke({
            color: `rgba(${r}, ${g}, ${b}, ${Math.min(1, fillOpacity + 0.1)})`,
            width: 0.5,
        });
    }
    return undefined;
}

function createBasemapSource(type: BasemapType): OSM | XYZ {
    switch (type) {
        case 'clean':
            return new XYZ({
                url: 'https://{a-d}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                attributions:
                    '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, © <a href="https://carto.com/attributions">CARTO</a>',
            });
        case 'satellite':
            return new XYZ({
                url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                attributions: 'Tiles © Esri',
            });
        default:
            return new OSM();
    }
}

/**
 * Returns a hex color string for a given score (0-100).
 * Uses the same interpolation as the map polygons.
 * Exported so the sidebar can match colors exactly.
 */
export function interpolateScoreColor(score: number): string {
    const [r, g, b] = interpolateColor(score);
    return `rgb(${r}, ${g}, ${b})`;
}

function schoolMarkerColor(merit: number | null): string {
    if (merit === null) return 'rgba(148, 163, 184, 0.9)';
    if (merit > 230) return 'rgba(34, 197, 94, 0.9)';
    if (merit >= 200) return 'rgba(234, 179, 8, 0.9)';
    return 'rgba(249, 115, 22, 0.9)';
}

const noDataStyle = new Style({
    fill: new Fill({ color: 'rgba(200, 200, 200, 0.3)' }),
    stroke: new Stroke({
        color: 'rgba(150, 150, 150, 0.6)',
        width: 1,
        lineDash: [4, 4],
    }),
});

function makeSelectedStyle(score: number | null): Style {
    if (score != null) {
        const [r, g, b] = interpolateColor(score);
        return new Style({
            fill: new Fill({ color: `rgba(${r}, ${g}, ${b}, 0.5)` }),
            stroke: new Stroke({ color: '#1e293b', width: 2.5 }),
        });
    }
    return new Style({
        fill: new Fill({ color: 'rgba(59, 130, 246, 0.25)' }),
        stroke: new Stroke({ color: 'rgba(59, 130, 246, 0.9)', width: 2.5 }),
    });
}

function h3ToFeature(h3Index: string, score: number, primaryDesoCode: string | null): Feature {
    const boundary = cellToBoundary(h3Index, true); // true = GeoJSON format [lng, lat]
    // Close the ring
    boundary.push(boundary[0]);
    const coords = boundary.map((c) => fromLonLat(c));
    const feature = new Feature({
        geometry: new Polygon([coords]),
    });
    feature.set('h3Index', h3Index);
    feature.set('score', score);
    feature.set('primaryDesoCode', primaryDesoCode);
    return feature;
}

function ScoreLegend() {
    const { t } = useTranslation();

    return (
        <div className="absolute bottom-6 left-6 z-10 rounded-lg border border-border bg-background/90 px-3 py-2 backdrop-blur-sm">
            <div
                className="mb-1 h-2 w-48 rounded-sm"
                style={{
                    background:
                        'linear-gradient(to right, #4a0072, #9c1d6e, #f0c040, #6abf4b, #1a7a2e)',
                }}
            />
            <div className="flex justify-between text-[11px] text-muted-foreground">
                <span>{t('map.legend.high_risk')}</span>
                <span>{t('map.legend.strong')}</span>
            </div>
        </div>
    );
}

function LayerControl({
    mode,
    onModeChange,
    showSmoothing,
    smoothed,
    onSmoothedChange,
    basemap,
    onBasemapChange,
}: {
    mode: LayerMode;
    onModeChange: (mode: LayerMode) => void;
    showSmoothing: boolean;
    smoothed: boolean;
    onSmoothedChange: (smoothed: boolean) => void;
    basemap: BasemapType;
    onBasemapChange: (basemap: BasemapType) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="absolute top-4 right-4 z-10 rounded-lg border border-border bg-background/90 px-3 py-2.5 backdrop-blur-sm">
            <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('map.layer_control.view')}
            </div>
            <div className="space-y-1">
                <label className="flex cursor-pointer items-center gap-2 text-xs text-foreground">
                    <input
                        type="radio"
                        name="layer"
                        checked={mode === 'hexagons'}
                        onChange={() => onModeChange('hexagons')}
                        className="h-3 w-3 accent-primary"
                    />
                    {t('map.layer_control.hexagons')}
                </label>
                <label className="flex cursor-pointer items-center gap-2 text-xs text-foreground">
                    <input
                        type="radio"
                        name="layer"
                        checked={mode === 'deso'}
                        onChange={() => onModeChange('deso')}
                        className="h-3 w-3 accent-primary"
                    />
                    {t('map.layer_control.statistical_areas')}
                </label>
            </div>
            {showSmoothing && mode === 'hexagons' && (
                <>
                    <div className="my-1.5 border-t border-border" />
                    <label className="flex cursor-pointer items-center gap-2 text-xs text-foreground">
                        <input
                            type="checkbox"
                            checked={!smoothed}
                            onChange={() => onSmoothedChange(!smoothed)}
                            className="h-3 w-3 rounded accent-primary"
                        />
                        {t('map.layer_control.raw_scores')}
                    </label>
                </>
            )}
            <div className="my-1.5 border-t border-border" />
            <div className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                Basemap
            </div>
            <div className="flex gap-1">
                {(['clean', 'detailed', 'satellite'] as const).map((type) => (
                    <button
                        key={type}
                        onClick={() => onBasemapChange(type)}
                        className={`rounded px-1.5 py-0.5 text-[10px] capitalize ${
                            basemap === type
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:bg-muted'
                        }`}
                    >
                        {type === 'clean'
                            ? 'Clean'
                            : type === 'detailed'
                              ? 'Detailed'
                              : 'Satellite'}
                    </button>
                ))}
            </div>
        </div>
    );
}

function LoadingOverlay() {
    const { t } = useTranslation();

    return (
        <div className="absolute inset-0 flex items-center justify-center bg-background/80">
            <div className="text-sm text-muted-foreground">
                {t('map.loading')}
            </div>
        </div>
    );
}

const DesoMap = forwardRef<DesoMapHandle, DesoMapProps>(function DesoMap(
    { initialCenter, initialZoom, onFeatureSelect, onSchoolClick, compareMode, onCompareClick, onMapReady, onPoiClick },
    ref,
) {
    const mapDivRef = useRef<HTMLDivElement>(null);
    const mapInstance = useRef<Map | null>(null);
    const hoveredFeature = useRef<Feature | null>(null);
    const selectedFeature = useRef<Feature | null>(null);
    const scoresRef = useRef<Record<string, DesoScore>>({});
    const desoPropsRef = useRef<Record<string, DesoProperties>>({});
    const schoolLayerRef = useRef<VectorLayer | null>(null);
    const schoolSourceRef = useRef<VectorSource | null>(null);
    const tooltipRef = useRef<HTMLDivElement>(null);
    const tooltipOverlayRef = useRef<Overlay | null>(null);
    const h3SourceRef = useRef<VectorSource | null>(null);
    const h3LayerRef = useRef<VectorLayer | null>(null);
    const desoLayerRef = useRef<VectorLayer | null>(null);
    const desoSourceRef = useRef<VectorSource | null>(null);
    const searchMarkerSourceRef = useRef<VectorSource | null>(null);
    const comparePinSourceRef = useRef<VectorSource | null>(null);
    const locationMarkerSourceRef = useRef<VectorSource | null>(null);
    const locationPulseTimerRef = useRef<number | null>(null);
    const fetchControllerRef = useRef<AbortController | null>(null);
    const tileLayerRef = useRef<TileLayer | null>(null);
    const [loading, setLoading] = useState(true);
    const [layerMode, setLayerMode] = useState<LayerMode>('hexagons');
    const [smoothed, setSmoothed] = useState(true);
    const [basemapType, setBasemapType] = useState<BasemapType>('clean');
    const [debugZoom, setDebugZoom] = useState(initialZoom);
    const layerModeRef = useRef<LayerMode>('hexagons');
    const smoothedRef = useRef(true);
    const compareModeRef = useRef(false);
    const onCompareClickRef = useRef(onCompareClick);
    const onMapReadyRef = useRef(onMapReady);
    const onPoiClickRef = useRef(onPoiClick);

    const handleFeatureSelect = useCallback(
        (properties: DesoProperties | null, score: DesoScore | null) => {
            onFeatureSelect(properties, score);
        },
        [onFeatureSelect],
    );

    // Keep refs in sync with state/props
    useEffect(() => {
        layerModeRef.current = layerMode;
    }, [layerMode]);

    useEffect(() => {
        smoothedRef.current = smoothed;
    }, [smoothed]);

    useEffect(() => {
        compareModeRef.current = !!compareMode;
        const el = mapInstance.current?.getTargetElement();
        if (el) {
            el.style.cursor = compareMode ? 'crosshair' : '';
        }
    }, [compareMode]);

    useEffect(() => {
        onCompareClickRef.current = onCompareClick;
    }, [onCompareClick]);

    useEffect(() => {
        onMapReadyRef.current = onMapReady;
    }, [onMapReady]);

    useEffect(() => {
        onPoiClickRef.current = onPoiClick;
    }, [onPoiClick]);

    useImperativeHandle(ref, () => ({
        updateSize() {
            mapInstance.current?.updateSize();
        },
        clearSchoolMarkers() {
            schoolSourceRef.current?.clear();
        },
        setSchoolMarkers(schools: School[]) {
            const source = schoolSourceRef.current;
            if (!source) return;
            source.clear();

            const features = schools
                .filter((s) => s.lat !== null && s.lng !== null)
                .map((s) => {
                    const feature = new Feature({
                        geometry: new Point(fromLonLat([s.lng!, s.lat!])),
                        school_unit_code: s.school_unit_code,
                        name: s.name,
                        merit_value: s.merit_value,
                        type: s.type,
                    });

                    const forms = s.school_forms ?? [];
                    const isGrundskola = forms.includes('Grundskola');
                    const isGymnasie = forms.includes('Gymnasieskola');
                    const fillColor = schoolMarkerColor(s.merit_value);
                    const strokeStyle = new Stroke({ color: '#fff', width: 2 });

                    let image;
                    if (isGymnasie && !isGrundskola) {
                        image = new RegularShape({
                            points: 4,
                            radius: 8,
                            angle: Math.PI / 4,
                            fill: new Fill({ color: fillColor }),
                            stroke: strokeStyle,
                        });
                    } else if (!isGrundskola && !isGymnasie) {
                        image = new CircleStyle({
                            radius: 4,
                            fill: new Fill({ color: 'rgba(148, 163, 184, 0.7)' }),
                            stroke: new Stroke({ color: '#fff', width: 1 }),
                        });
                    } else {
                        image = new CircleStyle({
                            radius: 7,
                            fill: new Fill({ color: fillColor }),
                            stroke: strokeStyle,
                        });
                    }

                    feature.setStyle(new Style({ image }));
                    return feature;
                });

            source.addFeatures(features);
        },
        placeSearchMarker(lat: number, lng: number) {
            const source = searchMarkerSourceRef.current;
            if (!source) return;
            source.clear();
            const feature = new Feature({
                geometry: new Point(fromLonLat([lng, lat])),
            });
            source.addFeature(feature);
        },
        clearSearchMarker() {
            searchMarkerSourceRef.current?.clear();
        },
        zoomToPoint(lat: number, lng: number, zoom: number) {
            const view = mapInstance.current?.getView();
            if (!view) return;
            view.animate({
                center: fromLonLat([lng, lat]),
                zoom,
                duration: 800,
            });
        },
        zoomToExtent(
            west: number,
            south: number,
            east: number,
            north: number,
        ) {
            const view = mapInstance.current?.getView();
            if (!view) return;
            const extent = transformExtent(
                [west, south, east, north],
                'EPSG:4326',
                'EPSG:3857',
            );
            view.fit(extent, {
                padding: [50, 50, 50, 50],
                duration: 800,
                maxZoom: 18,
            });
        },
        selectDesoByCode(desoCode: string) {
            const props = desoPropsRef.current[desoCode];
            const scoreData = scoresRef.current[desoCode] || null;
            if (props) {
                // Clear old selection
                if (selectedFeature.current) {
                    selectedFeature.current.setStyle(undefined);
                    selectedFeature.current = null;
                }
                handleFeatureSelect(props, scoreData);
            }
        },
        clearSelection() {
            if (selectedFeature.current) {
                selectedFeature.current.setStyle(undefined);
                selectedFeature.current = null;
            }
            handleFeatureSelect(null, null);
        },
        placeComparePin(id: 'a' | 'b', lat: number, lng: number) {
            const source = comparePinSourceRef.current;
            if (!source) return;

            // Remove existing pin with same id
            const existing = source.getFeatures().filter((f) => f.get('pinId') === id);
            existing.forEach((f) => source.removeFeature(f));

            // Remove existing connection line
            const lines = source.getFeatures().filter((f) => f.get('pinId') === 'line');
            lines.forEach((f) => source.removeFeature(f));

            const isA = id === 'a';
            const color = isA ? '#3b82f6' : '#f59e0b';
            const label = isA ? 'A' : 'B';

            const feature = new Feature({
                geometry: new Point(fromLonLat([lng, lat])),
                pinId: id,
            });
            feature.setStyle(
                new Style({
                    image: new CircleStyle({
                        radius: 12,
                        fill: new Fill({ color }),
                        stroke: new Stroke({ color: '#ffffff', width: 2 }),
                    }),
                    text: new Text({
                        text: label,
                        fill: new Fill({ color: '#ffffff' }),
                        font: 'bold 11px sans-serif',
                        offsetY: 1,
                    }),
                }),
            );
            source.addFeature(feature);

            // Draw connection line if both pins exist
            const pinFeatures = source.getFeatures().filter((f) => f.get('pinId') === 'a' || f.get('pinId') === 'b');
            if (pinFeatures.length === 2) {
                const pA = pinFeatures.find((f) => f.get('pinId') === 'a')!;
                const pB = pinFeatures.find((f) => f.get('pinId') === 'b')!;
                const coordA = (pA.getGeometry() as Point).getCoordinates();
                const coordB = (pB.getGeometry() as Point).getCoordinates();

                const lineFeature = new Feature({
                    geometry: new LineString([coordA, coordB]),
                    pinId: 'line',
                });
                lineFeature.setStyle(
                    new Style({
                        stroke: new Stroke({
                            color: 'rgba(100, 116, 139, 0.6)',
                            width: 1.5,
                            lineDash: [6, 4],
                        }),
                    }),
                );
                source.addFeature(lineFeature);
            }
        },
        clearComparePins() {
            comparePinSourceRef.current?.clear();
        },
        placeLocationMarker(lat: number, lng: number, accuracy: number) {
            const source = locationMarkerSourceRef.current;
            if (!source) return;
            source.clear();

            // Cancel any existing pulse animation
            if (locationPulseTimerRef.current) {
                cancelAnimationFrame(locationPulseTimerRef.current);
                locationPulseTimerRef.current = null;
            }

            // Inner dot
            const dotFeature = new Feature({
                geometry: new Point(fromLonLat([lng, lat])),
                locationType: 'dot',
            });
            dotFeature.setStyle(
                new Style({
                    image: new CircleStyle({
                        radius: 6,
                        fill: new Fill({ color: '#3b82f6' }),
                        stroke: new Stroke({ color: '#ffffff', width: 2 }),
                    }),
                }),
            );
            source.addFeature(dotFeature);

            // Pulse ring (animated)
            const pulseFeature = new Feature({
                geometry: new Point(fromLonLat([lng, lat])),
                locationType: 'pulse',
            });
            source.addFeature(pulseFeature);

            let pulseRadius = 8;
            let pulseOpacity = 0.8;
            const animatePulse = () => {
                pulseRadius += 0.3;
                pulseOpacity -= 0.015;
                if (pulseOpacity <= 0) {
                    pulseRadius = 8;
                    pulseOpacity = 0.8;
                }
                pulseFeature.setStyle(
                    new Style({
                        image: new CircleStyle({
                            radius: pulseRadius,
                            fill: new Fill({ color: `rgba(59, 130, 246, ${pulseOpacity * 0.3})` }),
                            stroke: new Stroke({ color: `rgba(59, 130, 246, ${pulseOpacity})`, width: 1.5 }),
                        }),
                    }),
                );
                locationPulseTimerRef.current = requestAnimationFrame(animatePulse);
            };
            animatePulse();

            // Accuracy circle if > 100m
            if (accuracy > 100) {
                const map = mapInstance.current;
                if (map) {
                    const center = fromLonLat([lng, lat]);
                    const view = map.getView();
                    const resolution = view.getResolution() ?? 1;
                    const radiusPx = accuracy / resolution;
                    const accFeature = new Feature({
                        geometry: new Point(center),
                        locationType: 'accuracy',
                    });
                    accFeature.setStyle(
                        new Style({
                            image: new CircleStyle({
                                radius: Math.min(radiusPx, 100),
                                fill: new Fill({ color: 'rgba(59, 130, 246, 0.08)' }),
                                stroke: new Stroke({ color: 'rgba(59, 130, 246, 0.25)', width: 1 }),
                            }),
                        }),
                    );
                    source.addFeature(accFeature);
                }
            }
        },
        clearLocationMarker() {
            locationMarkerSourceRef.current?.clear();
            if (locationPulseTimerRef.current) {
                cancelAnimationFrame(locationPulseTimerRef.current);
                locationPulseTimerRef.current = null;
            }
        },
        fitToPoints(points: Array<{ lat: number; lng: number }>) {
            const view = mapInstance.current?.getView();
            if (!view || points.length < 2) return;

            const coords = points.map((p) => fromLonLat([p.lng, p.lat]));
            const minX = Math.min(...coords.map((c) => c[0]));
            const minY = Math.min(...coords.map((c) => c[1]));
            const maxX = Math.max(...coords.map((c) => c[0]));
            const maxY = Math.max(...coords.map((c) => c[1]));

            const extent = [minX, minY, maxX, maxY] as [number, number, number, number];
            view.fit(extent, {
                padding: [80, 80, 80, 80],
                duration: 800,
                maxZoom: 15,
            });
        },
    }));

    // Fetch H3 hexes for the current viewport
    const loadH3Viewport = useCallback(() => {
        const map = mapInstance.current;
        const source = h3SourceRef.current;
        if (!map || !source) return;

        const view = map.getView();
        const extent = view.calculateExtent(map.getSize());
        const [minLng, minLat, maxLng, maxLat] = transformExtent(extent, 'EPSG:3857', 'EPSG:4326');
        const zoom = Math.round(view.getZoom() ?? 5);

        // Buffer the viewport to prevent bald patches at edges
        const buffer = 0.03;

        // Cancel previous request
        fetchControllerRef.current?.abort();
        const controller = new AbortController();
        fetchControllerRef.current = controller;

        const smoothedParam = smoothedRef.current ? 'true' : 'false';

        fetch(
            `/api/h3/viewport?bbox=${minLng - buffer},${minLat - buffer},${maxLng + buffer},${maxLat + buffer}&zoom=${zoom}&year=2024&smoothed=${smoothedParam}`,
            { signal: controller.signal },
        )
            .then((r) => r.json())
            .then((data) => {
                source.clear();
                const features = data.features.map((f: { h3_index: string; score: number; primary_deso_code: string | null }) =>
                    h3ToFeature(f.h3_index, Number(f.score), f.primary_deso_code),
                );
                source.addFeatures(features);
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.error('H3 viewport fetch failed:', err);
                }
            });
    }, []);

    useEffect(() => {
        if (!mapDivRef.current || !tooltipRef.current) return;

        // DeSO vector layer
        const desoSource = new VectorSource();
        desoSourceRef.current = desoSource;

        function styleForDesoFeature(feature: Feature): Style {
            const desoCode = feature.get('deso_code');
            const scoreData = scoresRef.current[desoCode];

            if (!scoreData) return noDataStyle;

            const [r, g, b] = interpolateColor(scoreData.score);
            const zoom = mapInstance.current?.getView().getZoom() ?? 5;
            const opacity = getScoreOpacity(zoom);
            return new Style({
                fill: new Fill({
                    color: `rgba(${r}, ${g}, ${b}, ${opacity})`,
                }),
                stroke: getStrokeStyle(zoom, r, g, b, opacity),
            });
        }

        const desoLayer = new VectorLayer({
            source: desoSource,
            style: (feature) => styleForDesoFeature(feature as Feature),
            visible: false, // Hidden by default, hexagons are primary
            zIndex: 3,
        });
        desoLayerRef.current = desoLayer;

        // H3 hexagon layer
        const h3Source = new VectorSource();
        h3SourceRef.current = h3Source;

        const h3Layer = new VectorLayer({
            source: h3Source,
            style: (feature) => {
                const score = (feature as Feature).get('score');
                if (score == null) return noDataStyle;
                const [r, g, b] = interpolateColor(score);
                const zoom = mapInstance.current?.getView().getZoom() ?? 5;
                const opacity = getScoreOpacity(zoom);
                return new Style({
                    fill: new Fill({
                        color: `rgba(${r}, ${g}, ${b}, ${opacity})`,
                    }),
                    stroke: getStrokeStyle(zoom, r, g, b, opacity),
                });
            },
            visible: true,
            zIndex: 3,
        });
        h3LayerRef.current = h3Layer;

        // School markers layer (on top)
        const schoolSource = new VectorSource();
        schoolSourceRef.current = schoolSource;
        const schoolLayer = new VectorLayer({
            source: schoolSource,
            zIndex: 10,
        });
        schoolLayerRef.current = schoolLayer;

        // Search marker layer
        const searchMarkerSource = new VectorSource();
        searchMarkerSourceRef.current = searchMarkerSource;
        const searchMarkerLayer = new VectorLayer({
            source: searchMarkerSource,
            zIndex: 50,
            style: new Style({
                image: new CircleStyle({
                    radius: 6,
                    fill: new Fill({ color: '#3b82f6' }),
                    stroke: new Stroke({ color: '#ffffff', width: 2 }),
                }),
            }),
        });

        // Compare pin layer
        const comparePinSource = new VectorSource();
        comparePinSourceRef.current = comparePinSource;
        const comparePinLayer = new VectorLayer({
            source: comparePinSource,
            zIndex: 100,
        });

        // Location marker layer
        const locationMarkerSource = new VectorSource();
        locationMarkerSourceRef.current = locationMarkerSource;
        const locationMarkerLayer = new VectorLayer({
            source: locationMarkerSource,
            zIndex: 90,
        });

        // Tooltip overlay
        const tooltipOverlay = new Overlay({
            element: tooltipRef.current,
            positioning: 'bottom-center',
            offset: [0, -12],
            stopEvent: false,
        });
        tooltipOverlayRef.current = tooltipOverlay;

        // Basemap tile layer (CartoDB Positron by default)
        const tileLayer = new TileLayer({
            source: createBasemapSource('clean'),
            zIndex: 0,
        });
        tileLayerRef.current = tileLayer;

        // Country mask layer (world polygon with Sweden hole) — added after boundary loads
        const maskSource = new VectorSource();
        const maskLayer = new VectorLayer({
            source: maskSource,
            style: new Style({
                fill: new Fill({ color: 'rgba(245, 245, 245, 0.75)' }),
            }),
            zIndex: 1,
        });

        // Sweden border stroke layer
        const borderSource = new VectorSource();
        const borderLayer = new VectorLayer({
            source: borderSource,
            style: new Style({
                stroke: new Stroke({
                    color: 'rgba(100, 116, 139, 0.5)',
                    width: 1.5,
                    lineDash: [8, 4],
                }),
                fill: new Fill({ color: 'rgba(0, 0, 0, 0)' }),
            }),
            zIndex: 2,
        });

        const map = new Map({
            target: mapDivRef.current,
            layers: [
                tileLayer,
                maskLayer,
                borderLayer,
                desoLayer,
                h3Layer,
                schoolLayer,
                searchMarkerLayer,
                locationMarkerLayer,
                comparePinLayer,
            ],
            overlays: [tooltipOverlay],
            view: new View({
                center: fromLonLat([initialCenter[1], initialCenter[0]]),
                zoom: initialZoom,
            }),
        });

        mapInstance.current = map;
        onMapReadyRef.current?.(map);

        // Load Sweden boundary for mask overlay
        fetch('/data/sweden-boundary.geojson')
            .then((r) => r.json())
            .then((boundary) => {
                const geom = boundary.features?.[0]?.geometry;
                if (!geom) return;

                // Build world polygon with Sweden-shaped hole(s)
                const worldRing = [
                    [-180, -90],
                    [180, -90],
                    [180, 90],
                    [-180, 90],
                    [-180, -90],
                ].map((c) => fromLonLat(c));

                const holes: number[][][] = [];
                if (geom.type === 'Polygon') {
                    holes.push(geom.coordinates[0].map((c: number[]) => fromLonLat(c)));
                } else if (geom.type === 'MultiPolygon') {
                    for (const polygon of geom.coordinates) {
                        holes.push(polygon[0].map((c: number[]) => fromLonLat(c)));
                    }
                }

                const maskFeature = new Feature({
                    geometry: new Polygon([worldRing, ...holes]),
                });
                maskSource.addFeature(maskFeature);

                // Border stroke features
                const borderFeatures: Feature[] = [];
                if (geom.type === 'Polygon') {
                    const coords = geom.coordinates[0].map((c: number[]) => fromLonLat(c));
                    borderFeatures.push(new Feature({ geometry: new Polygon([coords]) }));
                } else if (geom.type === 'MultiPolygon') {
                    for (const polygon of geom.coordinates) {
                        const coords = polygon[0].map((c: number[]) => fromLonLat(c));
                        borderFeatures.push(new Feature({ geometry: new Polygon([coords]) }));
                    }
                }
                borderSource.addFeatures(borderFeatures);
            })
            .catch((err) => {
                console.warn('Failed to load Sweden boundary for mask:', err);
            });

        // Load DeSO GeoJSON + scores (for DeSO layer and sidebar data)
        Promise.all([
            fetch('/data/deso.geojson').then((r) => r.json()),
            fetch('/api/deso/scores?year=2024').then((r) => r.json()),
        ])
            .then(([geojson, scores]) => {
                const parsedScores: Record<string, DesoScore> = {};
                for (const [code, data] of Object.entries(scores)) {
                    const d = data as Record<string, unknown>;
                    parsedScores[code] = {
                        deso_code: code,
                        score: Number(d.score),
                        trend_1y:
                            d.trend_1y != null ? Number(d.trend_1y) : null,
                        factor_scores:
                            typeof d.factor_scores === 'string'
                                ? JSON.parse(d.factor_scores)
                                : (d.factor_scores as Record<
                                      string,
                                      number
                                  > | null),
                        top_positive:
                            typeof d.top_positive === 'string'
                                ? JSON.parse(d.top_positive)
                                : (d.top_positive as string[] | null),
                        top_negative:
                            typeof d.top_negative === 'string'
                                ? JSON.parse(d.top_negative)
                                : (d.top_negative as string[] | null),
                        urbanity_tier: (d.urbanity_tier as DesoScore['urbanity_tier']) ?? null,
                    };
                }
                scoresRef.current = parsedScores;

                const features = new GeoJSON().readFeatures(geojson, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857',
                });

                // Store DeSO properties for lookup when clicking H3 hexes
                for (const f of features) {
                    const props = f.getProperties();
                    desoPropsRef.current[props.deso_code] = {
                        deso_code: props.deso_code,
                        deso_name: props.deso_name,
                        kommun_code: props.kommun_code,
                        kommun_name: props.kommun_name,
                        lan_code: props.lan_code,
                        lan_name: props.lan_name,
                        area_km2: props.area_km2,
                    };
                }

                desoSource.addFeatures(features);
                setLoading(false);

                // Initial H3 load after map is ready
                loadH3Viewport();
            })
            .catch((err) => {
                console.error('Failed to load map data:', err);
                setLoading(false);
            });

        // Viewport change → reload H3 hexes + update debug zoom
        let debounceTimer: ReturnType<typeof setTimeout> | null = null;
        map.on('moveend', () => {
            setDebugZoom(Math.round((map.getView().getZoom() ?? 0) * 10) / 10);
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                if (layerModeRef.current === 'hexagons') {
                    loadH3Viewport();
                }
            }, 300);
        });

        // Helper to get the active polygon layer
        function getActiveLayer(): VectorLayer {
            return layerModeRef.current === 'hexagons' ? h3Layer : desoLayer;
        }

        // Hover interaction
        map.on('pointermove', (evt) => {
            if (evt.dragging) return;

            // Check school markers first
            const schoolFeature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => f as Feature,
                { layerFilter: (l) => l === schoolLayer },
            );

            if (schoolFeature) {
                const name = schoolFeature.get('name');
                const merit = schoolFeature.get('merit_value');
                const tooltipEl = tooltipRef.current;
                if (tooltipEl) {
                    tooltipEl.innerHTML = `<strong>${name}</strong>${merit !== null ? `<br/>Meritvärde: ${merit}` : ''}`;
                    tooltipEl.style.display = 'block';
                }
                tooltipOverlay.setPosition(evt.coordinate);
                map.getTargetElement().style.cursor = 'pointer';

                if (
                    hoveredFeature.current &&
                    hoveredFeature.current !== selectedFeature.current
                ) {
                    hoveredFeature.current.setStyle(undefined);
                    hoveredFeature.current = null;
                }
                return;
            }

            // Check POI markers (cluster or single)
            const poiFeature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => {
                    const clustered = (f as Feature).get('features');
                    if (clustered && clustered.length > 0 && clustered[0].get('is_poi')) {
                        return f as Feature;
                    }
                    return null;
                },
            );

            if (poiFeature) {
                const clustered = poiFeature.get('features') as Feature[];
                const tooltipEl = tooltipRef.current;
                if (tooltipEl && clustered) {
                    if (clustered.length === 1) {
                        const poi = clustered[0];
                        const name = poi.get('name') || poi.get('poi_type');
                        const type = poi.get('poi_type')?.replace(/_/g, ' ') || '';
                        tooltipEl.innerHTML = `<strong>${name}</strong><br/><span style="opacity:0.7">${type}</span>`;
                    } else {
                        const sentiments: Record<string, number> = {};
                        for (const f of clustered) {
                            const s = f.get('sentiment') || 'neutral';
                            sentiments[s] = (sentiments[s] || 0) + 1;
                        }
                        const parts = [];
                        if (sentiments.negative) parts.push(`${sentiments.negative} nuisance${sentiments.negative > 1 ? 's' : ''}`);
                        if (sentiments.positive) parts.push(`${sentiments.positive} amenit${sentiments.positive > 1 ? 'ies' : 'y'}`);
                        if (sentiments.neutral) parts.push(`${sentiments.neutral} other`);
                        tooltipEl.innerHTML = `<strong>${clustered.length} POIs</strong><br/><span style="opacity:0.7">${parts.join(' · ')}</span>`;
                    }
                    tooltipEl.style.display = 'block';
                }
                tooltipOverlay.setPosition(evt.coordinate);
                map.getTargetElement().style.cursor = 'pointer';

                if (
                    hoveredFeature.current &&
                    hoveredFeature.current !== selectedFeature.current
                ) {
                    hoveredFeature.current.setStyle(undefined);
                    hoveredFeature.current = null;
                }
                return;
            }

            // Hide tooltip when not on school or POI
            if (tooltipRef.current) {
                tooltipRef.current.style.display = 'none';
            }

            // Polygon hover (active layer only)
            const activeLayer = getActiveLayer();
            const feature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => f as Feature,
                { layerFilter: (l) => l === activeLayer },
            );

            if (
                hoveredFeature.current &&
                hoveredFeature.current !== feature &&
                hoveredFeature.current !== selectedFeature.current
            ) {
                hoveredFeature.current.setStyle(undefined);
            }

            if (feature && feature !== selectedFeature.current) {
                // Determine score
                let score: number | null = null;
                if (layerModeRef.current === 'hexagons') {
                    score = feature.get('score');
                } else {
                    const desoCode = feature.get('deso_code');
                    score = scoresRef.current[desoCode]?.score ?? null;
                }

                if (score !== null) {
                    const [r, g, b] = interpolateColor(score);
                    const hoverZoom = mapInstance.current?.getView().getZoom() ?? 5;
                    const baseOpacity = getScoreOpacity(hoverZoom);
                    feature.setStyle(
                        new Style({
                            fill: new Fill({
                                color: `rgba(${r}, ${g}, ${b}, ${Math.min(1, baseOpacity + 0.2)})`,
                            }),
                            stroke: new Stroke({
                                color: `rgba(${Math.max(0, r - 40)}, ${Math.max(0, g - 40)}, ${Math.max(0, b - 40)}, 1)`,
                                width: 1.5,
                            }),
                        }),
                    );
                } else {
                    feature.setStyle(
                        new Style({
                            fill: new Fill({
                                color: 'rgba(180, 180, 180, 0.5)',
                            }),
                            stroke: new Stroke({
                                color: 'rgba(120, 120, 120, 0.8)',
                                width: 1.5,
                                lineDash: [4, 4],
                            }),
                        }),
                    );
                }
                map.getTargetElement().style.cursor = 'pointer';

                // Show tooltip for H3 hexes
                if (layerModeRef.current === 'hexagons' && score !== null) {
                    const tooltipEl = tooltipRef.current;
                    if (tooltipEl) {
                        tooltipEl.innerHTML = `Score: ${Number(score).toFixed(1)}`;
                        tooltipEl.style.display = 'block';
                    }
                    tooltipOverlay.setPosition(evt.coordinate);
                }
            } else if (!feature) {
                map.getTargetElement().style.cursor = compareModeRef.current ? 'crosshair' : '';
                if (layerModeRef.current === 'hexagons' && tooltipRef.current) {
                    tooltipRef.current.style.display = 'none';
                }
            }

            hoveredFeature.current = feature ?? null;
        });

        // Click interaction
        map.on('click', (evt) => {
            // Compare mode: forward click to parent
            if (compareModeRef.current && onCompareClickRef.current) {
                const coords = toLonLat(evt.coordinate);
                onCompareClickRef.current(coords[1], coords[0]); // lat, lng
                return;
            }

            // Check school markers first
            const schoolFeature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => f as Feature,
                { layerFilter: (l) => l === schoolLayer },
            );

            if (schoolFeature) {
                const code = schoolFeature.get('school_unit_code');
                if (code && onSchoolClick) {
                    onSchoolClick(code);
                }
                return;
            }

            // Check POI clusters — click to zoom in
            const poiClickFeature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => {
                    const clustered = (f as Feature).get('features');
                    if (clustered && clustered.length > 0 && clustered[0].get('is_poi')) {
                        return f as Feature;
                    }
                    return null;
                },
            );

            if (poiClickFeature) {
                const clustered = poiClickFeature.get('features') as Feature[];
                if (clustered && clustered.length > 1) {
                    // Zoom to cluster extent
                    const extent = createEmpty();
                    for (const f of clustered) {
                        const geom = f.getGeometry();
                        if (geom) extend(extent, geom.getExtent());
                    }
                    map.getView().fit(extent, {
                        padding: [60, 60, 60, 60],
                        maxZoom: 16,
                        duration: 500,
                    });
                } else if (clustered && clustered.length === 1) {
                    // Single POI click — show impact radius
                    onPoiClickRef.current?.(clustered[0]);
                }
                return;
            }

            // Polygon click (active layer)
            const activeLayer = getActiveLayer();
            const feature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => f as Feature,
                { layerFilter: (l) => l === activeLayer },
            );

            if (selectedFeature.current) {
                selectedFeature.current.setStyle(undefined);
            }

            if (feature) {
                const geom = feature.getGeometry();
                const view = map.getView();

                if (layerModeRef.current === 'hexagons') {
                    const desoCode = feature.get('primaryDesoCode');

                    if (!desoCode) {
                        // Low-res hex without a DeSO — zoom into it to reveal detail
                        if (geom) {
                            const extent = geom.getExtent();
                            view.fit(extent, {
                                duration: 600,
                                padding: [100, 100, 100, 100],
                                maxZoom: 11,
                            });
                        }
                        return;
                    }

                    // Has a DeSO — select it and animate to center
                    feature.setStyle(makeSelectedStyle(feature.get('score')));
                    selectedFeature.current = feature;

                    if (geom) {
                        // Buffer the hex extent to show ~5-10 surrounding hexes
                        const ext = geom.getExtent();
                        const dx = (ext[2] - ext[0]) * 5;
                        const dy = (ext[3] - ext[1]) * 5;
                        const cx = (ext[0] + ext[2]) / 2;
                        const cy = (ext[1] + ext[3]) / 2;
                        const buffered = [cx - dx, cy - dy, cx + dx, cy + dy];
                        const currentZoom = view.getZoom() ?? 5;
                        if (currentZoom < 14) {
                            view.fit(buffered, { duration: 600 });
                        } else {
                            view.animate({ center: [cx, cy], duration: 400 });
                        }
                    }

                    const props = desoPropsRef.current[desoCode] ?? {
                        deso_code: desoCode,
                        deso_name: null,
                        kommun_code: '',
                        kommun_name: null,
                        lan_code: '',
                        lan_name: null,
                        area_km2: null,
                    };
                    const scoreData = scoresRef.current[desoCode] || null;
                    handleFeatureSelect(props, scoreData);
                } else {
                    // DeSO polygon — select and animate to center
                    const desoClickCode = feature.get('deso_code');
                    const desoClickScore = scoresRef.current[desoClickCode]?.score ?? null;
                    feature.setStyle(makeSelectedStyle(desoClickScore));
                    selectedFeature.current = feature;

                    if (geom) {
                        const extent = geom.getExtent();
                        const cx = (extent[0] + extent[2]) / 2;
                        const cy = (extent[1] + extent[3]) / 2;
                        view.animate({
                            center: [cx, cy],
                            duration: 400,
                        });
                    }

                    const desoCode = feature.get('deso_code');
                    const props = desoPropsRef.current[desoCode] ?? {
                        deso_code: desoCode,
                        deso_name: null,
                        kommun_code: '',
                        kommun_name: null,
                        lan_code: '',
                        lan_name: null,
                        area_km2: null,
                    };
                    const scoreData = scoresRef.current[desoCode] || null;
                    handleFeatureSelect(props, scoreData);
                }
            } else {
                selectedFeature.current = null;
                handleFeatureSelect(null, null);
            }
        });

        return () => {
            map.setTarget(undefined);
            if (locationPulseTimerRef.current) {
                cancelAnimationFrame(locationPulseTimerRef.current);
            }
        };
    }, [initialCenter, initialZoom, handleFeatureSelect, onSchoolClick, loadH3Viewport]);

    // Layer mode switching
    useEffect(() => {
        const desoLayer = desoLayerRef.current;
        const h3Layer = h3LayerRef.current;
        if (!desoLayer || !h3Layer) return;

        if (layerMode === 'hexagons') {
            h3Layer.setVisible(true);
            desoLayer.setVisible(false);
            loadH3Viewport();
        } else {
            h3Layer.setVisible(false);
            desoLayer.setVisible(true);
        }

        // Clear selection when switching layers
        if (selectedFeature.current) {
            selectedFeature.current.setStyle(undefined);
            selectedFeature.current = null;
        }
    }, [layerMode, loadH3Viewport]);

    // Smoothing toggle → re-fetch H3 hexes
    useEffect(() => {
        if (layerMode === 'hexagons') {
            loadH3Viewport();
        }
    }, [smoothed, layerMode, loadH3Viewport]);

    // Basemap switching
    useEffect(() => {
        const tileLayer = tileLayerRef.current;
        if (!tileLayer) return;
        tileLayer.setSource(createBasemapSource(basemapType));
    }, [basemapType]);

    return (
        <div className="relative h-full w-full">
            <div ref={mapDivRef} className="h-full w-full" />
            <div
                ref={tooltipRef}
                className="pointer-events-none rounded bg-foreground px-2 py-1 text-xs text-background shadow-lg"
                style={{ display: 'none' }}
            />
            <div className="absolute bottom-6 right-6 z-10 rounded bg-foreground/70 px-2 py-1 font-mono text-xs text-background">
                Z {debugZoom}
            </div>
            <ScoreLegend />
            <LayerControl
                mode={layerMode}
                onModeChange={setLayerMode}
                showSmoothing={true}
                smoothed={smoothed}
                onSmoothedChange={setSmoothed}
                basemap={basemapType}
                onBasemapChange={setBasemapType}
            />
            {loading && (
                <LoadingOverlay />
            )}
        </div>
    );
});

export default DesoMap;
