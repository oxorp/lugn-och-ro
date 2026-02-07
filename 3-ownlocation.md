# TASK: Geolocation — Locate Me Button with Sweden Boundary Check

## Context

Every map product has a "locate me" button. Google Maps, Apple Maps, Hemnet — users expect it. Ours needs one too, with one twist: if the user is outside Sweden, we need to handle that gracefully instead of zooming to a random field in Germany or a living room in New York.

This is a small task with outsized UX impact. It takes 30 minutes to build and makes the product feel 10x more polished.

---

## Step 1: The Button

### 1.1 Placement

Add a locate-me button to the map controls, positioned in the right-side control cluster:

```
┌─────────┐
│  + / -  │  ← zoom
│  🔍     │  ← search (from search task)
│  ⇔      │  ← compare (from compare task)
│  ◎      │  ← LOCATE ME (this task)
│  🗺️     │  ← layer toggle
└─────────┘
```

### 1.2 Icon

Use a standard crosshair/target icon. Not the filled location pin (that's for search results). Use Lucide's `LocateFixed` or `Crosshair` icon.

- Default state: slate-500 icon, white background, same style as other map controls
- Hover: slate-700 icon
- Active/locating: pulsing blue dot animation (like iOS Maps when acquiring GPS)
- Located: blue-500 icon (indicates "currently tracking")
- Error: brief red flash, then return to default

### 1.3 Button Size

Same dimensions as other map control buttons. 36×36px touch target minimum (44×44px on mobile for accessibility).

---

## Step 2: Geolocation Flow

### 2.1 On Click

```
User clicks ◎
  → Check if Geolocation API is available
    → No: show toast "Location services not available in your browser"
    → Yes: request position
      → User denies permission: show toast "Location access denied. Enable it in browser settings."
      → User grants permission:
        → Acquiring: show pulsing animation on button
        → Got position:
          → Check if inside Sweden bounding box
            → Yes: zoom to location, place marker, select containing area
            → No: show toast "You appear to be outside Sweden. The map shows Swedish neighborhoods only."
        → Error (timeout, unavailable):
          → Show toast "Couldn't determine your location. Try again."
```

### 2.2 Browser Geolocation API

```typescript
function locateUser(): Promise<GeolocationPosition> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject(new Error('Geolocation not supported'));
      return;
    }

    navigator.geolocation.getCurrentPosition(resolve, reject, {
      enableHighAccuracy: true,    // Use GPS if available
      timeout: 10000,              // 10 second timeout
      maximumAge: 60000,           // Accept cached position up to 1 minute old
    });
  });
}
```

### 2.3 Sweden Bounding Box Check

```typescript
const SWEDEN_BOUNDS = {
  minLat: 55.0,    // Southernmost point (Smygehuk)
  maxLat: 69.1,    // Northernmost point (Treriksröset)
  minLng: 10.5,    // Westernmost point
  maxLng: 24.2,    // Easternmost point (Haparanda area)
};

function isInSweden(lat: number, lng: number): boolean {
  return (
    lat >= SWEDEN_BOUNDS.minLat &&
    lat <= SWEDEN_BOUNDS.maxLat &&
    lng >= SWEDEN_BOUNDS.minLng &&
    lng <= SWEDEN_BOUNDS.maxLng
  );
}
```

**Note:** This is a bounding box check, not a true polygon check. It will accept some points in Norway, Finland, and Denmark that fall within the bounding box. That's fine — those edge cases are rare and the DeSO resolution step will catch them (the point won't be inside any DeSO polygon). A bounding box is sufficient for the "are you roughly in Scandinavia?" check.

If you want precision: the backend already has Sweden's DeSO polygons. You could POST the coordinates to a backend endpoint that does `ST_Contains` against the DeSO union. But for the initial "should we even try to zoom there?" decision, the bounding box is enough.

---

## Step 3: Map Behavior After Location

### 3.1 Zoom to Location

When a valid Swedish position is obtained:

1. **Animate** the map to the user's position (smooth transition, ~800ms duration)
2. **Zoom** to level 14 (shows a few city blocks — appropriate for "where am I?")
3. **Place a marker** at the exact position: blue circle with a subtle pulse animation, different from search pins and compare pins
4. **Select the containing area:** Resolve which H3 cell or DeSO the point falls in and select it, populating the sidebar with that area's score data

```typescript
function zoomToLocation(lat: number, lng: number) {
  const view = map.getView();
  view.animate({
    center: fromLonLat([lng, lat]),
    zoom: 14,
    duration: 800,
  });
}
```

### 3.2 Location Marker Style

The user's location marker should be visually distinct from:
- Search result pins (blue solid circle with label)
- Compare pins (blue A / orange B circles)
- School markers (colored by quality)

