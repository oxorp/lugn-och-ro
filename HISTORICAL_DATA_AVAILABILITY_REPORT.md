# Historical Data Availability Report

**Date:** 2026-02-09
**Scope:** All non-POI indicators, 2019–2025 (earlier years noted where available)
**Method:** API metadata probing + actual data retrieval tests

---

## SCB Indicators

### Critical Finding: DeSO 2018 → 2025 Transition

SCB tables that span multiple years contain **both** old DeSO 2018 codes (5,984 areas) and new DeSO 2025 codes (6,160 areas). The pattern is consistent across ALL tables:

- **Old DeSO codes** (e.g., `0114A0010`): have data from the table's start year through **2023** (null for 2024)
- **DeSO2025 codes** (e.g., `0114A0010_DeSO2025`): have data **only for 2024** (null for all earlier years)

**Implication:** Historical ingestion MUST use old DeSO codes for 2019–2023, then DeSO2025 for 2024+. A crosswalk table mapping old→new codes is required.

### Old Table vs New Table Pattern

For education and housing, SCB has **two versions** of each table:
- Old table (no "N" suffix): uses old DeSO codes, has 2015–2023
- New table ("N" suffix or "N2"): uses DeSO2025 codes, has 2024 only

The project currently uses the new tables (2024 only). For historical data, we need the old tables too.

### SCB Indicator Availability

