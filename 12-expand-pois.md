# POI Expansion — Environmental & Industrial Nuisances

## Context

The current negative POI list focuses on social indicators (gambling, pawn shops, cash businesses). This expansion adds **environmental and industrial nuisances** — physical properties of the surroundings that depress real estate values through smell, noise, visual blight, or health concerns. These are often bigger price killers than social POIs because they're permanent and can't be "gentrified away."

Research consistently shows 5-15% property value reduction within 1-2 km of major industrial nuisances, with sewage plants and landfills being the worst offenders.

---

## Updated Negative POI Table

Replace section 3.5.1 in `data_pipeline_specification.md` with:

### Category A: Environmental & Industrial Nuisances (Smell / Noise / Health)

| POI Type | OSM Tag / Source | Signal | Impact Radius | Swedish Context |
|---|---|---|---|---|
| **Paper & pulp mills** (massabruk) | OSM: `man_made=works` + `product=paper`/`product=pulp`; Google Places | Sulfur smell, carries 5-10 km downwind. One of Sweden's most distinctive industrial odors | 5 km | Major mills in Sundsvall, Örnsköldsvik, Karlsborg, Hyltebruk, Hallstavik, Nymölla. ~40 active mills |
| **Wastewater treatment plants** (reningsverk) | OSM: `man_made=wastewater_plant` | Persistent sewage odor, especially in summer. Documented 9-12% value reduction in proximity studies | 2 km | Every kommun has at least one. ~1,700 municipal plants. Stockholm's Henriksdal is massive but underground |
| **Landfills & waste dumps** (avfallsanläggning) | OSM: `landuse=landfill`; Naturvårdsverket register | Odor, vermin, truck traffic, groundwater risk. Effect persists years after closure | 3 km | ~300 active + ~6,000 historic (many closed but still mapped). Kovik, Högbytorp, Hovgården are large ones |
| **Waste incineration plants** (förbränningsanläggning) | OSM: `man_made=works` + `product=waste`; `power=plant` + `plant:source=waste` | Air quality concerns, truck traffic, visual impact. Often combined with district heating | 2 km | ~40 plants. Högdalen (Stockholm), Sävenäs (Gothenburg), Sysav (Malmö) |
| **Quarries & gravel pits** (täkter) | OSM: `landuse=quarry` | Blasting noise, dust, heavy truck traffic. Permanent scar on landscape | 2 km | ~3,000 active permits. Skanska, NCC, Swerock are major operators |
| **Heavy industrial zones** | OSM: `landuse=industrial` (large polygons > 50,000 m²) | Noise, traffic, visual blight, potential contamination | 1 km | Filter for large industrial areas only — small workshops are neutral |
| **Oil refineries & chemical plants** | OSM: `man_made=works` + `product=oil`/`product=chemical`; `industrial=refinery` | Flaring, chemical smell, explosion risk perception, air quality | 5 km | Preemraff Lysekil, Preemraff Gothenburg, Borealis Stenungsund, SSAB Luleå/Oxelösund |
| **Power plants** (fossil/nuclear) | OSM: `power=plant` + `plant:source=coal`/`gas`/`nuclear` | Noise, cooling towers, radiation anxiety (nuclear), transmission lines | 3 km (fossil), 10 km (nuclear) | Nuclear: Forsmark, Ringhals, Oskarshamn. Few fossil plants remain |
| **Shooting ranges** (skjutbanor) | OSM: `sport=shooting`; `leisure=pitch` + `sport=shooting` | Intermittent loud noise, lead contamination in soil | 1.5 km | Military and civilian. ~1,000+ ranges. Many near residential areas in smaller towns |
| **Airports & airfields** | OSM: `aeroway=aerodrome` | Flight noise, especially approach/departure paths | 5 km (major), 2 km (small) | Arlanda, Landvetter, Sturup, Bromma. Also ~100+ smaller airfields |
| **Major highway interchanges** | OSM: `highway=motorway_junction` (large motorway links) | Constant traffic noise, air pollution (PM2.5, NO₂) | 500 m | E4, E6, E18, E20 corridors. Essingeleden in Stockholm is notorious |
| **Railway marshalling yards** (rangerbangårdar) | OSM: `railway=station` + `station=yard`; `landuse=railway` | Noise (shunting, braking), diesel fumes, vibration | 1 km | Hagalund (Solna), Malmö rangerbangård, Gothenburg Sävenäs |
| **Contaminated land** (förorenad mark) | Naturvårdsverket EBH-stödet database; Länsstyrelsen registers | Stigma, health risk, development restrictions | 1 km | ~85,000 potentially contaminated sites in Sweden's register. Most untreated |
| **Wind turbines** (vindkraftverk) | OSM: `power=generator` + `generator:source=wind` | Noise, shadow flicker, visual impact. Controversial in Sweden | 1 km | ~5,000 turbines. Rural DeSOs affected. Politically divisive |

