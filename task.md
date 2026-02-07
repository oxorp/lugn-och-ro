# TASK: Area Comparison — Compare Two Locations Side by Side

## Context

The single most common use case for homebuyers: "I'm choosing between Årsta and Hägersten — how do they compare?" Right now you can only view one area at a time. This task adds the ability to compare two locations side by side, with every indicator shown as a paired bar chart so differences jump out instantly.

**Why this is hard:** We operate on an H3 hexagonal grid. Users don't think in hex cell IDs — they think in addresses, neighborhoods, or map clicks. The comparison must feel like "compare Drottninggatan 53 vs Kungsholmsgatan 12" while under the hood resolving those to H3 cells and pulling their smoothed scores. The UX must be dead simple despite the spatial complexity.

---

## The UX Problem

There are three fundamental approaches to comparison on a map product. Each has tradeoffs:

**A. Split-screen (two maps side by side)**
Used by: Esri Swipe Tool, old Google Maps compare. Problem: halves your map viewport, terrible on mobile, disorienting because you lose spatial context of how the two locations relate to each other.

**B. Pin & compare (select two points on the same map)**
Used by: what we'll build. Both locations visible on one map, sidebar shows comparison. Preserves spatial context — you can see the two locations in relation to each other, which matters enormously for real estate ("how far is it from Årsta to Hägersten?").

**C. Dedicated comparison page (table view, no map)**
Used by: NeighborhoodScout "Match Any Neighborhood", Niche. Problem: loses the map entirely. Fine for national comparisons but wrong for our use case where relative location matters.

**We go with B. One map, two pins, comparison in sidebar.**

---

## Step 1: The Comparison Mental Model

### 1.1 What Users Compare

Users compare **locations**, not abstract hex IDs. A location is:
- An address they type ("Drottninggatan 53, Stockholm")
- A point they click on the map
- Their current geolocation (via the locate-me button from the geolocation task)

Each location resolves to an H3 cell (or set of cells if smoothing radius is active), which has scores. The user never sees H3 IDs — they see the address or a human-readable label.

### 1.2 Naming the Locations

Each pinned location needs a short, recognizable label. Derive it from:

1. **If entered as address:** Use the address ("Drottninggatan 53")
2. **If clicked on map:** Reverse-geocode to nearest address or use DeSO name ("Årsta 0020")
3. **If from geolocation:** "My Location"

Labels must be short — they appear in column headers of the comparison table. Truncate to ~25 characters with ellipsis if needed.

### 1.3 Color Coding

- **Location A:** Blue pin + blue accent in comparison columns (blue-500)
- **Location B:** Orange pin + orange accent in comparison columns (amber-500)

These colors are deliberately chosen to NOT conflict with the score gradient (purple→yellow→green). Blue and orange are absent from the score palette.

---

## Step 2: Entering Compare Mode

### 2.1 The Compare Button

Add a "Compare" button to the map controls (top-right, below zoom controls, near the layer toggle). Use a simple icon: two vertical bars or a ⇔ symbol.

```
┌─────────┐
│  + / -  │  ← zoom
│  🔍     │  ← search (from search task)
│  ⇔      │  ← compare (NEW)
│  📍     │  ← locate me (from geolocation task)
│  🗺️     │  ← layer toggle
└─────────┘
```

Clicking "Compare" enters **compare mode**. The button gets an active state (filled blue background).

### 2.2 Compare Mode Behavior

When compare mode is active:

1. **The cursor changes** to a crosshair (CSS `cursor: crosshair`) over the map
2. **A banner appears** at the top of the map: "Click two points to compare, or search for addresses" with a small × to exit compare mode
3. **First click** drops Pin A (blue) at that location
4. **Second click** drops Pin B (orange) at that location
5. **Sidebar switches** to comparison view (see Step 4)
6. **Clicking a third time** replaces Pin B (the most recent one). Pin A stays. This lets users "browse" by keeping their home location pinned and clicking around.

