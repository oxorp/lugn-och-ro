import { Head } from '@inertiajs/react';
import { useState } from 'react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';

interface YearData {
    has_data: boolean;
    count: number;
    total: number;
    coverage_pct: number;
    avg_value: number | null;
    last_updated: string | null;
}

interface IndicatorInfo {
    id: number;
    slug: string;
    name: string;
    source: string;
    category: string;
    unit: string | null;
}

interface MatrixRow {
    indicator: IndicatorInfo;
    years: Record<number, YearData>;
}

interface Summary {
    total_indicators: number;
    total_years: number;
    total_cells: number;
    filled_cells: number;
    total_desos: number;
}

interface Props {
    matrix: MatrixRow[];
    years: number[];
    summary: Summary;
}

function coverageColor(pct: number, hasData: boolean): string {
    if (!hasData) return 'bg-muted text-muted-foreground';
    if (pct >= 95) return 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300';
    if (pct >= 80) return 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300';
    if (pct >= 50) return 'bg-yellow-50 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300';
    if (pct >= 1) return 'bg-orange-50 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300';
    return 'bg-muted text-muted-foreground';
}

function formatNumber(n: number): string {
    return n.toLocaleString('sv-SE');
}

function formatDate(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleString('sv-SE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
}

function formatAvg(value: number | null, unit: string | null): string {
    if (value === null) return '-';
    if (unit === 'SEK') return `${formatNumber(Math.round(value))} SEK`;
    if (unit === '%' || unit === 'pct') return `${value}%`;
    return String(value);
}

/** Group matrix rows by source, preserving order. */
function groupBySource(matrix: MatrixRow[]): Map<string, MatrixRow[]> {
    const groups = new Map<string, MatrixRow[]>();
    for (const row of matrix) {
        const source = row.indicator.source ?? 'Other';
        if (!groups.has(source)) groups.set(source, []);
        groups.get(source)!.push(row);
    }
    return groups;
}

export default function DataCompletenessPage({ matrix, years, summary }: Props) {
    const [hoveredCell, setHoveredCell] = useState<string | null>(null);
    const grouped = groupBySource(matrix);
    const fillPct = summary.total_cells > 0
        ? Math.round((summary.filled_cells / summary.total_cells) * 100)
        : 0;

    return (
        <AdminLayout>
            <Head title="Data Completeness" />

            <div className="mb-6">
                <h1 className="text-2xl font-bold">Data Completeness</h1>
                <p className="text-muted-foreground text-sm">
                    Coverage of indicator data across years and DeSO areas
                </p>
            </div>

            {/* Summary cards */}
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <Card>
                    <CardContent className="pt-4">
                        <p className="text-muted-foreground text-xs">Indicators</p>
                        <p className="text-2xl font-bold">{summary.total_indicators}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4">
                        <p className="text-muted-foreground text-xs">Years</p>
                        <p className="text-2xl font-bold">{summary.total_years}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4">
                        <p className="text-muted-foreground text-xs">Filled Cells</p>
                        <p className="text-2xl font-bold">
                            {summary.filled_cells}
                            <span className="text-muted-foreground ml-1 text-sm font-normal">
                                / {summary.total_cells}
                            </span>
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4">
                        <p className="text-muted-foreground text-xs">Fill Rate</p>
                        <p className="text-2xl font-bold">{fillPct}%</p>
                    </CardContent>
                </Card>
            </div>

            {/* Legend */}
            <div className="mb-4 flex flex-wrap items-center gap-3 text-xs">
                <span className="text-muted-foreground font-medium">Coverage:</span>
                <span className="inline-flex items-center gap-1">
                    <span className="inline-block h-3 w-5 rounded bg-green-100 dark:bg-green-900/40" />
                    95-100%
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="inline-block h-3 w-5 rounded bg-emerald-50 dark:bg-emerald-900/30" />
                    80-94%
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="inline-block h-3 w-5 rounded bg-yellow-50 dark:bg-yellow-900/30" />
                    50-79%
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="inline-block h-3 w-5 rounded bg-orange-50 dark:bg-orange-900/30" />
                    1-49%
                </span>
                <span className="inline-flex items-center gap-1">
                    <span className="bg-muted inline-block h-3 w-5 rounded" />
                    No data
                </span>
            </div>

            {/* Completeness matrix */}
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium">
                        Indicator x Year Coverage Matrix
                    </CardTitle>
                </CardHeader>
                <CardContent className="overflow-x-auto">
                    <TooltipProvider delayDuration={150}>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b">
                                    <th className="sticky left-0 z-10 bg-background py-2 pr-4 text-left text-xs font-medium">
                                        Indicator
                                    </th>
                                    {years.map((y) => (
                                        <th
                                            key={y}
                                            className="px-1 py-2 text-center text-xs font-medium"
                                        >
                                            {y}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {[...grouped.entries()].map(([source, rows]) => (
                                    <SourceGroup
                                        key={source}
                                        source={source}
                                        rows={rows}
                                        years={years}
                                        hoveredCell={hoveredCell}
                                        onHover={setHoveredCell}
                                    />
                                ))}
                            </tbody>
                        </table>
                    </TooltipProvider>
                </CardContent>
            </Card>
        </AdminLayout>
    );
}

function SourceGroup({
    source,
    rows,
    years,
    hoveredCell,
    onHover,
}: {
    source: string;
    rows: MatrixRow[];
    years: number[];
    hoveredCell: string | null;
    onHover: (key: string | null) => void;
}) {
    return (
        <>
            <tr>
                <td
                    colSpan={years.length + 1}
                    className="sticky left-0 z-10 bg-muted/50 px-2 py-1.5 text-xs font-semibold uppercase tracking-wider"
                >
                    {source}
                </td>
            </tr>
            {rows.map((row) => (
                <tr key={row.indicator.slug} className="group border-b border-border/50">
                    <td className="bg-background sticky left-0 z-10 max-w-[220px] truncate py-1.5 pr-4 text-xs">
                        <span className="font-medium" title={row.indicator.name}>
                            {row.indicator.name}
                        </span>
                        <span className="text-muted-foreground ml-1 hidden lg:inline">
                            ({row.indicator.slug})
                        </span>
                    </td>
                    {years.map((year) => {
                        const cell = row.years[year];
                        const cellKey = `${row.indicator.slug}-${year}`;
                        const isHovered = hoveredCell === cellKey;

                        return (
                            <td key={year} className="px-0.5 py-1">
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <div
                                            className={cn(
                                                'mx-auto flex h-8 w-14 items-center justify-center rounded text-xs font-medium transition-shadow',
                                                coverageColor(cell?.coverage_pct ?? 0, cell?.has_data ?? false),
                                                isHovered && 'ring-2 ring-foreground/20',
                                            )}
                                            onMouseEnter={() => onHover(cellKey)}
                                            onMouseLeave={() => onHover(null)}
                                        >
                                            {cell?.has_data
                                                ? `${cell.coverage_pct}%`
                                                : '\u2014'}
                                        </div>
                                    </TooltipTrigger>
                                    <TooltipContent side="top" className="max-w-xs text-xs">
                                        <div className="space-y-1">
                                            <p className="font-semibold">
                                                {row.indicator.name} ({year})
                                            </p>
                                            {cell?.has_data ? (
                                                <>
                                                    <p>
                                                        {formatNumber(cell.count)} of{' '}
                                                        {formatNumber(cell.total)} DeSOs (
                                                        {cell.coverage_pct}%)
                                                    </p>
                                                    <p>
                                                        Avg: {formatAvg(cell.avg_value, row.indicator.unit)}
                                                    </p>
                                                    <p>Updated: {formatDate(cell.last_updated)}</p>
                                                </>
                                            ) : (
                                                <p className="text-muted-foreground">
                                                    No data available
                                                </p>
                                            )}
                                        </div>
                                    </TooltipContent>
                                </Tooltip>
                            </td>
                        );
                    })}
                </tr>
            ))}
        </>
    );
}
