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

See `docs/PROJECT_SPEC.md` §21 for the full phased delivery plan.
