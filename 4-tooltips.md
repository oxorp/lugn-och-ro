# TASK: Score Explanation Tooltips — Make Every Data Point Self-Documenting

## Context

The sidebar shows "Median Income: 78th percentile (287,000 SEK)" — but a normal person doesn't know what this means. What's the 78th percentile relative to? When was this measured? When was it last updated? Is 287,000 SEK good or bad? What does this indicator actually measure?

Every number on the screen should be one hover/tap away from a clear, human-readable explanation. This task adds contextual tooltips to all score-related UI elements: the composite score, each indicator bar, trend arrows, and school statistics.

**Principle: No number without context.** If a user sees a number, they should be able to understand it without leaving the page. The tooltip is the "explain it to me like I'm buying my first apartment" layer.

---

## Step 1: Define What Needs Explanation

### 1.1 Elements That Need Tooltips

Every data-bearing element in the sidebar gets a tooltip. Here's the complete inventory:

| Element | Current display | What user needs to know |
|---|---|---|
| Composite score (72) | Big number + color | What does 72 mean? What's the range? How is it computed? |
| Score label ("Stable / Positive Outlook") | Text badge | What defines this category? What are the thresholds? |
| Trend arrow (↑ +3.2) | Arrow + number | What changed, over what period, is this significant? |
| Indicator bar (Median Income: 78th, 287,000 SEK) | Bar + percentile + raw value | What does this indicator measure? What's the national average? When was this data collected? When did we last refresh it? |
| School card (Meritvärde: 241) | Number in school card | What is meritvärde? What's the scale? What's average? |
| "No data" states | Gray bar or "—" | Why is data missing? When might it become available? |

### 1.2 Two Levels of Explanation

Each element gets **two** levels:

**Level 1 — Inline hint (always visible):**
Small info icon (ⓘ) next to the element. Subtle, slate-400 color, 14px. Doesn't clutter the UI.

**Level 2 — Tooltip (on hover/tap):**
A popover that appears on hover (desktop) or tap (mobile). Contains the full explanation. Disappears when the user moves away or taps elsewhere.

---

## Step 2: Tooltip Content Schema

### 2.1 Data Model Extension

Each indicator in the database needs explanation metadata. Extend the `indicators` table:

```php
Schema::table('indicators', function (Blueprint $table) {
    $table->text('description_short')->nullable();      // One-line: "How much households earn after tax"
    $table->text('description_long')->nullable();        // 2-3 sentences explaining the indicator
    $table->text('methodology_note')->nullable();        // How it's measured: "Median of all individuals aged 20+ in the area"
    $table->string('national_context')->nullable();      // "National average: 248,000 SEK (2024)"
    $table->string('data_vintage')->nullable();          // "2024" — the year the data describes
    $table->timestamp('data_last_ingested_at')->nullable(); // When we last pulled from the source
    $table->string('source_name')->nullable();           // "Statistics Sweden (SCB)"
    $table->string('source_url')->nullable();            // "https://www.scb.se/..."
    $table->string('update_frequency')->nullable();      // "Annually (published Q1)"
});
```

### 2.2 Seed the Explanations

Populate via seeder or migration. Here are the exact texts for each current indicator:

**median_income:**
```
description_short: "Median disposable income per person"
description_long: "The median annual disposable income (after taxes and transfers) for individuals aged 20+ living in this area. This captures the economic standing of the typical resident — not skewed by a few very high or very low earners."
methodology_note: "Disposable income = earned income + capital income + transfers − taxes. Median = the middle value when all residents are ranked."
national_context: "National median: ~248,000 SEK (2024)"
source_name: "Statistics Sweden (SCB)"
source_url: "https://www.scb.se"
update_frequency: "Published annually, typically in Q1 for the previous year"
```

**low_economic_standard_pct:**
```
description_short: "Share of residents with low economic standard"
description_long: "The percentage of individuals whose household disposable income (adjusted for household size) falls below 60% of the national median. This is the EU standard definition of relative poverty risk."
methodology_note: "Uses the modified OECD equivalence scale to adjust for household composition."
national_context: "National average: ~14% (2024)"
source_name: "Statistics Sweden (SCB)"
update_frequency: "Published annually"
```

**employment_rate:**
```
description_short: "Share of working-age residents who are employed"
description_long: "The percentage of residents aged 16–64 who are gainfully employed. High employment means stable household finances, which supports mortgage payments and local business activity."
methodology_note: "Includes all forms of employment (full-time, part-time, self-employed). Measured in November each year."
national_context: "National average: ~68% (2024)"
source_name: "Statistics Sweden (SCB)"
update_frequency: "Published annually"
```

