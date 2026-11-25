# Design System — CMT Dashboard

**Status**: Draft (validated)
**Derives from**: `docs/srs.md`, `src/app/templates/`, `src/app/static/`

This document describes the existing visual and interaction design elements. The goal is to document, not to change, unless a change is required for a functional requirement (e.g., adding a sync status indicator).

## 1. Color Palette (CoreUI Dark Theme)

The application uses the default CoreUI v5 dark theme. Key colors observed:

- **Primary**: `#321fdb` (Buttons, links, key icons)
- **Background**: `#181b1e` (Page background)
- **Surface**: `#2a2f32` (Card backgrounds)
- **Text**: `rgba(255, 255, 255, 0.87)` (Primary text)
- **Secondary Text**: `rgba(255, 255, 255, 0.6)` (Labels, subtitles)
- **Success**: `#2eb85c`
- **Danger**: `#e55353`
- **Warning**: `#f9b115`

## 2. Typography

- **Font Family**: System UI font stack (e.g., Segoe UI, San Francisco, Roboto).
- **Base Font Size**: `1rem` (16px)
- **Headings**: Standard Bootstrap/CoreUI heading scale (`h1` through `h6`).

## 3. Key Components

The UI is built with standard components from **Bootstrap 5** and **CoreUI 5**.

- **Cards**: Primary layout container for all content blocks.
- **Tables**: Used to display all data (shipments, invoices, etc.). Tables are standard, non-paginated, and show the last 50-100 records.
- **Buttons**: Standard primary and secondary buttons for actions like "Sign In" and "Logout".
- **Forms**: Used for login (`login.html`). Standard Bootstrap form controls.
- **Alerts**: Used for displaying errors (e.g., on the login page).
- **Icons**: **Bootstrap Icons** are used for decorative and functional purposes (e.g., `bi-box-seam` on the login page).

## 4. Layout

- **Global**: A sidebar navigation layout is used for the main application (defined in `base.html`). The login page (`login.html`) is a standalone centered layout.
- **Responsiveness**: The layout is responsive and works on mobile devices, thanks to the Bootstrap grid system.

## 5. Internationalization (i18n)

- **Mechanism**: A simple `_t(key)` function powered by JSON files (`src/app/translations/{lang}.json`).
- **Languages**: `id` (Indonesian), `en` (English), `zh` (Chinese).
- **Detection**: Language is set via a `cmt_lang` cookie. A language switcher is present in the UI to set this cookie.

## 6. Interaction Patterns

- **Data Sync**: Triggered by a button click which sends a `POST` request to `/sync`. This is an asynchronous action from the user's perspective; the UI does not currently provide real-time feedback on its progress or completion.
- **Filtering**: Data for the `customer` role is filtered server-side. There are no client-side filtering or search controls available to the user.
- **Authentication**: Standard form-based login. Session is maintained via an `HttpOnly` cookie.
