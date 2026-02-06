import Feature from 'ol/Feature';
import Map from 'ol/Map';
import View from 'ol/View';
import GeoJSON from 'ol/format/GeoJSON';
import TileLayer from 'ol/layer/Tile';
import VectorLayer from 'ol/layer/Vector';
import { fromLonLat } from 'ol/proj';
import OSM from 'ol/source/OSM';
import VectorSource from 'ol/source/Vector';
import Fill from 'ol/style/Fill';
import Stroke from 'ol/style/Stroke';
import Style from 'ol/style/Style';
import { useCallback, useEffect, useRef, useState } from 'react';

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
}

interface DesoMapProps {
    initialCenter: [number, number];
    initialZoom: number;
    onFeatureSelect: (
        properties: DesoProperties | null,
        score: DesoScore | null,
    ) => void;
}

// Color stops: purple(0) -> red-purple(25) -> yellow(50) -> light-green(75) -> deep-green(100)
const COLOR_STOPS = [
    { score: 0, r: 74, g: 0, b: 114 },    // #4a0072
    { score: 25, r: 156, g: 29, b: 110 },  // #9c1d6e
    { score: 50, r: 240, g: 192, b: 64 },  // #f0c040
    { score: 75, r: 106, g: 191, b: 75 },  // #6abf4b
    { score: 100, r: 26, g: 122, b: 46 },  // #1a7a2e
];

function interpolateColor(score: number): [number, number, number, number] {
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
        180, // alpha
    ];
}

const noDataStyle = new Style({
    fill: new Fill({ color: 'rgba(200, 200, 200, 0.3)' }),
    stroke: new Stroke({
        color: 'rgba(150, 150, 150, 0.6)',
        width: 1,
        lineDash: [4, 4],
    }),
});

const selectedStyle = new Style({
    fill: new Fill({ color: 'rgba(255, 165, 0, 0.5)' }),
    stroke: new Stroke({ color: 'rgba(255, 140, 0, 1)', width: 2.5 }),
});

function ScoreLegend() {
    return (
        <div className="absolute bottom-6 left-6 z-10 rounded-lg bg-white/90 px-4 py-3 shadow-lg backdrop-blur-sm">
            <div className="mb-1.5 text-xs font-medium text-gray-700">
                Neighborhood Score
            </div>
            <div
                className="mb-1 h-3 w-48 rounded-sm"
                style={{
                    background:
                        'linear-gradient(to right, #4a0072, #9c1d6e, #f0c040, #6abf4b, #1a7a2e)',
                }}
            />
            <div className="flex justify-between text-[10px] text-gray-500">
                <span>High Risk</span>
                <span>Mixed</span>
                <span>Strong</span>
            </div>
        </div>
    );
}

