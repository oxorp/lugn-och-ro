interface SparklineProps {
    values: number[];
    years: number[];
    width?: number;
    height?: number;
}

export function Sparkline({
    values,
    years,
    width = 200,
    height = 40,
}: SparklineProps) {
    if (values.length < 2) return null;

    const padding = 4;
    const w = width - padding * 2;
    const h = height - padding * 2;

    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;

    const points = values.map((v, i) => {
        const x = padding + (i / (values.length - 1)) * w;
        const y = padding + h - ((v - min) / range) * h;
        return `${x},${y}`;
    });

    const last = values[values.length - 1];
    const first = values[0];
    const trendColor =
        last > first ? '#27ae60' : last < first ? '#e74c3c' : '#94a3b8';

    const lastPoint = points[points.length - 1].split(',');

    return (
        <div className="space-y-1">
            <svg width={width} height={height} className="block">
                <polygon
                    points={`${padding},${padding + h} ${points.join(' ')} ${padding + w},${padding + h}`}
                    fill={trendColor}
                    opacity={0.08}
                />
                <polyline
                    points={points.join(' ')}
                    fill="none"
                    stroke={trendColor}
                    strokeWidth={2}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
                <circle
                    cx={parseFloat(lastPoint[0])}
                    cy={parseFloat(lastPoint[1])}
                    r={3}
                    fill={trendColor}
                />
            </svg>
            <div
                className="flex justify-between text-[10px] text-muted-foreground"
                style={{ width }}
            >
                {years.map((y, i) => (
                    <span key={y}>
                        {i === 0 || i === years.length - 1
                            ? `'${String(y).slice(2)}`
                            : ''}
                    </span>
                ))}
            </div>
            <div className="text-[10px] text-muted-foreground">
                {values.join(' \u2192 ')}:e pctl
            </div>
        </div>
    );
}
