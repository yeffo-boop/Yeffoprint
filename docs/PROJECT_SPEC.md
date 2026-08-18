# YeffoPrint — Project Specification

## 1. Objective

A fully custom WordPress + WooCommerce e-commerce experience for YeffoPrint, a premium custom printing brand selling customizable printed vial labels and (Phase 2) custom stickers.

Core customer flow: **Browse Designs → Select Design → Customize Live → Choose Options → Checkout**

The site must feel like a purpose-built product application, not a generic WooCommerce store, and it should be difficult for a visitor to tell it runs on WordPress.

## 2. Priorities (in order)

1. Excellent customization experience
2. Premium visual design
3. Fast performance
4. Easy nontechnical administration
5. Clean, maintainable architecture
6. Minimal third-party dependencies
7. Strong mobile experience
8. Future extensibility

## 3. Codebases

| Codebase | Responsibility |
|---|---|
| `yeffoprint` (block theme) | Visual presentation, layouts, templates, patterns, typography, colors, responsive behavior, WooCommerce/configurator UI presentation |
| `yeffoprint-core` (plugin) | Business logic — templates, customization schemas, variants, pricing, materials, sizes, custom orders, proofs, and future saved-designs/reorder/rewards/stickers |

**Rule:** business-critical functionality must never depend on the active theme. No modification of WordPress or WooCommerce core.

## 4. Brand

- Name: **YeffoPrint**
- Identity: modern premium print studio — never clinical, lab, or pharmaceutical in feel
- Voice: premium, polished, professional, modern, occasionally clever, concise, confident but not corporate
- Public terminology: vial labels, custom labels, printed labels, custom stickers, label designs. "Peptides" is never the public-facing identity.

## 5. Visual System

- Palette: ~90% neutral (warm white, white, light gray, charcoal, near-black) / ~10% CMYK accents (cyan, magenta, yellow) used strategically (CTAs, borders, hover states, badges, glows) — not everywhere.
- Aesthetic: primarily light with occasional dark feature sections, rounded corners, premium shadows, selective glassmorphism (header, modals, cart drawer, hero, occasional featured cards only).
- Radius tokens: small controls 10–12px, inputs/buttons 12–14px, cards 18–24px, large containers 28–32px — stored as design tokens, never hard-coded ad hoc.
- Typography: max two font families, modern geometric sans for headings, highly readable sans for body/UI (Inter/Geist/Satoshi territory, properly licensed), minimal uppercase (labels/badges/eyebrows only), globally configurable via theme.json.
- Animation: ~4/10 intensity — subtle elevation, scroll reveals, microinteractions, smooth drawers; no parallax, flying text, or shopping-slowing effects. Respect `prefers-reduced-motion`.

## 6. Platform Requirements

- Modern block theme / Full Site Editing (theme.json, Gutenberg patterns, semantic HTML, CSS custom properties, native WP/WooCommerce APIs).
- No Elementor/Divi/WPBakery/Bootstrap/unneeded frameworks or jQuery.
- Fully editable by a nondeveloper from wp-admin.
- WCAG 2.2 AA target.
- No horizontal overflow; no hover-dependent controls (touch-first).
- Performance-first: minimal JS, responsive images, lazy loading, WebP/AVIF, deferred noncritical JS, no unjustified frameworks (configurator is the one area richer JS is justified).

## 7. Navigation

**Desktop:** logo · Shop Labels · Custom Design · Custom Stickers · How It Works · Search · Account · Cart — floating glass header, compacts on scroll, optional admin-editable announcement bar. Rewards is not primary nav.

**Mobile:** hamburger · logo · search · cart. No persistent bottom nav bar. Configurable product pages get a sticky bottom bar (live total + Add to Cart).

## 8. Homepage Sections (in order)

Hero → Featured Designs → How It Works (Choose/Customize/Print) → Fully Custom Design (dark break) → Materials → Popular Designs → Customer Work/Inspiration → Rewards (promo only) → Reviews → FAQ → Newsletter/Footer.

