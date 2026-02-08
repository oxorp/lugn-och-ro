import {
    Bus,
    GraduationCap,
    ShieldAlert,
    ShoppingCart,
    Sparkles,
    TreePine,
} from 'lucide-react';

export const PROXIMITY_FACTOR_CONFIG: Record<
    string,
    {
        icon: typeof GraduationCap;
        nameKey: string;
        detailKey:
            | 'nearest_school'
            | 'nearest_park'
            | 'nearest_stop'
            | 'nearest_store'
            | 'nearest'
            | 'count';
        distanceKey: string;
    }
> = {
    prox_school: {
        icon: GraduationCap,
        nameKey: 'sidebar.proximity.school',
        detailKey: 'nearest_school',
        distanceKey: 'nearest_distance_m',
    },
    prox_green_space: {
        icon: TreePine,
        nameKey: 'sidebar.proximity.green_space',
        detailKey: 'nearest_park',
        distanceKey: 'distance_m',
    },
    prox_transit: {
        icon: Bus,
        nameKey: 'sidebar.proximity.transit',
        detailKey: 'nearest_stop',
        distanceKey: 'nearest_distance_m',
    },
    prox_grocery: {
        icon: ShoppingCart,
        nameKey: 'sidebar.proximity.grocery',
        detailKey: 'nearest_store',
        distanceKey: 'distance_m',
    },
    prox_negative_poi: {
        icon: ShieldAlert,
        nameKey: 'sidebar.proximity.negative_poi',
        detailKey: 'count',
        distanceKey: 'nearest_distance_m',
    },
    prox_positive_poi: {
        icon: Sparkles,
        nameKey: 'sidebar.proximity.positive_poi',
        detailKey: 'count',
        distanceKey: '',
    },
};
