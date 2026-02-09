# TASK: Report Page — Full Paid Product

## Context

The user paid 79 kr (or has a subscription). They've gone through the preference questionnaire. Now we generate and render the actual report — the thing they're paying for. This is the product.

The report is a **standalone page** at `/reports/{uuid}`. It's permanent (persisted in the database), shareable (anyone with the link can view it), and print-friendly. Everything is snapshotted at generation time — scores, indicators, schools, trends, map state. It never changes after creation.

The free sidebar teaser shows 2 indicators per category + gray bars. The report shows **everything**: all indicators with full values, sparklines, trend arrows, per-school breakdowns, a static map image, written verdicts per category, and an overall assessment with strengths/weaknesses.

**Depends on:**
- Report generation service + `reports` table (from `task-report-generation.md`)
- Indicator trend data in the API (from `task-indicator-trends-ui.md`)
- Score color centralization (from `task-centralize-score-colors.md`)
- Sidebar teaser v3 with `is_free_preview` flag (from `task-sidebar-teaser-v3.md`)

---

## What the Report Contains

The report has 9 sections, rendered as a scrollable single-page document:

1. **Header** — address, kommun, län, date, report ID
2. **Hero Score** — big number, label, trend sparkline, personalization note
3. **Map Snapshot** — static image of the DeSO area + pin location + school markers
4. **Category Verdicts** — one verdict card per category with traffic light + summary text
5. **Full Indicator Breakdown** — every indicator, every value, sparklines, trend arrows
6. **School Detail** — per-school cards for all nearby schools
7. **Strengths & Weaknesses** — bullet lists with icons
8. **Outlook** — forward-looking assessment based on trends
9. **Methodology & Sources** — data sources, freshness, disclaimer

---

## Step 1: Extend the Snapshot

### 1.1 What Gets Snapshotted

The `ReportGenerationService::generate()` already collects most data. Extend it to capture everything the report page needs:

```php
// Additional data to snapshot in the generate() method

// 1. Historical percentiles per indicator (for sparklines)
$indicatorHistory = $this->getIndicatorHistory($deso->deso_code, $activeIndicators);

// 2. Category-level verdicts
$categoryVerdicts = $this->computeCategoryVerdicts($indicators, $indicatorHistory);

// 3. Composite score history (for hero sparkline)
$scoreHistory = CompositeScore::where('deso_code', $deso->deso_code)
    ->orderBy('year')
    ->get(['year', 'score'])
    ->map(fn($s) => ['year' => $s->year, 'score' => round($s->score, 1)])
    ->values();

// 4. DeSO metadata
$desoMeta = DesoArea::where('deso_code', $deso->deso_code)
    ->first(['deso_code', 'deso_name', 'kommun_code', 'kommun_name', 'lan_code', 'lan_name', 'area_km2', 'population']);

// 5. National reference values (for context)
$nationalRefs = $this->getNationalReferences($activeIndicators);

// 6. Map snapshot bounds (for static map rendering)
$desoBounds = DB::selectOne("
    SELECT ST_AsGeoJSON(ST_Envelope(geom)) as bbox,
           ST_AsGeoJSON(ST_Centroid(geom)) as centroid
    FROM deso_areas WHERE deso_code = ?
", [$deso->deso_code]);
```

### 1.2 Updated Report JSON Structure

The `area_indicators` JSON column now stores full history per indicator:

```json
{
  "area_indicators": [
    {
      "slug": "median_income",
      "name": "Medianinkomst",
      "category": "economy",
      "category_label": "Ekonomi & arbetsmarknad",
      "source": "scb",
      "unit": "SEK",
      "direction": "positive",
      "raw_value": 287000,
      "formatted_value": "287 000 kr",
      "normalized_value": 0.78,
      "percentile": 78,
      "national_median": 265000,
      "national_median_formatted": "265 000 kr",
      "trend": {
        "years": [2019, 2020, 2021, 2022, 2023, 2024],
        "percentiles": [68, 71, 73, 74, 75, 78],
        "raw_values": [241000, 251000, 259000, 268000, 275000, 287000],
        "change_1y": 3,
        "change_3y": 5,
        "change_5y": 10
      },
      "description": "Medelinkomst efter skatt per person i området. Högre inkomst indikerar ekonomisk styrka och köpkraft.",
      "weight": 0.20
    }
  ],
  "category_verdicts": {
    "safety": {
      "label": "Trygghet & brottslighet",
      "emoji": "🛡️",
      "score": 62,
      "grade": "B",
      "color": "#6abf4b",
      "verdict_sv": "Området har genomsnittlig brottslighet med sjunkande trend. Upplevd trygghet ligger nära riksgenomsnittet. Inga utsatta områden inom DeSO-gränsen.",
      "trend_direction": "improving",
      "indicator_count": 8
    }
  },
  "score_history": [
    {"year": 2019, "score": 61.2},
    {"year": 2020, "score": 63.5},
    {"year": 2024, "score": 72.0}
  ],
  "deso_meta": {
    "deso_code": "0180C4130",
    "deso_name": "Södermalm östra",
    "kommun_name": "Stockholm",
    "lan_name": "Stockholms län",
    "area_km2": 0.82,
    "population": 2413
  }
}
```

### 1.3 Additional Snapshot Columns

Add to the `reports` migration (or add a new migration):

