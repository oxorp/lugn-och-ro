# CLAUDE.md

## What is this project?

A Swedish real estate prediction platform that scores neighborhoods using public government data (crime, demographics, schools, financial distress). The frontend is an interactive map showing Sweden's ~6,160 DeSO (Demografiska statistikområden) statistical areas with color-coded scores.

## Your current task

Read `task.md` — it contains the complete step-by-step instructions for the current milestone. Follow it in order. Do not skip ahead.

Read `data_pipeline_specification.md` for full business context, data sources, and architecture decisions. You don't need to implement everything in that doc right now — just understand the bigger picture so your decisions align with where this is going.

## Stack

- Laravel 12, PHP 8.4
- Inertia.js v2 + React 19 + TypeScript
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

## Data Pipeline Best Practices

### SCB PX-Web API
- Base URL: `https://api.scb.se/OV0104/v1/doris/sv/ssd/`
- Use POST with JSON query body; response is JSON-stat2 format
- DeSO 2025 codes have `_DeSO2025` suffix in response — strip it with `extractDesoCode()`
- When a table contains both old DeSO codes and DeSO2025 codes, prefer DeSO2025 versions
- Employment data (`AM0207`) only goes to 2021 with old DeSO codes (5,835 matched)
- All other indicators have 2024 data with DeSO 2025 codes (6,160 matched)
- Need `memory_limit=1G` for large API responses

### Bulk Database Operations
- Use `DB::table()->upsert()` with chunks of 1000 for ingestion (not individual `updateOrCreate`)
- Individual Eloquent `updateOrCreate` in loops causes memory exhaustion at scale (6,160+ rows × 8 indicators)
- `PERCENT_RANK() OVER (ORDER BY raw_value)` for rank percentile normalization in PostgreSQL

### Skolverket APIs
- Two APIs: Skolenhetsregistret v2 (school registry) and Planned Educations v3 (statistics)
- Planned Educations v3 max page size is ~100 (returns 404 on size=500)
- Accept header required: `application/vnd.skolverket.plannededucations.api.v3.hal+json`
- Statistics use Swedish decimal format (comma separator) — convert with `str_replace(',', '.', $value)`
- `valueType` must be `EXISTS` for valid values; skip `.` and `..` placeholder values
- `Http::pool()` returns `ConnectionException` for failed requests — always check `instanceof Response` before calling `->successful()`
- Most school-level stats are from 2020/21 (Skolverket restricted publication after that)
- Teacher certification data has much better coverage than merit/achievement data
- Use `--academic-year=2020/21 --calendar-year=2024` for aggregation to align with SCB data year

### BRÅ Crime Data (No API — Excel/CSV only)
- BRÅ has explicitly no public API — all data via Excel/CSV downloads
- Kommun-level CSV: `storage/app/data/raw/bra/anmalda_brott_kommuner_2025.csv` (290 kommuner, total crimes + rate per 100k)
- National Excel: `storage/app/data/raw/bra/anmalda_brott_10_ar.xlsx` (crime categories by year, national level only)
- Category-level kommun rates estimated using national proportions applied to kommun totals
- Excel files have Swedish formatting: ".." = suppressed, "-" = zero, comma decimals, BOM in CSV
- `BraDataService` handles all parsing and estimation
- Disaggregation from kommun→DeSO uses demographic-weighted model (income 35%, employment 20%, education 15%, vulnerability 30%+20%)

### Police Vulnerability Areas
- GeoJSON download: `https://polisen.se/contentassets/.../uso_2025_geojson.zip` (44KB, 65 areas)
- CRS is EPSG:3006 (SWEREF99TM) — must transform to WGS84 via PostGIS `ST_Transform`
- Properties: NAMN (name), KATEGORI ("Utsatt område"/"Särskilt utsatt område"), REGION, LOKALPOLISOMRADE, ORT
- 46 utsatt + 19 särskilt utsatt = 65 total, ~275 DeSOs with >=25% overlap
- Geometry type is Polygon — wrap with ST_Multi for MULTIPOLYGON storage

### NTU Survey Data
- Län-level Excel: `storage/app/data/raw/bra/ntu_lan_2017_2025.xlsx` (21 län, 2017-2025)
- Key sheet: R4.1 "Otrygghet vid utevistelse sent på kvällen" (% feeling unsafe at night)
- Values are percentages, rows by län, columns by year, CI in last 2 columns
- Disaggregated to DeSO using inverted demographic weighting (safer areas get higher safety scores)

