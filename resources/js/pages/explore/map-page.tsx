import { Head } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useCallback, useRef } from 'react';

import type { HeatmapMapHandle } from '@/components/deso-map';
import HeatmapMap from '@/components/deso-map';
import MapSearch from '@/components/map-search';
import { useTranslation } from '@/hooks/use-translation';
import MapLayout from '@/layouts/map-layout';
import { type SearchResult, getZoomForType } from '@/services/geocoding';

import { ActiveSidebar } from './components/active-sidebar';
import { DefaultSidebar } from './components/default-sidebar';
import { useLocationData } from './hooks/use-location-data';
import { useUrlPin } from './hooks/use-url-pin';
import type { MapPageProps } from './types';

export default function MapPage({
    initialCenter,
    initialZoom,
    indicatorScopes,
    indicatorMeta,
}: MapPageProps) {
    const { t } = useTranslation();
    const mapRef = useRef<HeatmapMapHandle>(null);

    const { locationData, locationName, loading, pinActive, fetchLocationData, clearLocation } =
        useLocationData(mapRef);

    const handlePinDrop = useCallback(
        (lat: number, lng: number) => {
            // Update URL
            window.history.pushState(
                null,
                '',
                `/explore/${lat.toFixed(4)},${lng.toFixed(4)}`,
            );
            fetchLocationData(lat, lng);
        },
        [fetchLocationData],
    );

    const handlePinClear = useCallback(() => {
        clearLocation();
        mapRef.current?.clearPin();
    }, [clearLocation]);

    useUrlPin(mapRef, handlePinDrop);

    const handleSearchResult = useCallback(
        (result: SearchResult) => {
            mapRef.current?.dropPin(result.lat, result.lng);

            if (result.extent) {
                mapRef.current?.zoomToExtent(
                    result.extent[0],
                    result.extent[3],
                    result.extent[2],
                    result.extent[1],
                );
            } else {
                mapRef.current?.zoomToPoint(
                    result.lat,
                    result.lng,
                    getZoomForType(result.type),
                );
            }

            handlePinDrop(result.lat, result.lng);
        },
        [handlePinDrop],
    );

    const handleSearchClear = useCallback(() => {
        handlePinClear();
        mapRef.current?.clearPin();
    }, [handlePinClear]);

    const handleTrySearch = useCallback((query: string) => {
        // Focus the search input and set query
        const input =
            document.querySelector<HTMLInputElement>('input[type="text"]');
        if (input) {
            input.focus();
            // Trigger the search by dispatching an input event
            const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
                window.HTMLInputElement.prototype,
                'value',
            )?.set;
            nativeInputValueSetter?.call(input, query);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, []);

    return (
        <MapLayout>
            <Head title={t('map.title')} />
            <>
                {/* Map */}
                <div className="relative h-2/5 flex-none md:h-auto md:flex-1">
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
                <div className="min-h-0 flex-1 overflow-hidden border-t border-border bg-background md:w-[420px] md:flex-none md:border-t-0 md:border-l">
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

            </>
        </MapLayout>
    );
}