**education_post_secondary_pct:**
```
description_short: "Share of adults with university education"
description_long: "The percentage of residents aged 25–64 who have completed at least 3 years of post-secondary education (university degree or equivalent). A long-term predictor of area trajectory — highly educated populations attract employers and sustain higher incomes."
methodology_note: "Based on the Swedish education register (Utbildningsregistret). Includes both Swedish and foreign degrees."
national_context: "National average: ~29% (2024)"
source_name: "Statistics Sweden (SCB)"
update_frequency: "Published annually"
```

**education_below_secondary_pct:**
```
description_short: "Share of adults without upper secondary education"
description_long: "The percentage of residents aged 25–64 who have not completed gymnasieutbildning (upper secondary school). A higher share indicates economic vulnerability — these residents have limited access to the modern labor market."
methodology_note: "Includes individuals with only grundskola (9-year compulsory school) or less."
national_context: "National average: ~12% (2024)"
source_name: "Statistics Sweden (SCB)"
update_frequency: "Published annually"
```

**school_merit_value_avg:**
```
description_short: "Average final grades of local primary schools"
description_long: "The average meritvärde (merit value) across all grundskolor physically located in this area, weighted by student count. Meritvärde is computed from students' best 16 subject grades plus an optional 17th (moderna språk). It's the primary measure of school quality in Sweden and a major driver of where families choose to live."
methodology_note: "Sum of 16 best grades (A=20, B=17.5 ... F=0) + optional 17th subject. Maximum possible: 340. Only grundskola (years F-9) included in this score."
national_context: "National average: ~228 points (2024). Top schools: 270+. Struggling schools: <180."
source_name: "Swedish National Agency for Education (Skolverket)"
source_url: "https://www.skolverket.se"
update_frequency: "Published annually, typically in autumn"
```

**school_goal_achievement_avg:**
```
description_short: "Share of students achieving passing grades in all subjects"
description_long: "The average percentage of year-9 students who achieved at least grade E in all subjects, across all grundskolor in this area. This measures how well schools serve their entire student population, not just top performers."
national_context: "National average: ~76% (2024)"
source_name: "Swedish National Agency for Education (Skolverket)"
update_frequency: "Published annually"
```

**school_teacher_certification_avg:**
```
description_short: "Share of teachers with proper certification"
description_long: "The average percentage of teachers who are 'behöriga' (certified/qualified) to teach their assigned subjects. Higher certification rates correlate with better student outcomes and indicate a school that can attract qualified staff."
national_context: "National average: ~72% (2024)"
source_name: "Swedish National Agency for Education (Skolverket)"
update_frequency: "Published annually"
```

**foreign_background_pct / population / rental_tenure_pct:**
These are neutral indicators (weight 0, not scored). They still need tooltips explaining what they are, but the tooltip should note: "This indicator provides context but does not contribute to the composite score."

### 2.3 Composite Score Explanation

The composite score tooltip is special — it's not a single indicator. Content:

```
description_short: "Overall neighborhood trajectory score"
description_long: "A composite score from 0 to 100 that combines multiple indicators — income, employment, education, school quality, and more — into a single number. Each indicator is ranked against all ~6,160 areas in Sweden, then combined using weights based on their relevance to real estate outcomes. A score of 72 means this area outperforms roughly 72% of all areas nationwide."

Score ranges:
  80-100: Strong Growth Area
  60-79:  Stable / Positive Outlook
  40-59:  Mixed Signals
  20-39:  Elevated Risk
  0-19:   High Risk / Declining

"Last computed: [date]. Based on data from [year range]."
```

### 2.4 Trend Explanation

Trend arrows need context too:

```
"Change in this indicator over the past [N] years. Based on comparing [year] to [year] values.
↑ means the value has increased (by the shown percentage).
A green arrow means the change is positive for the area's score.
A red arrow means the change is negative."

If no trend available:
"Trend data not available for this area. This can happen when the area's boundaries changed between measurement periods."
```

---

## Step 3: API Extension

### 3.1 Include Metadata in Score Responses

Extend the DeSO/H3 score API to include indicator metadata:

```php
// In the score detail response (when a specific area is selected)
public function scoreDetail(string $identifier)
{
    // ... existing score lookup ...

    $indicators = Indicator::where('is_active', true)
        ->orderBy('display_order')
        ->get()
        ->map(fn ($ind) => [
            'slug' => $ind->slug,
            'name' => $ind->name,
            'description_short' => $ind->description_short,
            'description_long' => $ind->description_long,
            'methodology_note' => $ind->methodology_note,
            'national_context' => $ind->national_context,
            'source_name' => $ind->source_name,
            'source_url' => $ind->source_url,
            'update_frequency' => $ind->update_frequency,
            'data_vintage' => $ind->data_vintage,
            'data_last_ingested_at' => $ind->data_last_ingested_at?->toIso8601String(),
            'unit' => $ind->unit,
            'direction' => $ind->direction,
            'category' => $ind->category,
            // per-area values
            'raw_value' => $values[$ind->slug]->raw_value ?? null,
            'percentile' => $values[$ind->slug]->percentile ?? null,
            'trend' => $trends[$ind->slug] ?? null,
        ]);

    return response()->json([
        // ... existing fields ...
        'indicators' => $indicators,
        'score_computed_at' => $compositeScore->computed_at,
    ]);
}
```

