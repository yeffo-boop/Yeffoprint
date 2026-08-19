<?php namespace TierPricingTable\Addons\PluginsRecommendations;

class ConditionalLogicForProductAddons {

	public function __construct() {

		add_action( 'init', function () {

			if ( class_exists( 'MeowCrew\AddonsConditions\AddonsConditionsPlugin' ) ) {
				return;
			}

			add_action( 'woocommerce_product_addons_panel_before_options', array(
				$this,
				'renderConditionalBlock',
			), 10, 3 );

			add_action( 'admin_footer', function () {
				?>
				<script>
					jQuery(document).on('change', '.wc-pao-addon-conditions-suggestment-enabled', (function (event) {
						const checkbox = jQuery(event.target);
						const conditionsContainer = checkbox.closest('.wc-pao-row-conditions-suggestment-settings').find('.wc-pao-addon-conditions-suggestment');

						checkbox.is(':checked') ? conditionsContainer.show() : conditionsContainer.hide();
					}));
				</script>
				<?php
			} );
		} );
	}

	public function renderConditionalBlock( $post, $currentAddon, $loop ) {
		?>
		<div class="wc-pao-addons-secondary-settings">

			<div class="wc-pao-row wc-pao-row-conditions-suggestment-settings">
				<label for="wc-pao-addon-conditions-suggestment-enabled-<?php echo esc_attr( $loop ); ?>">

					<input type="checkbox"
						   id="wc-pao-addon-conditions-suggestment-enabled-<?php echo esc_attr( $loop ); ?>"
						   class="wc-pao-addon-conditions-suggestment-enabled"/>

					<?php esc_html_e( 'Conditional logic', 'tier-pricing-table' ); ?>
				</label>

				<div class="wc-pao-addon-conditions-suggestment" style="display:none;">
					<div>
						<p style="font-size: 13px; background: #f5f5f5; padding: 15px 10px;">
							<?php
							esc_html_e( 'Looking to provide condition logic for your add-ons?', 'tier-pricing-table' );
							?>
							<a target="_blank"
							   href="https://wordpress.org/plugins/conditional-logic-for-woo-product-add-ons/">
								<?php esc_html_e( 'Check out this page.', 'tier-pricing-table' ); ?>
							</a>
						</p>
					</div>
				</div>
			</div>
		</div>
		<?php

	}
}