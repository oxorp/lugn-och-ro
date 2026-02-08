import { Head, Link } from '@inertiajs/react';
import { MapPin } from 'lucide-react';

interface ReportData {
    uuid: string;
    address: string | null;
    kommun_name: string | null;
    lan_name: string | null;
    score: number | null;
    score_label: string;
    created_at: string;
    view_count: number;
    lat: number;
    lng: number;
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
        month: 'long',
        day: 'numeric',
    });
}

export default function ReportShow({ report }: { report: ReportData }) {
    return (
        <div className="mx-auto max-w-2xl px-4 py-12">
            <Head title={`Rapport — ${report.address ?? report.kommun_name ?? 'Område'}`} />

            {report.score !== null && (
                <div className="mb-8 text-center">
                    <div
                        className={`mb-2 text-5xl font-bold ${scoreColorClass(report.score)}`}
                    >
                        {Math.round(report.score)}
                    </div>
                    <p className="text-lg text-muted-foreground">
                        {report.score_label}
                    </p>
                </div>
            )}

            <div className="mb-6 rounded-lg border p-4">
                <div className="mb-1 flex items-center gap-2">
                    <MapPin className="h-4 w-4 text-muted-foreground" />
                    <span className="font-medium">
                        {report.address ?? 'Vald plats'}
                    </span>
                </div>
                <p className="text-sm text-muted-foreground">
                    {[report.kommun_name, report.lan_name]
                        .filter(Boolean)
                        .join(' \u00b7 ')}
                </p>
            </div>

            <div className="rounded-lg bg-muted/50 p-8 text-center">
                <p className="mb-2 text-muted-foreground">
                    Den fullständiga rapporten med detaljerade indikatorer,
                    skolanalys och närhetsanalys kommer snart.
                </p>
                <p className="text-sm text-muted-foreground">
                    Du kommer att få ett e-postmeddelande när rapporten är
                    komplett.
                </p>
            </div>

            <div className="mt-8 text-center text-sm text-muted-foreground">
                <p>Rapport-ID: {report.uuid}</p>
                <p>Skapad: {formatDate(report.created_at)}</p>
                <p className="mt-2">
                    Spara den här länken — den fungerar för alltid.
                </p>
            </div>

            <div className="mt-8 text-center">
                <Link
                    href={`/explore/${report.lat},${report.lng}`}
                    className="text-sm font-medium text-primary hover:underline"
                >
                    Visa på kartan →
                </Link>
            </div>
        </div>
    );
}
