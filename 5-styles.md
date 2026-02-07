# TASK: Design System — Typography, Colors & Visual Foundation

## Context

The platform has functional UI built with shadcn/ui defaults. It works, but it doesn't have a cohesive visual identity. The map uses a purple→green score gradient, the sidebar shows data, and everything else is default shadcn gray. Before adding more features and pages (methodology, admin dashboard, reports), we need a design foundation that:

1. Lets the data be the star — the map colors and score numbers should be the most visually prominent things on screen
2. Feels trustworthy and professional — banks and mäklare are target customers
3. Is information-dense without feeling cluttered — the sidebar packs a lot into 400px
4. Is dark-mode-ready in architecture even though v1 ships light-only

**This is not a redesign.** It's establishing the color tokens, typography, and component conventions so that everything built from here forward is consistent. Existing components should be updated to use the new tokens, but layout and functionality don't change.

## Goals

1. Define and implement color tokens (CSS custom properties via Tailwind)
2. Set up typography (Inter, with tabular numbers for data)
3. Establish component-level styling conventions for the sidebar, cards, indicators, and badges
4. Ensure dark mode can be added later with minimal effort
5. Apply the system to all existing pages (map, sidebar, admin)

---

## Step 1: Color System

### 1.1 Design Principles

**The data is the color, the UI is the frame.**

The score gradient (purple → yellow → green) is the most important visual element. It appears on the map, in the score number, and in indicator bars. Everything else — navbar, sidebar, text, borders — should be neutral enough that the data colors pop without competition.

**Brand color is blue, used sparingly.**

Blue is the only primary color absent from the score gradient. It reads as "interface" on a map (users are trained by Google Maps, Apple Maps). Use it only for interactive states: selected items, focused inputs, primary CTAs. Never for decoration.

### 1.2 Color Tokens

Define these as CSS custom properties in the root, following shadcn's HSL convention. These extend/override shadcn's default theme.

**Neutral palette (slate-based):**

```css
:root {
  /* Base neutrals — slate family */
  --background: 0 0% 100%;           /* White — page/sidebar background */
  --foreground: 215 20% 27%;         /* slate-800 — primary text */
  --muted: 215 16% 95%;              /* slate-100 — subtle backgrounds */
  --muted-foreground: 215 13% 44%;   /* slate-500 — secondary text, labels */
  --border: 215 16% 90%;             /* slate-200 — dividers, card borders */
  --input: 215 16% 90%;              /* slate-200 — input borders */
  --ring: 217 91% 60%;               /* blue-500 — focus rings */

  /* Card surfaces */
  --card: 0 0% 100%;                 /* White */
  --card-foreground: 215 20% 27%;    /* slate-800 */

  /* Popover/tooltip */
  --popover: 0 0% 100%;
  --popover-foreground: 215 20% 27%;
}
```

**Brand accent (blue):**

```css
:root {
  --primary: 217 91% 60%;            /* #3b82f6 — blue-500 */
  --primary-foreground: 0 0% 100%;   /* White text on blue */
  --accent: 215 16% 95%;             /* slate-100 — hover backgrounds */
  --accent-foreground: 215 20% 27%;  /* slate-800 */
}
```

**Semantic colors:**

```css
:root {
  /* Status */
  --destructive: 0 72% 51%;          /* red-600 — errors, destructive actions */
  --destructive-foreground: 0 0% 100%;

  /* Trend indicators */
  --trend-positive: 142 71% 35%;     /* A clear green — not the score green, slightly different */
  --trend-negative: 347 77% 50%;     /* rose-500 — muted red, not alarming */
  --trend-stable: 215 13% 64%;       /* slate-400 — quiet gray */
  --trend-none: 215 13% 78%;         /* slate-300 — very subtle */
}
```

**Score gradient (the most important colors in the system):**

```css
:root {
  --score-0: 280 100% 22%;           /* #4a0072 — Deep purple */
  --score-25: 330 70% 36%;           /* #9c1d6e — Red-purple */
  --score-50: 45 85% 60%;            /* #f0c040 — Warm yellow */
  --score-75: 100 50% 52%;           /* #6abf4b — Light green */
  --score-100: 140 65% 29%;          /* #1a7a2e — Deep green */
}
```

These score colors are used on the map, in the sidebar score number, in indicator bars, and in the legend. They must be identical everywhere — define once, reference everywhere.

### 1.3 Dark Mode Tokens (Architecture Only — Not Shipped in v1)

