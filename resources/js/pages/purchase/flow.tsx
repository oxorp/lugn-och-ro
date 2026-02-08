import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    Loader2,
    Lock,
    Mail,
    MapPin,
} from 'lucide-react';
import React, { type FormEvent, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SharedData } from '@/types';

interface Props {
    lat: number;
    lng: number;
    address: string | null;
    kommun_name: string | null;
    lan_name: string | null;
    deso_code: string | null;
    score: number | null;
    stripe_key: string;
}

function scoreColorClass(score: number): string {
    if (score >= 80) return 'text-green-700';
    if (score >= 60) return 'text-green-600';
    if (score >= 40) return 'text-amber-600';
    if (score >= 20) return 'text-orange-600';
    return 'text-red-600';
}

function LocationSummary({
    address,
    kommun,
    lan,
    score,
}: {
    address: string | null;
    kommun: string | null;
    lan: string | null;
    score: number | null;
}) {
    return (
        <div className="mb-6 flex items-center gap-4 rounded-lg border bg-card p-4">
            <MapPin className="h-5 w-5 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <p className="truncate font-medium">
                    {address ?? 'Vald plats'}
                </p>
                <p className="text-sm text-muted-foreground">
                    {[kommun, lan].filter(Boolean).join(' \u00b7 ')}
                </p>
            </div>
            {score !== null && (
                <div
                    className={`shrink-0 text-2xl font-bold ${scoreColorClass(score)}`}
                >
                    {Math.round(score)}
                </div>
            )}
        </div>
    );
}

function StepIndicator({
    steps,
    current,
}: {
    steps: string[];
    current: number;
}) {
    return (
        <div className="mb-8 flex items-center gap-2">
            {steps.map((label, i) => (
                <React.Fragment key={label}>
                    {i > 0 && (
                        <div
                            className={`h-px flex-1 ${i <= current ? 'bg-primary' : 'bg-border'}`}
                        />
                    )}
                    <div className="flex items-center gap-2">
                        <div
                            className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-medium ${
                                i <= current
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            {i < current ? '\u2713' : i + 1}
                        </div>
                        <span
                            className={`hidden text-sm sm:inline ${
                                i === current
                                    ? 'font-medium'
                                    : 'text-muted-foreground'
                            }`}
                        >
                            {label}
                        </span>
                    </div>
                </React.Fragment>
            ))}
        </div>
    );
}

function GoogleIcon({ className }: { className?: string }) {
    return (
        <svg className={className} viewBox="0 0 24 24">
            <path
                d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"
                fill="#4285F4"
            />
            <path
                d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                fill="#34A853"
            />
            <path
                d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                fill="#FBBC05"
            />
            <path
                d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                fill="#EA4335"
            />
        </svg>
    );
}

function GuestEmailForm({
    onSubmit,
    onBack,
}: {
    onSubmit: (email: string) => void;
    onBack: () => void;
}) {
    const [email, setEmail] = useState('');
    const [error, setError] = useState<string | null>(null);

    const handleSubmit = () => {
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            setError('Ange en giltig e-postadress');
            return;
        }
        onSubmit(email);
    };

    return (
        <div className="space-y-4">
            <button
                onClick={onBack}
                className="flex items-center gap-1 text-sm text-muted-foreground hover:underline"
            >
                <ChevronLeft className="h-4 w-4" /> Tillbaka
            </button>

            <div>
                <h2 className="mb-1 text-xl font-semibold">
                    Din e-postadress
                </h2>
                <p className="text-sm text-muted-foreground">
                    Vi skickar en länk till din rapport. Du kan alltid komma
                    tillbaka till den via den här länken.
                </p>
            </div>

            <div>
                <Label htmlFor="guest-email">E-post</Label>
                <Input
                    id="guest-email"
                    type="email"
                    placeholder="namn@example.com"
                    value={email}
                    onChange={(e) => {
                        setEmail(e.target.value);
                        setError(null);
                    }}
                    onKeyDown={(e) => e.key === 'Enter' && handleSubmit()}
                    autoFocus
                />
                {error && (
                    <p className="mt-1 text-sm text-destructive">{error}</p>
                )}
            </div>

            <Button onClick={handleSubmit} className="w-full" size="lg">
                Fortsätt till betalning →
            </Button>

            <p className="text-center text-xs text-muted-foreground">
                Vi sparar aldrig kortuppgifter. Din e-post används bara för att
                skicka rapportlänken.
            </p>
        </div>
    );
}

