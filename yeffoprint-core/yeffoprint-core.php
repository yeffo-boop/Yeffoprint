<?php
/**
 * Plugin Name: YeffoPrint Core
 * Plugin URI: https://yeffoprint.com
 * Description: Business logic for YeffoPrint — templates, customization schemas, batches/variants, pricing, materials, sizes, custom orders, and proofs. Presentation lives in the yeffoprint theme; this plugin must work under any theme.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: YeffoPrint
 * Author URI: https://yeffoprint.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yeffoprint-core
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'YEFFOPRINT_CORE_VERSION', '0.1.0' );
define( 'YEFFOPRINT_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'YEFFOPRINT_CORE_URL', plugin_dir_url( __FILE__ ) );

require_once YEFFOPRINT_CORE_PATH . 'includes/class-yeffoprint-core.php';

YeffoPrint_Core::instance();
