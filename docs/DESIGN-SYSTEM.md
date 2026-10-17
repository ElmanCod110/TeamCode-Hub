# Design System

This document describes the shared visual and interaction foundation used by TeamCode Hub. The design system is intentionally framework-free and is suitable for ordinary PHP hosting.

## Public assets

| File | Responsibility |
| --- | --- |
| `public/assets/css/app.css` | Versioned stylesheet entry point |
| `public/assets/css/tokens.css` | Color, typography, spacing, radius, elevation, motion, and layering tokens |
| `public/assets/css/base.css` | Browser defaults, semantic element defaults, focus visibility, and reduced motion |
| `public/assets/css/layout.css` | Containers, responsive grids, page shell, sidebar, and header layouts |
| `public/assets/css/components.css` | Buttons, forms, badges, cards, alerts, tables, tabs, overlays, and feedback |
| `public/assets/css/utilities.css` | Small composable spacing, text, surface, and alignment helpers |
| `public/assets/js/ui.js` | Accessible interactions shared by product pages |\n| `public/assets/css/workspace.css` | Product-specific workspace, dashboard, project, task, and chat presentation |\n| `public/assets/js/workspace.js` | Authenticated API integration and workspace interactions |\n| `public/app.html` | English workspace shell and sign-in form |
| `public/assets/css/design-system-demo.css` | Styles unique to the reference showcase |
| `public/assets/js/design-system-demo.js` | Demo-only button handlers |
| `public/design-system.html` | English component reference page |

## Loading the design system

Load the entry stylesheet and the shared UI controller from a page template. Keep product-specific styles and behavior in separate files and avoid inline style or script blocks.

```html
<link rel="stylesheet" href="/assets/css/app.css?v=0.2.0-dev">
<script src="/assets/js/ui.js?v=0.2.0-dev" defer></script>
```

The entry stylesheet imports its layers in dependency order and versions each imported URL. When the design system changes, update the version in the README/changelog and the asset URLs together. A production release should use its actual SemVer version.

## Design principles

- Use the neutral palette as the default; reserve semantic colors for success, warning, danger, and informational states.
- Use the spacing and radius tokens before introducing new values.
- Prefer a component class over a long sequence of utilities for a repeated interface pattern.
- Keep content widths readable and isolate horizontal scrolling to data-heavy components.
- Use system fonts only. The font stack prefers Inter when it is already installed, then falls back to platform UI fonts. No remote font service is required.
- The light palette is the default. A page can opt into the dark palette with `data-theme="dark"` on the root element. The theme controller remembers a user's explicit choice when browser storage is available.
- All user-facing product copy must be English and the default document language must be declared with `lang="en"`.

## Component conventions

### Buttons

Use `.btn` with a single visual variant: `.btn-primary`, `.btn-outline`, `.btn-subtle`, `.btn-ghost`, `.btn-danger`, or `.btn-danger-outline`. Use `.btn-sm` and `.btn-lg` for size variants. Icon-only buttons must provide a meaningful accessible name.

### Forms

Use `.field` to group a visible `.label`, control, and optional `.help` or `.error-text`. Connect labels to controls using matching `for` and `id` values. Set `aria-invalid="true"` on invalid controls and describe the error in text. Never rely on color alone to communicate validation state.

### Status and feedback

Use `.badge-success`, `.badge-warning`, `.badge-danger`, and `.badge-info` for concise status labels. Use alerts with `role="status"` for non-urgent updates and `role="alert"` for urgent errors. Toast messages must be plain text; the shared controller uses `textContent`, not HTML parsing.

### Tabs

A tab list uses `data-tabs` and `role="tablist"`. Each button has `data-tab="key"`, `role="tab"`, and an `aria-controls` reference. Each corresponding panel uses `data-tab-panel="key"`, `role="tabpanel"`, and an `aria-labelledby` reference. Place the tab list and panels inside a common `data-tabs-scope` container. The controller supports arrow keys, Home, and End.

### Dropdowns and dialogs

Dropdown triggers use `data-dropdown-toggle` and `aria-controls` pointing to the menu element ID. Dialog openers use `data-modal-open="dialog-id"`; close controls use `data-modal-close`. Use proper dialog semantics, an accessible title, and a description where helpful. Server-side authorization is still required for every action invoked by a UI control.

### Responsive sidebar

Use `id="app-sidebar"` for the drawer, `data-sidebar-toggle` for openers, `data-sidebar-close` for close controls, and optionally `id="sidebar-backdrop"` with class `.sidebar-backdrop` for the backdrop. Keep the drawer's links usable by keyboard and avoid placing sensitive actions in hidden menus without server-side permission checks.

## Product workspace\n\nThe product interface is documented separately in [WORKSPACE-UI.md](WORKSPACE-UI.md). It uses the shared tokens and components while keeping product-specific styles in `workspace.css`. The workspace JavaScript uses same-origin credentials, CSRF tokens for mutations, and DOM text nodes for user-controlled values.\n\n## Accessibility checklist

- Preserve a visible keyboard focus indicator.
- Provide a skip link to the main content on application pages.
- Use semantic headings in order and real buttons for actions.
- Give icon-only controls accessible names.
- Associate form labels and errors with their controls.
- Maintain meaningful ARIA states when tabs, menus, dialogs, and drawers change.
- Respect `prefers-reduced-motion`.
- Ensure that information is not communicated through color alone.
- Verify contrast after adding custom colors or changing theme tokens.
- Test layouts at narrow viewport widths and with keyboard-only navigation.

## Validation

Run:

```sh
php tests/design-system.php
node --check public/assets/js/ui.js
node --check public/assets/js/design-system-demo.js
```

CI runs these checks in addition to PHP lint, smoke tests, and MariaDB integration tests. These checks do not replace manual browser review, assistive-technology testing, or visual regression testing.

## Current scope

The showcase is a reference implementation of the style system, not a claim that the product dashboard, team/project screens, or chat interface are complete. Product screens must be built on the existing APIs and retain server-side authentication and authorization checks.
