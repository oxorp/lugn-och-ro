# TASK: Authentication, Users, Tenants & Roles — Foundation

## Context

The app currently has no auth. Anyone can hit `/admin/indicators` and change weights. This task adds the minimum viable auth layer: sign-in (no sign-up yet), a global admin role, and a silent tenant system that scopes indicator weights per tenant without the user ever knowing tenants exist.

**The architecture:** Every user belongs to a tenant. On day one, there's one tenant (auto-created with a UUID name). Indicator weights are per-tenant, not global. The admin role is global — it's a superpower that transcends tenants, giving access to system dashboards, all tenants' data, ingestion commands, etc. When we eventually add sign-up, each new user either joins an existing tenant (invite flow) or creates a new one. But for now: one tenant, one admin user, sign-in only.

---

## Goals

1. User model with email/password authentication (Laravel Breeze or Fortify — keep it simple)
2. Tenant model — auto-created, UUID-named, invisible to users
3. Indicator weights scoped to tenant (not global)
4. Global admin role (not tenant-bound)
5. Sign-in page only (no sign-up, no forgot-password yet)
6. Protect admin routes behind admin role
7. Seed a default tenant + admin user
8. Middleware that resolves current tenant from authenticated user

---

## Step 1: Database Schema

### 1.1 Tenants Table

```php
Schema::create('tenants', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->string('name')->nullable();              // Human name, optional — null for now
    $table->string('slug')->nullable()->unique();     // For future URL routing (/t/acme/)
    $table->json('settings')->nullable();             // Future: tenant-specific config
    $table->timestamps();
});
```

The tenant is a lightweight container. No billing, no plan, no features — just an identity that owns indicator weights. Settings JSON is a future escape hatch for per-tenant config without schema changes.

### 1.2 Users Table (Extend Laravel Default)

Laravel ships with a `users` migration. Modify it or create a new one:

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('email')->unique();
    $table->timestamp('email_verified_at')->nullable();
    $table->string('password');
    $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
    $table->boolean('is_admin')->default(false);       // Global admin flag
    $table->rememberToken();
    $table->timestamps();
});
```

**Why `is_admin` as a boolean, not a roles table?**

Right now there are exactly two states: admin or not-admin. A full roles/permissions system (Spatie, Bouncer, etc.) is overkill when you have one role. When we need tenant-admin, billing-admin, viewer, etc., we add a proper roles table then. The `is_admin` flag migrates cleanly into a roles system later — just seed the "admin" role for all users where `is_admin = true`, then drop the column.

### 1.3 Tenant Indicator Weights Table

Currently, indicator weights live on the `indicators` table directly. We need to move them to a per-tenant table so different tenants can have different weighting models.

```php
Schema::create('tenant_indicator_weights', function (Blueprint $table) {
    $table->id();
    $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
    $table->foreignId('indicator_id')->constrained()->cascadeOnDelete();
    $table->decimal('weight', 5, 4)->default(0.0);
    $table->enum('direction', ['positive', 'negative', 'neutral'])->default('neutral');
    $table->boolean('is_active')->default(true);
    $table->timestamps();

    $table->unique(['tenant_id', 'indicator_id']);
});
```

**What stays on `indicators` table:** Everything that's factual about the indicator — slug, name, source, unit, normalization method, description, category. These are global.

**What moves to `tenant_indicator_weights`:** Weight, direction, is_active. These are opinions about how much an indicator matters — and different tenants might disagree.

**Migration path:**
1. Create `tenant_indicator_weights` table
2. Create the default tenant
3. Copy current `weight`, `direction`, `is_active` from `indicators` into `tenant_indicator_weights` for the default tenant
4. Keep the columns on `indicators` as defaults for new tenant initialization (or drop them and use the seeder values as the preset)

### 1.4 Tenant Composite Scores Table

Composite scores are also per-tenant now, since different weights produce different scores:

```php
Schema::table('composite_scores', function (Blueprint $table) {
    $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
    
    // Update the unique constraint
    $table->dropUnique(['deso_code', 'year']);
    $table->unique(['deso_code', 'year', 'tenant_id']);
});
```

**Important:** The existing global scores (tenant_id = NULL) remain as the "default" scores shown to unauthenticated visitors (the free tier). Authenticated users see their tenant's scores. This means the public map still works without auth.

---

## Step 2: Models

### 2.1 Tenant Model

```php
// app/Models/Tenant.php

