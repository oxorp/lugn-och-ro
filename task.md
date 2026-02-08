# Refactor: MapPage (`map-page.tsx`)

## Goal
Break up the monolithic MapPage file into well-organized components, utils, and types. **Zero behaviour changes** — this is a pure structural refactor.

---

## Target Structure

```
resources/js/pages/explore/
├── map-page.tsx                  # Slim orchestrator (state, handlers, layout)
├── types.ts                      # All interfaces/types
├── utils.ts                      # Pure helper functions
├── components/
│   ├── default-sidebar.tsx       # DefaultSidebar component
│   ├── active-sidebar.tsx        # ActiveSidebar component
│   ├── indicator-bar.tsx         # IndicatorBar component
│   ├── proximity-factor-row.tsx  # ProximityFactorRow component
│   └── score-card.tsx            # Score card section (extracted from ActiveSidebar)
├── hooks/
│   ├── use-score-label.ts        # useScoreLabel hook
│   ├── use-location-data.ts      # fetchLocationData + reverseGeocode + abort logic
│   └── use-url-pin.ts            # URL parsing + initial pin drop effect
└── constants.ts                  # PROXIMITY_FACTOR_CONFIG
```

---

## Extraction Plan

### 1. `types.ts`
Move all interfaces out of `map-page.tsx`:
- `MapPageProps`
- `ProximityFactor`
- `SafetyZone`
- `ProximityData`
- `LocationData`

### 2. `utils.ts`
Move pure functions:
- `formatIndicatorValue(value, unit)`
- `formatDistance(meters)`
- `scoreBgStyle(score)` (depends on `interpolateScoreColor` import)

### 3. `constants.ts`
Move:
- `PROXIMITY_FACTOR_CONFIG` object

### 4. Components

#### `components/indicator-bar.tsx`
- Move `IndicatorBar` as-is
- Imports `formatIndicatorValue`, `scoreBgStyle` from `../utils`
- Imports types from `../types`

#### `components/proximity-factor-row.tsx`
- Move `ProximityFactorRow` as-is
- Imports `PROXIMITY_FACTOR_CONFIG` from `../constants`
- Imports `formatDistance`, `scoreBgStyle` from `../utils`

#### `components/score-card.tsx`
- Extract the score card JSX block from `ActiveSidebar` (the `score &&` section with the colored box, trend arrow, area/proximity breakdown)
- Props: `score`, `urbanityTier`, `lat`, `lng`

#### `components/active-sidebar.tsx`
- Keep `ActiveSidebar` but now it imports sub-components
- Imports `IndicatorBar`, `ProximityFactorRow`, `ScoreCard`
- Much slimmer — mostly layout and section toggling

#### `components/default-sidebar.tsx`
- Move `DefaultSidebar` as-is
- Self-contained, only needs `useTranslation` and `MapPin` icon

### 5. Hooks

#### `hooks/use-score-label.ts`
- Move `useScoreLabel` hook

#### `hooks/use-location-data.ts`
- Extract from `map-page.tsx`:
  - `fetchLocationData` callback
  - `reverseGeocode` callback
  - `abortRef` management
  - State: `locationData`, `locationName`, `loading`, `pinActive`
- Returns: `{ locationData, locationName, loading, pinActive, fetchLocationData, clearLocation }`
- Accepts: `mapRef` as parameter

#### `hooks/use-url-pin.ts`
- Extract the `useEffect` that parses `/explore/lat,lng` from URL
- Accepts: `mapRef`, `handlePinDrop`

---

## Step-by-step Execution Order

1. Create `types.ts` — move interfaces, update imports in `map-page.tsx`
2. Create `utils.ts` — move helpers, update imports
3. Create `constants.ts` — move config object, update imports
4. Create `hooks/use-score-label.ts` — extract hook
5. Create `components/default-sidebar.tsx` — extract component
6. Create `components/proximity-factor-row.tsx` — extract component
7. Create `components/indicator-bar.tsx` — extract component
8. Create `components/score-card.tsx` — extract from ActiveSidebar
9. Create `components/active-sidebar.tsx` — extract component, wire sub-components
10. Create `hooks/use-location-data.ts` — extract data fetching logic
11. Create `hooks/use-url-pin.ts` — extract URL effect
12. Clean up `map-page.tsx` — should only contain the top-level orchestrator

**After each step**: verify the page renders and behaves identically (no visual or functional diff).

---

## Rules

- **No behaviour changes.** No new features, no bug fixes, no style tweaks.
- Keep all existing prop names, CSS classes, and translation keys identical.
- Preserve the exact same component tree / DOM output.
- Every extracted file gets explicit TypeScript types (no `any`).
- Re-export from an `index.ts` barrel only if the project already uses that pattern — otherwise skip.