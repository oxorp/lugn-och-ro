import { scoreBgStyle } from '@/pages/explore/utils';

export function PercentileBar({
    effectivePct,
    className = 'h-1.5',
}: {
    effectivePct: number;
    className?: string;
}) {
    return (
        <div
            className={`w-full overflow-hidden rounded-full bg-muted ${className}`}
        >
            <div
                className="h-full rounded-full transition-all"
                style={{
                    width: `${effectivePct}%`,
                    ...scoreBgStyle(effectivePct),
                }}
            />
        </div>
    );
}