| Indicator Slug | Year | Available? | DeSO Version | Source Table | Notes |
|---|---|---|---|---|---|
| **median_income** | 2019 | ✅ | 2018 | Tab3InkDesoRegso | Old DeSO code, `000008AB`, multiply×1000 |
| median_income | 2020 | ✅ | 2018 | Tab3InkDesoRegso | Verified: 330-400 SEK thousands |
| median_income | 2021 | ✅ | 2018 | Tab3InkDesoRegso | |
| median_income | 2022 | ✅ | 2018 | Tab3InkDesoRegso | |
| median_income | 2023 | ✅ | 2018 | Tab3InkDesoRegso | Last year with old DeSO codes |
| median_income | 2024 | ✅ | 2025 | Tab3InkDesoRegso | DeSO2025 codes, currently ingested |
| median_income | 2025 | ❌ | — | — | Expected Q1-Q2 2026 |
| **low_economic_standard_pct** | 2019 | ✅ | 2018 | Tab4InkDesoRegso | `000008AC` |
| low_economic_standard_pct | 2020 | ✅ | 2018 | Tab4InkDesoRegso | |
| low_economic_standard_pct | 2021 | ✅ | 2018 | Tab4InkDesoRegso | |
| low_economic_standard_pct | 2022 | ✅ | 2018 | Tab4InkDesoRegso | |
| low_economic_standard_pct | 2023 | ✅ | 2018 | Tab4InkDesoRegso | |
| low_economic_standard_pct | 2024 | ✅ | 2025 | Tab4InkDesoRegso | Currently ingested |
| low_economic_standard_pct | 2025 | ❌ | — | — | Expected Q1-Q2 2026 |
| **population** | 2019 | ✅ | 2018 | FolkmDesoAldKon | `000007Y7`, Alder=totalt, Kon=1+2 |
| population | 2020 | ✅ | 2018 | FolkmDesoAldKon | |
| population | 2021 | ✅ | 2018 | FolkmDesoAldKon | |
| population | 2022 | ✅ | 2018 | FolkmDesoAldKon | |
| population | 2023 | ✅ | 2018 | FolkmDesoAldKon | |
| population | 2024 | ✅ | 2025 | FolkmDesoAldKon | Currently ingested |
| population | 2025 | ❌ | — | — | Expected Q1-Q2 2026 |
| **foreign_background_pct** | 2019 | ✅ | 2018 | FolkmDesoBakgrKon | `000007Y4`, ratio foreign/total |
| foreign_background_pct | 2020 | ✅ | 2018 | FolkmDesoBakgrKon | |
| foreign_background_pct | 2021 | ✅ | 2018 | FolkmDesoBakgrKon | |
| foreign_background_pct | 2022 | ✅ | 2018 | FolkmDesoBakgrKon | |
| foreign_background_pct | 2023 | ✅ | 2018 | FolkmDesoBakgrKon | |
| foreign_background_pct | 2024 | ✅ | 2025 | FolkmDesoBakgrKon | Currently ingested |
| foreign_background_pct | 2025 | ❌ | — | — | Expected Q1-Q2 2026 |
| **education_post_secondary_pct** | 2019 | ✅ | 2018 | UtbSUNBefDesoRegso | OLD table (no "N"), `000007Z6` |
| education_post_secondary_pct | 2020 | ✅ | 2018 | UtbSUNBefDesoRegso | |
| education_post_secondary_pct | 2021 | ✅ | 2018 | UtbSUNBefDesoRegso | |
| education_post_secondary_pct | 2022 | ✅ | 2018 | UtbSUNBefDesoRegso | |
| education_post_secondary_pct | 2023 | ✅ | 2018 | UtbSUNBefDesoRegso | Last year old table |
| education_post_secondary_pct | 2024 | ✅ | 2025 | UtbSUNBefDesoRegsoN | NEW table, currently ingested |
| education_post_secondary_pct | 2025 | ❌ | — | — | Expected Q1-Q2 2026 |
| **education_below_secondary_pct** | 2019 | ✅ | 2018 | UtbSUNBefDesoRegso | Same table as above |
| education_below_secondary_pct | 2020 | ✅ | 2018 | UtbSUNBefDesoRegso | |
| education_below_secondary_pct | 2021 | ✅ | 2018 | UtbSUNBefDesoRegso | |
| education_below_secondary_pct | 2022 | ✅ | 2018 | UtbSUNBefDesoRegso | |
| education_below_secondary_pct | 2023 | ✅ | 2018 | UtbSUNBefDesoRegso | |
| education_below_secondary_pct | 2024 | ✅ | 2025 | UtbSUNBefDesoRegsoN | Currently ingested |
| education_below_secondary_pct | 2025 | ❌ | — | — | Expected Q1-Q2 2026 |
| **employment_rate** | 2019 | ✅ | 2018 | BefDeSoSyssN (AM0207) | `00000569`, FÖRV/total ratio |
| employment_rate | 2020 | ✅ | Both | BefDeSoSyssN + ArRegDesoStatusN (AM0210) | Both tables have 2020 |
| employment_rate | 2021 | ✅ | Both | BefDeSoSyssN + ArRegDesoStatusN (AM0210) | Both tables have 2021 |
| employment_rate | 2022 | ✅ | Both | ArRegDesoStatusN (AM0210) | **NEW DISCOVERY** — AM0210G table |
| employment_rate | 2023 | ✅ | Both | ArRegDesoStatusN (AM0210) | DeSO2025 codes available |
| employment_rate | 2024 | ✅ | 2025 | ArRegDesoStatusN (AM0210) | DeSO2025 codes, `0000089X`/`0000089Y` |
| employment_rate | 2025 | ❌ | — | — | Expected ~Q4 2026 (RAMS lag) |
| **rental_tenure_pct** | 2019 | ✅ | 2018 | BO0104T10N | OLD table, `00000864` |
| rental_tenure_pct | 2020 | ✅ | 2018 | BO0104T10N | |
| rental_tenure_pct | 2021 | ✅ | 2018 | BO0104T10N | |
| rental_tenure_pct | 2022 | ✅ | 2018 | BO0104T10N | |
| rental_tenure_pct | 2023 | ✅ | 2018 | BO0104T10N | Last year old table |
| rental_tenure_pct | 2024 | ✅ | 2025 | BO0104T01N2 | NEW table, currently ingested |
| rental_tenure_pct | 2025 | ❌ | — | — | Expected Q1-Q2 2026 |

### SCB Old vs New Table Mapping

| Indicator(s) | Old Table (DeSO 2018) | Years | New Table (DeSO 2025) | Years |
|---|---|---|---|---|
| median_income, low_economic_standard_pct | Tab3/4InkDesoRegso (same table, both code systems) | 2011–2023 | Same table, DeSO2025 codes | 2024 |
| population, foreign_background_pct | FolkmDesoAldKon / FolkmDesoBakgrKon (both code systems) | 2010–2023 | Same table, DeSO2025 codes | 2024 |
| education_*_pct | UtbSUNBefDesoRegso | 2015–2023 | UtbSUNBefDesoRegsoN | 2024 |
| employment_rate | BefDeSoSyssN (AM0207, 2019–2021) | 2019–2021 | ArRegDesoStatusN (AM0210G, both codes) | 2020–2024 |
| rental_tenure_pct | BO0104T10N | 2015–2023 | BO0104T01N2 | 2024 |

### Education Table Gotcha

The old education table (`UtbSUNBefDesoRegso`) covers ages **25-64**, while the new table (`UtbSUNBefDesoRegsoN`) covers ages **25-65**. This 1-year age range difference may cause a small discontinuity in the time series.

