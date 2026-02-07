# TASK: Data Tiering & Paywall — Protect the Product

## Context

We're giving away the entire product for free. The sidebar shows exact percentiles, raw source values, school-level stats, methodology explanations, crime breakdowns, and debt estimates. A competitor with 5 paid unlocks and a screenshot tool can reverse-engineer the entire scoring model. This task introduces tiered data access that protects our IP while keeping the free map compelling enough to drive conversions.

**The core insight:** The colored map is marketing. The composite score is the hook. The "why" behind the score is the product. The exact numbers are the API business.

---

## The Tiering Model

### Tier 0: Public (No Account)

**What they see:**
- Full colored map (all 6,160 DeSOs) — this is the billboard
- Click a DeSO → composite score number + label ("34 — Utmanande område")
- Score color matches the map gradient
- Area name, kommun, län, area size
- A blurred/locked preview of the indicator breakdown (they can see there ARE indicators, but not the values)
- CTA: "Skapa konto för att se hela analysen" / "Sign up to see the full analysis"

**What they DON'T see:**
- Individual indicator values (percentiles, raw values, bars)
- School details
- Crime & debt breakdowns
- Strengths/weaknesses badges
- Trends
- Tooltips with methodology
- Comparison tool

**Why this works:** The map is shareable, embeddable, screenshot-friendly. Journalists, social media — let it spread. But every click lands on "sign up to see more." The score number without explanation creates curiosity tension: "Why is my area only 34? What's dragging it down?"

### Tier 1: Free Account (Signed In, No Payment)

**What they see (everything in Tier 0, plus):**
- Indicator breakdown with **band labels** — not percentiles, not raw values
- Each indicator shows: name + colored bar + band label
- Bands: "Very Low" / "Low" / "Average" / "High" / "Very High" (5 tiers)
- No raw values, no exact percentiles, no numbers on the bars
- Trend direction only: ↑ ↓ → (no magnitude — not "+3.2 points")
- Top 2 strengths and top 2 weaknesses as badges (not all of them)
- School count in the area (but no school cards, no school stats)
- "Uppgradera för fullständig analys" CTA for more detail
- Limited to viewing **20 DeSOs per day** (soft limit — tracked by session, not enforced harshly)

**Example sidebar at Tier 1:**
```
┌─────────────────────────────────────────┐
│ 2182B3010                               │
│ Söderhamn · Okänt                    34 │
│ 12.55 km²              Utmanande område │
├─────────────────────────────────────────┤
│                                         │
│ INDIKATORÖVERSIKT                       │
│                                         │
│ Medianinkomst      ██░░░░░░░░  Låg      │
│ Sysselsättning     █░░░░░░░░░  Mycket   │
│                                 låg     │
│ Utbildning (hög)   ██████████  Mycket   │
│                                 hög     │
│ Skolkvalitet       █░░░░░░░░░  Mycket   │
│                                 låg     │
│ Brottslighet       ████░░░░░░  Under    │
│                                 medel   │
│ Ekonomisk hälsa    █░░░░░░░░░  Mycket   │
│                                 låg     │
│                                         │
│ Trend: ↓ Sjunkande                      │
│                                         │
│ Styrkor: Hög utbildningsnivå,           │
│          Utsatt område (kartlagt)       │
│ Svagheter: Låg skolkvalitet,            │
│            Svag ekonomisk hälsa         │
│                                         │
│ Skolor i området: 3                     │
│                                         │
│ ┌─────────────────────────────────────┐ │
│ │ 🔒 Lås upp fullständig analys      │ │
│ │                                     │ │
│ │ Se exakta percentiler, skolresultat,│ │
│ │ brottsstatistik och trender.        │ │
│ │                                     │ │
│ │ [Lås upp detta område — 79 kr]      │ │
│ │ [Obegränsad — 349 kr/mån]          │ │
│ └─────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

**Why bands instead of percentiles:** "Low" tells you the direction but not the distance. A competitor can't reconstruct your normalization from "Low" — is that the 8th percentile or the 22nd? They'd need the actual data to know. Bands communicate value to the user without leaking precision.

### Tier 2: Single Unlock (Paid — per DeSO or per Kommun)

**What they see (everything in Tier 1, plus):**
- Full indicator breakdown with **percentile bands** (not exact) and **approximate raw values**
- Percentile bands: "Top 5%" / "Top 10%" / "Top 25%" / "Upper half" / "Lower half" / "Bottom 25%" / "Bottom 10%" / "Bottom 5%"
- Raw values **rounded**: "~285,000 SEK" not "287,432 SEK". "~70%" not "72.3%"
- Full trend with direction + magnitude label: "Stigande (+2-5 poäng)"
- All strengths and weaknesses badges
- School cards with school names, types, and **band-level** quality (not exact meritvärde)
- Crime & debt sections with band labels
- Comparison tool (between two unlocked areas)
- Tooltips with descriptions (but NOT methodology details or exact national averages)

**Pricing:**
- Single DeSO: 79 kr
- Kommun pack (all DeSOs in a kommun): 199 kr
- Region pack (all DeSOs in a län): 499 kr

**Unlock mechanics:**
- Unlock is permanent for that user (tied to their account, not time-limited)
- Kommun pack is better value — encourages bulk purchase
- Unlocked DeSOs marked on the map with a subtle badge/border so user can see what they've paid for

### Tier 3: Subscription (Monthly/Annual)

**What they see (everything in Tier 2, plus):**
- All DeSOs unlocked (no per-area purchase needed)
- **Exact percentiles** visible in the sidebar: "13:e pctl" not "Bottom 25%"
- **Exact raw values**: "287,432 SEK" not "~285,000 SEK"
- Full methodology tooltips with national averages and source details
- Historical trend charts (not just arrows — actual year-over-year graphs)
- School cards with exact meritvärde, goal achievement %, teacher cert %
- PDF report generation per DeSO
- Comparison tool for any two areas (no unlock requirement)
- Email alerts when a watched area's score changes significantly
- Priority data freshness (subscriber scores recomputed first when new data arrives)

**Pricing:**
- Monthly: 349 kr/month
- Annual: 2,990 kr/year (save ~30%)

### Tier 4: API / Enterprise (Contract)

**What they get (everything in Tier 3, plus):**
- REST API access with JSON responses
- Exact percentiles as decimal values (0.1342 not "13th")
- Raw unrounded values from all sources
- Bulk export (CSV/JSON) for all DeSOs
- Webhook notifications on data updates
- Custom weight profiles (multiple scoring models per account)
- Historical data dumps (all years, all indicators)
- SLA + dedicated support
- White-label option (their branding on reports)

**Pricing:**
- Starts at 5,000 kr/month
- Custom based on usage

---

## Step 1: Data Access Layer

### 1.1 The Tier Resolver

Every API response and Inertia page prop that contains indicator data must pass through a tier resolver that strips/transforms data based on the user's access level.

```php
// app/Services/DataTieringService.php

