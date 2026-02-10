import Feature from 'ol/Feature';
import GeoJSON from 'ol/format/GeoJSON';
import Map from 'ol/Map';
import Overlay from 'ol/Overlay';
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
import Icon from 'ol/style/Icon';
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

import { usePage } from '@inertiajs/react';
import type { SharedData } from '@/types';
import type { IsochroneData } from '@/pages/explore/types';
import { useTranslation } from '@/hooks/use-translation';
import { meritToColor, scoreGradientCSS } from '@/lib/score-colors';
import { getPoiMarkerDataUrl, hasIcon } from '@/lib/poi-icons';

import 'ol/ol.css';

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
    setIsochrone: (geojson: IsochroneData) => void;
    clearIsochrone: () => void;
    setPoiMarkers: (pois: PoiMarker[], categories: Record<string, PoiCategoryMeta>) => void;
    clearPoiMarkers: () => void;
    zoomToPoint: (lat: number, lng: number, zoom: number) => void;
    zoomToExtent: (west: number, south: number, east: number, north: number) => void;
    getMap: () => Map | null;
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

function ScoreLegend({
    showVulnAreas,
    onToggleVuln,
}: {
    showVulnAreas: boolean;
    onToggleVuln: (v: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="absolute bottom-6 left-6 z-10 rounded-lg border border-border bg-background/90 px-3 py-2 backdrop-blur-sm">
            <div
                className="mb-1 h-2 w-48 rounded-sm"
                style={{ background: scoreGradientCSS() }}
            />
            <div className="flex justify-between text-[11px] text-muted-foreground">
                <span>{t('map.legend.high_risk')}</span>
                <span>{t('map.legend.strong')}</span>
            </div>
            {/* Vulnerability areas toggle */}
            <div className="mt-2 border-t border-border pt-2">
                <label className="flex cursor-pointer items-center gap-1.5">
                    <input
                        type="checkbox"
                        checked={showVulnAreas}
                        onChange={(e) => onToggleVuln(e.target.checked)}
                        className="accent-primary h-3 w-3"
                    />
                    <span className="text-[10px] text-muted-foreground">Utsatta områden</span>
                </label>
                {showVulnAreas && (
                    <div className="mt-1 ml-4.5 space-y-0.5">
                        <div className="flex items-center gap-1.5">
                            <span className="inline-block h-2 w-4 rounded-sm border border-dashed" style={{ borderColor: '#991b1b', backgroundColor: 'rgba(220, 38, 38, 0.2)' }} />
                            <span className="text-[10px] text-muted-foreground">Särskilt utsatt (-15 p)</span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <span className="inline-block h-2 w-4 rounded-sm border border-dashed" style={{ borderColor: '#c2410c', backgroundColor: 'rgba(249, 115, 22, 0.15)' }} />
                            <span className="text-[10px] text-muted-foreground">Utsatt (-8 p)</span>
                        </div>
                    </div>
                )}
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
    const tooltipRef = useRef<HTMLDivElement>(null);
    const tooltipOverlayRef = useRef<Overlay | null>(null);
    const mapInstance = useRef<Map | null>(null);
    const pinSourceRef = useRef<VectorSource | null>(null);
    const schoolSourceRef = useRef<VectorSource | null>(null);
    const radiusSourceRef = useRef<VectorSource | null>(null);
    const isochroneSourceRef = useRef<VectorSource | null>(null);
    const poiSourceRef = useRef<VectorSource | null>(null);
    const schoolLayerRef = useRef<VectorLayer | null>(null);
    const poiLayerRef = useRef<VectorLayer | null>(null);
    const vulnLayerRef = useRef<VectorLayer | null>(null);
    const tileLayerRef = useRef<TileLayer | null>(null);
    const heatmapLayerRef = useRef<TileLayer | null>(null);
    const [basemapType, setBasemapType] = useState<BasemapType>('clean');
    const [showVulnAreas, setShowVulnAreas] = useState(true);
    const isAdmin = !!usePage<SharedData>().props.auth?.user?.is_admin;
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
            isochroneSourceRef.current?.clear();
            poiSourceRef.current?.clear();
        },
        setSchoolMarkers(schools: SchoolMarker[]) {
            const source = schoolSourceRef.current;
            if (!source) return;
            source.clear();

            const zoom = mapInstance.current?.getView().getZoom() ?? 14;
            const iconSize = zoom >= 15 ? 28 : zoom >= 14 ? 24 : 20;

            const features = schools
                .filter((s) => s.lat && s.lng)
                .map((s) => {
                    const feature = new Feature({
                        geometry: new Point(fromLonLat([s.lng, s.lat])),
                        name: s.name,
                        merit_value: s.merit_value,
                    });

                    const fillColor = meritToColor(s.merit_value);

                    const dataUrl = getPoiMarkerDataUrl('graduation-cap', fillColor, iconSize);
                    if (dataUrl) {
                        const stemRatio = 0.22;
                        const totalH = iconSize + Math.round(iconSize * stemRatio);
                        feature.setStyle(
                            new Style({
                                image: new Icon({
                                    src: dataUrl,
                                    anchor: [0.5, 1],
                                    anchorXUnits: 'fraction',
                                    anchorYUnits: 'fraction',
                                    scale: 1,
                                    size: [iconSize, totalH],
                                }),
                            }),
                        );
                    } else {
                        feature.setStyle(
                            new Style({
                                image: new CircleStyle({
                                    radius: 6,
                                    fill: new Fill({ color: fillColor }),
                                    stroke: new Stroke({ color: '#fff', width: 2 }),
                                }),
                            }),
                        );
                    }

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
            // Also clear isochrone when showing radius (fallback mode)
            isochroneSourceRef.current?.clear();

            const circleGeom = circular([lng, lat], radiusMeters, 64);
            circleGeom.transform('EPSG:4326', 'EPSG:3857');

            source.addFeature(new Feature({ geometry: circleGeom }));
        },
        setIsochrone(geojson: IsochroneData) {
            const source = isochroneSourceRef.current;
            if (!source) return;
            source.clear();

            // Clear old radius circle when showing isochrone
            radiusSourceRef.current?.clear();

            const format = new GeoJSON();
            const features = format.readFeatures(geojson, {
                featureProjection: 'EPSG:3857',
            });

            source.addFeatures(features);
        },
        clearIsochrone() {
            isochroneSourceRef.current?.clear();
        },
        setPoiMarkers(pois: PoiMarker[], categories: Record<string, PoiCategoryMeta>) {
            const source = poiSourceRef.current;
            if (!source) return;
            source.clear();

            const zoom = mapInstance.current?.getView().getZoom() ?? 14;
            const iconSize = zoom >= 15 ? 28 : zoom >= 14 ? 24 : 20;

            const features = pois
                .filter((p) => p.lat && p.lng)
                .map((p) => {
                    const feature = new Feature({
                        geometry: new Point(fromLonLat([p.lng, p.lat])),
                        name: p.name,
                        category: p.category,
                    });

                    const meta = categories[p.category];
                    const color = meta?.color ?? '#94a3b8';
                    const iconName = meta?.icon;

                    // Use Lucide SVG icon marker if available, otherwise colored dot
                    if (iconName && hasIcon(iconName)) {
                        const dataUrl = getPoiMarkerDataUrl(iconName, color, iconSize);
                        if (dataUrl) {
                            const stemRatio = 0.22;
                            const totalH = iconSize + Math.round(iconSize * stemRatio);
                            feature.setStyle(
                                new Style({
                                    image: new Icon({
                                        src: dataUrl,
                                        anchor: [0.5, 1],
                                        anchorXUnits: 'fraction',
                                        anchorYUnits: 'fraction',
                                        scale: 1,
                                        size: [iconSize, totalH],
                                    }),
                                }),
                            );
                            return feature;
                        }
                    }

                    // Fallback: colored circle
                    feature.setStyle(
                        new Style({
                            image: new CircleStyle({
                                radius: 5,
                                fill: new Fill({ color }),
                                stroke: new Stroke({ color: '#fff', width: 1.5 }),
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
        getMap() {
            return mapInstance.current;
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

        // Radius circle layer (fallback when isochrone unavailable)
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

        // Isochrone layer — replaces radius circle when Valhalla is available
        const isochroneSource = new VectorSource();
        isochroneSourceRef.current = isochroneSource;
        const isochroneLayer = new VectorLayer({
            source: isochroneSource,
            zIndex: 4,
            style: (feature) => {
                const contour = feature.get('contour') as number;
                const opacity = contour <= 5 ? 0.15 : contour <= 10 ? 0.08 : 0.04;
                const strokeOpacity = contour <= 5 ? 0.6 : contour <= 10 ? 0.4 : 0.25;

                return new Style({
                    fill: new Fill({
                        color: `rgba(59, 130, 246, ${opacity})`,
                    }),
                    stroke: new Stroke({
                        color: `rgba(59, 130, 246, ${strokeOpacity})`,
                        width: contour <= 5 ? 2 : 1.5,
                        lineDash: contour >= 15 ? [6, 4] : undefined,
                    }),
                });
            },
        });

        // POI markers layer
        const poiSource = new VectorSource();
        poiSourceRef.current = poiSource;
        const poiLayer = new VectorLayer({
            source: poiSource,
            zIndex: 8,
        });
        poiLayerRef.current = poiLayer;

        // Vulnerability areas layer
        const vulnSource = new VectorSource();
        const vulnLayer = new VectorLayer({
            source: vulnSource,
            zIndex: 5,
            minZoom: 9,
            style: (feature, resolution) => {
                const props = feature.getProperties();
                const fillColor = props.color ?? '#ef4444';
                const r = parseInt(fillColor.slice(1, 3), 16);
                const g = parseInt(fillColor.slice(3, 5), 16);
                const b = parseInt(fillColor.slice(5, 7), 16);
                // Scale stroke with zoom: thin at low zoom, thicker when close
                // resolution ~20 ≈ z13, ~80 ≈ z11, ~300 ≈ z9
                const zoom = resolution < 30 ? 13 : resolution < 100 ? 11 : 9;
                const width = zoom >= 13 ? 1.2 : zoom >= 11 ? 0.8 : 0.5;
                const dash = zoom >= 13 ? [4, 8] : zoom >= 11 ? [3, 6] : [2, 5];
                const fillOpacity = zoom >= 13 ? (props.opacity ?? 0.15) : zoom >= 11 ? 0.10 : 0.06;
                return new Style({
                    fill: new Fill({
                        color: `rgba(${r}, ${g}, ${b}, ${fillOpacity})`,
                    }),
                    stroke: new Stroke({
                        color: props.border_color ?? '#991b1b',
                        width,
                        lineDash: dash,
                    }),
                });
            },
        });
        vulnLayerRef.current = vulnLayer;

        // Fetch vulnerability areas
        fetch('/api/vulnerability-areas')
            .then((r) => r.json())
            .then((areas: Array<Record<string, unknown>>) => {
                const geojsonFormat = new GeoJSON();
                const features: Feature[] = [];
                for (const area of areas) {
                    const result = geojsonFormat.readFeature(
                        { type: 'Feature', geometry: area.geojson, properties: {} },
                        { featureProjection: 'EPSG:3857' },
                    );
                    const feat = Array.isArray(result) ? result[0] : result;
                    if (!feat) continue;
                    feat.setProperties({
                        name: area.name,
                        tier: area.tier,
                        tier_label: area.tier_label,
                        police_region: area.police_region,
                        penalty_points: area.penalty_points,
                        color: area.color,
                        border_color: area.border_color,
                        opacity: area.opacity,
                        is_vuln: true,
                    });
                    features.push(feat);
                }
                vulnSource.addFeatures(features);
            })
            .catch((err) => console.warn('Failed to load vulnerability areas:', err));

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
        schoolLayerRef.current = schoolLayer;

        const map = new Map({
            target: mapDivRef.current,
            layers: [
                tileLayer,
                maskLayer,
                borderLayer,
                heatmapLayer,
                radiusLayer,
                isochroneLayer,
                vulnLayer,
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

        // Fit to appropriate initial extent based on container size
        const containerH = mapDivRef.current?.clientHeight ?? 600;
        const isMobileMap = containerH < 500;
        const swedenExtent: [number, number, number, number] = isMobileMap
            ? [10.5, 55.0, 21, 63] // Southern Sweden for compact map
            : [10.5, 55.0, 24.5, 69.5]; // Full Sweden for desktop
        map.getView().fit(
            transformExtent(swedenExtent, 'EPSG:4326', 'EPSG:3857'),
            {
                padding: isMobileMap ? [10, 10, 10, 10] : [40, 40, 40, 40],
            },
        );

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

        // Tooltip overlay
        if (tooltipRef.current) {
            const tooltipOverlay = new Overlay({
                element: tooltipRef.current,
                positioning: 'bottom-center',
                offset: [0, -12],
                stopEvent: false,
            });
            tooltipOverlayRef.current = tooltipOverlay;
            map.addOverlay(tooltipOverlay);
        }

        // Hover tooltip for school, POI, and vulnerability area layers (throttled to 50ms)
        let lastHoverTime = 0;
        const hoverableLayers = new Set([schoolLayer, poiLayer, vulnLayer]);

        map.on('pointermove', (event) => {
            const now = performance.now();
            if (now - lastHoverTime < 50) return;
            lastHoverTime = now;

            const overlay = tooltipOverlayRef.current;
            const el = tooltipRef.current;
            if (!overlay || !el) return;

            const pixel = event.pixel;
            let found = false;

            map.forEachFeatureAtPixel(
                pixel,
                (feat, layer) => {
                    if (found) return;
                    const feature = feat as Feature;

                    // Vulnerability areas
                    if (layer === vulnLayer && feature.get('is_vuln')) {
                        const name = feature.get('name') as string;
                        const tierLabel = feature.get('tier_label') as string;
                        const penalty = feature.get('penalty_points') as number | null;
                        const region = feature.get('police_region') as string | null;
                        let html = `<strong>${name}</strong>`;
                        html += `<br><span style="opacity:0.8">${tierLabel}</span>`;
                        if (region) html += `<br><span style="opacity:0.7">${region}</span>`;
                        if (isAdmin && penalty !== null) html += `<br><span style="color:#991b1b;font-weight:600">Avdrag: ${penalty} poäng</span>`;
                        html += `<br><span style="opacity:0.5;font-size:10px">Polismyndigheten 2025</span>`;
                        el.innerHTML = html;
                        el.style.display = 'block';
                        overlay.setPosition(event.coordinate);
                        found = true;
                        return;
                    }

                    if (layer === schoolLayer) {
                        const name = feature.get('name') as string | undefined;
                        const merit = feature.get('merit_value') as number | null;
                        if (name) {
                            let html = `<strong>${name}</strong>`;
                            if (merit !== null && merit !== undefined) {
                                html += `<br><span style="opacity:0.8">Merit: ${merit}</span>`;
                            }
                            el.innerHTML = html;
                            el.style.display = 'block';
                            overlay.setPosition(event.coordinate);
                            found = true;
                        }
                    } else {
                        const clustered = feature.get('features') as Feature[] | undefined;
                        const poiFeatures = clustered ?? [feature];
                        const isPoi =
                            layer === poiLayer ||
                            poiFeatures[0]?.get('is_poi') ||
                            feature.get('category');

                        if (isPoi) {
                            if (poiFeatures.length === 1) {
                                const poi = poiFeatures[0];
                                const name = poi.get('name') as string | undefined;
                                const category = poi.get('category') as string | undefined;
                                const categoryLabel = category?.replace(/_/g, ' ') ?? 'POI';
                                const label = name || categoryLabel;
                                let html = `<strong>${label}</strong>`;
                                if (name && category) {
                                    html += `<br><span style="opacity:0.8">${categoryLabel}</span>`;
                                }
                                el.innerHTML = html;
                                el.style.display = 'block';
                                overlay.setPosition(event.coordinate);
                                found = true;
                            } else if (poiFeatures.length > 1) {
                                let pos = 0,
                                    neg = 0,
                                    other = 0;
                                for (const f of poiFeatures) {
                                    const s = f.get('sentiment');
                                    if (s === 'positive') pos++;
                                    else if (s === 'negative') neg++;
                                    else other++;
                                }
                                const parts: string[] = [];
                                if (neg > 0) parts.push(`${neg} nuisance${neg > 1 ? 's' : ''}`);
                                if (pos > 0) parts.push(`${pos} amenit${pos > 1 ? 'ies' : 'y'}`);
                                if (other > 0) parts.push(`${other} other`);
                                el.innerHTML = `<strong>${poiFeatures.length} POIs</strong><br><span style="opacity:0.8">${parts.join(' · ')}</span>`;
                                el.style.display = 'block';
                                overlay.setPosition(event.coordinate);
                                found = true;
                            }
                        }
                    }
                },
                { layerFilter: (layer) => hoverableLayers.has(layer as VectorLayer) },
            );

            if (!found) {
                el.style.display = 'none';
                overlay.setPosition(undefined);
            }

            const target = map.getTargetElement() as HTMLElement;
            target.style.cursor = found ? 'pointer' : '';
        });

        // Escape key clears pin
        const handleEscape = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                pinSource.clear();
                schoolSource.clear();
                radiusSource.clear();
                isochroneSource.clear();
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
            <div
                ref={tooltipRef}
                className="pointer-events-none rounded-md bg-background/95 px-2.5 py-1.5 text-xs text-foreground shadow-lg ring-1 ring-border backdrop-blur-sm"
                style={{ display: 'none', whiteSpace: 'nowrap' }}
            />
            <ScoreLegend
                showVulnAreas={showVulnAreas}
                onToggleVuln={(v) => {
                    setShowVulnAreas(v);
                    vulnLayerRef.current?.setVisible(v);
                }}
            />
            <BasemapControl basemap={basemapType} onBasemapChange={setBasemapType} />
        </div>
    );
});

export default HeatmapMap;