```php
Schema::table('reports', function (Blueprint $table) {
    $table->json('category_verdicts')->nullable();
    $table->json('score_history')->nullable();         // Composite score per year
    $table->json('deso_meta')->nullable();             // DeSO name, population, area
    $table->json('national_references')->nullable();   // National medians per indicator
    $table->json('deso_geojson')->nullable();          // DeSO polygon for static map
    $table->string('model_version', 20)->nullable();   // e.g. "v1.2"
    $table->integer('indicator_count')->default(0);
    $table->integer('year')->nullable();               // Data year used
});
```

---

## Step 2: Category Verdicts

### 2.1 Verdict Computation

Each of the 5 categories gets a verdict: a letter grade (A-E), a color, a short written summary, and a trend direction.

```php
// app/Services/VerdictService.php

class VerdictService
{
    private const CATEGORIES = [
        'safety' => [
            'label' => 'Trygghet & brottslighet',
            'emoji' => '🛡️',
            'indicator_slugs' => [
                'crime_violent_rate', 'crime_property_rate', 'crime_total_rate',
                'perceived_safety', 'vulnerability_flag',
                'fast_food_density', 'gambling_density', 'pawn_shop_density',
            ],
        ],
        'economy' => [
            'label' => 'Ekonomi & arbetsmarknad',
            'emoji' => '💰',
            'indicator_slugs' => [
                'median_income', 'low_economic_standard_pct', 'employment_rate',
                'debt_rate_pct', 'eviction_rate', 'median_debt_sek',
            ],
        ],
        'education' => [
            'label' => 'Utbildning & skolor',
            'emoji' => '🏫',
            'indicator_slugs' => [
                'school_merit_value_avg', 'school_goal_achievement_avg',
                'school_teacher_certification_avg',
                'education_post_secondary_pct', 'education_below_secondary_pct',
            ],
        ],
        'environment' => [
            'label' => 'Miljö & service',
            'emoji' => '🌳',
            'indicator_slugs' => [
                'grocery_density', 'healthcare_density', 'restaurant_density',
                'fitness_density', 'transit_stop_density',
            ],
        ],
        'proximity' => [
            'label' => 'Platsanalys',
            'emoji' => '📍',
            // Computed separately from proximity score
        ],
    ];

    public function computeVerdict(string $category, array $indicators, array $history): array
    {
        $config = self::CATEGORIES[$category];
        $relevantIndicators = collect($indicators)
            ->filter(fn($i) => in_array($i['slug'], $config['indicator_slugs']));

        if ($relevantIndicators->isEmpty()) {
            return [
                'label' => $config['label'],
                'emoji' => $config['emoji'],
                'score' => null,
                'grade' => '—',
                'color' => '#94a3b8',
                'verdict_sv' => 'Inga data tillgängliga för denna kategori.',
                'trend_direction' => 'unknown',
                'indicator_count' => 0,
            ];
        }

        // Category score = average directed percentile of all indicators in category
        $avgPercentile = $relevantIndicators->avg('percentile');

        // Trend = average 1y change across category indicators
        $avgChange = $relevantIndicators
            ->filter(fn($i) => $i['trend']['change_1y'] !== null)
            ->avg(fn($i) => $i['trend']['change_1y']) ?? 0;

        $grade = $this->percentileToGrade($avgPercentile);
        $trendDir = $avgChange > 1.5 ? 'improving' : ($avgChange < -1.5 ? 'declining' : 'stable');

        return [
            'label' => $config['label'],
            'emoji' => $config['emoji'],
            'score' => round($avgPercentile),
            'grade' => $grade['letter'],
            'color' => $grade['color'],
            'verdict_sv' => $this->generateVerdictText($category, $avgPercentile, $trendDir, $relevantIndicators),
            'trend_direction' => $trendDir,
            'indicator_count' => $relevantIndicators->count(),
        ];
    }

    private function percentileToGrade(float $pct): array
    {
        return match(true) {
            $pct >= 80 => ['letter' => 'A', 'color' => '#1a7a2e'],
            $pct >= 60 => ['letter' => 'B', 'color' => '#6abf4b'],
            $pct >= 40 => ['letter' => 'C', 'color' => '#f0c040'],
            $pct >= 20 => ['letter' => 'D', 'color' => '#e57373'],
            default    => ['letter' => 'E', 'color' => '#c0392b'],
        };
    }
}
```

### 2.2 Verdict Text Generation

Each category gets a 2-3 sentence Swedish verdict. These are template-driven, not AI-generated. The templates combine the grade, trend, and standout indicators:

```php
private function generateVerdictText(
    string $category,
    float $avgPercentile,
    string $trendDir,
    Collection $indicators
): string {
    // Find the standout indicators (best and worst in this category)
    $best = $indicators->sortByDesc('percentile')->first();
    $worst = $indicators->sortBy('percentile')->first();

    $trendText = match($trendDir) {
        'improving' => 'med förbättrad trend senaste året',
        'declining' => 'med försämrad trend senaste året',
        'stable'    => 'med stabil trend',
        default     => '',
    };

    $levelText = match(true) {
        $avgPercentile >= 80 => 'väl över riksgenomsnittet',
        $avgPercentile >= 60 => 'något över riksgenomsnittet',
        $avgPercentile >= 40 => 'nära riksgenomsnittet',
        $avgPercentile >= 20 => 'under riksgenomsnittet',
        default              => 'väl under riksgenomsnittet',
    };

    // Category-specific templates
    return match($category) {
        'safety' => "Tryggheten i området ligger {$levelText} {$trendText}. "
            . $this->safetyDetail($indicators),
        'economy' => "Den ekonomiska situationen ligger {$levelText} {$trendText}. "
            . $this->economyDetail($indicators),
        'education' => "Utbildningsnivån och skolkvaliteten ligger {$levelText} {$trendText}. "
            . $this->educationDetail($indicators),
        'environment' => "Tillgången till service och grönområden ligger {$levelText} {$trendText}. "
            . $this->environmentDetail($indicators),
        default => "Kategorin ligger {$levelText} {$trendText}.",
    };
}

private function safetyDetail(Collection $indicators): string
{
    $perceived = $indicators->firstWhere('slug', 'perceived_safety');
    $violent = $indicators->firstWhere('slug', 'crime_violent_rate');
    $vuln = $indicators->firstWhere('slug', 'vulnerability_flag');

    $parts = [];
    if ($perceived) {
        $level = $perceived['percentile'] >= 60 ? 'god' : ($perceived['percentile'] >= 40 ? 'genomsnittlig' : 'låg');
        $parts[] = "Upplevd trygghet är {$level} ({$perceived['percentile']}:e percentilen)";
    }
    if ($violent) {
        $level = $violent['percentile'] >= 60 ? 'lägre än genomsnittet' : 'högre än genomsnittet';
        $parts[] = "våldsbrott {$level}";
    }
    if ($vuln && $vuln['raw_value'] > 0) {
        $parts[] = "Området klassas som utsatt område av Polisen";
    }

    return implode('. ', $parts) . '.';
}

// ... similar detail methods for economy, education, environment
```

