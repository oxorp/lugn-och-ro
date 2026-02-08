# TASK: Personalized Report Generation + Persistence

## What This Is

The user drops a pin on the map. They've seen the score, the heatmap, the headline in the sidebar. Now they want the full picture. They click "Generate Report" — and before we compute anything, we ask: **what matters most to you?**

A family with kids cares about school quality. A 25-year-old cares about transit and nightlife. An investor cares about price trajectory and income trends. The report reflects their priorities by slightly adjusting the weighting — same data, personalized lens.

This is the monetization moment. The free tier shows the map colors and headline score. The report is the product they pay for. Once paid (or if they have a subscription), the report is theirs forever — persisted in the database, accessible via a unique URL, viewable on any device, months or years later.

---

## The User Flow

```
1. User drops pin on map (or searches address)
   → Sidebar shows: location name, headline score, trend arrow
   → Button: "Full Report — 79 kr" (or "Generate Report" if subscribed)

2. User clicks the button
   → If not paid/subscribed: payment flow (out of scope for this task — stub it)
   → If paid/subscribed: preference questionnaire appears

3. Preference questionnaire (in sidebar, 10 seconds to complete)
   → "What matters most to you?"
   → User picks their top 3 priorities from a visual grid
   → Optional: "Who is this for?" (family, investor, single, retiree)
   → Click "Generate"

4. Report generates (< 2 seconds)
   → Sidebar shows condensed report summary
   → Link: "View full report →" opens /reports/{uuid}

5. Full report page (/reports/{uuid})
   → Permanent URL, persisted in database
   → Renders all sections with personalized weighting
   → Print-friendly, shareable
   → User can return to it anytime (My Reports page)
```

---

## Step 1: Preference Questionnaire

### 1.1 The Priority Picker

When the user clicks "Generate Report," a preference panel slides into the sidebar. NOT a multi-page wizard. One screen, done in 10 seconds.

```
┌─────────────────────────────────────┐
│  Vad är viktigast för dig?          │
│  Välj upp till 3                    │
│                                     │
│  ┌──────────┐  ┌──────────┐        │
│  │ 🏫       │  │ 🚇       │        │
│  │ Skol-    │  │ Kollek-  │        │
│  │ kvalitet │  │ tivtrafik│        │
│  └──────────┘  └──────────┘        │
│  ┌──────────┐  ┌──────────┐        │
│  │ 🛡️       │  │ 📈       │        │
│  │ Trygghet │  │ Värde-   │        │
│  │          │  │ utveckling│        │
│  └──────────┘  └──────────┘        │
│  ┌──────────┐  ┌──────────┐        │
│  │ 🌳       │  │ 🛒       │        │
│  │ Grön-    │  │ Service  │        │
│  │ områden  │  │ & butiker│        │
│  └──────────┘  └──────────┘        │
│  ┌──────────┐  ┌──────────┐        │
│  │ 💰       │  │ 🏃       │        │
│  │ Pris-    │  │ Livsstil │        │
│  │ läge     │  │ & nöje   │        │
│  └──────────┘  └──────────┘        │
│                                     │
│  ── Vem är rapporten för? ──        │
│  ○ Familj  ○ Investerare           │
│  ○ Singel  ○ Pensionär             │
│  ○ Annan                            │
│                                     │
│  [    Generera rapport    ]         │
└─────────────────────────────────────┘
```

### 1.2 Priority Categories

| ID | Swedish Label | English (internal) | What it maps to |
|---|---|---|---|
| `school` | Skolkvalitet | School quality | School proximity weight ↑, school area indicator weight ↑ |
| `transit` | Kollektivtrafik | Public transit | Transit proximity weight ↑ |
| `safety` | Trygghet | Safety | Crime indicators weight ↑ (when available), negative POI weight ↑ |
| `appreciation` | Värdeutveckling | Price trajectory | Price prediction weight ↑, income trend weight ↑ |
| `green` | Grönområden | Green spaces | Green space proximity weight ↑ |
| `services` | Service & butiker | Shops & services | Grocery proximity weight ↑, positive POI weight ↑ |
| `affordability` | Prisläge | Price level | Low economic standard indicator, rental tenure |
| `lifestyle` | Livsstil & nöje | Lifestyle & entertainment | Positive POIs (cafes, gyms, restaurants) weight ↑ |

User picks **up to 3**. Selected priorities get boosted. Unselected ones don't get zeroed out — they just stay at default weight.

