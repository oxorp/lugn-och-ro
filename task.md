# TASK: Internationalization (i18n) — English + Swedish, No Silent Fallbacks

## Context

Every string in the app is currently hardcoded in English. The longer we wait on i18n, the more components get built with inline strings, and the more painful the extraction becomes. This task sets up the i18n infrastructure early, translates nothing yet (that comes later), and — critically — makes missing translations **loud and obvious** instead of silently falling back to English.

**The anti-pattern we're avoiding:** Most i18n setups default to falling back to the base language when a translation is missing. This means you ship Swedish mode, 80% of it is actually English, nobody notices, and users get a jarring mix. Instead: missing Swedish translations should show the raw key (like `sidebar.score.label`) or a visible error marker so every gap is impossible to miss during development and QA.

---

## Goals

1. Set up i18n infrastructure for both frontend (React) and backend (Laravel)
2. English as the **development/source language** — all keys authored in English, the complete reference translation
3. Swedish as the **default served language** — what users get unless they explicitly switch to English. Swedish is the SEO language, the `<html lang="sv">` language, the sitemap language, the language Google indexes.
4. Missing Swedish translations show visibly as `🔑 key.name` — never silently fall back to English
5. Locale detection: URL prefix (`/en/`) → cookie → browser → **default to Swedish**
6. Language switcher in the UI
7. Extract all existing hardcoded strings into translation files
8. URL-based locale routing for SEO (`/` = Swedish, `/en/` = English)

**The mental model:** English is the language of the codebase. Swedish is the language of the product. A visitor to the site gets Swedish. A developer reading the source code sees English keys.

---

## Step 1: Frontend i18n — react-i18next

### 1.1 Install Dependencies

```bash
npm install i18next react-i18next i18next-browser-languagedetector i18next-http-backend
```

- `i18next` — core library
- `react-i18next` — React bindings (hooks, components)
- `i18next-browser-languagedetector` — auto-detect user's language
- `i18next-http-backend` — load translation files lazily (optional, can use static imports instead)

### 1.2 Configuration

Create `resources/js/i18n.ts`:

```typescript
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import LanguageDetector from 'i18next-browser-languagedetector';

import en from './locales/en.json';
import sv from './locales/sv.json';

i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      en: { translation: en },
      sv: { translation: sv },
    },
    fallbackLng: 'sv',           // Swedish is the default — this is a Swedish product
    supportedLngs: ['en', 'sv'],
    interpolation: {
      escapeValue: false,         // React already escapes
    },
    detection: {
      // URL prefix is highest priority, then cookie, then browser
      // Note: browser detection means an English expat in Sweden gets English
      // which is fine — they can switch. But a fresh visit with a Swedish
      // browser gets Swedish, which is the right default.
      order: ['path', 'cookie', 'navigator'],
      lookupFromPathIndex: 0,     // /en/... → 'en'
      lookupCookie: 'locale',
      caches: ['cookie'],
      cookieMinutes: 525600,      // 1 year
    },

    // THE CRITICAL PART — no silent fallbacks for Swedish
    saveMissing: true,
    missingKeyHandler: (lngs, ns, key, fallbackValue) => {
      if (process.env.NODE_ENV === 'development') {
        console.warn(`[i18n] Missing translation: ${key} for ${lngs.join(', ')}`);
      }
    },

    // Return the key itself when translation is missing (not the English fallback)
    // This is configured per-language below
    returnNull: false,
    returnEmptyString: false,
  });

// CRITICAL: Override fallback behavior for Swedish
// When language is 'sv' and key is missing, show the key — not English
i18n.on('missingKey', (lngs, namespace, key) => {
  // Log in dev for easy tracking
  console.warn(`[i18n MISSING] Key "${key}" not found for language "${lngs}"`);
});

export default i18n;
```

### 1.3 The "No Fallback" Trick

i18next's default behavior: if `sv` is missing a key, it falls back to `en`. We want to prevent this **only for Swedish** (English should always work since it's the source language).

The cleanest approach: **use a custom `parseMissingKeyHandler`** or set Swedish translations to explicit marker values during development:

**Option A — Custom missing key display (recommended):**

Create a wrapper hook:

```typescript
// resources/js/hooks/useTranslation.ts
import { useTranslation as useI18nTranslation } from 'react-i18next';

export function useTranslation(ns?: string) {
  const { t: originalT, i18n, ready } = useI18nTranslation(ns);

  const t = (key: string, options?: any): string => {
    const currentLang = i18n.language;
    const result = originalT(key, { ...options, lng: currentLang });

    // If we're in Swedish and the result equals the English value,
    // it means i18next fell back. Show the key instead.
    if (currentLang === 'sv' && !i18n.exists(key, { lng: 'sv' })) {
      // In development: show a visible marker
      if (import.meta.env.DEV) {
        return `🔑 ${key}`;
      }
      // In production: show the key wrapped in brackets
      return `[${key}]`;
    }

    return result;
  };

  return { t, i18n, ready };
}
```

This way:
- English always works normally
- Swedish shows `🔑 sidebar.score.label` for any untranslated key in dev
- Swedish shows `[sidebar.score.label]` in production (ugly enough to catch but won't crash)
- You can literally screenshot the app in Swedish mode and see every missing translation at a glance

**Option B — i18next `saveMissing` + fallback disabled:**

```typescript
i18n.init({
  // ...
  fallbackLng: false,  // Disable ALL fallback
  // ...
});
```

Problem: this breaks English too if a key is typo'd. Option A is safer.

### 1.4 Import in App Entry

In `resources/js/app.tsx` (or wherever the React app initializes):

```typescript
import './i18n';  // Initialize before anything else
```

Must be imported before any component that uses translations.

---

## Step 2: Translation File Structure

### 2.1 Directory Layout

```
resources/js/locales/
├── en.json          # Complete — source of truth
└── sv.json          # Partial — only translated keys
```

### 2.2 Key Naming Convention

Use **dot-separated, hierarchical keys** that mirror the UI structure:

```
{component_area}.{section}.{element}
```

Examples:
```
map.controls.zoom_in
map.controls.zoom_out
map.controls.locate_me
map.controls.compare
map.controls.layers
map.legend.title
map.legend.high_risk
map.legend.strong_growth
sidebar.empty.title                    → "Click a DeSO area to view details"
sidebar.empty.subtitle                 → "Explore the map to see neighborhood scores"
sidebar.header.area_label              → "Area"
sidebar.header.municipality            → "Municipality"
sidebar.header.county                  → "County"
sidebar.score.title                    → "Composite Score"
sidebar.score.label.strong_growth      → "Strong Growth Area"
sidebar.score.label.stable             → "Stable / Positive Outlook"
sidebar.score.label.mixed              → "Mixed Signals"
sidebar.score.label.elevated_risk      → "Elevated Risk"
sidebar.score.label.high_risk          → "High Risk / Declining"
sidebar.score.trend_up                 → "up {{value}} points"
sidebar.score.trend_down               → "down {{value}} points"
sidebar.score.trend_flat               → "unchanged"
sidebar.indicators.title               → "Indicator Breakdown"
sidebar.indicators.percentile          → "{{value}}th percentile"
sidebar.indicators.no_data             → "No data"
sidebar.schools.title                  → "Schools in this area"
sidebar.schools.count                  → "{{count}} school" / "{{count}} schools"
sidebar.schools.none.title             → "No schools in this area"
sidebar.schools.none.nearest           → "Nearest: {{name}} ({{distance}} km)"
sidebar.schools.type.grundskola        → "Primary School"
sidebar.schools.type.gymnasieskola     → "Upper Secondary"
sidebar.schools.operator.kommunal      → "Municipal"
sidebar.schools.operator.fristaende    → "Independent"
sidebar.schools.merit_value            → "Merit Value"
sidebar.schools.goal_achievement       → "Goal Achievement"
sidebar.schools.teacher_cert           → "Teacher Certification"
sidebar.schools.students               → "Students"
sidebar.factors.title_positive         → "Strengths"
sidebar.factors.title_negative         → "Weaknesses"
admin.indicators.title                 → "Indicator Management"
admin.indicators.recompute             → "Recompute All Scores"
admin.indicators.recompute_running     → "Computing..."
admin.indicators.recompute_done        → "Scores recomputed for {{count}} DeSOs"
admin.indicators.weight_budget         → "{{percent}}% allocated ({{used}} / {{total}})"
common.loading                         → "Loading..."
common.error                           → "Something went wrong"
common.retry                           → "Try again"
common.close                           → "Close"
common.save                            → "Save"
common.cancel                          → "Cancel"
common.search                          → "Search..."
common.no_results                      → "No results found"
toast.location.outside_sweden          → "You appear to be outside Sweden. This map covers Swedish neighborhoods only."
toast.location.denied                  → "Location access denied. Enable it in browser settings."
toast.location.unavailable             → "Couldn't determine your location. Try again."
toast.location.not_supported           → "Location services not available in your browser."
```

### 2.3 Pluralization

i18next handles plurals with `_one` / `_other` suffixes:

```json
{
  "sidebar.schools.count_one": "{{count}} school",
  "sidebar.schools.count_other": "{{count}} schools"
}
```

Swedish has the same singular/plural pattern, so this works for both languages.

### 2.4 Interpolation

Dynamic values use `{{variable}}` syntax:

```json
{
  "sidebar.score.trend_up": "up {{value}} points",
  "sidebar.schools.none.nearest": "Nearest: {{name}} ({{distance}} km)"
}
```

In components:
```tsx
t('sidebar.score.trend_up', { value: 3.2 })
// → "up 3.2 points"
```

### 2.5 Number Formatting

Don't put number formatting in translation strings. Use `Intl.NumberFormat` separately:

```typescript
// Utility
export function formatNumber(value: number, locale: string): string {
  return new Intl.NumberFormat(locale).format(value);
}

// Swedish: 287 000 SEK (space as thousands separator)
// English: 287,000 SEK (comma as thousands separator)
```

i18next has an `i18next-icu` plugin for ICU MessageFormat which handles this natively, but it's overkill for two languages. Simple utility functions are fine.

---

## Step 3: Backend i18n — Laravel

### 3.1 Laravel's Built-in Localization

Laravel already has localization built in. Translation files go in `lang/`:

```
lang/
├── en/
│   ├── indicators.php    → Indicator names and descriptions
│   ├── scores.php        → Score labels
│   ├── emails.php        → Email templates (future)
│   └── validation.php    → Form validation messages
└── sv/
    ├── indicators.php
    ├── scores.php
    └── validation.php
```

### 3.2 Middleware for Locale Detection

Create `app/Http/Middleware/SetLocale.php`:

```php
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->query('lang')
            ?? $request->cookie('locale')
            ?? $request->getPreferredLanguage(['en', 'sv'])
            ?? 'en';

        if (!in_array($locale, ['en', 'sv'])) {
            $locale = 'en';
        }

        App::setLocale($locale);

        // Share with Inertia so frontend knows the locale
        Inertia::share('locale', $locale);

        return $next($request);
    }
}
```

Register in `bootstrap/app.php` or the HTTP kernel for all web routes.

### 3.3 Inertia Locale Sharing

The middleware shares `locale` via Inertia. On the frontend, sync i18next to match:

```typescript
// In app.tsx or a provider
const { locale } = usePage().props;

useEffect(() => {
  if (locale && i18n.language !== locale) {
    i18n.changeLanguage(locale as string);
  }
}, [locale]);
```

This keeps frontend and backend in sync. The URL determines the locale (via Laravel middleware), Laravel shares it to Inertia, Inertia tells i18next. Single source of truth: the URL.

### 3.4 Backend Translations — Indicator Names

Indicator names and descriptions should be translatable. Since they're stored in the database, we have two options:

**Option A — Translate in the API response (recommended):**

```php
// In the controller or resource
'name' => __("indicators.{$indicator->slug}.name"),
'description_short' => __("indicators.{$indicator->slug}.description_short"),
```

With `lang/en/indicators.php`:
```php
return [
    'median_income' => [
        'name' => 'Median Disposable Income',
        'description_short' => 'Median disposable income per person',
    ],
    'employment_rate' => [
        'name' => 'Employment Rate (16-64)',
        'description_short' => 'Share of working-age residents who are employed',
    ],
    // ...
];
```

**Option B — Store translations in DB (overkill for 2 languages):**

Add `name_en`, `name_sv` columns. Don't do this — it clutters the schema and doesn't scale to more fields.

**Go with Option A.** The indicator slugs are stable identifiers; the human-readable names are presentation-layer concerns that belong in translation files.

### 3.5 No Fallback for Swedish (Backend)

In `config/app.php`:
```php
'locale' => 'en',
'fallback_locale' => 'en',
```

For the same "no silent fallback" behavior on the backend, create a helper:

```php
// app/Helpers/i18n.php
function t_strict(string $key, array $replace = [], ?string $locale = null): string
{
    $locale = $locale ?? App::getLocale();

    if ($locale !== 'en') {
        // Check if translation exists in target locale
        $translated = __($key, $replace, $locale);
        if ($translated === $key) {
            // Translation missing — return visible marker
            return app()->isLocal() ? "🔑 {$key}" : "[{$key}]";
        }
        return $translated;
    }

    return __($key, $replace, 'en');
}
```

Use `t_strict()` in all API responses that face users. Internal/admin can use the normal `__()` helper.

---

## Step 4: Language Switcher

### 4.1 Placement

Add a language switcher in the **navbar**, right side, before any user menu. Simple toggle between EN and SV:

```
┌──────────────────────────────────────────────────────────────┐
│  🏠 Logotype           [Map] [Admin]          EN|SV    [👤] │
└──────────────────────────────────────────────────────────────┘
```

### 4.2 Component

```tsx
function LanguageSwitcher() {
  const { i18n } = useTranslation();
  const currentLang = i18n.language;

  const switchTo = (lang: string) => {
    const currentPath = window.location.pathname;
    let newPath: string;

    if (lang === 'en') {
      newPath = '/en' + (currentPath === '/' ? '' : currentPath);
    } else {
      newPath = currentPath.replace(/^\/en/, '') || '/';
    }

    document.cookie = `locale=${lang};path=/;max-age=31536000`;
    router.visit(newPath);
  };

  return (
    <div className="flex items-center gap-1 text-sm">
      <button
        onClick={() => switchTo('sv')}
        className={cn(
          "px-1.5 py-0.5 rounded",
          currentLang === 'sv' ? 'font-semibold text-foreground' : 'text-muted-foreground hover:text-foreground'
        )}
      >
        SV
      </button>
      <span className="text-muted-foreground">|</span>
      <button
        onClick={() => switchTo('en')}
        className={cn(
          "px-1.5 py-0.5 rounded",
          currentLang === 'en' ? 'font-semibold text-foreground' : 'text-muted-foreground hover:text-foreground'
        )}
      >
        EN
      </button>
    </div>
  );
}
```

Note: SV is listed first since it's the default/primary language.

### 4.3 Why Not a Dropdown

Two languages = toggle. Dropdowns are for 3+. The `SV|EN` pattern is compact and unambiguous.

### 4.4 Navigation, Not Reload

Switching language is a `router.visit()` to a new URL, not a reload of the same page. This means:
- The URL changes (`/` ↔ `/en/`)
- Google can index both versions
- Back button works (go back to Swedish from English)
- Bookmarking works (bookmark the English version, get English next time)

---

## Step 5: URL-Based Locale Routing (SEO)

### 5.1 URL Structure

Swedish is the default — no prefix. English gets `/en/`:

| URL | Language | SEO indexed? |
|---|---|---|
| `/` | Swedish | ✅ Primary — this is what Google sees |
| `/admin/indicators` | Swedish | No (admin) |
| `/en/` | English | ✅ Alternate |
| `/en/admin/indicators` | English | No (admin) |
| `/api/deso/scores` | No locale (JSON) | No |

**Swedish has no prefix** because:
- It's the canonical language (shorter URLs rank better)
- 95% of users are Swedish
- Google Search Console for `.se` domain prioritizes the unprefixed version
- Avoids breaking existing URLs if the site is already live

### 5.2 Laravel Route Groups

```php
// routes/web.php

// API routes — no locale prefix, no locale middleware
Route::prefix('api')->group(function () {
    Route::get('/deso/scores', [DesoController::class, 'scores']);
    Route::get('/deso/geojson', [DesoController::class, 'geojson']);
    // ...
});

// English routes — /en/ prefix
Route::prefix('en')->middleware('set-locale:en')->group(function () {
    Route::get('/', [MapController::class, 'index'])->name('en.map');
    Route::get('/admin/indicators', [AdminIndicatorController::class, 'index'])->name('en.admin.indicators');
    // ... mirror all web routes
});

// Swedish routes — no prefix (default)
Route::middleware('set-locale:sv')->group(function () {
    Route::get('/', [MapController::class, 'index'])->name('map');
    Route::get('/admin/indicators', [AdminIndicatorController::class, 'index'])->name('admin.indicators');
    // ... all web routes
});
```

### 5.3 Locale Middleware (Updated)

```php
class SetLocale
{
    public function handle(Request $request, Closure $next, ?string $forceLocale = null)
    {
        // Priority:
        // 1. Route-forced locale (/en/ prefix forces 'en')
        // 2. Cookie (user's saved preference)
        // 3. Default: 'sv' (Swedish product)

        $locale = $forceLocale
            ?? $request->cookie('locale')
            ?? 'sv';

        if (!in_array($locale, ['en', 'sv'])) {
            $locale = 'sv';
        }

        App::setLocale($locale);
        Inertia::share('locale', $locale);

        return $next($request);
    }
}
```

Note: browser `Accept-Language` detection is deliberately removed from the middleware. The URL is the source of truth. If a user visits `/` they get Swedish. If they visit `/en/` they get English. The language switcher changes the URL prefix + sets a cookie. The cookie is only used as a hint for the root URL `/` — if a user has previously switched to English and visits `/`, the cookie can redirect them to `/en/`. But this redirect is optional and should be a 302, not a 301.

### 5.4 SEO Headers

On every page, set:

```html
<html lang="sv">  <!-- or "en" for /en/ routes -->

<head>
  <link rel="alternate" hreflang="sv" href="https://example.se/" />
  <link rel="alternate" hreflang="en" href="https://example.se/en/" />
  <link rel="alternate" hreflang="x-default" href="https://example.se/" />
</head>
```

`x-default` points to Swedish (the canonical version). This tells Google: "Swedish is the main version, English is the alternative."

### 5.5 Sitemap

Generate separate entries for each language:

```xml
<url>
  <loc>https://example.se/</loc>
  <xhtml:link rel="alternate" hreflang="sv" href="https://example.se/" />
  <xhtml:link rel="alternate" hreflang="en" href="https://example.se/en/" />
</url>
<url>
  <loc>https://example.se/en/</loc>
  <xhtml:link rel="alternate" hreflang="sv" href="https://example.se/" />
  <xhtml:link rel="alternate" hreflang="en" href="https://example.se/en/" />
</url>
```

### 5.6 Language Switcher — URL Switch

When the user clicks EN/SV in the navbar, it should navigate to the equivalent URL in the other language:

```typescript
const switchLanguage = (targetLang: string) => {
  const currentPath = window.location.pathname;

  let newPath: string;
  if (targetLang === 'en') {
    // Swedish → English: add /en/ prefix
    newPath = '/en' + currentPath;
  } else {
    // English → Swedish: remove /en/ prefix
    newPath = currentPath.replace(/^\/en/, '') || '/';
  }

  // Set cookie for preference persistence
  document.cookie = `locale=${targetLang};path=/;max-age=31536000`;

  // Navigate (Inertia visit for SPA feel)
  router.visit(newPath);
};
```

### 5.7 Detection Priority (Updated)

```
1. URL prefix: /en/... → English, everything else → Swedish
2. Cookie: only matters for bare / URL (optional redirect to /en/)
3. Default: Swedish (always)
```

Browser Accept-Language is NOT used. Reasons:
- A Swedish person with an English-language browser (very common among developers, gamers, tech workers) should still get the Swedish product by default
- URL is deterministic and cacheable; browser headers are not
- SEO crawlers don't send meaningful Accept-Language headers

---

## Step 6: String Extraction from Existing Code

### 6.1 The Extraction Pass

Go through every `.tsx` file and replace hardcoded strings with `t()` calls. This is the bulk of the work.

**Before:**
```tsx
<h3 className="text-sm font-semibold">Schools in this area</h3>
<p>No schools are located in this DeSO.</p>
<span>Loading...</span>
```

**After:**
```tsx
<h3 className="text-sm font-semibold">{t('sidebar.schools.title')}</h3>
<p>{t('sidebar.schools.none.title')}</p>
<span>{t('common.loading')}</span>
```

### 6.2 What to Extract

- All visible UI text (labels, headings, descriptions, buttons, placeholders)
- Toast messages
- Error messages
- Tooltip content
- Accessibility labels (`aria-label`)
- `alt` text on images

### 6.3 What NOT to Extract

- Console.log messages (keep in English)
- Code comments
- CSS class names
- API field names / JSON keys
- Internal identifiers
- Admin-only debug text (optional — extract if you want a Swedish admin UI)

### 6.4 Don't Translate Yet

The goal of this task is **infrastructure + extraction**, not translation. After this task:
- `en.json` is complete with all strings
- `sv.json` is empty (or has a few test entries)
- Switching to Swedish shows `🔑 key.name` everywhere — confirming the system works
- A translator can then fill in `sv.json` at their own pace

---

## Step 7: Swedish-Specific Considerations

### 7.1 Number Formatting

| Format | English | Swedish |
|---|---|---|
| Thousands separator | 287,000 | 287 000 |
| Decimal separator | 72.4 | 72,4 |
| Currency | 287,000 SEK | 287 000 kr |
| Percentage | 78.3% | 78,3 % |

Use `Intl.NumberFormat` with the correct locale:
```typescript
new Intl.NumberFormat('sv-SE', { style: 'currency', currency: 'SEK' }).format(287000)
// → "287 000 kr"

new Intl.NumberFormat('en', { style: 'currency', currency: 'SEK' }).format(287000)
// → "SEK 287,000.00"
```

Create a shared `formatCurrency(value, locale)` utility. Every place that formats numbers or currency must use it.

### 7.2 Date Formatting

| Format | English | Swedish |
|---|---|---|
| Full date | February 7, 2026 | 7 februari 2026 |
| Short date | 2/7/2026 | 2026-02-07 |
| Relative | 3 days ago | 3 dagar sedan |

Sweden uses ISO 8601 dates (YYYY-MM-DD). Use `Intl.DateTimeFormat` with locale.

### 7.3 Common Terminology

Some domain terms have standard Swedish translations:

| English | Swedish |
|---|---|
| Composite Score | Sammanvägt betyg |
| Neighborhood | Område / Stadsdel |
| Municipality | Kommun |
| County | Län |
| Primary School | Grundskola |
| Upper Secondary | Gymnasieskola |
| Merit Value | Meritvärde |
| Employment Rate | Sysselsättningsgrad |
| Disposable Income | Disponibel inkomst |
| Independent (school) | Fristående |
| Municipal (school) | Kommunal |
| Teacher Certification | Lärarbehörighet |
| Elevated Risk | Förhöjd risk |
| Strong Growth | Stark tillväxt |

Store these in `sv.json` as the seed translations — they're stable domain terms.

### 7.4 RTL / Layout Concerns

Neither English nor Swedish is RTL, so no layout changes needed. Both use Latin script. Swedish has å, ä, ö which are UTF-8 safe. No special font requirements.

---

## Step 8: Testing the "No Fallback" System

### 8.1 Development Workflow

After this task is complete, the development workflow becomes:

1. Developer adds a new UI element with `t('new.key.here')`
2. Adds the English translation to `en.json`
3. Does NOT add Swedish translation yet
4. Switches to Swedish in the UI → sees `🔑 new.key.here` for the new element
5. All existing Swedish translations still work
6. Missing keys are logged to console: `[i18n MISSING] Key "new.key.here" not found for "sv"`

### 8.2 CI Check (Optional, Future)

Add a script that compares keys between `en.json` and `sv.json`:

```bash
node scripts/check-translations.js
```

Output:
```
Missing in sv.json:
  - sidebar.score.trend_up
  - sidebar.schools.none.nearest
  - toast.location.outside_sweden
  ... (47 more)

Coverage: sv.json has 23/70 keys (33%)
```

This isn't blocking — it's informational. The `🔑` markers in the UI are the real enforcement.

### 8.3 Verification Checklist

- [ ] `npm install` includes i18next dependencies
- [ ] `resources/js/i18n.ts` initializes correctly
- [ ] `en.json` contains all extracted strings (complete source reference)
- [ ] `sv.json` exists (can be mostly empty — keys show as `🔑` markers)
- [ ] `useTranslation()` hook works in components
- [ ] **`/` serves Swedish** — `<html lang="sv">`, Swedish translations used
- [ ] **`/en/` serves English** — `<html lang="en">`, English translations used
- [ ] Language switcher visible in navbar, SV listed first
- [ ] Clicking EN navigates to `/en/...`, clicking SV navigates to `/...`
- [ ] `hreflang` tags present on all pages (sv, en, x-default)
- [ ] `x-default` points to Swedish (the canonical version)
- [ ] Missing Swedish keys show `🔑 key.name` in dev, `[key.name]` in prod
- [ ] Missing keys logged to console
- [ ] Number formatting respects locale (space vs comma for thousands)
- [ ] Date formatting respects locale (YYYY-MM-DD for Swedish)
- [ ] Cookie persists language choice across sessions
- [ ] API routes (`/api/...`) have no locale prefix and work regardless of language
- [ ] Backend locale middleware sets `App::setLocale()` based on route prefix
- [ ] Inertia shares locale to frontend
- [ ] Indicator names served from backend translation files, not DB
- [ ] Admin page works in both languages (`/admin/...` and `/en/admin/...`)

---

## Notes for the Agent

### Extract First, Translate Never (In This Task)

This task is about plumbing. Do NOT spend time translating strings to Swedish. Extract every English string into `en.json`, set up the infrastructure, confirm the `🔑` markers work, and move on. Translation is a separate concern — it could be done by a human translator, a translation service, or a future task.

The only Swedish strings to include are the domain terms from section 7.3 (meritvärde, kommun, etc.) to prove the system works.

### The Custom `useTranslation` Hook Is Key

Don't skip the wrapper hook (Step 1.3, Option A). The native i18next fallback behavior silently serves English when Swedish is missing — this defeats the entire purpose. The wrapper hook checks `i18n.exists(key, { lng: 'sv' })` and shows the key marker if the translation doesn't exist for the current language. This is the single most important piece of the task.

### Don't Namespace Too Deeply

Keep keys to 3 levels max: `section.subsection.element`. Don't do `app.pages.map.sidebar.indicators.breakdown.bar.label` — that's unmaintainable. If a key path feels too deep, flatten it.

### Static Imports, Not HTTP Backend

For two languages with modest translation files (< 500 keys each), static imports (`import en from './locales/en.json'`) are fine. Don't set up `i18next-http-backend` to load translations via HTTP — it adds latency on first render for no benefit at this scale. If we ever add 10+ languages, switch to lazy loading then.

### What NOT to Do

- Don't translate everything to Swedish in this task — extraction only
- Don't use i18next namespaces (overkill for 2 languages, 1 app)
- Don't store translations in the database
- Don't use ICU MessageFormat (overkill — simple interpolation + plural suffixes are enough)
- Don't set `fallbackLng: false` globally — that breaks English if a key is typo'd. Use the wrapper hook instead.
- Don't forget number/date formatting — these are locale-sensitive too, not just strings
- Don't hardcode "SEK" — it might display as "kr" in Swedish locale