## 9. Shop Labels Page

- Full gallery shown immediately, no forced collection funnel.
- Default order: featured/relevant → newest → popularity. Sort options: Featured / Newest / Most Popular (price sorting de-emphasized).
- Lightweight filters: Style, Color, Material compatibility. Size is **not** a gallery filter (chosen in configurator).
- Predictive search indexing name, tags, color, style, material, metadata.
- Template names are simple (e.g., "Pure"); descriptive detail lives in metadata/tags, not the public name.
- Product cards: image, name, starting price ("From $0.35/label"), optional badge (New/Popular/Featured/Customizable), hover swap to vial mockup (desktop). No AI-generated mockups.

## 10. Live Label Configurator (signature feature)

- Desktop: ~55–60% preview / ~40–45% controls. Mobile: preview → controls → sticky price/CTA bar.
- Two synced preview modes — **Label View** (flat artwork, SVG/structured HTML preferred for crisp text) and **Vial View** (on-mockup) — sharing one underlying state, never a separate data source.
- **Generic field schema** per template (not hard-coded to compound/strength): internal ID, label, field type, default, required flag, max chars, position, alignment, font sizing rules (with minimum), formatting rules, preview behavior, admin description. Customers edit only predefined fields — no freeform/drag-drop placement in V1.
- Text overflow: auto-scale within limits, then show a clear "Text is too long for this design" warning rather than shrinking illegibly.
- Sizes: launch with 3 mL and 10 mL as admin-managed size records (not hard-coded), each with configurable print dimensions.
- Materials: Glossy White, Matte White, Holographic, Clear, Metallic — admin-managed records (name, description, swatch, active state, price adjustment, compatible products, sort order).
- Quantity: arbitrary, with shortcuts (25/50/100/250/500/1000/Custom).
- **Multi-variant batches**: one order quantity can be split across multiple personalized variants sharing a batch (template/size/material/production); combined quantity drives bulk pricing. UI shows one full editor for the active variant, compact cards for others, with add/switch/duplicate/remove/edit-quantity actions.

## 11. Data Model

**Batch** — shared: template, size, material, total quantity, pricing rules, production options.
**Variant** — per-label: generic field values (per template schema), quantity, preview state, unique ID.

## 12. Pricing Engine (in `yeffoprint-core`, never hard-coded in templates)

- Base: $0.35/label.
- Adjustments: material surcharge, size surcharge, future production options — admin-configurable, not code changes.
- Bulk discounts start above 1,000 units; admin-managed tiers (e.g., 1001+, 2500+, 5000+), each percentage- or fixed-price-based.
- Formula: `(base + adjustments − discounts) × quantity`.
- Live-updating breakdown shown to customer (base, material, size, quantity, discount, total) — no hidden changes.
- **Server always recalculates/validates authoritative price; client-side price is never trusted.**

## 13. Fully Custom Design Orders