---

## Step 3: Map Snapshot

### 3.1 Static Map Image

The report needs a map showing:
- The DeSO polygon, colored by score
- A pin at the user's exact location
- School markers within the DeSO
- Surrounding DeSO polygons faintly visible for context

**Option A: Server-side rendered static image (preferred)**

Generate a PNG using a headless rendering approach. The simplest way in Laravel:

```php
// Use a lightweight static map tile service + overlay

private function generateMapSnapshot(
    float $lat,
    float $lng,
    string $desoCode,
    array $schools
): string {
    // Store the DeSO polygon + pin + school markers as GeoJSON
    // Then render using a client-side component that gets screenshotted
    // OR use a tile server + SVG overlay approach

    // For MVP: store the raw data and render client-side in the report page
    return json_encode([
        'center' => [$lat, $lng],
        'zoom' => 14,
        'deso_geojson' => $this->getDesoGeoJson($desoCode),
        'pin' => [$lat, $lng],
        'school_markers' => collect($schools)->map(fn($s) => [
            'lat' => $s['lat'],
            'lng' => $s['lng'],
            'name' => $s['name'],
            'merit' => $s['merit_value'],
        ])->toArray(),
        'surrounding_desos' => $this->getSurroundingDesosGeoJson($desoCode),
    ]);
}
```

### 3.2 Client-Side Map in Report

Render a **non-interactive** OpenLayers map in the report page. The user can look at it but not pan/zoom. This is a snapshot, not a live map.

```tsx
// resources/js/Components/Report/ReportMap.tsx

function ReportMap({ mapData, score }: { mapData: MapSnapshot; score: number }) {
    const mapRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!mapRef.current) return;

        const map = new OlMap({
            target: mapRef.current,
            interactions: [],  // No interactions — static display
            controls: [],       // No controls
            layers: [
                // Base tile layer (light/muted)
                new TileLayer({
                    source: new OSM({
                        url: 'https://{a-c}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png',
                    }),
                }),
                // Surrounding DeSOs (faint gray)
                new VectorLayer({ /* ... surrounding polygons, fill #e5e5e5, opacity 0.3 */ }),
                // Selected DeSO (colored by score)
                new VectorLayer({ /* ... main polygon, fill = scoreToColor(score), opacity 0.5 */ }),
                // School markers
                new VectorLayer({ /* ... circle markers colored by merit value */ }),
                // Pin
                new VectorLayer({ /* ... pin icon at exact address */ }),
            ],
            view: new View({
                center: fromLonLat([mapData.center[1], mapData.center[0]]),
                zoom: mapData.zoom,
            }),
        });

        return () => map.setTarget(undefined);
    }, [mapData]);

    return (
        <div
            ref={mapRef}
            className="w-full h-[300px] rounded-lg overflow-hidden border"
            style={{ pointerEvents: 'none' }} // Extra safety: no interaction
        />
    );
}
```

### 3.3 Snapshot the DeSO Polygon

The report must store the GeoJSON at generation time so it renders correctly even if DeSO boundaries are updated later.

```php
private function getDesoGeoJson(string $desoCode): array
{
    $row = DB::selectOne("
        SELECT ST_AsGeoJSON(ST_SimplifyPreserveTopology(geom, 0.0001)) as geojson
        FROM deso_areas
        WHERE deso_code = ?
    ", [$desoCode]);

    return json_decode($row->geojson, true);
}

private function getSurroundingDesosGeoJson(string $desoCode): array
{
    // Get neighboring DeSOs that touch or are within 500m
    $rows = DB::select("
        SELECT d2.deso_code,
               ST_AsGeoJSON(ST_SimplifyPreserveTopology(d2.geom, 0.0002)) as geojson
        FROM deso_areas d1
        JOIN deso_areas d2 ON ST_DWithin(d1.geom, d2.geom, 0.005)
        WHERE d1.deso_code = ?
          AND d2.deso_code != ?
        LIMIT 20
    ", [$desoCode, $desoCode]);

    return collect($rows)->map(fn($r) => [
        'deso_code' => $r->deso_code,
        'geojson' => json_decode($r->geojson, true),
    ])->toArray();
}
```

---

## Step 4: Report Page Layout

### 4.1 Route

```php
Route::get('/reports/{uuid}', [ReportController::class, 'show'])->name('reports.show');
```

