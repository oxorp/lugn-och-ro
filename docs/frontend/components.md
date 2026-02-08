# Components

> Reusable component inventory.

## Application Components

| Component | File | Purpose |
|---|---|---|
| `HeatmapMap` | `components/deso-map.tsx` | OpenLayers map with heatmap tiles, pin-drop, and marker layers |
| `MapSearch` | `components/map-search.tsx` | Geocoding search with autocomplete |
| `InfoTooltip` | `components/info-tooltip.tsx` | Score, indicator, and school tooltips |
| `LanguageSwitcher` | `components/language-switcher.tsx` | sv/en locale toggle |
| `LocaleSync` | `components/locale-sync.tsx` | Syncs locale between server and client |

## Map Sub-Components

Defined within `deso-map.tsx`:

| Component | Purpose |
|---|---|
| `ScoreLegend` | Bottom-left gradient bar (red to green) |
| `BasemapControl` | Top-right basemap switcher (Clean / Detailed / Satellite) |

## Explore Module (`pages/explore/`)

The main map page was refactored from a monolithic `map.tsx` into a modular directory:

### Components

| Component | File | Purpose |
|---|---|---|
| `MapPage` | `explore/map-page.tsx` | Root page — map + sidebar layout, pin state management |
| `ActiveSidebar` | `explore/components/active-sidebar.tsx` | Full location detail (score, proximity, indicators, schools, POIs) |
| `DefaultSidebar` | `explore/components/default-sidebar.tsx` | Welcome state with search prompt |
| `ScoreCard` | `explore/components/score-card.tsx` | Large composite score badge with trend and breakdown |
| `IndicatorBar` | `explore/components/indicator-bar.tsx` | Individual indicator percentile bar with raw value |
| `ProximityFactorRow` | `explore/components/proximity-factor-row.tsx` | Individual proximity factor bar with icon and details |
| `LockedPreviewContent` | `explore/components/locked-preview.tsx` | Free tier teaser with sample indicators and CTA |
| `CTASummary` | `explore/components/locked-preview.tsx` | Data point count summary + unlock button |
| `StickyUnlockBar` | `explore/components/locked-preview.tsx` | Sticky bottom bar that appears on scroll |

### Hooks

| Hook | File | Purpose |
|---|---|---|
| `useLocationData` | `explore/hooks/use-location-data.ts` | Pin state, API fetch, reverse geocoding, map sync |
| `useScoreLabel` | `explore/hooks/use-score-label.ts` | Map score → Swedish label |
| `useUrlPin` | `explore/hooks/use-url-pin.ts` | Restore pin from `/explore/{lat},{lng}` URL on mount |

### Supporting Files

| File | Purpose |
|---|---|
| `explore/types.ts` | TypeScript interfaces: LocationData, PreviewData, ProximityData |
| `explore/constants.ts` | Proximity factor config (icons, i18n keys, detail field names) |
| `explore/utils.ts` | formatIndicatorValue, formatDistance, scoreBgStyle |

## Shared Scoring Components

| Component | File | Purpose |
|---|---|---|
| `PercentileBadge` | `components/percentile-badge.tsx` | Colored score badge with direction-aware tooltip |
| `PercentileBar` | `components/percentile-bar.tsx` | Horizontal bar with score-colored fill |

## Layout Components

| Component | File | Purpose |
|---|---|---|
| `AdminLayout` | `layouts/admin-layout.tsx` | Admin pages with tab navigation (Indicators, Pipeline, Data Quality) |
| `MapLayout` | `layouts/map-layout.tsx` | Full-screen map layout |
| `AppShell` | `components/app-shell.tsx` | Main app layout with sidebar |
| `AppSidebar` | `components/app-sidebar.tsx` | Navigation sidebar |
| `AppHeader` | `components/app-header.tsx` | Top header bar |
| `AppContent` | `components/app-content.tsx` | Content area wrapper |
| `AppLogo` | `components/app-logo.tsx` | Brand logo |
| `NavMain` | `components/nav-main.tsx` | Primary navigation links |
| `NavUser` | `components/nav-user.tsx` | User menu in sidebar |
| `NavFooter` | `components/nav-footer.tsx` | Sidebar footer |
| `Breadcrumbs` | `components/breadcrumbs.tsx` | Page breadcrumb navigation |

## shadcn/ui Primitives

Located in `components/ui/`. Standard shadcn/ui components used throughout:

`Accordion`, `Alert`, `Avatar`, `Badge`, `Breadcrumb`, `Button`, `Card`, `Checkbox`, `Collapsible`, `Dialog`, `DropdownMenu`, `Icon`, `Input`, `InputOTP`, `Label`, `NavigationMenu`, `Popover`, `ScrollArea`, `Select`, `Separator`, `Sheet`, `Sidebar`, `Skeleton`, `Sonner` (toasts), `Spinner`, `Switch`, `Table`, `Toggle`, `ToggleGroup`, `Tooltip`.

## Custom Hooks

| Hook | File | Purpose |
|---|---|---|
| `useTranslation` | `hooks/use-translation.ts` | i18n with Swedish/English support |
| `usePoiLayer` | `hooks/use-poi-layer.ts` | POI data fetching and map layer management |
| `useScoreColors` | `hooks/use-score-colors.ts` | Access to score color utilities |
| `useLocationData` | `pages/explore/hooks/use-location-data.ts` | Pin state, API fetch, reverse geocoding |
| `useScoreLabel` | `pages/explore/hooks/use-score-label.ts` | Map score → Swedish label |
| `useUrlPin` | `pages/explore/hooks/use-url-pin.ts` | Restore pin from URL on mount |

## Utilities

| File | Purpose |
|---|---|
| `lib/score-colors.ts` | Score → color interpolation, merit colors, indicator bar colors, CSS gradients |
| `lib/poi-icons.ts` | Generates SVG data URLs for POI marker pins |
| `icons.ts` | Centralized icon imports and mapping |
| `services/geocoding.ts` | Address search client with zoom-level mapping per result type |

## Pages

| Page | Route | Purpose |
|---|---|---|
| `explore/map-page.tsx` | `/`, `/explore/{lat},{lng}` | Main map interface with pin-drop scoring |
| `purchase/flow.tsx` | `/purchase/{lat},{lng}` | Stripe checkout flow for report purchase |
| `purchase/processing.tsx` | `/purchase/success` | Payment processing/polling page |
| `reports/show.tsx` | `/reports/{uuid}` | View completed report |
| `reports/my-reports.tsx` | `/my-reports` | List of purchased reports |
| `reports/request-access.tsx` | `/my-reports` (no auth) | Email access request for guest reports |
| `auth/register.tsx` | `/register` | Registration page |
| `auth/login.tsx` | `/login` | Login page |
| `methodology.tsx` | `/methodology` | Public methodology explanation |
| `admin/indicators.tsx` | `/admin/indicators` | Indicator management |
| `admin/poi-categories.tsx` | `/admin/poi-categories` | POI category & safety sensitivity management |
| `admin/data-quality.tsx` | `/admin/data-quality` | Data quality dashboard |
| `admin/pipeline.tsx` | `/admin/pipeline` | Pipeline overview |
| `admin/pipeline-source.tsx` | `/admin/pipeline/{source}` | Source detail |
| `settings/profile.tsx` | `/settings/profile` | Profile settings |
| `settings/password.tsx` | `/settings/password` | Password change |
| `settings/appearance.tsx` | `/settings/appearance` | Theme settings |
| `settings/two-factor.tsx` | `/settings/two-factor` | 2FA setup |

## Related

- [Architecture Stack](/architecture/stack)
- [Frontend Overview](/frontend/)
