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

export interface PreviewFreeIndicator {
    slug: string;
    name: string;
    raw_value: number;
    percentile: number | null;
    unit: string | null;
    direction: 'positive' | 'negative' | 'neutral';
}

export interface PreviewCategory {
    slug: string;
    label: string;
    icon: string;
    stat_line: string;
    indicator_count: number;
    locked_count: number;
    free_indicators: PreviewFreeIndicator[];
    has_data: boolean;
    poi_count?: number;
}

export interface PreviewCtaSummary {
    indicator_count: number;
    insight_count: number;
    poi_count: number;
}

export interface PreviewData {
    data_point_count: number;
    source_count: number;
    sources: string[];
    categories: PreviewCategory[];
    nearby_school_count: number;
    cta_summary: PreviewCtaSummary;
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
        raw_score_before_penalties: number | null;
        penalties_applied: Array<{
            slug: string;
            name: string;
            amount: number;
        }> | null;
        history: {
            years: number[];
            scores: number[];
        } | null;
    } | null;
    tier: number;
    display_radius: number;
    preview?: PreviewData;
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
        trend: {
            years: number[];
            percentiles: number[];
            raw_values: number[];
            change_1y: number | null;
            change_3y: number | null;
            change_5y: number | null;
        };
    }>;
    schools: SchoolMarker[];
    pois: PoiMarker[];
    poi_summary: Array<{
        category: string;
        count: number;
        nearest_m: number;
    }>;
    poi_categories: Record<string, PoiCategoryMeta>;
}
