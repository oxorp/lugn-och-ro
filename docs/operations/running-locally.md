# Running Locally

> First-time setup and daily development workflow.

## Prerequisites

- Docker Desktop (or Docker Engine + Compose)
- Git

No PHP, Node.js, or PostgreSQL installation required on the host.

## First-Time Setup

```bash
# 1. Clone and enter directory
git clone <repo-url> && cd platsindex

# 2. Copy environment file
cp .env.example .env

# 3. Build and start containers
docker compose up -d --build

# 4. Install dependencies
docker compose exec app composer install
docker compose exec app npm install

# 5. Generate app key
docker compose exec app php artisan key:generate

# 6. Run migrations and seed
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed

# 7. Import DeSO boundaries
docker compose exec app php artisan import:deso-areas

# 8. Build frontend
docker compose exec app npm run build
```

## Daily Development

```bash
# Start containers
docker compose up -d

# Frontend dev server (hot reload)
docker compose exec app npm run dev

# Or full dev environment (PHP + Vite)
docker compose exec app composer run dev

# Run artisan commands
docker compose exec app php artisan <command>

# Access Tinker REPL
docker compose exec app php artisan tinker
```

## Accessing the Application

| Service | URL |
|---|---|
| Application | `http://localhost` (or `APP_PORT`) |
| Horizon Dashboard | `http://localhost/horizon` |
| Telescope | `http://localhost/telescope` |

## Database Access

Connect from host machine:

```
Host: localhost
Port: 5432 (or DB_PORT)
Database: realestate
Username: realestate
Password: secret
```

Or use `psql` inside the container:

```bash
docker compose exec postgres psql -U realestate
```

## Running Tests

```bash
docker compose exec app php artisan test --compact
```

## Common Tasks

```bash
# Run full data pipeline
docker compose exec app php artisan pipeline:run --year=2024

# Recompute scores after weight changes
docker compose exec app php artisan compute:scores --year=2024

# Check queue status
docker compose exec app php artisan horizon:status

# Clear caches
docker compose exec app php artisan cache:clear
docker compose exec app php artisan config:clear
```

## Related

- [Docker Setup](/operations/docker-setup)
- [Artisan Commands](/operations/artisan-commands)
