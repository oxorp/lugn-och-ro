# TASK: Purchase Flow — Identity + Stripe Checkout + Report Stub

## Context

The user has dropped a pin on the map and sees a headline score. They want the full report. This task builds everything between "click unlock" and "report generated" — the identity step, payment, and the report record in the database. The actual report content (indicators, proximity analysis, school details, preference questionnaire) is a separate future task that plugs into the skeleton we build here.

## What We're Building

**For guests (not signed in) — 3 steps:**
```
Pin dropped → Click "Lås upp rapport — 79 kr"
  → Step 1: Sign in / Sign up / Continue as guest (email only)
  → Step 2: Stripe Checkout (pay 79 kr)
  → Step 3: Done — report created, link shown
```

**For signed-in users — 1 step:**
```
Pin dropped → Click "Lås upp rapport — 79 kr"
  → Step 1: Stripe Checkout (pay 79 kr)
  → Done — report created, link shown
```

**Future addition (not this task):**
An additional step after payment where the user picks what matters to them (school quality, transit, safety, etc.) and the report personalizes its weighting. This plugs in as Step 3 for signed-in / Step 4 for guests. The report generation task will handle this.

## UI Decision: Page Route, Not Modal

The purchase flow routes to `/purchase/{lat},{lng}` — a dedicated page with the same navbar as the rest of the app. Not a modal, not a sidebar panel. Reasons:

- 3-step wizard in a modal feels cramped, especially on mobile
- Browser back/forward works properly (user can go back to step 1 from step 2)
- URL is bookmarkable/shareable ("I was about to buy this, what do you think?")
- Stripe Checkout redirects away from the site anyway — a modal would close and reopen awkwardly
- The map stays behind in browser history — one click back returns to the exact pin position

The page shows a **compact summary card** at the top so the user remembers what they're buying:

```
┌──────────────────────────────────────────────────────┐
│  📍 Sveavägen 42, Stockholm                          │
│  Stockholms kommun · Stockholms län                  │
│  Poäng: 68/100 · Stabilt / Positivt                 │
└──────────────────────────────────────────────────────┘
```

---

## Step 1: Database

### 1.1 Users Table

Laravel ships with a users table. Extend it for social login:

```php
Schema::table('users', function (Blueprint $table) {
    $table->string('google_id')->nullable()->unique()->after('email');
    $table->string('avatar_url')->nullable()->after('google_id');
    $table->string('provider', 20)->nullable()->after('avatar_url'); // 'google', 'email'
    $table->string('name')->nullable()->change(); // Not required for email-only
    $table->string('password')->nullable()->change(); // NULL for Google-only users
});
```

### 1.2 Reports Table (Stub)

The full reports table from the report generation task spec is overkill right now. We create a minimal version that the report task will extend via migration later:

```php
Schema::create('reports', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique()->index();
    $table->unsignedBigInteger('user_id')->nullable()->index();
    $table->string('guest_email')->nullable()->index();

    // Location snapshot
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    $table->string('address')->nullable();
    $table->string('kommun_name')->nullable();
    $table->string('lan_name')->nullable();
    $table->string('deso_code', 10)->nullable()->index();

    // Score snapshot (headline score at time of purchase)
    $table->decimal('score', 6, 2)->nullable();
    $table->string('score_label')->nullable();

    // Payment
    $table->string('stripe_session_id')->nullable()->unique();
    $table->string('stripe_payment_intent_id')->nullable();
    $table->integer('amount_ore');
    $table->string('currency', 3)->default('sek');
    $table->string('status', 20)->default('pending');
    // pending → paid → generating → completed
    // pending → expired  (abandoned checkout)
    // paid → refunded

    $table->integer('view_count')->default(0);
    $table->timestamps();
});
```

No `priorities`, `personalized_score`, `area_indicators`, `proximity_factors` etc. yet. Those come with the report generation task. This table records: who bought what, for where, and whether they paid.

### 1.3 Foreign Keys

```php
$table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
```

---

## Step 2: Auth — Laravel Breeze + Google OAuth

### 2.1 Install

```bash
docker compose exec app composer require laravel/breeze laravel/socialite
docker compose exec app php artisan breeze:install react --typescript --no-interaction
docker compose exec app npm install
docker compose exec app npm run build
```

Breeze gives us: login, register, password reset, email verification, session management. All with Inertia + React + TypeScript.

### 2.2 Customize Breeze Registration

The default Breeze register form asks for name, email, password, confirm password. We want:

- **Email** (required)
- **Password** (required, min 8)
- That's it. No name. No password confirmation. Lowest friction.

Override `RegisteredUserController`:

