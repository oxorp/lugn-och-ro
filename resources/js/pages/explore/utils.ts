import { interpolateScoreColor } from '@/components/deso-map';

export function formatIndicatorValue(value: number, unit: string | null): string {
    switch (unit) {
        case 'SEK':
            return `${Math.round(value).toLocaleString('sv-SE')} kr`;
        case 'percent':
        case '%':
            return `${value.toFixed(1)}%`;
        case 'per_1000':
        case '/1000':
            return `${value.toFixed(1)}/1000`;
        case 'per_100k':
        case '/100k':
            return `${value.toFixed(1)}/100k`;
        case 'points':
            return `${Math.round(value)}`;
        case 'index':
            return value.toFixed(1);
        default:
            return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
    }
}

export function formatDistance(meters: number): string {
    if (meters < 1000) return `${meters}m`;
    return `${(meters / 1000).toFixed(1)}km`;
}

export function scoreBgStyle(score: number): React.CSSProperties {
    return { backgroundColor: interpolateScoreColor(score) };
}
