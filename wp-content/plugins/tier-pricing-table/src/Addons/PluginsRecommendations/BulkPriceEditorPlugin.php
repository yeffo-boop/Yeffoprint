<?php namespace TierPricingTable\Addons\PluginsRecommendations;

use TierPricingTable\Admin\Tips\Tip;

class BulkPriceEditorPlugin extends Tip {
	
	public function __construct() {
		parent::__construct();
		
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render' ), - 10 );
		
		add_filter( 'tiered_pricing_table/admin/tips/get_tip_by_slug', function ( $tip, $slug ) {
			if ( $slug === $this->getSlug() ) {
				return $this;
			}
			
			return $tip;
		}, 10, 2 );
	}
	
	public function render() {
		
		if ( $this->isSeen() ) {
			return;
		}
		
		$activationDate = \TierPricingTable\TierPricingTablePlugin::getPluginActivationDate();
		if ( ! $activationDate || ( time() - $activationDate ) < ( WEEK_IN_SECONDS * 2 ) ) {
			return;
		}
		
		?>
		<div class="">
			<div class="tiered-pricing-tip"
				 style="margin: 12px; padding: 10px; background: #fafafa; border: 1px solid #eeeeee; display: flex; gap: 10px; justify-content: space-between">
				<div style="display:flex; gap: 10px; ">
					<div style="color: #2272b1; margin: 0 5px;">
						<span class="dashicons dashicons-admin-post"></span>
					</div>
					<div>

						<div>
							<strong>
								<?php esc_html_e( 'Tip', 'tier-pricing-table' ); ?>:
							</strong>
							You can update the prices of multiple products at once with the
							<a target="_blank"
							   href="https://price-editor.com">
								<b>Bulk Price Editor plugin</b>
							</a>
						</div>

						<div style="margin-top: 10px;">
							<ul>
								<li>✔️ Add or remove <b>tiered prices</b> in bulk</li>
								<li>✔️ Increase or decrease prices by a certain percentage</li>
								<li>✔️ Add or subtract a fixed amount from prices</li>
							</ul>
						</div>

						<div style="margin-top: 10px; display: flex; align-items: center; gap:15px;">

							<a target="_blank"
							   class="button"
							   href="https://wordpress.org/plugins/bulk-price-editor-for-woocommerce/">
								<?php esc_html_e( 'Check plugin', 'tier-pricing-table' ); ?>
							</a>

							<a target="_blank"
							   href="https://www.youtube.com/watch?v=nPA4j53w0iw">
								<?php esc_html_e( 'View video', 'tier-pricing-table' ); ?>
								<svg style="
							width: 0.8rem;
							height: 0.8rem;
							stroke: currentColor;
							fill: none;"
									 xmlns='http://www.w3.org/2000/svg'
									 stroke-width='10' stroke-dashoffset='0'
									 stroke-dasharray='0' stroke-linecap='round'
									 stroke-linejoin='round' viewBox='0 0 100 100'>
									<polyline fill="none" points="40 20 20 20 20 90 80 90 80 60"/>
									<polyline fill="none" points="60 10 90 10 90 40"/>
									<line fill="none" x1="89" y1="11" x2="50" y2="50"/>
								</svg>
							</a>
						</div>
					</div>
				</div>

				<div style="white-space: nowrap;">
					<a role="button" href="<?php echo esc_attr( $this->getMarkAsSeenURL() ); ?>"
					   class="tiered-pricing-tip-close-button">
						&times; <?php esc_html_e( 'Hide this tip', 'tier-pricing-table' ); ?>
					</a>
				</div>
			</div>
		</div>
		<?php
	}
	
	public function getSlug(): string {
		return 'bulk_price_editor_recommendation';
	}
}