```php
public function show(string $uuid)
{
    $report = Report::where('uuid', $uuid)
        ->where('status', 'active')
        ->firstOrFail();

    $report->increment('view_count');

    return Inertia::render('Reports/Show', [
        'report' => $report,
    ]);
}
```

### 4.2 Page Component Structure

```tsx
// resources/js/Pages/Reports/Show.tsx

export default function ReportShow({ report }: { report: ReportData }) {
    return (
        <div className="min-h-screen bg-muted/30 print:bg-white">
            {/* Sticky nav — minimal, just logo + back */}
            <ReportNav reportId={report.uuid} />

            <main className="max-w-4xl mx-auto px-4 py-8 space-y-8 print:space-y-6">

                {/* 1. Header */}
                <ReportHeader report={report} />

                {/* 2. Hero Score */}
                <ReportHeroScore report={report} />

                {/* 3. Map Snapshot */}
                <ReportMap mapData={report.map_snapshot} score={report.default_score} />

                {/* 4. Category Verdict Cards — the quick overview */}
                <ReportVerdictGrid verdicts={report.category_verdicts} />

                {/* 5. Full Indicator Breakdown — the deep dive */}
                <ReportIndicatorBreakdown
                    indicators={report.area_indicators}
                    priorities={report.priorities}
                />

                {/* 6. School Detail — per-school cards */}
                <ReportSchoolSection
                    schools={report.schools}
                    proximity={report.proximity_factors}
                />

                {/* 7. Strengths & Weaknesses */}
                <ReportStrengthsWeaknesses
                    positive={report.top_positive}
                    negative={report.top_negative}
                />

                {/* 8. Outlook */}
                <ReportOutlook report={report} />

                {/* 9. Methodology & Sources */}
                <ReportMethodology report={report} />

            </main>
        </div>
    );
}
```

---

## Step 5: Section Designs

### 5.1 Header

Clean, informational. Not a hero banner — the score section handles the visual impact.

```
Områdesrapport

Sveavägen 42, Stockholm
Stockholms kommun · Stockholms län
DeSO: Södermalm östra (0180C4130) · 0,82 km² · 2 413 invånare

Genererad 9 feb 2026 · Rapport abc12345
Dataunderlag: 2024 · 27 indikatorer · 5 datakällor
```

```tsx
function ReportHeader({ report }: { report: ReportData }) {
    return (
        <header className="space-y-1">
            <p className="text-sm font-medium text-muted-foreground uppercase tracking-wide">
                Områdesrapport
            </p>
            <h1 className="text-2xl font-bold">{report.address}</h1>
            <p className="text-muted-foreground">
                {report.kommun_name} · {report.lan_name}
            </p>
            <p className="text-sm text-muted-foreground">
                DeSO: {report.deso_meta.deso_name} ({report.deso_code})
                {' · '}{report.deso_meta.area_km2} km²
                {' · '}{report.deso_meta.population?.toLocaleString('sv-SE')} invånare
            </p>
            <div className="flex gap-4 text-xs text-muted-foreground pt-2 border-t mt-4">
                <span>Genererad {formatDate(report.created_at)}</span>
                <span>·</span>
                <span>Rapport {report.uuid.slice(0, 8)}</span>
                <span>·</span>
                <span>{report.indicator_count} indikatorer</span>
                <span>·</span>
                <span>Dataår {report.year}</span>
            </div>
        </header>
    );
}
```

### 5.2 Hero Score

The visual centerpiece. Big number, sparkline, trend, personalization note.

```
┌──────────────────────────────────────────────────────────────────┐
│                                                                  │
│           ┌───────────────────────┐                              │
│           │                       │     Stabilt / Positivt       │
│           │         72            │     ↑ +3,2 vs 2023           │
│           │                       │                              │
│           └───────────────────────┘     ▁▂▃▄▅▆▇█                │
│                                         '19  '21  '23  '24      │
│                                                                  │
│  ── Dina prioriteringar ──                                       │
│  🏫 Skolkvalitet · 🚇 Kollektivtrafik · 🌳 Grönområden         │
│  Personlig poäng: 78 (+6 jämfört med standardpoäng 72)          │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘
```

The score circle uses the centralized `scoreToColor()`. The sparkline shows 2019-2024 composite trajectory. If the user's personalized score differs from the default, show both.

```tsx
function ReportHeroScore({ report }: { report: ReportData }) {
    const diff = (report.personalized_score ?? report.default_score) - report.default_score;

    return (
        <Card className="overflow-hidden">
            <CardContent className="p-8">
                <div className="flex items-center gap-8">
                    {/* Big score circle */}
                    <div
                        className="w-28 h-28 rounded-full flex items-center justify-center text-white text-4xl font-bold shrink-0"
                        style={{ backgroundColor: scoreToColor(report.default_score) }}
                    >
                        {Math.round(report.personalized_score ?? report.default_score)}
                    </div>

                    <div className="space-y-2">
                        <div>
                            <p className="text-lg font-semibold">
                                {scoreToLabel(report.default_score)}
                            </p>
                            {report.trend_1y != null && (
                                <p className="text-sm">
                                    <TrendArrow change={report.trend_1y} direction="positive" size="md" />
                                    {' '}vs {report.year - 1}
                                </p>
                            )}
                        </div>

                        {/* Sparkline */}
                        {report.score_history?.length >= 2 && (
                            <Sparkline
                                values={report.score_history.map(h => h.score)}
                                years={report.score_history.map(h => h.year)}
                                width={180}
                                height={32}
                            />
                        )}
                    </div>
                </div>

                {/* Personalization note */}
                {report.priorities?.length > 0 && (
                    <div className="mt-6 pt-4 border-t">
                        <p className="text-sm text-muted-foreground">
                            Baserat på dina prioriteringar:{' '}
                            {report.priorities.map(p => priorityLabels[p]).join(' · ')}
                        </p>
                        {diff !== 0 && (
                            <p className="text-sm mt-1">
                                Personlig poäng: {Math.round(report.personalized_score)}
                                {' '}
                                <span className={diff > 0 ? 'text-green-600' : 'text-red-600'}>
                                    ({diff > 0 ? '+' : ''}{diff.toFixed(0)} jämfört med standard {Math.round(report.default_score)})
                                </span>
                            </p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
```