```php
public function store(Request $request): RedirectResponse
{
    $request->validate([
        'email' => 'required|string|lowercase|email|max:255|unique:users',
        'password' => ['required', Rules\Password::defaults()],
    ]);

    $user = User::create([
        'email' => $request->email,
        'password' => Hash::make($request->password),
        'provider' => 'email',
    ]);

    event(new Registered($user));

    Auth::login($user);

    // Claim any guest reports with this email
    $claimed = Report::where('guest_email', $user->email)
        ->whereNull('user_id')
        ->update(['user_id' => $user->id]);

    // Redirect to where they came from (usually the purchase flow)
    return redirect()->intended('/');
}
```

### 2.3 Google OAuth

```php
// routes/web.php
Route::get('/auth/google', [SocialAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [SocialAuthController::class, 'callback']);
```

```php
class SocialAuthController extends Controller
{
    public function redirect(Request $request)
    {
        // Store intended URL so we can redirect back after Google
        if ($request->has('redirect')) {
            session(['url.intended' => $request->query('redirect')]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'Google-inloggningen misslyckades. Försök igen.');
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            // Existing user — link Google if not already linked
            if (!$user->google_id) {
                $user->update([
                    'google_id' => $googleUser->getId(),
                    'avatar_url' => $googleUser->getAvatar(),
                ]);
            }
        } else {
            // New user via Google
            $user = User::create([
                'email' => $googleUser->getEmail(),
                'name' => $googleUser->getName(),
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'provider' => 'google',
                'email_verified_at' => now(),
            ]);
        }

        // Claim guest reports
        Report::where('guest_email', $user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        Auth::login($user, remember: true);

        return redirect()->intended('/');
    }
}
```

### 2.4 Login — Also Claim Reports

On every login (email or Google), claim unclaimed reports:

```php
// In LoginController or via event listener on Illuminate\Auth\Events\Login

class ClaimGuestReports
{
    public function handle(Login $event): void
    {
        Report::where('guest_email', $event->user->email)
            ->whereNull('user_id')
            ->update(['user_id' => $event->user->id]);
    }
}
```

Register in `EventServiceProvider`.

### 2.5 Environment

```env
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=${APP_URL}/auth/google/callback
```

---

## Step 3: Stripe Setup

### 3.1 Install

```bash
docker compose exec app composer require stripe/stripe-php
```

**Not using Laravel Cashier.** Cashier is for subscriptions and customer management. We're doing one-off Checkout Sessions. The Stripe PHP SDK is all we need.

### 3.2 Config

```php
// config/stripe.php
return [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'price_id' => env('STRIPE_PRICE_ID'),
];
```

### 3.3 Environment

```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_ID=price_...
```

### 3.4 Stripe Dashboard Setup (Test Mode)

1. **Product:** "Områdesrapport" / "Neighborhood Report"
2. **Price:** 7900 öre (79.00 SEK), one-time, currency SEK
3. Copy the `price_xxx` id → `.env` as `STRIPE_PRICE_ID`
4. Webhook endpoint → Step 6

---

## Step 4: Routes

```php
// Purchase flow
Route::get('/purchase/{lat},{lng}', [PurchaseController::class, 'show'])
    ->name('purchase')
    ->where(['lat' => '[0-9.]+', 'lng' => '[0-9.]+']);

Route::post('/purchase/checkout', [PurchaseController::class, 'checkout'])
    ->name('purchase.checkout');

Route::get('/purchase/success', [PurchaseController::class, 'success'])
    ->name('purchase.success');

Route::get('/purchase/cancel', [PurchaseController::class, 'cancel'])
    ->name('purchase.cancel');

Route::get('/purchase/status/{sessionId}', [PurchaseController::class, 'status'])
    ->name('purchase.status');

// Stripe webhook (no CSRF)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// Reports
Route::get('/reports/{report:uuid}', [ReportController::class, 'show'])
    ->name('reports.show');

Route::get('/my-reports', [MyReportsController::class, 'index'])
    ->name('my-reports');

Route::post('/my-reports/request-access', [MyReportsController::class, 'requestAccess'])
    ->name('my-reports.request-access');
```

---

## Step 5: Purchase Flow — The 3-Step Wizard

### 5.1 PurchaseController

