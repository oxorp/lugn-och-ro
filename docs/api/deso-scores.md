# DeSO Scores

> `GET /api/deso/scores` — Composite scores for all DeSO areas.

## Endpoint

```
GET /api/deso/scores?year=2024
```

**Controller**: `DesoController@scores`

## Parameters

| Param | Type | Default | Description |
|---|---|---|---|
| `year` | integer | Current year | Scoring year |

## Response

Returns scores keyed by DeSO code.

**Cache**: 1 hour (`max-age=3600`)

```json
{
  "0114A0010": {
    "deso_code": "0114A0010",
    "score": 72.4,
    "trend_1y": 2.1,
    "factor_scores": "{\"median_income\": 14.2, ...}",
    "top_positive": "[\"median_income\", \"school_merit_value_avg\"]",
    "top_negative": "[\"crime_total_rate\", \"debt_rate_pct\"]",
    "urbanity_tier": "urban"
  }
}
```

## Version Resolution

1. If tenant exists, look for tenant-specific published version
2. Fallback to default (null tenant) published version
3. Final fallback: serve latest unversioned scores

## Related

- [API Overview](/api/)
- [Scoring Engine](/architecture/scoring-engine)
