import { Head } from '@inertiajs/react';
import { ArrowDown, ArrowRight, ArrowUp, MapPin } from 'lucide-react';
import { useState } from 'react';

import DesoMap, {
    type DesoProperties,
    type DesoScore,
} from '@/components/deso-map';
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

const INDICATOR_LABELS: Record<string, string> = {
    median_income: 'Median Income',
    low_economic_standard_pct: 'Low Economic Standard',
    employment_rate: 'Employment Rate',
    education_post_secondary_pct: 'Post-Secondary Education',
    education_below_secondary_pct: 'Below Secondary Education',
    foreign_background_pct: 'Foreign Background',
    population: 'Population',
    rental_tenure_pct: 'Rental Housing',
};

function scoreColor(score: number): string {
    if (score >= 70) return 'text-green-700';
    if (score >= 40) return 'text-yellow-700';
    return 'text-purple-800';
}

function scoreBgColor(score: number): string {
    if (score >= 70) return 'bg-green-600';
    if (score >= 40) return 'bg-yellow-500';
    return 'bg-purple-700';
}

function TrendIcon({ trend }: { trend: number | null }) {
    if (trend === null) return <ArrowRight className="h-4 w-4 text-gray-400" />;
    if (trend > 1)
        return <ArrowUp className="h-4 w-4 text-green-600" />;
    if (trend < -1)
        return <ArrowDown className="h-4 w-4 text-red-600" />;
    return <ArrowRight className="h-4 w-4 text-gray-400" />;
}

function FactorBar({
    label,
    value,
}: {
    label: string;
    value: number;
}) {
    const pct = Math.round(value * 100);
    return (
        <div className="space-y-0.5">
            <div className="flex justify-between text-xs">
                <span className="text-muted-foreground">{label}</span>
                <span className="font-medium">{pct}th pctl</span>
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-gray-100">
                <div
                    className={`h-full rounded-full transition-all ${scoreBgColor(pct)}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
        </div>
    );
}

export default function MapPage({ initialCenter, initialZoom }: MapPageProps) {
    const [selectedDeso, setSelectedDeso] = useState<DesoProperties | null>(
        null,
    );
    const [selectedScore, setSelectedScore] = useState<DesoScore | null>(null);

    function handleFeatureSelect(
        properties: DesoProperties | null,
        score: DesoScore | null,
    ) {
        setSelectedDeso(properties);
        setSelectedScore(score);
    }

    return (
        <MapLayout>
            <Head title="Map" />

            <DesoMap
                initialCenter={initialCenter}
                initialZoom={initialZoom}
                onFeatureSelect={handleFeatureSelect}
            />

            <Sheet
                open={selectedDeso !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSelectedDeso(null);
                        setSelectedScore(null);
                    }
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
                            <div className="flex items-start justify-between">
                                <div>
                                    <div className="text-muted-foreground text-xs">
                                        DeSO Code
                                    </div>
                                    <div className="font-mono text-sm font-medium">
                                        {selectedDeso.deso_code}
                                    </div>
                                    {selectedDeso.deso_name && (
                                        <div className="text-sm text-gray-600">
                                            {selectedDeso.deso_name}
                                        </div>
                                    )}
                                </div>

                                {selectedScore && (
                                    <div className="text-right">
                                        <div
                                            className={`text-3xl font-bold ${scoreColor(selectedScore.score)}`}
                                        >
                                            {selectedScore.score.toFixed(0)}
                                        </div>
                                        <div className="flex items-center justify-end gap-1">
                                            <TrendIcon
                                                trend={selectedScore.trend_1y}
                                            />
                                            <span className="text-muted-foreground text-xs">
                                                {selectedScore.trend_1y !== null
                                                    ? `${selectedScore.trend_1y > 0 ? '+' : ''}${selectedScore.trend_1y.toFixed(1)}`
                                                    : 'N/A'}
                                            </span>
                                        </div>
                                    </div>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <div className="text-muted-foreground text-xs">
                                        Kommun
                                    </div>
                                    <div className="flex items-center gap-1 text-sm">
                                        <span className="font-medium">
                                            {selectedDeso.kommun_name ??
                                                'Unknown'}
                                        </span>
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            {selectedDeso.kommun_code}
                                        </Badge>
                                    </div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground text-xs">
                                        Lan
                                    </div>
                                    <div className="flex items-center gap-1 text-sm">
                                        <span className="font-medium">
                                            {selectedDeso.lan_name ?? 'Unknown'}
                                        </span>
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            {selectedDeso.lan_code}
                                        </Badge>
                                    </div>
                                </div>
                            </div>

                            {selectedScore?.factor_scores && (
                                <>
                                    <Separator />
                                    <div>
                                        <div className="mb-2 text-xs font-medium text-gray-700">
                                            Factor Breakdown
                                        </div>
                                        <div className="space-y-2">
                                            {Object.entries(
                                                selectedScore.factor_scores,
                                            ).map(([slug, value]) => (
                                                <FactorBar
                                                    key={slug}
                                                    label={
                                                        INDICATOR_LABELS[slug] ||
                                                        slug
                                                    }
                                                    value={value}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                </>
                            )}

                            {(selectedScore?.top_positive?.length ||
                                selectedScore?.top_negative?.length) && (
                                <>
                                    <Separator />
                                    <div className="space-y-2">
                                        {selectedScore.top_positive &&
                                            selectedScore.top_positive.length >
                                                0 && (
                                                <div>
                                                    <div className="mb-1 text-xs font-medium text-green-700">
                                                        Strengths
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_positive.map(
                                                            (slug) => (
                                                                <Badge
                                                                    key={slug}
                                                                    className="bg-green-100 text-green-800"
                                                                    variant="secondary"
                                                                >
                                                                    {INDICATOR_LABELS[
                                                                        slug
                                                                    ] || slug}
                                                                </Badge>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            )}

                                        {selectedScore.top_negative &&
                                            selectedScore.top_negative.length >
                                                0 && (
                                                <div>
                                                    <div className="mb-1 text-xs font-medium text-purple-700">
                                                        Weaknesses
                                                    </div>
                                                    <div className="flex flex-wrap gap-1">
                                                        {selectedScore.top_negative.map(
                                                            (slug) => (
                                                                <Badge
                                                                    key={slug}
                                                                    className="bg-purple-100 text-purple-800"
                                                                    variant="secondary"
                                                                >
                                                                    {INDICATOR_LABELS[
                                                                        slug
                                                                    ] || slug}
                                                                </Badge>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            )}
                                    </div>
                                </>
                            )}

                            {selectedDeso.area_km2 !== null && (
                                <>
                                    <Separator />
                                    <div>
                                        <div className="text-muted-foreground text-xs">
                                            Area
                                        </div>
                                        <div className="text-sm font-medium">
                                            {selectedDeso.area_km2.toFixed(2)}{' '}
                                            km²
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    )}
                </SheetContent>
            </Sheet>
        </MapLayout>
    );
}
