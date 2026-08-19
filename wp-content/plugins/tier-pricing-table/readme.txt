=== Tiered Pricing Table for WooCommerce ===

Contributors: bycrik, freemius
Tags: woocommerce, tiered pricing, dynamic price, price, wholesale
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 7.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create bulk, wholesale, and quantity-based pricing in WooCommerce with flexible tables, role-based pricing, and powerful upsells.

== Description ==

**Tiered Pricing Table for WooCommerce** lets you create bulk discounts, wholesale pricing, and quantity-based offers to increase average order value.

Easily set different prices based on quantity, user roles, or specific customers — and display them using beautiful, customizable pricing tables, blocks, or dropdowns.

[youtube https://www.youtube.com/watch?v=wRyPr6VQHZM]

[**Live Demo**](https://demo.tiered-pricing.com/) | [**Documentation**](https://tiered-pricing.com/documentation/user/) | [**Contact Us**](https://tiered-pricing.com/contact-us/) | [**Plugin Website**](https://tiered-pricing.com/)

You can apply custom pricing to individual products or categories, all users, specific user roles, or customer accounts.

Fine-tune pricing for any business model — from retail to wholesale — to serve different customer groups effortlessly.

📌 **Key features**:

✅ **Quantity-based pricing (Volume pricing)**
Offer different prices based on the quantity purchased to encourage larger orders.

✅ **Role and Customer based pricing**
Create custom pricing for user roles or individual customers, including quantity-based discounts.

✅ **Minimum, Maximum & Step Quantity Controls**
Define minimum and maximum purchase quantities, and enforce quantity increments.

✅ **Discount-friendly price formatting**
Display pricing in a way that highlights savings, including the lowest price, a price range or even a custom template.

✅ **Request a Quote**
Allow customers to request a quote with customizable form builder and flexible settings.

✅ **Flexible pricing display (product page & catalog)**
 Display tiered pricing in the format that best fits your store:
➖ **Table (5 styles)**
➖ **Blocks (5 styles)**
➖ **Options (4 styles)**
➖ **Dropdown**
➖ **Horizontal Table**
➖ **Plain text**
➖ **Tooltip**
*See screenshots for examples*

The clean interface and powerful functionality allow you to create any pricing strategy without complexity.

⚙️ **Advanced Features**
✅ Import & Export (WP All Import support).
✅ Pricing Labels – Create labels such as “Best Value” or “Most Popular” to make key pricing tiers stand out.
✅ Role Management – Create, edit, and delete user roles with ease.
✅ Built-in Caching – Improve performance with integrated caching.
✅ REST API & Debug Mode – Extend functionality through a REST API and troubleshoot issues with a dedicated debug mode.
✅ Savings Display – Show customers how much they save with customizable messages such as “You Save: $9.99”.

And much more!

💎 **Premium Extras**:

*   Percentage quantity-based discounts
*   Role-based and customer-based pricing (including base prices and min/max order quantity)
*   Role-based tax options (Override tax options for specific user roles)
*   Custom columns for pricing table
*   Option to hide prices and prevent purchasing for non-logged-in users
*   Min/Max order quantity control per product or category/tag/brand
*   Cart upsells (motivates users to purchase more to get a discount)
*   Totals on the product page
*   Clickable tiered pricing
*   Show the lowest price or a range of prices instead of default product price
*   Show the tiered price in the cart as a discount

**Works Seamlessly with 3rd-party plugins**:

⭐  **WP All Import**
⭐  **Elementor**
⭐️  **WPML**
⭐️  **WPML Multicurrency**
⭐️  **WooCommerce Product Add-ons**
⭐️  **Aelia Multicurrency**
⭐️  **Yith Request a Quote**
⭐️  **Request a Quote by Addify**
⭐️  **Product Bundles for WooCommerce**
⭐️  **WOOCS** (WooCommerce Currency Switcher by FOX)
⭐️  **WCCS** (WooCommerce Currency Switcher by WP Experts)
⭐️  **WCPA** (WooCommerce Custom Product Addons)
⭐️  **Product Fields** (Product Addons by StudioWombat)
⭐️  **WooCommerce Deposits**
⭐️  **Mix&Match for WooCommerce**
⭐️  **Yoast and Rank Math SEO plugins**

**Get more information about the [Tiered Pricing Table for WooCommerce](https://tiered-pricing.com/)**

Feel free to **[Contact us](https://tiered-pricing.com/contact-us/)** if you have any questions.

Set up a **[demo](https://demo.tiered-pricing.com/)** to see how the plugin works in action.

== Screenshots ==

1. Tiered Pricing on the product page
2. Set up on the product-level
3. Global Pricing Rules
4. Shop and Catalog prices
5. Role-based Pricing
6. Quantity Limits (min/max/step)
7. Tiered Pricing in the cart and upsells
8. Custom Pricing Labels
9. Mix&Match Pricing Rules
10. Roles Management
11. 3rd-party Integrations

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/tier-price-table` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to **WooCommerce → Settings → Tiered Pricing** to configure the plugin

After installing the plugin, configure it to your needs.

== Frequently Asked Questions ==

= Does this plugin support variable products? =
Yes, tiered pricing works with both simple and variable products.

= Can I apply discounts per category? =
Yes, you can create global pricing rules for categories.

= Does it work with taxes and coupons? =
Yes, the plugin integrates with WooCommerce taxes and coupons.

= What does the import format look like? =

`quantity:price,quantity:price`

For example:
"10:20,20:18" - in this case 20.00$ at 10 pieces, $18.00 at 20 pieces or more.
The exact format is used for the percentage-based rules:
"quantity:discount,quantity:discount"

Please note that you must use a dot as a decimal separator because a comma separates the pricing rules.

You can change the rules separator (in case you use a comma as a decimal separator) using the "tiered_pricing_table/rules_separator" hook.

For example, the following code will change the separator to "&":

add_filter('tiered_pricing_table/rules_separator', function(){
   return '&';
});

= Can I show the tiered pricing using a shortcode? =
Yes! The plugin provides the [tiered-pricing-table] shortcode that can be customized with various attributes.

= Can I show the tiered pricing via Elementor? =

Yes! Look for the "Tiered Pricing Table" widget.

= Can I show the pricing table/pricing blocks/pricing options via Gutenberg block? =

Yes! Look for the "Tiered Pricing" block.

= Can I apply tiered pricing for manual (admin-created) orders? =

Yes!
Each order has the "recalculate with tiered pricing" button, which recalculates the cost according to the tiered pricing rules.

== Changelog ==

= 7.1.5 [2026-08-14] =
* Fix: percentage tiered prices were calculated from a price WooCommerce Subscriptions had already modified, which double-prorated the first payment of synchronised subscriptions and mis-applied sign-up fees and free trials.
* Fix: the prorated cost of a subscription quantity switch ignored the tiered discount, overcharging the customer.

= 7.1.4 [2026-08-13] =
* Fix: cart prices ignored the exchange rate with the WPML Multicurrency integration (regression in 7.1.3).

= 7.1.3 [2026-08-09] =
* New: WooCommerce Subscriptions integration — tiered pricing now applies correctly to products purchased on a subscription plan, in both the initial and recurring totals.
* New: YITH Multi Currency Switcher integration.
* New: WPML/Polylang support for role-based and user-based tiered pricing on product translations.
* Enhance: Global tiered pricing rules now match products, categories, tags and brands across WPML/Polylang translations.
* Fix: Prevented two possible fatal errors — a null product passed into the price filter by third-party code, and re-calculating a manual order whose product had been deleted.
* Fix: A 100%-off tier could be charged at full price when re-calculating manual orders.
* Fix: WCCS, YITH and WPML currency switchers could convert percentage-based tiered prices twice.
* Fix: Product add-on costs were dropped from the cart price when a currency switcher was active.
* Fix: Division-by-zero warning in the WooCommerce Deposits integration.
* Fix: SEO structured data (Rank Math, Yoast, SEOPress) declared more offers than it output; the SEOPress price-range tag showed the lowest price instead of a range.

= 7.1.2 [2026-08-06] =
* Enhance: Product Bundles integration for manually created orders.
* Fix: WPML Multicurrency rounding rules were not applied to tiered prices.
* Fix: Tiered price in the cart was converted twice by currency switchers after decreasing the quantity below the first tier.
* Fix: Prices with taxes for the products without tiered pricing rules.

= 7.1.1 [2026-07-29] =
* Fix: frontend JS issue with totals.

= 7.1.0 [2026-07-27] =
* New: Mix&Match minimum order quantity for variable products.
* New: Woo Payment Multicurrency integration.
* Enhance: Move role-based and customer-based pricing to a separate tab.
* Enhance: Settings texts and descriptions updated.
* Fix: Minor fixes and improvements.

= 7.0.1 [2026-07-20] =
* Fix: Frontend script cache issue.

= 7.0.0 [2026-07-18] =
* New: Request a Quote functionality.
* New: Totals & "You save" feature for the products without tiered pricing rules.
* Enhance: Dropdown accessibility.

= 6.5.0 [2026-07-02] =
* New: Product level customer based pricing.
* New: Custom template for pricing formatting on the shop page.
* Enhance: CSS improvements.

= 6.4.1 [2026-07-01] =
* Fix: MCE JS issue in some languages.
* Enhance: tier labels and custom columns friendly UI.
* Enhance: Welcome page updated.
* Enhance: Compact layout for all pricing templates.
* Enhance: Import/Export as a module and can be deactivated.

= 6.4.0 [2026-06-21] =
* New: 4 new styles for pricing table.
* Enhance: custom columns work for horizontal table template.
* Enhance: Almost everything is a module: you can disable the features you do not need.
* Enhance: CSS is now minimized.
* Enhance: Cart upsells work for block based cart and checkout.

= 6.3.0 [2026-06-20] =
* New: Role-based tax options.
* Update: Translations for 10 most popular languages.
* Update: Frontend script improvements.
* Update: Minor improvements and fixes.

= 6.2.0 [2026-06-09] =
* New: Percentage discount for "You save" template.
* New: Tags and Brands for global pricing rules.
* New: Duplicate global pricing rule action.
* Fix: Fix UI with WordPress 7.0.
* Update: Use WordPress color theme for the plugin UI.
* Update: Minor improvements and fixes.

= 6.1.0 [2026-05-17] =
* New: Introducing "Tools" - manage user roles and clean up tiered pricing data.
* Update: Welcome page updated.
* Update: Minor improvements and fixes.

= 6.0.1 [2026-05-11] =
* Fix: Showing price range caused memory leak.

= 6.0.0 [2026-05-09] =
* New: Tier labels! Create custom labels for each pricing tier.
* New: Integration with SEOPress plugin.
* Update: Multiple improvements and fixes.
* Update: Minimum PHP version is 7.4.

= 5.6.2 [2026-03-26] =
* Update: Freemius SDK updated to the latest version.

= 5.6.1 [2026-03-25] =
* Fix: WooCommerce role-based import - do not require all fields to be present in the import file.
* Fix: Quick edit action.

= 5.6.0 [2026-03-18] =
* Update: WooCommerce & WordPress compatibility bumped.
* Update: Import/Export for role-based pricing rules.
* Fix: Notice on PHP 8.5 and above.

= 5.5.1 [2025-11-26] =
* Update: Freemius SDK updated.
* Update: WooCommerce & WordPress compatibility bumped.
* Update: Black Friday and feedback banners.
* Fix: (WCCS) by WP Experts integration fix.
* Fix: Manual orders rounding fix.

= 5.5.0 [2025-11-07] =
* New: Three new styles for pricing options.
* Update: Freemius SDK updated.
* Update: WooCommerce & WordPress compatibility bumped.

= 5.4.1 [2025-08-11] =
* New: Two new styles for pricing blocks.
* Fix: Fix issue with the PHP 8.2 and above.

= 5.4.0 [2025-07-29] =
* New: Rank Math and Yith SEO integration.
* Update: Improvements for formatting prices in the product catalog.
* Update: Freemius SDK to the latest version.
* Fix: Minor issues with the latest WooCommerce version.
* Update: WooCommerce compatibility to 10.1.0.

= 5.3.0 [2025-06-30] =
* New: Variable product cache - preload tiered pricing if there are less than 10 variations.
* Fix: If a coupon is applied only to a specific products, "disable tiered pricing" option affected the whole cart.
* Fix: Plain text template - warning with the latest PHP versions.
* Update: Bump WooCommerce compatibility to 10.0.0.

= 5.2.0 [2025-06-16] =
* New: Always use regular price to show a crossed-out price in the cart.
* Update: CSS and texts updates.
* Fix: Minor issues.

= 5.1.10 [2025-05-16] =
* Update: Freemius SDK to the latest version.
* Update: Texts over the plugin.
* New: Bulk Price Editor plugin suggestion.
* Fix: Settings floating title issue with the latest WooCommerce version.

= 5.1.9 [2025-05-07] =
* Fix: WP All Import integration translation issue.
* Fix: Woombat Product Addons integration fix.

= 5.1.8 [2025-04-16] =
* New: WCP Product Bundles integration.
* Fix: Minor issues.
* Update: WooCommerce & WordPress compatibility.

= 5.1.7 [2025-02-21] =
* New: CURCY compatibility.
* Update: Yith RaQ integration.
* Update: WPML config.

= 5.1.6 [2025-02-08] =
* New: Do not reload pricing table for variable product when all prices are the same.
* Update: Freemius SDK to the latest version.

= 5.1.5 [2025-01-06] =
* New: Welcome page.
* New: Unit label variable for pricing templates.
* Update: Freemius SDK to the latest version.
* Update: Declared compatibility with the latest WP and WC versions.
* Fix: Non-logged-in service.

= 5.1.4 [2024-12-05] =
* New: New template for the totals on the product page.
* Enhance: Speed optimization.
* Enhance: Notice when the free version is active but the premium version is available.
* Update: Minimum required characters to find products and categories in the global pricing rules set to 1.
* Update: Promotion banners updated.
* Update: Minor improvements and fixes.

= 5.1.3 [2024-10-22] =
* Fix: Global pricing rules issue.

= 5.1.2 [2024-10-20] =
* Update: Update Freemius SDK to the latest version of 2.9.0.

= 5.1.1 [2024-10-20] =
* Enhance: Minor improvements.

= 5.1.0 [2024-10-19] =
* New: Priority options for global pricing rules.
* New: Redesign global pricing rules form.
* Fix: Maximum order quantity in the cart.
* Enhance: Additional tips over the plugin.

= 5.0.4 [2024-10-06] =
* New: Two additional layouts for blocks.
* Enhance: Additional tips over the plugin.
* Enhance: Minor improvements.

= 5.0.3 [2024-09-27] =
* Fix: Multiple quantity fields on the product page.
* Fix: WOOCS integration.
* Update: Freemius updated to the latest version of 2.8.1.
* Enhance: Custom columns form updates.
* Enhance: Global pricing rules: make the form responsive.
* Enhance: Minor improvements.

= 5.0.2 [2024-09-10] =
* Update: Minor fixes and improvements.

= 5.0.1 [2024-08-19] =
* Update: Minor fixes and improvements.

= 5.0.0 [2024-08-14] =
* New: Show tiered pricing block in the product catalog.
* New: Compatibility with the new WooCommerce react-based product editor.
* New: New API for the tiered pricing fields.
* New: Integration with Addify Request a Quote plugin.
* Update: Freemius updated to the latest version of 2.7.3.
* Update: Frontend script updated.
* Update: New hooks added.
* Update: Removed the legacy hooks support.
* Update: WooCommerce & WordPress compatibility.
* Fix: Plaintext template variables issue.
* Fix: Custom columns: total column always shows the price with taxes.
* Fix: Options template: do not show "total" label in the free version.

= 4.3.3 [2024-07-08] =
* Fix: Do not show crossed-out total in "options" template if there are no discounts.

= 4.3.2 [2024-07-02] =
* Fix: Wombat product addons (free) integration.
* Fix: Price formatting for some 3rd-party plugins that use AJAX to update loop.
* Update: Freemius updated to the latest version of 2.7.3.
* Update: Minor improvements.
* Update: WooCommerce & WordPress compatibility.

= 4.3.1 [2024-06-17] =
* Fix: Error when the plugin is deactivated and items with tiered pricing are in the cart.

= 4.3.0 [2024-06-14] =
* New: Non-logged-in users options: hide prices and prevent purchasing.
* New: Prevent premium version be used without a valid license.
* Fix: Tiered pricing in the cart&checkout blocks.
* Fix: Types warnings on PHP 8.0 or above.
* Fix: Wombat product addons integration.
* Update: WooCommerce & WordPress compatibility.

= 4.2.4 [2024-05-10] =
* New: Integration with Global Pricing rules for woocommerce: do not apply tiered pricing on the free items.
* Fix: Tiered pricing in the cart&checkout blocks.
* Fix: Types warnings on PHP 8.0 or above.
* Fix: Maximum and group of quantity for variations.
* Update: WooCommerce & WordPress compatibility.

= 4.2.3 [2024-03-25] =
* New: Notice about cart&checkout blocks for the upsells feature.
* Fix: WP All Import running via CLI.
* Fix: Yith Request a Quote integration.

= 4.2.2 [2024-02-08] =
* New: Wombat product addons integration.
* New: New fields to import for WP All Import integration.
* Fix: CSS fixes.

= 4.2.1 [2024-01-29] =
* Fix: Allow set 0 quantity in the cart.
* Fix: Return default template to the product advanced options.

= 4.2.0 [2024-01-24] =
* New: New type of displaying - plain text.
* New: Discounts notifications.
* Fix: Empty price on variable products with the same price.
* Fix: Cache dependency.
* Update: REST API updated.

= 4.1.0 [2023-12-19] =
* New: New type of displaying - horizontal table.
* New: Show cart item subtotal as a discount.
* New: Excluding products\users for global pricing rules.
* New: Choose how to apply percentage discount: on sale or regular price.
* Update: Updated WPML.config to recognize "you save" template.

= 4.0.7 [2023-11-27] =
* Update: Removed "product has no rules" option.
* Fix: Issue when premium and free version are both activated.
* Fix: Case when +/- buttons on quantity field may not work correctly in some themes.

= 4.0.6 [2023-11-20] =
* New: Increase performance for the variable products: do not check if child have tiered pricing.
* Update: Move freemius init function to the main plugin file.
* Fix: Saving global pricing rule - save pricing type (Individual or Mix&Match).

= 4.0.5 [2023-11-13] =
* Fix: Issue when comma used as a thousand separator.

= 4.0.4 [2023-11-10] =
* Fix: Cache issues.
* Fix: Free version limits.

= 4.0.3 [2023-11-03] =
* Fix: Global rules mix and match pricing strategy.

= 4.0.2 [2023-11-03] =
* Fix: Percentage discount calculations in templates for fixed pricing rules.

= 4.0.1 [2023-11-03] =
* Fix: Tiered fixed price cannot be higher than 99.

= 4.0.0 [2023-11-02] =
* New: New global pricing rules form.
* New: Maximum and "group of quantity" quantity options.
* New: Percentage discounts for regular prices for role-based pricing rules.
* New: Gutenberg blocks for tiered pricing.
* New: Unit label per product.
* New: Custom columns for pricing table.
* New: "You save" feature.
* New: Notice when tiered pricing is set incorrectly.
* New: Debug mode.
* New: Minimum PHP version is 7.2.
* New: Yith request a quote integration.
* New: Calculation logic settings.
* Update: Codebase redesign.
* Update: Settings page updated.
* Update: Redesigned tiered pricing for manual orders.
* Update: Cache and performance updates.
* Fix: A bunch of minor issues.

= 3.6.2 [2023-09-08] =
* Fix: WPML Multicurrency integration fatal error.

= 3.6.1 [2023-09-07] =
* Fix: WPML Multicurrency integration issue.

= 3.6.0 [2023-09-06] =
* Fix: Cart upsells.
* Fix: Rounding issue.
* Fix: Minimum order quantity - do not remove item from cart if the qty is less than minimum. Adjust qty instead.
* New: WP Multicurrency integration.
* New: Rebuilt integrations tab.

= 3.5.1 [2023-07-05] =
* Fix: Clickable pricing for variable products.
* Fix: Pull right pricing when variation is specified in URL.
* Fix: CSS for dropdown.

= 3.5.0 [2023-06-30] =
* New: New type of displaying - dropdown.
* Fix: Issue when regular prices is replaces by 1$.
* Fix: Upsell {tp_actual_discount} variable.

= 3.4.3 [2023-06-20] =
* New: Integration with WCCS.
* Fix: Coupons potential error.
* Fix: Displaying price with taxes on product page.

= 3.4.2 [2023-05-25] =
* New: HPOS support.
* Fix: Minimum order quantity issue for user roles.
* Fix: Rounding price hook.

= 3.4.1 [2023-04-11] =
* Fix: Fix default variations.

= 3.4.0 [2023-03-30] =
* New: Cache: performance increased for large variable products.
* New: Advanced settings for products: select default variation, mark products that does not use tiered pricing.
* New: Quantity measurement fields in the settings.
* Fix: Fix role based rules for manual orders.
* Fix: Fix taxes for manual orders.

= 3.3.5 [2023-03-21] =
* New: Freemius SDK updated to 2.5.5.
* New: Support "woocommerce_price_trim_zeros" hook.
* New: Support role-based rules for manual orders.
* New: New hook to override the rules separator during the import.
* Fix: WCPA integration.

= 3.3.4 [2023-03-07] =
* Fix: Critical MOQ issue with variable products.

= 3.3.3 [2023-03-06] =
* Fix: Legacy hooks infinity loop.
* Fix: MOQ custom add to cart handlers.
* New: Extended WPML config.
* New: New hook for formatting variation prices.

= 3.3.2 [2023-03-01] =
* Fix: Show tiered pricing via shortcode/elementor widget even if the global display option is disabled.
* Fix: Saving percentage tiered pricing rules for variation.
* New: Show parent category for selected category.
* New: Added more legacy hooks.
* New: Make MOQ validation string translatable.

= 3.3.1 [2023-01-26] =
* Fix: Tooltip layout.
* Fix: Discount calculations on tiered pricing layouts.
* Fix: Do not run frontend script on product that does not have tiered pricing.
* New: Legacy hooks.

= 3.3.0 [2023-01-18] =
* New: Supports {price_excluding_tax} and {price_including_tax} price suffix variables.
* New: Showing discounted total price with original total crossed out.
* New: Cache for price manager.
* New: Trial button.
* Fix: Move to tiered_pricing_table/price/pricing_rule hook.

= 3.2.0 [2023-01-13] =
* New: Cart upsell.
* Fix: CSS issues.
* Fix: Typos.

= 3.1.1 [2023-01-10] =
* New: Notice with global rules on tiered pricing tab.
* Fix: Issue with global pricing rules.
* Fix: Price without taxes issue.
* Fix: Typos.

= 3.1.0 [2023-01-07] =
* New: New way to display the tiered pricing - options.
* New: Tiered pricing template can be selected per product.
* New: Little enhancements.
* Fix: Firefox JS issue.
* Fix: Hidden "quick-edit" for products.

= 3.0.1 [2023-01-02] =
* Fix: Default variation table.
* Fix: Manual orders are active by default (unable to change order total for admin-made orders).

= 3.0.0 [2022-12-29] =
* New: Refactoring the plugin structure.
* New: Refactoring the frontend script.
* New: Global Tiered Pricing rules.
* New: Tiered Pricing Blocks.
* New: Elementor integration.
* New: Settings redesign (added sections, many new settings, refactoring settings script).
* New: Discount column for fixed rules.
* New: Tiered Pricing shortcode.
* New: Tiered Pricing coupons management.
* New: WOOCS integration.
* Fix: Double pricing suffix on simple products.
* Fix: Minor bugs.

= 2.8.2 [2022-10-12] =
* Fix: Premium upgrading.
* Fix: WCPA Integration.

= 2.8.1 [2022-09-23] =
* New: Aelia Multicurrency Integration.
* New: WCPA Integration.
* New: WooCommerce Bundles Integration.
* New: Role-based rules for API.
* New: Support role-based rules in WooCommerce Import.
* New: New Hooks.
* Fix: Catalog prices.
* Fix: Bugs fixes & minor improvements.

= 2.8.0 [2022-05-29] =
* New: REST API.
* New: WordPress 6.0 support.
* New: WooCommerce 6.6 support.
* Fix: Bugs fixes & minor improvements.

= 2.7.0 [2022-04-25] =
* New: Static quantities for the pricing table.
* New: Pricing cache for variable products.
* New: WP All Import: "tiered pricing" import option.
* Fix: Bugs fixes & minor improvements.

= 2.6.1 [2022-03-04] =
* Fix: Security fix.
* Fix: WooCommerce Subscription variable products support.
* Enhance: Minor improvements.

= 2.6.0 [2021-10-24] =
* Fix: Minor bugs.
* Update: WPML extended support.

= 2.5.0 [2021-08-09] =
* Update: Freemius update.
* Fix: Bugs fixes.
* Enhance: Performance improvements.
* Enhance: Improved role-based pricing.
* Update: WPML support.

= 2.4.1 [2020-12-22] =
* Update: Freemius update.
* Fix: Bugs fixes.
* Enhance: Minor improvements.

= 2.4.0 [2020-09-19] =
* Update: Role-based pricing for the premium version.
* Fix: Bug fixes.
* Enhance: Minor improves.

= 2.3.7 [2020-04-22] =
* Fix: Addon fixes.
* Fix: Price Suffix fix.
* Enhance: Minor improves.

= 2.3.6 [2020-03-17] =
* Fix: WooCommerce 4 variations fix.

= 2.3.5 [2020-02-17] =
* Fix: Fix issues.
* Update: Category tiers in the premium version.

= 2.3.4 [2020-02-08] =
* Fix: Fix Ajax issues.
* Fix: Fix assets issues.

= 2.3.3 [2019-11-27] =
* Fix: Fix tax issue.
* New: Added ability to calculate the tiered price based on all variations.
* New: Added ability to set bulk rules for variable product.
* New: Added support minimum quantity in the PREMIUM version.
* New: Added summary table in PREMIUM version.
* Fix: Minor fixes.
* Fix: Fixes for the popular themes.

= 2.3.2 [2019-10-28] =
* Fix: Fix upgrading.

= 2.3.1 [2019-09-16] =
* Fix: Fix the jQuery issue.

= 2.3.0 [2019-07-19] =
* Fix: Fix critical bug.

= 2.2.3 [2019-07-15] =
* Fix: Fixed bugs.
* New: Added hooks.

= 2.2.1 [2019-06-04] =
* Fix: Fixed bugs.
* New: Added total price feature.

= 2.2.0 [2019-05-07] =
* New: Added Import\Export tiered pricing.
* Update: Clickable quantity rows (Premium).
* Fix: Fix with some themes.
* Fix: Fix the mini-cart issue.

= 2.1.2 [2019-04-04] =
* Fix: Fixes.
* Update: Trial mode.

= 2.1.1 [2019-03-26] =
* Fix: Fixes.
* Update: Premium variable catalog prices.

= 2.1.0 [2019-03-24] =
* Update: Support taxes.
* Update: Do not show the table head if column titles are blank.
* Fix: Fix Updater.
* Fix: Fix little issues.

= 2.0.2 [2019-03-18] =
* Fix: Fix JS calculation prices.
* Update: Remove the table from variation tier tables.

= 2.0.0 [2019-03-18] =
* Fix: Fix bugs.
* Update: JS updating prices on the product page.
* Update: Tooltip border.
* Update: Premium version.

= 1.1.0 [2019-01-20] =
* Fix: Fix bug with comma as a thousand separators.
* Update: Minor updates.

= 1.0.0 [2018-08-28] =
* Update: Initial Release.