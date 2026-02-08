import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function RequestAccess() {
    const { flash } = usePage<{ flash: { status?: string } }>().props;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/my-reports/request-access');
    };

    if (flash?.status === 'sent') {
        return (
            <div className="mx-auto max-w-md px-4 py-12 text-center">
                <Head title="Kontrollera din e-post" />
                <h1 className="mb-2 text-2xl font-bold">
                    Kontrollera din e-post
                </h1>
                <p className="text-muted-foreground">
                    Om det finns rapporter kopplade till den e-postadressen har
                    vi skickat en länk som ger dig åtkomst. Länken gäller i 24
                    timmar.
                </p>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-md px-4 py-12">
            <Head title="Åtkomst till rapporter" />

            <h1 className="mb-2 text-2xl font-bold">Åtkomst till rapporter</h1>
            <p className="mb-6 text-sm text-muted-foreground">
                Ange e-postadressen du använde vid köpet så skickar vi en länk
                till dina rapporter.
            </p>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <Label htmlFor="email">E-post</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="namn@example.com"
                        autoFocus
                    />
                    {errors.email && (
                        <p className="mt-1 text-sm text-destructive">
                            {errors.email}
                        </p>
                    )}
                </div>

                <Button
                    type="submit"
                    className="w-full"
                    disabled={processing}
                >
                    Skicka åtkomstlänk
                </Button>
            </form>
        </div>
    );
}