Define the dark mode overrides but keep them commented out or behind a feature flag. When dark mode is activated later, these swap in:

```css
.dark {
  --background: 222 20% 10%;         /* Dark slate background */
  --foreground: 210 16% 93%;         /* Light text */
  --muted: 217 19% 17%;              /* Slightly lighter dark */
  --muted-foreground: 215 13% 64%;   /* slate-400 */
  --border: 217 19% 22%;             /* Subtle dark borders */
  --card: 222 20% 13%;               /* Slightly lighter than background */
  --card-foreground: 210 16% 93%;

  /* Score gradient stays the same — it's data, not chrome */
  /* But may need brightness boost for dark backgrounds — test when implementing */
}
```

**Key rule:** The score gradient should NOT change between light and dark mode. The colors represent data, and changing them per-mode would confuse users who switch. If anything, slightly increase saturation on dark to compensate for reduced perceived brightness.

### 1.4 Implementation

Update `resources/css/app.css` (or wherever the Tailwind theme is configured) with the CSS custom properties. Shadcn already uses this pattern — just replace the default values.

Ensure every component references tokens (`text-foreground`, `bg-muted`, `border-border`) instead of hardcoded Tailwind colors (`text-slate-700`, `bg-slate-100`). This is what makes dark mode a toggle later rather than a find-and-replace.

---

## Step 2: Typography

### 2.1 Font Selection: Inter

**Inter** is the primary (and only) typeface. It was designed specifically for computer interfaces, with:
- Excellent legibility at 12-14px (our sidebar data size)
- Tabular number support via OpenType features
- Tall x-height that works in dense layouts
- Available on Google Fonts (free, CDN-served) or self-hosted

**No secondary font.** One font reduces load time, eliminates pairing issues, and is visually cleaner. If you want hierarchy, use weight and size — not a different font.

### 2.2 Installation

Add Inter via Google Fonts or self-host:

```html
<!-- In app layout head -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
```

Or self-host (better performance, no external dependency):

```bash
npm install @fontsource/inter
```

```ts
// In app.tsx or main entry
import '@fontsource/inter/400.css';
import '@fontsource/inter/500.css';
import '@fontsource/inter/600.css';
import '@fontsource/inter/700.css';
```

### 2.3 Tailwind Configuration

```js
// tailwind.config.ts
export default {
  theme: {
    fontFamily: {
      sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
    },
  },
}
```

### 2.4 Type Scale

Keep it tight. A data-dense sidebar doesn't need 8 font sizes.

| Role | Size | Weight | Tailwind class | Usage |
|---|---|---|---|---|
| Page title | 24px / 1.5rem | 700 (bold) | `text-2xl font-bold` | Methodology page hero, admin page titles |
| Section heading | 16px / 1rem | 600 (semibold) | `text-base font-semibold` | Sidebar section headers ("Schools in this area") |
| Body text | 14px / 0.875rem | 400 (regular) | `text-sm` | Most sidebar content, descriptions |
| Data label | 12px / 0.75rem | 500 (medium) | `text-xs font-medium` | "Median Income", "Employment Rate", category labels |
| Data value | 14px / 0.875rem | 600 (semibold) | `text-sm font-semibold` | "287,000 SEK", "72.3%", score numbers |
| Small / caption | 11px / 0.6875rem | 400 (regular) | `text-[11px]` | "Updated annually", "Source: SCB", timestamps |
| Score display | 36px / 2.25rem | 700 (bold) | `text-4xl font-bold` | The big score number in sidebar header |

### 2.5 Tabular Numbers

Critical for data alignment. When showing indicator values in a column, numbers must align vertically.

```css
/* Apply globally to data contexts */
.tabular-nums {
  font-variant-numeric: tabular-nums;
  font-feature-settings: "tnum";
}
```

Or use Tailwind's built-in `tabular-nums` class on any element displaying numeric data.

**Apply to:** Score numbers, indicator values (287,000 SEK), percentages (72.3%), percentile ranks (78th), school meritvärde (241), student counts.

### 2.6 Line Height

| Context | Line height | Tailwind |
|---|---|---|
| Headings | 1.2 | `leading-tight` |
| Sidebar body | 1.5 | `leading-normal` |
| Methodology page prose | 1.7 | `leading-relaxed` |
| Data labels (single line) | 1.0 | `leading-none` |

---

## Step 3: Navbar

### 3.1 Specifications

```
┌──────────────────────────────────────────────────────────────┐
│  [Logo]   Map   Methodology            [Admin ▾]            │
└──────────────────────────────────────────────────────────────┘
```