class DataTieringService
{
    public function resolveUserTier(?User $user, ?string $desoCode = null): DataTier
    {
        if (!$user) {
            return DataTier::PUBLIC;
        }

        if ($user->hasActiveSubscription()) {
            return DataTier::SUBSCRIBER;
        }

        if ($user->hasApiAccess()) {
            return DataTier::ENTERPRISE;
        }

        if ($desoCode && $user->hasUnlocked($desoCode)) {
            return DataTier::UNLOCKED;
        }

        return DataTier::FREE_ACCOUNT;
    }

    /**
     * Transform indicator data based on tier.
     */
    public function transformIndicators(
        Collection $indicators,
        DataTier $tier,
    ): Collection {
        return $indicators->map(fn ($ind) => $this->transformIndicator($ind, $tier));
    }

    private function transformIndicator(array $indicator, DataTier $tier): array
    {
        return match ($tier) {
            DataTier::PUBLIC => $this->forPublic($indicator),
            DataTier::FREE_ACCOUNT => $this->forFreeAccount($indicator),
            DataTier::UNLOCKED => $this->forUnlocked($indicator),
            DataTier::SUBSCRIBER => $this->forSubscriber($indicator),
            DataTier::ENTERPRISE => $indicator,  // Full data, no transformation
        };
    }

    private function forPublic(array $indicator): array
    {
        // Score only — no indicator data
        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'],
            'locked' => true,
        ];
    }

    private function forFreeAccount(array $indicator): array
    {
        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'],
            'band' => $this->percentileToBand($indicator['percentile']),
            'bar_width' => $this->percentileToBarWidth($indicator['percentile']),
            'direction' => $indicator['direction'],
            'trend_direction' => $this->trendToDirection($indicator['trend'] ?? null),
            'locked' => false,
            // NO: raw_value, percentile, trend_magnitude
        ];
    }

    private function forUnlocked(array $indicator): array
    {
        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'],
            'percentile_band' => $this->percentileToWideBand($indicator['percentile']),
            'bar_width' => $this->percentileToBarWidth($indicator['percentile']),
            'direction' => $indicator['direction'],
            'raw_value_approx' => $this->roundRawValue($indicator['raw_value'], $indicator['unit']),
            'trend_direction' => $this->trendToDirection($indicator['trend'] ?? null),
            'trend_band' => $this->trendToBand($indicator['trend'] ?? null),
            'locked' => false,
            // NO: exact percentile, exact raw_value, methodology
        ];
    }

    private function forSubscriber(array $indicator): array
    {
        return [
            'slug' => $indicator['slug'],
            'name' => $indicator['name'],
            'category' => $indicator['category'],
            'percentile' => $indicator['percentile'],           // Exact
            'raw_value' => $indicator['raw_value'],             // Exact
            'unit' => $indicator['unit'],
            'direction' => $indicator['direction'],
            'trend' => $indicator['trend'],                     // Exact magnitude
            'bar_width' => $indicator['percentile'] / 100,
            'description_short' => $indicator['description_short'],
            'description_long' => $indicator['description_long'],
            'methodology_note' => $indicator['methodology_note'],
            'national_context' => $indicator['national_context'],
            'source_name' => $indicator['source_name'],
            'data_vintage' => $indicator['data_vintage'],
            'locked' => false,
        ];
    }
}
```

### 1.2 Band Definitions

```php
// 5-band system for free accounts
private function percentileToBand(?float $percentile): ?string
{
    if ($percentile === null) return null;

    return match (true) {
        $percentile >= 80 => 'very_high',
        $percentile >= 60 => 'high',
        $percentile >= 40 => 'average',
        $percentile >= 20 => 'low',
        default => 'very_low',
    };
}

