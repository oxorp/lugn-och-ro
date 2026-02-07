# TASK: Map Search Bar with Geocoding

## Context

Users need to find specific locations on the map. Right now they have to scroll and zoom around Sweden manually to find their neighborhood. A search bar that accepts addresses, postal codes, city names, or municipality names — and zooms to the result — is table-stakes for a map product.

## Goals

1. Search bar on the map with autocomplete suggestions
2. Geocode the input to coordinates + bounding box
3. Zoom and center the map to the result
4. Highlight the relevant DeSO(s) and auto-select if it resolves to a single DeSO
5. Handle different result types gracefully (house, street, postal code, city, municipality)

---

## Geocoding Provider: Photon

**Use Photon (photon.komoot.io) as the geocoder.** It's free, open-source, built on OSM data, powered by OpenSearch, and — critically — supports **search-as-you-type autocomplete**, which Nominatim explicitly forbids in their usage policy.

| Feature | Photon | Nominatim |
|---|---|---|
| Autocomplete | ✅ Designed for it | ❌ Explicitly banned |
| Cost | Free (public API) | Free (public API) |
| Rate limit | "Be fair" (no hard number) | 1 req/sec, no autocomplete |
| Response format | GeoJSON (GeoCodeJSON) | Custom JSON |
| Bounding box | ✅ `extent` field in properties | ✅ `boundingbox` field |
| Swedish data | ✅ Full OSM Sweden coverage | ✅ Full OSM Sweden coverage |
| Self-hostable | ✅ (Java + OpenSearch) | ✅ (Python + PostgreSQL) |

**API endpoint:**
```
GET https://photon.komoot.io/api?q=Drottninggatan+Stockholm&lang=sv&limit=5&lat=62.0&lon=15.0&bbox=10.5,55.0,24.5,69.5
```

Key parameters:
- `q` — search query (free text)
- `lang=sv` — prefer Swedish results
- `limit=5` — max suggestions
- `lat`, `lon` — location bias (center of Sweden: 62.0, 15.0)
- `bbox=10.5,55.0,24.5,69.5` — restrict to Sweden's bounding box (SW lon, SW lat, NE lon, NE lat)
- `layer=house,street,locality,city,state` — restrict to address-type results (skip POIs unless wanted)

**Response example:**
```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "geometry": {
        "type": "Point",
        "coordinates": [18.0686, 59.3293]
      },
      "properties": {
        "osm_id": 123456,
        "osm_type": "W",
        "osm_key": "highway",
        "osm_value": "residential",
        "name": "Drottninggatan",
        "housenumber": "53",
        "street": "Drottninggatan",
        "postcode": "111 21",
        "city": "Stockholm",
        "state": "Stockholms län",
        "country": "Sweden",
        "countrycode": "SE",
        "type": "house",
        "extent": [18.0650, 59.3310, 18.0720, 59.3280]
      }
    }
  ]
}
```

The `type` field tells us what was matched: `house`, `street`, `locality`, `district`, `city`, `county`, `state`. The `extent` array is the bounding box `[west, north, east, south]` — use it for zoom level.

**Fallback:** If Photon's public API goes down or becomes too slow, Nominatim can serve as a fallback for the final geocode (after user selects a suggestion). The autocomplete part only works with Photon though. For a production deployment, self-hosting Photon is ~$40/month on a small server with Sweden-only data.

---

## Step 1: Search Bar Component

### 1.1 Position and Layout

The search bar sits **on top of the map**, in the upper-left corner, overlaying the map canvas. Not in the navbar, not in the sidebar — on the map itself, like Google Maps.

```
┌──────────────────────────────────────────┬─────────────────────┐
│  ┌─────────────────────────────┐         │                     │
│  │ 🔍 Search address or area   │         │    Sidebar          │
│  └─────────────────────────────┘         │                     │
│                                          │                     │
│           Map                            │                     │
│                                          │                     │
│                                          │                     │
│  ┌────────────────┐                      │                     │
│  │ Legend          │                      │                     │
│  └────────────────┘                      │                     │
└──────────────────────────────────────────┴─────────────────────┘
```

