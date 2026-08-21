<?php namespace TierPricingTable\Addons\Tools;

use TierPricingTable\Settings\Settings;

class AdminUserHooks {

	public function __construct() {
		add_action( 'admin_footer-user-new.php', array( $this, 'addRolesManagementLink' ) );
		add_action( 'admin_footer-user-edit.php', array( $this, 'addRolesManagementLink' ) );
	}

	public function addRolesManagementLink() {

		$url  = admin_url( 'admin.php?page=wc-settings&tab=' . Settings::SETTINGS_PAGE . '&section=tools' );
		$text = __( 'Manage Roles', 'tier-pricing-table' );
		?>
		<script>
			jQuery(document).ready(function ($) {
				var $roleSelect = $('#role');
				if ($roleSelect.length) {
					$roleSelect.after('&nbsp;<a href="<?php echo esc_url( $url ); ?>" style="display: inline-block; margin-left: 5px;"><?php echo esc_html( $text ); ?></a>');
				}
			});
		</script>
		<?php
	}
}