### 1.3 How Preferences Affect Scoring

This is NOT a fully separate scoring model. It's a **gentle reweighting** of the existing composite score.

```
Default weights: area 70%, proximity 30% (from proximity scoring task)

If user picks "school quality" as a priority:
  - school_proximity weight: 0.10 → 0.16 (+60%)
  - school_merit_value_avg weight: 0.17 → 0.22 (+30%)
  - Other weights: redistributed down slightly to keep sum at 1.0

If user picks 3 priorities:
  - Each boosted by ~40-60% of its base weight
  - Non-priority weights shrink proportionally
  - Net effect: score shifts by 3-8 points vs default
```

Implementation:

```php
class PersonalizedScoringService
{
    /**
     * Compute a personalized score by adjusting indicator weights
     * based on user priorities.
     *
     * @param array $priorities  e.g., ['school', 'transit', 'green']
     * @param float $areaScore   Default area score (0-100)
     * @param ProximityResult $proximity  Proximity scores
     * @param array $indicatorValues  Per-indicator normalized values
     * @return PersonalizedScore
     */
    public function compute(
        array $priorities,
        float $areaScore,
        ProximityResult $proximity,
        array $indicatorValues
    ): PersonalizedScore {

        // Map priorities to weight boost targets
        $boostMap = [
            'school' => [
                'area' => ['school_merit_value_avg' => 1.3, 'school_goal_achievement_avg' => 1.3],
                'proximity' => ['school_proximity' => 1.6],
            ],
            'transit' => [
                'proximity' => ['transit' => 1.6],
            ],
            'safety' => [
                'area' => ['crime_rate' => 1.5],  // future
                'proximity' => ['negative_poi' => 1.5],
            ],
            'appreciation' => [
                'area' => ['median_income' => 1.3, 'employment_rate' => 1.2],
            ],
            'green' => [
                'proximity' => ['green_space' => 1.6],
            ],
            'services' => [
                'proximity' => ['grocery' => 1.5, 'positive_poi' => 1.4],
            ],
            'affordability' => [
                'area' => ['low_economic_standard_pct' => 1.4, 'rental_tenure_pct' => 1.2],
            ],
            'lifestyle' => [
                'proximity' => ['positive_poi' => 1.5],
            ],
        ];

        // Apply boosts, then renormalize so weights sum to 1.0
        // Recompute area + proximity blended score
        // Return both the personalized score AND the default score for comparison
    }
}
```

The personalized score includes a comparison: "Your personalized score: 74. Default score: 68. School quality pushed your score +6 points because the nearest school (Årstaskolan, meritvärde 241) is excellent."

---

## Step 2: Database — Reports Table

### 2.1 Migration

```php
Schema::create('reports', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique()->index();         // Public URL identifier
    $table->unsignedBigInteger('user_id')->nullable()->index(); // NULL for guest purchases
    $table->string('guest_email')->nullable();        // For non-logged-in purchases
    $table->string('stripe_payment_id')->nullable();  // Payment reference (when we add payments)

    // Location
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    $table->string('address')->nullable();            // Reverse-geocoded display address
    $table->string('kommun_name')->nullable();
    $table->string('lan_name')->nullable();
    $table->string('deso_code', 10)->nullable()->index();

    // User preferences
    $table->json('priorities');                        // ["school", "transit", "green"]
    $table->string('persona')->nullable();            // "family", "investor", "single", "retiree"

    // Computed results (snapshot — persisted at generation time)
    $table->decimal('default_score', 6, 2)->nullable();
    $table->decimal('personalized_score', 6, 2)->nullable();
    $table->decimal('area_score', 6, 2)->nullable();
    $table->decimal('proximity_score', 6, 2)->nullable();
    $table->json('area_indicators');                  // Full indicator breakdown snapshot
    $table->json('proximity_factors');                // Full proximity factor breakdown
    $table->json('schools');                          // Nearby schools snapshot
    $table->json('personalization_impact');            // Which factors shifted and by how much
    $table->json('top_positive');                      // Top strengths
    $table->json('top_negative');                      // Top weaknesses
    $table->decimal('trend_1y', 6, 2)->nullable();
    $table->json('prediction')->nullable();           // Price prediction data if available
    $table->json('metadata')->nullable();             // Any extra data

    // Access control
    $table->string('status', 20)->default('active');  // active, expired, refunded
    $table->timestamp('expires_at')->nullable();       // NULL = never expires
    $table->integer('view_count')->default(0);

    $table->timestamps();
});
```

