import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faArrowLeft,
    faChevronDown,
    faCircleCheck,
    faCircleXmark,
    faClock,
    faSpinnerThird,
} from '@/icons';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AdminLayout from '@/layouts/admin-layout';

interface SourceConfig {
    key: string;
    name: string;
    description: string;
    expected_frequency: string;
    critical: boolean;
    commands: Record<string, { command: string; options: Record<string, unknown> }>;
    indicators: string[];
    stale_after_days: number | null;
    health: 'healthy' | 'warning' | 'critical' | 'unknown';
    running: boolean;
    last_success_at: string | null;
}

interface LogEntry {
    id: number;
    command: string;
    status: string;
    trigger: string | null;
    triggered_by: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
    records_processed: number;
    records_created: number;
    records_updated: number;
    records_failed: number;
    records_skipped: number;
    summary: string | null;
    error_message: string | null;
    warnings: string[] | null;
    stats: Record<string, unknown> | null;
    memory_peak_mb: number | null;
}

interface IndicatorCoverage {
    slug: string;
    name: string;
    latest_year: number | null;
    deso_coverage: number;
    coverage_pct: number;
}

interface Props {
    source: SourceConfig;
    logs: LogEntry[];
    indicators: IndicatorCoverage[];
}

const healthConfig = {
    healthy: {
        color: 'bg-emerald-500',
        label: 'Healthy',
        text: 'text-emerald-700',
    },
    warning: {
        color: 'bg-amber-500',
        label: 'Warning',
        text: 'text-amber-700',
    },
    critical: { color: 'bg-red-500', label: 'Critical', text: 'text-red-700' },
    unknown: {
        color: 'bg-slate-400',
        label: 'Unknown',
        text: 'text-slate-600',
    },
};

const statusIcons: Record<string, React.ReactNode> = {
    completed: <FontAwesomeIcon icon={faCircleCheck} className="h-4 w-4 text-emerald-500" />,
    failed: <FontAwesomeIcon icon={faCircleXmark} className="h-4 w-4 text-red-500" />,
    running: <FontAwesomeIcon icon={faSpinnerThird} spin className="h-4 w-4 text-blue-500" />,
};

function formatDuration(seconds: number | null): string {
    if (seconds === null) return '-';
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s > 0 ? `${m}m ${s}s` : `${m}m`;
}