// 8-band system for unlocked areas
private function percentileToWideBand(?float $percentile): ?string
{
    if ($percentile === null) return null;

    return match (true) {
        $percentile >= 95 => 'top_5',
        $percentile >= 90 => 'top_10',
        $percentile >= 75 => 'top_25',
        $percentile >= 50 => 'upper_half',
        $percentile >= 25 => 'lower_half',
        $percentile >= 10 => 'bottom_25',
        $percentile >= 5  => 'bottom_10',
        default => 'bottom_5',
    };
}

// Bar width is always available (it's visual, not precise)
// But we quantize it slightly to prevent reverse-engineering exact percentiles
private function percentileToBarWidth(?float $percentile): float
{
    if ($percentile === null) return 0;

    // Round to nearest 5% for free/unlocked tiers
    return round($percentile / 5) * 5 / 100;
}
```

### 1.3 Raw Value Rounding

```php
private function roundRawValue(?float $value, ?string $unit): ?string
{
    if ($value === null) return null;

    return match ($unit) {
        'SEK' => '~' . number_format(round($value / 5000) * 5000, 0, '.', ',') . ' kr',
        'percent' => '~' . round($value, 0) . '%',
        'per_1000', 'per_100k' => '~' . round($value, 0),
        'points' => '~' . round($value / 5) * 5,
        default => '~' . round($value, 0),
    };
}
```

Income of 287,432 SEK becomes "~285,000 kr". Merit value of 241.3 becomes "~240". Crime rate of 2,845.6/100k becomes "~2,846". Close enough to be useful, imprecise enough to not be copyable.

---

## Step 2: Database — Unlocks & Subscriptions

### 2.1 User Unlocks Table

```php
Schema::create('user_unlocks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('unlock_type', 20);       // 'deso', 'kommun', 'lan'
    $table->string('unlock_code', 10);       // DeSO code, kommun code, or län code
    $table->string('payment_reference')->nullable();  // Swish/Stripe ref
    $table->integer('price_paid')->default(0);        // öre (79 kr = 7900)
    $table->timestamps();

    $table->unique(['user_id', 'unlock_type', 'unlock_code']);
    $table->index(['user_id', 'unlock_code']);
});
```

### 2.2 Subscriptions Table

```php
Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('plan', 20);               // 'monthly', 'annual'
    $table->string('status', 20);             // 'active', 'cancelled', 'expired', 'past_due'
    $table->integer('price')->default(0);     // öre
    $table->string('payment_provider', 20)->nullable();  // 'swish', 'stripe', 'klarna'
    $table->string('external_id')->nullable();            // Provider's subscription ID
    $table->timestamp('current_period_start');
    $table->timestamp('current_period_end');
    $table->timestamp('cancelled_at')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'status']);
});
```

### 2.3 User Model Extensions

```php
// app/Models/User.php

public function unlocks(): HasMany
{
    return $this->hasMany(UserUnlock::class);
}

public function subscription(): HasOne
{
    return $this->hasOne(Subscription::class)->where('status', 'active');
}

public function hasActiveSubscription(): bool
{
    return $this->subscription()
        ->where('current_period_end', '>', now())
        ->exists();
}

public function hasUnlocked(string $desoCode): bool
{
    // Check direct DeSO unlock
    if ($this->unlocks()->where('unlock_type', 'deso')->where('unlock_code', $desoCode)->exists()) {
        return true;
    }

    // Check kommun unlock (DeSO code starts with 4-digit kommun code)
    $kommunCode = substr($desoCode, 0, 4);
    if ($this->unlocks()->where('unlock_type', 'kommun')->where('unlock_code', $kommunCode)->exists()) {
        return true;
    }

    // Check län unlock (first 2 digits)
    $lanCode = substr($desoCode, 0, 2);
    if ($this->unlocks()->where('unlock_type', 'lan')->where('unlock_code', $lanCode)->exists()) {
        return true;
    }

    return false;
}

public function hasApiAccess(): bool
{
    // For now, only admin. Future: API tier check
    return $this->is_admin;
}
```

---

## Step 3: API Response Transformation

### 3.1 Score Detail Endpoint

The existing endpoint that returns DeSO data for the sidebar must now filter based on tier:

```php
// app/Http/Controllers/DesoController.php