function SignupForm({
    onSuccess,
    onBack,
}: {
    onSuccess: () => void;
    onBack: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/register', {
            onSuccess: () => onSuccess(),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <button
                type="button"
                onClick={onBack}
                className="flex items-center gap-1 text-sm text-muted-foreground hover:underline"
            >
                <ChevronLeft className="h-4 w-4" /> Tillbaka
            </button>

            <h2 className="text-xl font-semibold">Skapa konto</h2>

            <div>
                <Label htmlFor="signup-email">E-post</Label>
                <Input
                    id="signup-email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    autoFocus
                />
                {errors.email && (
                    <p className="mt-1 text-sm text-destructive">
                        {errors.email}
                    </p>
                )}
            </div>

            <div>
                <Label htmlFor="signup-password">Lösenord</Label>
                <Input
                    id="signup-password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                />
                {errors.password && (
                    <p className="mt-1 text-sm text-destructive">
                        {errors.password}
                    </p>
                )}
            </div>

            <Button
                type="submit"
                className="w-full"
                size="lg"
                disabled={processing}
            >
                Skapa konto & fortsätt →
            </Button>
        </form>
    );
}

function LoginForm({
    onSuccess,
    onBack,
}: {
    onSuccess: () => void;
    onBack: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        post('/login', {
            onSuccess: () => onSuccess(),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <button
                type="button"
                onClick={onBack}
                className="flex items-center gap-1 text-sm text-muted-foreground hover:underline"
            >
                <ChevronLeft className="h-4 w-4" /> Tillbaka
            </button>

            <h2 className="text-xl font-semibold">Logga in</h2>

            <div>
                <Label htmlFor="login-email">E-post</Label>
                <Input
                    id="login-email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    autoFocus
                />
                {errors.email && (
                    <p className="mt-1 text-sm text-destructive">
                        {errors.email}
                    </p>
                )}
            </div>

            <div>
                <Label htmlFor="login-password">Lösenord</Label>
                <Input
                    id="login-password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                />
                {errors.password && (
                    <p className="mt-1 text-sm text-destructive">
                        {errors.password}
                    </p>
                )}
            </div>

            <Button
                type="submit"
                className="w-full"
                size="lg"
                disabled={processing}
            >
                Logga in & fortsätt →
            </Button>
        </form>
    );
}

function IdentityStep({
    onGuestContinue,
    onSignedIn,
    redirectAfterAuth,
}: {
    onGuestContinue: (email: string) => void;
    onSignedIn: () => void;
    redirectAfterAuth: string;
}) {
    const [mode, setMode] = useState<
        'choose' | 'guest' | 'signup' | 'login'
    >('choose');

    if (mode === 'guest') {
        return (
            <GuestEmailForm
                onSubmit={onGuestContinue}
                onBack={() => setMode('choose')}
            />
        );
    }

    if (mode === 'signup') {
        return (
            <SignupForm
                onSuccess={onSignedIn}
                onBack={() => setMode('choose')}
            />
        );
    }

    if (mode === 'login') {
        return (
            <LoginForm
                onSuccess={onSignedIn}
                onBack={() => setMode('choose')}
            />
        );
    }

    return (
        <div className="space-y-4">
            <div>
                <h2 className="mb-1 text-xl font-semibold">
                    Hur vill du fortsätta?
                </h2>
                <p className="text-sm text-muted-foreground">
                    Du kan köpa utan konto — ange bara din e-post så skickar vi
                    rapportlänken dit.
                </p>
            </div>

            <button
                onClick={() => setMode('guest')}
                className="w-full rounded-lg border-2 border-primary p-4 text-left transition-colors hover:bg-primary/5"
            >
                <div className="flex items-center gap-3">
                    <Mail className="h-5 w-5 text-primary" />
                    <div>
                        <p className="font-medium">Fortsätt med e-post</p>
                        <p className="text-sm text-muted-foreground">
                            Inget konto behövs. Snabbast.
                        </p>
                    </div>
                </div>
            </button>

            <a
                href={`/auth/google?redirect=${encodeURIComponent(redirectAfterAuth)}`}
                className="flex w-full items-center gap-3 rounded-lg border p-4 transition-colors hover:bg-accent"
            >
                <GoogleIcon className="h-5 w-5" />
                <div>
                    <p className="font-medium">Fortsätt med Google</p>
                    <p className="text-sm text-muted-foreground">
                        Logga in och spara rapporter automatiskt
                    </p>
                </div>
            </a>

            <div className="flex gap-3">
                <button
                    onClick={() => setMode('signup')}
                    className="flex-1 rounded-lg border p-3 text-center transition-colors hover:bg-accent"
                >
                    <p className="text-sm font-medium">Skapa konto</p>
                </button>
                <button
                    onClick={() => setMode('login')}
                    className="flex-1 rounded-lg border p-3 text-center transition-colors hover:bg-accent"
                >
                    <p className="text-sm font-medium">Logga in</p>
                </button>
            </div>
        </div>
    );
}

function Feature({ text }: { text: string }) {
    return (
        <div className="flex items-start gap-2">
            <Check className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
            <span className="text-sm">{text}</span>
        </div>
    );
}

function PaymentStep({
    lat,
    lng,
    address,
    deso_code,
    kommun_name,
    lan_name,
    score,
    email,
}: {
    lat: number;
    lng: number;
    address: string | null;
    deso_code: string | null;
    kommun_name: string | null;
    lan_name: string | null;
    score: number | null;
    email: string;
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handlePay = async () => {
        setLoading(true);
        setError(null);

        try {
            const csrfToken = document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content');

            const res = await fetch('/purchase/checkout', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(csrfToken
                        ? { 'X-CSRF-TOKEN': csrfToken }
                        : {}),
                },
                body: JSON.stringify({
                    lat,
                    lng,
                    address,
                    deso_code,
                    kommun_name,
                    lan_name,
                    score,
                    email,
                }),
            });

            if (!res.ok) {
                const data = await res.json().catch(() => null);
                throw new Error(
                    data?.message ?? 'Något gick fel. Försök igen.',
                );
            }

            const data = await res.json();
            window.location.href = data.checkout_url;
        } catch (err: unknown) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Något gick fel. Försök igen.',
            );
            setLoading(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h2 className="mb-1 text-xl font-semibold">
                    Fullständig rapport
                </h2>
                <p className="text-sm text-muted-foreground">
                    Engångsköp · Ingen prenumeration · Din för alltid
                </p>
            </div>

            <div className="space-y-2 rounded-lg bg-muted/50 p-4">
                <p className="mb-3 text-sm font-medium">
                    Rapporten innehåller:
                </p>
                <Feature text="Detaljerad poängberäkning med alla indikatorer" />
                <Feature text="Skolanalys — meritvärden, lärarbehörighet, avstånd" />
                <Feature text="Närhetsanalys — kollektivtrafik, grönområden, service" />
                <Feature text="Styrkor och svagheter för området" />
                <Feature text="Permanent länk som alltid fungerar" />
            </div>

            <div className="flex items-center justify-between border-b border-t py-3">
                <span className="font-medium">Områdesrapport</span>
                <span className="text-xl font-bold">79 kr</span>
            </div>

            <p className="text-sm text-muted-foreground">
                Rapportlänk skickas till <strong>{email}</strong>
            </p>

            {error && (
                <div className="rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                    {error}
                </div>
            )}

            <Button
                onClick={handlePay}
                disabled={loading}
                className="w-full"
                size="lg"
            >
                {loading ? (
                    <>
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />{' '}
                        Förbereder betalning...
                    </>
                ) : (
                    'Betala 79 kr →'
                )}
            </Button>

            <div className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                <Lock className="h-3 w-3" />
                Säker betalning via Stripe · Inga kortuppgifter sparas hos oss
            </div>
        </div>
    );
}

export default function PurchaseFlow(props: Props) {
    const { auth } = usePage<SharedData>().props;
    const user = auth?.user;

    const initialStep = user ? 'payment' : 'identity';
    const [step, setStep] = useState<'identity' | 'payment'>(initialStep);
    const [guestEmail, setGuestEmail] = useState('');

    return (
        <div className="min-h-screen bg-background">
            <Head title="Köp rapport" />

            <header className="flex h-12 shrink-0 items-center border-b border-border bg-background px-4">
                <div className="flex w-full items-center gap-6">
                    <Link
                        href="/"
                        className="text-base font-semibold text-foreground"
                    >
                        Lugn och Ro
                    </Link>
                    <div className="flex-1" />
                    {user && (
                        <span className="text-sm text-muted-foreground">
                            {user.name || user.email}
                        </span>
                    )}
                </div>
            </header>

            <div className="mx-auto max-w-lg px-4 py-8">
                <LocationSummary
                    address={props.address}
                    kommun={props.kommun_name}
                    lan={props.lan_name}
                    score={props.score}
                />

                <StepIndicator
                    steps={
                        user
                            ? ['Betalning', 'Klar']
                            : ['Konto', 'Betalning', 'Klar']
                    }
                    current={
                        step === 'identity'
                            ? 0
                            : user
                              ? 0
                              : 1
                    }
                />

                {step === 'identity' && (
                    <IdentityStep
                        onGuestContinue={(email) => {
                            setGuestEmail(email);
                            setStep('payment');
                        }}
                        onSignedIn={() => {
                            setStep('payment');
                        }}
                        redirectAfterAuth={`/purchase/${props.lat},${props.lng}`}
                    />
                )}

                {step === 'payment' && (
                    <PaymentStep
                        {...props}
                        email={user?.email ?? guestEmail}
                    />
                )}
            </div>
        </div>
    );
}
