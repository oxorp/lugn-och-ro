# TASK: Methodology Page

## Context

The platform needs a public-facing page that explains how the Neighborhood Trajectory Score works. This serves three audiences simultaneously: homebuyers who need to trust the number, investors/banks doing due diligence, and journalists who might write about us. The page must feel transparent and authoritative without revealing the actual scoring weights, normalization methods, or disaggregation techniques that make the model work.

**The line we're walking:** Niche.com publishes exact factor weights (e.g., "Academics: 30%, Teachers: 15%") — that's too much. NeighborhoodScout says "proprietary algorithms" and nothing else — that's too little and invites criticism. We want the middle: name every data category, explain *why* it matters for real estate, cite the government sources by name, but never reveal how much each factor weighs or how they combine.

## Goals

1. Add a "Methodology" link to the navbar that leads to `/methodology`
2. Build a static Inertia page with structured content sections
3. English only (Swedish translation is a separate future task)
4. Tone: friendly, confident, backed by authority — not academic, not corporate-stiff

---

## Step 1: Navbar Update

Add a "Methodology" link to the existing navbar, positioned after the map/home link and before any admin links.

```
[Logo]  [Map]  [Methodology]  [Admin ▾]
```

Use a simple `<Link>` — no dropdown, no fancy treatment. It's a standard page.

---

## Step 2: Route & Controller

```php
Route::get('/methodology', [PageController::class, 'methodology'])->name('methodology');
```

Create `app/Http/Controllers/PageController.php` (or add to an existing static page controller):

```php
public function methodology()
{
    return Inertia::render('Methodology');
}
```

No data passed from the backend. This is a static content page.

---

## Step 3: Page Layout

Create `resources/js/Pages/Methodology.tsx`

The page should use the same app layout as the rest of the site (navbar at top). The content area is a **single-column readable layout** — not full-width like the map, but a centered content column (max-width ~720px) with comfortable reading typography.

Use shadcn components where it makes sense: `Card` for the data source boxes, `Accordion` for the FAQ, `Badge` for labels. But keep it mostly prose — this is a content page, not a dashboard.

### Visual Structure

```
┌──────────────────────────────────────────────────────────┐
│  Navbar                                                   │
├──────────────────────────────────────────────────────────┤
│                                                           │
│           ┌─────────────────────────────┐                │
│           │                             │                │
│           │  Hero section               │                │
│           │  "How the Score Works"      │                │
│           │  Brief intro paragraph      │                │
│           │                             │                │
│           ├─────────────────────────────┤                │
│           │                             │                │
│           │  "The Score at a Glance"    │                │
│           │  Simple visual/diagram      │                │
│           │                             │                │
│           ├─────────────────────────────┤                │
│           │                             │                │
│           │  Data Source cards           │                │
│           │  (one per category)         │                │
│           │                             │                │
│           ├─────────────────────────────┤                │
│           │                             │                │
│           │  "What the Score Means"     │                │
│           │  Score range table          │                │
│           │                             │                │
│           ├─────────────────────────────┤                │
│           │                             │                │
│           │  Principles / approach      │                │
│           │                             │                │
│           ├─────────────────────────────┤                │
│           │                             │                │
│           │  FAQ (accordion)            │                │
│           │                             │                │
│           ├─────────────────────────────┤                │
│           │                             │                │
│           │  Footer CTA                 │                │
│           │                             │                │
│           └─────────────────────────────┘                │
│                                                           │
└──────────────────────────────────────────────────────────┘
```

---

## Step 4: Content

This is the actual copy for the page. The agent should implement it close to verbatim — the phrasing is deliberate. Formatting (headings, emphasis) should be applied tastefully with Tailwind typography.

---

### Section 1: Hero

**Heading:** How the Neighborhood Trajectory Score Works

**Body:**

> Every neighborhood in Sweden receives a score from 0 to 100 that reflects its current conditions and likely trajectory over the coming years. The score combines data from dozens of sources across multiple dimensions — economics, education, safety, financial stability, amenities, and infrastructure — into a single number designed to help you understand where an area stands and where it's heading.
>
> We built this because no tool like it exists in Sweden. Hemnet shows you listings. Booli shows you prices. We show you *why* prices move.

---

### Section 2: The Score at a Glance

A simple visual element — either an illustrated diagram or styled HTML — showing the score as a funnel/pipeline:

```
  Data Sources
  ────────────────────────
  │  Government  │  Commercial  │  Open Data  │
  │  agencies    │  partners    │  platforms  │
  ────────────────────────
          ↓
    Normalization & Analysis
    (percentile ranking across
     all 6,160 areas in Sweden)
          ↓
    Weighted Composite Score
          ↓
     ┌──────────┐
     │  0 – 100 │
     └──────────┘
   Neighborhood Trajectory Score
```

**Don't** make this a complex infographic. A clean, minimal diagram using Tailwind-styled divs or a simple SVG. The point is to show: multiple inputs → analysis → single output.

Below the diagram, a single sentence:

> Each area is compared against every other area in Sweden using percentile ranking — meaning a score of 75 tells you this neighborhood outperforms roughly 75% of all areas nationwide on the factors we measure.

---

### Section 3: What We Measure

This is the core section. One card per data category. Each card has:
- Category name
- One-line summary of what it captures
- Why it matters for real estate (1-2 sentences, plain language)
- Official source name with link
- A small badge: "Updated annually" or "Updated monthly" etc.

**Do NOT reveal:** weights, exact indicator slugs, normalization method, direction logic, how categories combine, or how many indicators exist within each category.

**Card 1: Income & Economic Standing**

> **What we measure:** Household income levels and the prevalence of economic hardship across an area.
>
> **Why it matters:** Income is the single strongest predictor of property values. Areas with rising incomes tend to see rising demand, improving amenities, and increasing real estate prices. Areas with concentrated economic hardship often face the opposite trajectory.
>
> **Source:** Statistics Sweden (SCB) — Sweden's official statistics agency
> **Frequency:** Updated annually

**Card 2: Employment**

> **What we measure:** The share of working-age residents who are employed.
>
> **Why it matters:** High employment means stable household finances, which supports mortgage payments, local business activity, and community investment. Declining employment is often a leading indicator of neighborhood decline — it shows up in the data before it shows up in property prices.
>
> **Source:** Statistics Sweden (SCB)
> **Frequency:** Updated annually

**Card 3: Education — Demographics**

> **What we measure:** The educational attainment of residents — specifically, what share of the adult population has completed post-secondary education.
>
> **Why it matters:** Education levels shape an area's long-term economic trajectory. Neighborhoods with highly educated populations tend to attract employers, sustain higher incomes, and maintain demand for housing. This is a slow-moving but powerful signal.
>
> **Source:** Statistics Sweden (SCB)
> **Frequency:** Updated annually

**Card 4: School Quality**

> **What we measure:** The academic performance of primary schools (grundskolor) physically located within each area, based on standardized national metrics including final grades and teacher qualifications.
>
> **Why it matters:** In Sweden, school quality is arguably the single biggest driver of where families choose to live. Parents routinely pay significant premiums to live near high-performing schools. A neighborhood's school quality has a direct, measurable effect on property values — and it's one of the factors that changes fastest when an area improves or declines.
>
> **Source:** Swedish National Agency for Education (Skolverket)
> **Frequency:** Updated annually

**Card 5: Safety**

> **What we measure:** Reported crime rates, how residents perceive their own safety, and whether an area has been classified as vulnerable by Swedish Police.
>
> **Why it matters:** Safety is fundamental. High or rising crime depresses property values, discourages investment, and drives out residents who have the means to move. Perceived safety — how safe people *feel*, not just official statistics — matters just as much, because it drives behavior. We combine official crime data with Sweden's National Crime Survey, one of Europe's largest victimization studies.
>
> **Sources:** Swedish National Council for Crime Prevention (BRÅ), Swedish Police Authority (Polisen)
> **Frequency:** Crime statistics updated quarterly; survey data updated annually

**Card 6: Financial Distress**

> **What we measure:** The prevalence of debt enforcement, payment defaults, and evictions — the share of an area's residents who have unpaid debts serious enough to reach Sweden's Enforcement Authority.
>
> **Why it matters:** Financial distress is both a symptom and a cause. Areas with high rates of debt enforcement tend to see more forced property sales, deferred maintenance, and population turnover. Rising financial distress in an area is one of the clearest warning signs of a downward trajectory. Falling distress, conversely, suggests an area is stabilizing.
>
> **Source:** Swedish Enforcement Authority (Kronofogden)
> **Frequency:** Updated annually