public function show(string $desoCode, Request $request)
{
    $user = $request->user();
    $tiering = app(DataTieringService::class);
    $tier = $tiering->resolveUserTier($user, $desoCode);

    $score = CompositeScore::where('deso_code', $desoCode)->first();
    $deso = DesoArea::where('deso_code', $desoCode)->first();

    // Composite score is ALWAYS visible (even public)
    $response = [
        'deso_code' => $desoCode,
        'deso_name' => $deso->deso_name,
        'kommun_name' => $deso->kommun_name,
        'lan_name' => $deso->lan_name,
        'area_km2' => $deso->area_km2,
        'score' => $score?->score,
        'score_label' => $this->scoreLabel($score?->score),
        'tier' => $tier->value,
    ];

    // Indicators — transformed by tier
    if ($tier !== DataTier::PUBLIC) {
        $indicators = $this->getIndicatorData($desoCode);
        $response['indicators'] = $tiering->transformIndicators($indicators, $tier);
    } else {
        // Public: send indicator slugs/names only (for the blurred preview)
        $response['indicators'] = Indicator::where('is_active', true)
            ->select('slug', 'name', 'category')
            ->orderBy('display_order')
            ->get()
            ->map(fn ($ind) => ['slug' => $ind->slug, 'name' => $ind->name, 'category' => $ind->category, 'locked' => true]);
    }

    // Trend — tier dependent
    if ($tier->value >= DataTier::FREE_ACCOUNT->value) {
        $response['trend_direction'] = $this->trendDirection($score?->trend_1y);
    }
    if ($tier->value >= DataTier::SUBSCRIBER->value) {
        $response['trend_1y'] = $score?->trend_1y;
        $response['trend_3y'] = $score?->trend_3y;
    }

    // Schools — tier dependent
    if ($tier->value >= DataTier::FREE_ACCOUNT->value) {
        $response['school_count'] = School::where('deso_code', $desoCode)->where('status', 'active')->count();
    }
    if ($tier->value >= DataTier::UNLOCKED->value) {
        $response['schools'] = $this->getSchoolData($desoCode, $tier);
    }

    // Strengths/weaknesses — tier dependent
    if ($tier->value >= DataTier::FREE_ACCOUNT->value) {
        $strengths = $score?->top_positive ?? [];
        $weaknesses = $score?->top_negative ?? [];
        $response['strengths'] = array_slice($strengths, 0, $tier === DataTier::FREE_ACCOUNT ? 2 : count($strengths));
        $response['weaknesses'] = array_slice($weaknesses, 0, $tier === DataTier::FREE_ACCOUNT ? 2 : count($weaknesses));
    }

    // Unlock info — for purchase CTAs
    if ($tier === DataTier::FREE_ACCOUNT) {
        $kommunCode = substr($desoCode, 0, 4);
        $response['unlock_options'] = [
            'deso' => ['code' => $desoCode, 'price' => 7900],
            'kommun' => ['code' => $kommunCode, 'name' => $deso->kommun_name, 'price' => 19900],
        ];
    }

    return response()->json($response);
}
```

### 3.2 DataTier Enum

```php
// app/Enums/DataTier.php

