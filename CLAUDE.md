# CLAUDE.md

## What is this project?

A Swedish real estate prediction platform that scores neighborhoods using public government data (crime, demographics, schools, financial distress). The frontend is an interactive map showing Sweden's ~6,160 DeSO (Demografiska statistikområden) statistical areas with color-coded scores.

## Your current task

Read `task.md` — it contains the complete step-by-step instructions for the current milestone. Follow it in order. Do not skip ahead.

Read `data_pipeline_specification.md` for full business context, data sources, and architecture decisions. You don't need to implement everything in that doc right now — just understand the bigger picture so your decisions align with where this is going.

## Stack

- Laravel 11, PHP 8.3
- Inertia.js + React 18 + TypeScript
- Tailwind CSS 4 + shadcn/ui
- OpenLayers (for the map — not Mapbox, not Leaflet, not Deck.gl)
- PostgreSQL 16 + PostGIS 3.4
- Docker

## Rules

- Always run the app inside Docker. Don't install PHP, Postgres, or Node on the host.
- Use TypeScript for all frontend code. No `.jsx` files, only `.tsx`.
- Use shadcn/ui components where applicable. Don't build custom UI primitives.
- Write Laravel code the Laravel way — Eloquent models, Artisan commands, service classes. No raw frameworks-within-frameworks.
- Commit working states. If something works, commit before moving to the next step.
- When a step says "verify", actually verify. Run the query, check the browser, confirm the count.