### 5.3 Category Verdict Cards

A grid of 4-5 cards, one per category. Each card shows: emoji, name, letter grade in a colored circle, 2-3 sentence verdict text, trend direction. This is the "quick scan" — the user reads these first before diving into individual indicators.

```
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ 🛡️ Trygghet     │  │ 💰 Ekonomi      │  │ 🏫 Utbildning   │
│                  │  │                  │  │                  │
│     B            │  │     A            │  │     B            │
│   62:a pctl      │  │   81:a pctl      │  │   67:a pctl      │
│   ↗ Förbättras   │  │   → Stabil       │  │   ↑ Förbättras   │
│                  │  │                  │  │                  │
│ Genomsnittlig    │  │ Stark ekonomi    │  │ God skolkvalitet │
│ brottslighet     │  │ med hög median-  │  │ med stigande     │
│ med sjunkande    │  │ inkomst och      │  │ meritvärden.     │
│ trend.           │  │ sysselsättning.  │  │                  │
└─────────────────┘  └─────────────────┘  └─────────────────┘

┌─────────────────┐  ┌─────────────────┐
│ 🌳 Miljö &      │  │ 📍 Plats-       │
│    service       │  │    analys        │
│                  │  │                  │
│     A            │  │     B            │
│   85:a pctl      │  │   71 poäng       │
│   → Stabil       │  │                  │
│                  │  │                  │
│ Utmärkt till-    │  │ God tillgång     │
│ gång till butik- │  │ till skola och   │
│ er, vård och     │  │ kollektivtrafik  │
│ grönområden.     │  │ inom gångavstånd │
└─────────────────┘  └─────────────────┘
```

```tsx
function ReportVerdictGrid({ verdicts }: { verdicts: Record<string, CategoryVerdict> }) {
    const categoryOrder = ['safety', 'economy', 'education', 'environment', 'proximity'];

    return (
        <section>
            <h2 className="text-lg font-semibold mb-4">Sammanfattning per kategori</h2>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 print:grid-cols-3">
                {categoryOrder.map(key => {
                    const v = verdicts[key];
                    if (!v) return null;
                    return (
                        <Card key={key} className="p-4 space-y-3">
                            <div className="flex items-center gap-2">
                                <span className="text-lg">{v.emoji}</span>
                                <span className="text-sm font-medium">{v.label}</span>
                            </div>

                            <div className="flex items-center gap-3">
                                <div
                                    className="w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-bold"
                                    style={{ backgroundColor: v.color }}
                                >
                                    {v.grade}
                                </div>
                                <div>
                                    <p className="text-sm">{v.score}:e percentilen</p>
                                    <p className="text-xs text-muted-foreground">
                                        {trendLabel(v.trend_direction)}
                                    </p>
                                </div>
                            </div>

                            <p className="text-xs text-muted-foreground leading-relaxed">
                                {v.verdict_sv}
                            </p>

                            <p className="text-[10px] text-muted-foreground">
                                Baserat på {v.indicator_count} indikatorer
                            </p>
                        </Card>
                    );
                })}
            </div>
        </section>
    );
}
```

### 5.4 Full Indicator Breakdown

This is the meat of the report — what the user paid for. Every indicator, organized by category. Each shows: name, percentile bar, raw value, trend arrow, sparkline.

```
── Ekonomi & arbetsmarknad ──────────────────────────────── Betyg: A

  Medianinkomst           ████████░░  78:e pctl  ↑ +3   287 000 kr
                          ▁▂▃▃▄▅▆█  (2019-2024)

  Sysselsättningsgrad     ██████░░░░  61:a pctl  → 0    72,3 %
                          ▄▄▄▄▄▅▅▅  (2019-2024)

  Låg ekonomisk standard  █████████░  88:e pctl  ↗ +2   8,1 %
                          ▅▅▆▆▇▇▇█  (2019-2024)
                          (Lägre = bättre. 88:e percentilen innebär lägre
                           andel med låg ekonomisk standard än 88% av DeSO.)

  Skuldkvot               ████████░░  76:e pctl  → 0    3,2 %
                          ▅▅▅▅▅▅▆▇  (2019-2024)

  Vräkningsgrad           █████████░  92:a pctl  ↗ +1   0,4 per 100k
                          ▇▇▇▇▇▇▇█  (2019-2024)

  Medianskuld             ███████░░░  68:e pctl  ↘ -2   42 000 kr
                          ▇▆▆▅▅▅▄▃  (2019-2024)
```

Note: for `negative` direction indicators, add a clarifying note below the bar: "Lägre = bättre" so the user understands why 88th percentile for low economic standard is green.