**Card 7: Local Amenities & Services**

> **What we measure:** The availability of everyday services and amenities — grocery stores, healthcare, restaurants, fitness facilities, and other services that residents depend on, measured relative to the local population.
>
> **Why it matters:** Amenity access directly affects quality of life and property demand. An area with good grocery coverage, healthcare options, and dining is more attractive than one where residents must drive 30 minutes for basic errands. We measure availability per capita, so a rural area with a well-stocked ICA for its 1,500 residents scores just as well as a city block with three options for its 3,000.
>
> **Sources:** OpenStreetMap, Google Places, and specialized registries
> **Frequency:** Updated monthly

**Card 8: Transport & Connectivity**

> **What we measure:** Public transit accessibility, commute times to employment centers, and the availability of transport infrastructure.
>
> **Why it matters:** In Sweden's housing market, "30 minutes to Central Station" is one of the most common search criteria. Areas with improving transit connections — a new Pendeltåg station, extended bus routes, planned metro expansion — often see property values rise well before the infrastructure is completed. We measure both what exists today and what's planned.
>
> **Sources:** Regional transit authorities (SL, Västtrafik, Skånetrafiken), GTFS open data
> **Frequency:** Updated monthly

---

### Section 4: What the Score Means

A styled table or set of cards showing the score ranges:

| Score | Label | What it means |
|-------|-------|---------------|
| 80–100 | Strong Growth Area | Consistently strong across most factors. These areas typically have high demand, rising property values, and positive momentum. |
| 60–79 | Stable / Positive Outlook | Solid fundamentals with some areas of strength. Generally desirable and trending in a positive direction. |
| 40–59 | Mixed Signals | Some strengths, some concerns. These areas may be transitioning — either improving or facing early signs of decline. Worth investigating closely. |
| 20–39 | Elevated Risk | Multiple concerning signals across several factors. These areas may face declining demand or structural challenges. |
| 0–19 | High Risk / Declining | Significant challenges across most measured factors. High uncertainty about the area's near-term trajectory. |

Below the table:

> A score is not a verdict — it's a starting point. A score of 35 doesn't mean you shouldn't buy there; it means you should understand *why* it scores that way before you do. The factor breakdown for each area (available when you click on the map) shows exactly which dimensions are strong and which are weak, so you can make your own judgment.

---

### Section 5: Our Approach

**Heading:** How We Build the Score

A series of short principle statements. These explain the *philosophy* without revealing the *mechanics*:

> **Everything is relative, not absolute.**
> We don't score areas on an abstract scale. Every area is ranked against every other area in Sweden. A score of 73 means this area outperforms 73% of all neighborhoods in the country on the factors we measure. This makes scores directly comparable — whether you're looking at central Stockholm or rural Norrland.

> **We use the best available data — and we tell you where it comes from.**
> Our foundation is official Swedish government statistics: SCB, Skolverket, BRÅ, Kronofogden, and Polisen. These are the same statistics that policymakers and researchers rely on. Where government data has gaps or lacks granularity, we supplement it with commercial data sources, open platforms like OpenStreetMap, and specialized datasets covering everything from transit accessibility to local amenities. Every data category we score is documented on this page, and every area's detail view shows which sources contributed to its score.

> **We measure what matters for real estate.**
> Not every statistic matters equally for property values. We focus on the factors that research and market evidence show actually drive where people want to live and what they're willing to pay. The weighting of each factor in our model is based on its demonstrated relationship with real estate outcomes in the Swedish market.

> **We never use individual-level data.**
> All our inputs are aggregate statistics — averages, rates, percentages, and counts across entire areas. We do not access, store, or process any data about identifiable individuals. This is a deliberate design choice, both for legal compliance with GDPR and because aggregate patterns are what drive neighborhood-level trends.

> **The score updates as new data becomes available.**
> Government agencies publish new data on different schedules — some annually, some quarterly. When new data arrives, we re-run the model and the map updates. The "last updated" date for each data source is visible in the area detail view.

> **Boundaries are not walls.**
> Government statistics are published for defined geographic areas — but reality doesn't stop at a boundary line. A street that separates two statistical areas doesn't create a wall between them. Our model accounts for this by considering neighboring areas when computing scores, so that transitions between areas are gradual rather than abrupt. This reflects how neighborhoods actually work: the character of a place is shaped not just by what's inside its borders, but by what surrounds it.