Use:
- **Outer ring:** 16px diameter, blue-500, 2px stroke, white fill with 50% opacity
- **Inner dot:** 8px diameter, blue-500, solid fill
- **Pulse animation:** The outer ring expands to 24px and fades out, repeating every 2 seconds
- **Accuracy circle:** If the geolocation accuracy is > 100m, show a translucent blue circle representing the accuracy radius. Hide it if accuracy is < 100m.

### 3.3 Accuracy Handling

The Geolocation API returns `coords.accuracy` in meters. If accuracy is very poor (> 1000m), zoom out to accommodate the uncertainty:

| Accuracy | Zoom Level | Note |
|---|---|---|
| < 50m | 15 | Street level, very precise |
| 50–200m | 14 | Good enough for neighborhood |
| 200–500m | 13 | Show surrounding area |
| 500–2000m | 12 | City-level, less useful |
| > 2000m | 11 | Show warning: "Location is approximate" |

---

## Step 4: Ongoing Tracking (Optional)

### 4.1 Single Shot vs Continuous

For v1, use **single shot** (`getCurrentPosition`). The user clicks the button, gets their position, done. The marker stays until they navigate away or click something else.

Do NOT use `watchPosition` for continuous tracking — it drains battery, complicates state management, and isn't needed for this use case. The user is sitting at their computer looking at neighborhoods, not navigating in a car.

### 4.2 Re-click Behavior

If the user clicks the locate button again:
- If the current map view is already centered on their location (within 100m): zoom in one level (up to max 16)
- If the map has been panned away: re-center on their location with animation

This mimics the Google Maps behavior: first click centers, second click zooms in closer.

---

## Step 5: Selecting the User's Area

### 5.1 Auto-Select

After zooming to the user's location, automatically select the containing H3 cell / DeSO area, just as if the user had clicked on it. This populates the sidebar with the score for their neighborhood.

Resolution:
```typescript
// Frontend resolves which hex/polygon the point falls in
// Option A: Check which rendered feature contains the point
const features = map.getFeaturesAtPixel(map.getPixelFromCoordinate(fromLonLat([lng, lat])));

// Option B: Call backend to resolve
const response = await fetch('/api/geocode/resolve-area', {
  method: 'POST',
  body: JSON.stringify({ lat, lng }),
});
// Returns: { h3_index: "...", deso_code: "...", ... }
```

Option A is faster (no network call) but depends on the hex/polygon layer being loaded. Option B is more reliable. Use Option A with Option B as fallback.

### 5.2 "You're Here" Badge

In the sidebar, when the displayed area is the user's located area, show a small badge: "📍 You're here" next to the area name. This confirms the connection between their location and the displayed data.

---

## Step 6: Outside-Sweden Handling

### 6.1 Friendly Message

If the user's position is outside the Sweden bounding box, show a toast notification (not an alert, not a modal):

```
📍 You appear to be outside Sweden.
This map covers Swedish neighborhoods only.
Use the search bar to explore any area in Sweden.
```

The toast appears for 5 seconds, then auto-dismisses. Use shadcn's `Toast` component.

### 6.2 Don't Move the Map

When the user is outside Sweden, do NOT zoom to their position (which would take them off the Sweden map entirely). Keep the current map view. The toast is the only feedback.

### 6.3 VPN / Inaccurate Geolocation

Some users may be in Sweden but get a position outside the bounds (VPN, Wi-Fi geolocation error, etc.). The toast message doesn't say "you are not in Sweden" — it says "you *appear* to be outside Sweden." This softens the claim. If the user knows they're in Sweden, they can use the search bar instead.

---

## Step 7: Permission Handling

### 7.1 Permission States

The browser Permissions API lets you check geolocation permission state:

```typescript
async function checkLocationPermission(): Promise<'granted' | 'denied' | 'prompt'> {
  if (!navigator.permissions) return 'prompt'; // Fallback
  const result = await navigator.permissions.query({ name: 'geolocation' });
  return result.state;
}
```

Use this to adjust the button's appearance:
- **'prompt'** (default): Normal icon, clicking triggers browser permission dialog
- **'granted'**: Normal icon, clicking immediately geolocates
- **'denied'**: Dimmed icon, clicking shows "Location access is blocked. Enable it in your browser settings at [browser-specific instructions]."

### 7.2 Don't Ask Pre-emptively

Never call `getCurrentPosition` on page load. Only geolocate when the user explicitly clicks the locate button. Pre-emptive geolocation requests annoy users and may be blocked by browsers.

---

## Step 8: Integration with Other Features

### 8.1 Search Integration

The locate-me result should integrate with the search flow:
- If search is active (search bar has text), clicking locate-me clears the search and locates
- The location marker is cleared when the user starts a new searcah

### 8.2 Compare Integration

In compare mode, clicking locate-me uses the user's position as Pin A (the "home base"). This enables the natural flow: "Where am I? Now let me compare my area with this other neighborhood I'm considering."

### 8.3 Geolocation Result in URL

