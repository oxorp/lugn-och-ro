# Operations

> Running, deploying, and maintaining the PlatsIndex platform.

## Quick Reference

| Task | Command |
|---|---|
| Start environment | `docker compose up -d` |
| Run full pipeline | `docker compose exec app php artisan pipeline:run --year=2024` |
| Recompute scores | `docker compose exec app php artisan compute:scores --year=2024` |
| Check data freshness | `docker compose exec app php artisan check:freshness` |
| Build frontend | `docker compose exec app npm run build` |

## Sections

- [Docker Setup](/operations/docker-setup) — Container architecture and configuration
- [Running Locally](/operations/running-locally) — First-time setup and daily development
- [Artisan Commands](/operations/artisan-commands) — Complete command reference
- [Data Refresh](/operations/data-refresh) — Pipeline scheduling and annual refresh workflow
- [Troubleshooting](/operations/troubleshooting) — Common issues and fixes

## Environment

All commands run inside Docker containers. Never install PHP, PostgreSQL, or Node.js on the host machine.

```
Host machine
└── docker compose
    ├── app        (PHP 8.4 + Node 22)
    ├── nginx      (reverse proxy)
    ├── postgres   (PostgreSQL 16 + PostGIS 3.4 + H3)
    ├── redis      (cache + queue)
    └── horizon    (queue worker)
```