```php
class PurchaseController extends Controller
{
    public function show(float $lat, float $lng)
    {
        // Validate Sweden bounds
        abort_unless($lat >= 55 && $lat <= 69 && $lng >= 11 && $lng <= 25, 404);

        // Get location info for the summary card
        $deso = DB::selectOne("
            SELECT d.deso_code, d.kommun_name, d.lan_name
            FROM deso_areas d
            WHERE ST_Contains(d.geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ", [$lng, $lat]);

        // Get headline score
        $score = null;
        if ($deso) {
            $score = DB::selectOne("
                SELECT score FROM composite_scores
                WHERE deso_code = ? ORDER BY year DESC LIMIT 1
            ", [$deso->deso_code]);
        }

        // Reverse geocode for display address (Photon)
        $address = $this->reverseGeocode($lat, $lng);

        return Inertia::render('Purchase/Flow', [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'kommun_name' => $deso->kommun_name ?? null,
            'lan_name' => $deso->lan_name ?? null,
            'deso_code' => $deso->deso_code ?? null,
            'score' => $score->score ?? null,
            'user' => auth()->user() ? [
                'id' => auth()->id(),
                'email' => auth()->user()->email,
                'name' => auth()->user()->name,
                'avatar_url' => auth()->user()->avatar_url,
            ] : null,
            'stripe_key' => config('stripe.key'),
        ]);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|min:55|max:69',
            'lng' => 'required|numeric|min:11|max:25',
            'address' => 'nullable|string|max:500',
            'deso_code' => 'nullable|string|max:10',
            'kommun_name' => 'nullable|string|max:100',
            'lan_name' => 'nullable|string|max:100',
            'score' => 'nullable|numeric',
            'email' => 'required_without:user_authenticated|email',
        ]);

        $email = auth()->check()
            ? auth()->user()->email
            : $validated['email'];

        \Stripe\Stripe::setApiKey(config('stripe.secret'));

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'customer_email' => $email,
            'line_items' => [[
                'price' => config('stripe.price_id'),
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => route('purchase.success') . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('purchase.cancel') . '?session_id={CHECKOUT_SESSION_ID}',
            'metadata' => [
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
                'deso_code' => $validated['deso_code'],
                'user_id' => auth()->id(),
            ],
            'locale' => 'sv',
            'expires_after' => 1800,
        ]);

        // Create report in pending state
        $report = Report::create([
            'uuid' => Str::uuid(),
            'user_id' => auth()->id(),
            'guest_email' => auth()->check() ? null : $email,
            'lat' => $validated['lat'],
            'lng' => $validated['lng'],
            'address' => $validated['address'],
            'kommun_name' => $validated['kommun_name'],
            'lan_name' => $validated['lan_name'],
            'deso_code' => $validated['deso_code'],
            'score' => $validated['score'],
            'stripe_session_id' => $session->id,
            'amount_ore' => 7900,
            'status' => 'pending',
        ]);

        return response()->json([
            'checkout_url' => $session->url,
        ]);
    }

    public function success(Request $request)
    {
        $sessionId = $request->query('session_id');
        if (!$sessionId) return redirect('/');

        $report = Report::where('stripe_session_id', $sessionId)->first();
        if (!$report) return redirect('/');

        // If already completed (webhook beat us), go straight to report
        if ($report->status === 'completed') {
            return redirect("/reports/{$report->uuid}");
        }

        // Show processing page that polls for webhook completion
        return Inertia::render('Purchase/Processing', [
            'session_id' => $sessionId,
            'report_uuid' => $report->uuid,
            'address' => $report->address,
            'lat' => $report->lat,
            'lng' => $report->lng,
        ]);
    }

    public function cancel(Request $request)
    {
        $sessionId = $request->query('session_id');

        if ($sessionId) {
            Report::where('stripe_session_id', $sessionId)
                ->where('status', 'pending')
                ->update(['status' => 'expired']);
        }

        $report = Report::where('stripe_session_id', $sessionId)->first();
        if ($report) {
            return redirect("/explore/{$report->lat},{$report->lng}");
        }

        return redirect('/');
    }

    public function status(string $sessionId)
    {
        $report = Report::where('stripe_session_id', $sessionId)->first();

        if (!$report) {
            return response()->json(['status' => 'unknown'], 404);
        }

        return response()->json([
            'status' => $report->status,
            'report_uuid' => $report->uuid,
        ]);
    }

    private function reverseGeocode(float $lat, float $lng): ?string
    {
        try {
            $response = Http::timeout(3)->get('https://photon.komoot.io/reverse', [
                'lat' => $lat,
                'lon' => $lng,
            ]);
            $props = $response->json('features.0.properties');
            return collect([$props['street'], $props['housenumber'], $props['city']])
                ->filter()->implode(', ') ?: null;
        } catch (\Exception) {
            return null;
        }
    }
}
```

### 5.2 The Wizard Page

