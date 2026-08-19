<?php namespace TierPricingTable\Addons\GlobalTieredPricing\CPT\Form;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs\ProductAndCategories;
use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs\Quantity;
use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs\Pricing;
use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs\Settings;
use TierPricingTable\Addons\GlobalTieredPricing\CPT\Form\Tabs\UsersAndRoles;
use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use TierPricingTable\Addons\GlobalTieredPricing\GlobalPricingRule;
use TierPricingTable\Core\ServiceContainerTrait;
use WP_Post;

class Form {

	use ServiceContainerTrait;

	/**
	 * Tabs
	 *
	 * @var FormTab[]
	 */
	protected $tabs;

	protected $defaultTab = 'pricing';

	protected $pricingRuleInstance = null;

	public function __construct() {

		add_action( 'init', function () {
			$tabs = array(
					new Pricing( $this ),
					new ProductAndCategories( $this ),
					new UsersAndRoles( $this ),
					new Quantity( $this ),
			);

			$tabs[] = new Settings( $this );

			$this->tabs = apply_filters( 'tiered_pricing_table/global_pricing/form_tabs', $tabs, $this );
		} );

		add_action( 'edit_form_after_title', function ( WP_Post $post ) {
			if ( GlobalTieredPricingCPT::SLUG !== $post->post_type ) {
				return;
			}

			$this->render( $post );
		} );

		new UpgradeTip();
	}

