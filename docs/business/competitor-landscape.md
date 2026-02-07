# Competitor Landscape

> How PlatsIndex compares to existing area intelligence tools in Sweden.

## Direct Competitors

### Hemnet Områdesdata

- **Coverage**: All of Sweden
- **Granularity**: Kommun level (290 areas)
- **Data**: Property prices, transaction volumes
- **Limitation**: No predictive scoring, no DeSO-level granularity, property-focused not neighborhood-focused

### Booli Område

- **Coverage**: Major cities
- **Granularity**: Custom neighborhoods
- **Data**: Price trends, demographics
- **Limitation**: Proprietary neighborhood definitions, limited rural coverage

### Mäklarstatistik

- **Coverage**: All of Sweden
- **Granularity**: Various
- **Data**: Transaction-based price statistics
- **Limitation**: Backward-looking (historical prices), not predictive

## PlatsIndex Differentiation

| Dimension | Competitors | PlatsIndex |
|---|---|---|
| Geographic unit | Kommun or custom | DeSO (SCB standard, 500-3,000 residents) |
| Resolution | 290 kommuner | 6,160 DeSOs |
| Data sources | 1-2 (prices, demographics) | 7 (SCB, Skolverket, BRA, NTU, Polisen, Kronofogden, OSM) |
| Indicators | 3-5 | 27 weighted indicators |
| Direction | Backward-looking | Forward-looking (trend analysis) |
| Methodology | Opaque | Transparent (public documentation) |
| Scoring | None or simple ranking | Weighted composite with normalization |
| Urbanity-adjusted | No | Yes (stratified normalization) |

## Indirect Competitors

- **SCB itself** — Raw data is free, but requires data science expertise to combine
- **Google Maps** — Street-level information, no scoring or aggregation
- **Tryggare Sverige** — Safety surveys, narrow focus on crime/safety

## Moat

1. **Disaggregation models** — Proprietary algorithms to estimate DeSO-level values from kommun/lan data
2. **Indicator curation** — 27 indicators with researched weights and directions
3. **H3 spatial smoothing** — Hexagonal visualization that smooths boundary artifacts
4. **Multi-source integration** — Combining 7 government data sources into one coherent score

## Related

- [Target Customers](/business/target-customers)
- [Master Indicator Reference](/indicators/)