### 2.2 Why Snapshot Everything

The report stores a **complete snapshot** of all computed data at the moment of generation. This is critical because:

- Area scores change when we recompute (new data, new weights)
- Proximity data changes when POIs update
- Schools get new statistics annually
- The user paid for this specific analysis — it shouldn't silently change

The report is a frozen document. If the user wants fresh data, they generate a new report.

### 2.3 Report Model

```php
class Report extends Model
{
    protected $casts = [
        'priorities' => 'array',
        'area_indicators' => 'array',
        'proximity_factors' => 'array',
        'schools' => 'array',
        'personalization_impact' => 'array',
        'top_positive' => 'array',
        'top_negative' => 'array',
        'prediction' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): string
    {
        return route('reports.show', $this->uuid);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getScoreLabelAttribute(): string
    {
        $score = $this->personalized_score;
        return match(true) {
            $score >= 80 => 'Starkt tillväxtområde',
            $score >= 60 => 'Stabilt / Positivt',
            $score >= 40 => 'Blandade signaler',
            $score >= 20 => 'Förhöjd risk',
            default => 'Hög risk / Nedåtgående',
        };
    }
}
```

---

## Step 3: Report Generation Service

### 3.1 ReportGenerationService

```php
class ReportGenerationService
{
    public function __construct(
        private ScoringService $scoring,
        private ProximityScoreService $proximity,
        private PersonalizedScoringService $personalized,
    ) {}

    public function generate(
        float $lat,
        float $lng,
        array $priorities,
        ?string $persona = null,
        ?User $user = null,
        ?string $guestEmail = null,
    ): Report {

        // 1. Resolve DeSO
        $deso = DB::selectOne("
            SELECT deso_code, kommun_name, lan_name
            FROM deso_areas
            WHERE ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            LIMIT 1
        ", [$lng, $lat]);

        // 2. Get area score + indicators
        $areaScore = CompositeScore::where('deso_code', $deso->deso_code)
            ->orderBy('year', 'desc')
            ->first();

        $indicators = IndicatorValue::where('deso_code', $deso->deso_code)
            ->whereHas('indicator', fn($q) => $q->where('is_active', true))
            ->orderBy('year', 'desc')
            ->get()
            ->groupBy('indicator_id')
            ->map(fn($group) => $group->first()) // Latest year per indicator
            ->values();

        // 3. Compute proximity scores
        $proximityResult = $this->proximity->score($lat, $lng);

        // 4. Get nearby schools
        $schools = School::where('status', 'active')
            ->whereRaw("ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 2000)", [$lng, $lat])
            ->with('latestStatistics')
            ->get()
            ->map(fn($s) => [
                'name' => $s->name,
                'type' => $s->type_of_schooling,
                'operator' => $s->operator_type,
                'merit_value' => $s->latestStatistics?->merit_value_17,
                'goal_achievement' => $s->latestStatistics?->goal_achievement_pct,
                'distance_m' => DB::selectOne("
                    SELECT ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) as dist
                    FROM schools WHERE id = ?
                ", [$lng, $lat, $s->id])->dist,
            ])
            ->sortBy('distance_m')
            ->values();

        // 5. Compute personalized score
        $personalizedResult = $this->personalized->compute(
            $priorities,
            $areaScore?->score ?? 50,
            $proximityResult,
            $indicators->toArray(),
        );

        // 6. Reverse geocode for display address
        $address = $this->reverseGeocode($lat, $lng);

        // 7. Get price prediction if available
        $prediction = PricePrediction::where('deso_code', $deso->deso_code)
            ->orderBy('prediction_year', 'desc')
            ->first();

        // 8. Create and persist the report
        return Report::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'guest_email' => $guestEmail,
            'lat' => $lat,
            'lng' => $lng,
            'address' => $address,
            'kommun_name' => $deso->kommun_name,
            'lan_name' => $deso->lan_name,
            'deso_code' => $deso->deso_code,
            'priorities' => $priorities,
            'persona' => $persona,
            'default_score' => $areaScore?->score,
            'personalized_score' => $personalizedResult->score,
            'area_score' => $areaScore?->score,
            'proximity_score' => $proximityResult->compositeScore(),
            'area_indicators' => $this->formatIndicators($indicators),
            'proximity_factors' => $proximityResult->toArray(),
            'schools' => $schools->toArray(),
            'personalization_impact' => $personalizedResult->impact,
            'top_positive' => $areaScore?->top_positive,
            'top_negative' => $areaScore?->top_negative,
            'trend_1y' => $areaScore?->trend_1y,
            'prediction' => $prediction?->toArray(),
        ]);
    }

    private function reverseGeocode(float $lat, float $lng): string
    {
        // Use Photon (free, no API key)
        $response = Http::get("https://photon.komoot.io/reverse", [
            'lat' => $lat,
            'lon' => $lng,
        ]);

        $props = $response->json('features.0.properties') ?? [];

        return implode(', ', array_filter([
            $props['street'] ?? null,
            $props['housenumber'] ?? null,
            $props['city'] ?? $props['name'] ?? null,
        ]));
    }
}
```

