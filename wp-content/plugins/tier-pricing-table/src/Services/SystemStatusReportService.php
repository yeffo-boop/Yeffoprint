<?php namespace TierPricingTable\Services;

/*
 * Class Status
 *
 * @package TierPricingTable/Services
 */

use TierPricingTable\Admin\Tips\Tip;
use TierPricingTable\Admin\Tips\TipsManager;
use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\TierPricingTablePlugin;

class SystemStatusReportService {
	
	use ServiceContainerTrait;
	
	public function __construct() {
		add_action( 'woocommerce_system_status_report', array( $this, 'addReport' ) );
	}
	
	public function addReport() {
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
			<tr>
				<th colspan="3" data-export-label="Tiered Pricing Table">
					<h2><?php esc_html_e( 'Tiered Pricing Table', 'tier-pricing-table' ); ?></h2>
				</th>
			</tr>
			</thead>
			<tbody>
			<tr>
				<td data-export-label="Tiered Pricing: Activation date">
					<?php esc_html_e( 'Activation date', 'tier-pricing-table' ); ?>:
				</td>

				<td>
					<?php
						$time = TierPricingTablePlugin::getPluginActivationDate();
						
					if ( ! $time ) {
						echo '----';
					}
						$activationDate = gmdate( 'Y-m-d H:i:s', $time );
						$diff           = human_time_diff( time(), $time );
						
						echo esc_html( $activationDate . " ($diff)" );
					?>
				</td>
			</tr>
			<tr>
				<td data-export-label="Tiered Pricing: Seen notifications">
					<?php esc_html_e( 'Seen notifications', 'tier-pricing-table' ); ?>:
				</td>

				<td>
					<?php
						$seenNotifications = $this->getContainer()->getNotificationManager()->getSeenNotifications();
						
						echo esc_html( implode( ', ', array_keys( $seenNotifications ) ) );
					?>
				</td>
			</tr>
			<tr>
				<td data-export-label="Tiered Pricing: Tips hidden">
					<?php esc_html_e( 'Tips hidden', 'tier-pricing-table' ); ?>:
				</td>

				<td>
					<?php
						echo esc_html( implode( ', ', Tip::getSeenTips() ) );
					?>
				</td>
			</tr>
			</tbody>
		</table>
		<?php
	}
	
}