```tsx
// resources/js/Pages/Purchase/Flow.tsx

interface Props {
    lat: number;
    lng: number;
    address: string | null;
    kommun_name: string | null;
    lan_name: string | null;
    deso_code: string | null;
    score: number | null;
    user: { id: number; email: string; name: string | null; avatar_url: string | null } | null;
    stripe_key: string;
}

export default function PurchaseFlow(props: Props) {
    const { user } = props;

    // If signed in, skip straight to payment step
    const initialStep = user ? 'payment' : 'identity';
    const [step, setStep] = useState<'identity' | 'payment' | 'processing'>(initialStep);
    const [guestEmail, setGuestEmail] = useState('');

    return (
        <div className="min-h-screen bg-background">
            {/* Same navbar as rest of app */}
            <Navbar user={user} />

            <div className="max-w-lg mx-auto px-4 py-8">
                {/* Location summary card — always visible */}
                <LocationSummary
                    address={props.address}
                    kommun={props.kommun_name}
                    lan={props.lan_name}
                    score={props.score}
                />

                {/* Step indicator */}
                <StepIndicator
                    steps={user
                        ? ['Betalning', 'Klar']
                        : ['Konto', 'Betalning', 'Klar']
                    }
                    current={
                        step === 'identity' ? 0 :
                        step === 'payment' ? (user ? 0 : 1) :
                        user ? 1 : 2
                    }
                />

                {/* Step content */}
                {step === 'identity' && (
                    <IdentityStep
                        onGuestContinue={(email) => {
                            setGuestEmail(email);
                            setStep('payment');
                        }}
                        onSignedIn={() => {
                            // Page will reload with user prop after Inertia redirect
                            // or we can setStep('payment') directly
                            setStep('payment');
                        }}
                        redirectAfterAuth={`/purchase/${props.lat},${props.lng}`}
                    />
                )}

                {step === 'payment' && (
                    <PaymentStep
                        {...props}
                        email={user?.email ?? guestEmail}
                        onSuccess={() => setStep('processing')}
                    />
                )}

                {step === 'processing' && (
                    <ProcessingStep address={props.address} />
                )}
            </div>
        </div>
    );
}
```

### 5.3 Location Summary Card

```tsx
function LocationSummary({ address, kommun, lan, score }: {
    address: string | null;
    kommun: string | null;
    lan: string | null;
    score: number | null;
}) {
    return (
        <div className="flex items-center gap-4 border rounded-lg p-4 mb-6 bg-card">
            <MapPin className="h-5 w-5 text-muted-foreground shrink-0" />
            <div className="flex-1 min-w-0">
                <p className="font-medium truncate">
                    {address ?? 'Vald plats'}
                </p>
                <p className="text-sm text-muted-foreground">
                    {[kommun, lan].filter(Boolean).join(' · ')}
                </p>
            </div>
            {score !== null && (
                <div className={cn("text-2xl font-bold shrink-0", scoreColorClass(score))}>
                    {Math.round(score)}
                </div>
            )}
        </div>
    );
}
```

### 5.4 Step Indicator

```tsx
function StepIndicator({ steps, current }: { steps: string[]; current: number }) {
    return (
        <div className="flex items-center gap-2 mb-8">
            {steps.map((label, i) => (
                <React.Fragment key={label}>
                    {i > 0 && (
                        <div className={cn(
                            "h-px flex-1",
                            i <= current ? "bg-primary" : "bg-border"
                        )} />
                    )}
                    <div className="flex items-center gap-2">
                        <div className={cn(
                            "h-7 w-7 rounded-full flex items-center justify-center text-xs font-medium",
                            i < current ? "bg-primary text-primary-foreground" :
                            i === current ? "bg-primary text-primary-foreground" :
                            "bg-muted text-muted-foreground"
                        )}>
                            {i < current ? '✓' : i + 1}
                        </div>
                        <span className={cn(
                            "text-sm hidden sm:inline",
                            i === current ? "font-medium" : "text-muted-foreground"
                        )}>
                            {label}
                        </span>
                    </div>
                </React.Fragment>
            ))}
        </div>
    );
}
```

---

## Step 6: Identity Step (Guests Only)

### 6.1 The Three Options

```tsx
function IdentityStep({ onGuestContinue, onSignedIn, redirectAfterAuth }: {
    onGuestContinue: (email: string) => void;
    onSignedIn: () => void;
    redirectAfterAuth: string;
}) {
    const [mode, setMode] = useState<'choose' | 'guest' | 'signup' | 'login'>('choose');

    if (mode === 'choose') {
        return (
            <div className="space-y-4">
                <div>
                    <h2 className="text-xl font-semibold mb-1">Hur vill du fortsätta?</h2>
                    <p className="text-sm text-muted-foreground">
                        Du kan köpa utan konto — ange bara din e-post
                        så skickar vi rapportlänken dit.
                    </p>
                </div>

                {/* Guest — the primary path, visually dominant */}
                <button
                    onClick={() => setMode('guest')}
                    className="w-full border-2 border-primary rounded-lg p-4 text-left hover:bg-primary/5 transition-colors"
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

                {/* Google */}
                <a
                    href={`/auth/google?redirect=${encodeURIComponent(redirectAfterAuth)}`}
                    className="w-full border rounded-lg p-4 flex items-center gap-3 hover:bg-accent transition-colors"
                >
                    <GoogleIcon className="h-5 w-5" />
                    <div>
                        <p className="font-medium">Fortsätt med Google</p>
                        <p className="text-sm text-muted-foreground">
                            Logga in och spara rapporter automatiskt
                        </p>
                    </div>
                </a>

                {/* Email signup / login */}
                <div className="flex gap-3">
                    <button
                        onClick={() => setMode('signup')}
                        className="flex-1 border rounded-lg p-3 text-center hover:bg-accent transition-colors"
                    >
                        <p className="text-sm font-medium">Skapa konto</p>
                    </button>
                    <button
                        onClick={() => setMode('login')}
                        className="flex-1 border rounded-lg p-3 text-center hover:bg-accent transition-colors"
                    >
                        <p className="text-sm font-medium">Logga in</p>
                    </button>
                </div>
            </div>
        );
    }

    if (mode === 'guest') {
        return <GuestEmailForm onSubmit={onGuestContinue} onBack={() => setMode('choose')} />;
    }

    if (mode === 'signup') {
        return <SignupForm onSuccess={onSignedIn} onBack={() => setMode('choose')} />;
    }

    if (mode === 'login') {
        return <LoginForm onSuccess={onSignedIn} onBack={() => setMode('choose')} />;
    }
}
```

