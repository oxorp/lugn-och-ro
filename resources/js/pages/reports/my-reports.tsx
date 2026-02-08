import { Head, Link } from '@inertiajs/react';
import { MapPin } from 'lucide-react';

interface ReportSummary {
    uuid: string;
    address: string | null;
    kommun_name: string | null;
    score: number | null;
    created_at: string;
}

interface Props {
    reports: ReportSummary[];
    email: string;
    guest?: boolean;
}

function scoreColorClass(score: number): string {
    if (score >= 80) return 'text-green-700';
    if (score >= 60) return 'text-green-600';
    if (score >= 40) return 'text-amber-600';
    if (score >= 20) return 'text-orange-600';
    return 'text-red-600';
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('sv-SE', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export default function MyReports({ reports, email, guest }: Props) {
    return (
        <div className="mx-auto max-w-2xl px-4 py-12">
            <Head title="Mina rapporter" />

            <h1 className="mb-2 text-2xl font-bold">Mina rapporter</h1>
            <p className="mb-8 text-sm text-muted-foreground">
                {guest
                    ? `Rapporter för ${email}`
                    : `Inloggad som ${email}`}
            </p>

            {reports.length === 0 ? (
                <div className="rounded-lg border border-dashed p-8 text-center">
                    <p className="mb-4 text-muted-foreground">
                        Du har inga rapporter ännu.
                    </p>
                    <Link
                        href="/"
                        className="text-sm font-medium text-primary hover:underline"
                    >
                        Utforska kartan →
                    </Link>
                </div>
            ) : (
                <div className="space-y-3">
                    {reports.map((report) => (
                        <Link
                            key={report.uuid}
                            href={`/reports/${report.uuid}`}
                            className="flex items-center gap-4 rounded-lg border p-4 transition-colors hover:bg-accent"
                        >
                            <MapPin className="h-5 w-5 shrink-0 text-muted-foreground" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate font-medium">
                                    {report.address ?? report.kommun_name ?? 'Okänd plats'}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatDate(report.created_at)}
                                </p>
                            </div>
                            {report.score !== null && (
                                <div
                                    className={`shrink-0 text-lg font-bold ${scoreColorClass(report.score)}`}
                                >
                                    {Math.round(report.score)}
                                </div>
                            )}
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}
