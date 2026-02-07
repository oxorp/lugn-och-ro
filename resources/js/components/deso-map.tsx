import Feature from 'ol/Feature';
import Map from 'ol/Map';
import View from 'ol/View';
import Point from 'ol/geom/Point';
import Polygon, { circular } from 'ol/geom/Polygon';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import { fromLonLat, toLonLat, transformExtent } from 'ol/proj';
import OSM from 'ol/source/OSM';
import VectorSource from 'ol/source/Vector';
import XYZ from 'ol/source/XYZ';
import CircleStyle from 'ol/style/Circle';
import Fill from 'ol/style/Fill';
import RegularShape from 'ol/style/RegularShape';
import Stroke from 'ol/style/Stroke';
import Style from 'ol/style/Style';
import {
    forwardRef,
    useCallback,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from 'react';

import { useTranslation } from '@/hooks/use-translation';

import 'ol/ol.css';

// Color stops: purple(0) -> red-purple(25) -> yellow(50) -> light-green(75) -> deep-green(100)
const COLOR_STOPS = [
    { score: 0, r: 74, g: 0, b: 114 },
    { score: 25, r: 156, g: 29, b: 110 },
    { score: 50, r: 240, g: 192, b: 64 },
    { score: 75, r: 106, g: 191, b: 75 },
    { score: 100, r: 26, g: 122, b: 46 },
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

export function interpolateScoreColor(score: number): string {
    const [r, g, b] = interpolateColor(score);
    return `rgb(${r}, ${g}, ${b})`;
}

export interface SchoolMarker {
    name: string;
    lat: number;
    lng: number;
    merit_value: number | null;
    type: string | null;
    operator_type: string | null;
    distance_m: number;
}

export interface PoiMarker {
    name: string;
    lat: number;
    lng: number;
    category: string;
    distance_m: number;
}

export interface PoiCategoryMeta {
    name: string;
    color: string;
    icon: string;
    signal: string;
}

export interface HeatmapMapHandle {
    updateSize: () => void;
    dropPin: (lat: number, lng: number) => void;
    clearPin: () => void;
    setSchoolMarkers: (schools: SchoolMarker[]) => void;
    clearSchoolMarkers: () => void;
    setRadiusCircle: (lat: number, lng: number, radiusMeters: number) => void;
    setPoiMarkers: (pois: PoiMarker[], categories: Record<string, PoiCategoryMeta>) => void;
    clearPoiMarkers: () => void;
    zoomToPoint: (lat: number, lng: number, zoom: number) => void;
    zoomToExtent: (west: number, south: number, east: number, north: number) => void;
}

type BasemapType = 'clean' | 'detailed' | 'satellite';

interface HeatmapMapProps {
    initialCenter: [number, number];
    initialZoom: number;
    onPinDrop: (lat: number, lng: number) => void;
    onPinClear: () => void;
}

function createBasemapSource(type: BasemapType): OSM | XYZ {
    switch (type) {
        case 'clean':
            return new XYZ({
                url: 'https://{a-d}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                attributions:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, &copy; <a href="https://carto.com/attributions">CARTO</a>',
            });
        case 'satellite':
            return new XYZ({
                url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                attributions: 'Tiles &copy; Esri',
            });
        default:
            return new OSM();
    }
}

function schoolMarkerColor(merit: number | null): string {
    if (merit === null) return 'rgba(148, 163, 184, 0.9)';
    if (merit > 230) return 'rgba(34, 197, 94, 0.9)';
    if (merit >= 200) return 'rgba(234, 179, 8, 0.9)';
    return 'rgba(249, 115, 22, 0.9)';
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

function BasemapControl({
    basemap,
    onBasemapChange,
}: {
    basemap: BasemapType;
    onBasemapChange: (basemap: BasemapType) => void;
}) {
    return (
        <div className="absolute top-4 right-4 z-10 rounded-lg border border-border bg-background/90 px-3 py-2.5 backdrop-blur-sm">
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

const HeatmapMap = forwardRef<HeatmapMapHandle, HeatmapMapProps>(function HeatmapMap(
    { initialCenter, initialZoom, onPinDrop, onPinClear },
    ref,
) {
    const mapDivRef = useRef<HTMLDivElement>(null);
    const mapInstance = useRef<Map | null>(null);
    const pinSourceRef = useRef<VectorSource | null>(null);
    const schoolSourceRef = useRef<VectorSource | null>(null);
    const radiusSourceRef = useRef<VectorSource | null>(null);
    const poiSourceRef = useRef<VectorSource | null>(null);
    const tileLayerRef = useRef<TileLayer | null>(null);
    const heatmapLayerRef = useRef<TileLayer | null>(null);
    const [basemapType, setBasemapType] = useState<BasemapType>('clean');
    const onPinDropRef = useRef(onPinDrop);
    const onPinClearRef = useRef(onPinClear);

    useEffect(() => {
        onPinDropRef.current = onPinDrop;
    }, [onPinDrop]);

    useEffect(() => {
        onPinClearRef.current = onPinClear;
    }, [onPinClear]);

    const dropPinAtCoords = useCallback((lat: number, lng: number) => {
        const source = pinSourceRef.current;
        if (!source) return;
        source.clear();

        const feature = new Feature({
            geometry: new Point(fromLonLat([lng, lat])),
        });
        feature.setStyle(
            new Style({
                image: new CircleStyle({
                    radius: 8,
                    fill: new Fill({ color: '#ffffff' }),
                    stroke: new Stroke({ color: '#1a1a2e', width: 3 }),
                }),
            }),
        );
        source.addFeature(feature);
    }, []);

    useImperativeHandle(ref, () => ({
        updateSize() {
            mapInstance.current?.updateSize();
        },
        dropPin(lat: number, lng: number) {
            dropPinAtCoords(lat, lng);
        },
        clearPin() {
            pinSourceRef.current?.clear();
            schoolSourceRef.current?.clear();
            radiusSourceRef.current?.clear();
            poiSourceRef.current?.clear();
        },
        setSchoolMarkers(schools: SchoolMarker[]) {
            const source = schoolSourceRef.current;
            if (!source) return;
            source.clear();

            const features = schools
                .filter((s) => s.lat && s.lng)
                .map((s) => {
                    const feature = new Feature({
                        geometry: new Point(fromLonLat([s.lng, s.lat])),
                        name: s.name,
                        merit_value: s.merit_value,
                    });

                    const fillColor = schoolMarkerColor(s.merit_value);
                    const isGymnasie = s.type?.includes('Gymnasie') ?? false;

                    let image;
                    if (isGymnasie) {
                        image = new RegularShape({
                            points: 4,
                            radius: 7,
                            angle: Math.PI / 4,
                            fill: new Fill({ color: fillColor }),
                            stroke: new Stroke({ color: '#fff', width: 2 }),
                        });
                    } else {
                        image = new CircleStyle({
                            radius: 6,
                            fill: new Fill({ color: fillColor }),
                            stroke: new Stroke({ color: '#fff', width: 2 }),
                        });
                    }

                    feature.setStyle(new Style({ image }));
                    return feature;
                });

            source.addFeatures(features);
        },
        clearSchoolMarkers() {
            schoolSourceRef.current?.clear();
        },
        setRadiusCircle(lat: number, lng: number, radiusMeters: number) {
            const source = radiusSourceRef.current;
            if (!source) return;
            source.clear();

            const circleGeom = circular([lng, lat], radiusMeters, 64);
            circleGeom.transform('EPSG:4326', 'EPSG:3857');

            source.addFeature(new Feature({ geometry: circleGeom }));
        },
        setPoiMarkers(pois: PoiMarker[], categories: Record<string, PoiCategoryMeta>) {
            const source = poiSourceRef.current;
            if (!source) return;
            source.clear();

            const features = pois
                .filter((p) => p.lat && p.lng)
                .map((p) => {
                    const feature = new Feature({
                        geometry: new Point(fromLonLat([p.lng, p.lat])),
                        name: p.name,
                        category: p.category,
                    });

                    const color = categories[p.category]?.color ?? '#94a3b8';

                    feature.setStyle(
                        new Style({
                            image: new CircleStyle({
                                radius: 4,
                                fill: new Fill({ color }),
                                stroke: new Stroke({ color: '#fff', width: 1 }),
                            }),
                        }),
                    );
                    return feature;
                });

            source.addFeatures(features);
        },
        clearPoiMarkers() {
            poiSourceRef.current?.clear();
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
        zoomToExtent(west: number, south: number, east: number, north: number) {
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
    }));

    useEffect(() => {
        if (!mapDivRef.current) return;

        // Basemap tile layer
        const tileLayer = new TileLayer({
            source: createBasemapSource('clean'),
            zIndex: 0,
        });
        tileLayerRef.current = tileLayer;

        // Country mask layer
        const maskSource = new VectorSource();
        const maskLayer = new VectorLayer({
            source: maskSource,
            style: new Style({
                fill: new Fill({ color: 'rgba(245, 245, 245, 0.75)' }),
            }),
            zIndex: 1,
        });

        // Sweden border stroke
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

        // Heatmap tile layer (the PNG tiles)
        const heatmapLayer = new TileLayer({
            source: new XYZ({
                url: '/tiles/2025/{z}/{x}/{y}.png',
                minZoom: 5,
                maxZoom: 12,
            }),
            opacity: 0.45,
            zIndex: 3,
        });
        heatmapLayerRef.current = heatmapLayer;

        // Radius circle layer (3km ring around pin)
        const radiusSource = new VectorSource();
        radiusSourceRef.current = radiusSource;
        const radiusLayer = new VectorLayer({
            source: radiusSource,
            style: new Style({
                stroke: new Stroke({
                    color: 'rgba(100, 116, 139, 0.6)',
                    width: 1.5,
                    lineDash: [6, 4],
                }),
                fill: new Fill({ color: 'rgba(100, 116, 139, 0.04)' }),
            }),
            zIndex: 4,
        });

        // POI markers layer
        const poiSource = new VectorSource();
        poiSourceRef.current = poiSource;
        const poiLayer = new VectorLayer({
            source: poiSource,
            zIndex: 8,
        });

        // Pin marker layer
        const pinSource = new VectorSource();
        pinSourceRef.current = pinSource;
        const pinLayer = new VectorLayer({
            source: pinSource,
            zIndex: 50,
        });

        // School markers layer
        const schoolSource = new VectorSource();
        schoolSourceRef.current = schoolSource;
        const schoolLayer = new VectorLayer({
            source: schoolSource,
            zIndex: 10,
        });

        const map = new Map({
            target: mapDivRef.current,
            layers: [
                tileLayer,
                maskLayer,
                borderLayer,
                heatmapLayer,
                radiusLayer,
                poiLayer,
                schoolLayer,
                pinLayer,
            ],
            view: new View({
                center: fromLonLat([initialCenter[1], initialCenter[0]]),
                zoom: initialZoom,
            }),
        });

        mapInstance.current = map;

        // Load Sweden boundary for mask
        fetch('/data/sweden-boundary.geojson')
            .then((r) => r.json())
            .then((boundary) => {
                const geom = boundary.features?.[0]?.geometry;
                if (!geom) return;

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

                maskSource.addFeature(
                    new Feature({ geometry: new Polygon([worldRing, ...holes]) }),
                );

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
            .catch((err) => console.warn('Failed to load Sweden boundary:', err));

        // Opacity by zoom
        map.getView().on('change:resolution', () => {
            const zoom = map.getView().getZoom() ?? 5;
            let opacity = 0.45;
            if (zoom >= 12) opacity = 0.30;
            if (zoom >= 13) opacity = 0.20;
            heatmapLayer.setOpacity(opacity);
        });

        // Click → drop pin
        map.on('singleclick', (event) => {
            const [lng, lat] = toLonLat(event.coordinate);

            // Check if click is in Sweden bounds
            if (lat < 55.0 || lat > 69.1 || lng < 10.5 || lng > 24.2) return;

            dropPinAtCoords(lat, lng);
            onPinDropRef.current(lat, lng);
        });

        // Escape key clears pin
        const handleEscape = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                pinSource.clear();
                schoolSource.clear();
                radiusSource.clear();
                poiSource.clear();
                onPinClearRef.current();
            }
        };
        document.addEventListener('keydown', handleEscape);

        return () => {
            document.removeEventListener('keydown', handleEscape);
            map.setTarget(undefined);
        };
    }, [initialCenter, initialZoom, dropPinAtCoords]);

    // Basemap switching
    useEffect(() => {
        const tileLayer = tileLayerRef.current;
        if (!tileLayer) return;
        tileLayer.setSource(createBasemapSource(basemapType));
    }, [basemapType]);

    return (
        <div className="relative h-full w-full">
            <div ref={mapDivRef} className="h-full w-full" />
            <ScoreLegend />
            <BasemapControl basemap={basemapType} onBasemapChange={setBasemapType} />
        </div>
    );
});

export default HeatmapMap;
