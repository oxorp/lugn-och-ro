# Kronofogden Debt Data

> Financial distress statistics from Kronofogden via the Kolada API.

## Overview

Kronofogden (Swedish Enforcement Authority) handles debt collection, payment orders, and evictions. Data is fetched from the **Kolada API** — a clean JSON REST API providing kommun-level statistics from multiple Swedish government agencies.

## API Details

| Property | Value |
|---|---|
| API Base URL | `https://api.kolada.se/v3/` |
| Auth | None required |
| Format | JSON |
| Municipality list | `/municipality` endpoint |
| Data endpoint | `/data/kpi/{kpiId}/year/{year}` |

## KPIs Used

| KPI ID | Name | Description | Unit |
|---|---|---|---|
| `N00989` | Debt rate | % adult population with debts at Kronofogden | percent |
| `N00990` | Median debt | Median debt per debtor | SEK |
| `U00958` | Eviction rate | Evictions per 100,000 inhabitants | per_100k |

## Service

`app/Services/KronofogdenService.php` handles API interaction.

## Response Format

```json
{
  "values": [
    {
      "municipality": "0114",
      "period": "2024",
      "values": [
        { "gender": "T", "value": 3.2 },
        { "gender": "M", "value": 3.8 },
        { "gender": "K", "value": 2.6 }
      ]
    }
  ]
}
```

Use `gender: "T"` for total (not M/K).

## Ingestion & Processing

```bash
# Ingest kommun-level data from Kolada
php artisan ingest:kronofogden --year=2024 --source=kolada

# Disaggregate to DeSO level
php artisan disaggregate:kronofogden --year=2024

# Create indicator values from disaggregation results
php artisan aggregate:kronofogden-indicators --year=2024
```

## Known Issues & Edge Cases

- **URL format**: Use `/data/kpi/{kpiId}/year/{year}` — NOT `/municipality/all/year/`
- **Municipality filtering**: The `/municipality` endpoint returns all entries including regions (type "L"). Filter on `type === 'K'` and exclude `id === '0000'` (Riket/national).
- **Region code confusion**: Region codes have 4-digit IDs too (e.g., "0001" = Region Stockholm). Don't rely on string length to distinguish.
- **Median debt limitation**: `median_debt_sek` cannot be disaggregated (median of sub-areas ≠ sub-area medians). All DeSOs in a kommun share the same value.
- **Disaggregation quality**: R² = 0.4030 — demographics explain ~40% of debt rate variance
- **No `foreign_background_pct`**: Explicitly excluded from the disaggregation formula per legal/ethical review
- **Clamping**: DeSO estimates are clamped to 10%–300% of kommun rate before constraining

## Related

- [Data Sources Overview](/data-sources/)
- [Financial Distress Indicators](/indicators/financial-distress)
- [Disaggregation Methodology](/methodology/disaggregation)
