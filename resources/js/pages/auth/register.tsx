import { Form, Head, Link } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';

export default function Register() {
    return (
        <AuthLayout
            title="Skapa konto"
            description="Ange din e-post och ett lösenord"
        >
            <Head title="Skapa konto" />

            <Form
                action="/register"
                method="post"
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">E-post</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    placeholder="namn@example.com"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Lösenord</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    name="password"
                                    required
                                    autoComplete="new-password"
                                    placeholder="Minst 8 tecken"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                Skapa konto
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            Har du redan ett konto?{' '}
                            <Link
                                href="/login"
                                className="underline underline-offset-4 hover:text-primary"
                            >
                                Logga in
                            </Link>
                        </div>
                    </>
                )}
            </Form>
        </AuthLayout>
    );
}
