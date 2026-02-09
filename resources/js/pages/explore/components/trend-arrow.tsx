interface TrendArrowProps {
    change: number | null;
    direction: 'positive' | 'negative' | 'neutral';
}

export function TrendArrow({ change, direction }: TrendArrowProps) {
    if (change === null) {
        return <span className="text-xs text-muted-foreground">&mdash;</span>;
    }

    // Neutral indicators: show arrow direction but always gray (no good/bad interpretation)
    if (direction === 'neutral') {
        const { arrow } = getArrowStyle(change);
        const sign = change > 0 ? '+' : '';
        return (
            <span
                className="inline-flex items-center gap-0.5 text-xs font-medium tabular-nums"
                style={{ color: '#94a3b8' }}
                title={`${sign}${change} percentilpoäng vs förra året`}
            >
                {arrow} {sign}
                {change}
            </span>
        );
    }

    // For negative indicators (crime, debt), flip interpretation:
    // a percentile drop in crime = good = green arrow
    const directedChange = direction === 'negative' ? -change : change;

    const { arrow, color } = getArrowStyle(directedChange);
    const sign = directedChange > 0 ? '+' : '';

    return (
        <span
            className="inline-flex items-center gap-0.5 text-xs font-medium tabular-nums"
            style={{ color }}
            title={`${sign}${directedChange} percentilpoäng vs förra året`}
        >
            {arrow} {sign}
            {directedChange}
        </span>
    );
}

function getArrowStyle(effectiveChange: number): {
    arrow: string;
    color: string;
} {
    if (effectiveChange >= 3) return { arrow: '\u2191', color: '#27ae60' };
    if (effectiveChange >= 1) return { arrow: '\u2197', color: '#6abf4b' };
    if (effectiveChange > -1) return { arrow: '\u2192', color: '#94a3b8' };
    if (effectiveChange > -3) return { arrow: '\u2198', color: '#e57373' };
    return { arrow: '\u2193', color: '#e74c3c' };
}