### 3.2 Caching

The indicator metadata (descriptions, source info) is static — it only changes when we update the seed data. Cache it aggressively. The per-area values are already cached from the existing score endpoint. No new performance concerns.

---

## Step 4: Tooltip Component

### 4.1 InfoTooltip Component

Build a reusable tooltip component using shadcn's `Tooltip` or `Popover`:

```tsx
interface InfoTooltipProps {
  indicator: IndicatorMeta;
  children?: React.ReactNode;
}

function InfoTooltip({ indicator }: InfoTooltipProps) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button className="inline-flex items-center text-muted-foreground hover:text-foreground transition-colors ml-1">
          <InfoIcon className="h-3.5 w-3.5" />
        </button>
      </PopoverTrigger>
      <PopoverContent className="w-80 text-sm" side="left" align="start">
        <div className="space-y-2">
          <p className="font-medium">{indicator.name}</p>
          <p className="text-muted-foreground">{indicator.description_long}</p>

          {indicator.national_context && (
            <p className="text-xs text-muted-foreground border-l-2 border-muted pl-2">
              {indicator.national_context}
            </p>
          )}

          <div className="flex items-center gap-4 text-xs text-muted-foreground pt-1 border-t">
            <span>Source: {indicator.source_name}</span>
            <span>Data from: {indicator.data_vintage}</span>
          </div>

          {indicator.data_last_ingested_at && (
            <p className="text-xs text-muted-foreground">
              Last updated: {formatRelativeDate(indicator.data_last_ingested_at)}
            </p>
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}
```

### 4.2 Use Popover, Not Tooltip

shadcn's `Tooltip` disappears on mouse leave, which is fine for simple labels but bad for longer text that users might want to read. Use `Popover` instead — it stays open until the user clicks elsewhere or presses Escape. This also works better on mobile (tap to open, tap elsewhere to close).

### 4.3 Positioning

Popovers should open to the **left** of the info icon (toward the map) when in the sidebar, since the sidebar is on the right edge of the screen. Use `side="left"` to avoid the popover being cut off by the viewport edge.

On mobile (bottom sheet), popovers should open **above** the trigger: `side="top"`.

---

## Step 5: Apply Tooltips to All Elements

### 5.1 Indicator Bars

Current:
```
Median Income          ████████░░  78th (287,000 SEK)
```

New:
```
Median Income  ⓘ      ████████░░  78th (287,000 SEK)
```

The ⓘ icon appears after the indicator name. Clicking/hovering shows the full explanation.

### 5.2 Composite Score

Current:
```
72
Stable / Positive Outlook
```

New:
```
72  ⓘ
Stable / Positive Outlook
```

The ⓘ next to the score number. Tooltip explains the composite score methodology, shows the score range table, and the computation date.

### 5.3 Trend Arrows

Current:
```
↑ +3.2
```

New:
```
↑ +3.2  ⓘ
```

Tooltip explains: "Median Income increased by 3.2% from 2022 to 2024. This trend is based on 3 years of data from SCB."

### 5.4 School Cards

Each stat in the school card gets its own tooltip:

```
Meritvärde    ████████░░  241  ⓘ
Goal ach.     █████████░  94%  ⓘ
Teachers      ███████░░░  78%  ⓘ
Students      342
```

The meritvärde tooltip is especially important — most non-Swedish-education-system users have no idea what it means.

### 5.5 "No Data" States

When an indicator shows "—" or "No data":

```
School Quality    — No data  ⓘ
```

Tooltip: "No schools are located within this area. School quality data is based on grundskolor physically within the area boundary. The nearest school is [name], [distance] away."

Or: "Data for this indicator is not yet available for this area. This can happen for newly created statistical areas or areas where the source agency suppresses data for privacy reasons."

---

## Step 6: Data Freshness Display

### 6.1 Freshness in Sidebar Footer

At the bottom of the sidebar, below all indicators, add a subtle "Data freshness" section:

```
─────────────────────────────
Data sources:
  SCB Demographics    2024  •  Refreshed Jan 2025
  Skolverket Schools  2024  •  Refreshed Dec 2024
  Last score computation: Feb 1, 2025
```