---

## Step 4: Routes & Controllers

### 4.1 Routes

```php
// Report generation
Route::post('/api/reports', [ReportController::class, 'store']);

// Report detail page (public URL with UUID)
Route::get('/reports/{uuid}', [ReportController::class, 'show'])->name('reports.show');

// User's reports list
Route::get('/my-reports', [ReportController::class, 'index'])->name('reports.index');
```

### 4.2 ReportController

```php
class ReportController extends Controller
{
    public function store(Request $request, ReportGenerationService $service)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric|between:55,69',  // Sweden bounds
            'lng' => 'required|numeric|between:11,25',
            'priorities' => 'required|array|min:1|max:3',
            'priorities.*' => 'string|in:school,transit,safety,appreciation,green,services,affordability,lifestyle',
            'persona' => 'nullable|string|in:family,investor,single,retiree,other',
        ]);

        // TODO: Check payment/subscription status
        // For now, allow all requests (stub payment check)

        $report = $service->generate(
            lat: $validated['lat'],
            lng: $validated['lng'],
            priorities: $validated['priorities'],
            persona: $validated['persona'] ?? null,
            user: $request->user(),
        );

        return response()->json([
            'uuid' => $report->uuid,
            'url' => $report->url,
            'summary' => $this->buildSummary($report),
        ]);
    }

    public function show(string $uuid)
    {
        $report = Report::where('uuid', $uuid)->firstOrFail();

        if ($report->isExpired()) {
            abort(410, 'This report has expired.');
        }

        $report->increment('view_count');

        return Inertia::render('Reports/Show', [
            'report' => $report,
        ]);
    }

    public function index(Request $request)
    {
        $reports = Report::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return Inertia::render('Reports/Index', [
            'reports' => $reports,
        ]);
    }

    private function buildSummary(Report $report): array
    {
        return [
            'score' => $report->personalized_score,
            'label' => $report->score_label,
            'address' => $report->address,
            'kommun' => $report->kommun_name,
            'top_strength' => $report->top_positive[0] ?? null,
            'top_weakness' => $report->top_negative[0] ?? null,
            'personalization_note' => $this->personalizationNote($report),
        ];
    }

    private function personalizationNote(Report $report): string
    {
        $diff = $report->personalized_score - $report->default_score;
        if (abs($diff) < 1) return 'Dina prioriteringar påverkar inte poängen nämnvärt.';

        $direction = $diff > 0 ? 'högre' : 'lägre';
        $priorities = collect($report->priorities)
            ->map(fn($p) => match($p) {
                'school' => 'skolkvalitet',
                'transit' => 'kollektivtrafik',
                'safety' => 'trygghet',
                'appreciation' => 'värdeutveckling',
                'green' => 'grönområden',
                'services' => 'service',
                'affordability' => 'prisläge',
                'lifestyle' => 'livsstil',
            })
            ->join(', ', ' och ');

        return sprintf(
            'Ditt personliga poäng är %+.0f poäng %s än standardpoängen tack vare ditt fokus på %s.',
            $diff, $direction, $priorities
        );
    }
}
```

---

## Step 5: Sidebar Report Summary

### 5.1 After Report Generation

When the report comes back from the API, the sidebar updates to show a condensed version:

