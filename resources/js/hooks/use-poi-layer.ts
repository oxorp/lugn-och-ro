import Feature from 'ol/Feature';
import type OLMap from 'ol/Map';
import { circular } from 'ol/geom/Polygon';
import Point from 'ol/geom/Point';
import VectorLayer from 'ol/layer/Vector';
import { fromLonLat, toLonLat, transformExtent } from 'ol/proj';
import Cluster from 'ol/source/Cluster';
import VectorSource from 'ol/source/Vector';
import CircleStyle from 'ol/style/Circle';
import Fill from 'ol/style/Fill';
import Icon from 'ol/style/Icon';
import Stroke from 'ol/style/Stroke';
import Style from 'ol/style/Style';
import Text from 'ol/style/Text';
import { useCallback, useEffect, useRef, useState } from 'react';

import type { PoiCategory, PoiFeatureData } from '@/lib/poi-config';
import { SENTIMENT_COLORS, loadPoiPreferences, savePoiPreferences } from '@/lib/poi-config';
import { getPoiMarkerDataUrl } from '@/lib/poi-icons';

// Style cache to avoid re-creating styles on every render
const styleCache: Record<string, Style> = {};

function getSentimentColor(sentiment: string): string {
    return SENTIMENT_COLORS[sentiment] ?? SENTIMENT_COLORS.neutral;
}

function getSimplePoiStyle(sentiment: string): Style {
    const key = `simple-${sentiment}`;
    if (!styleCache[key]) {
        styleCache[key] = new Style({
            image: new CircleStyle({
                radius: 5,
                fill: new Fill({ color: getSentimentColor(sentiment) }),
                stroke: new Stroke({ color: '#ffffff', width: 1.5 }),
            }),
        });
    }
    return styleCache[key];
}

function getIconPoiStyle(iconName: string, color: string, size: number): Style | null {
    const key = `icon-${iconName}-${color}-${size}`;
    if (styleCache[key]) return styleCache[key];

    const dataUrl = getPoiMarkerDataUrl(iconName, color, size);
    if (!dataUrl) return null;

    const stemRatio = 0.22;
    const totalH = size + Math.round(size * stemRatio);

    styleCache[key] = new Style({
        image: new Icon({
            src: dataUrl,
            anchor: [0.5, 1],
            anchorXUnits: 'fraction',
            anchorYUnits: 'fraction',
            scale: 1,
            size: [size, totalH],
        }),
    });
    return styleCache[key];
}

function getClusterStyle(count: number, dominantSentiment: string): Style {
    const radius = Math.min(22, 10 + Math.log2(count) * 3);
    const color = getSentimentColor(dominantSentiment);
    return new Style({
        image: new CircleStyle({
            radius,
            fill: new Fill({ color }),
            stroke: new Stroke({ color: '#ffffff', width: 2 }),
        }),
        text: new Text({
            text: count.toString(),
            fill: new Fill({ color: '#ffffff' }),
            font: `bold ${Math.max(11, radius - 1)}px Inter, system-ui, sans-serif`,
        }),
    });
}

function getDominantSentiment(features: Feature[]): string {
    let pos = 0;
    let neg = 0;
    for (const f of features) {
        const s = f.get('sentiment');
        if (s === 'positive') pos++;
        else if (s === 'negative') neg++;
    }
    if (neg > pos) return 'negative';
    if (pos > neg) return 'positive';
    return 'neutral';
}

function getClusterDistance(zoom: number): number {
    if (zoom >= 16) return 0;
    if (zoom >= 14) return 25;
    if (zoom >= 12) return 40;
    return 60;
}

const impactRadiusStyle = new Style({
    fill: new Fill({ color: 'rgba(249, 115, 22, 0.08)' }),
    stroke: new Stroke({
        color: 'rgba(249, 115, 22, 0.35)',
        width: 1.5,
        lineDash: [6, 4],
    }),
});

interface UsePoiLayerReturn {
    poiLayer: VectorLayer | null;
    categories: PoiCategory[];
    enabledCategories: Set<string>;
    visibleCount: number;
    toggleCategory: (slug: string) => void;
    toggleGroup: (slugs: string[]) => void;
    enableAll: () => void;
    disableAll: () => void;
    resetDefaults: () => void;
    getFeatureAtPixel: (pixel: number[]) => Feature | null;
    showImpactRadius: (feature: Feature) => void;
    clearImpactRadius: () => void;
}

interface CategoryConfig {
    icon: string;
    color: string;
    impact_radius_km: number | null;
}

