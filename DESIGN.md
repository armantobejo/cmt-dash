---
# gstack: design-md-format=spec
name: CMT Dashboard
description: Dense, dark enterprise operations console for freight data — restrained, tabular, finance-grade legibility.
colors:
  primary: "#5856d6"
  accent: "#6a5fc1"
  on-primary: "#ffffff"
  surface: "#212631"
  surface-raised: "#323a49"
  surface-subtle: "#2a303d"
  text: "rgba(255, 255, 255, 0.87)"
  text-muted: "rgba(255, 255, 255, 0.6)"
  text-faint: "rgba(255, 255, 255, 0.38)"
  border: "#323a49"
  border-subtle: "rgba(255, 255, 255, 0.1)"
  success: "#1b9e3e"
  info: "#3399ff"
  warning: "#f9b115"
  danger: "#e55353"
  shipment-import: "#6a5fc1"
  shipment-export: "#db6d28"
  shipment-domestic: "#2ba86f"
  shipment-trucking: "#8aab2f"
  shipment-breakbulk: "#d4567a"
typography:
  display:
    fontFamily: IBM Plex Sans
    fontWeight: 500
    fontSize: 1.75rem
    letterSpacing: 0
  body:
    fontFamily: IBM Plex Sans
    fontSize: 1rem
    lineHeight: 1.5
  label:
    fontFamily: IBM Plex Sans
    fontWeight: 600
    fontSize: 0.75rem
    letterSpacing: 0.04em
  mono:
    fontFamily: SFMono-Regular, Menlo, Consolas, monospace
    fontFeature: tnum
rounded:
  sm: 4px
  md: 6px
  lg: 8px
  full: 50rem
spacing:
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  2xl: 48px
components:
  table:
    container: ".table-responsive inside .card"
    rowDensity: compact
    headerTreatment: inherit-theme
  stat-card:
    backgroundColor: "{colors.surface}"
    borderColor: "{colors.border}"
    rounded: "{rounded.md}"
  badge-shipment:
    backgroundColor: "{colors.shipment-import}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.sm}"
  money-cell:
    fontFeature: tnum
    align: right
    whiteSpace: nowrap
  nav-group-label:
    textColor: "{colors.text-muted}"
    textTransform: uppercase
---

# CMT Dashboard

## Overview

**Creative North Star:** A quiet, dense ledger — the interface disappears and the numbers stay sharp.
**Product context:** Internal read-only dashboard over legacy FoxPro cargo/accounting databases; five company DBs, Indonesian/English/Chinese users, finance and operations staff who live in tables all day.
**Mode per surface:** Operate everywhere — login, dashboards, list pages, drill-downs, and admin are all task surfaces. There is no marketing surface.
**Key characteristics:** Dark-first enterprise palette; tabular figures everywhere money appears; module-colored badges as the only chroma; table cards as the dominant layout unit; almost no motion.

## Colors

**Strategy:** Restrained — one purple family carries brand and interaction; five shipment-kind hues are the only decorative color, and they encode data, not mood.
**Light or dark:** Dark is the default because the tool is used all day on office desktops next to green-terminal exports from the legacy system; light exists for projector/print moments and must never invert surfaces blindly — the light theme re-derives badge colors to keep contrast, it does not reuse dark values.
Named rules: `primary` carries links, active nav, and focus rings. Shipment-kind colors (`shipment-*`) appear **only** on badges and the trend chart series — never as text or backgrounds elsewhere. Semantic `success/info/warning/danger` are reserved for state, and badge text on tinted backgrounds always uses the `-subtle` background + matching text pair so both themes pass contrast. Muted text derives from white at 60% opacity in dark; never pick arbitrary grays.

## Typography

IBM Plex Sans was chosen over the system stack because a finance tool needs unambiguous zeros, tight tabular alignment, and a slightly mechanical voice that matches the FoxPro heritage. Loaded from Google Fonts at weights 400/500/600 with `display=swap`.
Scale: page titles are `h3`/`h4`; card titles `h5`/`h6` or `fw-semibold` spans; body 1rem; table meta and sidebar sub-items at 0.875rem; uppercase group labels at 0.75rem with wide tracking. Reference numbers, account codes, and DB stems use the mono stack. **Every right-aligned number column sets `font-variant-numeric: tabular-nums`** (done globally for `.table .text-end`) — never remove it. Stat values may pull tracking slightly negative; labels never get negative tracking.