```
┌─────────────────────────────────────┐
│  📊 Din rapport                     │
│  Sveavägen 42, Stockholm            │
│                                     │
│  ┌──────────────────────┐           │
│  │  Ditt poäng: 74      │           │
│  │  Stabilt / Positivt  │           │
│  │                      │           │
│  │  Standard: 68        │           │
│  │  Dina prioriteringar │           │
│  │  gav +6 poäng        │           │
│  └──────────────────────┘           │
│                                     │
│  ✅ Skolkvalitet: Utmärkt           │
│  Årstaskolan (241 mv) — 200m        │
│                                     │
│  ✅ Kollektivtrafik: Mycket bra     │
│  Zinkensdamm T-bana — 350m          │
│                                     │
│  ⚠️ Trygghet: Blandat               │
│  Inga negativa POI inom 500m        │
│                                     │
│  [  Visa fullständig rapport →  ]   │
│                                     │
│  Rapporten sparad — du kan alltid   │
│  komma tillbaka via länken ovan.    │
└─────────────────────────────────────┘
```

The sidebar summary shows ONLY the user's priority categories (the 3 they picked) plus the overall score. The full detail is on the report page.

### 5.2 Sidebar Component

```tsx
interface ReportSummary {
  uuid: string;
  url: string;
  score: number;
  label: string;
  address: string;
  kommun: string;
  priorities: string[];
  defaultScore: number;
  personalizedScore: number;
  personalizationNote: string;
  topStrength: string | null;
  topWeakness: string | null;
}

function ReportSummaryCard({ summary }: { summary: ReportSummary }) {
  const diff = summary.personalizedScore - summary.defaultScore;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-sm">📊 Din rapport</CardTitle>
        <p className="text-muted-foreground text-xs">{summary.address}</p>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Score display */}
        <div className="text-center p-4 rounded-lg bg-muted">
          <div className="text-3xl font-bold" style={{ color: scoreColor(summary.score) }}>
            {summary.personalizedScore}
          </div>
          <div className="text-sm text-muted-foreground">{summary.label}</div>
          {diff !== 0 && (
            <div className="text-xs mt-1">
              Standard: {summary.defaultScore} ·
              <span className={diff > 0 ? 'text-green-600' : 'text-red-600'}>
                {diff > 0 ? '+' : ''}{diff.toFixed(0)} från dina val
              </span>
            </div>
          )}
        </div>

        {/* Personalization note */}
        <p className="text-xs text-muted-foreground">{summary.personalizationNote}</p>

        {/* Link to full report */}
        <Button asChild className="w-full">
          <a href={summary.url}>Visa fullständig rapport →</a>
        </Button>

        <p className="text-xs text-muted-foreground text-center">
          Rapporten sparad — du kan alltid komma tillbaka.
        </p>
      </CardContent>
    </Card>
  );
}
```

---

## Step 6: Full Report Page

### 6.1 Page Structure

`/reports/{uuid}` is a standalone Inertia page. NOT a sidebar view — this is a full-width page designed for reading, sharing, and printing.

