# YeffoPrint Migrate

A one-time tool for moving data from an old YeffoPrint WooCommerce site to a new one: **WooCommerce settings, order history, and user accounts — nothing else.** No products, no theme or other site content, no yeffoprint-core content types (Templates, Materials, Custom Orders, Proofs, Saved Designs).

Deliberately a separate plugin from `yeffoprint-core` — this is migration tooling, not part of the site's permanent architecture. Deactivate (or delete) it once the migration is done.

## Requirements

WooCommerce active on **both** sites (export and import). Does not require `yeffoprint-core` to be active on either side — order/user meta, including yeffoprint-core's own `_yp_*` keys, is copied through as opaque data without needing to interpret it.

## How to use it

Install and activate on both the old and new site. On both, go to **Tools → YeffoPrint Migrate**.

Recommended order:

1. **Settings** — export on the old site, download the file, upload + import on the new site. Import *overwrites* matching settings and replaces shipping zones/tax rates wholesale (not a merge) — confirm you actually want that before importing.
2. **Users** — export/import next. Accounts are matched by email: if the new site already has an account with that email, it's left completely untouched and just gets mapped for step 3; only a genuinely new email creates an account (with the original password hash carried over, so existing passwords keep working).
3. **Orders** — import last, after Users has finished. Orders link to the accounts Users import creates/matches, so doing this first would leave every order unowned. Re-running a completed orders import skips anything already migrated (by comparing against a `_yp_migrated_from_order_id` meta marker), so it's safe to resume after an interruption.

Large user/order histories are exported and imported in small batches automatically (a progress bar tracks it) — this all runs from the browser, so keep the tab open until each step reports done.

## Known, deliberate limitations

- **Order line items' `product_id`/`variation_id` won't resolve to real products** on the new site, since products never migrate. The line item's own frozen name/price/quantity/total (which WooCommerce always stores separately from the live product) still display correctly — only "view product" style links break.
- **An order originally linked to a Custom Design request** (`yp_custom_order`) keeps that link's ID in its meta, but the linked record itself doesn't migrate, so its Reorder/proof-download links will dangle. Regular Template-based label orders (the majority) carry no such reference.
- **Refunds are preserved as a read-only summary** (amount, reason, date) on the order, not reconstructed as real refund records — replaying WooCommerce's actual refund-creation flow risks re-triggering stock/gateway/email side effects that have no business firing again during a migration.
- **New order IDs will not match the old site's order numbers.** The original ID is kept as searchable meta (`_yp_migrated_from_order_id`), not as the literal new ID — WordPress doesn't offer a safe way to force a specific post/order ID without real collision risk.

## Security

Export files can contain password hashes and customer PII. They're stored under `wp-content/uploads/yeffoprint-migrate/`, blocked from direct web access (`.htaccess` + `index.php`), and only reachable through a nonce-verified, `manage_options`-gated download link — never a guessable direct URL. Delete them from the Files list on the admin page once a migration is complete.