export function usePoiLayer(map: OLMap | null): UsePoiLayerReturn {
    const poiSourceRef = useRef<VectorSource>(new VectorSource());
    const impactSourceRef = useRef<VectorSource>(new VectorSource());
    const clusterSourceRef = useRef<Cluster | null>(null);
    const poiLayerRef = useRef<VectorLayer | null>(null);
    const impactLayerRef = useRef<VectorLayer | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const lastZoomRef = useRef<number>(0);
    const currentZoomRef = useRef<number>(0);
    const categoryConfigRef = useRef<Map<string, CategoryConfig>>(new Map());
    const [categories, setCategories] = useState<PoiCategory[]>([]);
    const [enabledCategories, setEnabledCategories] = useState<Set<string>>(loadPoiPreferences);
    const [visibleCount, setVisibleCount] = useState(0);
    const enabledCategoriesRef = useRef(enabledCategories);

    // Keep ref in sync
    useEffect(() => {
        enabledCategoriesRef.current = enabledCategories;
        savePoiPreferences(enabledCategories);
    }, [enabledCategories]);

    // Fetch categories from API on mount
    useEffect(() => {
        fetch('/api/pois/categories')
            .then((r) => r.json())
            .then((data: PoiCategory[]) => {
                setCategories(data);
                // Build category config lookup for icon styling + impact radius
                const lookup = new Map<string, CategoryConfig>();
                for (const cat of data) {
                    lookup.set(cat.slug, {
                        icon: cat.icon,
                        color: cat.color,
                        impact_radius_km: cat.impact_radius_km,
                    });
                }
                categoryConfigRef.current = lookup;
            })
            .catch(console.error);
    }, []);

    // Initialize POI layer
    useEffect(() => {
        if (!map) return;

        const poiSource = poiSourceRef.current;

        const clusterSource = new Cluster({
            distance: 40,
            minDistance: 20,
            source: poiSource,
        });
        clusterSourceRef.current = clusterSource;

        // Impact radius layer (below POI markers)
        const impactLayer = new VectorLayer({
            source: impactSourceRef.current,
            zIndex: 4,
            style: impactRadiusStyle,
        });
        impactLayerRef.current = impactLayer;
        map.addLayer(impactLayer);

        const poiLayer = new VectorLayer({
            source: clusterSource,
            zIndex: 5,
            style: (feature) => {
                const clusteredFeatures = feature.get('features') as Feature[];
                if (!clusteredFeatures || clusteredFeatures.length === 0) {
                    return new Style();
                }

                if (clusteredFeatures.length === 1) {
                    const poi = clusteredFeatures[0];
                    const sentiment = poi.get('sentiment') ?? 'neutral';
                    const zoom = currentZoomRef.current;

                    // At zoom 13+, use Lucide icon markers
                    if (zoom >= 13) {
                        const category = poi.get('category') as string;
                        const config = categoryConfigRef.current.get(category);
                        if (config) {
                            const size = zoom >= 15 ? 28 : zoom >= 14 ? 24 : 20;
                            const iconStyle = getIconPoiStyle(config.icon, config.color, size);
                            if (iconStyle) return iconStyle;
                        }
                    }

                    // Fallback: simple colored circle
                    return getSimplePoiStyle(sentiment);
                }

                const dominant = getDominantSentiment(clusteredFeatures);
                return getClusterStyle(clusteredFeatures.length, dominant);
            },
        });
        poiLayerRef.current = poiLayer;

        map.addLayer(poiLayer);

        return () => {
            map.removeLayer(poiLayer);
            map.removeLayer(impactLayer);
            poiLayerRef.current = null;
            impactLayerRef.current = null;
            clusterSourceRef.current = null;
        };
    }, [map]);

    // Load POIs for current viewport
    const loadPois = useCallback(() => {
        if (!map) return;

        const view = map.getView();
        const zoom = Math.round(view.getZoom() ?? 0);
        currentZoomRef.current = zoom;

        // No POIs below zoom 8
        if (zoom < 8) {
            poiSourceRef.current.clear();
            setVisibleCount(0);
            return;
        }

        // Update cluster distance based on zoom
        const distance = getClusterDistance(zoom);
        clusterSourceRef.current?.setDistance(distance);

        // Cancel previous request
        abortRef.current?.abort();
        abortRef.current = new AbortController();

        const extent = view.calculateExtent(map.getSize());
        const [minLng, minLat, maxLng, maxLat] = transformExtent(
            extent,
            'EPSG:3857',
            'EPSG:4326',
        );

        // Pad bbox 10% for preloading
        const padLng = (maxLng - minLng) * 0.1;
        const padLat = (maxLat - minLat) * 0.1;

        const enabled = enabledCategoriesRef.current;
        const categoryParam = [...enabled].join(',');

        if (enabled.size === 0) {
            poiSourceRef.current.clear();
            setVisibleCount(0);
            return;
        }

        const params = new URLSearchParams({
            bbox: `${minLng - padLng},${minLat - padLat},${maxLng + padLng},${maxLat + padLat}`,
            zoom: zoom.toString(),
            categories: categoryParam,
        });

        fetch(`/api/pois?${params}`, {
            signal: abortRef.current.signal,
        })
            .then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            })
            .then((pois: PoiFeatureData[]) => {
                const source = poiSourceRef.current;
                source.clear();

                const features = pois.map((poi) => {
                    const feature = new Feature({
                        geometry: new Point(
                            fromLonLat([parseFloat(poi.lng), parseFloat(poi.lat)]),
                        ),
                    });
                    feature.set('poi_id', poi.id);
                    feature.set('poi_type', poi.poi_type);
                    feature.set('category', poi.category);
                    feature.set('sentiment', poi.sentiment);
                    feature.set('name', poi.name);
                    feature.set('is_poi', true);
                    return feature;
                });

                source.addFeatures(features);
                setVisibleCount(pois.length);
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    console.error('Failed to load POIs:', err);
                }
            });
    }, [map]);

    // Listen to map movements with debounce
    useEffect(() => {
        if (!map) return;

        const handleMoveEnd = () => {
            const zoom = Math.round(map.getView().getZoom() ?? 0);
            currentZoomRef.current = zoom;

            // Update cluster distance immediately on zoom change
            if (zoom !== lastZoomRef.current) {
                const prevZoom = lastZoomRef.current;
                lastZoomRef.current = zoom;
                const distance = getClusterDistance(zoom);
                clusterSourceRef.current?.setDistance(distance);

                // Force re-render when crossing zoom 13 or 15 threshold (icon size changes)
                if (
                    (zoom >= 13 && prevZoom < 13) ||
                    (zoom < 13 && prevZoom >= 13) ||
                    (zoom >= 15 && prevZoom < 15) ||
                    (zoom < 15 && prevZoom >= 15)
                ) {
                    poiLayerRef.current?.changed();
                }
            }

            if (debounceRef.current) clearTimeout(debounceRef.current);
            debounceRef.current = setTimeout(loadPois, 300);
        };

        map.on('moveend', handleMoveEnd);

        // Initial load
        loadPois();

        return () => {
            map.un('moveend', handleMoveEnd);
            if (debounceRef.current) clearTimeout(debounceRef.current);
            abortRef.current?.abort();
        };
    }, [map, loadPois]);

    // Reload when enabled categories change
    useEffect(() => {
        loadPois();
    }, [enabledCategories, loadPois]);

    const toggleCategory = useCallback((slug: string) => {
        setEnabledCategories((prev) => {
            const next = new Set(prev);
            if (next.has(slug)) {
                next.delete(slug);
            } else {
                next.add(slug);
            }
            return next;
        });
    }, []);

    const toggleGroup = useCallback((slugs: string[]) => {
        setEnabledCategories((prev) => {
            const next = new Set(prev);
            const allEnabled = slugs.every((s) => next.has(s));
            if (allEnabled) {
                slugs.forEach((s) => next.delete(s));
            } else {
                slugs.forEach((s) => next.add(s));
            }
            return next;
        });
    }, []);

    const enableAll = useCallback(() => {
        const all = new Set<string>();
        for (const cat of categories) {
            all.add(cat.slug);
        }
        setEnabledCategories(all);
    }, [categories]);

    const disableAll = useCallback(() => {
        setEnabledCategories(new Set());
    }, []);

    const resetDefaults = useCallback(() => {
        setEnabledCategories(loadPoiPreferences());
    }, []);

    const getFeatureAtPixel = useCallback(
        (pixel: number[]): Feature | null => {
            if (!map || !poiLayerRef.current) return null;
            return (
                map.forEachFeatureAtPixel(pixel, (f) => f as Feature, {
                    layerFilter: (l) => l === poiLayerRef.current,
                }) ?? null
            );
        },
        [map],
    );

    const showImpactRadius = useCallback(
        (feature: Feature) => {
            const source = impactSourceRef.current;
            source.clear();

            const category = feature.get('category') as string;
            const config = categoryConfigRef.current.get(category);
            if (!config?.impact_radius_km) return;

            const geom = feature.getGeometry();
            if (!geom) return;

            const coords = (geom as Point).getCoordinates();
            const lonLat = toLonLat(coords);
            const circle = circular(lonLat, config.impact_radius_km * 1000, 64);
            circle.transform('EPSG:4326', 'EPSG:3857');

            source.addFeature(new Feature(circle));
        },
        [],
    );

    const clearImpactRadius = useCallback(() => {
        impactSourceRef.current.clear();
    }, []);

    return {
        poiLayer: poiLayerRef.current,
        categories,
        enabledCategories,
        visibleCount,
        toggleCategory,
        toggleGroup,
        enableAll,
        disableAll,
        resetDefaults,
        getFeatureAtPixel,
        showImpactRadius,
        clearImpactRadius,
    };
}