### 6.2 Guest Email Form

```tsx
function GuestEmailForm({ onSubmit, onBack }: {
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
            <button onClick={onBack} className="text-sm text-muted-foreground hover:underline flex items-center gap-1">
                <ChevronLeft className="h-4 w-4" /> Tillbaka
            </button>

            <div>
                <h2 className="text-xl font-semibold mb-1">Din e-postadress</h2>
                <p className="text-sm text-muted-foreground">
                    Vi skickar en länk till din rapport. Du kan alltid komma
                    tillbaka till den via den här länken.
                </p>
            </div>

            <div>
                <Label htmlFor="email">E-post</Label>
                <Input
                    id="email"
                    type="email"
                    placeholder="namn@example.com"
                    value={email}
                    onChange={e => { setEmail(e.target.value); setError(null); }}
                    onKeyDown={e => e.key === 'Enter' && handleSubmit()}
                    autoFocus
                />
                {error && <p className="text-sm text-destructive mt-1">{error}</p>}
            </div>

            <Button onClick={handleSubmit} className="w-full" size="lg">
                Fortsätt till betalning →
            </Button>

            <p className="text-xs text-muted-foreground text-center">
                Vi sparar aldrig kortuppgifter. Din e-post används
                bara för att skicka rapportlänken.
            </p>
        </div>
    );
}
```

### 6.3 Inline Signup Form

```tsx
function SignupForm({ onSuccess, onBack }: { onSuccess: () => void; onBack: () => void }) {
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
            <button type="button" onClick={onBack} className="text-sm text-muted-foreground hover:underline flex items-center gap-1">
                <ChevronLeft className="h-4 w-4" /> Tillbaka
            </button>

            <h2 className="text-xl font-semibold">Skapa konto</h2>

            <div>
                <Label htmlFor="email">E-post</Label>
                <Input id="email" type="email" value={data.email}
                    onChange={e => setData('email', e.target.value)} autoFocus />
                {errors.email && <p className="text-sm text-destructive mt-1">{errors.email}</p>}
            </div>

            <div>
                <Label htmlFor="password">Lösenord</Label>
                <Input id="password" type="password" value={data.password}
                    onChange={e => setData('password', e.target.value)} />
                {errors.password && <p className="text-sm text-destructive mt-1">{errors.password}</p>}
            </div>

            <Button type="submit" className="w-full" size="lg" disabled={processing}>
                Skapa konto & fortsätt →
            </Button>
        </form>
    );
}
```

### 6.4 Inline Login Form

Same pattern as signup but POSTs to `/login`. Include a "Glömt lösenord?" link.

---

## Step 7: Payment Step

```tsx
function PaymentStep({ lat, lng, address, deso_code, kommun_name, lan_name, score, email, onSuccess }: {
    // ... all props
    email: string;
    onSuccess: () => void;
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handlePay = async () => {
        setLoading(true);
        setError(null);

        try {
            const res = await axios.post('/purchase/checkout', {
                lat, lng, address, deso_code, kommun_name, lan_name, score, email,
            });

            // Redirect to Stripe Checkout
            window.location.href = res.data.checkout_url;
        } catch (err: any) {
            setError(err.response?.data?.message ?? 'Något gick fel. Försök igen.');
            setLoading(false);
        }
    };

    return (
        <div className="space-y-6">
            <div>
                <h2 className="text-xl font-semibold mb-1">Fullständig rapport</h2>
                <p className="text-sm text-muted-foreground">
                    Engångsköp · Ingen prenumeration · Din för alltid
                </p>
            </div>

            {/* What you get */}
            <div className="bg-muted/50 rounded-lg p-4 space-y-2">
                <p className="font-medium text-sm mb-3">Rapporten innehåller:</p>
                <Feature text="Detaljerad poängberäkning med alla indikatorer" />
                <Feature text="Skolanalys — meritvärden, lärarbehörighet, avstånd" />
                <Feature text="Närhetsanalys — kollektivtrafik, grönområden, service" />
                <Feature text="Styrkor och svagheter för området" />
                <Feature text="Permanent länk som alltid fungerar" />
            </div>

            {/* Price */}
            <div className="flex items-center justify-between py-3 border-t border-b">
                <span className="font-medium">Områdesrapport</span>
                <span className="text-xl font-bold">79 kr</span>
            </div>

            {/* Email confirmation */}
            <p className="text-sm text-muted-foreground">
                Rapportlänk skickas till <strong>{email}</strong>
            </p>

            {error && (
                <div className="bg-destructive/10 text-destructive text-sm rounded-lg p-3">
                    {error}
                </div>
            )}

            <Button onClick={handlePay} disabled={loading} className="w-full" size="lg">
                {loading ? (
                    <><Loader2 className="h-4 w-4 animate-spin mr-2" /> Förbereder betalning...</>
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

function Feature({ text }: { text: string }) {
    return (
        <div className="flex items-start gap-2">
            <Check className="h-4 w-4 text-green-600 shrink-0 mt-0.5" />
            <span className="text-sm">{text}</span>
        </div>
    );
}
```

