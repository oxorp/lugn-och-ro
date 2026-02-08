import type { PoiCategoryMeta, PoiMarker, SchoolMarker } from '@/components/deso-map';
import type { IndicatorMeta } from '@/components/info-tooltip';

export interface MapPageProps {
    initialCenter: [number, number];
    initialZoom: number;
    indicatorScopes: Record<string, 'national' | 'urbanity_stratified'>;
    indicatorMeta: Record<string, IndicatorMeta>;
    userTier: number;
    isAuthenticated: boolean;
}

export interface ProximityFactor {
    slug: string;
    score: number | null;
    details: Record<string, unknown>;
}

export interface SafetyZone {
    level: 'high' | 'medium' | 'low';
    label: string;
}

export interface ProximityData {
    composite: number;
    safety_score: number;
    safety_zone: SafetyZone;
    factors: ProximityFactor[];
}

export interface LocationData {
    location: {
        lat: number;
        lng: number;
        deso_code: string;
        kommun: string;
        lan_code: string;
        area_km2: number;
        urbanity_tier: string | null;
    };
    score: {
        value: number;
        area_score: number | null;
        proximity_score: number;
        trend_1y: number | null;
        label: string;
        top_positive: string[] | null;
        top_negative: string[] | null;
        factor_scores: Record<string, number> | null;
    } | null;
    tier: number;
    display_radius: number;
    proximity: ProximityData | null;
    indicators: Array<{
        slug: string;
        name: string;
        raw_value: number;
        normalized_value: number;
        unit: string | null;
        direction: 'positive' | 'negative' | 'neutral';
        category: string | null;
        normalization_scope: 'national' | 'urbanity_stratified';
    }>;
    schools: SchoolMarker[];
    pois: PoiMarker[];
    poi_categories: Record<string, PoiCategoryMeta>;
}