### Category B: Social & Economic Nuisance POIs (Existing + Expanded)

| POI Type | OSM Tag / Source | Signal | Impact Radius |
|---|---|---|---|
| Mosques / Islamic prayer halls | `amenity=place_of_worship` + `religion=muslim`; SST; Bolagsverket | Demographic composition proxy | DeSO-level |
| Gambling venues | Svenska Spel, ATG shops; OSM: `shop=bookmaker`, `leisure=adult_gaming_centre` | Financial distress correlation | 500 m |
| Pawn shops (pantbank) | OSM: `shop=pawnbroker`; Google Places | Financial distress marker | 500 m |
| Payday loan offices | Finansinspektionen register + Google Maps | Financial distress marker | 500 m |
| Late-night fast food clusters | OSM: `amenity=fast_food` (density metric, not individual) | Nighttime economy/disturbance proxy | DeSO-level density |
| Cash-intensive businesses | Bolagsverket SNI codes + OSM | Money laundering indicators | DeSO-level density |
| **Homeless shelters** (härbärgen) | OSM: `social_facility=shelter` + `social_facility:for=homeless`; kommun websites | Area distress indicator | 500 m |
| **Methadone/substitution clinics** | Google Places; kommun healthcare listings | Substance abuse concentration | 500 m |
| **Sex shops / adult entertainment** | OSM: `shop=erotic`; `amenity=stripclub` | Nightlife/seediness signal | 500 m |
| **Prisons / detention centers** (anstalter) | OSM: `amenity=prison`; Kriminalvården.se | Stigma, perceived safety | 2 km |
| **Large parking structures** (>200 spaces) | OSM: `amenity=parking` + `parking=multi-storey` (large) | Visual blight, car-centric planning, crime magnet at night | 300 m |
| **Systembolaget** (state liquor stores) | systembolaget.se API; OSM: `shop=alcohol` + `brand=Systembolaget` | Neutral individually, but cluster density with bars/nightlife = indicator | Cluster metric |
| **Nightclub clusters** | OSM: `amenity=nightclub` (density) | Noise, weekend disturbance, fights | Cluster density |

### Category C: Infrastructure Nuisances

| POI Type | OSM Tag / Source | Signal | Impact Radius |
|---|---|---|---|
| **High-voltage power lines** (>130 kV) | OSM: `power=line` + `voltage≥130000`; Svenska Kraftnät | Visual blight, EMF anxiety, limits building | 200 m corridor |
| **Mobile phone towers** (master) | OSM: `man_made=mast` + `tower:type=communication` | Visual impact, EMF anxiety (irrational but real price impact) | 300 m |
| **Train tracks** (main line) | OSM: `railway=rail` + `usage=main` | Noise, vibration, barrier effect | 300 m |
| **Busy roads** (>15,000 AADT) | Trafikverket traffic count data; OSM: `highway=trunk`/`primary` | Noise, PM2.5, barrier | 200 m |
| **Bus depots** | OSM: `amenity=bus_station` (large); Google Places | Diesel fumes, early morning noise | 500 m |
| **Recycling stations** (återvinningsstationer) | OSM: `amenity=recycling` (large ones only) | Noise (glass containers), weekend traffic, visual disorder | 200 m |