**Specifications:**
- Position: `absolute top-4 left-4` (inside the map container)
- Width: 360px on desktop, full width minus padding on mobile
- Z-index: above the map but below modals
- Background: `bg-background/95 backdrop-blur-sm` (slightly transparent white)
- Border: `border rounded-lg shadow-sm`
- Input: text-sm, placeholder "Search address, postal code, or city..."
- Left icon: magnifying glass (lucide `Search`)
- Right icon: clear button (lucide `X`) when input has text

### 1.2 Autocomplete Dropdown

As the user types (after 3+ characters), show a dropdown with suggestions:

```
┌─────────────────────────────────────┐
│ 🔍 Drottningg                    ✕  │
├─────────────────────────────────────┤
│ 📍 Drottninggatan 53, Stockholm     │
│    111 21 · Stockholms län          │
├─────────────────────────────────────┤
│ 🏘 Drottninggatan, Stockholm        │
│    Street · Stockholms län          │
├─────────────────────────────────────┤
│ 🏘 Drottninggatan, Göteborg         │
│    Street · Västra Götalands län    │
├─────────────────────────────────────┤
│ 🏘 Drottninggatan, Malmö            │
│    Street · Skåne län               │
├─────────────────────────────────────┤
│ 🏘 Drottninggatan, Uppsala          │
│    Street · Uppsala län             │
└─────────────────────────────────────┘
```

**Each suggestion row shows:**
- Icon based on type: 📍 house, 🏘 street/locality, 🏙 city, 🗺 county/state
- Primary text: name + number (if house) or name
- Secondary text: postal code (if available) + city/county/state
- Use `text-sm` for primary, `text-xs text-muted-foreground` for secondary

**Behavior:**
- Debounce: 300ms after last keystroke before firing API request
- Minimum 3 characters before searching
- Show loading spinner while fetching
- Show "No results found" if API returns empty
- Show "Search Sweden..." as initial placeholder suggestion
- Keyboard navigation: up/down arrows move selection, Enter selects, Escape closes
- Click outside closes the dropdown

### 1.3 Component Structure

```tsx
// resources/js/Components/MapSearch.tsx

interface SearchResult {
  id: string;              // osm_type + osm_id for uniqueness
  name: string;            // Display name (formatted)
  secondary: string;       // City, county, etc.
  type: 'house' | 'street' | 'locality' | 'district' | 'city' | 'county' | 'state';
  lat: number;
  lng: number;
  extent: [number, number, number, number] | null;  // [west, north, east, south]
  postcode: string | null;
  city: string | null;
  county: string | null;
}
```

---

## Step 2: Geocoding Service (Frontend)

### 2.1 Photon Client

Create a small client that wraps the Photon API:

```tsx
// resources/js/services/geocoding.ts

const PHOTON_BASE = 'https://photon.komoot.io/api';

// Sweden center + bounding box
const SWEDEN_LAT = 62.0;
const SWEDEN_LNG = 15.0;
const SWEDEN_BBOX = '10.5,55.0,24.5,69.5';

export async function searchPlaces(query: string, signal?: AbortSignal): Promise<SearchResult[]> {
  if (query.length < 3) return [];

  const params = new URLSearchParams({
    q: query,
    lang: 'sv',
    limit: '7',
    lat: String(SWEDEN_LAT),
    lon: String(SWEDEN_LNG),
    bbox: SWEDEN_BBOX,
  });

  const response = await fetch(`${PHOTON_BASE}?${params}`, { signal });
  if (!response.ok) throw new Error(`Photon API error: ${response.status}`);

  const data = await response.json();
  return data.features.map(parsePhotonFeature).filter(Boolean);
}

function parsePhotonFeature(feature: any): SearchResult | null {
  const props = feature.properties;
  const [lng, lat] = feature.geometry.coordinates;

  // Skip results outside Sweden
  if (props.countrycode && props.countrycode !== 'SE') return null;

  const type = mapPhotonType(props.type);
  const name = formatName(props);
  const secondary = formatSecondary(props);

  return {
    id: `${props.osm_type}${props.osm_id}`,
    name,
    secondary,
    type,
    lat,
    lng,
    extent: props.extent || null,
    postcode: props.postcode || null,
    city: props.city || null,
    county: props.county || null,
  };
}

function mapPhotonType(photonType: string): SearchResult['type'] {
  switch (photonType) {
    case 'house': return 'house';
    case 'street': return 'street';
    case 'locality':
    case 'district': return 'locality';
    case 'city': return 'city';
    case 'county': return 'county';
    case 'state': return 'state';
    default: return 'locality';
  }
}

function formatName(props: any): string {
  if (props.type === 'house' && props.housenumber) {
    return `${props.street || props.name} ${props.housenumber}`;
  }
  return props.name || props.street || 'Unknown';
}

function formatSecondary(props: any): string {
  const parts: string[] = [];
  if (props.postcode) parts.push(props.postcode);
  if (props.city) parts.push(props.city);
  else if (props.county) parts.push(props.county);
  if (props.state && props.state !== props.city) parts.push(props.state);
  return parts.join(' · ') || 'Sweden';
}
```

### 2.2 Debouncing

Use a debounced search hook:

```tsx
function useSearch() {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResult[]>([]);
  const [loading, setLoading] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    if (query.length < 3) {
      setResults([]);
      return;
    }

    const timeout = setTimeout(async () => {
      abortRef.current?.abort();
      const controller = new AbortController();
      abortRef.current = controller;

      setLoading(true);
      try {
        const results = await searchPlaces(query, controller.signal);
        setResults(results);
      } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') return;
        console.error('Search failed:', e);
        setResults([]);
      } finally {
        setLoading(false);
      }
    }, 300);

    return () => clearTimeout(timeout);
  }, [query]);

  return { query, setQuery, results, loading };
}
```

---

## Step 3: Zoom-to-Result Logic

### 3.1 Zoom Levels by Result Type

When the user selects a search result, the map should zoom to an appropriate level based on what was matched:

| Result type | Zoom behavior | Typical zoom level |
|---|---|---|
| `house` | Zoom tight to the point, ~street level | 17-18 |
| `street` | Fit the street's extent, or zoom to moderate level | 15-16 |
| `locality` / `district` | Fit the locality's extent | 13-14 |
| `city` | Fit the city's extent | 11-13 |
| `county` | Fit the county's extent | 8-10 |
| `state` (län) | Fit the state's extent | 7-8 |

### 3.2 Using Extent vs Point

If the result has an `extent` (bounding box), use it to fit the map view — this is more accurate than guessing a zoom level from the type. OpenLayers can fit to an extent:

```tsx
function zoomToResult(map: OLMap, result: SearchResult) {
  const view = map.getView();

  if (result.extent) {
    // Photon extent: [west, north, east, south]
    // OpenLayers extent: [minX, minY, maxX, maxY] = [west, south, east, north]
    const [west, north, east, south] = result.extent;
    const extent = transformExtent(
      [west, south, east, north],
      'EPSG:4326',
      'EPSG:3857'
    );

    view.fit(extent, {
      padding: [50, 50, 50, 50],
      duration: 800,
      maxZoom: 18,
    });
  } else {
    // No extent — zoom to point with type-appropriate zoom
    const center = fromLonLat([result.lng, result.lat]);
    const zoom = getZoomForType(result.type);

    view.animate({
      center,
      zoom,
      duration: 800,
    });
  }
}

function getZoomForType(type: SearchResult['type']): number {
  switch (type) {
    case 'house': return 17;
    case 'street': return 15;
    case 'locality':
    case 'district': return 14;
    case 'city': return 12;
    case 'county': return 9;
    case 'state': return 8;
    default: return 14;
  }
}
```