### Employment Data: AM0210G Discovery

The original employment table (`AM0207/BefDeSoSyssN`) stopped at 2021. However, a **newer table was discovered**:

**`AM0210/AM0210G/ArRegDesoStatusN`** — "Labour market status by region of residence (DeSO/RegSO), sex and age. Annual register."

- **Years:** 2020–2024
- **Region codes:** 18,870 total (6,160 DeSO2025 + 5,984 old DeSO + RegSO codes)
- **ContentsCodes:** `0000089X` (employed), `0000089W` (unemployed), `0000089V` (labour force), `0000089Z` (outside labour force), `0000089Y` (total)
- **Age groups:** 15-74, 16-64, 16-65, 16-66, 20-64, 20-65, 20-66
- **Verified:** Sample query for `0114A0010_DeSO2025` returns `null` for 2020 (DeSO2025 not active then), `415` employed / `497` total for 2024
- **Old DeSO companion table:** `ArRegDesoStatus` has same data for old codes (2020–2023 only)

**Employment rate formula:** `employed (0000089X) / total (0000089Y)` for ages 20-64, both sexes.

This resolves the previous gap for 2022–2024. For 2019, only the old AM0207 table applies. Combined coverage: **2019–2024 (6 years)**.

**Note:** This is a different SCB survey (AM0210 = "Population by Labour market status" based on annual register) vs AM0207 (= "Labour statistics based on administrative sources"). The methodology is slightly different (register-based annual vs. admin-based). The resulting employment rates may differ by 1-2 percentage points. For trend computation, use one table consistently or handle the series break explicitly.

---

## Skolverket Indicators

### Key Discovery: API Returns 5 Academic Years

The Planned Educations API v3 now returns **5 academic years** per school:
- 2020/21, 2021/22, 2022/23, 2023/24, 2024/25

**This is a major finding.** The project memory stated data was "concentrated in 2020/21" because the parser only extracted the latest EXISTS value. The API actually has good data for all 5 years.

### Coverage by Field and Year

| Field | 2020/21 | 2021/22 | 2022/23 | 2023/24 | 2024/25 |
|---|---|---|---|---|---|
| certifiedTeachersQuota | ~88% | ~90% | ~90% | ~92% | ~92% |
| averageGradesMeritRating9thGrade | ~29% | ~30% | ~30% | ~32% | ~33% |
| ratioOfPupilsIn9thGradeWithAllSubjectsPassed | ~27% | ~28% | ~29% | ~30% | ~31% |
| ratioOfPupils9thGradeEligibleForNationalProgramYR | ~19% | ~22% | ~20% | ~24% | ~25% |

Coverage = % of schools returning valueType=EXISTS (based on 97-school sample).

### Skolverket Indicator Availability

| Indicator Slug | Academic Year | Calendar Year | Available? | Source | Notes |
|---|---|---|---|---|---|
| school_teacher_certification_avg | 2020/21 | 2021 | ✅ | Planned Educations v3 API | ~88% school coverage |
| school_teacher_certification_avg | 2021/22 | 2022 | ✅ | Planned Educations v3 API | ~90% |
| school_teacher_certification_avg | 2022/23 | 2023 | ✅ | Planned Educations v3 API | ~90% |
| school_teacher_certification_avg | 2023/24 | 2024 | ✅ | Planned Educations v3 API | ~92% |
| school_teacher_certification_avg | 2024/25 | 2025 | ✅ | Planned Educations v3 API | ~92%, **2025 data already live** |
| school_merit_value_avg | 2020/21 | 2021 | ✅ | Planned Educations v3 API | ~29%, 9th-grade schools only |
| school_merit_value_avg | 2021/22 | 2022 | ✅ | Planned Educations v3 API | ~30% |
| school_merit_value_avg | 2022/23 | 2023 | ✅ | Planned Educations v3 API | ~30% |
| school_merit_value_avg | 2023/24 | 2024 | ✅ | Planned Educations v3 API | ~32% |
| school_merit_value_avg | 2024/25 | 2025 | ✅ | Planned Educations v3 API | ~33% |
| school_goal_achievement_avg | 2020/21 | 2021 | ✅ | Planned Educations v3 API | ~27% |
| school_goal_achievement_avg | 2021/22 | 2022 | ✅ | Planned Educations v3 API | ~28% |
| school_goal_achievement_avg | 2022/23 | 2023 | ✅ | Planned Educations v3 API | ~29% |
| school_goal_achievement_avg | 2023/24 | 2024 | ✅ | Planned Educations v3 API | ~30% |
| school_goal_achievement_avg | 2024/25 | 2025 | ✅ | Planned Educations v3 API | ~31% |

