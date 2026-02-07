import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    ChevronDown,
    Clock,
    Database,
    Loader2,
    Play,
    RefreshCw,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';

import LocaleSync from '@/components/locale-sync';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

interface LastRun {
    id: number;
    status: string;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
    records_processed: number;
    records_created: number;
    records_updated: number;
    records_failed: number;
    summary: string | null;
    has_warnings: boolean;
    has_errors: boolean;
}

interface Source {
    key: string;
    name: string;
    description: string;
    expected_frequency: string;
    critical: boolean;
    health: 'healthy' | 'warning' | 'critical' | 'unknown';
    last_run: LastRun | null;
    last_success_at: string | null;
    running: boolean;
    indicator_count: number;
    commands: string[];
}

interface RecentLog {
    id: number;
    source: string;
    command: string;
    status: string;
    trigger: string | null;
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
    stats: Record<string, string | number> | null;
    memory_peak_mb: number | null;
}

interface Stats {
    total_ingestion_runs: number;
    runs_last_7_days: number;
    failed_last_7_days: number;
    total_indicators: number;
    total_desos_with_scores: number;
    total_schools: number;
}

interface Props {
    sources: Source[];
    overallHealth: 'healthy' | 'warning' | 'critical';
    stats: Stats;
    pipelineOrder: string[];
    recentLogs: RecentLog[];
}

const healthConfig = {
    healthy: { color: 'bg-emerald-500', label: 'Healthy' },
    warning: { color: 'bg-amber-500', label: 'Warning' },
    critical: { color: 'bg-red-500', label: 'Critical' },
    unknown: { color: 'bg-slate-400', label: 'Unknown' },
};

const statusIcons: Record<string, React.ReactNode> = {
    completed: <CheckCircle className="h-4 w-4 text-emerald-500" />,
    failed: <XCircle className="h-4 w-4 text-red-500" />,
    running: <Loader2 className="h-4 w-4 animate-spin text-blue-500" />,
};

function formatDuration(seconds: number | null): string {
    if (seconds === null) return '-';
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s > 0 ? `${m}m ${s}s` : `${m}m`;
}

