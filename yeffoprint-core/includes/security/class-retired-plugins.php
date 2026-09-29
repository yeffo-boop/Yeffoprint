<?php
/**
 * Switches off plugins that were only ever meant to be temporary, once —
 * from the Dose Tracker security audit (Jeff, 2026-09-29: go ahead and
 * deactivate YeffoPrint Migrate). Its own description says it should be
 * deactivated once the server move was done; left on, it's one more
 * admin tool that can export every customer account.
 *
 * Runs a single time per plugin (remembered in an option), so turning
 * it back on in Plugins later sticks.
 */

defined( 'ABSPATH' ) || exit;

class YeffoPrint_Retired_Plugins {

	private const DONE_OPTION = 'yeffoprint_retired_plugins_done';

	private const PLUGINS = [
		'yeffoprint-migrate/yeffoprint-migrate.php',
	];

	public function __construct() {
		add_action( 'init', [ $this, 'maybe_deactivate' ] );
	}

	public function maybe_deactivate(): void {
		$done = (array) get_option( self::DONE_OPTION, [] );
		$todo = array_diff( self::PLUGINS, $done );
		if ( ! $todo ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		foreach ( $todo as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				deactivate_plugins( $plugin, true );
			}
		}
		update_option( self::DONE_OPTION, array_values( array_merge( $done, $todo ) ), false );
	}
}
