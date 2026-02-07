# DeSO Indicators

> `GET /api/deso/{code}/indicators` — All indicator values for a specific DeSO, with tier-based detail.

## Endpoint

```
GET /api/deso/{desoCode}/indicators?year=2024
```

**Controller**: `DesoController@indicators`
**Rate limited**: Yes (`throttle:deso-detail`)

## Response by Tier

| Tier | Data Returned |
|---|---|
| Public (0) | Indicator names and categories only (for blurred preview) |
| Free (1) | Raw values, normalized values, units, directions, trends |
| Unlocked (2) | Same as Free + descriptions, source info |
| Subscriber (3) | Same as Unlocked + historical values |
| Admin (99) | Everything + weight, contribution, rank, coverage, API paths |

## Admin-Only Fields

| Field | Description |
|---|---|
| `weight` | Indicator weight in scoring |
| `weighted_contribution` | This indicator's contribution to the score |
| `rank` / `rank_total` | DeSO's rank for this indicator |
| `normalization_method` | Method used (rank_percentile, etc.) |
| `coverage_count` / `coverage_total` | Data coverage statistics |
| `source_api_path` | Technical API path for data source |
| `data_quality_notes` | Known quality issues |

## Trend Data

Trends are included for Free+ tiers when available:

```json
{
  "trend": {
    "direction": "improving",
    "percent_change": 3.2,
    "absolute_change": 8500,
    "base_year": 2019,
    "end_year": 2024,
    "data_points": 6,
    "confidence": 0.85
  }
}
```

Trend eligibility depends on `deso_areas.trend_eligible` (false for areas with boundary changes).

## Unlock Options

For Free tier users, the response includes unlock pricing:

```json
{
  "unlock_options": {
    "deso": { "code": "0114A0010", "price": 7900 },
    "kommun": { "code": "0114", "name": "Upplands Väsby", "price": 19900 }
  }
}
```

Prices are in öre (7900 = 79 SEK).

## Related

- [API Overview](/api/)
- [Master Indicator Reference](/indicators/)
- [Tiering Model](/business/tiering-model)
