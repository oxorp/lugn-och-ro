import { Head } from '@inertiajs/react';
import { CheckCircle, Clock, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';

interface Props {
    session_id: string;
    report_uuid: string;
    address: string | null;
    lat: number;
    lng: number;
}

export default function Processing({
    session_id,
    report_uuid,
    address,
}: Props) {
    const [status, setStatus] = useState<'processing' | 'ready' | 'timeout'>(
        'processing',
    );

    useEffect(() => {
        const poll = setInterval(async () => {
            try {
                const res = await fetch(`/purchase/status/${session_id}`);
                const data = await res.json();
                if (data.status === 'completed') {
                    setStatus('ready');
                    clearInterval(poll);
                    setTimeout(() => {
                        window.location.href = `/reports/${report_uuid}`;
                    }, 1500);
                }
            } catch {
                // Ignore fetch errors during polling
            }
        }, 2000);

        const timeout = setTimeout(() => {
            clearInterval(poll);
            setStatus('timeout');
        }, 60000);

        return () => {
            clearInterval(poll);
            clearTimeout(timeout);
        };
    }, [session_id, report_uuid]);

    return (
        <div className="flex min-h-screen flex-col items-center justify-center px-4">
            <Head title="Behandlar betalning" />

            {status === 'processing' && (
                <>
                    <Loader2 className="mb-4 h-8 w-8 animate-spin text-primary" />
                    <h1 className="mb-2 text-xl font-semibold">
                        Betalning mottagen!
                    </h1>
                    <p className="text-center text-muted-foreground">
                        Vi förbereder din rapport för {address ?? 'din plats'}
                        ...
                    </p>
                </>
            )}
            {status === 'ready' && (
                <>
                    <CheckCircle className="mb-4 h-8 w-8 text-green-500" />
                    <h1 className="mb-2 text-xl font-semibold">Klar!</h1>
                    <p className="text-muted-foreground">
                        Öppnar din rapport...
                    </p>
                </>
            )}
            {status === 'timeout' && (
                <>
                    <Clock className="mb-4 h-8 w-8 text-amber-500" />
                    <h1 className="mb-2 text-xl font-semibold">
                        Tar lite längre än vanligt
                    </h1>
                    <p className="mb-4 text-center text-muted-foreground">
                        Din betalning har gått igenom. Vi skickar rapportlänken
                        till din e-post inom kort.
                    </p>
                    <Button
                        variant="outline"
                        onClick={() => (window.location.href = '/')}
                    >
                        Tillbaka till kartan
                    </Button>
                </>
            )}
        </div>
    );
}
