import type { HeatmapMapHandle } from '@/components/deso-map';
import { useEffect } from 'react';

export function useUrlPin(
    mapRef: React.RefObject<HeatmapMapHandle | null>,
    handlePinDrop: (lat: number, lng: number) => void,
): void {
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
                    mapRef.current?.setRadiusCircle(lat, lng, 2000);
                    handlePinDrop(lat, lng);
                }, 500);
            }
        }
    }, []);
}
