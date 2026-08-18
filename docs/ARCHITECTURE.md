# YeffoPrint — Architecture

Companion to `PROJECT_SPEC.md`. This file explains *how* the system is structured, not *what* it should do. Keep it current as implementation decisions are made — future Claude Code sessions should be able to onboard from this file alone.

## 1. Two-Codebase Split

```
yeffoprint/          # block theme — presentation only
yeffoprint-core/      # plugin — business logic, data, pricing, orders
```

**Rule of thumb:** if it would still need to exist under a different theme, it belongs in `yeffoprint-core`. If it's purely how things look/lay out, it belongs in `yeffoprint`.

### `yeffoprint` (theme) owns
- `theme.json` design tokens (color, spacing, radius, typography)
- Block templates and template parts (FSE)
- Reusable block patterns (hero, featured designs, materials grid, etc.)
- Header/footer/announcement bar presentation
- WooCommerce template overrides for presentation only (product cards, cart drawer markup, checkout layout)
- Configurator **UI** (rendering, styling, preview surfaces) — consumes state/data from plugin-provided APIs, does not own pricing or schema logic
- Responsive/accessibility CSS and JS behavior for presentation components

### `yeffoprint-core` (plugin) owns
- Custom post types / data records: Templates, Materials, Sizes, Pricing Rules, Custom Orders, Proofs
- Template field-schema engine (generic, not compound/strength-specific)
- Pricing engine (base price, surcharges, bulk discount tiers, server-side authoritative calculation)
- Batch/variant data model and validation
- Cart/order metadata structure (how customization state is serialized and reconstructed)
- Custom order + proof workflow and status model
- REST/AJAX endpoints the configurator calls (fetch template schema, live price calc, submit custom order, etc.)
- Admin UI under a top-level **YeffoPrint** menu (Templates, Materials, Sizes, Pricing Rules, Custom Orders, Proofs, Site Settings)
- Seed/demo data tooling (dev-only, never auto-overwrites production content)
- Future: saved designs, reorder, rewards, sticker infrastructure

**Never:** business logic (pricing math, schema validation, order state transitions) inside the theme. Never core WordPress/WooCommerce file modification.

## 2. Core Data Model

```
Template
 ├─ id, name, active/inactive, featured, popularity/sort
 ├─ artwork, gallery imagery, vial mockup imagery
 ├─ tags: searchable / color / style / material-compatibility
 ├─ field_schema[]            # generic — see below
 └─ compatible_sizes[], compatible_materials[]

Field (within a Template's field_schema)
 ├─ id, label, type, default, required
 ├─ max_chars, position, alignment
 ├─ font_size_max, font_size_min, formatting_rules
 └─ admin_description, preview_behavior

Size (admin-managed record)
 ├─ id, name, active/inactive
 ├─ print_dimensions
 └─ price_adjustment

Material (admin-managed record)
 ├─ id, name, description, swatch/image, active/inactive
 ├─ price_adjustment
 ├─ compatible_products
 └─ sort_order

PricingRule
 ├─ base_unit_price
 ├─ size_surcharges[], material_surcharges[]
 ├─ bulk_discount_tiers[]   # threshold, type(percent|fixed_unit_price)
 └─ custom_design_fee

Batch (a single line item's shared config)
 ├─ template_id + version, size_id, material_id
 ├─ total_quantity
 ├─ pricing_snapshot            # rule version + computed breakdown
 └─ variants[]

Variant (one personalized label within a Batch)
 ├─ id, quantity
 ├─ field_values{}              # keyed by Field.id, per the template's schema
 └─ preview_state

CustomOrder (Fully Custom Design workflow — separate from Batch/Variant)
 ├─ size, material, quantity
 ├─ compound(s)/strength(s), brand name, style notes, instructions
 ├─ inspiration_uploads[]
 ├─ design_fee (fixed, $25 at launch)
 ├─ status  # Design in progress → Proof ready → Awaiting approval → Approved → Printing → Shipped
 └─ proof_history[]             # future portal: uploads, approvals, timestamps
```

