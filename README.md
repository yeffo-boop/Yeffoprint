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
- **Phase 5 (Configurator)** — done: the live Label Configurator at each Template's page (`/shop-labels/{template}/`) — synced Label View/Vial View preview, Size/Material selectors, dynamically-rendered customization fields with live character counters, auto-scaling preview text with a clear overflow warning when a field genuinely doesn't fit, quantity shortcuts, and a full multi-variant batch editor (add/switch/duplicate/remove labels within one order, each with its own field values and quantity). Backed by a new public read-only REST endpoint that hands the configurator everything it needs in one call. Add to Cart is a working stub (Phase 7 wires it to WooCommerce).
- **Phase 6 (Pricing)** — done: a real Pricing Rule admin screen (base price, $25 custom design fee, a keyboard-operable bulk-discount-tier repeater) backs `(base + adjustments − discounts) × quantity` exactly as specified. The configurator now shows an instant client-side estimate, then swaps in the authoritative, discount-aware breakdown (base/material/size/quantity/discount/total, each shown separately) from a new public `/pricing/calculate` endpoint — the same server-validated number a future Add to Cart would submit, never a client-trusted total.
- **Phase 7 (WooCommerce Commerce Integration)** — done: Add to Cart is fully wired up. Each Template gets one hidden, made-to-order WooCommerce product (never a template×size×material variation explosion); Add to Cart server-validates the whole batch, adds it as one cart line item, and opens the branded slide-out cart drawer (thumbnail, size, material, quantity, live price, Edit customization, Checkout/View Cart) that's been waiting since Phase 2. Cart price is always live-recalculated; placing an order freezes a full structured snapshot (template, size, material, pricing, and each variant) onto the order line item. `wp yeffoprint setup-shipping` configures the spec's flat-rate shipping zones (USPS $6 / UPS $15 / International $25). **Known gap:** the branded checkout styling and item-data display assume classic WooCommerce cart/checkout pages — see `docs/ARCHITECTURE.md` §9 if the site ends up on the block-based Cart/Checkout instead.

See `docs/PROJECT_SPEC.md` §21 for the full phased delivery plan.
