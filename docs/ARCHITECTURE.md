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
- **Global UI tokens (Phase 2):** added shadow (`shadow-elevation.low/medium/high`), glassmorphism (`glass.background/border/blur`), and `focus-ring` tokens under `theme.json` `settings.custom`, plus a `spacingSizes` scale mirroring the existing `spacing-scale` custom tokens so the block editor's own spacing UI offers the same values. Button element styling (radius, padding, hover) set via `styles.elements.button` rather than a class, so it applies to every core Button block automatically.
- **Component CSS lives in one file (Phase 2):** `assets/css/global.css` holds all cross-page component styles (header, drawer primitive, cards, forms, footer, a11y utilities) enqueued via `wp_enqueue_scripts`. Section/page-specific styling will move into block patterns as they're built (Phase 3+); this file stays scoped to reusable chrome so it doesn't grow into a dumping ground.
- **Drawer primitive, not a cart-specific component (Phase 2):** built one generic accessible drawer (`.yp-drawer`, open/close/focus-trap/ESC in `assets/js/site.js`) used now for the mobile search overlay and stubbed for the cart. The Phase 7 cart drawer will populate `#yp-cart-drawer`'s body with real line-item markup (and swap in WooCommerce's cart-fragments data) rather than introducing a second drawer mechanism.
- **Mobile nav uses core Navigation block's built-in overlay** (`overlayMenu: "mobile"`) instead of hand-rolled hamburger JS — avoids extra JS for something WordPress core already handles accessibly, in line with the "minimal third-party dependencies" priority.
- **Account icon uses the WooCommerce `customer-account` block** (`icon_only` display) rather than custom account-menu markup, since Accounts (PROJECT_SPEC §16) isn't built until Phase 9 — the block already links to the native My Account page in the interim with no custom code required.
- **Shop Labels is the `yp_template` archive (Phase 3):** rather than a hand-built page template with a manual `WP_Query`, the Template CPT now registers `has_archive => 'shop-labels'` with a matching rewrite slug, so `/shop-labels/` *is* `templates/archive-yp_template.html`. Filters (Style/Color/Material) and sort both work by extending that one native archive query — see the next two entries — instead of introducing a parallel query layer.
- **Filters use native taxonomy query vars, sort uses `pre_get_posts` (Phase 3):** `yp_style`/`yp_color`/`yp_material_tag` are registered with matching `query_var`s, so `?yp_style=bold` filters the archive with zero custom code — WordPress folds it into the main query automatically. Sort (`?sort=featured|newest|popularity`) is resolved in `YeffoPrint_Template_Query::apply_shop_labels_sort()` (plugin, since it's query/business logic) via `pre_get_posts`. Both are plain GET requests with a working non-JS fallback, matching PROJECT_SPEC §6's "richer JS is justified [only in] the configurator."
- **Predictive search extends core search rather than replacing it (Phase 3):** on every Template save, `YeffoPrint_Template_Search` flattens title + style/color/material term names into a `_yp_search_index` post meta value, then splices an `OR (yp_search_index.meta_value LIKE ...)` into core's own `posts_search` SQL fragment (scoped to `yp_template` searches only). The same extended search backs both the classic `s=` query and the REST `?search=` param the header's predictive dropdown (`assets/js/search.js`) calls — one search implementation, two entry points.
- **Card presentation is a theme-owned dynamic block, not a Query Loop of core blocks (Phase 3):** `yeffoprint/template-card` (script-less, `render.php` only, per the WP 6.4 "render" block.json field) is what actually needs per-post plugin data — the vial-mockup hover-swap image and badge can't be expressed as static core block markup inside a Query Loop's Post Template. The plugin exposes that data through one template tag, `yeffoprint_core_get_template_card_data()` (`includes/api/template-api.php`), so the block never reads `_yp_*` meta keys or plugin class constants directly — it stays a presentation consumer of a plugin API, per the Architecture §1 split. `yeffoprint/gallery-toolbar` (the sort/filter bar) is the same pattern, server-rendered because it needs live `$_GET` state and term counts that static markup can't provide.
- **Starting price is a plugin template tag, not a literal string (Phase 3):** `yeffoprint_core_starting_price_label()` (`includes/pricing/class-pricing-placeholder.php`) wraps the `$0.35` base price constant so the theme card never hard-codes a price string, even though the real PricingRule-driven calculation doesn't exist until Phase 6. That file is deleted once Phase 6 lands.
- **Template meta (featured/popularity/badge/vial mockup) has no admin UI yet (Phase 3):** registered via `register_post_meta` with `show_in_rest` so it's settable through the REST API, WP-CLI, or the block editor's Custom Fields panel, but no meta box was built — Phase 4 ("Template Management") owns the admin CRUD experience and will replace this with proper controls. `wp yeffoprint seed` (dev-only WP-CLI command, idempotent by slug) seeds demo Templates so the gallery/filters/sort/search are actually exercisable before Phase 4 lands.
- **field_schema is a single JSON meta value, not per-key `register_post_meta` fields (Phase 4):** each Template's `field_schema` (array of field definitions — id/label/type/default/required/max_chars/position/alignment/font sizing/formatting rule/preview behavior/admin description) is stored as one JSON-encoded string in `_yp_field_schema`, validated by `YeffoPrint_Field_Schema::sanitize()` — not exposed via `register_post_meta`'s REST array schema. Reasoning: a REST-typed nested array-of-objects schema buys nothing yet, since nothing public reads it until Phase 5's schema-fetch endpoint, which can shape the JSON however the configurator actually needs it. The admin editor reads/writes it through the classic nonce-verified meta box POST flow, same as `compatible_sizes`/`compatible_materials` (also plain `get/update_post_meta`, same rationale).
- **Field-schema repeater reorders via Move Up/Down buttons, not drag-and-drop (Phase 4):** `assets/admin/field-schema.js` keeps the whole repeater keyboard-operable (PROJECT_SPEC §6 WCAG 2.2 AA target) rather than requiring pointer-based HTML5 drag-and-drop, which needs significant extra ARIA work to be accessible. Same vanilla-JS, no-build-step approach as the theme's JS — state lives in a plain in-memory array, every mutation re-renders and re-syncs one hidden JSON input that submits with the normal post form.
- **V1 field types are `text` and `textarea` only (Phase 4):** `YeffoPrint_Field_Schema::TYPES` is a single constant map nothing else depends on the shape of — extending it later (e.g. a `select` type) touches one array, not the sanitize logic, the repeater JS, or the save flow.
- **Position is two percentage inputs (x/y, 0–100), not a visual click-to-place picker (Phase 4):** matches PROJECT_SPEC §10 ("no freeform/drag-drop placement in V1") — admin sets a fixed position per field, customers never move it. A visual picker on top of the actual artwork is Phase 5 territory (it needs the Label View preview surface to exist first), not a Phase 4 admin-CRUD concern.
- **Material/Size lean on native WordPress fields instead of custom meta (Phase 4):** active/inactive is `post_status` (publish/draft) rather than a redundant boolean meta flag; sort_order is native `menu_order` (via `page-attributes` support, which also gives a built-in drag-orderable admin list for free); Material's description is `post_content` (via `editor` support); the swatch image is the existing featured-image (`thumbnail` support, already registered in Phase 1). The only new meta is `price_adjustment` (both) and Size's `print_width_mm`/`print_height_mm`. `compatible_products` from the Architecture §2 Material model isn't duplicated on Material — `Template.compatible_materials` (set from the Template editor) is the single source of truth for that relationship, read from the Template side only, so the two can't drift out of sync.
- **The "template schema fetch" endpoint is public and read-only (Phase 5):** `GET /yeffoprint-core/v1/templates/{id}/configurator` (`class-template-schema-controller.php`) needs no auth (`permission_callback` is `__return_true`) because everything it returns is already public storefront data — a published Template plus its published, compatible Sizes/Materials. It shapes `field_schema`/sizes/materials/pricing into exactly what the configurator needs, rather than the theme assembling that from three separate `wp/v2` REST calls. `base_unit_price` is still the Phase 3 placeholder constant — Phase 6 is what makes this endpoint's pricing authoritative.
- **Quantity presets (25/50/100/250/500/1000) live in the plugin, not the theme (Phase 5):** `yeffoprint_core_quantity_presets()` (`includes/configurator/quantity-presets.php`) is a fixed business rule from PROJECT_SPEC §10, not an admin-configurable record like Sizes/Materials — but it's still returned through the schema-fetch endpoint rather than hard-coded in `configurator.js`, so there's one source of truth if it ever needs to change.
- **Label View and Vial View render the exact same field DOM (Phase 5):** per Architecture §4 ("never a separate data source"), `configurator.js` builds one set of absolutely-positioned field elements from `field_schema` and only swaps the background image + toggles a CSS class between views — Vial View isn't a second render path. Because there's no per-template "where does the label sit on this bottle" data yet, Vial View approximates it with a flat `scale(0.55)` on the same field positions rather than a calibrated placement region. Revisit if/when Vial View needs to look convincing rather than illustrative — likely a new admin-configurable placement field on Template, not a configurator rewrite.
- **Font auto-scaling measures against a shared box ratio, not a per-field box size (Phase 5):** the Field data model has `font_size_min/max` and `position` but no explicit box width/height, so `fitText()` in `configurator.js` measures every field against a box that's a fixed percentage of the stage's own rendered size (86% width; 32% height, multiline fields only) rather than a per-field dimension. Shrinks the field's font from `font_size_max` down to `font_size_min` in 1px steps until it fits (or doesn't — then it gets the `is-overflowing` outline and the shared "Text is too long for this design" banner, per PROJECT_SPEC §10). If per-field box sizing turns out to matter, it's an additive schema field, not a rework of the fitting algorithm.
- **Provisional pricing only, still no server validation (Phase 5):** the configurator's running total (`unitPrice() × totalQuantity()`) uses `base_unit_price` plus whatever Size/Material `price_adjustment` the customer picked — real values already in the REST payload — but has no bulk-discount tiers and is never sent anywhere the server would trust it. "Server always recalculates/validates authoritative price" (PROJECT_SPEC §12) is Phase 6.
- **"Add to Cart" is a visible stub, not a disabled button (Phase 5):** since WooCommerce cart integration is Phase 7, both Add to Cart buttons (desktop summary panel + mobile sticky bar) are live and clickable but show an inline "Cart isn't connected yet" message rather than being permanently `disabled` — keeps the buttons testable/stylable now and is a one-line swap for the real cart-add call later.
- **`wp yeffoprint seed` now also creates the launch Sizes/Materials and wires each demo Template's compatible_sizes/compatible_materials/field_schema (Phase 5):** the Phase 3 version only seeded Templates, which left the configurator with nothing to render. Same idempotent-by-slug approach, extended to `yp_size`/`yp_material`.