```
┌──────────────────────────────────────────────────────────────┐
│  Platsindex                            [Mina rapporter]      │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  ── Områdesrapport ──────────────────────────────────────    │
│  Sveavägen 42, Stockholms kommun, Stockholms län             │
│  Genererad 2026-02-08 · Rapport-ID: abc-123-def              │
│                                                              │
│  ┌────────────────────────────────────────────────────┐      │
│  │  Ditt personliga poäng                             │      │
│  │                                                    │      │
│  │      74 / 100                                      │      │
│  │  Stabilt / Positivt                                │      │
│  │                                                    │      │
│  │  ↑ +3.2 (1 år)     Standard: 68                   │      │
│  │                                                    │      │
│  │  Baserat på dina prioriteringar:                   │      │
│  │  🏫 Skolkvalitet · 🚇 Kollektivtrafik · 🌳 Grön  │      │
│  └────────────────────────────────────────────────────┘      │
│                                                              │
│  ── Varför detta poäng? ─────────────────────────────────    │
│                                                              │
│  Dina prioriteringar gav +6 poäng jämfört med standard-      │
│  poängen (68). Skolkvaliteten i närheten drog upp mest       │
│  (+4 poäng) tack vare Årstaskolan med meritvärde 241,        │
│  bara 200 meter bort.                                        │
│                                                              │
│  ── Områdesdata ─────────────────────────────────────────    │
│                                                              │
│  Medianinkomst        ████████░░  78:e percentilen  287 tkr  │
│  Sysselsättning       ██████░░░░  61:a percentilen  72.3%    │
│  Eftergymn. utb.      ███████░░░  71:a percentilen  48.2%    │
│  Skolkvalitet (snitt) █████████░  91:a percentilen  241 mv   │
│  ...                                                         │
│                                                              │
│  ── Närhetsanalys ───────────────────────────────────────    │
│                                                              │
│  🏫 Skolkvalitet                                    82/100   │
│  Årstaskolan (grundskola, kommunal) — 200m                   │
│  Meritvärde 241 · Måluppfyllelse 94% · Behöriga 78%         │
│                                                              │
│  Eriksdalsskolan (grundskola, kommunal) — 850m               │
│  Meritvärde 228 · Måluppfyllelse 88% · Behöriga 82%         │
│                                                              │
│  🚇 Kollektivtrafik                                68/100   │
│  Zinkensdamm (T-bana) — 350m                                 │
│  Hornstull (T-bana) — 600m                                   │
│  Buss 4, 66 — 120m                                           │
│                                                              │
│  🌳 Grönområden                                    97/100   │
│  Tantolunden — 120m                                          │
│  Eriksdalsbadet — 400m                                       │
│                                                              │
│  🛒 Livsmedel                                      91/100   │
│  ICA Nära Hornstull — 80m                                    │
│                                                              │
│  ── Styrkor & Svagheter ─────────────────────────────────    │
│                                                              │
│  ✅ Hög skolkvalitet i närheten                              │
│  ✅ Stigande medianinkomst (+8% senaste 3 åren)              │
│  ✅ Utmärkt kollektivtrafik                                  │
│  ✅ Nära grönområden                                         │
│                                                              │
│  ⚠️ Sysselsättningsgrad under genomsnittet                   │
│  ⚠️ Hög andel hyresrätter (begränsar pristillväxt)           │
│                                                              │
│  ── Prisutsikt ──────────────────────────────────────────    │
│  (if prediction data available)                              │
│                                                              │
│  ↑ Trolig uppgång: +5 till +12% (2 år)                      │
│  Konfidensgrad: Medium                                       │
│  Baserat på historiska mönster från liknande områden.         │
│                                                              │
│  ── Metod & Data ────────────────────────────────────────    │
│                                                              │
│  Denna rapport bygger på öppna data från SCB, Skolverket,    │
│  och OpenStreetMap. Poängen beräknas med [antal] indikatorer │
│  på områdesnivå och [antal] närhetsfaktorer för den exakta   │
│  adressen. Dina prioriteringar justerar viktningen med       │
│  upp till ±8 poäng.                                          │
│                                                              │
│  Data senast uppdaterad: 2026-01-15                          │
│  Beräkningsmodell: v1.0                                      │
│                                                              │
│  ⚠️ Statistisk uppskattning baserad på historiska mönster.   │
│  Inte finansiell rådgivning.                                 │
│                                                              │
│  ── Fotnoter ────────────────────────────────────────────    │
│  [Print-vänlig version]  [Dela rapport]  [Generera ny]       │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

### 6.2 Report Page Component

```tsx
// resources/js/Pages/Reports/Show.tsx