---

## Updated Positive POI Table

Replace section 3.5.2 in `data_pipeline_specification.md` with:

### Category D: Quality of Life Indicators (Positive)

| POI Type | Source | Signal | Impact Radius |
|---|---|---|---|
| High-performing schools (top quartile) | Skolverket API | Strongest single price driver for families | 2 km catchment |
| **International schools** | Skolverket; Google Places | Expat/high-income family signal | 5 km |
| Premium grocery (Paradiset, ICA Kvantum, Coop Extra) | OSM/chain data | Affluence indicator | 1 km |
| **Farmers markets** (bondens marknad) | OSM: `amenity=marketplace`; bondens.se | Affluent, food-conscious demographic | 1 km |
| Gyms, padel courts, premium fitness | OSM: `leisure=fitness_centre`; Google Places | Active lifestyle demographics | 1 km |
| Specialty coffee / high-end cafés | OSM: `amenity=cafe` + `cuisine=coffee_shop` (selective) | Gentrification indicator | 500 m |
| **Bookshops** | OSM: `shop=books` | Educated, high-cultural-capital demographic | 500 m |
| **Pharmacies** (apotek) | OSM: `amenity=pharmacy` | Service level indicator | 1 km |
| **Libraries** (bibliotek) | OSM: `amenity=library` | Civic investment, family-friendly | 1 km |
| **Parks & green space** (>1 ha) | OSM: `leisure=park` (area > 10,000 m²) | 5-15% premium for adjacent properties. Universal across all markets | 500 m |
| **Waterfront** | OSM: `natural=water` + `water=lake`/`river`/`sea`; coastline | 10-25% premium in Swedish market. Sjötomt is king | 500 m |
| **Nature reserves** (naturreservat) | OSM: `leisure=nature_reserve`; Naturvårdsverket | Guaranteed green buffer, recreation access | 1 km |
| **Marinas & boat clubs** (båtklubbar) | OSM: `leisure=marina` | Affluence + waterfront lifestyle | 1 km |
| **Swimming facilities** (badplatser, simhallar) | OSM: `leisure=swimming_pool`, `leisure=bathing_place` | Family-friendly, quality of life | 1 km |
| **Cultural venues** (museums, theaters) | OSM: `tourism=museum`, `amenity=theatre` | Cultural capital, educated demographic | 1 km |
| **Medical centers** (vårdcentral) | OSM: `amenity=clinic`, `amenity=doctors` | Essential service proximity | 2 km |
| **New construction** (nyproduktion) | Building permits data; OSM: `building=construction` | Developer confidence signal — developers don't build where values are falling | DeSO-level |

---

## Updated POI Sources

| Source | URL | What It Gives Us |
|---|---|---|
| OpenStreetMap Overpass API | https://overpass-api.de/api/interpreter | Free. Best for: industrial facilities, infrastructure, parks, water, transport |
| Google Places API | https://maps.googleapis.com/maps/api/place/ | Paid. Best for: commercial POIs (clinics, sex shops, specialty stores) |
| Naturvårdsverket EBH-stödet | https://www.naturvardsverket.se/ebh-stodet | Contaminated land register. Free but may need agreement |
| Naturvårdsverket skyddad natur | https://skyddadnatur.naturvardsverket.se/ | Nature reserves, national parks. Free GIS data |
| Trafikverket NVDB | https://nvdb2012.trafikverket.se/SesijFrontPage.html | Road traffic volumes (AADT). Free |
| Svenska Kraftnät | https://www.svk.se/ | Power grid/transmission lines. Some open data |
| Kriminalvården | https://www.kriminalvarden.se/hitta-och-kontakta-oss/vara-anstalter/ | Prison locations. Public list |
| SST (faith communities) | https://www.myndighetensst.se/ | Religious organizations with state funding |
| Bolagsverket | https://www.bolagsverket.se/ | Company register (for SNI-code based lookups) |
| Systembolaget | https://www.systembolaget.se/api/ | Liquor store locations. Public API |
| Bondens Marknad | https://www.bondensegen.com/ | Farmers market locations |
| Skolverket | https://api.skolverket.se/ | School locations + quality data |