**No data before 2020/21.** The API's maximum history is 5 years.

### Skolverket Gotchas

1. **Parser bug:** Current `parseGrundskolaStatsResponse()` only extracts the latest EXISTS value per field. Needs modification to return all years.
2. **DeSO version irrelevant:** School stats are per-school, aggregated to DeSO via spatial join. Works with DeSO2025 regardless of year.
3. **totalNumberOfPupils** only available for current year (2024/25), not historical. Historical student count weighting would need another source.
4. **School openings/closures** affect year-to-year comparability.

---

## BRÅ Crime Indicators

### Reported Offences (crime_violent_rate, crime_property_rate, crime_total_rate)

| Dataset | Year | Available? | Granularity | Source | Notes |
|---|---|---|---|---|---|
| Kommun crime totals | 2019 | ✅ | Kommun (290) | statistik.bra.se Table 120 | Interactive database, not CSV |
| Kommun crime totals | 2020 | ✅ | Kommun (290) | statistik.bra.se Table 120 | COVID year — anomalous patterns |
| Kommun crime totals | 2021 | ✅ | Kommun (290) | statistik.bra.se Table 120 | |
| Kommun crime totals | 2022 | ✅ | Kommun (290) | statistik.bra.se Table 120 | |
| Kommun crime totals | 2023 | ✅ | Kommun (290) | statistik.bra.se Table 120 | |
| Kommun crime totals | 2024 | ✅ | Kommun (290) | statistik.bra.se Table 120 | |
| Kommun crime totals | 2025 | ✅ | Kommun (290) | BRÅ CSV download | Preliminary, currently ingested |
| National crime categories | 2019 | ✅ | National only | Excel (anmalda_brott_10_ar.xlsx) | For category proportion estimation |
| National crime categories | 2020 | ✅ | National only | Excel | |
| National crime categories | 2021 | ✅ | National only | Excel | |
| National crime categories | 2022 | ✅ | National only | Excel | |
| National crime categories | 2023 | ✅ | National only | Excel | |
| National crime categories | 2024 | ✅ | National only | Excel | |

**Key findings:**
- The BRÅ kommun CSV download only serves the **current year** (2025 prelim). No historical CSV downloads.
- Historical kommun data must be obtained from **statistik.bra.se** interactive database (Table 120), which goes back to 1996.
- The national category breakdown Excel has 10 years of data (2015–2024).
- The regional 10-year file (10Rn) has 7 police regions only, not kommun-level.

### NTU Survey (perceived_safety)

| Dataset | Year | Available? | Granularity | Source | Notes |
|---|---|---|---|---|---|
| NTU perceived safety | 2019 | ✅ | Län (21) | ntu_lan_2017_2025.xlsx, Sheet R4.1 | |
| NTU perceived safety | 2020 | ✅ | Län (21) | ntu_lan_2017_2025.xlsx | |
| NTU perceived safety | 2021 | ✅ | Län (21) | ntu_lan_2017_2025.xlsx | |
| NTU perceived safety | 2022 | ✅ | Län (21) | ntu_lan_2017_2025.xlsx | |
| NTU perceived safety | 2023 | ✅ | Län (21) | ntu_lan_2017_2025.xlsx | |
| NTU perceived safety | 2024 | ✅ | Län (21) | ntu_lan_2017_2025.xlsx | |
| NTU perceived safety | 2025 | ✅ | Län (21) | ntu_lan_2017_2025.xlsx | Currently ingested |

The existing Excel file already covers 2017–2025 (9 years). All years at län level (21 counties). Disaggregated to DeSO via inverse demographic weighting.

### Vulnerability Areas (vulnerability_flag)

| Year | Available? | Areas | Source | Notes |
|---|---|---|---|---|
| 2015 | ✅ | ~53 | Polisen PDF | First classification, no GeoJSON |
| 2017 | ✅ | ~61 | Polisen PDF/report | |
| 2019 | ✅ | ~60 | Polisen report | |
| 2021 | ✅ | ~61 | Polisen report | |
| 2023 | ✅ | ~59 | Polisen report | May have GeoJSON |
| 2025 | ✅ | 65 | Polisen GeoJSON | Currently ingested (46 utsatt + 19 sarskilt_utsatt) |

