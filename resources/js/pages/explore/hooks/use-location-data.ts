import type { HeatmapMapHandle } from '@/components/deso-map';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';

import type { LocationData } from '../types';

export function useLocationData(mapRef: React.RefObject<HeatmapMapHandle | null>) {
    const [locationData, setLocationData] = useState<LocationData | null>(null);
    const [locationName, setLocationName] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [pinActive, setPinActive] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

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

                // Build street address: "Street Housenumber" or just "Street"
                if (props.street) {
                    const street = props.housenumber
                        ? `${props.street} ${props.housenumber}`
                        : props.street;
                    parts.push(street);
                } else if (props.name) {
                    parts.push(props.name);
                }

                // Add locality/district for context if different from street
                if (props.locality && !parts.includes(props.locality)) {
                    parts.push(props.locality);
                } else if (props.district && !parts.includes(props.district)) {
                    parts.push(props.district);
                }

                // Add city if different from what we already have
                if (props.city && !parts.includes(props.city)) {
                    parts.push(props.city);
                }

                setLocationName(parts.join(', ') || null);
            }
        } catch {
            // Ignore reverse geocode failures, fall back to kommun name
        }
    }, []);

    const fetchLocationData = useCallback(async (lat: number, lng: number) => {
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setLoading(true);
        setPinActive(true);

        try {
            const response = await fetch(
                `/api/location/${lat.toFixed(6)},${lng.toFixed(6)}`,
                {
                    signal: controller.signal,
                },
            );

            if (!response.ok) {
                if (response.status === 404) {
                    setLocationData(null);
                    setPinActive(false);
                    mapRef.current?.clearPin();
                    toast.info('Platsen saknar data — välj en annan plats i Sverige', {
                        duration: 4000,
                    });
                    return;
                }
                throw new Error(`API error: ${response.status}`);
            }

            const data: LocationData = await response.json();
            setLocationData(data);

            // Zoom to neighborhood level and show isochrone or radius fallback
            mapRef.current?.zoomToPoint(lat, lng, 14);
            if (data.isochrone) {
                mapRef.current?.setIsochrone(data.isochrone);
            } else {
                mapRef.current?.setRadiusCircle(lat, lng, data.display_radius);
            }

            // Set school markers on map
            mapRef.current?.setSchoolMarkers(data.schools);

            // Set POI markers within radius (with icons)
            if (data.pois?.length > 0) {
                mapRef.current?.setPoiMarkers(data.pois, data.poi_categories);
            }

            // Reverse geocode for location name
            reverseGeocode(lat, lng);
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError')
                return;
            console.error('Failed to fetch location data:', err);
        } finally {
            setLoading(false);
        }
    }, [mapRef, reverseGeocode]);

    const clearLocation = useCallback(() => {
        setPinActive(false);
        setLocationData(null);
        setLocationName(null);
        mapRef.current?.clearSchoolMarkers();
        mapRef.current?.clearPoiMarkers();
        mapRef.current?.clearIsochrone();
        window.history.pushState(null, '', '/');
    }, [mapRef]);

    return { locationData, locationName, loading, pinActive, fetchLocationData, clearLocation };
}