This is always visible (not behind a tooltip). Small text, muted color. It answers the "is this up to date?" question without requiring any interaction.

### 6.2 Freshness Dot in Indicator Bars

Each indicator bar can optionally show a tiny colored dot indicating freshness:
- **Green dot (8px):** Data is from the most recent available year
- **Yellow dot:** Data is 1 year behind the most recent
- **Gray dot:** Data is 2+ years old or metadata missing

This is a subtle visual cue. Only show it if the data is NOT from the latest year (i.e., default is no dot = fresh). Only show yellow/gray dots as warnings.

### 6.3 How to Get the Dates

The `data_vintage` field on the indicator tells you which year the data describes. The `data_last_ingested_at` tells you when we last pulled it. The `computed_at` on composite_scores tells you when the score was last calculated.

Pass all three through the API response. The frontend decides what to show.

---

## Step 7: Mobile Behavior

### 7.1 Mobile Tooltips

On mobile, the info icon tap opens the same `Popover` but sized for the screen:
- Full width minus 32px padding
- Positioned above the trigger element
- Max height 300px with scroll if content is long
- Close button (×) in top-right of popover

### 7.2 Bottom Sheet Integration

In the bottom sheet on mobile, tooltips should not cause the bottom sheet to jump or resize. They overlay on top of the bottom sheet content.

---

## Step 8: Admin — Manage Explanations

### 8.1 Edit Explanations in Admin Dashboard

On the existing `/admin/indicators` page, add an "Edit" button for each indicator that opens a form to edit the explanation fields:

- description_short (input, max 100 chars)
- description_long (textarea, max 500 chars)
- methodology_note (textarea, max 300 chars)
- national_context (input, max 100 chars)
- source_name (input)
- source_url (input, URL)
- update_frequency (input)

This lets the admin refine explanations without code deploys. The texts are stored in the database and served via the API.

---

## Step 9: Verification

### 9.1 Checklist

- [ ] Every indicator bar in the sidebar has an ⓘ icon
- [ ] Clicking ⓘ opens a popover with description, national context, source, and dates
- [ ] Composite score has a tooltip explaining the methodology and score ranges
- [ ] Trend arrows have tooltips explaining the change period and significance
- [ ] School card stats have tooltips (especially meritvärde)
- [ ] "No data" states have explanatory tooltips
- [ ] Data freshness section appears at bottom of sidebar
- [ ] Popovers position correctly (left on desktop, top on mobile)
- [ ] Popovers don't get cut off by viewport edges
- [ ] Mobile: tap to open, tap elsewhere to close
- [ ] Admin can edit explanation texts on /admin/indicators
- [ ] All explanation fields are seeded with real content
- [ ] Source URLs are clickable links
- [ ] National context values are approximately correct

### 9.2 Content Review

Have someone unfamiliar with Swedish real estate read each tooltip and confirm they understand:
1. What the number means
2. Whether the value is good or bad relative to the country
3. Where the data comes from
4. How recent the data is

If any of these four questions can't be answered from the tooltip, the text needs work.

---

## Notes for the Agent

### The "National Context" Line Is Critical

The single most useful piece of information in each tooltip is the national context: "National average: 248,000 SEK." This immediately tells the user whether 287,000 is good (above average) or bad (below average). Without this anchor point, percentile ranks are meaningless to most users.

Pull national averages from the actual data when possible. For each indicator, compute the national average across all DeSOs (population-weighted if available) and store it in the `national_context` field. Update it annually when new data arrives.

### Don't Over-explain

Each tooltip should be readable in 5 seconds. If it takes longer, it's too long. The description_long field should be 2-3 sentences max. The methodology_note is for the curious — most users will skip it.

### Hierarchy of Information in Tooltip

1. **What it is** (description_short / description_long) — always shown first
2. **How this area compares** (national context) — the "anchor"
3. **Source + freshness** (source_name, data_vintage, last ingested) — builds trust
4. **Methodology** (methodology_note) — for the detail-oriented

### Integration with Comparison View

When the comparison sidebar is active (from the comparison task), each indicator already shows side-by-side bars. The ⓘ icon should still appear and show the same tooltip — the explanation doesn't change based on whether you're viewing one area or comparing two.

### What NOT to Do

- Don't use browser-native `title` attributes — they're ugly, delayed, and can't be styled
- Don't make tooltips appear on hover of the entire bar — only on the ⓘ icon (accidental hovers are annoying)
- Don't show tooltips automatically on page load or on first visit
- Don't include exact indicator weights in tooltips — that's protected IP (see methodology page task)
- Don't make tooltips blocking — they should always be dismissible and shouldn't prevent interaction with other elements