	protected function includeAssets() {
		?>
		<style>
			/**
			* Externals
			 */
			/* do not display any notices on rule creation */
			.wrap .notice:not(.notice-success, .tpt__admin__feedback-discount-banner) {
				display: none
			}

			.tpt-global-pricing-rule-form .woocommerce-help-tip {
				margin-left: 5px;
			}

			.tpt-global-pricing-rule-hint {
				display: flex;
				align-items: center;
				padding: 10px;
				border-left: 3px solid var(--wp-admin-theme-color, #2271b1);
				background: #f6f7f7;
				color: var(--wp-admin-theme-color, #2271b1) !important;
				margin-bottom: 20px;
			}

			.tpt-global-pricing-rule-hint--top-level {
				margin-top: 10px;
				border: 1px solid #888;
			}

			.tpt-global-pricing-rule-hint__icon {
				margin-right: 10px;
			}

			.tpt-global-pricing-rule-form {
				margin: 20px 0;
				display: flex;
				overflow: hidden;
				border-radius: 3px;
				flex-wrap: nowrap;
			}

			.tpt-global-pricing-rule-form__tabs {
				width: 30%;
				max-width: 300px;
				min-width: 250px;
			}

			.tpt-global-pricing-rule-form-tab {
				background: #fff;
				border-bottom: 1px solid #e8e8e8;
				border-left: 1px solid #e8e8e8;
				overflow: hidden;
				cursor: pointer;
				display: flex;
				align-items: center;
				padding: 15px 10px;
			}

			.tpt-global-pricing-rule-form-tab:first-child {
				border-top: 1px solid #e8e8e8;
			}

			.tpt-global-pricing-rule-form-tab:hover:not(.tpt-global-pricing-rule-form-tab--active) {
				background: #fbfbfb;
			}

			.tpt-global-pricing-rule-form-tab--active {
				cursor: default;
				background: #f6f7f7;
			}

			.tpt-global-pricing-rule-form-tab__icon {
				transition: all .1s;
				margin-right: 10px;
				height: 40px;
				aspect-ratio: 1/1;
				border-radius: 50%;
				background: #f6f7f7;
				text-align: center;
				color: var(--wp-admin-theme-color, #2271b1);
				font-size: 20px;
				font-weight: bold;
				display: flex;
				justify-content: center;
				align-items: center;
			}

			.tpt-global-pricing-rule-form-tab--active h3,
			.tpt-global-pricing-rule-form-tab--active div {
				color: var(--wp-admin-theme-color, #2271b1) !important;
			}

			.tpt-global-pricing-rule-form-tab--active .tpt-global-pricing-rule-form-tab__icon {
				background: #fff;
			}

			.tpt-global-pricing-rule-form-tab__title h3 {
				font-size: 1.1em;
				margin: 0;
			}

			.tpt-global-pricing-rule-form-tab__title div {
				margin-top: 5px;
				color: #777;
			}

			.tpt-global-pricing-rule-form-tab-content {
				display: none;
			}

			.tpt-global-pricing-rule-form-tab-content--active {
				display: block;
			}

			.tpt-global-pricing-rule-form__content {
				width: 70%;
				background: #fff;
				flex-grow: 1;
				padding: 10px;
				border: 1px solid #e8e8e8;
				box-shadow: 0 0 8px rgba(0, 0, 0, .1);
			}

			.tpt-global-pricing-rule-form input[type="text"],
			.tpt-global-pricing-rule-form input[type="number"],
			.tpt-global-pricing-rule-form .tiered-pricing-pricing-rules-form-row__inputs {
				width: 75% !important;
			}

			.tpt-global-pricing-rule-form #tiered_pricing_type {
				max-width: 75%;
				width: 75% !important;
			}

			@media screen and (max-width: 1248px) {

				.tpt-global-pricing-rule-form input[type="text"],
				.tpt-global-pricing-rule-form input[type="number"],
				.tpt-global-pricing-rule-form .tiered-pricing-pricing-rules-form-row__inputs {
					width: 100% !important;
				}

				.tpt-global-pricing-rule-form #tiered_pricing_type {
					max-width: 100%;
					width: 100% !important;
				}


				.tpt-global-pricing-rule-form {
					flex-wrap: wrap;
				}

				.tpt-global-pricing-rule-form__tabs {
					display: flex;
					max-width: 100%;
					width: 100%;
				}

				.tpt-global-pricing-rule-form-tab__icon {
					display: none;
				}

				.tpt-global-pricing-rule-form-tab--active {
					border-bottom: 3px solid var(--wp-admin-theme-color, #2271b1);
				}
			}

			@media screen and (max-width: 500px) {
				.tiered-pricing-form-block {
					padding: 5px 20px !important;
				}
			}

			/* Accordion styles */
			.tpt-exclusions-accordion {
				margin-top: 20px;
				background: #fff;
			}

			.tpt-exclusions-accordion summary {
				cursor: pointer;
				list-style: none;
				display: flex;
				align-items: center;
				justify-content: space-between;
				padding: 12px 15px;
				background: #f6f7f7;
				border: 1px solid #e8e8e8;
				font-size: 14px;
				font-weight: 600;
				color: #1d2327;
				transition: background-color 0.2s ease;
			}

			.tpt-exclusions-accordion summary::-webkit-details-marker {
				display: none;
			}

			.tpt-exclusions-accordion summary:hover {
				background: #f0f0f1;
			}

			.tpt-exclusions-accordion[open] summary {
				border-bottom-left-radius: 0;
				border-bottom-right-radius: 0;
				border-bottom: 1px solid #e8e8e8;
			}

			.tpt-exclusions-accordion[open] summary .tpt-accordion-icon {
				transform: rotate(180deg);
			}

			.tpt-accordion-icon {
				transition: transform 0.3s ease;
				color: #787c82;
			}

			.tpt-exclusions-accordion-content {
				padding: 15px 0;
				border: 1px solid #e8e8e8;
				border-top: none;
				border-radius: 0 0 4px 4px;
			}
		</style>
		<script>
			jQuery(document).ready(function () {
				let tabs = jQuery('.tpt-global-pricing-rule-form-tab');
				let tabsContent = jQuery('.tpt-global-pricing-rule-form-tab-content');

				tabs.click(function (e) {
					e.preventDefault();

					tabsContent.removeClass('tpt-global-pricing-rule-form-tab-content--active');
					tabs.removeClass('tpt-global-pricing-rule-form-tab--active');

					jQuery(this).addClass('tpt-global-pricing-rule-form-tab--active');

					const target = jQuery(this).data('target');

					jQuery('#' + target).addClass('tpt-global-pricing-rule-form-tab-content--active');
				});
			});
		</script>
		<?php
	}

	protected function render( WP_Post $post ) {

		$this->includeAssets();

		$rulesCount = (int) wp_count_posts( GlobalTieredPricingCPT::SLUG )->publish;

		if ( $this->isNewRule() && $rulesCount < 1 ) {
			$this->renderHelpingSteps();
		}

		if ( ! $this->isNewRule() && ! $this->getPricingRuleInstance( $post )->isValidPricing() ) {
			$this->tabs[0]->renderHint( __( 'The pricing rule does not affect either prices or product quantity limits. The rule will be skipped.',
					'tier-pricing-table' ), array( 'custom_class' => 'tpt-global-pricing-rule-hint--top-level' ) );
		}

		?>
		<div class="tpt-global-pricing-rule-form">

			<nav class="tpt-global-pricing-rule-form__tabs">
				<?php foreach ( $this->tabs as $tab ) : ?>
					<div class="tpt-global-pricing-rule-form-tab <?php echo esc_attr( $tab->getId() === $this->defaultTab ? 'tpt-global-pricing-rule-form-tab--active' : '' ); ?>"
					     data-target="tpt-global-pricing-rule-form-tab-<?php echo esc_attr( $tab->getId() ); ?>">

						<div class="tpt-global-pricing-rule-form-tab__icon" style="">
							<?php if ( strpos( $tab->getIcon(), 'dashicons-' ) === 0 ) : ?>
								<span class="dashicons <?php echo esc_attr( $tab->getIcon() ); ?>"></span>
							<?php else : ?>
								<span><?php echo esc_html( $tab->getIcon() ); ?></span>
							<?php endif; ?>
						</div>

						<div class="tpt-global-pricing-rule-form-tab__title">
							<h3>
								<?php echo esc_html( $tab->getTitle() ); ?>
							</h3>
							<div><?php echo esc_html( $tab->getDescription() ); ?></div>
						</div>
					</div>
				<?php endforeach; ?>
			</nav>

			<section class="tpt-global-pricing-rule-form__content woocommerce_options_panel">
				<?php foreach ( $this->tabs as $tab ) : ?>
					<div class="tpt-global-pricing-rule-form-tab-content <?php echo esc_attr( $tab->getId() === $this->defaultTab ? 'tpt-global-pricing-rule-form-tab-content--active' : '' ); ?>"
					     id="tpt-global-pricing-rule-form-tab-<?php echo esc_attr( $tab->getId() ); ?>">
						<?php
							$tab->render( $this->getPricingRuleInstance( $post ) );

							do_action( 'tiered_pricing_table/global_pricing/form/tab_end', $tab,
									$this->getPricingRuleInstance( $post ) );
						?>
					</div>
				<?php endforeach; ?>
			</section>
		</div>
		<?php
	}

	/**
	 * Get pricing rule instance
	 *
	 * @param  WP_Post  $post
	 *
	 * @return GlobalPricingRule
	 */
	public function getPricingRuleInstance( WP_Post $post ): GlobalPricingRule {
		if ( empty( $this->pricingRuleInstance ) ) {
			$this->pricingRuleInstance = GlobalPricingRule::build( $post->ID );
		}

		return $this->pricingRuleInstance;
	}

	public function renderHelpingSteps() {
		?>
		<style>
			.tpt-global-pricing-rule-helping {
				background: #ffffff;
				border: 1px solid #e2e4e7;
				border-radius: 8px;
				padding: 30px;
				position: relative;
				margin: 20px 0;
				box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			}

			.tpt-global-pricing-rule-helping__close {
				position: absolute;
				top: 15px;
				width: 32px;
				height: 32px;
				background: #f0f0f1;
				color: #3c434a;
				text-align: center;
				line-height: 32px;
				right: 15px;
				font-weight: 600;
				border-radius: 50%;
				transition: all 0.2s ease;
			}

			.tpt-global-pricing-rule-helping__close:hover {
				background: #dcdcdd;
				cursor: pointer;
				transform: scale(1.05);
			}

			.tpt-global-pricing-rule-helping__header {
				text-align: center;
				margin-bottom: 30px;
			}

			.tpt-global-pricing-rule-helping__title {
				font-size: 22px;
				font-weight: 600;
				color: #1d2327;
				margin-bottom: 20px;
			}

			.tpt-global-pricing-rule-helping__subtitle {
				font-size: 14px;
				color: #646970;
				max-width: 650px;
				margin: 0 auto;
				line-height: 1.5;
			}

			.tpt-global-pricing-rule-helping__steps {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
				gap: 20px;
			}

			.tpt-global-pricing-rule-helping-step {
				background: #ffffff;
				border: 1px solid #e0e0e0;
				border-radius: 8px;
				padding: 24px 20px;
				text-align: center;
				transition: transform 0.2s ease, box-shadow 0.2s ease;
				position: relative;
			}

			.tpt-global-pricing-rule-helping-step:hover {
				transform: translateY(-2px);
				box-shadow: 0 6px 16px rgba(0, 0, 0, 0.06);
				border-color: #c3c4c7;
			}

			.tpt-global-pricing-rule-helping-step__icon {
				width: 52px;
				height: 52px;
				border-radius: 50%;
				background: #e0f0fa;
				color: #0070bc;
				margin: 0 auto 16px;
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 24px;
				font-weight: bold;
			}

			.tpt-global-pricing-rule-helping-step__title {
				font-size: 15px;
				font-weight: 600;
				color: #1d2327;
				margin-bottom: 8px;
			}

			.tpt-global-pricing-rule-helping-step__description {
				font-size: 13px;
				color: #50575e;
				line-height: 1.5;
			}

		</style>
		<script>
			jQuery(document).ready(function () {
				jQuery('.tpt-global-pricing-rule-helping__close').click(function () {
					jQuery(this).closest('.tpt-global-pricing-rule-helping').slideUp(200);
				})
			})
		</script>
		<?php
		$steps = array(
				array(
						'title'       => __( 'Set Custom Pricing', 'tier-pricing-table' ),
						'description' => __( 'Set custom regular and tiered pricing for matching products.',
								'tier-pricing-table' ),
						'icon'        => '$',
				),
				array(
						'title'       => __( 'Select Products', 'tier-pricing-table' ),
						'description' => __( 'Target specific products, categories, tags, or brands. Leave empty to apply store-wide.',
								'tier-pricing-table' ),
						'icon'        => '<span class="dashicons dashicons-archive"></span>',
				),
				array(
						'title'       => __( 'Filter Users', 'tier-pricing-table' ),
						'description' => 'Restrict this pricing to specific customers or user roles.',
						'icon'        => '<span class="dashicons dashicons-admin-users"></span>',
				),
				array(
						'title'       => __( 'Quantity Limits', 'tier-pricing-table' ),
						'description' => __( 'Enforce minimum, maximum, and step increments for purchasing.',
								'tier-pricing-table' ),
						'icon'        => '<span class="dashicons dashicons-database"></span>',
				),
		)
		?>
		<div class="tpt-global-pricing-rule-helping">
			<div class="tpt-global-pricing-rule-helping__close"
			     title="<?php esc_attr_e( 'Dismiss', 'tier-pricing-table' ); ?>">
				&times;
			</div>

			<div class="tpt-global-pricing-rule-helping__header">
				<div class="tpt-global-pricing-rule-helping__title">
					<?php esc_html_e( 'How global pricing rules work', 'tier-pricing-table' ); ?>
				</div>
				<div class="tpt-global-pricing-rule-helping__subtitle">
					<p style="margin: 0 0 6px;">
						<?php
							esc_html_e( 'Global rules enable you to bulk-apply dynamic pricing and quantity limits to selected groups of products and users simultaneously.',
									'tier-pricing-table' );
						?>
					</p>
					<p style="margin: 0;">
						<?php
							echo sprintf( '<strong>%s</strong> %s', esc_html__( 'Note:', 'tier-pricing-table' ),
									esc_html__( 'Depending on your priority settings, global rules may override product-level pricing configurations.',
											'tier-pricing-table' ) );
						?>
					</p>
				</div>
			</div>

			<div class="tpt-global-pricing-rule-helping__steps">
				<?php foreach ( $steps as $index => $step ) : ?>
					<div class="tpt-global-pricing-rule-helping-step">
						<div class="tpt-global-pricing-rule-helping-step__icon">
							<?php echo wp_kses_post( $step['icon'] ); ?>
						</div>

						<div class="tpt-global-pricing-rule-helping-step__title">
							<?php echo esc_html( $step['title'] ); ?>
						</div>

						<div class="tpt-global-pricing-rule-helping-step__description">
							<?php echo esc_html( $step['description'] ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	public function isNewRule(): bool {
		global $pagenow;

		return 'post-new.php' == $pagenow;
	}
}