### 3.3 Animate the Transition

Use OpenLayers' built-in `view.animate()` or `view.fit()` with `duration: 800` for a smooth fly-to effect. If the user is already zoomed into Stockholm and searches for Malmö, the map should smoothly fly there, not teleport.

---

## Step 4: DeSO Highlighting After Search

### 4.1 The Key UX Question: What Happens After Zoom?

After the map zooms to the search result, we need to connect the search result to the DeSO system. The behavior depends on what was searched:

**House / Street / Small Locality:**
The result falls within a single DeSO. Auto-select that DeSO:
1. Zoom to the result location
2. Find which DeSO polygon contains the result point (spatial query)
3. Highlight that DeSO as if the user clicked it
4. Populate the sidebar with that DeSO's data
5. Drop a small marker pin at the exact search location (so the user can see where within the DeSO their search landed)

**City / Municipality:**
The result covers many DeSOs. Don't auto-select a single DeSO:
1. Zoom to fit the city/municipality extent
2. Don't auto-select any DeSO (the user can now click one)
3. Optionally: highlight all DeSOs within the city boundary with a subtle outline pulse

**Postal Code:**
Postal codes in Sweden map roughly to neighborhoods. They often align loosely with 1-5 DeSOs:
1. Zoom to the postal code area
2. Find all DeSOs that intersect with the postal code centroid
3. If it resolves to 1 DeSO: auto-select it
4. If it resolves to multiple: highlight them all, let user click one

### 4.2 Point-in-DeSO Resolution (Backend)

Create an API endpoint that resolves a lat/lng to a DeSO code:

```php
Route::get('/api/geocode/resolve-deso', [GeocodeController::class, 'resolveDeso']);
```

```php
public function resolveDeso(Request $request)
{
    $request->validate([
        'lat' => 'required|numeric|between:55,70',
        'lng' => 'required|numeric|between:10,25',
    ]);

    $lat = $request->float('lat');
    $lng = $request->float('lng');

    $deso = DB::table('deso_areas')
        ->select('deso_code', 'deso_name', 'kommun_name', 'lan_name')
        ->whereRaw('ST_Contains(geom, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$lng, $lat])
        ->first();

    if (!$deso) {
        // Point might be slightly outside DeSO boundaries (coast, border)
        // Try nearest DeSO within 500m
        $deso = DB::table('deso_areas')
            ->select('deso_code', 'deso_name', 'kommun_name', 'lan_name')
            ->whereRaw('ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 500)', [$lng, $lat])
            ->orderByRaw('ST_Distance(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)', [$lng, $lat])
            ->first();
    }

    return response()->json([
        'deso' => $deso ? [
            'deso_code' => $deso->deso_code,
            'deso_name' => $deso->deso_name,
            'kommun_name' => $deso->kommun_name,
            'lan_name' => $deso->lan_name,
        ] : null,
    ]);
}
```

### 4.3 Search Result Marker

After zooming, place a temporary marker at the search result location. This shows the user exactly where their search resolved within the DeSO polygon.

Marker style:
- A small pin/dot, not a full Google Maps style pin
- Color: brand blue (`primary`) to distinguish from school markers (which are score-colored)
- Size: 10px circle with a 2px white border
- With a subtle drop animation on appearance
- Disappears when the user clicks elsewhere or clears the search

