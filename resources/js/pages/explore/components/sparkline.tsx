import { useState } from 'react';

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
    const [hoverIdx, setHoverIdx] = useState<number | null>(null);

    if (values.length < 2) return null;

    const padding = 12;
    const paddingTop = 16;
    const w = width - padding * 2;
    const h = height - paddingTop - padding;

    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min || 1;

    const coords = values.map((v, i) => ({
        x: padding + (i / (values.length - 1)) * w,
        y: paddingTop + h - ((v - min) / range) * h,
    }));

    const polyPoints = coords.map((c) => `${c.x},${c.y}`).join(' ');

    const last = values[values.length - 1];
    const first = values[0];
    const trendColor =
        last > first ? '#27ae60' : last < first ? '#e74c3c' : '#94a3b8';

    const lastCoord = coords[coords.length - 1];

    return (
        <svg
            width={width}
            height={height}
            className="block"
            onMouseLeave={() => setHoverIdx(null)}
        >
            {/* Area fill */}
            <polygon
                points={`${padding},${paddingTop + h} ${polyPoints} ${padding + w},${paddingTop + h}`}
                fill={trendColor}
                opacity={0.08}
            />
            {/* Line */}
            <polyline
                points={polyPoints}
                fill="none"
                stroke={trendColor}
                strokeWidth={1.5}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {/* Last point dot (always visible) */}
            <circle cx={lastCoord.x} cy={lastCoord.y} r={2.5} fill={trendColor} />

            {/* Year labels: first and last */}
            <text
                x={coords[0].x}
                y={paddingTop + h + 10}
                textAnchor="start"
                className="fill-muted-foreground"
                fontSize={9}
            >
                {years[0]}
            </text>
            <text
                x={lastCoord.x}
                y={paddingTop + h + 10}
                textAnchor="end"
                className="fill-muted-foreground"
                fontSize={9}
            >
                {years[years.length - 1]}
            </text>

            {/* Invisible hover targets per data point */}
            {coords.map((c, i) => {
                const hitWidth = w / (values.length - 1);
                return (
                    <rect
                        key={i}
                        x={c.x - hitWidth / 2}
                        y={0}
                        width={hitWidth}
                        height={height}
                        fill="transparent"
                        onMouseEnter={() => setHoverIdx(i)}
                    />
                );
            })}

            {/* Hover state */}
            {hoverIdx !== null && (
                <>
                    {/* Vertical guide line */}
                    <line
                        x1={coords[hoverIdx].x}
                        y1={paddingTop}
                        x2={coords[hoverIdx].x}
                        y2={paddingTop + h}
                        stroke="currentColor"
                        strokeOpacity={0.15}
                        strokeWidth={1}
                        strokeDasharray="2,2"
                    />
                    {/* Hover dot */}
                    <circle
                        cx={coords[hoverIdx].x}
                        cy={coords[hoverIdx].y}
                        r={3.5}
                        fill={trendColor}
                        stroke="white"
                        strokeWidth={1.5}
                    />
                    {/* Tooltip */}
                    <text
                        x={coords[hoverIdx].x}
                        y={paddingTop - 4}
                        textAnchor="middle"
                        className="fill-foreground"
                        fontSize={10}
                        fontWeight={600}
                    >
                        {years[hoverIdx]}: {values[hoverIdx]}
                    </text>
                </>
            )}
        </svg>
    );
}