## Layout

CoreUI fixed sidebar (256px, collapsible to icons) + sticky header + fluid content. Content uses the Bootstrap grid with `row g-3` rhythm; list pages are a single card; detail pages stack an info card above the table card. Breakpoints are CoreUI's (576/768/992/1200/1400); toolbars wrap with `flex-wrap gap-2` rather than hiding controls. Long values get `text-break`/`text-truncate` with a `title` tooltip; **dates and money get `text-nowrap`** so values never split mid-string. Tables scroll horizontally inside `.table-responsive` — the page itself must never scroll horizontally.

## Elevation & Depth

Depth comes from borders, not shadows: cards are surface + 1px border + radius; hovering a nav item or row shifts background tint (≈6% white in dark), never lifts. No drop shadows on cards, no zero-offset glows. The only "raised" surface is dropdown/flyout UI from CoreUI itself.

## Shapes

Radius hierarchy: form controls and badges `sm`; cards, stat-cards, inputs `md`; pills and the company selector chip `full`. Sidebar accents (header bar, active indicator) are square — rectangles, not rounded chips. Nested elements keep inner radius = outer − padding.

## Components

- **Table card** (the core pattern): `.card` → `.card-header` with title left, filter form right → `.table-responsive` → `.table table-striped table-hover table-sm align-middle mb-0` → optional `.card-footer` with page count + pagination. Sortable headers are links with a `bi-chevron-up/down` indicator; never hand-rolled sort UI.
- **Money cells:** right-aligned, `text-nowrap`, tabular figures, formatted through the `money` Jinja filter — USD `$1,234.56`, IDR `Rp 1.234.567`, negatives in parentheses. **Always pass the currency explicitly** (`|money('idr')`); the default is USD and silent defaults caused a real bug. Totals summed across mixed currencies must convert via `EXRATE` before summing.
- **Badges:** shipment-kind badges use their own hue (white text, bold, subtle shadow for the two warm hues); module badges (INV/OPR/AP/AR/BYR…) use semantic `-subtle` pairs; status uses `success/danger-subtle`. One badge per concept — never stack two badges to say one thing.
- **Stat card:** centered label above a large value, used in 4-up grids (`col-12 col-md-6 col-lg-3`); value color may use a semantic hue for emphasis, label stays muted.
- **Sidebar:** all icons — main items, group toggles, sub-items — render at one shared size in a fixed-width box for a single baseline; sub-items differentiate through smaller text and indent, not smaller icons. Group labels are uppercase muted; sub-items are muted until hover/active. Active state = 3px left accent bar + accent-tinted fill + accent-colored icon. Revealed groups get a subtle inset background so children read as a nested block. Groups auto-expand when their `active_page` matches.
- **Empty state:** centered check-circle in `success`, one headline, one muted sentence — no illustration, no button theater.
- **i18n:** every user-visible string goes through `_t()` with keys in EN/ID/ZH; badges map machine values through label helpers (`anomaly_label`), never render raw enum text.

## Do's and Don'ts

- Do: wrap every data table in the table-card pattern; add new columns with nowrap/truncate rules; extend `--color-*` tokens instead of writing hex in templates; scope per-company pages to the active DB from the header selector.
- Don't: hardcode `table-light` or any light-only treatment (breaks dark theme); render amounts without an explicit currency; introduce a second accent hue; put decorative cards around single values that aren't stats; add animation beyond the 120ms hover transition.

## Motion

- **Approach:** minimal-functional — this is a ledger, not a showcase.
- **Easing:** enter(ease-out) exit(ease-in) move(ease-in-out)
- **Duration:** micro(100-120ms) for hover/active; no longer transitions anywhere.
- **The one authored moment:** the sidebar active-indicator bar sliding between items as you navigate.

## Decisions Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-09-11 | Initial design system created | Extracted from theme.css + CoreUI dark tokens during the 2026-09-11 design review; verified against all eight live pages |
| 2026-09-11 | Explicit-currency rule for money filter | IDR rows rendered with `$` when the default was relied on (FINDING-001) |
| 2026-09-11 | tabular-nums global on right-aligned table cells | Money columns must align digit-for-digit across rows and themes |