- Separate flow, not presented as a premade template ("Create a Custom Label").
- $25 one-time custom design fee, shown transparently (never folded into per-label price).
- Collects: size, material, quantity, compound/strength, brand name, style/colors, instructions, optional multi-file inspiration upload.
- Requires a proof (premade/template orders do not). Custom job status model: Design in progress → Proof ready → Awaiting approval → Approved → Printing → Shipped (tracked separately from WooCommerce's native order status where appropriate).
- Architecture should anticipate a future proof portal (upload, customer view/approve/request changes, timestamped history) without requiring rebuild.

## 14. Cart & Checkout

- Add to Cart opens a slide-out drawer (not immediate navigation) showing template, thumbnail, size, material, key customization details, quantity, price, with "Edit customization" (reloads full existing config) plus Checkout/View Cart. No aggressive upsells in V1.
- Cart/order data stored as structured metadata (template ID/version, batch, variants, field values, quantities, size, material, pricing calc + rule version, preview info) — never one opaque serialized blob.
- Checkout: clean branded WooCommerce checkout, guest/login/optional-account-creation, left contact/shipping/payment + right order summary, WooPayments for processing (no custom payment logic).

## 15. Commerce Configuration

- **Shipping:** US — USPS Ground Advantage $6, UPS 2nd Day Air $15; International — $25. Configured via WooCommerce, not hard-coded in templates.
- **Taxes:** business operates from Oregon, no tax collection initially; keep WooCommerce tax system compatible for future change. No custom tax engine.
- **Inventory:** made-to-order, no stock counts; templates are simply Active/Inactive. No "only X left" messaging.

## 16. Accounts

Recommended, not required. Fully branded dashboard (not default WooCommerce My Account visuals) with Orders, Saved Designs, Rewards, Proofs, Addresses. Architecture must support future Reorder (restore batch into configurator, then edit before purchase) and Saved Designs without reworking the configurator.

## 17. Admin Experience

Custom **YeffoPrint** wp-admin section: Templates, Materials, Sizes, Pricing Rules, Custom Orders, Proofs, Site Settings. A nondeveloper must be able to add a template, material, size, or pricing change without touching code. Site Settings stays consolidated (announcement bar, contact info, social links, featured homepage content, promo copy, placeholder imagery, configurator copy, shipping display copy) rather than sprawling into many screens.

## 18. Security & Quality Bars

- Sanitize input, escape output, validate data, use nonces + capability checks, prepared queries, safe/validated uploads (PDF/SVG/PNG/JPG initially, configurable size limits, no arbitrary executables), safe AJAX/REST endpoints.
- SEO: semantic HTML, logical headings, canonical-friendly URLs, WooCommerce structured data, breadcrumb/OG/sitemap compatibility — without hard-coupling to one SEO plugin.
- Clear, non-silent error handling and polished empty states (search, cart, orders, saved designs, proofs, unavailable templates) instead of default WP/WooCommerce ugliness.

## 19. Explicit Non-Goals for V1

Full sticker configurator UI, saved designs UI, one-click reorder UI, online proof approval UI, rewards dashboard, referral system, expanded sizes/materials beyond launch set, AI-generated mockups. The underlying architecture (pricing engine, option system, uploads, order metadata) must still be built generically enough to support these later without rewrites.

## 20. Hard "Do Not" List

No Elementor/heavy page builders; no unnecessary third-party plugins; no hard-coding sizes/materials/prices into templates; no WooCommerce variation explosion (template × material × size); no required account to purchase; no clinical/lab brand feel; no exposed stock counts; no trusting client-side pricing; no core/WooCommerce core modification; no building the full roadmap before the foundation is validated.

## 21. Phased Delivery

1. Foundation (scaffolding, tokens, docs, data architecture)
2. Global UI (header, footer, buttons, cards, forms, drawers, a11y foundations)
3. Storefront (homepage, gallery, cards, filters, search)
4. Template Management (data model + admin CRUD, prove nondeveloper can add a template)
5. Configurator (Label/Vial views, selectors, multi-variant editor, overflow handling)
6. Pricing (base/surcharges/discounts, live pricing, server validation, admin UI)
7. WooCommerce Commerce Integration (cart, drawer, checkout, order metadata, shipping, WooPayments)
8. Custom Orders & Proof Foundation ($25 fee, uploads, proof states)
9. Accounts & Reordering Foundation (dashboard, reorder/saved-design/proof/rewards architecture)
10. QA & Optimization (responsive, a11y, performance, security, browser, cleanup)

Each phase should be validated before the next begins; do not build ahead of the current phase.

## 22. Success Standard

A customer should be able to discover a design, personalize it visually, split an order across multiple variants, see exactly what they're buying, understand pricing, and check out quickly — all without the experience ever feeling complicated, generic, or WordPress-like.