---

## Step 8: Stripe Webhook

```php
class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                config('stripe.webhook_secret')
            );
        } catch (\Exception $e) {
            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCompleted($event->data->object),
            'checkout.session.expired' => $this->handleExpired($event->data->object),
            default => null,
        };

        return response('OK', 200);
    }

    private function handleCompleted($session): void
    {
        $report = Report::where('stripe_session_id', $session->id)->first();
        if (!$report || $report->status === 'completed') return;

        $report->update([
            'status' => 'completed', // Will become 'paid' → 'generating' → 'completed' when report generation task lands
            'stripe_payment_intent_id' => $session->payment_intent,
        ]);

        // Send confirmation email with report link
        $email = $report->guest_email ?? $report->user?->email;
        if ($email) {
            Mail::to($email)->send(new \App\Mail\ReportReady($report));
        }
    }

    private function handleExpired($session): void
    {
        Report::where('stripe_session_id', $session->id)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);
    }
}
```

---

## Step 9: Processing + Completion

### 9.1 Processing Page (Webhook Race Condition)

```tsx
// resources/js/Pages/Purchase/Processing.tsx

export default function Processing({ session_id, report_uuid, address }: Props) {
    const [status, setStatus] = useState<'processing' | 'ready' | 'timeout'>('processing');

    useEffect(() => {
        const poll = setInterval(async () => {
            const res = await axios.get(`/purchase/status/${session_id}`);
            if (res.data.status === 'completed') {
                setStatus('ready');
                clearInterval(poll);
                setTimeout(() => {
                    window.location.href = `/reports/${report_uuid}`;
                }, 1500);
            }
        }, 2000);

        const timeout = setTimeout(() => {
            clearInterval(poll);
            setStatus('timeout');
        }, 60000);

        return () => { clearInterval(poll); clearTimeout(timeout); };
    }, []);

    return (
        <div className="min-h-screen flex flex-col items-center justify-center px-4">
            {status === 'processing' && (
                <>
                    <Loader2 className="h-8 w-8 animate-spin text-primary mb-4" />
                    <h1 className="text-xl font-semibold mb-2">Betalning mottagen!</h1>
                    <p className="text-muted-foreground text-center">
                        Vi förbereder din rapport för {address}...
                    </p>
                </>
            )}
            {status === 'ready' && (
                <>
                    <CheckCircle className="h-8 w-8 text-green-500 mb-4" />
                    <h1 className="text-xl font-semibold mb-2">Klar!</h1>
                    <p className="text-muted-foreground">Öppnar din rapport...</p>
                </>
            )}
            {status === 'timeout' && (
                <>
                    <Clock className="h-8 w-8 text-amber-500 mb-4" />
                    <h1 className="text-xl font-semibold mb-2">Tar lite längre än vanligt</h1>
                    <p className="text-muted-foreground text-center mb-4">
                        Din betalning har gått igenom. Vi skickar
                        rapportlänken till din e-post inom kort.
                    </p>
                    <Button variant="outline" onClick={() => window.location.href = '/'}>
                        Tillbaka till kartan
                    </Button>
                </>
            )}
        </div>
    );
}
```

### 9.2 Report Stub Page

Until the full report generation task lands, `/reports/{uuid}` shows a placeholder:

