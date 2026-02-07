---
layout: home

hero:
  name: PlatsIndex
  text: Swedish Real Estate Prediction Platform
  tagline: Scoring Sweden's 6,160 neighborhoods using public government data
  actions:
    - theme: brand
      text: Architecture
      link: /architecture/
    - theme: alt
      text: Data Pipeline
      link: /data-pipeline/
    - theme: alt
      text: Indicators
      link: /indicators/

features:
  - title: 6,160 DeSO Areas
    details: Complete coverage of Sweden's Demografiska statistikområden with polygon boundaries from SCB.
  - title: 20+ Indicators
    details: Demographics, crime, schools, debt, POIs — each ingested, normalized, and scored.
  - title: Composite Scoring
    details: Weighted 0–100 scores with direction handling, percentile normalization, and factor attribution.
  - title: Multi-Resolution H3
    details: H3 hexagonal grid at resolutions 5–8, with spatial smoothing and viewport-based loading.
  - title: Data Tiering
    details: Six access tiers from public to admin, controlling data granularity per user type.
  - title: Real-Time Pipeline
    details: Artisan commands for ingestion, normalization, scoring, and publishing with version control.
---
