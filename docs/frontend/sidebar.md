# Sidebar

> Location score display with proximity factors, indicators, schools, and POIs.

## Overview

**Directory**: `resources/js/pages/explore/`

The sidebar is a 360px right panel on the map page. It has two states:

1. **Default state** (`DefaultSidebar`) — Prompt to search or click the map
2. **Active state** (`ActiveSidebar`) — Full location detail after a pin drop, with tier-based content

## Default Sidebar

**File**: `explore/components/default-sidebar.tsx`

Shown when no pin is active. Contains:

- Location icon and welcome message
- Embedded `MapSearch` component for geocoding
- Instruction to search or click the map

## Active Sidebar

**File**: `explore/components/active-sidebar.tsx`

Shown after a pin drop or search result selection. Fetches data via `useLocationData` hook from `GET /api/location/{lat},{lng}`.

### Sections — Free Tier (tier = 0)

1. **Location header** — Reverse-geocoded address (via Photon/Komoot), kommun, close button
2. **Score card** (`ScoreCard`) — Large colored badge, trend arrow, score label, area/proximity breakdown
3. **Locked preview** (`LockedPreviewContent`) — Category sections with free sample indicators:
   - Each category shows icon, label, stat line, and 2 free indicators with actual percentile values
   - Remaining indicators shown as locked count ("+ N indikatorer i rapporten")
   - School skeleton cards (blurred placeholders)
   - CTA summary with data point count + "Lås upp fullständig rapport" (79 kr) button → `/purchase/{lat},{lng}`
4. **Sticky unlock bar** (`StickyUnlockBar`) — Appears when CTA scrolls out of view

### Sections — Paid Tiers (tier >= 1)

1. **Location header** — Same as above
2. **Score card** — Same as above
3. **Proximity Analysis** — Safety zone badge + per-factor bars with icons (`ProximityFactorRow`)
4. **Indicator Breakdown** — All area-level indicators with percentile bars (`IndicatorBar` + `PercentileBadge`)
5. **Schools** — Nearby schools within urbanity-tiered radius (1.5–3.5 km) with merit values and distance
6. **POIs** — Nearby POI counts by category with color dots, urbanity-tiered radius (1–2.5 km)

### Score Card

The main score card shows:

```
┌──────────────────────────────────────┐
│  ┌──────┐  Stabilt / Positivt       │
│  │ 72.3 │  Urban                    │
│  │ +1.2 │  Area: 75.0  Location: 66 │
│  └──────┘                            │
└──────────────────────────────────────┘
```

- Score badge is color-matched to the red→green gradient (`config/score_colors.php`)
- Trend arrow shows 1-year change (if available)
- Area vs proximity sub-scores shown below the label

### Score Labels

Defined in `config/score_colors.php`:

| Score Range | Swedish | English |
|---|---|---|
| 80–100 | Starkt tillväxtområde | Strong Growth Area |
| 60–79 | Stabil / positiv utsikt | Stable / Positive Outlook |
| 40–59 | Blandade signaler | Mixed Signals |
| 20–39 | Förhöjd risk | Elevated Risk |
| 0–19 | Hög risk / vikande | High Risk / Declining |

### Proximity Factors

Each factor shows an icon, name, 0–100 score bar, and detail line:

| Factor | Icon | Detail |
|---|---|---|
| School | GraduationCap | Nearest school name + distance |
| Green Space | TreePine | Nearest park name + distance |
| Transit | Bus | Nearest stop name + type + distance |
| Grocery | ShoppingCart | Nearest store name + distance |
| Negative POIs | ShieldAlert | Count of nearby negative POIs |
| Positive POIs | Sparkles | Count of nearby positive amenities |

For negative POI factor, score 100 = good (no negatives nearby). The detail shows "No negative POIs nearby" or "3 within 500m".

### Safety Zone Badge

Next to the "Proximity Analysis" heading, a colored badge shows the area's safety context:

| Level | Badge Color | Meaning |
|---|---|---|
| High (> 0.65) | Green | No safety penalty on distances |
| Medium (0.35–0.65) | Yellow | Moderate distance inflation |
| Low (< 0.35) | Red | Significant distance inflation |

When safety is medium or low, an orange warning note explains that effective distances are longer than physical distances due to safety concerns.

Each factor's distance display shows both physical and effective distance when they differ (e.g., "320m (eff. 378m)").

### Indicator Bars

Each indicator shows:
- Name with info tooltip (description, methodology, source)
- Direction-adjusted percentile value (0–100)
- Color-coded progress bar
- Raw value with unit (e.g., "312,000 SEK")
- Scope label: national percentile or urbanity-stratified percentile

### Tier Gating

| Tier | Score | Preview | Proximity | Indicators | Schools | POIs |
|---|---|---|---|---|---|---|
| Free (0) | Full score | Category previews + CTA | Hidden | 8 free samples | Skeleton | Hidden |
| Paid (1+) | Full breakdown | — | Shown | All shown | Shown | Shown |

Free tier shows `LockedPreviewContent` with real indicator values for 8 free-preview indicators grouped by category, plus locked counts and a purchase CTA (79 SEK one-time).

## Explore URL

Pin drops update the URL for shareable links:

```
/explore/{lat},{lng}
```

On page load, if the URL contains explore coordinates, the pin is automatically dropped and data fetched.

## Reverse Geocoding

After a pin drop, the map page calls the Photon API for a human-readable address:

```
https://photon.komoot.io/reverse?lat={lat}&lon={lng}&lang=default
```

Falls back to the kommun name from the location API if geocoding fails.

## Mobile

On smaller screens (< `md` breakpoint), the sidebar collapses into a bottom sheet with the same content in a compact layout.

## Internationalization

All text uses `useTranslation()` with keys like:
- `sidebar.score.strong_growth`
- `sidebar.proximity.school`
- `sidebar.indicators.percentile_national`
- `sidebar.default.title`

## Related

- [Map Rendering](/frontend/map-rendering)
- [Location Lookup API](/api/location-lookup)
- [Tiering Model](/business/tiering-model)