export default function DesoMap({
    initialCenter,
    initialZoom,
    onFeatureSelect,
}: DesoMapProps) {
    const mapRef = useRef<HTMLDivElement>(null);
    const mapInstance = useRef<Map | null>(null);
    const hoveredFeature = useRef<Feature | null>(null);
    const selectedFeature = useRef<Feature | null>(null);
    const scoresRef = useRef<Record<string, DesoScore>>({});
    const [loading, setLoading] = useState(true);

    const handleFeatureSelect = useCallback(
        (properties: DesoProperties | null, score: DesoScore | null) => {
            onFeatureSelect(properties, score);
        },
        [onFeatureSelect],
    );

    useEffect(() => {
        if (!mapRef.current) return;

        const vectorSource = new VectorSource();

        function styleForFeature(feature: Feature): Style {
            const desoCode = feature.get('deso_code');
            const scoreData = scoresRef.current[desoCode];

            if (!scoreData) return noDataStyle;

            const [r, g, b, a] = interpolateColor(scoreData.score);
            return new Style({
                fill: new Fill({ color: `rgba(${r}, ${g}, ${b}, ${a / 255})` }),
                stroke: new Stroke({
                    color: `rgba(${Math.max(0, r - 30)}, ${Math.max(0, g - 30)}, ${Math.max(0, b - 30)}, 0.7)`,
                    width: 0.5,
                }),
            });
        }

        const vectorLayer = new VectorLayer({
            source: vectorSource,
            style: (feature) => styleForFeature(feature as Feature),
        });

        const map = new Map({
            target: mapRef.current,
            layers: [
                new TileLayer({
                    source: new OSM(),
                }),
                vectorLayer,
            ],
            view: new View({
                center: fromLonLat([initialCenter[1], initialCenter[0]]),
                zoom: initialZoom,
            }),
        });

        mapInstance.current = map;

        // Fetch both GeoJSON and scores in parallel
        Promise.all([
            fetch('/data/deso.geojson').then((r) => r.json()),
            fetch('/api/deso/scores?year=2024').then((r) => r.json()),
        ])
            .then(([geojson, scores]) => {
                // Parse scores - they come as JSON strings from the API
                const parsedScores: Record<string, DesoScore> = {};
                for (const [code, data] of Object.entries(scores)) {
                    const d = data as Record<string, unknown>;
                    parsedScores[code] = {
                        deso_code: code,
                        score: Number(d.score),
                        trend_1y: d.trend_1y != null ? Number(d.trend_1y) : null,
                        factor_scores:
                            typeof d.factor_scores === 'string'
                                ? JSON.parse(d.factor_scores)
                                : (d.factor_scores as Record<string, number> | null),
                        top_positive:
                            typeof d.top_positive === 'string'
                                ? JSON.parse(d.top_positive)
                                : (d.top_positive as string[] | null),
                        top_negative:
                            typeof d.top_negative === 'string'
                                ? JSON.parse(d.top_negative)
                                : (d.top_negative as string[] | null),
                    };
                }
                scoresRef.current = parsedScores;

                const features = new GeoJSON().readFeatures(geojson, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857',
                });
                vectorSource.addFeatures(features);
                setLoading(false);
            })
            .catch((err) => {
                console.error('Failed to load map data:', err);
                setLoading(false);
            });

        // Hover interaction
        map.on('pointermove', (evt) => {
            if (evt.dragging) return;

            const feature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => f as Feature,
            );

            if (
                hoveredFeature.current &&
                hoveredFeature.current !== feature &&
                hoveredFeature.current !== selectedFeature.current
            ) {
                hoveredFeature.current.setStyle(undefined);
            }

            if (feature && feature !== selectedFeature.current) {
                const desoCode = feature.get('deso_code');
                const scoreData = scoresRef.current[desoCode];
                if (scoreData) {
                    const [r, g, b] = interpolateColor(scoreData.score);
                    feature.setStyle(
                        new Style({
                            fill: new Fill({
                                color: `rgba(${r}, ${g}, ${b}, 0.85)`,
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
            } else if (!feature) {
                map.getTargetElement().style.cursor = '';
            }

            hoveredFeature.current = feature ?? null;
        });

        // Click interaction
        map.on('click', (evt) => {
            const feature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => f as Feature,
            );

            if (selectedFeature.current) {
                selectedFeature.current.setStyle(undefined);
            }

            if (feature) {
                feature.setStyle(selectedStyle);
                selectedFeature.current = feature;

                const props = feature.getProperties();
                const desoCode = props.deso_code;
                const scoreData = scoresRef.current[desoCode] || null;

                handleFeatureSelect(
                    {
                        deso_code: desoCode,
                        deso_name: props.deso_name,
                        kommun_code: props.kommun_code,
                        kommun_name: props.kommun_name,
                        lan_code: props.lan_code,
                        lan_name: props.lan_name,
                        area_km2: props.area_km2,
                    },
                    scoreData,
                );
            } else {
                selectedFeature.current = null;
                handleFeatureSelect(null, null);
            }
        });

        return () => {
            map.setTarget(undefined);
        };
    }, [initialCenter, initialZoom, handleFeatureSelect]);

    return (
        <div className="relative h-full w-full">
            <div ref={mapRef} className="h-full w-full" />
            <ScoreLegend />
            {loading && (
                <div className="bg-background/80 absolute inset-0 flex items-center justify-center">
                    <div className="text-muted-foreground text-sm">
                        Loading DeSO areas...
                    </div>
                </div>
            )}
        </div>
    );
}