- **Height:** 48px. Not 64, not 56. The map needs every pixel.
- **Background:** `bg-background` (white) with a 1px `border-b border-border` bottom border. No shadow (shadows compete with the map).
- **Logo:** Product name in `text-base font-semibold text-foreground`. No icon/symbol for now. Just the name as wordmark.
- **Nav links:** `text-sm font-medium text-muted-foreground`. Active link: `text-foreground`. Hover: `text-foreground`. No underlines, no colored highlights. The active state is just darker text.
- **Admin dropdown:** Same styling. Subtle chevron icon. Dropdown uses shadcn `DropdownMenu`.
- **Spacing:** `px-4` horizontal padding. `gap-6` between nav links.
- **Mobile:** Hamburger menu on < 768px. Standard shadcn `Sheet` sliding from left.

### 3.2 No Colored Navbar

Do not add a blue/colored top bar. The navbar is infrastructure, not branding. A colored navbar fights the map. White + thin border is correct.

---

## Step 4: Sidebar Styling

### 4.1 Overall

- **Background:** `bg-background` (white). Clean.
- **Width:** 400px on desktop (already specified in previous task).
- **Padding:** `p-5` (20px). Consistent on all sides.
- **Section spacing:** `space-y-6` between major sections (header, score, indicators, schools).
- **Scroll:** `overflow-y-auto` with shadcn `ScrollArea` for styled scrollbar. Scrollbar should be thin and subtle (`w-1.5`).

### 4.2 DeSO Header Section

```
Danderyd 0162C1010
Danderyds kommun · Stockholms län
Area: 1.2 km²
```

- DeSO name (if available) in `text-lg font-semibold text-foreground`
- DeSO code in `text-xs font-medium text-muted-foreground` next to the name (not on its own line)
- Kommun + län in `text-sm text-muted-foreground`
- Area in `text-xs text-muted-foreground`
- Separator: `border-b border-border` after the header

### 4.3 Score Section

```
        72
  Stable / Positive Outlook
```

- Score number: `text-4xl font-bold tabular-nums` in the **score gradient color** matching its value. This is the single most important visual connection between the sidebar and the map. A score of 72 should be the exact same green as the 72-colored polygon on the map.
- Score label: `text-sm font-medium text-muted-foreground`
- The score section should have slight vertical padding (`py-4`) and a bottom border

### 4.4 Indicator Bars

```
Median Income          ████████░░  78th   287,000 SEK   ↑ +8.2%
```

This is the densest UI element. Every pixel matters.

- **Layout:** Single row per indicator. Name on the left, bar in the middle, values on the right.
- **Name:** `text-xs font-medium text-muted-foreground uppercase tracking-wide`. All-caps labels are more scannable at small sizes.
- **Bar:** Height 6px. Rounded (`rounded-full`). Fill color = score gradient color at the percentile position. Unfilled = `bg-muted` (slate-100). Width ~100px.
- **Percentile:** `text-xs font-semibold tabular-nums text-foreground`. Show as "78th" not "0.78" or "78%".
- **Raw value:** `text-xs tabular-nums text-muted-foreground`. In parentheses or after a separator. "287,000 SEK" or "72.3%".
- **Trend arrow:** Small inline arrow icon (12px). Green `text-trend-positive` for improving, rose `text-trend-negative` for worsening, gray `text-trend-stable` for stable. Percentage in the same color, `text-xs tabular-nums`.
- **No trend:** A muted dash "—" in `text-trend-none`.

**Visual rhythm:** Each indicator row should be ~32-36px tall. With 8 indicators, the whole section is ~280px — scrollable but usually visible without scrolling on desktop.

### 4.5 School Cards

Already specified in the schools task. Apply these tokens:
- Card: `bg-background border border-border rounded-lg p-3`
- School name: `text-sm font-semibold text-foreground`
- Type/operator: `text-xs text-muted-foreground`
- Stats bars: Same as indicator bars but smaller (4px height)

### 4.6 Badges

For strengths/weaknesses and category labels:

- **Strength badge:** `bg-emerald-50 text-emerald-700 border border-emerald-200` (light green chip)
- **Weakness badge:** `bg-purple-50 text-purple-700 border border-purple-200` (light purple chip — matches the low-score map color)
- **Neutral badge:** `bg-muted text-muted-foreground border border-border`
- **All badges:** `text-xs font-medium px-2 py-0.5 rounded-full`

