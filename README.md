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

Phase 1 (Foundation) in progress: repo scaffolding, `theme.json` design tokens, and the core data-record post types (Templates, Materials, Sizes, Pricing Rules, Custom Orders, Proofs). See `docs/PROJECT_SPEC.md` §21 for the full phased delivery plan.