**Design principle:** the schema is generic over field types — nothing in the data model or pricing engine should assume "compound" or "strength" specifically. Compound/strength are just two Fields defined on a given Template's schema.

## 3. Pricing Flow

1. Client (configurator JS) computes a **provisional** price locally from cached pricing-rule data for instant feedback.
2. On every state change that affects price, and always before Add to Cart / checkout, the client calls a plugin REST endpoint that recalculates from the authoritative `PricingRule` data server-side.
3. The server-computed value is what's stored on the cart item and order — client-submitted totals are never trusted.
4. Bulk discount tier is evaluated against the **batch's combined quantity** across all variants, not per-variant.

## 4. Preview Architecture

- Single source of truth: the active Batch/Variant state (JS state object, mirrored to a hidden form/cart payload).
- Label View and Vial View are two renderers subscribing to the same state — never separate data paths. Prefer SVG/structured HTML+CSS for Label View so text stays crisp and font-scaling can be computed precisely (measure against `font_size_min`/`max` before falling back to the overflow warning).

## 5. Cart & Order Metadata

Cart line items store structured metadata (not a single serialized blob), sufficient to:
- Re-render the exact configurator state for "Edit customization"
- Reconstruct the order for a future "Reorder" action
- Preserve which `PricingRule` version was active at time of purchase (so historical orders remain accurate even after prices change later)

## 6. Proof / Custom Order States

Modeled as a dedicated status field on `CustomOrder`, independent of WooCommerce's native order status, since the production/proof lifecycle (design → proof → approval → printing) doesn't map cleanly onto WooCommerce's processing/completed states. WooCommerce order status still drives payment/fulfillment; `CustomOrder.status` drives the production workflow and is surfaced to the customer and admin separately.

## 7. Key WordPress/WooCommerce Integration Points

- WooCommerce: product/catalog infra, cart, checkout, customer accounts, orders, WooPayments, shipping — used natively wherever it models the need adequately.
- Product-level customization (fields, batches, variants, pricing) is layered on top via `yeffoprint-core`, not via WooCommerce Product Variations (avoids the template × material × size variation explosion the spec explicitly rules out).
- REST/AJAX endpoints exposed by the plugin for: template schema fetch, live price calc, cart add w/ structured metadata, custom order submission + uploads.

## 8. Extensibility Points (built now, UI later)

- `CustomOrder` proof fields already shaped for a future customer-facing proof portal.
- Batch/Variant model already sufficient for a future "Reorder" (rehydrate into configurator) and "Saved Designs" (persist a Batch to a user without purchasing).
- Pricing/upload/option infrastructure intentionally generic so Custom Stickers (Phase 2) can reuse it rather than needing a parallel system.

## 9. Open Architectural Decisions

*(Log decisions here as they're made during implementation, with brief rationale — keep entries short.)*

- **Data records as CPTs (Phase 1):** Template, Material, Size, Pricing Rule, Custom Order, and Proof are implemented as custom post types (`yp_template`, `yp_material`, `yp_size`, `yp_pricing_rule`, `yp_custom_order`, `yp_proof`) with structured meta rather than custom DB tables. Rationale: native wp-admin list-table/CRUD UI for free, works with REST out of the box, no migration tooling needed at this stage. Revisit only if query patterns demand custom tables later.
- **Plugin bootstrap pattern:** single root plugin file (`yeffoprint-core.php`) that requires a `YeffoPrint_Core` singleton in `includes/class-yeffoprint-core.php`, which in turn loads one class per post type under `includes/post-types/`. Keeps registration declarative and easy for future phases (pricing engine, REST endpoints, admin screens) to hook into the same singleton without touching the bootstrap file.
- **Theme scaffolding (Phase 1):** minimal valid FSE block theme (`style.css` header + `theme.json` + empty `templates/index.html` + `parts/header.html`/`footer.html`) with design tokens (color palette, radius scale, typography, spacing) from PROJECT_SPEC §5 encoded directly in `theme.json`. No patterns or WooCommerce template overrides yet — those start in Phase 2/3.