---

### Section 6: FAQ (Accordion)

Use a shadcn `Accordion` component. Each question expands to reveal the answer.

**Q: How often does the score change?**
> The score updates whenever we receive new data from our government sources. In practice, this means most scores are recalculated at least once per year, with some components (like crime statistics) updating more frequently. Major shifts in a score usually reflect real changes on the ground — a new school opening, a significant change in employment, or a shift in crime trends.

**Q: Why does my area score low even though it feels nice?**
> The score reflects measurable data, not subjective impressions. An area might feel pleasant to live in but score lower because of, say, below-average school performance or higher-than-average financial distress rates. Conversely, an area might score high on data but have qualities you personally dislike that we don't measure — like lack of green space or long commute times. The score is one input to your decision, not the whole picture.

**Q: Do you factor in ethnicity or immigration status?**
> No. We do not use ethnicity, country of origin, immigration status, religious affiliation, or any demographic characteristic as a factor in the score. Our model measures economic outcomes (income, employment, education, financial stability), institutional quality (schools), and safety. These are the factors that research shows drive property values. Using demographic characteristics would be both legally problematic and methodologically unnecessary — economic indicators already capture the relevant signals.

**Q: How granular is the data?**
> Sweden is divided into approximately 6,160 DeSO areas (Demografiska statistikområden), each containing roughly 700–2,700 residents. These are the finest-grained statistical areas for which the Swedish government publishes data. Each DeSO gets its own score. This is far more granular than municipal or postal code-level analysis — within a single municipality like Stockholm, scores can range from below 20 to above 90.

**Q: Can I see exactly which factors affect my area's score?**
> Yes. Click any area on the map to see its full factor breakdown. You'll see each measured dimension with its individual performance (as a percentile) and the actual underlying value (for example, median income in SEK, or the local school's merit value). This transparency lets you understand not just the score, but *why* the score is what it is.

**Q: Why don't you publish the exact weights?**
> The weighting model is the core of our intellectual property. We're transparent about *what* we measure and *where* the data comes from, because we believe that's necessary for trust. But the specific way factors are combined — which we've developed through extensive research into what actually predicts real estate outcomes in Sweden — is what makes our score uniquely valuable. Publishing exact weights would allow anyone to replicate the model trivially, which wouldn't serve the users who rely on us to maintain and improve it.

**Q: Is the score a property valuation?**
> No. The Neighborhood Trajectory Score is not an appraisal, a property valuation, or financial advice. It measures area-level conditions and trends — not individual property characteristics like size, condition, floor, or view. Two apartments in the same DeSO area will have the same neighborhood score but very different market values. Use the score to understand the *area*; use a mäklare to understand the *property*.

**Q: What's the difference between you and Booli or Hemnet?**
> Hemnet is a listings platform — it shows you what's for sale. Booli adds pricing history and some analytics. We do something different: we score *neighborhoods*, not properties, using government data that goes far beyond transaction prices. We're answering a different question. They answer "what does this apartment cost?" We answer "is this neighborhood getting better or worse, and why?"

**Q: I'm a journalist. Can I cite your scores?**
> Yes. When citing our data, please attribute it to "[Platform Name] Neighborhood Trajectory Score" and note that it is based on data from government agencies including SCB, Skolverket, BRÅ, and Kronofogden, supplemented by commercial and open-source datasets. We're happy to provide additional context for articles — reach out to [contact info]. We ask that you do not present the score as a property valuation or financial recommendation, as it is neither.

**Q: I'm a researcher. Can I access the underlying data?**
> Much of the raw data we use is public — you can access government statistics yourself from the agencies we cite. We also integrate commercial and open-source datasets where government data has gaps. What we add beyond raw data is the integration, normalization, and scoring methodology. We don't currently offer API access or bulk data exports, but if you're working on academic research involving neighborhood-level analysis in Sweden, we'd love to hear from you. Contact [contact info].

**Q: Why does the map sometimes show a high-scoring area right next to a low-scoring area?**
> Statistical areas have defined boundaries, but neighborhoods in reality blend into each other. We mitigate this through spatial smoothing — each area's score is influenced by its neighbors, creating more gradual transitions. However, genuine sharp contrasts do exist in Sweden. It's not uncommon for a wealthy residential area to be separated from a disadvantaged one by a single street or railway line. When you see a sharp contrast on the map, it often reflects a real geographic divide — but always click both areas to understand the specific factors driving the difference.