```tsx
// Create a marker feature for the search result
const searchMarkerSource = new VectorSource();
const searchMarkerLayer = new VectorLayer({
  source: searchMarkerSource,
  zIndex: 100,  // Above DeSO polygons and school markers
  style: new Style({
    image: new Circle({
      radius: 6,
      fill: new Fill({ color: '#3b82f6' }),     // blue-500
      stroke: new Stroke({ color: '#ffffff', width: 2 }),
    }),
  }),
});

function placeSearchMarker(lat: number, lng: number) {
  searchMarkerSource.clear();
  const feature = new Feature({
    geometry: new Point(fromLonLat([lng, lat])),
  });
  searchMarkerSource.addFeature(feature);
}

function clearSearchMarker() {
  searchMarkerSource.clear();
}
```

### 4.4 Full Flow

```
User types "Drottninggatan 53 Stockholm"
  → Debounce 300ms
  → Photon API returns suggestions
  → User clicks "Drottninggatan 53, 111 21, Stockholm"
  → Map smoothly zooms to coordinates (18.0686, 59.3293) at zoom 17
  → Blue pin appears at the exact location
  → POST /api/geocode/resolve-deso?lat=59.3293&lng=18.0686
  → Backend returns { deso_code: "0180C1060" }
  → DeSO "0180C1060" highlights on map
  → Sidebar populates with that DeSO's score, indicators, schools
  → User sees: "Your searched address is in DeSO 0180C1060 (Norrmalm)"

User types "114 34"
  → Photon returns "114 34, Stockholm, Stockholms län" (postal code)
  → Map zooms to postal code area (extent if available, or point + zoom 15)
  → Resolve DeSO from centroid
  → If single DeSO → auto-select
  → If multiple → highlight them, user clicks one

User types "Danderyd"
  → Photon returns "Danderyd, Stockholms län" (city/municipality)
  → Map zooms to fit Danderyd's extent (zoom ~12)
  → No DeSO auto-selected — user sees all DeSOs in Danderyd colored by score
  → User clicks one to see details

User types "Värmland"
  → Photon returns "Värmlands län" (state)
  → Map zooms to fit the whole län (zoom ~8)
  → No DeSO auto-selected
```

---

## Step 5: Postal Code Handling

### 5.1 The Swedish Postal Code System

Swedish postal codes are 5 digits (XXX XX, formatted with a space after the third digit). The first digit indicates the region (1 = Stockholm, 2 = Southern, 3-4 = Western, 5 = Eastern, 6-7 = Norrland, 8-9 = Northernmost).

Postal codes are relatively small areas — they map roughly to 1-5 DeSOs in urban areas and can cover larger areas in rural zones.

### 5.2 Detecting Postal Code Input

If the user types what looks like a Swedish postal code (3-5 digits, optionally with a space after digit 3), treat it as a postal code search:

```tsx
function isPostalCode(query: string): boolean {
  const cleaned = query.replace(/\s/g, '');
  return /^\d{3}\s?\d{2}$/.test(query.trim()) || /^\d{5}$/.test(cleaned);
}
```

When detected, you can use Photon with a structured-style query or just pass it as free text — Photon handles Swedish postal codes well.

### 5.3 Postal Code → DeSO Mapping

Since postal code areas don't align perfectly with DeSOs, use the centroid approach:
1. Geocode the postal code → get centroid coordinates
2. Resolve the centroid to a DeSO via PostGIS
3. If the postal code extent covers multiple DeSOs, highlight them all

---

## Step 6: Search Clearing and Reset

### 6.1 Clear Button

The X button in the search bar clears the search:
- Clear the input text
- Remove the search pin from the map
- Deselect any auto-selected DeSO
- Reset the sidebar to the default "Click a DeSO" state
- Don't change the map zoom/position (user stays where they are)

### 6.2 Escape Key

Pressing Escape while the search bar is focused:
- If dropdown is open: close the dropdown
- If dropdown is closed: clear the search and blur the input

### 6.3 New Search Replaces Old

If the user searches for a new location while a previous search is still showing:
- Remove the old search pin
- Deselect the old DeSO
- Proceed with the new search as normal

---

## Step 7: Mobile Behavior

### 7.1 Mobile Search Bar

On mobile (<768px), the search bar takes full width minus padding:

```
┌──────────────────────────────────┐
│ 🔍 Search address or area     ✕  │
├──────────────────────────────────┤
│                                  │
│            Map                   │
│                                  │
│                                  │
├──────────────────────────────────┤
│        Bottom Sheet              │
└──────────────────────────────────┘
```

When the search bar is focused on mobile:
- Expand the dropdown to cover the map (full-screen overlay with results)
- After selection, the overlay closes and the map zooms to the result
- The bottom sheet shows the DeSO data

### 7.2 Touch-Friendly Suggestions

On mobile, suggestion rows should be taller (min 48px touch target) and the text slightly larger.

---

## Step 8: Edge Cases

### 8.1 No Results

If Photon returns no results:
```
┌────────────────────────────────────┐
│ 🔍 asdfqwerty                   ✕  │
├────────────────────────────────────┤
│                                    │
│   No results found in Sweden.      │
│   Try a street name, postal code,  │
│   or city name.                    │
│                                    │
└────────────────────────────────────┘
```

### 8.2 API Failure

If Photon is down or returns an error:
- Don't show an error in the dropdown (confusing)
- Show nothing, or a subtle "Search unavailable" message
- Log the error for monitoring
- Consider a Nominatim fallback for the final geocode (not for autocomplete)

### 8.3 Result Outside Sweden

Even with the bbox filter, Photon might return results outside Sweden (e.g., "Stockholm" in Wisconsin). Filter these out in the `parsePhotonFeature` function by checking `countrycode === 'SE'`.

### 8.4 Water/Wilderness Points

A search result might land in water (an island name, a lake) or deep wilderness where there's no DeSO. The `resolve-deso` endpoint returns null. In this case:
- Still zoom to the location
- Place the pin
- Sidebar shows: "This location is not within a statistical area (DeSO). Click a nearby colored area for details."

### 8.5 Duplicate/Similar Results

Photon may return multiple hits for the same street in different cities. The secondary text (city + län) disambiguates these. Deduplicate by `osm_id` if Photon returns the same object twice.

---

## Step 9: URL State

### 9.1 Sync Search to URL

When a search result is selected, update the URL so the location is shareable:

```
/?search=Drottninggatan+53+Stockholm&lat=59.3293&lng=18.0686&zoom=17
```

Or more concisely:
```
/?q=Drottninggatan+53+Stockholm
```

When the page loads with a `q` parameter:
1. Populate the search bar with the query
2. Geocode it
3. Zoom to the first result
4. Auto-select the DeSO

This makes search results linkable — a user can share "here's the score for my street."

### 9.2 Coexistence with DeSO URL State

If the app already has URL state for selected DeSO (`?deso=0180C1060`), the search parameter should coexist:
```
/?q=Drottninggatan+53&deso=0180C1060
```

The `q` drives the zoom position and pin. The `deso` drives the sidebar selection. If only `q` is present, resolve the DeSO from the coordinates.

---

## Step 10: Keyboard Shortcut

### 10.1 Focus Search

Pressing `/` (slash) anywhere on the page focuses the search bar. This is a standard convention (GitHub, YouTube, Google Maps).

```tsx
useEffect(() => {
  const handler = (e: KeyboardEvent) => {
    if (e.key === '/' && !isInputFocused()) {
      e.preventDefault();
      searchInputRef.current?.focus();
    }
  };
  document.addEventListener('keydown', handler);
  return () => document.removeEventListener('keydown', handler);
}, []);
```

Show a subtle hint in the search bar placeholder on desktop: `Search address or area (/)`.

---

## Step 11: Verification

### 11.1 Search Scenarios to Test