Optionally: add `?loc=59.33,18.07` to the URL after locating. This is NOT a shareable link to the user's exact location (that would be a privacy concern). Instead, it's a convenience for the user's own browser — if they refresh the page, it remembers their last located position. Clear this parameter when the user navigates away from their location.

---

## Step 9: Mobile

### 9.1 Mobile Location

On mobile, geolocation is typically more accurate (GPS) and users expect it more strongly. The button should be in the same control cluster but sized for touch (44×44px minimum).

### 9.2 iOS Safari Permissions

iOS Safari requires HTTPS for geolocation. Our production site should be HTTPS, but verify during development. localhost is exempt.

### 9.3 Battery Considerations

Single-shot `getCurrentPosition` has minimal battery impact. If we ever add continuous tracking (`watchPosition`), add a visible indicator that tracking is active and a way to stop it.

---

## Step 10: Verification

### 10.1 Test Scenarios

Test from different locations / simulated positions:

| Scenario | Expected behavior |
|---|---|
| User in Stockholm | Zoom to position, select area, show score |
| User in Malmö | Zoom to position, select area, show score |
| User in Kiruna (northern Sweden) | Zoom to position, may be sparse data |
| User in Oslo (outside Sweden) | Toast: "appear to be outside Sweden", map stays |
| User in New York | Toast: "appear to be outside Sweden", map stays |
| User on Sweden/Norway border | Bounding box may accept it, DeSO lookup may fail → show "not in a scored area" |
| Permission denied | Toast: "Location access denied" |
| Geolocation timeout | Toast: "Couldn't determine your location" |
| Browser doesn't support geolocation | Toast: "Location services not available" |
| Second click (already located) | Zoom in closer |
| Click after panning away | Re-center on location |

### 10.2 Simulating Position in Browser

For testing, use Chrome DevTools → Sensors → Geolocation override. Set to various coordinates:
- Stockholm: 59.3293, 18.0686
- Malmö: 55.6050, 13.0038
- Kiruna: 67.8558, 20.2253
- Oslo: 59.9139, 10.7522
- New York: 40.7128, -74.0060

### 10.3 Checklist

- [ ] Locate-me button visible in map controls
- [ ] Button has correct icon (crosshair/target, not pin)
- [ ] Clicking triggers browser permission prompt (first time)
- [ ] Pulsing animation while acquiring position
- [ ] Smooth zoom animation to user's position
- [ ] Location marker with pulse effect appears at position
- [ ] Containing area auto-selected, sidebar shows score
- [ ] "📍 You're here" badge in sidebar
- [ ] Outside Sweden: toast message, map doesn't move
- [ ] Permission denied: clear error message
- [ ] Timeout: clear error message with retry suggestion
- [ ] Second click: re-centers if panned away, zooms in if already centered
- [ ] Works in compare mode (position becomes Pin A)
- [ ] Marker disappears when user starts a new search
- [ ] Mobile: button is touch-accessible (44px target)
- [ ] No geolocation call on page load (only on click)

---

## Notes for the Agent

### This Is a Quick Win

Don't over-engineer this. The core implementation is:
1. A button
2. `getCurrentPosition()`
3. A bounding box check
4. `view.animate()` to move the map
5. A marker feature
6. Select the area

That's ~100 lines of code. The edge case handling (permissions, errors, outside Sweden) adds another 50. Total: small task, big impact.

### The Pulse Animation Matters

The pulsing blue dot at the user's location is a small detail that makes the product feel alive. It's the iOS Maps / Google Maps convention. Users recognize it instantly as "you are here." Without the pulse, it's just another static dot. With it, it's *your* location.

CSS animation:
```css
@keyframes pulse {
  0% { transform: scale(1); opacity: 1; }
  100% { transform: scale(2.5); opacity: 0; }
}
```

Or in OpenLayers, animate the outer circle's radius and opacity on a requestAnimationFrame loop.

### Sweden Bounding Box Is Generous

The bbox (55.0–69.1, 10.5–24.2) includes bits of Norway, Denmark, and Finland. That's intentional — it's better to accept a point that's technically 5km into Norway (and then fail gracefully at DeSO lookup) than to reject a user who's in Strömstad near the border. The DeSO/H3 spatial join is the real boundary check. The bbox is just a fast pre-filter.

### Don't Store User Locations

We do NOT store or log the user's geolocation coordinates. No backend call to save their position. No analytics on where users are located. This is a purely client-side feature — the coordinates are used to zoom the map and that's it. If we add the `?loc=` URL parameter, it's in the user's own browser history only.

### What NOT to Do

- Don't auto-geolocate on page load
- Don't use `watchPosition` (continuous tracking) — single shot only
- Don't store user coordinates on the server
- Don't show a native browser `alert()` — use toast notifications
- Don't move the map if the user is outside Sweden
- Don't block the UI while acquiring position — it's an async operation, the map should remain interactive