Published biennially. **Historical GeoJSON confirmed unavailable** — URLs for 2023, 2021, 2019 all return HTTP 404. Only 2025 has geospatial data files. Prior years are PDF-only, which would require manual digitization. Area counts and boundaries change between assessments.

---

## Kronofogden / Kolada Indicators

### Kolada API Availability (all at kommun level, 290 kommuner)

| KPI | Indicator Slug | Year | Available? | Kommuner with data | Value Range (gender=T) |
|---|---|---|---|---|---|
| **N00989** (debt rate %) | debt_rate_pct | 2019 | ✅ | 290 | ~0.9–10.3% |
| N00989 | debt_rate_pct | 2020 | ✅ | 290 | 0.88–10.31% |
| N00989 | debt_rate_pct | 2021 | ✅ | 290 | 0.85–9.52% |
| N00989 | debt_rate_pct | 2022 | ✅ | 290 | 0.98–9.09% |
| N00989 | debt_rate_pct | 2023 | ✅ | 290 | 0.91–9.46% |
| N00989 | debt_rate_pct | 2024 | ✅ | 290 | 0.93–9.40% |
| N00989 | debt_rate_pct | 2025 | ✅ | 290 | 0.87–9.33% |
| **N00990** (median debt SEK) | median_debt_sek | 2019 | ✅ | ~290 | ~30K–90K SEK |
| N00990 | median_debt_sek | 2020 | ✅ | ~290 | 34K–118K SEK |
| N00990 | median_debt_sek | 2021 | ✅ | ~290 | Similar range |
| N00990 | median_debt_sek | 2022 | ✅ | ~290 | Similar range |
| N00990 | median_debt_sek | 2023 | ✅ | ~290 | Similar range |
| N00990 | median_debt_sek | 2024 | ✅ | ~290 | Similar range |
| N00990 | median_debt_sek | 2025 | ❌ | — | Not yet published |
| **U00958** (eviction rate/100k) | eviction_rate | 2019 | ✅ | 289 | 0–107 |
| U00958 | eviction_rate | 2020 | ✅ | 290 | 0–107 |
| U00958 | eviction_rate | 2021 | ✅ | 290 | Similar range |
| U00958 | eviction_rate | 2022 | ✅ | 290 | Similar range |
| U00958 | eviction_rate | 2023 | ✅ | 290 | Similar range |
| U00958 | eviction_rate | 2024 | ✅ | 290 | Similar range |
| U00958 | eviction_rate | 2025 | ❌ | — | Not yet published |

**Notes:**
- N00989 (debt rate) has data from **2015** onwards, with 2025 already available
- N00990 (median debt) starts from **2017** (no data for 2015–2016)
- U00958 (eviction rate) has data from at least **2014** onwards
- Response includes grouped entries (G-prefix codes) and "0000" (Riket) — filter on 4-digit numeric codes excluding "0000"
- All are kommun-level; disaggregated to DeSO using the existing weighted propensity model

---

## Other Sources Found in Codebase

| Source | Dataset | Years | Granularity | Notes |
|---|---|---|---|---|
| OSM/Overpass | POI data | Current only | Point locations | **Out of scope** — point-in-time snapshots |
| Proximity scoring | Per-coordinate factors | Derived | Point locations | Computed from POI/school data, not ingested |

No other historical data sources were found in the codebase.

---

## Summary

