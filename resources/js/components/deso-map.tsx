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

interface DesoMapProps {
    initialCenter: [number, number];
    initialZoom: number;
    onFeatureSelect: (properties: DesoProperties | null) => void;
}

const defaultStyle = new Style({
    fill: new Fill({ color: 'rgba(30, 80, 160, 0.15)' }),
    stroke: new Stroke({ color: 'rgba(30, 80, 160, 0.5)', width: 1 }),
});

const hoverStyle = new Style({
    fill: new Fill({ color: 'rgba(30, 80, 160, 0.35)' }),
    stroke: new Stroke({ color: 'rgba(30, 80, 160, 0.8)', width: 1.5 }),
});

const selectedStyle = new Style({
    fill: new Fill({ color: 'rgba(255, 165, 0, 0.4)' }),
    stroke: new Stroke({ color: 'rgba(255, 140, 0, 1)', width: 2 }),
});

export default function DesoMap({
    initialCenter,
    initialZoom,
    onFeatureSelect,
}: DesoMapProps) {
    const mapRef = useRef<HTMLDivElement>(null);
    const mapInstance = useRef<Map | null>(null);
    const hoveredFeature = useRef<Feature | null>(null);
    const selectedFeature = useRef<Feature | null>(null);
    const [loading, setLoading] = useState(true);

    const handleFeatureSelect = useCallback(
        (properties: DesoProperties | null) => {
            onFeatureSelect(properties);
        },
        [onFeatureSelect],
    );

    useEffect(() => {
        if (!mapRef.current) return;

        const vectorSource = new VectorSource();

        const vectorLayer = new VectorLayer({
            source: vectorSource,
            style: defaultStyle,
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

        // Fetch DeSO GeoJSON (static file served by nginx, falls back to API)
        fetch('/data/deso.geojson')
            .then((res) => res.json())
            .then((geojson) => {
                const features = new GeoJSON().readFeatures(geojson, {
                    dataProjection: 'EPSG:4326',
                    featureProjection: 'EPSG:3857',
                });
                vectorSource.addFeatures(features);
                setLoading(false);
            })
            .catch((err) => {
                console.error('Failed to load DeSO data:', err);
                setLoading(false);
            });

        // Hover interaction
        map.on('pointermove', (evt) => {
            if (evt.dragging) return;

            const feature = map.forEachFeatureAtPixel(
                evt.pixel,
                (f) => f as Feature,
            );

            // Reset previous hovered feature (unless it's selected)
            if (
                hoveredFeature.current &&
                hoveredFeature.current !== feature &&
                hoveredFeature.current !== selectedFeature.current
            ) {
                hoveredFeature.current.setStyle(undefined);
            }

            if (feature && feature !== selectedFeature.current) {
                feature.setStyle(hoverStyle);
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

            // Reset previous selected feature
            if (selectedFeature.current) {
                selectedFeature.current.setStyle(undefined);
            }

            if (feature) {
                feature.setStyle(selectedStyle);
                selectedFeature.current = feature;

                const props = feature.getProperties();
                handleFeatureSelect({
                    deso_code: props.deso_code,
                    deso_name: props.deso_name,
                    kommun_code: props.kommun_code,
                    kommun_name: props.kommun_name,
                    lan_code: props.lan_code,
                    lan_name: props.lan_name,
                    area_km2: props.area_km2,
                });
            } else {
                selectedFeature.current = null;
                handleFeatureSelect(null);
            }
        });

        return () => {
            map.setTarget(undefined);
        };
    }, [initialCenter, initialZoom, handleFeatureSelect]);

    return (
        <div className="relative h-full w-full">
            <div ref={mapRef} className="h-full w-full" />
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