class Tenant extends Model
{
    protected $fillable = ['uuid', 'name', 'slug', 'settings'];

    protected $casts = [
        'settings' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function indicatorWeights(): HasMany
    {
        return $this->hasMany(TenantIndicatorWeight::class);
    }

    public function compositeScores(): HasMany
    {
        return $this->hasMany(CompositeScore::class);
    }

    /**
     * Get the effective weight for an indicator.
     * Returns the tenant-specific weight, or null if not configured.
     */
    public function getWeightFor(Indicator $indicator): ?TenantIndicatorWeight
    {
        return $this->indicatorWeights()
            ->where('indicator_id', $indicator->id)
            ->first();
    }

    /**
     * Initialize this tenant's weights from the default preset.
     * Called on tenant creation.
     */
    public function initializeWeights(): void
    {
        $indicators = Indicator::all();

        $weights = $indicators->map(fn (Indicator $ind) => [
            'tenant_id' => $this->id,
            'indicator_id' => $ind->id,
            'weight' => $ind->default_weight ?? 0.0,
            'direction' => $ind->default_direction ?? 'neutral',
            'is_active' => $ind->default_is_active ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        TenantIndicatorWeight::insert($weights);
    }
}
```

### 2.2 User Model (Extend)

```php
// app/Models/User.php

class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'tenant_id', 'is_admin'];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_admin' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }
}
```

### 2.3 TenantIndicatorWeight Model

```php
// app/Models/TenantIndicatorWeight.php

class TenantIndicatorWeight extends Model
{
    protected $fillable = ['tenant_id', 'indicator_id', 'weight', 'direction', 'is_active'];

    protected $casts = [
        'weight' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(Indicator::class);
    }
}
```

---

## Step 3: Indicators Table — Add Default Columns

Keep the `indicators` table as the source of truth for indicator definitions, but rename the weight/direction/is_active columns to make it clear they're defaults for new tenants:

```php
Schema::table('indicators', function (Blueprint $table) {
    $table->renameColumn('weight', 'default_weight');
    $table->renameColumn('direction', 'default_direction');
    $table->renameColumn('is_active', 'default_is_active');
});
```

Or if renaming is painful (data already exists, other code references these), just add a comment in the model and keep the column names — but make the code always read from `tenant_indicator_weights` for actual scoring.

**Simpler alternative:** Don't rename. Keep `weight`, `direction`, `is_active` on `indicators` as the defaults. When creating a new tenant, copy from `indicators`. The `ScoringService` reads from `tenant_indicator_weights`. The admin UI edits `tenant_indicator_weights`. The `indicators` table columns become the "factory preset" — only used during tenant initialization.

---

## Step 4: Authentication — Sign-In Only

### 4.1 Install Laravel Breeze

```bash
composer require laravel/breeze --dev
php artisan breeze:install react --typescript --inertia
```

Breeze gives us:
- Login page
- Registration page (we'll disable this)
- Password reset (we'll disable this for now)
- Session-based auth with CSRF protection
- Inertia React pages for login

### 4.2 Disable Registration and Password Reset

Remove or guard the registration routes. In `routes/auth.php`:

```php
// Comment out or delete:
// Route::get('register', [RegisteredUserController::class, 'create']);
// Route::post('register', [RegisteredUserController::class, 'store']);

// Comment out or delete:
// Route::get('forgot-password', ...);
// Route::post('forgot-password', ...);
// Route::get('reset-password/{token}', ...);
// Route::post('reset-password', ...);

// Keep only:
Route::get('login', [AuthenticatedSessionController::class, 'create'])
    ->middleware('guest')
    ->name('login');

Route::post('login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest');

Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
```

### 4.3 Login Page

Breeze generates a login page. Customize it to match the app's design (shadcn components, Tailwind). Keep it simple:

- Email input
- Password input
- "Sign in" button
- No "Forgot password?" link (disabled)
- No "Create account" link (disabled)
- App logo at top

### 4.4 Post-Login Redirect

After login, redirect to the map page (`/`). Not to a dashboard — the map IS the product.

```php
// In LoginRequest.php or AuthenticatedSessionController
protected function redirectTo(): string
{
    return '/';
}
```

### 4.5 Integrate with i18n

The login route should respect the locale URL prefix:
- `/login` → Swedish login page
- `/en/login` → English login page

---

## Step 5: Middleware

### 5.1 ResolveTenant Middleware

```php
// app/Http/Middleware/ResolveTenant.php

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        if ($user = $request->user()) {
            $tenant = $user->tenant;

            if ($tenant) {
                // Bind the current tenant into the container
                app()->instance('currentTenant', $tenant);

                // Share with Inertia
                Inertia::share('tenant', [
                    'id' => $tenant->id,
                    'uuid' => $tenant->uuid,
                    'name' => $tenant->name,
                ]);
            }
        }

