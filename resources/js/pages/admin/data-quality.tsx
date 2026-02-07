import { Head, router } from '@inertiajs/react';
import { useState } from 'react';

import LocaleSync from '@/components/locale-sync';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useTranslation } from '@/hooks/use-translation';

interface OverallHealth {
    status: 'healthy' | 'warning' | 'critical';
    warnings: number;
    errors: number;
}

interface SourceHealth {
    source: string;
    status: string;
    last_ingested: string | null;
    indicator_count: number;
}

interface ScoreVersionData {
    id: number;
    year: number;
    status: string;
    deso_count: number;
    mean_score: number;
    stddev_score: number;
    computed_at: string;
    published_at: string | null;
    notes: string | null;
}

interface ValidationEntry {
    id: number;
    source: string;
    command: string;
    date: string | null;
    passed: number;
    failed: number;
    skipped: number;
    status: string;
}

interface SentinelResult {
    name: string;
    deso_code: string;
    tier: string;
    score: number | null;
    expected_min: number;
    expected_max: number;
    passed: boolean;
}

interface IngestionEntry {
    id: number;
    source: string;
    command: string;
    status: string;
    records_processed: number | null;
    started_at: string | null;
    completed_at: string | null;
    duration_seconds: number | null;
}

interface Props {
    overallHealth: OverallHealth;
    sourceHealth: SourceHealth[];
    latestVersion: ScoreVersionData | null;
    recentValidations: ValidationEntry[];
    sentinelResults: SentinelResult[];
    ingestionHistory: IngestionEntry[];
    scoreVersions: ScoreVersionData[];
}

const healthColors: Record<string, string> = {
    healthy: 'bg-green-500',
    warning: 'bg-yellow-500',
    critical: 'bg-red-500',
};

const statusColors: Record<string, string> = {
    current: 'bg-green-500',
    stale: 'bg-yellow-500',
    outdated: 'bg-red-500',
    unknown: 'bg-gray-400',
};

const versionStatusBadge: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    validated: 'bg-blue-100 text-blue-800',
    published: 'bg-green-100 text-green-800',
    superseded: 'bg-gray-100 text-gray-600',
    rolled_back: 'bg-red-100 text-red-800',
};