---

### Section 7: Footer CTA

A simple closing section:

> **Ready to explore?**
> Go back to the map and click any area in Sweden to see its score, factor breakdown, and school details.
>
> [Back to Map →]

---

## Step 5: Styling

- **Page width:** Max ~720px centered, comfortable reading column. Use Tailwind `prose` classes or equivalent.
- **Typography:** Use the same font stack as the rest of the app. Generous line-height (1.6–1.7). Headings in the bold weight.
- **Data source cards:** Use shadcn `Card` component. Subtle border, slight shadow. Source name and update frequency as subdued text or badges below the main content. Cards should be in a single column (not a grid) — each one should be readable without scanning sideways.
- **Score table:** Colored left border or dot matching the map color scale (purple → green). Don't use a heavy table grid — keep it light.
- **FAQ accordion:** shadcn `Accordion` with clean expand/collapse. Answers in slightly muted text color.
- **Spacing:** Generous vertical spacing between sections. This is a page people will scroll through at their own pace — don't cram it.
- **No images required.** The pipeline diagram (Section 2) can be done purely with styled divs/boxes and arrows. Don't over-engineer it — simple > fancy.

---

## Step 6: Verification

- [ ] `/methodology` route works and renders the page
- [ ] Navbar shows "Methodology" link on all pages
- [ ] Page is readable on desktop (centered column, comfortable width)
- [ ] Page is readable on mobile (content reflows, cards stack, accordion works)
- [ ] All six data source cards are present with correct source names
- [ ] Score range table matches the colors used on the actual map
- [ ] FAQ accordion expands/collapses correctly
- [ ] "Back to Map" link works
- [ ] No weights, percentages, or indicator slugs are exposed anywhere on the page
- [ ] Page loads fast (no backend queries, no data fetching)

---

## Notes for the Agent

### What We're Protecting

The following must NOT appear on this page or anywhere public-facing:
- Exact indicator weights (e.g., "school quality: 0.25")
- Number of indicators per category
- Indicator slugs or internal names
- Normalization method (percentile rank, z-score, etc.)
- Direction logic (positive/negative/neutral classification)
- Disaggregation methodology (how kommun-level data maps to DeSO)
- The fact that some data sources are at different geographic granularities
- Any mention of H3 hexagonal grids (future architecture detail)

### What We're Deliberately Revealing

- Every data source by name (SCB, Skolverket, BRÅ, Kronofogden, Polisen, OSM, Google Places, GTFS)
- Every category of measurement (income, employment, education, schools, safety, financial distress, amenities, transport)
- That the score is a percentile-based relative ranking
- That factors are weighted by relevance to real estate outcomes
- That we combine government data with commercial and open data sources
- That we don't use ethnicity/demographics as scoring factors
- The DeSO geographic unit (6,160 areas, ~700-2,700 people each)
- Score ranges and labels (these are already visible on the map)

### Tone Guidance

The copy is written to be confident without being arrogant. We cite government sources by their full Swedish names (which adds authority). We acknowledge limitations honestly (the FAQ about "why does my area score low even though it feels nice"). We explain the weight secrecy directly rather than dodging it.

Avoid: jargon, hedging, self-congratulation, comparisons to competitors by name (the Booli/Hemnet FAQ is the one exception — it's a question users will genuinely ask).

### This Is a v1

The page is static and English-only. Future iterations:
- Swedish translation (separate task)
- Dynamic stats pulled from DB ("currently tracking X indicators across Y areas")
- Per-category deep-dive pages (linked from each card)
- "Data freshness" section showing last update per source
- Methodology changelog ("January 2026: Added financial distress data from Kronofogden")
- Update "Boundaries are not walls" section and spatial smoothing FAQ once H3 hexagonal grid is implemented (see `task-h3-grid.md`). The H3 grid will make the visual smoothing self-evident — hexagons at boundaries naturally blend between DeSO values. The copy should then reference the hexagonal visualization as a concrete feature, not just a principle.

### Placeholder: Platform Name

The copy uses "[Platform Name]" in the journalist FAQ. Replace with the actual product name when one is decided. If no name exists yet, use a placeholder and leave a TODO comment.