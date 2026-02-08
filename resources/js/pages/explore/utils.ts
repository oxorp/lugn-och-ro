import { interpolateScoreColor } from '@/components/deso-map';

export function formatIndicatorValue(value: number, unit: string | null): string {
    if (unit === '%') return `${value.toFixed(1)}%`;
    if (unit === 'SEK') return `${Math.round(value).toLocaleString()} SEK`;
    if (unit === '/100k') return `${value.toFixed(1)}/100k`;
    if (unit === '/1000') return `${value.toFixed(2)}/1000`;
    return value.toLocaleString(undefined, { maximumFractionDigits: 1 });
}

export function formatDistance(meters: number): string {
    if (meters < 1000) return `${meters}m`;
    return `${(meters / 1000).toFixed(1)}km`;
}

export function scoreBgStyle(score: number): React.CSSProperties {
    return { backgroundColor: interpolateScoreColor(score) };
}
