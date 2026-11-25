# Decision record — Filament as the Laravel view layer

**For:** engineering + owner · **From:** engineering · **Date:** 2026-10-14 · **Status:** decided

**Where this sits:** PRD §1.2 ("DECIDED — Frontend framework — Filament adopted as the view layer"),
Q-09, and the §3.2 scope note ("each row is a Filament page to reproduce"). PRD §1.2 is the single
source of truth for the *what*; this file is the durable record of the *how* that the panel provider
and converted pages must match.

## 1. The decision in one line

Adopt [Filament](https://filamentphp.com/) v5.10.2 as the admin-panel / view layer for the Laravel
rebuild. The app stays Laravel MVC — same controllers, same query classes, same sync-owned SQLite
schema (no Eloquent models on business tables, same as the Python app). Filament is the wiring and
component infrastructure; it is not a free pass to re-theme the system.

## 2. Pinned version

- `laravel/composer.json`: `"filament/filament": "5.10.2"` (exact, no `^`/`~` range).
- Lockfile carries `v5.10.2` at a real git reference; `composer audit` reports 0 High/Critical
  advisories (NFR-001b / SC-005).
- `post-autoload-dump` runs `php artisan filament:upgrade` so the panel's compiled assets stay
  coupled to the pinned release.

Upgrade policy: move the pin only through an explicit todo that updates the pin, regenerates any
committed asset snapshot the panel depends on, re-runs the parity + role-gate tests for the converted
page, and re-runs `composer audit`. Do not float the constraint.

## 3. Theme / customization approach (DESIGN.md is binding)

DESIGN.md remains binding for color, type, spacing, motion, and the "interface disappears, numbers
stay sharp" aesthetic. Filament supplies the component chrome; the app supplies the tokens.

**What is configured on the panel (`App\Providers\Filament\AdminPanelProvider`):**

- Auth is the application's own session — guard `cmt`, no Filament login page, no second user store.
  Guests are redirected to the app's `/login`. The panel's access rule is "has a valid app session";
  per-role gates live on the individual panel pages (PRD §3.2 per-row roles), not on the panel itself.
- Colors from DESIGN.md's `colors.primary` (#5856d6) plus the other semantic tokens, wired via
  `colors([primary, danger, warning, success, info])` so Filament's components pick them up.
- Font: IBM Plex Sans from Google Fonts, set via `font()`, matching DESIGN.md's type token.
- Dark-first: `darkMode()` + `defaultThemeMode(ThemeMode::Dark)`, matching DESIGN.md's dark-first
  stance.
- `maxContentWidth(Width::Full)` — dense, tabular tables (no max-width card gutters on data tables).
- The DESIGN.md token map is also shipped as a plain stylesheet
  (`public/css/cmt/filament-tokens.css`) registered as an *additional* admin asset via
  `FilamentAsset::register()`. It is layered *on top of* Filament's own theme CSS, not a replacement
  — so the default theme is preserved and the app tokens win where they conflict.

**What is deliberately NOT done:**

- No custom panel theme package, no re-implementation of Filament's color system, and no
  forked theme. The one deliberate component-level override is shadow suppression on seven
  Filament classes (`.fi-sidebar`, `.fi-topbar`, `.fi-section`, `.fi-ta-ctn`,
  `.fi-wi-stats-overview-stat`, `.fi-dropdown-panel`, `.fi-modal-window`) applied with
  `!important` because Filament's own utilities are specificity-scoped; DESIGN.md forbids
  shadows, so the suppression must happen. It is the single exception, named here rather
  than left implicit. Otherwise the customization is a token map + font + dark mode, exactly
  so the system keeps receiving Filament bugfixes and component improvements without a forked
  theme to maintain.
- No hand-authored Livewire components. The non-goal "no new frontend framework" survives as "no
  *additional* framework beyond Laravel + Filament"; Filament's own Livewire dependency is not an
  additional framework and is not a non-goal.

## 4. Blade-fallback boundary

A page stays plain Blade when any of these is true:

- It is a public, non-panel route (e.g. `/login`, `/set-db`, `/set-lang`) — those are not Filament
  pages by definition.
- It is a parity surface whose query/rendering is already committed and tested in Blade, and converting
  it is tracked by its own §3.2 todo (the report-page items, not this one).
- It is a read-only report page that does not gain anything from a table/actions/pagination scaffold
  Filament provides and whose Blade rendering already passes the parity gate.

For converted pages: the page class renders through a Filament view (`protected string $view =
'filament.pages.<name>'`) that itself is a thin wrapper around `<x-filament-panels::page>` + the
table/widget the page class builds. The page class is where the query is wired; the Blade view under
`resources/views/filament/pages/` is the minimal rendering shell.

## 5. What did not change (PRD §1.2 list, reproduced as checks)

The following carry over unchanged from the Python app / PRD §1.2, and any converted page or panel
config must keep them true. These are checks, not prose, so a later change can be verified against them.

- **5-role RBAC model intact.** `superadmin > admin > management > staff > customer`, same level
  numbers, same `cmt.role` middleware semantics. The panel's `canAccessPanel()` is "has a valid app
  session"; role minimums are on routes/pages, not the panel.
- **Read-only guarantee (F-21).** Business tables are sync-owned; converted pages expose read-only
  tables only. No mutations, no Filament actions that write business data. The test for the converted
  page asserts no mutating action routes are registered for the business models.
- **Atomic-sync contract (FR-007).** Converting a page does not touch the sync. The page reads the same
  active-company DB the rest of the app reads, through the same `CompanyDbManager` + middleware stack
  (`ResolveCompanyDb`, `CmtSession`, `CmtLocale`), which the panel also runs.
- **Money / currency / EXRATE rendering discipline.** `CmtMoney::format()` (and `@money` / `@moneyabbr`)
  is used on both Blade and Filament renderings; the parity test asserts identical formatted values.
- **i18n requirement (F-18).** Every app-visible string on the converted page goes through the app's
  translation path (`CmtI18n` over `lang/cmt/{id,en,zh}.json`), selected by the `cmt_lang` cookie.
  Filament's own UI strings come from Filament's shipped id/zh_CN translations, selected by Laravel's
  locale (set from the cookie by `CmtLocale` middleware). No hardcoded English literals in column labels,
  titles, or empty-state text.
- **Flat dark aesthetic, no-animation-beyond-120ms, no shadows.** Carried by the DESIGN.md token map;
  the panel config does not introduce a light-first default or a shadow-heavy card style.
- **Parity-first scope (nothing new added).** Filament is a different way to render the same parity
  surface. The converted page is asserted side-by-side against the Blade rendering for the same fixture.

## 6. First converted page: Invoices list (F-07)

- Class: `App\Filament\Pages\Invoices` (`app/Filament/Pages/Invoices.php`).
- View: `resources/views/filament/pages/invoices.blade.php` — thin `<x-filament-panels::page>` shell
  that renders `$this->table`.
- Data source: `CmtQueries::getInvoices()`, the same query class the Blade page (`/invoices`) uses
  during parallel run. Both renderings call one query, so data equality is structural, not aspirational.
- No Eloquent model: the business tables are sync-owned, so the table is fed through Filament's
  `records()` data-source hook from raw stdClass rows — not a model the app does not own.
- Role gating: unchanged. The query receives the same `customer_company` filter (FR-005b) the Blade
  page uses; F-07 is a role `all` surface.
- Strings: all column labels, title, subheading, empty state, and badge labels go through
  `$this->t()` / `self::translate()` → `CmtI18n`. Filament's own strings (search, pagination, etc.)
  come from Filament's shipped translations, selected by locale.

## 7. Verification

- `laravel/composer.json` + lockfile: pinned `5.10.2`; `composer audit` → 0 High/Critical.
- Panel provider committed and registered in `bootstrap/providers.php`; panel config matches §3.
- Converted page + test committed: `tests/Feature/FilamentInvoicesParityTest` — 7 tests, 88 assertions:
  same rows/order/totals across Blade vs Filament for all 5 roles and the customer-company filter;
  anonymous denied on both; labels translated and matching the Blade page per language; the DESIGN.md
  token stylesheet is present in the panel HTML.
- Run with the project's PHP shim so `pdo_sqlite`/`sqlite3` are loaded:
  `PHP_INI_SCAN_DIR=laravel/.dev-ini php vendor/bin/phpunit --filter FilamentInvoicesParityTest`
  (or the equivalent `-d extension_dir=... -d extension=pdo_sqlite -d extension=sqlite3` form on the
  Windows dev host).

## 8. Open / downstream

- Each §3.2 report row that is converted to a Filament page gets its own todo and its own parity/role-gate
  test; this file is the shared decision they all inherit from.
- Any page that stays Blade is named in its own todo with a reason from §4, not left implicit.
- Upgrading the pin is a separate todo (§2 policy); it re-runs this file's verification section.