        return $next($request);
    }
}
```

Register for all web routes (after auth middleware).

### 5.2 Helper to Get Current Tenant

```php
// app/Helpers/tenant.php (or a service)

function currentTenant(): ?Tenant
{
    return app()->bound('currentTenant') ? app('currentTenant') : null;
}
```

Use throughout the app:
```php
$weights = currentTenant()?->indicatorWeights ?? collect();
```

### 5.3 AdminOnly Middleware

```php
// app/Http/Middleware/AdminOnly.php

class AdminOnly
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->isAdmin()) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            abort(403);
        }

        return $next($request);
    }
}
```

### 5.4 Route Protection

```php
// Admin routes — require auth + admin
Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::get('/indicators', [AdminIndicatorController::class, 'index']);
    Route::put('/indicators/{indicator}', [AdminIndicatorController::class, 'update']);
    Route::post('/recompute-scores', [AdminScoreController::class, 'recompute']);
    // ... ingestion dashboard, etc.
});

// Map and public API — no auth required
Route::get('/', [MapController::class, 'index']);
Route::get('/api/deso/geojson', [DesoController::class, 'geojson']);
Route::get('/api/deso/scores', [DesoController::class, 'scores']);  // Uses default/public scores

// Tenant-scoped API — require auth
Route::middleware('auth')->group(function () {
    Route::get('/api/my/scores', [TenantScoreController::class, 'scores']);  // Tenant-specific scores
    Route::get('/api/my/weights', [TenantWeightController::class, 'index']);
    Route::put('/api/my/weights/{indicator}', [TenantWeightController::class, 'update']);
});
```

---

## Step 6: Scoring Service — Tenant Awareness

### 6.1 Update ScoringService

The scoring service currently reads weights from the `indicators` table. Update it to accept an optional tenant:

```php
// app/Services/ScoringService.php

public function computeScores(int $year, ?Tenant $tenant = null): void
{
    if ($tenant) {
        // Tenant-specific weights
        $weights = TenantIndicatorWeight::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('weight', '>', 0)
            ->with('indicator')
            ->get();
    } else {
        // Default weights from indicators table (public/free tier)
        $weights = Indicator::where('is_active', true)
            ->where('weight', '>', 0)
            ->get()
            ->map(fn ($ind) => (object) [
                'indicator' => $ind,
                'weight' => $ind->weight,
                'direction' => $ind->direction,
                'is_active' => $ind->is_active,
            ]);
    }

    // ... rest of scoring logic stays the same ...
    // Just pass $tenant->id to CompositeScore::updateOrCreate
}
```

### 6.2 Compute Command — Tenant Flag

```bash
# Default scores (public)
php artisan compute:scores --year=2024

# Specific tenant
php artisan compute:scores --year=2024 --tenant=<uuid>