| Input | Expected behavior |
|---|---|
| `Drottninggatan 53 Stockholm` | Zoom to house, pin at location, auto-select DeSO |
| `114 34` | Zoom to postal code area, auto-select DeSO |
| `11434` | Same as above (without space) |
| `Danderyd` | Zoom to municipality extent, no auto-select |
| `Rinkeby` | Zoom to neighborhood, auto-select DeSO (or highlight cluster) |
| `Värmland` | Zoom to län, no auto-select |
| `Stockholm` | Zoom to city, no auto-select |
| `Kungsgatan` | Show multiple results (Stockholm, Göteborg, etc.), user picks one |
| `asdfqwerty` | "No results found" message |
| `Berlin` | Filtered out (not in Sweden) |

### 11.2 Visual Checklist

- [ ] Search bar visible on map (upper-left, overlaying map)
- [ ] Typing 3+ characters triggers autocomplete suggestions
- [ ] Suggestions show name + secondary info + type icon
- [ ] Keyboard navigation works (up/down/enter/escape)
- [ ] Selecting a result zooms the map smoothly
- [ ] Blue pin appears at the search location
- [ ] DeSO auto-selects for house/street results
- [ ] Sidebar populates with DeSO data after auto-select
- [ ] City/municipality results zoom to extent without auto-selecting a DeSO
- [ ] Postal codes work (both formats: "114 34" and "11434")
- [ ] Clear button removes pin, deselects DeSO, keeps map position
- [ ] `/` key focuses the search bar
- [ ] URL updates with search query (shareable)
- [ ] Loading with `?q=...` in URL geocodes and zooms on page load
- [ ] Mobile: search works as full-width overlay
- [ ] No results: shows helpful message
- [ ] Results outside Sweden are filtered out
- [ ] Water/wilderness results: pin shows but sidebar says "not in DeSO"

---

## Notes for the Agent

### Photon API is Called Directly from the Frontend

The geocoding calls go from the browser directly to `photon.komoot.io`. No backend proxy needed. This keeps latency low (no round-trip through our server) and avoids our backend being a bottleneck for typeahead.

The only backend call is `resolve-deso` after a result is selected — this resolves the coordinates to a DeSO code via PostGIS.

### Rate Limiting Yourself

Even though Photon doesn't publish hard rate limits, be respectful:
- 300ms debounce (don't fire on every keystroke)
- Cancel in-flight requests when user types more (`AbortController`)
- Limit to 7 results per request
- Cache results briefly in memory (if user deletes a character and retypes, reuse cached results)

### Don't Build a Backend Geocoding Proxy (Yet)

For v1, call Photon directly from the frontend. If Photon's public API becomes unreliable or we hit rate limits, we can later:
1. Self-host Photon (Sweden-only data, ~5GB index, runs on any $20 VPS)
2. Or proxy through our backend with caching

But that's premature optimization. The public API handles moderate traffic fine.

### The resolve-deso Endpoint is Fast

PostGIS `ST_Contains` with a spatial index is sub-millisecond for point-in-polygon on 6,160 DeSOs. No optimization needed.

### What NOT to Do

- Don't use Nominatim for autocomplete — their usage policy explicitly bans it
- Don't proxy all Photon requests through the backend — adds latency
- Don't show 20 results in the dropdown — 5-7 is plenty
- Don't geocode on every keystroke — debounce 300ms minimum
- Don't auto-select a DeSO for city/municipality results — it's confusing ("I searched for Danderyd, why did it select one random DeSO?")
- Don't build a custom geocoder from our DeSO data — OSM geocoding is way better
- Don't block the search bar while the resolve-deso call is in-flight — zoom to the result immediately, highlight the DeSO when the response comes back

### What to Prioritize

1. **Search bar with autocomplete** — the core feature
2. **Zoom to result** — makes the search useful
3. **DeSO auto-select for address results** — connects search to our data
4. **Search pin** — visual feedback on where the result is
5. **Keyboard shortcut** — small but makes power users happy
6. **URL state** — enables sharing, nice to have
7. **Postal code detection** — common Swedish search pattern
8. **Mobile** — bottom of priority for now, but the layout should be mobile-aware