function formatRelativeTime(dateStr: string | null): string {
    if (!dateStr) return 'Never';
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    return `${diffDays}d ago`;
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

export default function PipelinePage({ sources, overallHealth, stats, pipelineOrder, recentLogs }: Props) {
    const [runAllOpen, setRunAllOpen] = useState(false);
    const [runAllYear, setRunAllYear] = useState(String(new Date().getFullYear() - 1));
    const [submitting, setSubmitting] = useState(false);

    // Poll while any source is running
    useEffect(() => {
        if (sources.some((s) => s.running)) {
            const interval = setInterval(() => {
                router.reload({ only: ['sources', 'recentLogs', 'stats'] });
            }, 5000);
            return () => clearInterval(interval);
        }
    }, [sources]);

    function handleRunCommand(sourceKey: string, command: string) {
        router.post(`/admin/pipeline/${sourceKey}/run`, { command }, {
            preserveScroll: true,
        });
    }

    function handleRunAll() {
        setSubmitting(true);
        router.post('/admin/pipeline/run-all', { year: parseInt(runAllYear) }, {
            preserveScroll: true,
            onFinish: () => {
                setSubmitting(false);
                setRunAllOpen(false);
            },
        });
    }

    const currentYear = new Date().getFullYear();
    const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - 1 - i);

    return (
        <div className="mx-auto max-w-7xl p-6">
            <LocaleSync />
            <Head title="Pipeline Dashboard" />

            {/* Admin Nav */}
            <nav className="mb-4 flex items-center gap-4 text-sm">
                <Link href="/admin/indicators" className="text-muted-foreground hover:text-foreground">
                    Indicators
                </Link>
                <span className="font-medium text-foreground">Pipeline</span>
                <Link href="/admin/data-quality" className="text-muted-foreground hover:text-foreground">
                    Data Quality
                </Link>
            </nav>

            {/* Header */}
            <div className="mb-6 flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-bold">Pipeline Dashboard</h1>
                    <p className="text-muted-foreground text-sm">
                        Data ingestion sources, health status, and run history
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <div className="flex items-center gap-2">
                        <div className={`h-3 w-3 rounded-full ${healthConfig[overallHealth].color}`} />
                        <span className="text-sm font-medium">{healthConfig[overallHealth].label}</span>
                    </div>
                    <Button onClick={() => setRunAllOpen(true)}>
                        <Play className="mr-2 h-4 w-4" />
                        Run All
                    </Button>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4 lg:grid-cols-6">
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-muted-foreground text-xs">Sources</div>
                        <div className="text-2xl font-bold">{sources.length}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-muted-foreground text-xs">Runs (7d)</div>
                        <div className="text-2xl font-bold">{stats.runs_last_7_days}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-muted-foreground text-xs">Failed (7d)</div>
                        <div className="text-2xl font-bold text-red-600">{stats.failed_last_7_days}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-muted-foreground text-xs">Indicators</div>
                        <div className="text-2xl font-bold">{stats.total_indicators}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-muted-foreground text-xs">DeSOs Scored</div>
                        <div className="text-2xl font-bold">{stats.total_desos_with_scores.toLocaleString()}</div>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-4 pb-4">
                        <div className="text-muted-foreground text-xs">Schools</div>
                        <div className="text-2xl font-bold">{stats.total_schools.toLocaleString()}</div>
                    </CardContent>
                </Card>
            </div>

            {/* Source Health Cards */}
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle>Source Health</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                    <div className="divide-y">
                        {sources.map((source) => (
                            <div key={source.key} className="flex items-center justify-between px-6 py-4">
                                <div className="flex-1">
                                    <div className="flex items-center gap-3">
                                        {source.running ? (
                                            <div className="h-3 w-3 animate-pulse rounded-full bg-blue-500" />
                                        ) : (
                                            <div className={`h-3 w-3 rounded-full ${healthConfig[source.health].color}`} />
                                        )}
                                        <span className="font-medium">{source.name}</span>
                                        {source.critical && (
                                            <Badge variant="outline" className="text-xs">Critical</Badge>
                                        )}
                                        {source.running && (
                                            <Badge className="bg-blue-100 text-blue-800 text-xs">Running</Badge>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground mt-1 ml-6 text-sm">
                                        {source.indicator_count > 0 && (
                                            <span>{source.indicator_count} indicators</span>
                                        )}
                                        {source.last_run && (
                                            <>
                                                {source.indicator_count > 0 && <span className="mx-1">&middot;</span>}
                                                <span>
                                                    Last: {source.last_run.status === 'completed' ? 'completed' : source.last_run.status}
                                                    {source.last_run.records_processed > 0 && (
                                                        <> &middot; {source.last_run.records_processed.toLocaleString()} records</>
                                                    )}
                                                    {source.last_run.duration_seconds !== null && (
                                                        <> &middot; {formatDuration(source.last_run.duration_seconds)}</>
                                                    )}
                                                </span>
                                            </>
                                        )}
                                    </div>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-muted-foreground text-sm">
                                        {source.running ? (
                                            <span className="flex items-center gap-1">
                                                <Loader2 className="h-3 w-3 animate-spin" />
                                                Running...
                                            </span>
                                        ) : (
                                            formatRelativeTime(source.last_success_at)
                                        )}
                                    </span>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => router.visit(`/admin/pipeline/${source.key}`)}
                                    >
                                        View
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="outline" size="sm" disabled={source.running}>
                                                Run
                                                <ChevronDown className="ml-1 h-3 w-3" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            {source.commands.map((cmd) => (
                                                <DropdownMenuItem
                                                    key={cmd}
                                                    onClick={() => handleRunCommand(source.key, cmd)}
                                                >
                                                    {cmd.charAt(0).toUpperCase() + cmd.slice(1)}
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            {/* Recent Activity */}
            <Card>
                <CardHeader>
                    <CardTitle>Recent Activity</CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Command</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">Records</TableHead>
                                <TableHead className="text-right">Duration</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {recentLogs.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-muted-foreground text-center">
                                        No ingestion runs yet
                                    </TableCell>
                                </TableRow>
                            ) : (
                                recentLogs.map((log) => (
                                    <TableRow
                                        key={log.id}
                                        className="cursor-pointer hover:bg-muted/50"
                                        onClick={() => router.visit(`/admin/pipeline/${log.source}`)}
                                    >
                                        <TableCell className="text-muted-foreground text-sm">
                                            {formatDate(log.started_at)}
                                        </TableCell>
                                        <TableCell className="font-mono text-sm">
                                            {log.command}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-1.5">
                                                {statusIcons[log.status] || (
                                                    <Clock className="text-muted-foreground h-4 w-4" />
                                                )}
                                                <span className="text-sm">{log.status}</span>
                                            </div>
                                            {log.error_message && (
                                                <div className="mt-0.5 max-w-sm truncate text-xs text-red-500">
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
                                            {formatDuration(log.duration_seconds)}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            {/* Run All Dialog */}
            <Dialog open={runAllOpen} onOpenChange={setRunAllOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Run Full Pipeline</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 py-4">
                        <p className="text-muted-foreground text-sm">
                            This will run all data sources in sequence:
                        </p>
                        <ol className="text-muted-foreground list-inside list-decimal space-y-1 text-sm">
                            {pipelineOrder.map((key) => {
                                const source = sources.find((s) => s.key === key);
                                return (
                                    <li key={key}>
                                        {source?.name ?? key}
                                    </li>
                                );
                            })}
                        </ol>
                        <div>
                            <label className="mb-1 block text-sm font-medium">Year</label>
                            <Select value={runAllYear} onValueChange={setRunAllYear}>
                                <SelectTrigger className="w-32">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {yearOptions.map((y) => (
                                        <SelectItem key={y} value={String(y)}>
                                            {y}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Estimated time: 15-20 minutes
                        </p>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRunAllOpen(false)}>
                            Cancel
                        </Button>
                        <Button onClick={handleRunAll} disabled={submitting}>
                            {submitting && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                            Run Pipeline
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