# All tenants
php artisan compute:scores --year=2024 --all-tenants
```

### 6.3 Score API — Tenant Resolution

The public score endpoint (`/api/deso/scores`) returns default scores (tenant_id = NULL). This is the free tier — the colored map everyone sees.

The authenticated endpoint (`/api/my/scores`) returns the current user's tenant scores. If the tenant hasn't customized weights yet (or hasn't triggered a recompute), fall back to default scores.

```php
public function scores(Request $request)
{
    $tenant = currentTenant();
    $year = $request->integer('year', now()->year - 1);

    $query = CompositeScore::where('year', $year);

    if ($tenant) {
        // Try tenant-specific scores first
        $tenantScores = (clone $query)->where('tenant_id', $tenant->id)->count();

        if ($tenantScores > 0) {
            $query->where('tenant_id', $tenant->id);
        } else {
            // Tenant hasn't computed yet — fall back to default
            $query->whereNull('tenant_id');
        }
    } else {
        $query->whereNull('tenant_id');
    }

    return response()->json(
        $query->select('deso_code', 'score', 'trend_1y')->get()->keyBy('deso_code')
    );
}
```

---

## Step 7: Admin Dashboard Updates

### 7.1 Admin Indicators Page — Now Edits Tenant Weights

The admin at `/admin/indicators` currently edits the `indicators` table directly. There are two modes now:

**For global admin:** Show the default weights (on `indicators` table) with a note: "These are the default weights for new tenants." Admin can also impersonate any tenant to see/edit their weights.

**For a tenant user (future):** Show their `tenant_indicator_weights` with a "Recompute My Scores" button.

For now (no sign-up, single tenant), the admin page edits the default tenant's `tenant_indicator_weights`. The "Recompute" button recomputes scores for that tenant.

### 7.2 Weight Bar — Per Tenant

The weight allocation bar at the top of the admin page reads from `tenant_indicator_weights` for the current tenant (or defaults if viewing as global admin).

---

## Step 8: Seeder

### 8.1 Default Tenant + Admin User

Create `database/seeders/TenantAndAdminSeeder.php`:

```php
class TenantAndAdminSeeder extends Seeder
{
    public function run(): void
    {
        // Create default tenant
        $tenant = Tenant::firstOrCreate(
            ['uuid' => 'default'],
            [
                'uuid' => Str::uuid()->toString(),
                'name' => null,  // Silent — user never sees this
                'slug' => null,
            ]
        );

        // Initialize weights from indicator defaults
        if ($tenant->indicatorWeights()->count() === 0) {
            $tenant->initializeWeights();
        }

        // Create admin user
        User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),  // Change in production
                'tenant_id' => $tenant->id,
                'is_admin' => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Default tenant: {$tenant->uuid}");
        $this->command->info("Admin user: admin@example.com / password");
    }
}
```

### 8.2 Run in DatabaseSeeder

```php
// database/seeders/DatabaseSeeder.php
public function run(): void
{
    $this->call([
        IndicatorSeeder::class,          // Indicators first (with defaults)
        TenantAndAdminSeeder::class,     // Then tenant + admin + weight copy
        // ... other seeders
    ]);
}
```

---

## Step 9: Sharing Auth State with Frontend

### 9.1 Inertia Shared Props

In `HandleInertiaRequests` middleware, share auth state:

```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'auth' => [
            'user' => $request->user() ? [
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
                'is_admin' => $request->user()->is_admin,
            ] : null,
        ],
        'locale' => App::getLocale(),
        'tenant' => $request->user()?->tenant ? [
            'id' => $request->user()->tenant->id,
            'uuid' => $request->user()->tenant->uuid,
        ] : null,
    ];
}
```

### 9.2 Frontend Auth Types

```typescript
// resources/js/types/auth.ts

interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
}

interface Tenant {
    id: number;
    uuid: string;
}

