<?php
/**
 * Admin-only visibility check for features still being finished before
 * a full launch — direct request: "I don't want to release all of these
 * new features until I'm sure they're ready... Customers should see a
 * coming soon page unless they're logged in as an admin." Currently used
 * by blocks/label-designer-choice (the Label Designer canvas option on
 * the Custom Design page).
 *
 * The 3 prebuilt product-label Templates + the Product Type gallery
 * filter use a different, code-free mechanism instead (WordPress's own
 * `private` post status — see docs/ARCHITECTURE.md) and don't need
 * anything from this class.
 *
 * `manage_options` matches the capability the admin app itself already
 * gates on (class-admin-app-shortcut.php) — one convention for "is this
 * an admin" everywhere in the plugin, not a second one invented here.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Feature_Gate {

	public static function is_admin_viewer(): bool {
		return current_user_can( 'manage_options' );
	}
}
