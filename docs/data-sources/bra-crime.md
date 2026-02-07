# BRÅ Crime Data

> Crime statistics from BRÅ (Swedish National Council for Crime Prevention) — CSV/Excel files, no API.

## Overview

BRÅ is the primary source for crime statistics. Unlike other sources, BRÅ has **explicitly no public API** — all data is via downloadable Excel and CSV files. The platform also uses NTU (National Crime Survey) data for perceived safety and Police vulnerability area classifications.

## Data Files

| File | Location | Content |
|---|---|---|
| Kommun crime CSV | `storage/app/data/raw/bra/anmalda_brott_kommuner_2025.csv` | 290 kommuner, total crimes + rate per 100k |
| National Excel | `storage/app/data/raw/bra/anmalda_brott_10_ar.xlsx` | Crime categories by year, national level |
| NTU Survey | `storage/app/data/raw/bra/ntu_lan_2017_2025.xlsx` | 21 län, 2017–2025, perceived safety |

## Service

`app/Services/BraDataService.php` handles all parsing and estimation.

## Crime Categories

Category-level kommun rates are **estimated** using national proportions applied to kommun totals:

| Category | Slug | Description |
|---|---|---|
| Person crimes | `crime_person` | Assault, threats, harassment |
| Robbery | `crime_robbery` | Robbery |
| Sexual crimes | `crime_sexual` | Sexual offences |
| Theft | `crime_theft` | Theft, burglary |
| Criminal damage | `crime_damage` | Vandalism |
| Total | `crime_total` | All reported crimes |

### Estimation Method

1. Parse national Excel for category breakdown (% of total per category)
2. Parse kommun CSV for total crime count per kommun
3. Apply national proportions to kommun totals → estimated kommun-level category rates

## NTU Survey Data

The National Crime Survey (Nationella trygghetsundersökningen) provides perceived safety data:

| Property | Value |
|---|---|
| Key sheet | R4.1 "Otrygghet vid utevistelse sent på kvällen" |
| Measure | % feeling unsafe at night |
| Granularity | 21 län (counties) |
| Years | 2017–2025 |
| Respondents | ~200,000 aged 16–84 |

NTU values are disaggregated to DeSO using **inverted demographic weighting** — safer demographics get higher safety scores.

## Police Vulnerability Areas

| Property | Value |
|---|---|
| Source | `https://polisen.se/contentassets/.../uso_2025_geojson.zip` |
| Format | GeoJSON (44KB) |
| CRS | EPSG:3006 (SWEREF99TM) — must transform to WGS84 |
| Count | 65 areas (46 utsatt + 19 särskilt utsatt) |
| DeSO overlap | ~275 DeSOs with ≥25% overlap |

Properties in GeoJSON:
- `NAMN` — Area name
- `KATEGORI` — "Utsatt område" or "Särskilt utsatt område"
- `REGION`, `LOKALPOLISOMRADE`, `ORT`

## Ingestion Commands

```bash
# Kommun-level crime data
php artisan ingest:bra-crime --year=2024

# NTU survey data
php artisan ingest:ntu --year=2025

# Vulnerability area polygons
php artisan ingest:vulnerability-areas --year=2025

# Disaggregate to DeSO level
php artisan disaggregate:crime --year=2024
```

## Swedish Data Formatting

BRÅ files use Swedish conventions that require careful parsing:

| Convention | Meaning | Handling |
|---|---|---|
| `..` | Suppressed (too few cases) | Treat as NULL |
| `-` | Zero | Treat as 0 |
| Comma decimal | e.g., `1 234,5` | Convert to `1234.5` |
| BOM | Byte order mark in CSV | Strip on read |

## Known Issues & Edge Cases

- **No API**: All data must be manually downloaded from bra.se and placed in `storage/app/data/raw/bra/`
- **Counting method**: Sweden counts every individual offence separately (inflates raw numbers vs other countries)
- **Category estimation**: Kommun-level category rates are estimated from national proportions — assumes uniform category distribution across kommuner
- **NTU coarseness**: 21 län disaggregated to 6,160 DeSOs — significant precision loss
- **Vulnerability area geometry**: Source uses EPSG:3006, must transform to WGS84. Geometry is Polygon type — wrap with ST_Multi for MULTIPOLYGON storage.

## Related

- [Data Sources Overview](/data-sources/)
- [Crime Indicators](/indicators/crime)
- [Disaggregation Methodology](/methodology/disaggregation)