function formatDate(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleString('sv-SE', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDuration(seconds: number | null): string {
    if (seconds === null) return '-';
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

export default function DataQualityPage({
    overallHealth,
    sourceHealth,
    latestVersion,
    recentValidations,
    sentinelResults,
    ingestionHistory,
    scoreVersions,
}: Props) {
    const [publishing, setPublishing] = useState<number | null>(null);

    function handlePublish(versionId: number) {
        setPublishing(versionId);
        router.post(`/admin/data-quality/publish/${versionId}`, {}, {
            preserveScroll: true,
            onFinish: () => setPublishing(null),
        });
    }

    const { t } = useTranslation();

    function handleRollback(versionId: number) {
        if (!confirm(t('admin.data_quality.confirm_rollback'))) return;
        router.post(`/admin/data-quality/rollback/${versionId}`, {}, {
            preserveScroll: true,
        });
    }

    return (
        <div className="mx-auto max-w-7xl p-6">
            <LocaleSync />
            <Head title={t('admin.data_quality.head_title')} />

            <div className="mb-6">
                <h1 className="text-2xl font-bold">{t('admin.data_quality.title')}</h1>
                <p className="text-muted-foreground text-sm">
                    {t('admin.data_quality.subtitle')}
                </p>
            </div>

            {/* Overall Health */}
            <Card className="mb-6">
                <CardContent className="flex items-center gap-3 pt-6">
                    <div className={`h-4 w-4 rounded-full ${healthColors[overallHealth.status]}`} />
                    <span className="text-lg font-semibold capitalize">{overallHealth.status}</span>
                    {overallHealth.warnings > 0 && (
                        <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">
                            {overallHealth.warnings} warning{overallHealth.warnings !== 1 ? 's' : ''}
                        </Badge>
                    )}
                    {overallHealth.errors > 0 && (
                        <Badge variant="secondary" className="bg-red-100 text-red-800">
                            {overallHealth.errors} error{overallHealth.errors !== 1 ? 's' : ''}
                        </Badge>
                    )}
                </CardContent>
            </Card>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Source Health */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">Source Health</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {sourceHealth.map((s) => (
                                <div key={s.source} className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <div className={`h-2.5 w-2.5 rounded-full ${statusColors[s.status] ?? 'bg-gray-400'}`} />
                                        <span className="font-medium capitalize">{s.source}</span>
                                        <span className="text-muted-foreground text-xs">({s.indicator_count} indicators)</span>
                                    </div>
                                    <span className="text-muted-foreground text-sm">
                                        {s.last_ingested ? formatDate(s.last_ingested) : 'Never'}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Latest Score Version */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">
                            Latest Score Version{latestVersion ? ` #${latestVersion.id}` : ''}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {latestVersion ? (
                            <div className="space-y-2 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Status</span>
                                    <Badge className={versionStatusBadge[latestVersion.status] ?? ''} variant="secondary">
                                        {latestVersion.status}
                                    </Badge>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Computed</span>
                                    <span>{formatDate(latestVersion.computed_at)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">DeSOs scored</span>
                                    <span>{latestVersion.deso_count.toLocaleString()} / 6,160</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Mean score</span>
                                    <span>{latestVersion.mean_score.toFixed(1)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Std deviation</span>
                                    <span>{latestVersion.stddev_score.toFixed(1)}</span>
                                </div>
                            </div>
                        ) : (
                            <p className="text-muted-foreground text-sm">No versions computed yet.</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Sentinel Areas */}
            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">Sentinel Areas</CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {sentinelResults.map((s) => (
                            <div
                                key={s.deso_code}
                                className={`flex items-center justify-between rounded-lg border p-3 ${
                                    s.passed ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'
                                }`}
                            >
                                <div>
                                    <span className="text-sm font-medium">{s.name}</span>
                                    <span className="text-muted-foreground ml-2 text-xs">
                                        ({s.expected_min}-{s.expected_max})
                                    </span>
                                </div>
                                <span className={`font-mono text-sm font-bold ${s.passed ? 'text-green-700' : 'text-red-700'}`}>
                                    {s.score !== null ? s.score.toFixed(1) : 'N/A'}
                                </span>
                            </div>
                        ))}
                    </div>
                </CardContent>
            </Card>

            {/* Recent Validations */}
            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">Recent Validation Results</CardTitle>
                </CardHeader>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Source</TableHead>
                            <TableHead>Passed</TableHead>
                            <TableHead>Failed</TableHead>
                            <TableHead>Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {recentValidations.length > 0 ? (
                            recentValidations.map((v) => (
                                <TableRow key={v.id}>
                                    <TableCell className="text-sm">{formatDate(v.date)}</TableCell>
                                    <TableCell>
                                        <Badge variant="outline">{v.source}</Badge>
                                    </TableCell>
                                    <TableCell className="text-green-600">{v.passed}</TableCell>
                                    <TableCell className={v.failed > 0 ? 'text-red-600 font-medium' : 'text-muted-foreground'}>
                                        {v.failed}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant={v.status === 'completed' ? 'secondary' : 'destructive'}>
                                            {v.status}
                                        </Badge>
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell colSpan={5} className="text-muted-foreground text-center">
                                    No validation results yet
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </Card>

            {/* Score Versions */}
            <Card className="mb-6">
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">Score Versions</CardTitle>
                </CardHeader>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Version</TableHead>
                            <TableHead>Year</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>DeSOs</TableHead>
                            <TableHead>Mean</TableHead>
                            <TableHead>Computed</TableHead>
                            <TableHead>Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {scoreVersions.map((v) => (
                            <TableRow key={v.id}>
                                <TableCell className="font-mono">#{v.id}</TableCell>
                                <TableCell>{v.year}</TableCell>
                                <TableCell>
                                    <Badge className={versionStatusBadge[v.status] ?? ''} variant="secondary">
                                        {v.status}
                                    </Badge>
                                </TableCell>
                                <TableCell>{v.deso_count.toLocaleString()}</TableCell>
                                <TableCell>{v.mean_score.toFixed(1)}</TableCell>
                                <TableCell className="text-sm">{formatDate(v.computed_at)}</TableCell>
                                <TableCell>
                                    <div className="flex gap-1">
                                        {(v.status === 'pending' || v.status === 'validated') && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => handlePublish(v.id)}
                                                disabled={publishing === v.id}
                                            >
                                                {publishing === v.id ? 'Publishing...' : 'Publish'}
                                            </Button>
                                        )}
                                        {(v.status === 'superseded' || v.status === 'rolled_back') && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => handleRollback(v.id)}
                                            >
                                                Restore
                                            </Button>
                                        )}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            {/* Ingestion History */}
            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="text-sm font-medium">Ingestion History</CardTitle>
                </CardHeader>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Source</TableHead>
                            <TableHead>Records</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Duration</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {ingestionHistory.map((h) => (
                            <TableRow key={h.id}>
                                <TableCell className="text-sm">{formatDate(h.started_at)}</TableCell>
                                <TableCell>
                                    <Badge variant="outline">{h.source}</Badge>
                                </TableCell>
                                <TableCell>{h.records_processed?.toLocaleString() ?? '-'}</TableCell>
                                <TableCell>
                                    <Badge
                                        variant={
                                            h.status === 'completed'
                                                ? 'secondary'
                                                : h.status === 'failed'
                                                  ? 'destructive'
                                                  : 'outline'
                                        }
                                    >
                                        {h.status}
                                    </Badge>
                                </TableCell>
                                <TableCell className="text-muted-foreground text-sm">
                                    {formatDuration(h.duration_seconds)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>
        </div>
    );
}