interface PageProps {
    auth: {
        user: User | null;
    };
    tenant: Tenant | null;
    locale: 'en' | 'sv';
}
```

### 9.3 UI Changes — User Menu

When authenticated, show a minimal user menu in the navbar (right side, after language switcher):

```
┌──────────────────────────────────────────────────────────────┐
│  🏠 Logotype           [Map]          SV|EN   admin@...  ▾  │
└──────────────────────────────────────────────────────────────┘
```

Dropdown menu:
- Admin Dashboard (if is_admin)
- Sign Out

When not authenticated, show nothing — or a subtle "Sign in" link if you want. The map is the public product; auth is for power users.

### 9.4 Protect Admin Link

Only show the "Admin" nav link if the user is an admin:

```tsx
{auth.user?.is_admin && (
    <Link href="/admin/indicators">{t('nav.admin')}</Link>
)}
```

---

## Step 10: Public vs Authenticated Experience

### 10.1 What Changes When Logged In

| Feature | Public (not logged in) | Authenticated |
|---|---|---|
| Map with scores | ✅ Default weights | ✅ Tenant-specific weights (if computed) |
| Click DeSO → sidebar | ✅ Score + indicators | ✅ Score + indicators (tenant weights) |
| School markers | ✅ | ✅ |
| Admin dashboard | ❌ | ✅ (admin only) |
| Custom weights | ❌ | ✅ (via admin or future tenant UI) |
| Recompute scores | ❌ | ✅ (admin or tenant) |

### 10.2 The Free Map Is Always There

Critical: the unauthenticated experience must remain fully functional. The colored map, score sidebar, school markers — all of it works without logging in. Auth adds the ability to customize weights and access admin tools. The free map IS the marketing.

---

## Step 11: Future-Proofing Notes

### 11.1 When Sign-Up Comes

Adding sign-up later means:
1. Uncomment registration routes
2. On registration: create a new Tenant (UUID, no name), assign user to it, initialize weights from defaults
3. Add email verification if needed
4. The new user gets the default scoring model, can customize from there

### 11.2 When Tenant Features Come

When we need workspace features (invites, tenant admin, billing):
1. Add `role` column to a `tenant_user` pivot table (or add Spatie permissions)
2. Add tenant name/logo/settings UI
3. Add subdomain or path-based tenant routing (`/t/acme/` or `acme.platform.se`)
4. Replace `is_admin` boolean with a proper roles system
5. The `tenant_indicator_weights` and per-tenant `composite_scores` already work — no migration needed

### 11.3 When Multiple Roles Come

Replace `is_admin` boolean with a roles approach:
- Option A: Spatie `laravel-permission` package (battle-tested, supports roles + permissions + tenants)
- Option B: Simple `roles` table + pivot (if Spatie is overkill)

Migration: `UPDATE users SET role = 'admin' WHERE is_admin = true`, then drop `is_admin`. Clean.

---

## Step 12: Verification

### 12.1 Checklist

- [ ] `tenants` table exists with UUID column
- [ ] `tenant_indicator_weights` table exists and is populated for default tenant
- [ ] Default tenant created by seeder
- [ ] Admin user created by seeder (admin@example.com)
- [ ] Login page works at `/login` (Swedish) and `/en/login` (English)
- [ ] Registration page is disabled (404 or redirect)
- [ ] Password reset is disabled
- [ ] After login, redirect to map (`/`)
- [ ] Auth state shared via Inertia (`auth.user`, `tenant`)
- [ ] Navbar shows user email + dropdown when logged in
- [ ] Admin link only visible to admin users
- [ ] `/admin/indicators` returns 403 when not admin
- [ ] `/admin/indicators` works when logged in as admin
- [ ] Admin page edits `tenant_indicator_weights`, not `indicators` table
- [ ] Recompute button uses tenant-scoped scoring
- [ ] Public map (`/`) still works without auth (default scores)
- [ ] Public score API returns default scores (tenant_id = NULL)
- [ ] Authenticated score API returns tenant-specific scores (if computed)
- [ ] Logout works, redirects to map

### 12.2 Security Checks

- [ ] CSRF protection on login form
- [ ] Password is hashed (bcrypt via Laravel)
- [ ] Session-based auth (not JWT — we're server-rendered with Inertia)
- [ ] Admin routes protected by middleware, not just hidden links
- [ ] No user enumeration on login page (same error for wrong email and wrong password)
- [ ] Rate limiting on login attempts (Laravel's built-in throttle)

---

## Notes for the Agent

### Don't Over-Scope This

This task is: migrations, models, Breeze login, middleware, seed data, admin route protection, tenant-aware scoring. That's it. No sign-up, no invites, no billing, no password reset, no email verification, no OAuth, no API tokens. Those are all future tasks.

### The Tenant Is Invisible

The user never sees the word "tenant." They don't see a UUID. They don't pick a workspace. Behind the scenes, `tenant_id` is on their user row, and their indicator weights live in `tenant_indicator_weights`. That's all. The tenant becomes visible only when we build workspace features.

### Indicator Weights Migration Is the Tricky Part

The main complexity is moving weights from `indicators` to `tenant_indicator_weights` without breaking the scoring pipeline. The migration should:
1. Create `tenant_indicator_weights`
2. Create default tenant
3. Copy weights from `indicators` to `tenant_indicator_weights`
4. Update `ScoringService` to read from `tenant_indicator_weights`
5. Update admin controller to edit `tenant_indicator_weights`
6. Keep `indicators.weight` etc. as "factory defaults" for new tenants

Do this in a single migration + seeder run. Test by running `compute:scores` before and after — the scores should be identical.

### Breeze Generates a Lot of Files

Laravel Breeze will scaffold login/register pages, controllers, requests, middleware. Delete what you don't need (registration, password reset, email verification). Keep the login flow and session management. Restyle the login page to match the app's design system.

### What NOT to Do

- Don't install Spatie permissions (overkill for one boolean role)
- Don't build a registration flow
- Don't add OAuth / social login
- Don't add API tokens (Sanctum SPA auth uses sessions, not tokens)
- Don't add tenant switching UI
- Don't add user management UI (seeded admin only for now)
- Don't remove the public/unauthenticated map experience — it must keep working