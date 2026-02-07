# DeSO Schools

> `GET /api/deso/{code}/schools` — Schools within a DeSO area, tiered by user access level.

## Endpoint

```
GET /api/deso/{desoCode}/schools
```

**Controller**: `DesoController@schools`
**Rate limited**: Yes (`throttle:deso-detail`)

## Response by Tier

| Tier | Data Returned |
|---|---|
| Public (0) | School count only |
| Free (1) | School count only |
| Unlocked (2) | Names, types, locations + quality band (very_high/high/average/low/very_low) |
| Subscriber (3) | Exact merit value, goal achievement %, teacher certification %, student count |
| Admin (99) | All above + school_unit_code |

### Example (Subscriber tier)

```json
{
  "school_count": 3,
  "tier": 3,
  "schools": [
    {
      "name": "Väsby skola",
      "type": "Grundskola",
      "school_forms": ["GR"],
      "operator_type": "Kommun",
      "lat": 59.5123,
      "lng": 17.9456,
      "merit_value": 234.5,
      "goal_achievement": 78.2,
      "teacher_certification": 92.1,
      "student_count": 450
    }
  ]
}
```

## Related

- [API Overview](/api/)
- [School Quality Indicators](/indicators/school-quality)