---

## Implementation Notes

### Impact Radius Logic

Not all POIs have equal range. A paper mill's sulfur stench carries 5-10 km. A recycling station's noise fades at 200 m. The aggregation must use **distance-weighted scoring**, not simple presence/absence:

```
poi_impact = Σ (weight_i × decay(distance_i, radius_i))

where decay(d, r) = max(0, 1 - (d / r)²)  // quadratic decay to zero at radius
```

This means a wastewater plant 500 m away contributes heavily, while one 1.8 km away barely registers. A quarry at 3 km is effectively zero.

### Size Matters

A 50-person workshop tagged `landuse=industrial` is different from SSAB Oxelösund. Filter by:
- Industrial areas: only polygons > 50,000 m² (5 hectares)
- Parks: only areas > 10,000 m² (1 hectare)  
- Parking: only `capacity > 200` or multi-storey
- Roads: only those with known AADT > 15,000 (from Trafikverket)

### OSM Coverage Warning

OSM coverage varies. Paper mills and wastewater plants are well-mapped in Sweden. Contaminated land is NOT in OSM — use Naturvårdsverket's register. Shooting ranges are spotty. Methadone clinics are rarely in OSM — use Google Places or kommun health directories.

### Overpass Query Examples

**Paper & pulp mills in Sweden:**
```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
(
  nwr["man_made"="works"]["product"~"paper|pulp"](area.sweden);
  nwr["industrial"="pulp_mill"](area.sweden);
  nwr["industrial"="paper_mill"](area.sweden);
);
out center;
```

**Wastewater treatment plants:**
```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
nwr["man_made"="wastewater_plant"](area.sweden);
out center;
```

**Landfills (active and historic):**
```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
nwr["landuse"="landfill"](area.sweden);
out center;
```

**Quarries:**
```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
nwr["landuse"="quarry"](area.sweden);
out center;
```

**Prisons:**
```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
nwr["amenity"="prison"](area.sweden);
out center;
```

**High-voltage power lines (≥130kV):**
```
[out:json][timeout:300];
area["ISO3166-1"="SE"]->.sweden;
way["power"="line"]["voltage"~"^(130000|220000|400000)"](area.sweden);
out geom;
```

**Wind turbines:**
```
[out:json][timeout:120];
area["ISO3166-1"="SE"]->.sweden;
(
  nwr["power"="generator"]["generator:source"="wind"](area.sweden);
  nwr["man_made"="windmill"](area.sweden);
);
out center;
```

### What About Positive Environmental Features?

The biggest omission in the current positive list: **silence and darkness**. Rural DeSOs far from highways, airports, and industry have intrinsic value that urban buyers increasingly seek (remote work). Consider adding:
- Distance-to-nearest-major-noise-source as a positive metric for rural DeSOs
- Light pollution levels (available from satellite data — VIIRS nighttime lights)

This is a future enhancement but worth noting.

---

## Summary: Full POI Category Count

| Category | Count | Type |
|---|---|---|
| Environmental/Industrial nuisances | 14 types | Negative |
| Social/Economic nuisances | 12 types | Negative |
| Infrastructure nuisances | 6 types | Negative |
| Quality of life indicators | 17 types | Positive |
| **Total** | **49 POI types** | |

This is roughly comparable to NeighborhoodScout's environmental layer (they track ~60 nuisance categories). The difference: we're using freely available data (OSM, government registers) instead of commercial datasets.