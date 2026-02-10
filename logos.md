# TASK: Data Source Logos + Marquee Band Component

## Goal

Download logos for every data source we use. Store them as static assets. Build a slowly scrolling marquee band component showing all logos. Use it on the free-tier preview (builds trust: "real government data") and on generated report pages (footer attribution).

That's it. No database tables, no config wiring, no badge components, no admin integration.

---

## Step 1: Download Logos

### 1.1 Sources We Actually Use

These sources have data flowing through the pipeline right now:

| Slug | Agency | Website | What We Use |
|---|---|---|---|
| `scb` | Statistiska centralbyrån | scb.se | Income, employment, education, demographics |
| `skolverket` | Skolverket | skolverket.se | School quality, meritvärden |
| `bra` | Brottsförebyggande rådet | bra.se | Crime statistics (NTU) |
| `kolada` | Kolada (RKA) | kolada.se | Municipal KPIs |
| `polisen` | Polismyndigheten | polisen.se | Vulnerable area classifications |
| `kronofogden` | Kronofogdemyndigheten | kronofogden.se | Debt statistics |
| `osm` | OpenStreetMap | openstreetmap.org | POIs, parks, amenities |
| `trafiklab` | Trafiklab | trafiklab.se | Transit stops (GTFS) |

### 1.2 How to Get Them

For each source:

1. Check the agency website footer, press section ("press" / "media" / "grafisk profil" / "logotyp"), or "Om oss" page
2. Download SVG if available, otherwise highest-res PNG
3. Swedish government agencies (SCB, Skolverket, BRÅ, Kronofogden, Polisen) all use the standard government visual identity with the crown — their logos are typically available in press/media sections
4. OpenStreetMap logo: https://wiki.openstreetmap.org/wiki/Logos — the magnifying glass icon, CC BY-SA
5. Trafiklab: check trafiklab.se footer or about page
6. Kolada: check kolada.se — may just be a wordmark

If no downloadable logo exists for a source, screenshot the wordmark from their website header and crop it cleanly with transparent background.

### 1.3 Store Them

```
public/images/sources/
├── scb.svg          (or .png if SVG unavailable)
├── skolverket.svg
├── bra.svg
├── kolada.svg
├── polisen.svg
├── kronofogden.svg
├── osm.svg
├── trafiklab.svg
└── README.md        (note where each logo was downloaded from, date, license)
```

**Requirements:**
- Transparent background
- Reasonable file size (SVG < 15KB, PNG < 50KB)
- All logos should work at ~32px height — if they're illegible that small, get a simpler version (icon-only instead of full wordmark)
- For dark mode: logos should be visible on both light and dark backgrounds. If a logo is all-black, consider getting the white/inverted version too and naming it `scb-light.svg`

### 1.4 README.md in the logos folder

```markdown
# Data Source Logos

Downloaded [DATE]. Used for attribution marquee on preview and report pages.

| File | Source | Downloaded From | License |
|---|---|---|---|
| scb.svg | SCB | scb.se/om-scb/... | Public/government |
| skolverket.svg | Skolverket | skolverket.se/... | Public/government |
| ... | ... | ... | ... |

Usage: Source attribution only. Not modified. Links to source websites.
```

---

## Step 2: Marquee Band Component

### 2.1 What It Looks Like

A horizontal strip showing all source logos scrolling slowly left-to-right in an infinite loop. Subtle, professional, not flashy. Think "Trusted by" bands on SaaS landing pages, but for data sources.

```
┌─────────────────────────────────────────────────────┐
│  Data från:  [SCB] [Skolverket] [BRÅ] [Polisen] [Kolada] [Kronofogden] [OSM] [Trafiklab] [SCB] [Skol...  │
└─────────────────────────────────────────────────────┘
```