```tsx
function ReportIndicatorBreakdown({
    indicators,
    priorities,
}: {
    indicators: SnapshotIndicator[];
    priorities: string[];
}) {
    // Group by category
    const grouped = groupBy(indicators, 'category');
    const categoryOrder = ['safety', 'economy', 'education', 'environment'];

    return (
        <section className="space-y-8">
            <h2 className="text-lg font-semibold">Detaljerad indikatoranalys</h2>

            {categoryOrder.map(cat => {
                const catIndicators = grouped[cat];
                if (!catIndicators?.length) return null;

                const catLabel = categoryLabels[cat];
                const catVerdict = verdicts[cat];

                return (
                    <div key={cat} className="space-y-4">
                        <div className="flex items-center justify-between border-b pb-2">
                            <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                {catLabel}
                            </h3>
                            {catVerdict && (
                                <span
                                    className="text-xs font-medium px-2 py-0.5 rounded-full text-white"
                                    style={{ backgroundColor: catVerdict.color }}
                                >
                                    Betyg: {catVerdict.grade}
                                </span>
                            )}
                        </div>

                        {catIndicators.map(ind => (
                            <ReportIndicatorRow key={ind.slug} indicator={ind} />
                        ))}
                    </div>
                );
            })}
        </section>
    );
}

function ReportIndicatorRow({ indicator }: { indicator: SnapshotIndicator }) {
    const isNegative = indicator.direction === 'negative';

    return (
        <div className="space-y-1 py-2">
            {/* Row 1: Name + bar + percentile + trend + value */}
            <div className="flex items-center gap-3">
                <span className="text-sm w-48 shrink-0">{indicator.name}</span>
                <IndicatorBar percentile={indicator.percentile} className="flex-1" />
                <span className="text-sm tabular-nums w-16 text-right">
                    {indicator.percentile}:e
                </span>
                <TrendArrow
                    change={indicator.trend.change_1y}
                    direction={indicator.direction}
                />
                <span className="text-sm text-muted-foreground w-24 text-right tabular-nums">
                    {indicator.formatted_value}
                </span>
            </div>

            {/* Row 2: Sparkline + clarification for negative indicators */}
            <div className="flex items-center gap-3 pl-48">
                {indicator.trend.percentiles?.length >= 2 && (
                    <Sparkline
                        values={indicator.trend.percentiles}
                        years={indicator.trend.years}
                        width={160}
                        height={20}
                    />
                )}
                {isNegative && (
                    <span className="text-[10px] text-muted-foreground italic">
                        Lägre = bättre för området
                    </span>
                )}
            </div>

            {/* Row 3: National reference */}
            {indicator.national_median != null && (
                <p className="text-[10px] text-muted-foreground pl-48">
                    Riksgenomsnitt: {indicator.national_median_formatted}
                </p>
            )}
        </div>
    );
}
```

### 5.5 School Detail Section

Per-school cards with full statistics. Only show schools within the proximity radius (~2km). Ordered by distance from pin.

```
── Skolor i närheten (5 grundskolor inom 2 km) ─────────────────

  ┌────────────────────────────────────────────────────────────┐
  │  🏫 Årstaskolan                                  200 m    │
  │  Grundskola F-9 · Kommunal (Stockholms kommun)            │
  │                                                            │
  │  Meritvärde (17 ämnen)  ████████░░  241    (85:e pctl)    │
  │  Måluppfyllelse          █████████░  94 %   (91:a pctl)   │
  │  Lärarbehörighet         ███████░░░  78 %   (52:a pctl)   │
  │  Antal elever: 342                                         │
  │                                                            │
  │  Trend: Meritvärdet stigit från 228 till 241 (2021→2025)  │
  └────────────────────────────────────────────────────────────┘

  ┌────────────────────────────────────────────────────────────┐
  │  🏫 Eriksdalsskolan                              850 m    │
  │  Grundskola F-6 · Kommunal (Stockholms kommun)            │
  │                                                            │
  │  Meritvärde (17 ämnen)  —  (skolan har ej åk 9)           │
  │  Måluppfyllelse          —                                 │
  │  Lärarbehörighet         ████████░░  82 %   (61:a pctl)   │
  │  Antal elever: 280                                         │
  └────────────────────────────────────────────────────────────┘
```

For schools without year-9 (F-6 schools), merit value and goal achievement aren't applicable. Show "—" with an explanatory note.

The school section is separate from the indicator breakdown because it's per-school (point data), not per-DeSO (area data). This is the kind of granularity you can't get from the free tier.

### 5.6 Strengths & Weaknesses

Bullet list distilled from the top/bottom indicators. Written as actionable statements, not raw data.

```
── Styrkor ──────────────────────────────────────────────────────

  ✅ Hög skolkvalitet i närheten — Årstaskolan (241 meritvärde)
     ligger i topp 15 % nationellt, bara 200 m bort.

  ✅ Stigande medianinkomst — inkomsten ökat med 19 % sedan 2019
     och rankas nu i 78:e percentilen.

  ✅ Utmärkt tillgång till grönområden — Tantolunden 120 m,
     Eriksdalsbadet 400 m.

  ✅ God kollektivtrafik — T-bana (Zinkensdamm) inom 350 m,
     3 busslinjer inom 200 m.


── Svagheter ────────────────────────────────────────────────────

  ⚠️ Sysselsättningsgrad under genomsnittet — 72,3 % mot
     riksmedian 82,5 %. Rankas i 61:a percentilen.

  ⚠️ Lärarbehörighet kan förbättras — 78 % behöriga lärare
     på Årstaskolan, under riksgenomsnittet på 83 %.

  ⚠️ Hög andel hyresrätter — 67 % hyresrätter begränsar
     potential för prisutveckling på bostadsrätter.
```