| Indicator / Dataset | Earliest Year | Latest Year | Total Years | Gaps | Granularity | DeSO Version Change |
|---|---|---|---|---|---|---|
| median_income | 2019* | 2024 | 6 | None | DeSO | 2024 (2018→2025) |
| low_economic_standard_pct | 2019* | 2024 | 6 | None | DeSO | 2024 (2018→2025) |
| population | 2019* | 2024 | 6 | None | DeSO | 2024 (2018→2025) |
| foreign_background_pct | 2019* | 2024 | 6 | None | DeSO | 2024 (2018→2025) |
| education_post_secondary_pct | 2019* | 2024 | 6 | None | DeSO | 2024 (old table→new table) |
| education_below_secondary_pct | 2019* | 2024 | 6 | None | DeSO | 2024 (old table→new table) |
| employment_rate | 2019 | 2024 | 6 | None | DeSO | AM0207 (2019–2021, old) + AM0210 (2020–2024, both) |
| rental_tenure_pct | 2019* | 2024 | 6 | None | DeSO | 2024 (old table→new table) |
| school_teacher_certification_avg | 2021 | 2025 | 5 | None | Per school→DeSO | N/A |
| school_merit_value_avg | 2021 | 2025 | 5 | None | Per school→DeSO | N/A (low coverage ~30%) |
| school_goal_achievement_avg | 2021 | 2025 | 5 | None | Per school→DeSO | N/A (low coverage ~30%) |
| crime_violent_rate | 2019 | 2025 | 7 | None | Kommun→DeSO | N/A |
| crime_property_rate | 2019 | 2025 | 7 | None | Kommun→DeSO | N/A |
| crime_total_rate | 2019 | 2025 | 7 | None | Kommun→DeSO | N/A |
| perceived_safety | 2019 | 2025 | 7 | None | Län→DeSO | N/A |
| vulnerability_flag | 2019 | 2025 | 4 | Biennial only | Named areas→DeSO | Boundaries change |
| debt_rate_pct | 2019 | 2025 | 7 | None | Kommun→DeSO | N/A |
| eviction_rate | 2019 | 2024 | 6 | None | Kommun→DeSO | N/A |
| median_debt_sek | 2019 | 2024 | 6 | None | Kommun (flat) | N/A |

*\* Data actually goes back further (2010–2015 depending on indicator), but 2019 is our target start year.*

---

## DeSO 2018 → 2025 Crosswalk

### The Problem

- DeSO 2018: **5,984** areas
- DeSO 2025: **6,160** areas (176 more — some areas were split)
- Old codes (e.g., `0114A0010`) ≠ New codes (e.g., `0114A0010_DeSO2025`) even when the area didn't change

### What We Need

1. **Crosswalk table:** mapping each old DeSO code to one or more new DeSO2025 codes (and vice versa)
2. **Area overlap fractions:** for split areas, what proportion of the old area maps to each new area
3. **Value redistribution logic:** for split areas, how to allocate a historical value (e.g., "old DeSO had income 350k") across the new sub-areas

### Potential Sources

- SCB may publish an official crosswalk — check `https://www.scb.se/hitta-statistik/regional-statistik-och-kartor/regionala-indelningar/deso---demografiska-statistikomraden/`
- Build one using spatial overlap: load both the 2018 and 2025 DeSO geometries from SCB WFS, compute `ST_Intersection` area proportions
- For ~90%+ of areas, the mapping will be 1:1 (same area, just code format change). Only the ~176 split areas need special handling.

### Recommended Approach

1. Load DeSO 2018 geometries from SCB WFS (layer `stat:DeSO` or `stat:DeSO_2018`)
2. Load DeSO 2025 geometries (already in `deso_areas` table)
3. Compute spatial overlap matrix: `ST_Area(ST_Intersection(old.geom, new.geom)) / ST_Area(old.geom)`
4. Store as `deso_crosswalk` table: `old_code`, `new_code`, `overlap_fraction`
5. For historical values: `new_value = SUM(old_value * overlap_fraction)` for rate/percentage indicators, or population-weighted for count indicators

---

## Recommendations for Historical Ingestion

### Priority 1: High Value, Low Effort
1. **SCB income/population/education/housing** (2019–2023 via old DeSO codes) — 6 indicators × 5 years = 30 indicator-years. Same API, same ContentsCode, just use old table names + old region codes.
2. **SCB employment via AM0210** (2020–2024 via ArRegDesoStatusN) — 1 indicator × 5 years = 5 indicator-years. New table, new ContentsCodes, but straightforward computation. Add 2019 from AM0207.
3. **Skolverket** (2020/21–2024/25) — Fix parser to save all years, re-ingest. 3 indicators × 5 years = 15 indicator-years.
4. **Kolada** (2019–2024) — Same API, just change year parameter. 3 indicators × 6 years = 18 indicator-years.
5. **NTU survey** (2019–2024) — Already in the Excel file, just ingest additional years. 1 indicator × 6 years.

### Priority 2: Moderate Effort
6. **BRÅ kommun crime** (2019–2024) — Need to scrape from statistik.bra.se interactive database instead of CSV download. 3 derived indicators × 6 years.
7. **DeSO crosswalk table** — Required for SCB historical data (2019–2023) to work with DeSO2025 boundaries.

### Priority 3: Low Priority / Hard
8. **Vulnerability areas** — Historical classifications available in PDFs (2015–2023) but NO GeoJSON. Would require manual digitization or area-name matching. Biennial only.

### Total Potential: ~100+ indicator-year combinations available for ingestion
