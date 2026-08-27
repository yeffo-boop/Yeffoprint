<?php
/**
 * Plugin Name: YeffoPrint Migrate
 * Plugin URI: https://yeffoprint.com
 * Description: Migration tooling for YeffoPrint. Two capabilities: (1) an admin-page tool for selectively moving WooCommerce settings, order history, and user accounts from an old YeffoPrint site to a different new one, and (2) a WP-CLI total database + media backup/restore for moving this exact site to a new server. Deliberately separate from yeffoprint-core — this is migration tooling, not part of the site's permanent architecture, and is meant to be deactivated (or removed) once a migration is complete.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: YeffoPrint
 * Author URI: https://yeffoprint.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yeffoprint-migrate
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'YEFFOPRINT_MIGRATE_VERSION', '1.0.0' );
define( 'YEFFOPRINT_MIGRATE_PATH', plugin_dir_path( __FILE__ ) );
define( 'YEFFOPRINT_MIGRATE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Scope, on purpose, per direct request: WooCommerce settings, order
 * history, and user accounts — nothing else. No products, no theme/
 * site settings, no yeffoprint-core content types (Templates,
 * Materials, Custom Orders, Proofs, Saved Designs). Two real
 * consequences of that boundary, both accepted deliberately rather
 * than silently:
 *
 * 1. An imported order's line items keep their own frozen name/price/
 *    quantity (WooCommerce always stores these on the line item, not
 *    just as a live product reference), so order history still reads
 *    correctly — but each item's product_id/variation_id will not
 *    resolve to a real product on the new site, since products never
 *    migrate. "View product" links on an old order will 404 or point
 *    at whatever unrelated product happens to hold that ID now.
 * 2. An order originally linked to a yp_custom_order (Fully Custom
 *    Design) record keeps that link's ID in its meta, but the linked
 *    record itself never migrates — its "Reorder"/proof-download links
 *    will dangle. Regular Template-based label orders (the majority)
 *    carry no such reference and are unaffected.
 *
 * Requires WooCommerce active on both the export and import site (the
 * order/settings APIs this plugin calls are WooCommerce's own). Does
 * NOT require yeffoprint-core to be active — order/user meta is
 * copied verbatim as opaque key/value pairs, including yeffoprint-
 * core's own `_yp_*` keys, without needing that plugin's classes to
 * interpret them.
 */

require_once YEFFOPRINT_MIGRATE_PATH . 'includes/class-file-store.php';
require_once YEFFOPRINT_MIGRATE_PATH . 'includes/class-settings-migrator.php';
require_once YEFFOPRINT_MIGRATE_PATH . 'includes/class-users-migrator.php';
require_once YEFFOPRINT_MIGRATE_PATH . 'includes/class-orders-migrator.php';
require_once YEFFOPRINT_MIGRATE_PATH . 'includes/class-admin-page.php';
require_once YEFFOPRINT_MIGRATE_PATH . 'includes/class-ajax-controller.php';

add_action( 'plugins_loaded', function () {
	new YeffoPrint_Migrate_Admin_Page();
	new YeffoPrint_Migrate_Ajax_Controller();
} );

register_activation_hook( __FILE__, [ 'YeffoPrint_Migrate_File_Store', 'protect_storage_dir' ] );

/**
 * A second, unrelated migration capability living in this same plugin
 * (see class-cli-backup-command.php's own docblock for why it belongs
 * here rather than duplicating this plugin, or living in yeffoprint-core):
 * a full database + wp-content/uploads backup/restore for moving this
 * exact site to a new server, as opposed to the selective settings/
 * users/orders transplant above. WP-CLI only — `wp yeffoprint-migrate
 * backup export|import`.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once YEFFOPRINT_MIGRATE_PATH . 'includes/class-cli-backup-command.php';
	( new YeffoPrint_Migrate_CLI_Backup_Command() )->register();
}