```php
// In ReportGenerationService — generate strengths/weaknesses

private function generateStrengths(array $indicators, array $schools, array $proximity): array
{
    $strengths = [];

    // Top area indicators (percentile >= 75)
    foreach ($indicators as $ind) {
        if ($ind['direction'] === 'neutral') continue;
        $directedPctl = $ind['direction'] === 'negative'
            ? 100 - $ind['percentile']
            : $ind['percentile'];

        if ($directedPctl >= 75) {
            $strengths[] = [
                'category' => $ind['category'],
                'slug' => $ind['slug'],
                'text_sv' => $this->strengthText($ind),
                'percentile' => $directedPctl,
            ];
        }
    }

    // Nearby high-quality schools
    $topSchool = collect($schools)
        ->filter(fn($s) => ($s['merit_value'] ?? 0) >= 230)
        ->sortBy('distance_m')
        ->first();

    if ($topSchool) {
        $strengths[] = [
            'category' => 'education',
            'slug' => 'school_nearby',
            'text_sv' => "Hög skolkvalitet i närheten — {$topSchool['name']} ({$topSchool['merit_value']} meritvärde) ligger i topp " . $this->meritToTopPct($topSchool['merit_value']) . " % nationellt, bara " . round($topSchool['distance_m']) . " m bort.",
            'percentile' => 90,
        ];
    }

    // Sort by percentile descending, take top 5
    usort($strengths, fn($a, $b) => $b['percentile'] <=> $a['percentile']);
    return array_slice($strengths, 0, 5);
}

// Mirror logic for weaknesses (directed percentile <= 35)
```

### 5.7 Outlook Section

A forward-looking assessment synthesizing trends. Template-driven, not AI-generated.

```
── Utsikter ─────────────────────────────────────────────────────

  Områdets totalpoäng har stigit stadigt från 61 (2019) till 72
  (2024), en ökning med 11 poäng på 5 år. Denna trend drivs
  främst av stigande inkomster och förbättrad skolkvalitet.

  3 av 5 kategorier visar förbättring senaste året. Ekonomi och
  utbildning är starkast. Sysselsättningen är den svagaste
  punkten men stabil.

  Baserat på historiska mönster tyder områdets profil på
  fortsatt positiv utveckling. Områden med liknande profiler
  har i genomsnitt ökat i poäng med 2-4 enheter per år.

  ⚠️ Detta är en statistisk uppskattning baserad på historiska
  data. Inte finansiell rådgivning. Lokala faktorer som ny-
  byggnation, infrastrukturprojekt och politiska beslut kan
  påverka utvecklingen avsevärt.
```

```php
private function generateOutlook(array $scoreHistory, array $verdicts, array $indicators): array
{
    $years = count($scoreHistory);
    $firstScore = $scoreHistory[0]['score'] ?? null;
    $lastScore = $scoreHistory[count($scoreHistory) - 1]['score'] ?? null;
    $totalChange = $lastScore && $firstScore ? $lastScore - $firstScore : null;

    // Count improving categories
    $improving = collect($verdicts)->filter(fn($v) => $v['trend_direction'] === 'improving')->count();
    $declining = collect($verdicts)->filter(fn($v) => $v['trend_direction'] === 'declining')->count();
    $total = collect($verdicts)->count();

    // Determine overall outlook
    $outlook = match(true) {
        $improving >= 3 && $declining === 0 => 'strong_positive',
        $improving >= 2 => 'positive',
        $declining >= 3 => 'negative',
        $declining >= 2 => 'cautious',
        default => 'neutral',
    };

    return [
        'outlook' => $outlook,
        'outlook_label' => match($outlook) {
            'strong_positive' => 'Starkt positiv',
            'positive' => 'Positiv',
            'neutral' => 'Neutral',
            'cautious' => 'Viss osäkerhet',
            'negative' => 'Utmanande',
        },
        'total_change' => $totalChange ? round($totalChange, 1) : null,
        'years_span' => $years,
        'improving_count' => $improving,
        'declining_count' => $declining,
        'total_categories' => $total,
        'text_sv' => $this->generateOutlookText($scoreHistory, $outlook, $improving, $declining, $total, $totalChange),
        'disclaimer' => 'Detta är en statistisk uppskattning baserad på historiska data. Inte finansiell rådgivning. Lokala faktorer som nybyggnation, infrastrukturprojekt och politiska beslut kan påverka utvecklingen avsevärt.',
    ];
}
```

### 5.8 Methodology & Sources

The last section. Builds trust and covers legal requirements.

```
── Metod & datakällor ───────────────────────────────────────────

  Denna rapport bygger på 27 indikatorer från 5 offentliga
  datakällor, aggregerade till DeSO-nivå (demografiska
  statistikområden, ca 700-3 000 invånare per område).

  ┌──────────────────────────────────────────────────────────┐
  │ Källa          │ Antal indikatorer │ Senaste data        │
  │────────────────│───────────────────│─────────────────────│
  │ SCB            │ 8                 │ 2024                │
  │ Skolverket     │ 3                 │ 2024/25             │
  │ BRÅ            │ 3                 │ 2024                │
  │ Kolada         │ 3                 │ 2024                │
  │ OpenStreetMap  │ 8                 │ Feb 2026            │
  │ NTU            │ 1                 │ 2024                │
  │ Polisen        │ 1                 │ 2025                │
  └──────────────────────────────────────────────────────────┘

  Poängen beräknas som ett viktat genomsnitt av percentilrankning
  per indikator. Områdespoäng (70 %) baseras på DeSO-aggregerade
  data. Närhetspoäng (30 %) baseras på avstånd till specifika
  platser från den exakta adressen.

  All data är offentlig statistik på aggregerad nivå. Inga
  personuppgifter eller individuella data lagras eller behandlas.

  Beräkningsmodell: v1.0
  Data senast uppdaterad: 2026-01-15
```

