# Peptide Label Customizer

A custom WooCommerce plugin for YeffoPrint: turns products into live-preview
vial label templates, plus a separate custom design request flow.

## Requirements

- WordPress with WooCommerce active
- A host that allows custom plugin installs (any standard WooCommerce host,
  or WordPress.com's Business plan / an Atomic site with SFTP access —
  WordPress.com's "Simple" plan does not support custom plugins)

## Installation

1. Zip the `peptide-label-customizer` folder (already zipped as
   `peptide-label-customizer.zip` if you received it that way).
2. In wp-admin: **Plugins → Add New → Upload Plugin**, choose the zip, and
   click **Install Now**, then **Activate**.
   (Or upload the unzipped folder to `/wp-content/plugins/` via
   SFTP/SSH and activate from the Plugins screen.)

## Setting up a label template product

1. Create or edit a WooCommerce **product** (this becomes one label
   template, e.g. "NAD+ 500mg").
2. In the **Label Designer (Live Preview Template)** box:
   - Check **"This product is a live-preview label template."**
   - Click **Choose Template Image** and select the label artwork (like
     the Pure NAD+ label you shared).
   - For each zone you want customizable (Compound Name, Strength, Batch
     Number, Expiration Date), check **Enabled**, then drag its blue
     marker on the image to where that text should sit. Set font size,
     color, weight, alignment, and max character count.
   - Batch Number and Expiration Date can have a **Default Value** — if
     the customer leaves that field blank, the default is used instead.
3. In the **Label Options & Pricing** box:
   - Edit the Size and Media rows (pre-filled with your current 3mL/10mL/
     20mL/30mL/Custom and Glossy/Matte/Holographic/Clear/Silver options
     and their price add-ons) — add, remove, or reprice as needed.
   - Check **"Let customers pick an accent color"** if this template
     should expose a color picker.
4. Publish/update the product. The product page will now show the live
   canvas preview and matching form fields.

## Setting up a custom design request product

1. Create a WooCommerce product (e.g. "Custom Label Design").
2. In the main **Product data** panel (General tab), check **"Custom
   Design Request."**
3. Leave the Label Designer box unchecked/disabled for this product.
4. On the front end, this product shows a description field + reference
   file upload instead of the live preview. Submitted details and files
   attach to the resulting order for you to review and design manually.

## What shows up on orders

- Each label order stores Compound, Strength, Batch/Lot #, Expiration,
  Size, Media, Accent Color, and Design Notes as order line item details.
- A flattened PNG of the exact label the customer previewed is generated
  automatically and shown as a thumbnail on the order edit screen in
  wp-admin (under that line item) — this is what you print from.
- Custom design request orders show the description and any uploaded
  reference files (linked) on the order edit screen.

## Notes / things to double-check after install

- If the template image is hosted on a different domain than your
  WooCommerce site (e.g. a CDN without CORS headers), the browser may
  block reading canvas pixel data, which prevents generating the saved
  preview PNG. Host template images on the same domain as the store, or
  make sure the image host sends `Access-Control-Allow-Origin` headers.
- Price add-ons for size/media are applied on top of the product's
  regular price at cart calculation time — double check pricing looks
  right on a test order before going live.
- This plugin does not include shipping/tax logic — that stays with
  WooCommerce's normal settings.