export default function ReportShow({ report }: { report: Report }) {
  return (
    <div className="max-w-3xl mx-auto py-8 px-4 print:px-0">
      {/* Header */}
      <header className="mb-8">
        <h1 className="text-2xl font-bold">Områdesrapport</h1>
        <p className="text-muted-foreground">{report.address}</p>
        <p className="text-xs text-muted-foreground">
          {report.kommun_name}, {report.lan_name} ·
          Genererad {formatDate(report.created_at)} ·
          Rapport-ID: {report.uuid.slice(0, 8)}
        </p>
      </header>

      {/* Score card */}
      <ScoreHero report={report} />

      {/* Personalization explanation */}
      <PersonalizationExplanation report={report} />

      {/* Area indicators */}
      <AreaIndicatorSection indicators={report.area_indicators} />

      {/* Proximity factors - ordered by user's priorities first */}
      <ProximitySection
        factors={report.proximity_factors}
        schools={report.schools}
        priorities={report.priorities}
      />

      {/* Strengths & weaknesses */}
      <StrengthsWeaknesses
        positive={report.top_positive}
        negative={report.top_negative}
      />

      {/* Price prediction (if available) */}
      {report.prediction && (
        <PricePredictionSection prediction={report.prediction} />
      )}

      {/* Methodology footer */}
      <MethodologyFooter report={report} />
    </div>
  );
}
```

### 6.3 Priority-Ordered Sections

The report sections should be ordered by the user's priorities. If they picked school → transit → green, the proximity section shows schools first, then transit, then green space, then the rest. This makes the report feel personalized even at a structural level.

```tsx
function ProximitySection({ factors, schools, priorities }) {
  // Sort factors: user priorities first, then the rest
  const priorityOrder = [
    ...priorities,
    ...ALL_PRIORITIES.filter(p => !priorities.includes(p)),
  ];

  const sortedFactors = priorityOrder
    .map(p => factors.find(f => f.slug === prioritySlugMap[p]))
    .filter(Boolean);

  return (
    <section>
      <h2>Närhetsanalys</h2>
      {sortedFactors.map(factor => (
        <ProximityFactorCard
          key={factor.slug}
          factor={factor}
          isPriority={priorities.includes(reverseSlugMap[factor.slug])}
          schools={factor.slug === 'school_proximity' ? schools : undefined}
        />
      ))}
    </section>
  );
}
```

---

## Step 7: My Reports Page

### 7.1 User's Report History

`/my-reports` shows all reports the user has generated, ordered by date.

```
┌─────────────────────────────────────────────────────────────┐
│  Mina rapporter                                             │
│                                                             │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 📊 Sveavägen 42, Stockholm          74    2026-02-08 │  │
│  │    🏫 Skolkvalitet · 🚇 Trafik · 🌳 Grön            │  │
│  │    [Visa rapport →]                                   │  │
│  └───────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 📊 Strandvägen 7, Danderyd           82    2026-02-05│  │
│  │    🏫 Skolkvalitet · 📈 Värde · 💰 Pris              │  │
│  │    [Visa rapport →]                                   │  │
│  └───────────────────────────────────────────────────────┘  │
│  ┌───────────────────────────────────────────────────────┐  │
│  │ 📊 Rinkeby torg 1, Stockholm         38    2026-01-28│  │
│  │    🛡️ Trygghet · 📈 Värde · 🚇 Trafik               │  │
│  │    [Visa rapport →]                                   │  │
│  └───────────────────────────────────────────────────────┘  │
│                                                             │
│  Visar 3 av 3 rapporter                                    │
└─────────────────────────────────────────────────────────────┘
```

### 7.2 Guest Access

If the user isn't logged in, reports are tied to their email (from payment). They can:
- Access the report via the UUID URL at any time (bookmarkable)
- See all their reports by entering their email at `/my-reports` (sends a magic link)
- Create an account later and claim their existing reports

```php
// Claim guest reports when user creates account
public function claimGuestReports(User $user): int
{
    return Report::where('guest_email', $user->email)
        ->whereNull('user_id')
        ->update(['user_id' => $user->id]);
}
```

---

## Step 8: Report Sharing

### 8.1 Public UUID URLs

Every report has a permanent URL: `/reports/{uuid}`. This URL is:
- Accessible without login (the report is the product — once paid, it's shareable)
- Shareable via messaging, email, social media
- Indexable by search engines (optional — could be noindex if we want to keep them private)

### 8.2 Share Options

On the report page, add share buttons:

```
[📋 Kopiera länk]  [📧 Skicka via email]  [🖨️ Skriv ut]
```

The print stylesheet should produce a clean, single-page PDF-like output. No navigation, no sidebar — just the report content.

### 8.3 OG Tags for Social Sharing

When someone shares a report URL on social media, show a preview:

```php
// In ReportController::show()
Inertia::render('Reports/Show', [
    'report' => $report,
])->withViewData([
    'og_title' => "Områdesrapport: {$report->address}",
    'og_description' => "Poäng: {$report->personalized_score}/100 — {$report->score_label}",
    'og_image' => route('reports.og-image', $report->uuid),
]);
```

Generate a simple OG image (Python/Pillow or HTML-to-image) showing the score and address. Not critical for v1 — can be added later.

---

## Step 9: Payment Stub

### 9.1 Not Implementing Payments Yet

This task creates the report infrastructure. Payment integration (Swish, Klarna, Stripe) is a separate task. For now:

```php
// In ReportController::store()

// TODO: Implement payment check
// For now, all report generation is free (development mode)
$canGenerate = true;

// Future logic:
// $canGenerate = $user?->hasActiveSubscription()
//     || $user?->hasCredit('report')
//     || $this->processPayment($request);

