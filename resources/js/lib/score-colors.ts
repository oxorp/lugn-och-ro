import type { ScoreColorConfig } from '@/types/score-colors';

const DEFAULT_STOPS: Record<number, string> = {
    0: '#c0392b',
    25: '#e74c3c',
    40: '#f39c12',
    50: '#f1c40f',
    60: '#f1c40f',
    75: '#27ae60',
    100: '#1a7a2e',
};

/**
 * Linearly interpolate between two hex colors.
 */
function lerpColor(a: string, b: string, t: number): string {
    const parse = (hex: string) => [
        parseInt(hex.slice(1, 3), 16),
        parseInt(hex.slice(3, 5), 16),
        parseInt(hex.slice(5, 7), 16),
    ];

    const [r1, g1, b1] = parse(a);
    const [r2, g2, b2] = parse(b);

    const r = Math.round(r1 + (r2 - r1) * t);
    const g = Math.round(g1 + (g2 - g1) * t);
    const bl = Math.round(b1 + (b2 - b1) * t);

    return `#${[r, g, bl].map((v) => v.toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Convert a score (0-100) to a hex color using gradient stops.
 * Falls back to hardcoded defaults if stops aren't provided.
 */
export function scoreToColor(
    score: number | null | undefined,
    stops?: Record<number, string>,
): string {
    if (score == null) return '#d5d5d5';

    const gradientStops = stops ?? DEFAULT_STOPS;

    const thresholds = Object.keys(gradientStops)
        .map(Number)
        .sort((a, b) => a - b);

    const clamped = Math.max(0, Math.min(100, score));

    for (let i = 0; i < thresholds.length - 1; i++) {
        const lo = thresholds[i];
        const hi = thresholds[i + 1];
        if (clamped >= lo && clamped <= hi) {
            const t = hi === lo ? 0 : (clamped - lo) / (hi - lo);
            return lerpColor(gradientStops[lo], gradientStops[hi], t);
        }
    }

    return gradientStops[thresholds[thresholds.length - 1]];
}

/**
 * Get the score label for a given score.
 */
export function scoreToLabel(
    score: number,
    labels?: ScoreColorConfig['labels'],
): string {
    const defaultLabels: ScoreColorConfig['labels'] = [
        { min: 80, max: 100, label_sv: 'Starkt tillväxtområde', label_en: 'Strong Growth Area', color: '#1a7a2e' },
        { min: 60, max: 79, label_sv: 'Stabil / positiv utsikt', label_en: 'Stable / Positive Outlook', color: '#27ae60' },
        { min: 40, max: 59, label_sv: 'Blandade signaler', label_en: 'Mixed Signals', color: '#f1c40f' },
        { min: 20, max: 39, label_sv: 'Förhöjd risk', label_en: 'Elevated Risk', color: '#e74c3c' },
        { min: 0, max: 19, label_sv: 'Hög risk / vikande', label_en: 'High Risk / Declining', color: '#c0392b' },
    ];

    const list = labels ?? defaultLabels;
    const match = list.find((l) => score >= l.min && score <= l.max);
    return match?.label_sv ?? 'Okänt';
}

/**
 * Get the color for a school marker based on meritvärde.
 */
export function meritToColor(
    merit: number | null,
    schoolColors?: ScoreColorConfig['school_markers'],
): string {
    const colors = schoolColors ?? {
        high: '#27ae60',
        medium: '#f1c40f',
        low: '#e74c3c',
        no_data: '#999999',
    };

    if (merit == null) return colors.no_data;
    if (merit > 230) return colors.high;
    if (merit >= 200) return colors.medium;
    return colors.low;
}

/**
 * Get indicator bar color based on whether this indicator is helping or hurting.
 */
export function indicatorBarColor(
    percentile: number,
    direction: 'positive' | 'negative' | 'neutral',
    barColors?: ScoreColorConfig['indicator_bar'],
): string {
    const colors = barColors ?? {
        good: '#27ae60',
        bad: '#e74c3c',
        neutral: '#94a3b8',
    };

    if (direction === 'neutral') return colors.neutral;

    const isGood = direction === 'positive' ? percentile >= 50 : percentile < 50;

    return isGood ? colors.good : colors.bad;
}

/**
 * Generate CSS gradient string for the legend bar.
 */
export function scoreGradientCSS(stops?: Record<number, string>): string {
    const gradientStops = stops ?? DEFAULT_STOPS;

    const parts = Object.entries(gradientStops)
        .sort(([a], [b]) => Number(a) - Number(b))
        .map(([pct, color]) => `${color} ${pct}%`);

    return `linear-gradient(to right, ${parts.join(', ')})`;
}
