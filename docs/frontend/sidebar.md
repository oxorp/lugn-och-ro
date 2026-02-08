# Sidebar

> Location score display with proximity factors, indicators, schools, and POIs.

## Overview

**File**: `resources/js/pages/map.tsx`

The sidebar is a 360px right panel on the map page. It has two states:

1. **Default state** — Prompt to search or click the map, with suggested addresses
2. **Active state** — Full location detail after a pin drop

## Default Sidebar

Shown when no pin is active. Contains:

- MapPin icon and title ("Explore any address")
- Subtitle explaining pin-drop or search
- Three suggestion buttons (e.g., "Try: Sveavägen, Stockholm") that pre-fill the search bar
- Legend hint text

## Active Sidebar

Shown after a pin drop or search result selection. Fetches data from `GET /api/location/{lat},{lng}`.

### Sections (top to bottom)

1. **Location header** — Reverse-geocoded address name (via Photon/Komoot), kommun, close button
2. **Blended score card** — Large colored score badge (0–100), trend arrow, score label
3. **Score breakdown** — Area score vs proximity score as sub-values
4. **Proximity Analysis** — Safety zone badge + per-factor bars with icons (paid tiers only)
5. **Indicator Breakdown** — All area-level indicators with percentile bars (paid tiers only)
6. **Schools** — Nearby schools within urbanity-tiered radius (1.5–3.5 km) with merit values and distance (paid tiers only)
7. **POIs** — Nearby POI counts by category with color dots, urbanity-tiered radius (1–2.5 km) (paid tiers only)

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

| Score Range | Swedish | English |
|---|---|---|
| 80–100 | Starkt tillväxtområde | Strong Growth Area |
| 60–79 | Stabilt / Positivt | Stable / Positive |
| 40–59 | Blandat | Mixed Signals |
| 20–39 | Förhöjd risk | Elevated Risk |
| 0–19 | Hög risk | High Risk |

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

| Tier | Score | Proximity | Indicators | Schools | POIs |
|---|---|---|---|---|---|
| Public (0) | Blended only | Hidden | Hidden | Hidden | Hidden |
| Paid (1+) | Full breakdown | Shown | Shown | Shown | Shown |

Public tier shows a CTA card with login/upgrade prompt instead of detail sections.

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
