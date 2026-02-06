import { Head } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import { useState } from 'react';

import DesoMap, { type DesoProperties } from '@/components/deso-map';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import MapLayout from '@/layouts/map-layout';

interface MapPageProps {
    initialCenter: [number, number];
    initialZoom: number;
}

export default function MapPage({ initialCenter, initialZoom }: MapPageProps) {
    const [selectedDeso, setSelectedDeso] = useState<DesoProperties | null>(
        null,
    );

    return (
        <MapLayout>
            <Head title="Map" />

            <DesoMap
                initialCenter={initialCenter}
                initialZoom={initialZoom}
                onFeatureSelect={setSelectedDeso}
            />

            <Sheet
                open={selectedDeso !== null}
                onOpenChange={(open) => {
                    if (!open) setSelectedDeso(null);
                }}
            >
                <SheetContent side="right">
                    <SheetHeader>
                        <SheetTitle className="flex items-center gap-2">
                            <MapPin className="h-4 w-4" />
                            DeSO Area
                        </SheetTitle>
                        <SheetDescription>
                            Demographic statistical area details
                        </SheetDescription>
                    </SheetHeader>

                    {selectedDeso && (
                        <div className="space-y-4 px-4">
                            <div>
                                <div className="text-muted-foreground text-xs">
                                    DeSO Code
                                </div>
                                <div className="font-mono text-sm font-medium">
                                    {selectedDeso.deso_code}
                                </div>
                            </div>

                            {selectedDeso.deso_name && (
                                <div>
                                    <div className="text-muted-foreground text-xs">
                                        Name
                                    </div>
                                    <div className="text-sm font-medium">
                                        {selectedDeso.deso_name}
                                    </div>
                                </div>
                            )}

                            <Separator />

                            <div>
                                <div className="text-muted-foreground text-xs">
                                    Kommun
                                </div>
                                <div className="flex items-center gap-2 text-sm">
                                    <span className="font-medium">
                                        {selectedDeso.kommun_name ??
                                            'Unknown'}
                                    </span>
                                    <Badge variant="outline">
                                        {selectedDeso.kommun_code}
                                    </Badge>
                                </div>
                            </div>

                            <div>
                                <div className="text-muted-foreground text-xs">
                                    Län
                                </div>
                                <div className="flex items-center gap-2 text-sm">
                                    <span className="font-medium">
                                        {selectedDeso.lan_name ?? 'Unknown'}
                                    </span>
                                    <Badge variant="outline">
                                        {selectedDeso.lan_code}
                                    </Badge>
                                </div>
                            </div>

                            <Separator />

                            {selectedDeso.area_km2 !== null && (
                                <div>
                                    <div className="text-muted-foreground text-xs">
                                        Area
                                    </div>
                                    <div className="text-sm font-medium">
                                        {selectedDeso.area_km2.toFixed(2)} km²
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </MapLayout>
    );
}
