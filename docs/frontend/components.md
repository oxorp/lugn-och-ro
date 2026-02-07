# Components

> Reusable component inventory.

## Application Components

| Component | File | Purpose |
|---|---|---|
| `DesoMap` | `components/deso-map.tsx` | OpenLayers map with DeSO/H3 layers |
| `ComparisonSidebar` | `components/comparison-sidebar.tsx` | Side-by-side area comparison |
| `PoiControls` | `components/poi-controls.tsx` | POI category toggle panel |
| `MapSearch` | `components/map-search.tsx` | Geocoding search with autocomplete |
| `InfoTooltip` | `components/info-tooltip.tsx` | Score, indicator, and school tooltips |
| `LanguageSwitcher` | `components/language-switcher.tsx` | sv/en locale toggle |
| `LocaleSync` | `components/locale-sync.tsx` | Syncs locale between server and client |

## Layout Components

| Component | File | Purpose |
|---|---|---|
| `AdminLayout` | `layouts/admin-layout.tsx` | Admin pages with tab navigation (Indicators, Pipeline, Data Quality) |
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

## Pages

| Page | Route | Purpose |
|---|---|---|
| `map.tsx` | `/map` | Main map interface |
| `dashboard.tsx` | `/dashboard` | User dashboard |
| `welcome.tsx` | `/` | Landing page |
| `methodology.tsx` | `/methodology` | Public methodology explanation |
| `admin/indicators.tsx` | `/admin/indicators` | Indicator management |
| `admin/data-quality.tsx` | `/admin/data-quality` | Data quality dashboard |
| `admin/pipeline.tsx` | `/admin/pipeline` | Pipeline overview |
| `admin/pipeline-source.tsx` | `/admin/pipeline/{source}` | Source detail |
| `auth/login.tsx` | `/login` | Login page |
| `settings/profile.tsx` | `/settings/profile` | Profile settings |
| `settings/password.tsx` | `/settings/password` | Password change |
| `settings/appearance.tsx` | `/settings/appearance` | Theme settings |
| `settings/two-factor.tsx` | `/settings/two-factor` | 2FA setup |

## Related

- [Architecture Stack](/architecture/stack)
- [Frontend Overview](/frontend/)