---

## Step 6: Print & Share

### 6.1 Print Styles

The report should look good when printed or saved as PDF. Add print-specific Tailwind classes:

```tsx
// In the main layout
<main className="max-w-4xl mx-auto px-4 py-8 space-y-8 print:space-y-4 print:px-0 print:max-w-none">

// Hide nav and non-essential UI when printing
<ReportNav className="print:hidden" />

// Page breaks before major sections
<section className="print:break-before-page"> // Before school section
```

### 6.2 Share Functionality

The report URL (`/reports/{uuid}`) is already shareable — no auth required to view. Add:

```tsx
function ShareButton({ uuid }: { uuid: string }) {
    const url = `${window.location.origin}/reports/${uuid}`;

    const copyToClipboard = async () => {
        await navigator.clipboard.writeText(url);
        toast('Länk kopierad!');
    };

    return (
        <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={copyToClipboard}>
                Kopiera länk
            </Button>
            <Button variant="outline" size="sm" onClick={() => window.print()}>
                Skriv ut / Spara PDF
            </Button>
        </div>
    );
}
```

---

## Step 7: Value Formatting

### 7.1 Swedish Number Formatting

All values in the report use Swedish formatting conventions:

```tsx
function formatValue(value: number | null, unit: string): string {
    if (value === null) return '—';

    switch (unit) {
        case 'SEK':
            return new Intl.NumberFormat('sv-SE').format(Math.round(value)) + ' kr';
        case 'percent':
            return value.toFixed(1).replace('.', ',') + ' %';
        case 'per_1000':
            return value.toFixed(1).replace('.', ',') + ' per 1 000';
        case 'points':
            return Math.round(value).toString();
        case 'number':
            return new Intl.NumberFormat('sv-SE').format(Math.round(value));
        default:
            return value.toFixed(1).replace('.', ',');
    }
}
```

### 7.2 Percentile Ordinal Formatting

Swedish ordinal suffixes for percentiles:

```tsx
function formatPercentile(pctl: number): string {
    // Swedish: 1:a, 2:a, 3:e, 4:e, ..., 78:e, 91:a, 92:a, 100:e
    const suffix = [1, 2].includes(pctl % 10) && ![11, 12].includes(pctl % 100) ? ':a' : ':e';
    return `${pctl}${suffix}`;
}
```

---

## Verification

### Data Integrity
- [ ] All snapshotted values match what the sidebar shows for the same DeSO
- [ ] Trend sparklines match the indicator_values table history
- [ ] School distances are correct (verify one against Google Maps)
- [ ] Category verdicts are consistent with the individual indicator values they aggregate
- [ ] National references are plausible (median income ~265k, employment ~82%)

### Visual Checklist
- [ ] Report page loads at `/reports/{uuid}` with all 9 sections
- [ ] Hero score circle is colored correctly (using centralized color function)
- [ ] Score sparkline shows 2019-2024 trajectory
- [ ] Map snapshot renders with DeSO polygon, pin, school markers
- [ ] Map is non-interactive (no pan/zoom)
- [ ] Category verdict cards show letter grades A-E with correct colors
- [ ] All indicators show name, bar, percentile, trend arrow, raw value, sparkline
- [ ] Negative indicators show "Lägre = bättre" clarification
- [ ] School cards show full statistics with distance
- [ ] Schools without year-9 show "—" for merit/goal, not 0
- [ ] Strengths and weaknesses are written as Swedish sentences, not raw data
- [ ] Outlook section synthesizes trends into forward-looking text
- [ ] Methodology section lists all sources with indicator counts and data dates
- [ ] All numbers use Swedish formatting (287 000 kr, 72,3 %, komma as decimal separator)
- [ ] Percentile ordinals are correct (1:a, 2:a, 3:e, 78:e)
- [ ] Print view renders cleanly (Ctrl+P → looks good)
- [ ] Share link copies the correct URL
- [ ] Report persists — close browser, reopen URL, report is still there
- [ ] View count increments on each visit

### Edge Cases
- [ ] DeSO with no schools shows "Inga skolor inom 2 km" with nearest school info
- [ ] DeSO with no historical data shows flat sparkline / "—" for trends
- [ ] Indicator with NULL raw_value shows "Inga data" not "0"
- [ ] Very long school names don't break layout
- [ ] Report for rural DeSO (sparse data, few POIs) still renders all sections

---

## What NOT to Do

- **DO NOT render the report from live data.** Everything comes from the `reports` table snapshot. If we recompute scores tomorrow, existing reports stay unchanged.
- **DO NOT use AI/LLM to generate verdict text.** Template-driven text is predictable, fast, and free. The templates combine data points into readable Swedish sentences. No API calls during rendering.
- **DO NOT make the map interactive.** It's a snapshot — non-interactive OpenLayers with no controls, no interactions. The user has the live map on the main page.
- **DO NOT show indicator weights in the report.** Users don't need to know that median_income has weight 0.20. They see the percentile, the bar, the trend. The weighting is internal methodology.
- **DO NOT translate to English yet.** The report is Swedish-first. English comes with the i18n task. All user-facing text in this task is in Swedish.
- **DO NOT add PDF generation.** Browser print (Ctrl+P → Save as PDF) is good enough for v1. Native PDF generation (using DomPDF or Puppeteer) is a future optimization.
- **DO NOT gate report viewing behind auth.** Anyone with the UUID link can view. This is intentional — reports are shareable. The payment gate is at *generation* time, not viewing time.