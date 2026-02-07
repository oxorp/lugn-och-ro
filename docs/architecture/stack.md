# Tech Stack

> Technology choices and versions used in the PlatsIndex platform.

## Overview

PlatsIndex uses a conventional Laravel stack with spatial extensions. The entire application runs inside Docker containers during development.

## Backend

| Technology | Version | Purpose |
|---|---|---|
| PHP | 8.4 | Runtime |
| Laravel | 12 | Application framework |
| PostgreSQL | 16 | Primary database |
| PostGIS | 3.4 | Spatial extensions (geometry, geography) |
| h3-pg | — | H3 hexagonal indexing in SQL |
| Redis | — | Cache, queues, sessions |
| Laravel Horizon | 5 | Queue monitoring dashboard |
| Laravel Telescope | 5 | Debug dashboard (dev only) |
| Laravel Fortify | 1 | Authentication (2FA support) |
| Laravel Wayfinder | 0 | TypeScript route generation |
| PHPUnit | 11 | Testing framework |
| Laravel Pint | 1 | Code formatter |

## Frontend

| Technology | Version | Purpose |
|---|---|---|
| React | 19 | UI framework |
| Inertia.js | 2 | Server-driven SPA |
| TypeScript | — | Type safety |
| Tailwind CSS | 4 | Utility-first styling |
| shadcn/ui | — | Component library |
| OpenLayers | — | Map rendering (NOT Mapbox/Leaflet) |
| Vite | — | Build tool |

## Infrastructure

| Technology | Purpose |
|---|---|
| Docker Compose | Local development environment |
| Laravel Sail | Docker wrapper for Laravel |
| npm | Frontend package management |
| Composer | PHP package management |

## Why These Choices

### Why OpenLayers (not Mapbox or Leaflet)
- Open source with no API key requirement
- Native support for vector tiles and complex styling
- Better performance with large polygon datasets (6,160 DeSO areas)
- No usage-based pricing

### Why Inertia.js (not SPA + API)
- Server-side routing with SPA user experience
- Shared authentication and sessions — no token management
- Direct Eloquent → React props via Inertia responses
- Laravel Wayfinder generates typed route functions

### Why PostgreSQL + PostGIS (not MySQL)
- PostGIS provides spatial queries (ST_Contains, ST_DWithin, ST_Transform)
- H3 PostgreSQL extension for hexagonal indexing in SQL
- PERCENT_RANK() window function for normalization
- Native JSON/JSONB support for flexible metadata

## Related

- [Architecture Overview](/architecture/)
- [Database Schema](/architecture/database-schema)
- [Docker Setup](/operations/docker-setup)