enum DataTier: int
{
    case PUBLIC = 0;
    case FREE_ACCOUNT = 1;
    case UNLOCKED = 2;
    case SUBSCRIBER = 3;
    case ENTERPRISE = 4;
    case ADMIN = 99;
}
```

Integer values enable `>=` comparisons for tier checks. Admin is 99 — it always passes any `>=` check.

### 3.3 Admin Tier — The Learning Dashboard

**Admin sees EVERYTHING, plus extra.** The admin tier is not just "subscriber + access to /admin." It's a richer experience designed to help the product owner continuously learn about their own data. When you (the admin) click a DeSO, you should walk away understanding that area better than someone who's lived there for 10 years.

**What admin sees beyond subscriber tier:**

| Content | Subscriber | Admin |
|---|---|---|
| Exact percentiles + raw values | ✅ | ✅ |
| Tooltips: description + methodology | ✅ | ✅ |
| Tooltips: national average | ✅ | ✅ |
| Tooltips: **source API table ID** | ❌ | ✅ |
| Tooltips: **raw API response field path** | ❌ | ✅ |
| Tooltips: **data quality notes** | ❌ | ✅ |
| Tooltips: **normalization details** | ❌ | ✅ |
| Tooltips: **weight in current model** | ❌ | ✅ |
| Tooltips: **DeSO count with data** | ❌ | ✅ |
| Indicator: **rank among all DeSOs** | ❌ | ✅ |
| Indicator: **exact normalized value** | ❌ | ✅ |
| School: **school_unit_code** | ❌ | ✅ |
| Score: **factor_scores JSON breakdown** | ❌ | ✅ |
| Score: **weighted contribution per indicator** | ❌ | ✅ |
| Debug: **DeSO H3 cell count** | ❌ | ✅ |
| Debug: **data vintage per indicator** | ❌ | ✅ |
| Debug: **ingestion timestamp per source** | ❌ | ✅ |

**Admin tooltip example for "Median Income":**

```
┌─────────────────────────────────────────────┐
│ Median Disposable Income                    │
│                                             │
│ The median annual disposable income (after  │
│ taxes and transfers) for individuals aged   │
│ 20+ living in this area.                    │
│                                             │
│ ┌─ Methodology ─────────────────────────┐   │
│ │ Disposable income = earned income +   │   │
│ │ capital income + transfers − taxes.   │   │
│ │ Median = middle value when ranked.    │   │
│ └───────────────────────────────────────┘   │
│                                             │
│ National median: ~248,000 SEK (2024)        │
│                                             │
│ This area: 287,432 SEK                      │
│ Percentile: 78.3 (rank 1,342 of 6,148)     │
│ Normalized value: 0.7834                    │
│                                             │
│ ┌─ Model contribution ──────────────────┐   │
│ │ Weight: 0.15 (15% of composite)       │   │
│ │ Direction: positive (higher = better) │   │
│ │ Directed value: 0.7834               │   │
│ │ Weighted contribution: 11.75 pts     │   │
│ │ (of this area's 72.4 total score)    │   │
│ └───────────────────────────────────────┘   │
│                                             │
│ ┌─ Data pipeline ───────────────────────┐   │
│ │ Source: SCB (api.scb.se)              │   │
│ │ Table: HE0110/HE0110A/TabVX1DeSO     │   │
│ │ Field: HE0110K3                       │   │
│ │ Data describes: 2024                  │   │
│ │ Ingested: 2025-01-15 03:22:41 UTC    │   │
│ │ Normalization: rank_percentile        │   │
│ │ Coverage: 6,148 / 6,160 DeSOs (99.8%)│   │
│ └───────────────────────────────────────┘   │
│                                             │
│ Source: Statistics Sweden (SCB)              │
│ Published annually, Q1 for previous year    │
└─────────────────────────────────────────────┘
```

This tooltip teaches you: what the indicator is, how it's computed, how this specific area ranks, exactly how much it contributes to the composite score, where the data came from, which API table, when it was last pulled, and how many DeSOs have data. After clicking 10 DeSOs with these tooltips, you understand your own scoring model inside out.

### 3.4 Admin Score Header — Full Breakdown

When admin clicks a DeSO, the composite score section shows extra detail:

```
72.4  (rank 1,892 / 6,160)

Factor contributions:
  Median Income:       0.15 × 0.78 = 11.75 pts
  Employment:          0.10 × 0.61 =  6.10 pts
  Education (high):    0.10 × 0.99 =  9.90 pts
  Education (low):     0.05 × 0.98 =  4.90 pts  (inverted)
  School Quality:      0.12 × 0.06 =  0.72 pts
  School Goals:        0.08 × 0.08 =  0.64 pts
  Teacher Cert:        0.05 × 0.02 =  0.10 pts
  Crime (violent):     0.10 × 0.34 =  3.40 pts  (inverted)
  ...
  ─────────────────────────────
  Sum: 0.724 × 100 = 72.4

  Data completeness: 14/16 indicators have data
  Missing: transit_accessibility, poi_composite
```

This is the "show your work" view. When the score seems wrong ("why is this nice area only 72?"), the breakdown tells you exactly which indicator is dragging it down.

### 3.5 Admin-Specific Indicator Metadata

Extend the `indicators` table (or a related table) with admin-only metadata fields:

```php
Schema::table('indicators', function (Blueprint $table) {
    $table->string('source_api_path')->nullable();      // "HE/HE0110/HE0110A/TabVX1DeSO"
    $table->string('source_field_code')->nullable();     // "HE0110K3"
    $table->text('data_quality_notes')->nullable();      // "12 DeSOs missing due to SCB suppression rules"
    $table->text('admin_notes')->nullable();             // Free-form notes: quirks, gotchas, edge cases
});
```

These fields are:
- Populated by the ingestion commands (automatically where possible)
- Editable in the admin dashboard
- Only served to admin-tier users
- A living knowledge base about each data source

### 3.6 Admin Notes — Continuously Updated

The `admin_notes` field is the key to "continuously learn about your own app." Every time you (or an agent) discovers something about an indicator — a quirk, an edge case, a seasonal pattern, a data quality issue — it goes in `admin_notes`.

Examples:
- `"SCB suppresses income data for DeSOs with <50 residents. 12 DeSOs affected in 2024."`
- `"Meritvärde dropped nationally in 2023 due to COVID catch-up grading. Don't compare 2023 to 2022 naively."`
- `"Kronofogden debt rate is estimated via regression, not directly measured. R² = 0.82. Residuals are highest in university towns (students skew the model)."`
- `"Employment rate includes self-employed. In farming DeSOs this inflates the number significantly."`

The admin tooltip always shows `admin_notes` if populated — it's the first thing you see after the description, highlighted in a distinct color (amber background) so it stands out as "important context."

### 3.7 Implementation in DataTieringService

```php
private function forAdmin(array $indicator): array
{
    return [
        // Everything a subscriber sees
        ...$this->forSubscriber($indicator),

        // Plus admin extras
        'locked' => false,
        'percentile_exact' => $indicator['percentile'],
        'normalized_value' => $indicator['normalized_value'],
        'rank' => $indicator['rank'],
        'rank_total' => $indicator['rank_total'],
        'weight' => $indicator['weight'],
        'direction' => $indicator['direction'],
        'weighted_contribution' => $indicator['weighted_contribution'],
        'source_api_path' => $indicator['source_api_path'],
        'source_field_code' => $indicator['source_field_code'],
        'data_quality_notes' => $indicator['data_quality_notes'],
        'admin_notes' => $indicator['admin_notes'],
        'normalization_method' => $indicator['normalization_method'],
        'coverage_count' => $indicator['coverage_count'],
        'coverage_total' => $indicator['coverage_total'],
        'data_last_ingested_at' => $indicator['data_last_ingested_at'],
    ];
}
```

### 3.8 Update resolveUserTier

```php
public function resolveUserTier(?User $user, ?string $desoCode = null): DataTier
{
    if (!$user) {
        return DataTier::PUBLIC;
    }

    // Admin always gets admin tier — regardless of subscription status
    if ($user->isAdmin()) {
        return DataTier::ADMIN;
    }

    if ($user->hasApiAccess()) {
        return DataTier::ENTERPRISE;
    }

    if ($user->hasActiveSubscription()) {
        return DataTier::SUBSCRIBER;
    }

    if ($desoCode && $user->hasUnlocked($desoCode)) {
        return DataTier::UNLOCKED;
    }

    return DataTier::FREE_ACCOUNT;
}
```

---

## Step 4: Frontend — Tiered Sidebar

### 4.1 Locked Indicator Preview (Tier 0 — Public)

Show indicator names with blurred/skeleton bars:

```tsx
function LockedIndicator({ name, category }: { name: string; category: string }) {
  return (
    <div className="flex items-center justify-between opacity-50">
      <span className="text-sm">{name}</span>
      <div className="w-24 h-2 bg-muted rounded animate-pulse" />
    </div>
  );
}
```

Below the locked list, show the CTA card:

```tsx
<Card className="bg-muted/50 border-dashed">
  <CardContent className="text-center py-6">
    <Lock className="h-6 w-6 mx-auto mb-2 text-muted-foreground" />
    <p className="font-medium">{t('paywall.unlock_title')}</p>
    <p className="text-sm text-muted-foreground mt-1">{t('paywall.unlock_description')}</p>
    <Button className="mt-4" onClick={onSignUp}>
      {t('paywall.create_account')}
    </Button>
  </CardContent>
</Card>
```

### 4.2 Band Labels (Tier 1 — Free Account)

```tsx
function BandIndicator({ name, band, barWidth }: BandIndicatorProps) {
  const bandLabels: Record<string, string> = {
    very_high: t('band.very_high'),   // "Mycket hög"
    high: t('band.high'),             // "Hög"
    average: t('band.average'),       // "Medel"
    low: t('band.low'),               // "Låg"
    very_low: t('band.very_low'),     // "Mycket låg"
  };

  const bandColors: Record<string, string> = {
    very_high: 'bg-green-500',
    high: 'bg-green-400',
    average: 'bg-yellow-400',
    low: 'bg-red-400',
    very_low: 'bg-red-500',
  };

  return (
    <div className="space-y-1">
      <div className="flex justify-between text-sm">
        <span>{name}</span>
        <span className="text-muted-foreground">{bandLabels[band]}</span>
      </div>
      <div className="h-2 bg-muted rounded-full overflow-hidden">
        <div
          className={cn("h-full rounded-full", bandColors[band])}
          style={{ width: `${barWidth * 100}%` }}
        />
      </div>
    </div>
  );
}
```

### 4.3 Unlocked Indicator (Tier 2)

```tsx
function UnlockedIndicator({ name, percentileBand, rawValueApprox, trendBand }: UnlockedProps) {
  return (
    <div className="space-y-1">
      <div className="flex justify-between text-sm">
        <span>{name}</span>
        <span className="text-muted-foreground">
          {percentileBandLabel(percentileBand)}
          {rawValueApprox && <span className="ml-1">({rawValueApprox})</span>}
        </span>
      </div>
      <div className="h-2 bg-muted rounded-full overflow-hidden">
        <div className={cn("h-full rounded-full", bandColor(percentileBand))} style={...} />
      </div>
    </div>
  );
}
```

### 4.4 Subscriber Indicator (Tier 3)

This is what's currently showing — exact percentiles, raw values, tooltips. No change to existing components, they just only render for subscribers.

### 4.5 Upgrade CTA Placement

For free account users, show a persistent but non-aggressive upgrade prompt at the bottom of the indicator list:

```tsx
<div className="mt-4 p-3 rounded-lg bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100">
  <p className="text-sm font-medium">{t('paywall.see_more')}</p>
  <p className="text-xs text-muted-foreground mt-0.5">{t('paywall.see_more_description')}</p>
  <div className="flex gap-2 mt-2">
    <Button size="sm" variant="default" onClick={() => unlockArea(desoCode)}>
      {t('paywall.unlock_area', { price: '79 kr' })}
    </Button>
    <Button size="sm" variant="outline" onClick={() => showSubscriptionModal()}>
      {t('paywall.subscribe')}
    </Button>
  </div>
</div>
```

---

## Step 5: School Data Tiering

### 5.1 School Visibility by Tier

| Data | Public | Free | Unlocked | Subscriber | Enterprise |
|---|---|---|---|---|---|
| School count in area | ❌ | ✅ | ✅ | ✅ | ✅ |
| School names | ❌ | ❌ | ✅ | ✅ | ✅ |
| School type (grundskola/etc) | ❌ | ❌ | ✅ | ✅ | ✅ |
| Operator (kommunal/fristående) | ❌ | ❌ | ✅ | ✅ | ✅ |
| Quality band (Hög/Låg) | ❌ | ❌ | ✅ | ❌ (has exact) | ❌ |
| Exact meritvärde | ❌ | ❌ | ❌ | ✅ | ✅ |
| Goal achievement % | ❌ | ❌ | ❌ | ✅ | ✅ |
| Teacher certification % | ❌ | ❌ | ❌ | ✅ | ✅ |
| Student count | ❌ | ❌ | ❌ | ✅ | ✅ |
| School markers on map | ❌ | ❌ | ✅ | ✅ | ✅ |
| school_unit_code | ❌ | ❌ | ❌ | ❌ | ✅ |
| DeSO assignment method | ❌ | ❌ | ❌ | ❌ | ✅ |

### 5.2 School Marker Visibility

School markers on the map appear **only for unlocked/subscriber tiers**. For free accounts, clicking a DeSO shows "3 schools in this area" but no markers and no details. This is a strong conversion driver — parents WANT to see which schools are in the area.

---

## Step 6: Tooltip/Methodology Tiering

### 6.1 What Shows Where

| Content | Free | Unlocked | Subscriber | Admin |
|---|---|---|---|---|
| Indicator name | ✅ | ✅ | ✅ | ✅ |
| Short description (1 line) | ✅ | ✅ | ✅ | ✅ |
| Long description | ❌ | ✅ | ✅ | ✅ |
| Admin notes (quirks, gotchas) | ❌ | ❌ | ❌ | ✅ (amber highlight) |
| Methodology note | ❌ | ❌ | ✅ | ✅ |
| National average | ❌ | ❌ | ✅ | ✅ |
| Source name | ❌ | ✅ | ✅ | ✅ |
| Source URL | ❌ | ❌ | ✅ | ✅ |
| Source API path + field code | ❌ | ❌ | ❌ | ✅ |
| Data vintage year | ❌ | ✅ | ✅ | ✅ |
| Last ingestion timestamp | ❌ | ❌ | ✅ | ✅ (exact datetime) |
| Normalization method | ❌ | ❌ | ❌ | ✅ |
| Weight + direction in model | ❌ | ❌ | ❌ | ✅ |
| Weighted contribution to score | ❌ | ❌ | ❌ | ✅ |
| Rank (N of total) | ❌ | ❌ | ❌ | ✅ |
| Normalized value (0-1) | ❌ | ❌ | ❌ | ✅ |
| Coverage (DeSOs with data) | ❌ | ❌ | ❌ | ✅ |
| Data quality notes | ❌ | ❌ | ❌ | ✅ |

The admin column is the "teach me about my own product" layer. Every tooltip is a mini-lesson. Over time, as ingestion commands discover edge cases and the team adds `admin_notes`, the tooltips become a living encyclopedia of the data pipeline.

The methodology and national averages are the most sensitive for competitors — they reveal exactly how we score and what "good" looks like. Reserve for subscribers and above.

---

## Step 7: Comparison Tool Tiering

### 7.1 Access Rules

| Feature | Public | Free | Unlocked | Subscriber |
|---|---|---|---|---|
| Enter compare mode | ❌ | ✅ | ✅ | ✅ |
| Place pins | ❌ | ✅ | ✅ | ✅ |
| See score comparison | ❌ | ✅ (scores only) | ✅ (if both unlocked) | ✅ |
| See indicator comparison | ❌ | bands only | wide bands | exact |
| Verdict section | ❌ | ❌ | ✅ | ✅ |
| Share comparison URL | ❌ | ❌ | ✅ | ✅ |
| PDF comparison report | ❌ | ❌ | ❌ | ✅ |

Free account comparison: "Area A: 72 (Stable) vs Area B: 34 (Challenging)" — scores only, with band-level bars. No raw values, no verdict. Just enough to be useful, not enough to be the product.

---

## Step 8: Anti-Scraping Measures

### 8.1 Rate Limiting

```php
// For authenticated users
RateLimiter::for('deso-detail', function (Request $request) {
    $user = $request->user();

    if (!$user) {
        return Limit::perHour(10)->by($request->ip());    // Public: 10/hour
    }

    if ($user->hasActiveSubscription()) {
        return Limit::perHour(500)->by($user->id);        // Subscriber: 500/hour
    }

    return Limit::perHour(50)->by($user->id);             // Free: 50/hour
});
```

Apply to the DeSO detail endpoint. The GeoJSON endpoint (map polygons) and score list endpoint (just numbers) can be more permissive.

### 8.2 Don't Return Exact Percentiles in Bulk

The `/api/deso/scores` endpoint returns scores for all 6,160 DeSOs at once (needed to color the map). This is fine — composite scores are a single number, not granular indicators.

**Never** create a bulk endpoint that returns per-indicator data for all DeSOs. That's the entire dataset in one call. Even for enterprise clients, paginate and rate-limit.

### 8.3 Bar Width Quantization

The bar widths (visual width of the colored bar) are quantized to 5% increments for free/unlocked tiers. This prevents inferring exact percentiles from the visual representation. A bar that's 60% wide could be the 58th or 62nd percentile — close enough to be useful, imprecise enough to not be reverse-engineerable.

For subscribers: exact bar widths matching exact percentiles.

---

## Step 9: Payment Integration (Placeholder)

### 9.1 Don't Build Payments in This Task

This task defines the tiering logic and UI. Actual payment processing (Swish, Stripe, Klarna) is a separate task. For now:

- The "Unlock" and "Subscribe" buttons show a "Coming soon" modal
- Admin can manually grant unlocks via a seeder or tinker: `$user->unlocks()->create([...])`
- Admin can manually activate subscriptions: `$user->subscription()->create([...])`
- All tier logic works — it just can't be self-service purchased yet

### 9.2 Simulating Tiers for Testing

Add artisan commands for testing:

```bash
# Grant a free account user a DeSO unlock
php artisan user:unlock admin@example.com --deso=0180C1020

# Grant a kommun unlock
php artisan user:unlock admin@example.com --kommun=0180

# Activate a subscription
php artisan user:subscribe admin@example.com --plan=monthly

# Check a user's tier for a specific DeSO
php artisan user:tier admin@example.com --deso=0180C1020
```

---

## Step 10: Verification

### 10.1 Tier Behavior Checklist

**Public (logged out):**
- [ ] Map shows colored DeSOs with scores
- [ ] Clicking DeSO shows score + label in sidebar
- [ ] Indicator names visible but values locked/blurred
- [ ] CTA to create account visible
- [ ] No school details, no trends, no comparison
- [ ] Can't access /admin routes

**Free account (logged in, no purchases):**
- [ ] Indicator bands visible (Very Low / Low / Average / High / Very High)
- [ ] Bars show visual width (quantized to 5%)
- [ ] NO exact percentiles, NO raw values
- [ ] Trend direction (↑↓→) visible, no magnitude
- [ ] Top 2 strengths + 2 weaknesses shown
- [ ] School count visible, no school cards
- [ ] Compare mode: scores + bands only
- [ ] "Unlock area" CTA visible

**Unlocked (purchased single DeSO/kommun):**
- [ ] Wide percentile bands visible (Top 10%, Bottom 25%, etc.)
- [ ] Approximate raw values visible (~285,000 kr)
- [ ] All strengths/weaknesses shown
- [ ] School cards with names, types, quality bands
- [ ] School markers on map for this DeSO
- [ ] Tooltips with descriptions (not methodology)
- [ ] Non-unlocked DeSOs still show free-tier data

**Subscriber:**
- [ ] Exact percentiles visible (13th pctl)
- [ ] Exact raw values (287,432 SEK)
- [ ] Full tooltips with methodology + national averages
- [ ] School cards with exact stats
- [ ] Full comparison with verdict
- [ ] All DeSOs fully unlocked
- [ ] PDF export button visible

**Admin:**
- [ ] Everything subscriber sees, plus:
- [ ] Tooltips show source API path and field code
- [ ] Tooltips show weight + direction + weighted contribution to composite score
- [ ] Tooltips show rank (e.g., "rank 1,342 of 6,148")
- [ ] Tooltips show normalized value (0.7834)
- [ ] Tooltips show coverage (6,148 / 6,160 DeSOs)
- [ ] Tooltips show normalization method (rank_percentile)
- [ ] Tooltips show admin_notes in amber highlight (if populated)
- [ ] Tooltips show data quality notes (if populated)
- [ ] Tooltips show exact ingestion timestamp
- [ ] Score header shows factor contribution breakdown (weight × value = points per indicator)
- [ ] Score header shows data completeness (14/16 indicators have data)
- [ ] School cards show school_unit_code
- [ ] Admin notes field editable from admin dashboard

### 10.2 Anti-Leak Checks

- [ ] Free tier bars are quantized (can't infer exact percentile from visual width)
- [ ] Raw values are rounded at unlock tier (not exact)
- [ ] Methodology/national averages hidden below subscriber tier
- [ ] No bulk indicator export endpoint exists
- [ ] Rate limits work (10/hour public, 50/hour free, 500/hour subscriber)

---

## Notes for the Agent

### The Transformation Happens at the API Layer

Do NOT implement tiering in the frontend only (hiding data with CSS). The API response itself must be stripped. If the JSON doesn't contain exact percentiles, the user can't find them in browser DevTools. The `DataTieringService` runs server-side before the response is sent.

### Bar Width Is a Visual Hint, Not Data

Even at the free tier, showing a colored bar with approximate width is fine — it communicates "this indicator is strong/weak" without leaking the exact number. The quantization to 5% increments adds enough noise. Don't remove bars entirely for free users — the visual pattern IS the product experience.

### The "Free Account vs Public" Gap Matters

The jump from "logged out" to "free account" must feel meaningful. The user creates an account and immediately sees band labels where there used to be blur. That's the dopamine hit that keeps them engaged and moving toward payment. If free accounts see everything, there's no reason to pay.

### Kommun Packs Are the Conversion Lever

Most homebuyers compare 3-5 DeSOs within the same kommun. A single DeSO unlock at 79 kr feels expensive for "just one area." A kommun pack at 199 kr (which covers 40-80 DeSOs) feels like a bargain. Price the kommun pack to be the obvious choice.

### What NOT to Do

- Don't implement payment processing in this task — just the tiering logic
- Don't show "upgrade" popups on every click — one persistent CTA at the bottom is enough
- Don't lock the composite score — it's visible at all tiers (it's on the map anyway)
- Don't lock the map colors — they're the marketing
- Don't build a credit/token system — too complex. Per-area unlock or subscription, that's it
- Don't send raw data to the frontend and hide it with CSS — strip at the API level
- Don't forget that admin users bypass all tier restrictions (they see everything, plus debug info)
- Don't treat admin tooltips as a one-time setup — they're a living knowledge base. Every ingestion command, every data quirk discovery, every edge case should update `admin_notes` or `data_quality_notes`. The agent should add notes during every data pipeline task.

### Admin Tooltips Are a Product Requirement, Not a Debug Feature

The admin tooltip system is explicitly designed to help the product owner learn their own product. When a new data source is added, the ingestion command should populate `source_api_path`, `source_field_code`, and `data_quality_notes` automatically. When an agent discovers an edge case ("SCB suppresses income for DeSOs with <50 people"), it goes in `admin_notes`. Over time, clicking through the map as admin becomes a masterclass in Swedish neighborhood statistics. This is not optional polish — it's how the product owner stays ahead of their own product's complexity.