# YeffoPrint

Custom WordPress + WooCommerce e-commerce experience for YeffoPrint — a premium custom printing brand.

- [`docs/PROJECT_SPEC.md`](docs/PROJECT_SPEC.md) — what to build
- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — how it's structured, and the log of implementation decisions

## Codebases

| Path | Responsibility |
|---|---|
| `yeffoprint/` | Block theme — visual presentation only |
| `yeffoprint-core/` | Plugin — business logic, data model, pricing, orders |

Business-critical functionality lives in `yeffoprint-core` and must never depend on the active theme.

## Status

- **Phase 1 (Foundation)** — done: repo scaffolding, `theme.json` design tokens, and the core data-record post types (Templates, Materials, Sizes, Pricing Rules, Custom Orders, Proofs).
- **Phase 2 (Global UI)** — done: floating glass header with announcement bar, search/cart drawers, footer, button/card/form styles, and accessibility foundations (focus-visible, reduced-motion, skip link, drawer focus trapping).
- **Phase 3 (Storefront)** — done: homepage (hero through FAQ, assembled from block patterns), the Shop Labels gallery (`/shop-labels/`) with server-rendered Style/Color/Material filters and Featured/Newest/Most Popular sort, the gallery card as a theme-owned dynamic block, and predictive search (extends core search to match style/color/material tags, not just title).
- **Phase 4 (Template Management)** — done: a nondeveloper can now add a Template entirely from wp-admin — featured/badge/popularity, vial mockup image (media library picker), compatible Sizes/Materials, and a full customization-field-schema repeater (add/remove/reorder fields with type, default, required, max chars, position, alignment, font sizing, formatting rule, preview behavior, admin description). Materials and Sizes also got real admin CRUD (pricing, print dimensions, drag-orderable sort, active/inactive via Publish/Draft).

See `docs/PROJECT_SPEC.md` §21 for the full phased delivery plan.