### 2.3 Alternative Entry: Search

If the search bar is used while in compare mode:
- First search result → Pin A
- Second search result → Pin B

If one pin is already placed and the user searches, the result becomes the empty slot (or replaces B if both are placed).

### 2.4 Alternative Entry: Sidebar "Compare with..." Button

When viewing a single area's details in the sidebar (normal mode, not compare mode), add a button: **"Compare with another area"**. Clicking this:
1. Enters compare mode
2. Sets the currently viewed area as Pin A
3. Prompts for Pin B ("Click the map or search for a second location")

This is the most natural entry point — user is already looking at an area and wants to compare it.

### 2.5 Exiting Compare Mode

- Click the Compare button again (toggles off)
- Click the × on the banner
- Press Escape
- Click the "Back to map" or "Clear comparison" button in the sidebar

Exiting removes both pins and returns to normal single-area view.

---

## Step 3: Map Visualization During Comparison

### 3.1 Pin Markers

Two distinct pin markers on the map:

**Pin A (Blue):**
- Circle marker, 10px radius, blue-500 fill (#3b82f6), white 2px border
- Label "A" centered in the circle (white, bold, 11px)
- Drop shadow for depth
- zIndex: 100

**Pin B (Orange):**
- Same style but amber-500 fill (#f59e0b)
- Label "B"
- zIndex: 101

Both pins are **draggable**. User can adjust either pin's position after placing it. When dragged, the comparison data updates (with a small debounce — don't re-fetch on every pixel).

### 3.2 Connection Line

Draw a subtle dashed line between Pin A and Pin B:
- 1px dashed, slate-400 color
- Show distance label at midpoint: "2.3 km" (computed from the two points using Haversine or PostGIS)

This gives instant spatial context: how far apart are these two locations?

### 3.3 Highlight Both Areas

Both H3 cells (or DeSO areas if at that zoom level) should be highlighted:
- Pin A's area: blue tinted outline (2px blue-500)
- Pin B's area: orange tinted outline (2px amber-500)
- Both slightly brighter fill than surrounding hexes to stand out

### 3.4 Auto-fit View

After both pins are placed, the map should auto-fit to show both locations with padding. Use OpenLayers `view.fit(extent)` with the bounding box of both points plus 20% padding. Don't zoom in further than level 14 — keep surrounding context visible.

If pins are very close (< 500m), zoom to level 15 max. If very far (> 50km), cap at level 9.

---

## Step 4: Comparison Sidebar

### 4.1 Sidebar Layout

When both pins are placed, the sidebar completely changes to comparison mode:

```
┌─────────────────────────────────────────┐
│  ← Back to map       [Clear comparison] │
├─────────────────────────────────────────┤
│                                         │
│  ● Drottninggatan 53    ● Kungsholms... │
│    Stockholm              Stockholm     │
│    Norrmalm                Kungsholmen  │
│                                         │
│  ─────────── 2.3 km ───────────         │
│                                         │
├─────────────────────────────────────────┤
│                                         │
│  COMPOSITE SCORE                        │
│     72              vs          58      │
│   ██████████████       ██████████       │
│   Stable              Mixed Signals     │
│                                         │
├─────────────────────────────────────────┤
│                                         │
│  INDICATOR BREAKDOWN                    │
│                                         │
│  Median Income                          │
│  A ████████████░░  78th  287,000 SEK    │
│  B ██████████░░░░  64th  245,000 SEK    │
│     A is 17% higher                     │
│                                         │
│  School Quality                         │
│  A ██████████████  91st  241 pts        │
│  B ████████░░░░░░  52nd  209 pts        │
│     A is 15% higher                     │
│                                         │
│  Employment Rate                        │
│  A ██████████░░░░  65th  74.2%          │
│  B ████████████░░  78th  79.1%          │
│     B is 7% higher                      │
│                                         │
│  ... (all active indicators)            │
│                                         │
├─────────────────────────────────────────┤
│                                         │
│  VERDICT                                │
│                                         │
│  A is stronger in:                      │
│  🟢 School Quality (+39 percentile pts) │
│  🟢 Median Income (+14 percentile pts)  │
│                                         │
│  B is stronger in:                      │
│  🟠 Employment Rate (+13 percentile pts)│
│  🟠 Financial Stability (+8 pctl pts)   │
│                                         │
│  Similar in:                            │
│  ⚪ Education Level (within 5 pts)      │
│                                         │
├─────────────────────────────────────────┤
│                                         │
│  [Share comparison]  [Save as PDF 🔒]   │
│                                         │
└─────────────────────────────────────────┘
```

### 4.2 Header Section

Each location shows:
- Pin color dot (blue/orange) + address or area name
- Municipality name
- DeSO area name (if available)
- Distance between the two locations

### 4.3 Composite Score Section

Big numbers side by side. Each colored by the score gradient. Include the score label ("Stable", "Mixed Signals"). Show the difference: "+14 points" in favor of the higher one.

### 4.4 Indicator Breakdown

For each active indicator (sorted by weight, highest first):

- Indicator name
- Two horizontal bars (A on top, B below), each showing percentile rank
- Raw value in parentheses
- **Comparison sentence:** "A is 17% higher" or "Similar (within 3%)" or "B is 12% higher"
- The "winner" bar gets its pin color accent; the "loser" gets a muted gray
- If difference < 5 percentile points → show as "Similar" with neutral gray for both

### 4.5 Verdict Section

Summarize which location wins on which dimensions:

- **A is stronger in:** list indicators where A leads by > 5 percentile points, sorted by gap size
- **B is stronger in:** same for B
- **Similar in:** indicators within 5 percentile point gap

Use green badges for A's strengths, orange for B's strengths, gray for similar. This gives users the instant "bottom line" without reading every bar.

### 4.6 Share Button

"Share comparison" copies a URL like:
```
https://[domain]/?compare=59.3293,18.0686|59.3310,18.0300
```

This URL contains both lat/lng pairs. On load, it enters compare mode and places both pins.

### 4.7 PDF Report (Paywall Placeholder)

"Save as PDF" button with a lock icon (🔒). Clicking shows a gentle paywall message: "Full comparison reports are available with a premium account. Coming soon." This is a placeholder for the future payment system — the button exists now to signal the feature exists.

---

## Step 5: Backend — Comparison API

### 5.1 Endpoint

```php
Route::post('/api/compare', [CompareController::class, 'compare']);
```

Request body:
```json
{
  "point_a": { "lat": 59.3293, "lng": 18.0686 },
  "point_b": { "lat": 59.3310, "lng": 18.0300 }
}
```

### 5.2 Response

```json
{
  "location_a": {
    "lat": 59.3293,
    "lng": 18.0686,
    "h3_index": "881f1d4a7ffffff",
    "deso_code": "0180C1020",
    "deso_name": "Norrmalm 0020",
    "kommun_name": "Stockholm",
    "label": "Norrmalm 0020",
    "composite_score": 72.4,
    "score_label": "Stable / Positive Outlook",
    "indicators": {
      "median_income": { "raw_value": 287000, "normalized": 0.78, "percentile": 78, "unit": "SEK" },
      "school_merit_value_avg": { "raw_value": 241.2, "normalized": 0.91, "percentile": 91, "unit": "points" }
    }
  },
  "location_b": {
    // same structure
  },
  "distance_km": 2.31,
  "comparison": {
    "score_difference": 14.2,
    "a_stronger": ["school_merit_value_avg", "median_income"],
    "b_stronger": ["employment_rate"],
    "similar": ["education_post_secondary_pct"]
  }
}
```

### 5.3 Resolution Logic

For each point:
1. Compute H3 index at resolution 8: `h3_latlng_to_cell(point, 8)`
2. Look up smoothed score for that cell (from the h3_scores table)
3. Look up all indicator values (join through deso_h3_mapping or direct h3 lookup depending on architecture)
4. Resolve DeSO code via `ST_Contains` for the label
5. Reverse-geocode via Photon (optional — only if we want address labels instead of DeSO names)

### 5.4 Distance Calculation

```sql
SELECT ST_Distance(
    ST_SetSRID(ST_MakePoint(:lng_a, :lat_a), 4326)::geography,
    ST_SetSRID(ST_MakePoint(:lng_b, :lat_b), 4326)::geography
) / 1000 as distance_km;
```

---

## Step 6: Frontend Implementation

### 6.1 State Management

Compare mode state:
```typescript
interface CompareState {
  active: boolean;
  pinA: { lat: number; lng: number; label: string } | null;
  pinB: { lat: number; lng: number; label: string } | null;
  result: CompareResult | null;
  loading: boolean;
}
```

Store in React state (not URL — URL only gets synced when user clicks "Share"). The compare state lives alongside the existing selected-area state and overrides the sidebar when active.

### 6.2 Map Click Handler

In compare mode, the map click handler changes:
```typescript
if (compareState.active) {
  const coords = toLonLat(evt.coordinate);
  if (!compareState.pinA) {
    setCompareState(prev => ({ ...prev, pinA: { lat: coords[1], lng: coords[0] } }));
  } else {
    setCompareState(prev => ({ ...prev, pinB: { lat: coords[1], lng: coords[0] } }));
    // Fetch comparison data
    fetchComparison(compareState.pinA, { lat: coords[1], lng: coords[0] });
  }
} else {
  // Normal hex/DeSO click behavior
}
```

### 6.3 OpenLayers Layers

Add a dedicated `VectorLayer` for comparison markers:
- Two point features for Pin A and Pin B
- One LineString feature for the connection line
- Styled with the blue/orange color scheme
- Layer zIndex above the hex layer but below any overlays

### 6.4 ComparisonSidebar Component

Replace the normal sidebar content when compare result is available:

```tsx
<ComparisonSidebar
  result={compareResult}
  onClose={() => exitCompareMode()}
  onSwapPins={() => swapPins()}
  onShare={() => copyShareUrl()}
/>
```

Include a **swap button** (⇄) in the header that swaps Pin A and Pin B. This is a small touch that feels great — user placed pins in the wrong order, one click fixes it.

---

## Step 7: Mobile

### 7.1 Mobile Compare Flow

On mobile (< 768px), comparison works differently:

1. Compare button enters compare mode with same banner
2. First tap places Pin A, bottom sheet shows "Pin A placed at [location]. Tap another point."
3. Second tap places Pin B, bottom sheet expands to show comparison
4. Bottom sheet is scrollable with all comparison sections
5. Map is still visible above (60% map, 40% bottom sheet, same as existing layout)

### 7.2 Mobile Sidebar Adjustments

The comparison sidebar content should stack vertically on mobile. Indicator bars still work (they're already full-width). The "A vs B" layout switches from side-by-side to stacked:

```
COMPOSITE SCORE
● A: Norrmalm — 72 (Stable)
● B: Kungsholmen — 58 (Mixed)
Difference: +14 points
```

---

## Step 8: URL State & Sharing

### 8.1 URL Format

When user clicks "Share comparison":
```
https://[domain]/?compare=59.3293,18.0686|59.3310,18.0300
```

### 8.2 Load from URL

On page load, check for `compare` parameter:
1. Parse the two coordinate pairs
2. Enter compare mode
3. Place both pins
4. Fetch comparison data
5. Show results in sidebar

### 8.3 Integration with Search URL

If the search task already uses `?q=...` parameter, comparison uses `?compare=...`. They're mutually exclusive — a URL has either `q` or `compare`, never both.

---

## Step 9: Edge Cases

### 9.1 Same Location

If both pins are in the same H3 cell (or within 50m of each other), show a friendly message: "These locations are in the same area. Try comparing locations further apart." Don't block — still show the (identical) data.

### 9.2 Location Outside Sweden

If either pin is outside Sweden's bounding box (lat 55.0–69.5, lng 10.5–24.5), show: "Comparison is only available for locations within Sweden." Don't place the pin.

### 9.3 Missing Data

If one location has data but the other doesn't (e.g., a rural area with no scores), show what's available with "No data" for missing indicators. Don't block the entire comparison.

### 9.4 Network Error

If the comparison API fails, show a retry button: "Couldn't load comparison data. [Retry]"

---

## Step 10: Verification

### 10.1 Functional Checklist

- [ ] Compare button appears in map controls
- [ ] Clicking it enters compare mode (crosshair cursor, banner)
- [ ] First map click places blue Pin A
- [ ] Second map click places orange Pin B
- [ ] Sidebar switches to comparison view with both locations' data
- [ ] All active indicators shown as paired bars with percentile + raw value
- [ ] Verdict section correctly identifies which location wins on which indicators
- [ ] Distance shown between the two points
- [ ] Pins are draggable — dragging updates the comparison
- [ ] Third click replaces Pin B (not A)
- [ ] "Compare with another area" button works from single-area view
- [ ] Search works in compare mode (results become pins)
- [ ] Swap button switches A and B
- [ ] Share button copies URL with both coordinates
- [ ] Loading shared URL enters compare mode with correct pins
- [ ] Exiting compare mode clears pins and returns to normal view
- [ ] Escape key exits compare mode
- [ ] Mobile: comparison works with bottom sheet

### 10.2 Test Scenarios

1. **Stockholm inner city vs suburb:** Norrmalm vs Rinkeby — should show dramatic differences across most indicators
2. **Adjacent areas:** Two DeSOs next to each other — differences should be small, verdict shows mostly "Similar"
3. **Different cities:** Stockholm vs Gothenburg — works, shows distance
4. **Same area:** Click the same spot twice — shows "same area" message
5. **Rural vs urban:** A Norrland DeSO vs central Stockholm — some indicators may be missing for rural area
6. **Pin outside Sweden:** Click on Norway — rejects with message

---

## Notes for the Agent

### Why Not DeSO-to-DeSO Comparison

The original question was "compare two fully qualified addresses or two points." This is better than DeSO-to-DeSO because:
1. Users think in addresses, not administrative units
2. With H3 + smoothing, the score at any point is unique (not one flat score per polygon)
3. Address entry feels more natural: "compare my apartment vs the one I'm looking at on Hemnet"
4. It works regardless of zoom level or spatial unit

### The "Pin A stays, Pin B rotates" Pattern

This is deliberate. The primary use case is: "I live at X, should I move to Y or Z?" Pin A is "home base." The user places it once, then clicks around exploring alternatives. Each click updates Pin B and refreshes the comparison. This is much faster than having to place two new pins each time.

### Don't Over-engineer the Backend

The comparison endpoint is just two parallel score lookups + a diff. Don't build a special comparison table or pre-compute pairs. The data already exists — we're just presenting two results side by side with some computed differences.

### Integration Dependencies

This task depends on:
- **Map search** (Photon geocoding) — for address entry in compare mode
- **H3 implementation** — for resolving points to scored cells
- **Geolocation** (locate-me) — optional, but "compare my location with..." is a great flow

If search isn't implemented yet, comparison still works via map clicks only. Search integration can be added later.

### What NOT to Do

- Don't build a separate comparison page — keep it in the sidebar on the same map
- Don't allow comparing more than 2 locations (keep it simple — 3-way comparison is confusing)
- Don't show comparison in a modal/overlay — the sidebar is the right place
- Don't hide the map during comparison — both pins visible on the map IS the feature
- Don't use DeSO names as the only identifier — always try to show an address or at least kommun + area name