if (!$canGenerate) {
    return response()->json(['error' => 'Payment required'], 402);
}
```

### 9.2 Payment-Ready Fields

The `reports` table already has `stripe_payment_id` and `status` columns. When payment is implemented:
- Single purchase: create report → redirect to payment → on success, set `stripe_payment_id`
- Subscription: check `user.subscription_status` before allowing generation
- Credits: decrement credit counter, record which credit was used

---

## Implementation Order

### Phase A: Database + Service Layer
1. Create `reports` migration
2. Create `Report` model with casts and accessors
3. Create `PersonalizedScoringService`
4. Create `ReportGenerationService`
5. Test: `php artisan tinker` → manually generate a report, verify JSON structure

### Phase B: API + Preference UI
6. Create `ReportController` with `store`, `show`, `index`
7. Add routes
8. Build preference questionnaire component (sidebar panel)
9. Wire up: user picks priorities → POST to API → receives UUID + summary
10. Show summary in sidebar with link to full report

### Phase C: Full Report Page
11. Create `Reports/Show.tsx` Inertia page
12. Build all report sections: score hero, personalization explanation, area indicators, proximity factors, schools, strengths/weaknesses, methodology
13. Priority-ordered section rendering
14. Print stylesheet
15. Test: generate report → view at `/reports/{uuid}` → verify all sections render

### Phase D: My Reports + Persistence
16. Create `Reports/Index.tsx` page
17. Add "Mina rapporter" navigation link
18. Verify: generate 3 reports → see all 3 in My Reports → click each → full report loads
19. Test persistence: generate report → close browser → reopen → navigate to `/reports/{uuid}` → report is still there
20. Guest email claim logic (for future auth integration)

---

## Verification

### Core Flow
- [ ] Clicking "Generate Report" shows preference questionnaire in sidebar
- [ ] User can select 1-3 priorities from the grid
- [ ] User can optionally select a persona
- [ ] Clicking "Generate" creates a report in < 2 seconds
- [ ] Sidebar shows condensed report summary with personalized score
- [ ] "View full report" link opens `/reports/{uuid}`
- [ ] Full report page renders all sections
- [ ] Report URL works when opened in a new browser/incognito (persistence)
- [ ] My Reports page lists all generated reports

### Personalization
- [ ] Selecting "school quality" as priority actually changes the score (vs default)
- [ ] Score difference is visible: "Standard: 68, Your score: 74, +6 from your priorities"
- [ ] Report sections are ordered by user's priorities
- [ ] Explanation text describes WHY the score shifted

### Persistence
- [ ] Report data survives server restart (it's in the database, not session)
- [ ] UUID URL is bookmarkable and works days later
- [ ] Report shows the data snapshot from generation time (not live data)
- [ ] `view_count` increments on each view

### Edge Cases
- [ ] Location with no schools → proximity school section says "Inga skolor inom 2 km"
- [ ] Location outside DeSO boundaries → graceful error
- [ ] Location in rural area with few POIs → low proximity score, report still renders
- [ ] User generates same location twice with different priorities → two separate reports, different scores

---

## What NOT to Do

- **DO NOT implement payment processing.** Stub it. That's a separate task with Swish/Klarna/Stripe integration.
- **DO NOT generate PDF files.** The web page IS the report. Print stylesheet handles the print case. PDF generation (server-side with Puppeteer/wkhtmltopdf) is a future optimization.
- **DO NOT change the heatmap tiles.** Tiles still show default (non-personalized) area scores. Personalization is sidebar + report only.
- **DO NOT store personalized scores in `composite_scores`.** The reports table has its own snapshot. The composite_scores table stays as the canonical default score.
- **DO NOT make the preference questionnaire more than one screen.** One panel, 10 seconds, done. Multi-step wizards kill conversion.
- **DO NOT allow more than 3 priorities.** If everything is a priority, nothing is. Three forces the user to think about what actually matters to them.
- **DO NOT expire reports.** Once generated, the report lives forever (unless explicitly refunded). The `expires_at` column exists for future subscription-gated features but defaults to NULL (no expiry).

**DO:**
- Snapshot everything at generation time (scores, indicators, schools)
- Show both default and personalized score (transparency builds trust)
- Order report sections by user's priorities (feels custom)
- Make UUIDs the primary access key (no login required to view)
- Keep the preference questionnaire delightful (icons, quick, visual)
- Show a "personalization impact" explanation in plain Swedish