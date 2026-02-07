export interface PoiCategory {
    slug: string;
    name: string;
    signal: 'positive' | 'negative' | 'neutral';
    display_tier: number;
    icon: string;
    color: string;
    impact_radius_km: number | null;
    category_group: string | null;
}

export interface PoiFeatureData {
    id: number;
    name: string | null;
    poi_type: string;
    category: string;
    sentiment: string;
    lat: string;
    lng: string;
}

// Category groups for the controls panel
export const POI_GROUPS = {
    nuisances: {
        label: 'Nuisances',
        subcategories: {
            industrial: {
                label: 'Industrial',
                icon: 'factory',
                types: [
                    'paper_mill',
                    'waste_incinerator',
                    'quarry',
                ],
            },
            waste_water: {
                label: 'Wastewater & Waste',
                icon: 'droplets',
                types: ['wastewater_plant', 'landfill'],
            },
            noise_sources: {
                label: 'Noise Sources',
                icon: 'volume-2',
                types: [
                    'airport',
                    'shooting_range',
                    'wind_turbine',
                ],
            },
            social: {
                label: 'Social',
                icon: 'alert-circle',
                types: [
                    'gambling',
                    'pawn_shop',
                    'homeless_shelter',
                    'nightclub',
                    'sex_shop',
                    'prison',
                ],
            },
            infrastructure: {
                label: 'Infrastructure',
                icon: 'zap',
                types: ['recycling_station'],
                defaultOff: true,
            },
        },
    },
    amenities: {
        label: 'Amenities',
        subcategories: {
            nature: {
                label: 'Parks & Nature',
                icon: 'trees',
                types: [
                    'park',
                    'nature_reserve',
                    'marina',
                    'swimming',
                ],
            },
            healthcare: {
                label: 'Healthcare',
                icon: 'heart-pulse',
                types: ['healthcare', 'pharmacy'],
            },
            culture: {
                label: 'Culture & Cafés',
                icon: 'coffee',
                types: [
                    'restaurant',
                    'library',
                    'cultural_venue',
                    'bookshop',
                ],
            },
            shopping: {
                label: 'Shopping',
                icon: 'shopping-bag',
                types: ['grocery'],
            },
            sports: {
                label: 'Sports & Leisure',
                icon: 'dumbbell',
                types: ['fitness'],
                defaultOff: true,
            },
        },
    },
    transport: {
        label: 'Transport',
        subcategories: {
            transit: {
                label: 'Public Transport',
                icon: 'bus',
                types: ['public_transport_stop', 'fast_food_late'],
                defaultOff: true,
            },
        },
    },
} as const;

// Sentiment colors
export const SENTIMENT_COLORS: Record<string, string> = {
    positive: '#16a34a',
    negative: '#f97316',
    neutral: '#6b7280',
};

// Get default enabled categories (all except those in defaultOff groups)
export function getDefaultEnabledCategories(): Set<string> {
    const enabled = new Set<string>();
    for (const group of Object.values(POI_GROUPS)) {
        for (const sub of Object.values(group.subcategories)) {
            if (!('defaultOff' in sub && sub.defaultOff)) {
                for (const type of sub.types) {
                    enabled.add(type);
                }
            }
        }
    }
    return enabled;
}

// Get all category slugs
export function getAllCategories(): string[] {
    const all: string[] = [];
    for (const group of Object.values(POI_GROUPS)) {
        for (const sub of Object.values(group.subcategories)) {
            all.push(...sub.types);
        }
    }
    return all;
}

const STORAGE_KEY = 'poi-preferences';

export function loadPoiPreferences(): Set<string> {
    try {
        const saved = localStorage.getItem(STORAGE_KEY);
        if (saved) return new Set(JSON.parse(saved));
    } catch {
        // Ignore parse errors
    }
    return getDefaultEnabledCategories();
}

export function savePoiPreferences(categories: Set<string>): void {
    localStorage.setItem(STORAGE_KEY, JSON.stringify([...categories]));
}