### Kronofogden / Kolada API
- **Kolada API** (`https://api.kolada.se/v3/`) is the primary source — clean JSON, no auth, all 290 kommuner
- URL format: `/data/kpi/{kpiId}/year/{year}` (NOT `/municipality/all/year/`)
- Key KPIs: `N00989` (debt rate %), `N00990` (median debt SEK), `U00958` (eviction rate per 100k)
- Municipality list: `/municipality` — returns all entries; filter on `type === 'K'` and exclude `id === '0000'` (Riket)
- Region codes (type "L") have 4-digit IDs too (e.g., "0001" = Region Stockholm) — don't rely on string length
- Response shape: `values[]` → each has `municipality`, `period`, `values[]` (with `gender` T/M/K and `value`)
- Disaggregation: kommun→DeSO using weighted propensity model (income 35%, employment 20%, education 15%, low_econ 15%, vulnerability 15%+10%)
- Constraint: population-weighted DeSO average must match kommun rate exactly
- Clamp estimates to 10%-300% of kommun rate before constraining
- 3 indicators: `debt_rate_pct` (0.06), `eviction_rate` (0.04), `median_debt_sek` (0.02) — all direction=negative
- `median_debt_sek` is kommun-level only (can't disaggregate a median) — flat for all DeSOs in kommun
- R² = 0.4030 for cross-validation (demographics explain ~40% of variance)
- Do NOT use `foreign_background_pct` in disaggregation formula

### Artisan Commands
- `ingest:scb --all` — Ingest all SCB indicators (or `--indicator=slug --year=2024`)
- `ingest:skolverket-schools` — Ingest school locations + metadata from Skolverket
- `ingest:skolverket-stats` — Ingest performance statistics per school
- `aggregate:school-indicators --academic-year=2020/21 --calendar-year=2024` — Aggregate school stats to DeSO indicators
- `ingest:bra-crime --year=2024` — Ingest BRÅ kommun-level crime statistics from CSV/Excel
- `ingest:ntu --year=2025` — Ingest NTU survey data from Excel
- `ingest:vulnerability-areas --year=2025` — Import police vulnerability area polygons
- `disaggregate:crime --year=2024` — Disaggregate crime data from kommun/län to DeSO
- `ingest:kronofogden --year=2024 --source=kolada` — Ingest Kronofogden debt data from Kolada API
- `disaggregate:kronofogden --year=2024` — Disaggregate kommun debt rates to DeSO level
- `aggregate:kronofogden-indicators --year=2024` — Create indicator values from disaggregation results
- `normalize:indicators --year=2024` — Normalize all active indicators
- `compute:scores --year=2024` — Compute composite scores

### Service Architecture
- `ScbApiService` — Fetches and parses SCB PX-Web data
- `SkolverketApiService` — Fetches school registry and statistics data
- `BraDataService` — Parses BRÅ Excel/CSV, estimates category rates from national proportions
- `KronofogdenService` — Fetches Kronofogden data from Kolada API (debt rates, median debt, evictions)
- `NormalizationService` — Rank percentile, min-max, z-score normalization
- `ScoringService` — Weighted composite scores with direction handling

### Key Routes
- `/api/deso/scores?year=2024` — Returns composite scores keyed by deso_code (1-hour cache)
- `/api/deso/{desoCode}/schools` — Returns schools for a specific DeSO with latest statistics
- `/api/deso/{desoCode}/crime` — Returns crime rates, vulnerability info, perceived safety for a DeSO
- `/api/deso/{desoCode}/financial` — Returns estimated debt rate, eviction rate, kommun actual, high-distress flag
- `/admin/indicators` — Admin dashboard for indicator management
- `/admin/indicators/{indicator}` — Update indicator weight/direction
- `/admin/recompute` — Re-normalize and recompute all scores

## Normalization Rules
- Socioeconomic indicators (income, employment, education, crime, debt): **national** percentile rank
- Amenity/access indicators (POI density, transit, healthcare): **urbanity-stratified** percentile rank
- The scoring engine doesn't care about normalization scope — it reads normalized_value identically
- Rule of thumb: if it measures physical access → stratified. If it measures a rate or outcome → national.

## POI System
- All POI data goes in the generic `pois` table regardless of source or category
- Adding a new POI type = new row in `poi_categories` + a scrape run
- Always compute per-capita density, never raw counts
- Use catchment radius (not DeSO boundary) for access metrics
- Zero is data (store as 0.0), NULL means unmeasured
- OSM first, Google Places for gaps

## Data Source Policy
- No restriction on source type (government, commercial, open-source all valid)
- Restriction is on individual-level data: aggregate statistics only
- GDPR Article 10 constraint specifically on criminal conviction data linked to individuals

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.8
- inertiajs/inertia-laravel (INERTIA) - v2
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- laravel/telescope (TELESCOPE) - v5
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/react (INERTIA) - v2
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `wayfinder-development` — Activates whenever referencing backend routes in frontend components. Use when importing from @/actions or @/routes, calling Laravel routes from TypeScript, or working with Wayfinder route functions.
- `inertia-react-development` — Develops Inertia.js v2 React client-side applications. Activates when creating React pages, forms, or navigation; using &lt;Link&gt;, &lt;Form&gt;, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions React with Inertia, React pages, React forms, or React navigation.
- `tailwindcss-development` — Styles applications using Tailwind CSS v4 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

=== inertia-laravel/v2 rules ===

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `php artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== wayfinder/core rules ===

# Laravel Wayfinder

Wayfinder generates TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

- IMPORTANT: Activate `wayfinder-development` skill whenever referencing backend routes in frontend components.
- Invokable Controllers: `import StorePost from '@/actions/.../StorePostController'; StorePost()`.
- Parameter Binding: Detects route keys (`{post:slug}`) — `show({ slug: "my-post" })`.
- Query Merging: `show(1, { mergeQuery: { page: 2, sort: null } })` merges with current URL, `null` removes params.
- Inertia: Use `.form()` with `<Form>` component or `form.submit(store())` with useForm.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.
</laravel-boost-guidelines>