```tsx
// resources/js/Pages/Reports/Show.tsx

export default function ReportShow({ report }: { report: ReportData }) {
    return (
        <div className="max-w-2xl mx-auto py-12 px-4">
            <div className="text-center mb-8">
                <div className={cn("text-5xl font-bold mb-2", scoreColorClass(report.score))}>
                    {Math.round(report.score)}
                </div>
                <p className="text-lg text-muted-foreground">{scoreLabel(report.score)}</p>
            </div>

            <div className="border rounded-lg p-4 mb-6">
                <div className="flex items-center gap-2 mb-1">
                    <MapPin className="h-4 w-4 text-muted-foreground" />
                    <span className="font-medium">{report.address}</span>
                </div>
                <p className="text-sm text-muted-foreground">
                    {report.kommun_name} · {report.lan_name}
                </p>
            </div>

            {/* Placeholder for full report content */}
            <div className="bg-muted/50 rounded-lg p-8 text-center">
                <p className="text-muted-foreground mb-2">
                    Den fullständiga rapporten med detaljerade indikatorer,
                    skolanalys och närhetsanalys kommer snart.
                </p>
                <p className="text-sm text-muted-foreground">
                    Du kommer att få ett e-postmeddelande när rapporten
                    är komplett.
                </p>
            </div>

            <div className="mt-8 text-center text-sm text-muted-foreground">
                <p>Rapport-ID: {report.uuid}</p>
                <p>Skapad: {formatDate(report.created_at)}</p>
                <p className="mt-2">
                    Spara den här länken — den fungerar för alltid.
                </p>
            </div>
        </div>
    );
}
```

### 9.3 ReportController

```php
class ReportController extends Controller
{
    public function show(Report $report)
    {
        // Only show paid reports (or completed)
        if (!in_array($report->status, ['completed', 'paid'])) {
            abort(404);
        }

        $report->increment('view_count');

        return Inertia::render('Reports/Show', [
            'report' => [
                'uuid' => $report->uuid,
                'address' => $report->address,
                'kommun_name' => $report->kommun_name,
                'lan_name' => $report->lan_name,
                'score' => $report->score,
                'score_label' => $this->scoreLabel($report->score),
                'created_at' => $report->created_at->toISOString(),
                'view_count' => $report->view_count,
            ],
        ]);
    }

    private function scoreLabel(?float $score): string
    {
        if ($score === null) return 'Ingen data';
        return match(true) {
            $score >= 80 => 'Starkt tillväxtområde',
            $score >= 60 => 'Stabilt / Positivt',
            $score >= 40 => 'Blandat',
            $score >= 20 => 'Förhöjd risk',
            default => 'Hög risk',
        };
    }
}
```

---

## Step 10: My Reports

### 10.1 For Logged-In Users

```php
class MyReportsController extends Controller
{
    public function index(Request $request)
    {
        if (auth()->check()) {
            $reports = Report::where('user_id', auth()->id())
                ->whereIn('status', ['completed', 'paid'])
                ->orderByDesc('created_at')
                ->get();

            return Inertia::render('Reports/MyReports', [
                'reports' => $reports,
                'email' => auth()->user()->email,
            ]);
        }

        // Guest: check for signed URL
        if ($request->hasValidSignature() && $request->query('email')) {
            $reports = Report::where('guest_email', $request->query('email'))
                ->whereIn('status', ['completed', 'paid'])
                ->orderByDesc('created_at')
                ->get();

            return Inertia::render('Reports/MyReports', [
                'reports' => $reports,
                'email' => $request->query('email'),
                'guest' => true,
            ]);
        }

        // No auth → show email request form
        return Inertia::render('Reports/RequestAccess');
    }

    public function requestAccess(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $url = URL::temporarySignedRoute(
            'my-reports',
            now()->addHours(24),
            ['email' => $request->email]
        );

        // Always send email (don't reveal if reports exist)
        Mail::to($request->email)->send(new \App\Mail\MyReportsAccess($url));

        return back()->with('status', 'sent');
    }
}
```

---

## Step 11: Sidebar Unlock Button

In the existing sidebar (on the map page), add the CTA that starts the purchase flow:

```tsx
// In the sidebar component, below the headline score

{score !== null && (
    <div className="border-t pt-4 mt-4">
        <a
            href={`/purchase/${lat},${lng}`}
            className="block w-full"
        >
            <Button className="w-full" size="lg">
                Lås upp fullständig rapport — 79 kr
            </Button>
        </a>
        <p className="text-xs text-muted-foreground text-center mt-2">
            Engångsköp · Ingen prenumeration
        </p>
    </div>
)}
```

Simple link to the purchase page. No JavaScript, no modals, just a route transition.

---

## Step 12: Confirmation Email

```php
// app/Mail/ReportReady.php
class ReportReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Report $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Din områdesrapport är klar');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.report-ready',
            with: [
                'url' => url("/reports/{$this->report->uuid}"),
                'address' => $this->report->address,
                'score' => $this->report->score,
            ],
        );
    }
}
```

```blade
<x-mail::message>
# Din rapport är klar

Vi har analyserat **{{ $address }}**.

@if($score)
**Områdespoäng: {{ round($score) }}/100**
@endif

<x-mail::button :url="$url">
Visa rapport
</x-mail::button>

Spara den här länken — den fungerar för alltid.

{{ $url }}

*{{ config('app.name') }}*
</x-mail::message>
```

