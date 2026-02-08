# TASK: Data Source Logos — Download, Store & Connect

## Context

The sidebar teaser shows source badges like `SCB  Skolverket  Trafiklab  OpenStreetMap` as plain text. These should be recognizable logos. When a user sees the Skolverket crown or the SCB mark, it signals "this is real government data" far more effectively than text.

This task downloads official logos/icons for every data source, stores them as static assets, and connects them to the source system so any component (sidebar badges, report page, admin dashboard, methodology footer) can render them.

## What to Do

1. Download official logos for each data source
2. Store in `/public/images/sources/`
3. Create a `data_sources` config or database table mapping source slugs to metadata (name, logo path, URL, description)
4. Update the indicator seeder/system to reference source metadata
5. Create a reusable `<SourceBadge>` component

---

## Step 1: Source List & Logo Acquisition

### 1.1 Sources and Where to Find Logos

| Source Slug | Display Name | Official Website | Logo Notes |
|---|---|---|---|
| `scb` | Statistiska centralbyrån (SCB) | scb.se | Blue wordmark. Available in their press/media section or footer. SVG preferred. |
| `skolverket` | Skolverket | skolverket.se | Crown + wordmark. Available in press/media. Swedish government agency style. |
| `trafiklab` | Trafiklab / Samtrafiken | trafiklab.se | Trafiklab logo for the API, Samtrafiken for the data provider. Use Trafiklab since that's our API source. |
| `osm` | OpenStreetMap | openstreetmap.org | The magnifying glass map icon. Widely available, open license. Use from wiki.openstreetmap.org/wiki/Logos |
| `bra` | Brottsförebyggande rådet (BRÅ) | bra.se | Government agency wordmark. Blue. Check press section. |
| `kronofogden` | Kronofogden | kronofogden.se | Government agency. Crown + wordmark. Press section. |
| `polisen` | Polismyndigheten | polisen.se | Police star badge. Widely recognized. Press section or public media kit. |
| `lantmateriet` | Lantmäteriet | lantmateriet.se | Land survey agency. Logo available in press section. (Future data source.) |
| `photon` | Photon / Komoot | photon.komoot.io | Komoot logo for geocoding attribution. |

### 1.2 Download Process

The agent should:

1. Visit each source's website and find their press/media section or logo page
2. Download the logo in the best available format: **SVG preferred**, PNG fallback
3. If SVG is available, also create a small PNG version (for contexts where SVG isn't ideal)
4. If no press kit exists, take a clean screenshot of their logo from the website header and crop it. Use web_fetch or the browser to locate downloadable assets.
5. Store originals in `storage/app/source-logos/originals/` (for reference)
6. Process and copy finals to `public/images/sources/`

### 1.3 File Naming & Sizing

```
public/images/sources/
├── scb.svg
├── scb.png              (height: 24px, for inline badge use)
├── scb-full.png         (height: 48px, for report/methodology section)
├── skolverket.svg
├── skolverket.png
├── skolverket-full.png
├── trafiklab.svg
├── trafiklab.png
├── trafiklab-full.png
├── osm.svg
├── osm.png
├── osm-full.png
├── bra.svg
├── bra.png
├── bra-full.png
├── kronofogden.svg
├── kronofogden.png
├── kronofogden-full.png
├── polisen.svg
├── polisen.png
├── polisen-full.png
└── photon.svg
    photon.png
    photon-full.png
```

**Small (`.png`, 24px height):** For inline badges in the sidebar and compact UI. Crisp at small size.
**Full (`.png-full`, 48px height):** For the report methodology section and admin dashboard.
**SVG:** For any context where resolution independence matters. Preferred when available.

**If SVG is not available:** Download the highest-resolution PNG/JPG available, then resize to the two target sizes. Use ImageMagick or PHP's GD/Imagick:

```bash
convert original.png -resize x24 scb.png
convert original.png -resize x48 scb-full.png
```

### 1.4 Fallback: Text Abbreviation

Some sources may not have easily downloadable logos, or their logos may not work well at 24px height. For these, create a simple styled text badge as fallback. The component handles this gracefully (see Step 4).

---

## Step 2: Data Source Configuration

### 2.1 Option A: Config File (Recommended for Now)

```php
// config/data_sources.php

return [
    'scb' => [
        'name' => 'SCB',
        'full_name' => 'Statistiska centralbyrån',
        'url' => 'https://www.scb.se',
        'logo' => '/images/sources/scb.svg',
        'logo_small' => '/images/sources/scb.png',
        'logo_full' => '/images/sources/scb-full.png',
        'description' => 'Officiell svensk statistik — inkomst, sysselsättning, utbildning, demografi',
        'license' => 'CC0 / Public domain',
        'color' => '#003366',  // Brand color for text badge fallback
    ],
    'skolverket' => [
        'name' => 'Skolverket',
        'full_name' => 'Statens skolverk',
        'url' => 'https://www.skolverket.se',
        'logo' => '/images/sources/skolverket.svg',
        'logo_small' => '/images/sources/skolverket.png',
        'logo_full' => '/images/sources/skolverket-full.png',
        'description' => 'Skoldata — meritvärden, måluppfyllelse, lärarbehörighet',
        'license' => 'Open data',
        'color' => '#1B4F72',
    ],
    'trafiklab' => [
        'name' => 'Trafiklab',
        'full_name' => 'Trafiklab / Samtrafiken',
        'url' => 'https://www.trafiklab.se',
        'logo' => '/images/sources/trafiklab.svg',
        'logo_small' => '/images/sources/trafiklab.png',
        'logo_full' => '/images/sources/trafiklab-full.png',
        'description' => 'Kollektivtrafikdata — hållplatser, avgångar, linjer (GTFS Sverige 2)',
        'license' => 'CC0',
        'color' => '#E74C3C',
    ],
    'osm' => [
        'name' => 'OpenStreetMap',
        'full_name' => 'OpenStreetMap',
        'url' => 'https://www.openstreetmap.org',
        'logo' => '/images/sources/osm.svg',
        'logo_small' => '/images/sources/osm.png',
        'logo_full' => '/images/sources/osm-full.png',
        'description' => 'Geografisk data — parker, butiker, service, närhet',
        'license' => 'ODbL',
        'color' => '#7EBC6F',
    ],
    'bra' => [
        'name' => 'BRÅ',
        'full_name' => 'Brottsförebyggande rådet',
        'url' => 'https://www.bra.se',
        'logo' => '/images/sources/bra.svg',
        'logo_small' => '/images/sources/bra.png',
        'logo_full' => '/images/sources/bra-full.png',
        'description' => 'Brottsstatistik — anmälda brott, trygghet, utsatta områden',
        'license' => 'Public domain',
        'color' => '#2C3E50',
    ],
    'kronofogden' => [
        'name' => 'Kronofogden',
        'full_name' => 'Kronofogdemyndigheten',
        'url' => 'https://www.kronofogden.se',
        'logo' => '/images/sources/kronofogden.svg',
        'logo_small' => '/images/sources/kronofogden.png',
        'logo_full' => '/images/sources/kronofogden-full.png',
        'description' => 'Skuld- och exekutionsdata — skuldsatta, vräkningar, betalningsförelägganden',
        'license' => 'PSI / Open data',
        'color' => '#1A5276',
    ],
    'polisen' => [
        'name' => 'Polisen',
        'full_name' => 'Polismyndigheten',
        'url' => 'https://www.polisen.se',
        'logo' => '/images/sources/polisen.svg',
        'logo_small' => '/images/sources/polisen.png',
        'logo_full' => '/images/sources/polisen-full.png',
        'description' => 'Polisdata — utsatta områden, klassificering',
        'license' => 'Public',
        'color' => '#1B2631',
    ],
    'photon' => [
        'name' => 'Photon',
        'full_name' => 'Photon / Komoot',
        'url' => 'https://photon.komoot.io',
        'logo' => '/images/sources/photon.svg',
        'logo_small' => '/images/sources/photon.png',
        'logo_full' => '/images/sources/photon-full.png',
        'description' => 'Geokodning — adresser till koordinater',
        'license' => 'Apache 2.0',
        'color' => '#2ECC71',
    ],
];
```

### 2.2 Option B: Database Table (For Future Admin Control)

If we want the admin to manage sources and add new ones without code changes:

```php
Schema::create('data_sources', function (Blueprint $table) {
    $table->id();
    $table->string('slug', 40)->unique();
    $table->string('name', 60);
    $table->string('full_name');
    $table->string('url')->nullable();
    $table->string('logo_path')->nullable();       // Relative to /public
    $table->string('description')->nullable();
    $table->string('license', 60)->nullable();
    $table->string('brand_color', 7)->nullable();   // Hex color
    $table->boolean('is_active')->default(true);
    $table->integer('display_order')->default(0);
    $table->timestamps();
});
```

Seed from the config above. The `indicators.source` column already exists and stores slugs like `'scb'`, `'skolverket'` etc. This table gives those slugs a face.

**Recommendation:** Start with the config file. It's simpler and logos don't change. If the admin dashboard needs to manage sources, migrate to database later.

---

## Step 3: Connect to Existing Indicator System

### 3.1 Helper: Get Source Metadata

```php
// app/Helpers/DataSourceHelper.php (or a simple helper function)

function data_source(string $slug): ?array
{
    return config("data_sources.{$slug}");
}

function data_source_logo(string $slug, string $size = 'small'): ?string
{
    $source = config("data_sources.{$slug}");
    if (!$source) return null;

    return match($size) {
        'svg' => $source['logo'] ?? null,
        'small' => $source['logo_small'] ?? null,
        'full' => $source['logo_full'] ?? null,
        default => $source['logo_small'] ?? null,
    };
}
```

### 3.2 Include in API Responses

When the sidebar preview or report page needs source info, include it:

```php
// In the location preview endpoint
'sources' => Indicator::where('is_active', true)
    ->whereHas('values', fn($q) => $q->where('deso_code', $deso->deso_code)->whereNotNull('raw_value'))
    ->distinct()
    ->pluck('source')
    ->map(fn($slug) => [
        'slug' => $slug,
        'name' => config("data_sources.{$slug}.name", $slug),
        'logo' => config("data_sources.{$slug}.logo_small"),
        'url' => config("data_sources.{$slug}.url"),
        'color' => config("data_sources.{$slug}.color"),
    ])
    ->values(),
```

### 3.3 Inertia Shared Data

Make source config available to all pages:

```php
// In HandleInertiaRequests middleware
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'dataSources' => config('data_sources'),
    ];
}
```

This way any component can look up `dataSources.scb.logo` without an extra API call.

---

## Step 4: Frontend — SourceBadge Component

### 4.1 Compact Badge (Sidebar)

```tsx
// resources/js/Components/SourceBadge.tsx

interface SourceBadgeProps {
    slug: string;
    size?: 'sm' | 'md';
    showName?: boolean;
}

export function SourceBadge({ slug, size = 'sm', showName = false }: SourceBadgeProps) {
    const { dataSources } = usePage<SharedProps>().props;
    const source = dataSources[slug];

    if (!source) {
        return (
            <span className="text-[10px] px-1.5 py-0.5 bg-muted rounded text-muted-foreground">
                {slug}
            </span>
        );
    }

    const imgSize = size === 'sm' ? 'h-3.5' : 'h-5';

    return (
        <a
            href={source.url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 px-1.5 py-0.5 bg-muted/50 rounded hover:bg-muted transition-colors"
            title={source.full_name}
        >
            {source.logo_small ? (
                <img
                    src={source.logo_small}
                    alt={source.name}
                    className={`${imgSize} w-auto object-contain`}
                    loading="lazy"
                />
            ) : (
                <span
                    className="text-[10px] font-semibold"
                    style={{ color: source.color }}
                >
                    {source.name}
                </span>
            )}
            {showName && (
                <span className="text-[10px] text-muted-foreground">
                    {source.name}
                </span>
            )}
        </a>
    );
}
```

### 4.2 Source Badge Row (Sidebar Preview)

Replace the text-only badges from the sidebar teaser task:

```tsx
function SourceBadges({ sources }: { sources: Array<{ slug: string }> }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {sources.map(source => (
                <SourceBadge key={source.slug} slug={source.slug} />
            ))}
        </div>
    );
}
```

### 4.3 Source Attribution (Report Methodology Footer)

Larger format for the report page:

```tsx
function SourceAttribution({ sources }: { sources: string[] }) {
    const { dataSources } = usePage<SharedProps>().props;

    return (
        <div className="space-y-3">
            <h4 className="text-sm font-semibold text-muted-foreground uppercase tracking-wide">
                Datakällor
            </h4>
            {sources.map(slug => {
                const source = dataSources[slug];
                if (!source) return null;
                return (
                    <div key={slug} className="flex items-start gap-3">
                        {source.logo_full ? (
                            <img
                                src={source.logo_full}
                                alt={source.name}
                                className="h-6 w-auto object-contain mt-0.5"
                            />
                        ) : (
                            <span className="text-sm font-semibold" style={{ color: source.color }}>
                                {source.name}
                            </span>
                        )}
                        <div>
                            <a
                                href={source.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-sm font-medium hover:underline"
                            >
                                {source.full_name}
                            </a>
                            <p className="text-xs text-muted-foreground">
                                {source.description}
                            </p>
                            <p className="text-[10px] text-muted-foreground">
                                Licens: {source.license}
                            </p>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
```

### 4.4 Admin Dashboard — Source Column

In the indicator management table, replace the plain text source column with the logo badge:

```tsx
// In Admin/Indicators.tsx table
<TableCell>
    <SourceBadge slug={indicator.source} showName />
</TableCell>
```

---

## Step 5: Legal — Logo Usage

### 5.1 Swedish Government Agency Logos

Swedish government agencies (SCB, Skolverket, BRÅ, Kronofogden, Polisen) use a standardized visual identity. Their logos typically include the Swedish crown (lilla riksvapnet). Usage rules:

- **Referencing the source is OK.** We're saying "this data comes from SCB" — that's factual attribution, not impersonation.
- **Don't modify the logo.** Don't recolor, crop the crown, or add effects.
- **Don't imply endorsement.** Don't suggest SCB approves or is affiliated with our product. Context should make clear this is source attribution.
- **Link to the source.** Each badge links to the agency's website.

### 5.2 OpenStreetMap

OSM logo usage: https://wiki.openstreetmap.org/wiki/Logos
The magnifying glass logo is available under CC BY-SA 2.0. Attribution required (which we provide by showing the badge and linking).

### 5.3 Trafiklab

Trafiklab is run by Samtrafiken. Check https://www.trafiklab.se for any logo usage guidelines. Usually fine for source attribution.

### 5.4 When In Doubt

If a logo can't be downloaded or usage is unclear: fall back to the text badge with the brand color. The component handles this automatically (`logo_small: null` → renders text).

---

## Verification

### Assets
- [ ] Every source in `config/data_sources.php` has at least one logo file in `/public/images/sources/`
- [ ] SVG files render correctly at all sizes
- [ ] Small PNGs (24px height) are crisp, not blurry
- [ ] Full PNGs (48px height) are crisp
- [ ] Files are reasonable size (< 50KB each, SVGs < 10KB)
- [ ] No broken image links in any component

### Components
- [ ] `<SourceBadge slug="scb" />` renders logo at compact size
- [ ] `<SourceBadge slug="scb" showName />` renders logo + "SCB" text
- [ ] `<SourceBadge slug="unknown_source" />` renders text fallback gracefully
- [ ] Badge links to source website (opens in new tab)
- [ ] Badge has hover state and title tooltip with full name

### Integration
- [ ] Sidebar preview shows source logos instead of text badges
- [ ] Admin indicator table shows source logos
- [ ] Report methodology footer shows full source attribution with descriptions
- [ ] Shared Inertia props include `dataSources` config

### Visual
- [ ] Logos look professional at 24px height (not pixelated, not illegible)
- [ ] Government agency logos have appropriate gravitas (crown visible)
- [ ] Color scheme of badges doesn't clash with the app theme
- [ ] Dark mode: logos are still visible (consider a subtle background or white-space padding)

---

## What NOT to Do

- **DO NOT hotlink logos from agency websites.** Download and serve locally. External URLs break, get rate-limited, or change.
- **DO NOT use favicon.ico files as logos.** Favicons are 16x16 or 32x32 — too small and often low quality. Find the real logo asset.
- **DO NOT create custom/modified versions of government logos.** Use them as-is. Recoloring a government agency logo is legally questionable and looks unprofessional.
- **DO NOT add logos for sources we don't actually use yet.** Only include sources that have data flowing through the indicator pipeline. When BRÅ data is integrated, add the BRÅ logo then.
- **DO NOT overthink dark mode.** If a logo has a transparent background and is mostly dark, it won't show on dark backgrounds. Solution: add a subtle `bg-white/80 rounded` wrapper around the image. Don't create dark-mode variants of government logos.
- **DO NOT store logos in the database as BLOBs.** Static files in `/public` served by the web server. Simple, fast, cacheable.