---

## Step 5: Map Chrome

### 5.1 Legend

The score legend overlay on the map:

- Position: bottom-left of the map area, `absolute` positioned
- Background: `bg-background/90 backdrop-blur-sm` (white with slight transparency so map peeks through)
- Border: `border border-border rounded-lg`
- Padding: `px-3 py-2`
- Gradient bar: 200px wide, 8px tall, using the score gradient
- Labels: `text-[11px] text-muted-foreground` at each end: "High Risk" and "Strong Growth"
- **Minimal.** No title, no extra decoration. Just the bar and two labels.

### 5.2 Layer Controls (When H3 Is Implemented)

- Position: top-right of the map area
- Same card styling: `bg-background/90 backdrop-blur-sm border border-border rounded-lg`
- Radio buttons and toggles use shadcn components
- `text-xs` for labels

### 5.3 Map Background

The default map background (behind the DeSO polygons / H3 hexes) should be:
- Light mode: `#f1f5f9` (slate-100) — a very light gray that distinguishes "no data" areas from the white sidebar
- Water bodies (if rendered): `#e2e8f0` (slate-200) or a very subtle blue-gray

This ensures that the colored score polygons are the dominant visual element.

---

## Step 6: Admin Pages

### 6.1 General Admin Styling

Admin pages are internal tools — functional over beautiful. But they should still use the same tokens for consistency.

- **Page background:** `bg-muted` (slate-100) — slightly gray to distinguish from the main app's white
- **Content cards:** `bg-background` (white) cards on the gray background
- **Tables:** Shadcn `Table` with default styling. Headers in `text-xs font-medium text-muted-foreground uppercase tracking-wide`. Rows in `text-sm`.
- **Inputs:** Standard shadcn. No customization needed.

### 6.2 Admin Navbar Distinction

Subtle visual cue that you're in admin:
- Add a small `text-xs font-medium text-muted-foreground bg-muted px-2 py-0.5 rounded` badge next to the admin link: "Admin"
- Or use a slightly different background tint on admin pages

---

## Step 7: Data Freshness Indicators

### 7.1 Status Dots

Used in the sidebar (data sources section) and admin dashboard:

| Status | Dot | Text color |
|---|---|---|
| Current | `bg-emerald-500` (green) | `text-foreground` |
| Stale | `bg-amber-500` (yellow) | `text-foreground` |
| Outdated | `bg-red-500` (red) | `text-foreground` |
| Unknown | `bg-muted-foreground` (gray) | `text-muted-foreground` |

Dots are 8px circles, inline with the text label. `w-2 h-2 rounded-full`.

---

## Step 8: Responsive Behavior

### 8.1 Breakpoints

| Breakpoint | Layout |
|---|---|
| ≥ 1024px (lg) | Map + sidebar (400px) side by side |
| 768–1023px (md) | Map + sidebar (320px) side by side, slightly narrower |
| < 768px (sm) | Map takes full width, sidebar becomes bottom sheet (40% height) |

### 8.2 Mobile Bottom Sheet

On mobile, the sidebar becomes a draggable bottom sheet:
- Default state: shows score header + "drag up for details"
- Expanded: scrollable, shows all sections
- Map visible above (60% of viewport)
- Use shadcn `Drawer` (Vaul) for the mobile sheet

### 8.3 Typography Doesn't Change

Don't scale fonts down on mobile. 14px body text and 12px labels are already mobile-friendly. Reducing them further hurts readability. The mobile sidebar is narrower (full-width minus padding = ~340px) but the same type scale works.

---

## Step 9: Methodology Page Styling

### 9.1 Reading Column

The methodology page is prose, not data. Different styling:

- **Max width:** 680px centered. `max-w-2xl mx-auto`.
- **Paragraph text:** `text-base leading-relaxed text-foreground`. Slightly larger and more relaxed than sidebar text.
- **Headings:** `text-xl font-bold text-foreground` for section headings, `text-lg font-semibold` for subsections.
- **Data source cards:** `bg-background border border-border rounded-lg p-5`. Source name in `text-xs font-medium text-muted-foreground`. Frequency badge: `bg-muted text-muted-foreground text-xs px-2 py-0.5 rounded-full`.
- **Score range table:** Left border in the score gradient color for each row. `border-l-4`. Cell padding `py-3`.
- **FAQ accordion:** Shadcn default. Trigger in `text-base font-medium`. Content in `text-sm text-muted-foreground leading-relaxed`.

---