---

## Step 13: Dev Bypass

For local development without Stripe credentials:

```php
// In PurchaseController::checkout

if (app()->environment('local') && !config('stripe.secret')) {
    // Skip Stripe entirely — mark report as completed
    $report->update(['status' => 'completed']);

    return response()->json([
        'checkout_url' => "/reports/{$report->uuid}",
        'dev_mode' => true,
    ]);
}
```

---

## Step 14: Expired Checkout Cleanup

```php
// Schedule daily
$schedule->command('purchase:cleanup')->daily();
```

```php
class CleanupExpiredPurchases extends Command
{
    protected $signature = 'purchase:cleanup';

    public function handle()
    {
        $expired = Report::where('status', 'pending')
            ->where('created_at', '<', now()->subHours(2))
            ->update(['status' => 'expired']);

        $this->info("Expired {$expired} abandoned checkouts.");
    }
}
```

---

## Testing

### Test Cards (Stripe Test Mode)

| Card | Result |
|---|---|
| `4242 4242 4242 4242` | Payment succeeds |
| `4000 0000 0000 3220` | 3D Secure required (still succeeds) |
| `4000 0000 0000 0002` | Payment declined |

Any future expiry, any CVC, any name.

### Stripe CLI for Local Webhooks

```bash
stripe login
stripe listen --forward-to localhost:8000/stripe/webhook
# Copy whsec_... → .env STRIPE_WEBHOOK_SECRET
```

---

## Verification

### Guest Purchase Flow
- [ ] Drop pin → "Lås upp — 79 kr" button visible in sidebar
- [ ] Click button → routed to `/purchase/{lat},{lng}`
- [ ] Location summary card shows address, kommun, score
- [ ] Step indicator shows 3 steps: Konto · Betalning · Klar
- [ ] "Fortsätt med e-post" is the visually dominant option
- [ ] Enter email → click "Fortsätt till betalning" → moves to payment step
- [ ] Payment step shows feature list, price (79 kr), email confirmation
- [ ] Click "Betala 79 kr" → redirects to Stripe Checkout (Swedish locale)
- [ ] Pay with `4242...` → redirect back to processing page
- [ ] Processing shows spinner → "Klar!" → redirects to `/reports/{uuid}`
- [ ] Report stub page shows score, address, placeholder for full content
- [ ] Confirmation email received with working link

### Google Login Purchase Flow
- [ ] Click "Fortsätt med Google" → Google consent → redirected back → step 2 (payment)
- [ ] Guest reports with same email auto-claimed on login

### Signed-In Purchase Flow
- [ ] Signed-in user sees "Lås upp — 79 kr" → clicks → routed to purchase page
- [ ] Step indicator shows 2 steps: Betalning · Klar (no identity step)
- [ ] No email field (uses account email)
- [ ] Pay → report linked to user_id

### Webhook Resilience
- [ ] Close browser after Stripe redirect, before returning → webhook fires → report status = completed → email sent
- [ ] UUID link from email works

### My Reports
- [ ] Signed-in: `/my-reports` shows all purchased reports
- [ ] Guest: `/my-reports` shows email form → magic link email → clicking link shows reports
- [ ] Empty state: "Du har inga rapporter" with link to map

### Cancel / Error
- [ ] Cancel on Stripe page → redirected to map with pin preserved
- [ ] Declined card → stays on Stripe (their UI handles error), no report created
- [ ] Expired checkout (2h) cleaned up by scheduled command

### Dev Bypass
- [ ] Without STRIPE_SECRET → purchase bypasses Stripe, report created directly

---

## What This Task Does NOT Do

- **NO report content generation.** The report page is a stub showing score + address. Full content (indicators, proximity, schools, preferences) comes in the report generation task.
- **NO preference questionnaire.** That's the report generation task. This task creates the report record; that task fills it with data.
- **NO subscriptions or credit packs.** Single purchase only. Upsell flows are future work.
- **NO Swish or Klarna.** Card payments via Stripe Checkout. Other methods added later as Stripe payment method types.
- **NO BankID.** Google + email is enough for launch.
- **NO admin refund UI.** Refunds done manually in Stripe Dashboard for now.
- **NO PDF generation.** The report is a web page.

## What the Report Generation Task Will Add Later

When the report generation task is implemented, it will:

1. Add columns to `reports`: `priorities`, `personalized_score`, `area_indicators`, `proximity_factors`, `schools`, `personalization_impact`, `top_positive`, `top_negative`
2. Add a preference step in the purchase wizard (Step 3 for signed-in, Step 4 for guests — between payment and "done")
3. Replace the stub report page with full content
4. Change report status flow: `pending → paid → generating → completed`
5. The webhook marks status as `paid`, then dispatches a job to generate content, then marks `completed`

The skeleton this task builds is designed to accommodate all of that without restructuring.