function formatDate(dateStr: string | null): string {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleString('sv-SE', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatFullDate(dateStr: string | null): string {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleString('sv-SE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

export default function PipelineSourcePage({
    source,
    logs,
    indicators,
}: Props) {
    const [selectedLog, setSelectedLog] = useState<LogEntry | null>(null);

    // Poll while source is running
    useEffect(() => {
        if (source.running) {
            const interval = setInterval(() => {
                router.reload({ only: ['source', 'logs'] });
            }, 5000);
            return () => clearInterval(interval);
        }
    }, [source.running]);

    function handleRunCommand(command: string) {
        router.post(
            `/admin/pipeline/${source.key}/run`,
            { command },
            {
                preserveScroll: true,
            },
        );
    }

    const commands = Object.keys(source.commands);
    const health = healthConfig[source.health];

    return (
        <AdminLayout>
            <Head title={`Pipeline - ${source.name}`} />

            {/* Header */}
            <div className="mb-6">
                <Button
                    variant="ghost"
                    size="sm"
                    className="mb-2"
                    onClick={() => router.visit('/admin/pipeline')}
                >
                    <FontAwesomeIcon icon={faArrowLeft} className="mr-1 h-4 w-4" />
                    Pipeline
                </Button>
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">{source.name}</h1>
                        <p className="text-sm text-muted-foreground">
                            {source.description}
                        </p>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button disabled={source.running}>
                                {source.running ? (
                                    <FontAwesomeIcon icon={faSpinnerThird} spin className="mr-2 h-4 w-4" />
                                ) : null}
                                Run
                                <FontAwesomeIcon icon={faChevronDown} className="ml-1 h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {commands.map((cmd) => (
                                <DropdownMenuItem
                                    key={cmd}
                                    onClick={() => handleRunCommand(cmd)}
                                >
                                    {cmd.charAt(0).toUpperCase() + cmd.slice(1)}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            {/* Source Info */}
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-xs text-muted-foreground">
                            Status
                        </div>
                        <div className="mt-1 flex items-center gap-2">
                            <div
                                className={`h-3 w-3 rounded-full ${health.color}`}
                            />
                            <span className={`font-medium ${health.text}`}>
                                {health.label}
                            </span>
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-xs text-muted-foreground">
                            Last Success
                        </div>
                        <div className="mt-1 text-sm font-medium">
                            {source.last_success_at
                                ? formatFullDate(source.last_success_at)
                                : 'Never'}
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-xs text-muted-foreground">
                            Frequency
                        </div>
                        <div className="mt-1 text-sm font-medium capitalize">
                            {source.expected_frequency.replace('_', ' ')}
                        </div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-xs text-muted-foreground">
                            Commands
                        </div>
                        <div className="mt-1 flex flex-wrap gap-1">
                            {Object.entries(source.commands).map(([key, cmd]) => (
                                <Badge
                                    key={key}
                                    variant="secondary"
                                    className="font-mono text-xs"
                                >
                                    {typeof cmd === 'string' ? cmd : cmd.command}
                                </Badge>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Indicator Coverage */}
            {indicators.length > 0 && (
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Indicator Coverage</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {indicators.map((ind) => (
                                <div
                                    key={ind.slug}
                                    className="flex items-center gap-4"
                                >
                                    <div className="w-48 truncate font-mono text-sm">
                                        {ind.slug}
                                    </div>
                                    <div className="flex-1">
                                        <div className="h-2.5 w-full rounded-full bg-slate-100">
                                            <div
                                                className="h-2.5 rounded-full bg-emerald-500 transition-all"
                                                style={{
                                                    width: `${Math.min(ind.coverage_pct, 100)}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                    <div className="w-16 text-right text-sm text-muted-foreground">
                                        {ind.coverage_pct}%
                                    </div>
                                </div>
                            ))}
                        </div>
                        {indicators[0]?.latest_year && (
                            <p className="mt-4 text-xs text-muted-foreground">
                                Latest year: {indicators[0].latest_year}{' '}
                                &middot;{' '}
                                {Math.max(
                                    ...indicators.map((i) => i.deso_coverage),
                                ).toLocaleString()}{' '}
                                of 6,160 DeSOs
                            </p>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* Ingestion History */}
            <Card>
                <CardHeader>
                    <CardTitle>Ingestion History</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Command</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Records
                                </TableHead>
                                <TableHead className="text-right">
                                    Duration
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {logs.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="text-center text-muted-foreground"
                                    >
                                        No ingestion runs for this source
                                    </TableCell>
                                </TableRow>
                            ) : (
                                logs.map((log) => (
                                    <TableRow
                                        key={log.id}
                                        className="cursor-pointer hover:bg-slate-50"
                                        onClick={() => setSelectedLog(log)}
                                    >
                                        <TableCell className="text-sm text-muted-foreground">
                                            {formatDate(log.started_at)}
                                        </TableCell>
                                        <TableCell className="font-mono text-sm">
                                            {log.command}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1.5">
                                                {statusIcons[log.status] || (
                                                    <FontAwesomeIcon icon={faClock} className="h-4 w-4 text-muted-foreground" />
                                                )}
                                                <span className="text-sm">
                                                    {log.status}
                                                </span>
                                            </div>
                                            {log.error_message && (
                                                <div className="mt-0.5 max-w-md truncate text-xs text-red-500">
                                                    {log.error_message}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            {log.records_processed > 0
                                                ? log.records_processed.toLocaleString()
                                                : '-'}
                                        </TableCell>
                                        <TableCell className="text-right text-sm">
                                            {formatDuration(
                                                log.duration_seconds,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                    {logs.length > 0 && (
                        <div className="border-t px-6 py-3 text-xs text-muted-foreground">
                            Showing {logs.length} most recent &middot; Total:{' '}
                            {logs.length} runs
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* Log Detail Modal */}
            <Dialog
                open={selectedLog !== null}
                onOpenChange={() => setSelectedLog(null)}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Ingestion Log #{selectedLog?.id}
                        </DialogTitle>
                    </DialogHeader>
                    {selectedLog && (
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 gap-x-8 gap-y-2 text-sm">
                                <div>
                                    <span className="text-muted-foreground">
                                        Source:
                                    </span>{' '}
                                    <span className="font-medium">
                                        {source.name}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Command:
                                    </span>{' '}
                                    <span className="font-mono">
                                        {selectedLog.command}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Status:
                                    </span>{' '}
                                    <span className="inline-flex items-center gap-1">
                                        {statusIcons[selectedLog.status]}
                                        {selectedLog.status}
                                    </span>
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Trigger:
                                    </span>{' '}
                                    {selectedLog.trigger ?? '-'}
                                    {selectedLog.triggered_by &&
                                        ` (${selectedLog.triggered_by})`}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Started:
                                    </span>{' '}
                                    {formatFullDate(selectedLog.started_at)}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Finished:
                                    </span>{' '}
                                    {formatFullDate(selectedLog.completed_at)}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Duration:
                                    </span>{' '}
                                    {formatDuration(
                                        selectedLog.duration_seconds,
                                    )}
                                </div>
                                <div>
                                    <span className="text-muted-foreground">
                                        Memory:
                                    </span>{' '}
                                    {selectedLog.memory_peak_mb
                                        ? `${selectedLog.memory_peak_mb} MB peak`
                                        : '-'}
                                </div>
                            </div>

                            {/* Records */}
                            <div>
                                <h4 className="mb-2 text-sm font-medium">
                                    Records
                                </h4>
                                <div className="grid grid-cols-5 gap-2 text-center">
                                    {[
                                        [
                                            'Processed',
                                            selectedLog.records_processed,
                                        ],
                                        [
                                            'Created',
                                            selectedLog.records_created,
                                        ],
                                        [
                                            'Updated',
                                            selectedLog.records_updated,
                                        ],
                                        ['Failed', selectedLog.records_failed],
                                        [
                                            'Skipped',
                                            selectedLog.records_skipped,
                                        ],
                                    ].map(([label, value]) => (
                                        <div
                                            key={label as string}
                                            className="rounded bg-slate-50 px-2 py-1.5"
                                        >
                                            <div className="text-xs text-muted-foreground">
                                                {label as string}
                                            </div>
                                            <div className="font-mono text-sm font-medium">
                                                {(
                                                    value as number
                                                ).toLocaleString()}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Stats */}
                            {selectedLog.stats &&
                                Object.keys(selectedLog.stats).length > 0 && (
                                    <div>
                                        <h4 className="mb-2 text-sm font-medium">
                                            Stats
                                        </h4>
                                        <div className="rounded bg-slate-50 p-3 font-mono text-xs">
                                            {Object.entries(
                                                selectedLog.stats,
                                            ).map(([key, value]) => (
                                                <div
                                                    key={key}
                                                    className="flex justify-between py-0.5"
                                                >
                                                    <span className="text-muted-foreground">
                                                        {key}:
                                                    </span>
                                                    <span>{String(value)}</span>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                            {/* Warnings */}
                            {selectedLog.warnings &&
                                selectedLog.warnings.length > 0 && (
                                    <div>
                                        <h4 className="mb-2 text-sm font-medium">
                                            Warnings (
                                            {selectedLog.warnings.length})
                                        </h4>
                                        <div className="space-y-1">
                                            {selectedLog.warnings.map(
                                                (w, i) => (
                                                    <div
                                                        key={i}
                                                        className="flex items-start gap-2 text-sm text-amber-700"
                                                    >
                                                        <span>&#9888;</span>
                                                        <span>{w}</span>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                )}

                            {/* Error */}
                            {selectedLog.error_message && (
                                <div>
                                    <h4 className="mb-2 text-sm font-medium text-red-700">
                                        Error
                                    </h4>
                                    <div className="max-h-64 overflow-auto rounded bg-red-50 p-3 font-mono text-xs break-all whitespace-pre-wrap text-red-800">
                                        {selectedLog.error_message}
                                    </div>
                                </div>
                            )}

                            {/* Summary */}
                            {selectedLog.summary && (
                                <div>
                                    <h4 className="mb-2 text-sm font-medium">
                                        Summary
                                    </h4>
                                    <div className="max-h-48 overflow-auto rounded bg-slate-50 p-3 font-mono text-xs whitespace-pre-wrap">
                                        {selectedLog.summary}
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
