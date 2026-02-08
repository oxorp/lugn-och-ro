# TASK: Adjust Proximity Radius Values

## Context

The proximity scoring task set some max distances too high (3km unlock radius was discussed) and others need fine-tuning based on actual Swedish walking behavior and real estate research. This task updates the max distance per category to realistic values.

## The Change

Update `ProximityScoreService` and `poi_category_settings` (if the safety-modulated task has landed) with these max distances:

| Category | Old max_distance_m | New max_distance_m | Rationale |
|---|---|---|---|
| School (grundskola) | 2000 | **2000** | No change. Parents walk/bike 2km for the right school. Swedish property premium for school proximity drops off sharply after ~1.5km but 2km catches the long tail. |
| Green space / park | 1000 | **1500** | People walk further for a good park. Tantolunden, Hagaparken — 15-18 min walk is normal for a park outing. |
| Transit stop | 1000 | **1000** | No change. If the stop is further than 1km you're not walking to it daily. |
| Grocery | 1000 | **1000** | No change. Grocery beyond 1km means you're driving/biking, not walking. |
| Negative POIs | 500 | **500** | No change. Only matters if very close. |
| Positive POIs (café, gym, etc.) | 1000 | **1000** | No change. |
| Cinema / entertainment | 1500 | **1500** | No change. People accept a longer walk for discretionary evening activities — but the safety modulation (separate task) handles the effective distance inflation in unsafe areas. |

**Net change: only park moves from 1000 → 1500.** The other values were already reasonable. The real issue (3km radius) was about the unlock/display radius discussed in the pin architecture, not the proximity scoring per se.

## Implementation

### If `poi_category_settings` table exists (safety task landed):

```sql
UPDATE poi_category_settings SET max_distance_m = 1500 WHERE category = 'park';
```

Or update the seeder and re-run.

### If settings are still hardcoded in ProximityScoreService:

Find each scorer method and verify the `$maxDistance` variable matches the table above. The only change is `scoreGreenSpace`:

```php
private function scoreGreenSpace(float $lat, float $lng): ProximityFactor
{
    $maxDistance = 1500; // was 1000
    // ... rest unchanged
}
```

### Also verify the unlock/display radius

The heatmap pin architecture task may reference a display radius for "show schools within X km of pin." This should also be **1.5km** as the default display radius, with schools extended to **2km**. Check `LocationController` or wherever the school query lives:

```php
// Schools: 2km radius from pin
->whereRaw("ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 2000)", [$lng, $lat])

// Other POIs: 1.5km radius from pin  
->whereRaw("ST_DWithin(geom::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 1500)", [$lng, $lat])
```

## Verification

- [ ] `scoreGreenSpace` uses 1500m max distance
- [ ] All other categories unchanged from proximity task values
- [ ] School query radius remains 2000m
- [ ] Sidebar school list shows schools up to 2km away
- [ ] A park at 1.2km still contributes to the proximity score (would have been zero at old 1000m max)
- [ ] A park at 1.6km contributes nothing (beyond 1500m)