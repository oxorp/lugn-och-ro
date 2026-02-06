# Laravel Template

A Laravel 12 starter template with Inertia.js, React 19, Tailwind CSS v4, and Fortify authentication.

## Stack

- **Backend**: Laravel 12, PHP 8.4
- **Frontend**: Inertia.js v2, React 19, Tailwind CSS v4
- **Auth**: Laravel Fortify with two-factor authentication
- **Database**: PostgreSQL (via Docker)
- **Cache/Queue**: Redis (via Docker), Laravel Horizon
- **Monitoring**: Laravel Telescope (dev only)
- **Testing**: PHPUnit

## Getting Started

```bash
# Install dependencies
composer install
npm install

# Copy environment file
cp .env.example .env
php artisan key:generate

# Start Docker services
docker compose up -d

# Run migrations
php artisan migrate

# Build frontend assets
npm run build

# Or run dev server
composer run dev
```

## Development

```bash
# Run tests
php artisan test --compact

# Format PHP code
vendor/bin/pint

# Run queue worker (or use Horizon)
php artisan queue:work
php artisan horizon

# Access dashboards (local only)
# Horizon: /horizon
# Telescope: /telescope
```

## Project Structure

```
app/
├── Actions/Fortify/     # User creation, password reset
├── Concerns/            # Shared traits for validation
├── Http/
│   ├── Controllers/
│   │   └── Settings/    # Profile, password, 2FA controllers
│   ├── Middleware/      # Inertia, appearance handling
│   └── Requests/        # Form request validation
├── Models/              # Eloquent models
└── Providers/           # Service providers (incl. Horizon, Telescope)

resources/
└── js/
    └── Pages/           # Inertia React pages
        ├── auth/        # Login, register, password reset
        └── settings/    # Profile, password, appearance

database/
├── factories/           # Model factories
├── migrations/          # Database migrations
└── seeders/             # Database seeders
```

## Included Features

- User registration and login
- Email verification
- Password reset
- Two-factor authentication
- Profile management
- Password change
- Appearance/theme settings
- Docker development environment
- Laravel Horizon for queue management
- Laravel Telescope for debugging (dev only)