## Step 10: Dark Mode Architecture (Prep Only)

### 10.1 What to Do Now

1. **All colors via CSS custom properties.** Never hardcode `text-slate-700` — use `text-foreground`. This is the single most important dark mode prep.
2. **All backgrounds via tokens.** `bg-background`, `bg-card`, `bg-muted`. Never `bg-white` or `bg-slate-100`.
3. **Score gradient colors in variables.** So they can be adjusted for dark backgrounds if needed.
4. **Add `dark` class support in Tailwind config:**

```js
// tailwind.config.ts
export default {
  darkMode: 'class',  // Enables .dark class on <html>
  // ...
}
```

5. **Comment the dark mode token overrides** in the CSS file with a note: "Uncomment and tune when dark mode is implemented."

### 10.2 What NOT to Do Now

- Don't implement a dark mode toggle in the UI
- Don't test components in dark mode
- Don't create dark mode variants of the score gradient
- Don't add dark basemap tiles for the map

### 10.3 When to Implement Dark Mode

After launch, when you have user feedback. It's a half-day task if the token architecture is correct:
1. Uncomment dark token overrides
2. Add a toggle button (system preference / manual)
3. Test score gradient legibility on dark background (may need saturation bump)
4. Add dark map background color
5. Test every component

---

## Step 11: Implementation Approach

### 11.1 Order of Operations

1. **Set up color tokens and typography** — update CSS variables, install Inter, configure Tailwind
2. **Update the navbar** — height, colors, spacing
3. **Update the sidebar** — all sections (header, score, indicators, schools)
4. **Update the map chrome** — legend, layer controls, background
5. **Update admin pages** — tables, cards, forms
6. **Update methodology page** — typography, cards, accordion
7. **Audit for hardcoded colors** — search codebase for raw Tailwind colors (`text-gray-*`, `bg-slate-*`, etc.) and replace with token references

### 11.2 Verification Checklist

- [ ] Inter font loads correctly (check network tab — no FOUT/FOIT)
- [ ] Tabular numbers align in indicator value columns
- [ ] Score number color matches the corresponding map polygon color for the same score value
- [ ] Navbar is 48px, white background, thin border — no shadow, no color
- [ ] Sidebar is clean white, readable at all scroll positions
- [ ] Indicator bars use the score gradient fill
- [ ] Trend arrows are color-coded (green/rose/gray)
- [ ] Badges use the correct light-chip style
- [ ] Admin pages have subtle gray background distinction
- [ ] Legend is semi-transparent, positioned bottom-left, minimal
- [ ] Methodology page has comfortable reading width (680px max)
- [ ] Mobile: sidebar becomes bottom sheet at < 768px
- [ ] No hardcoded color values in component files (all via tokens)
- [ ] `darkMode: 'class'` is configured in Tailwind (even though dark mode isn't active)
- [ ] Dark mode token overrides are in the CSS file (commented out)

---

## Notes for the Agent

### Don't Over-Design

This is a data product, not a brand showcase. If you're debating between "clean and simple" and "visually interesting," always pick clean and simple. The map IS the visual interest.

### The Score Color Connection Is Critical

When a user looks at a green polygon on the map and then sees the score "72" in the sidebar, that number MUST be the same green. Compute the score color with the same interpolation function in both places. If they're even slightly different, it feels broken.

### shadcn Is Your Friend

Don't build custom components where shadcn has one. Button, Card, Table, Input, Select, Switch, Badge, Accordion, DropdownMenu, ScrollArea, Drawer — all of these exist in shadcn and should be used. The design system is about configuring shadcn's tokens, not replacing its components.

### Don't Add a Logo Yet

The product doesn't have a name yet. Use a text wordmark placeholder ("[Platform]" in `font-semibold`). A logo/icon is a branding task, not a design system task.

### What NOT to Do

- Don't use more than one font
- Don't use shadows on the navbar or sidebar (they compete with the map)
- Don't make the navbar taller than 48px
- Don't use red for the brand color (conflicts with score gradient)
- Don't ship dark mode in v1
- Don't use colored backgrounds for the sidebar
- Don't use serif fonts anywhere
- Don't add animations or transitions beyond what shadcn provides by default

### What to Prioritize

1. Color tokens (CSS variables) — unblocks everything else
2. Inter font setup — quick win, immediate visual improvement
3. Sidebar indicator bars — the most data-dense, most-seen component
4. Score color matching (sidebar ↔ map)
5. Navbar cleanup
6. Everything else