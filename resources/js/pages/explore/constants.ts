import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';

import {
    faBus,
    faCartShopping,
    faGraduationCap,
    faShieldExclamation,
    faSparkles,
    faTree,
} from '@/icons';

export const PROXIMITY_FACTOR_CONFIG: Record<
    string,
    {
        icon: IconDefinition;
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
        icon: faGraduationCap,
        nameKey: 'sidebar.proximity.school',
        detailKey: 'nearest_school',
        distanceKey: 'nearest_distance_m',
    },
    prox_green_space: {
        icon: faTree,
        nameKey: 'sidebar.proximity.green_space',
        detailKey: 'nearest_park',
        distanceKey: 'distance_m',
    },
    prox_transit: {
        icon: faBus,
        nameKey: 'sidebar.proximity.transit',
        detailKey: 'nearest_stop',
        distanceKey: 'nearest_distance_m',
    },
    prox_grocery: {
        icon: faCartShopping,
        nameKey: 'sidebar.proximity.grocery',
        detailKey: 'nearest_store',
        distanceKey: 'distance_m',
    },
    prox_negative_poi: {
        icon: faShieldExclamation,
        nameKey: 'sidebar.proximity.negative_poi',
        detailKey: 'count',
        distanceKey: 'nearest_distance_m',
    },
    prox_positive_poi: {
        icon: faSparkles,
        nameKey: 'sidebar.proximity.positive_poi',
        detailKey: 'count',
        distanceKey: '',
    },
};
