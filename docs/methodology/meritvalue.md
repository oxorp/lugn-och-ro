# Merit Value (Meritvärde)

> The Swedish school grading system and how it's used in PlatsIndex.

## Overview

**Meritvärde** is the composite score used for gymnasieskola (upper secondary school) admissions in Sweden. It's the primary measure of school quality used in the `school_merit_value_avg` indicator.

## How It Works

1. Students receive final grades in each subject: A–F (A=20, B=17.5, C=15, D=12.5, E=10, F=0)
2. The **best 16 or 17 subjects** are selected (17 if studying a modern language)
3. Points are summed to produce the meritvärde

| Subjects | Max Points Per Subject | Maximum Meritvärde |
|---|---|---|
| 16 | 20 | 320 |
| 17 | 20 | 340 |

## Interpretation

| Merit Value Range | Interpretation |
|---|---|
| 250+ | Very high — competitive for top gymnasieskola programs |
| 220–249 | High — above national average |
| 190–219 | Average |
| 160–189 | Below average |
| < 160 | Very low — limited gymnasieskola options |

## National Average

The national average meritvärde for grundskola year 9 is approximately 220–230 points. This varies by year and cohort.

## Quality Bands

The `DesoController` maps merit values to quality bands for tiered display:

```php
return match (true) {
    $meritValue >= 250 => 'very_high',
    $meritValue >= 220 => 'high',
    $meritValue >= 190 => 'average',
    $meritValue >= 160 => 'low',
    default => 'very_low',
};
```

## Related

- [School Quality Indicators](/indicators/school-quality)
- [Skolverket Data Source](/data-sources/skolverket-schools)