- Logos scroll continuously left at ~30px/second (slow, calm)
- Logos are duplicated so the strip loops seamlessly
- Subtle separator between logos (a thin dot · or just spacing)
- Muted opacity (60-70%) — this is attribution, not the main content
- On hover: pause scrolling, increase opacity to 100%
- Height: ~48px total (logo ~24-28px + padding)
- Optional label on the left: "Data från" or "Baserat på data från" (fixed, doesn't scroll)

### 2.2 Implementation

Use pure CSS animation — no JS libraries needed.

```tsx
// resources/js/components/source-marquee.tsx

const SOURCES = [
    { slug: 'scb', name: 'SCB', logo: '/images/sources/scb.svg' },
    { slug: 'skolverket', name: 'Skolverket', logo: '/images/sources/skolverket.svg' },
    { slug: 'bra', name: 'BRÅ', logo: '/images/sources/bra.svg' },
    { slug: 'polisen', name: 'Polisen', logo: '/images/sources/polisen.svg' },
    { slug: 'kolada', name: 'Kolada', logo: '/images/sources/kolada.svg' },
    { slug: 'kronofogden', name: 'Kronofogden', logo: '/images/sources/kronofogden.svg' },
    { slug: 'osm', name: 'OpenStreetMap', logo: '/images/sources/osm.svg' },
    { slug: 'trafiklab', name: 'Trafiklab', logo: '/images/sources/trafiklab.svg' },
];

export function SourceMarquee() {
    // Duplicate the list for seamless looping
    const items = [...SOURCES, ...SOURCES];

    return (
        <div className="w-full overflow-hidden border-t border-border bg-muted/30 py-3">
            <div className="flex items-center gap-2 px-4">
                <span className="shrink-0 text-[11px] font-medium text-muted-foreground/70 uppercase tracking-wider">
                    Data från
                </span>
                <div className="relative flex-1 overflow-hidden">
                    {/* Fade edges */}
                    <div className="pointer-events-none absolute inset-y-0 left-0 z-10 w-8 bg-gradient-to-r from-muted/30 to-transparent" />
                    <div className="pointer-events-none absolute inset-y-0 right-0 z-10 w-8 bg-gradient-to-l from-muted/30 to-transparent" />

                    <div className="animate-marquee flex items-center gap-8 hover:[animation-play-state:paused]">
                        {items.map((source, i) => (
                            <img
                                key={`${source.slug}-${i}`}
                                src={source.logo}
                                alt={source.name}
                                title={source.name}
                                className="h-5 w-auto shrink-0 object-contain opacity-50 grayscale transition-all hover:opacity-100 hover:grayscale-0"
                                loading="lazy"
                            />
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
```

### 2.3 CSS Animation

Add to your global CSS (or a Tailwind plugin):

```css
/* In resources/css/app.css or equivalent */

@keyframes marquee {
    0% { transform: translateX(0); }
    100% { transform: translateX(-50%); }
}

.animate-marquee {
    animation: marquee 40s linear infinite;
    width: max-content;
}
```

The `translateX(-50%)` works because we duplicated the items — when the first set scrolls fully off-screen, the second set is exactly where the first one started. Seamless loop.

**Speed tuning:** `40s` for 8 logos feels calm. If it feels too slow or fast, adjust. Shorter = faster.

### 2.4 Dark Mode

Logos use `grayscale` + `opacity-50` by default, which works on both light and dark backgrounds. On hover they show full color. If a specific logo is invisible on dark backgrounds, use the `-light.svg` variant:

```tsx
// In SOURCES array, handle dark mode variants:
{
    slug: 'scb',
    name: 'SCB',
    logo: '/images/sources/scb.svg',
    logoDark: '/images/sources/scb-light.svg',  // optional
}
```

Then in the component, check `document.documentElement.classList.contains('dark')` or use Tailwind's `dark:` classes with two `<img>` tags:

```tsx
<img src={source.logo} className="h-5 ... dark:hidden" />
<img src={source.logoDark ?? source.logo} className="h-5 ... hidden dark:block" />
```

Only do this if specific logos are actually invisible in dark mode. Most government logos with the crown render fine with the grayscale treatment.

---

## Step 3: Use the Component

### 3.1 Free-Tier Preview (Sidebar)

In the locked preview section of the explore sidebar, add the marquee at the bottom:

```tsx
// In resources/js/pages/explore/components/locked-preview.tsx
// After the CTA button / paywall section:

import { SourceMarquee } from '@/components/source-marquee';

// ... at the bottom of the preview:
<SourceMarquee />
```

This goes below the "Unlock full analysis" CTA. It reinforces credibility: "this isn't made-up data, it's from SCB, Skolverket, Polisen..."

### 3.2 Report Page Footer

On the generated report view page, add the marquee in the footer area:

```tsx
// In resources/js/pages/reports/show.tsx (or equivalent report page)
// Footer section:

<footer className="mt-12 border-t pt-6">
    <SourceMarquee />
    <p className="mt-2 text-center text-[10px] text-muted-foreground">
        Alla data är hämtade från offentliga svenska myndigheter och öppna datakällor.
    </p>
</footer>
```

### 3.3 That's It

Don't add it to the admin dashboard, the methodology page, the map page, the login page, or anywhere else. Two places: preview sidebar and report footer. Keep it focused.

---

## Verification

- [ ] All 8 logo files exist in `public/images/sources/`
- [ ] Logos render at ~24px height without being blurry or illegible
- [ ] Marquee scrolls smoothly left, loops seamlessly (no jump/gap)
- [ ] Hovering pauses the scroll and removes grayscale
- [ ] Marquee appears on the free-tier locked preview
- [ ] Marquee appears on the report page footer
- [ ] Works in dark mode (logos visible)
- [ ] No layout shift or overflow issues on mobile
- [ ] "Data från" label is visible and doesn't scroll

## What NOT to Do

- Don't create a database table for this
- Don't add a config file mapping sources to metadata
- Don't build a SourceBadge component for inline use
- Don't wire logos into the indicator system or admin dashboard
- Don't hotlink logos from agency websites — download and serve locally
- Don't modify government logos (no recoloring, cropping the crown, etc.)
- Don't use this as a "trusted by" section — it's source attribution
