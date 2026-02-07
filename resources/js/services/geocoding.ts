export interface SearchResult {
    id: string;
    name: string;
    secondary: string;
    type:
        | 'house'
        | 'street'
        | 'locality'
        | 'district'
        | 'city'
        | 'county'
        | 'state';
    lat: number;
    lng: number;
    extent: [number, number, number, number] | null; // [west, north, east, south]
    postcode: string | null;
    city: string | null;
    county: string | null;
}

const PHOTON_BASE = 'https://photon.komoot.io/api';

// Sweden center + bounding box
const SWEDEN_LAT = 62.0;
const SWEDEN_LNG = 15.0;
const SWEDEN_BBOX = '10.5,55.0,24.5,69.5';

/**
 * Format a Swedish postal code with the standard space (XXX XX).
 * Photon handles "664 94" but not "66494".
 */
function formatPostalCode(query: string): string {
    const cleaned = query.replace(/\s/g, '');
    if (/^\d{5}$/.test(cleaned)) {
        return `${cleaned.slice(0, 3)} ${cleaned.slice(3)}`;
    }
    return query;
}

export async function searchPlaces(
    query: string,
    signal?: AbortSignal,
): Promise<SearchResult[]> {
    if (query.length < 3) return [];

    const formattedQuery = formatPostalCode(query.trim());

    const params = new URLSearchParams({
        q: formattedQuery,
        lang: 'default',
        limit: '7',
        lat: String(SWEDEN_LAT),
        lon: String(SWEDEN_LNG),
        bbox: SWEDEN_BBOX,
    });

    const response = await fetch(`${PHOTON_BASE}?${params}`, { signal });
    if (!response.ok) throw new Error(`Photon API error: ${response.status}`);

    const data = await response.json();
    return data.features
        .map(parsePhotonFeature)
        .filter((r: SearchResult | null): r is SearchResult => r !== null);
}

function parsePhotonFeature(feature: {
    geometry: { coordinates: [number, number] };
    properties: Record<string, unknown>;
}): SearchResult | null {
    const props = feature.properties;
    const [lng, lat] = feature.geometry.coordinates;

    // Skip results outside Sweden
    if (props.countrycode && props.countrycode !== 'SE') return null;

    const type = mapPhotonType(props.type as string);
    const name = formatName(props);
    const secondary = formatSecondary(props);

    return {
        id: `${props.osm_type}${props.osm_id}`,
        name,
        secondary,
        type,
        lat,
        lng,
        extent: (props.extent as [number, number, number, number]) || null,
        postcode: (props.postcode as string) || null,
        city: (props.city as string) || null,
        county: (props.county as string) || null,
    };
}

function mapPhotonType(photonType: string): SearchResult['type'] {
    switch (photonType) {
        case 'house':
            return 'house';
        case 'street':
            return 'street';
        case 'locality':
        case 'district':
            return 'locality';
        case 'city':
            return 'city';
        case 'county':
            return 'county';
        case 'state':
            return 'state';
        default:
            return 'locality';
    }
}

function formatName(props: Record<string, unknown>): string {
    if (props.type === 'house' && props.housenumber) {
        return `${(props.street as string) || (props.name as string)} ${props.housenumber}`;
    }
    return (
        (props.name as string) ||
        (props.street as string) ||
        'Unknown'
    );
}

function formatSecondary(props: Record<string, unknown>): string {
    const parts: string[] = [];
    if (props.postcode) parts.push(props.postcode as string);
    if (props.city) parts.push(props.city as string);
    else if (props.county) parts.push(props.county as string);
    if (props.state && props.state !== props.city)
        parts.push(props.state as string);
    return parts.join(' \u00b7 ') || 'Sweden';
}

/** Whether a result type should auto-select a DeSO on the map */
export function shouldAutoSelectDeso(
    type: SearchResult['type'],
): boolean {
    return type === 'house' || type === 'street' || type === 'locality';
}

/** Get a fallback zoom level when no extent is available */
export function getZoomForType(type: SearchResult['type']): number {
    switch (type) {
        case 'house':
            return 17;
        case 'street':
            return 15;
        case 'locality':
        case 'district':
            return 14;
        case 'city':
            return 12;
        case 'county':
            return 9;
        case 'state':
            return 8;
        default:
            return 14;
    }
}
