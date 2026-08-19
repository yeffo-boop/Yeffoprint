<?php

namespace TierPricingTable\Admin\ProductPage;

use TierPricingTable\Core\ServiceContainerTrait;
use TierPricingTable\PriceManager;
use TierPricingTable\Forms\MinimumOrderQuantityForm;
use TierPricingTable\Forms\TieredPricingRulesForm;
use TierPricingTable\TierPricingTablePlugin;
/**
 * Class ProductManager
 *
 * @package TierPricingTable\Admin\Product
 */
class TieredPricingTab {
    use ServiceContainerTrait;
    public function __construct() {
        // Tiered Pricing Product Tab
        add_filter(
            'woocommerce_product_data_tabs',
            array($this, 'register'),
            99,
            1
        );
        add_action( 'woocommerce_product_data_panels', array($this, 'render') );
        add_action( 'woocommerce_process_product_meta', array($this, 'save') );
    }

    /**
     * Add tiered pricing tab to woocommerce product tabs
     *
     * @param  array  $productTabs
     *
     * @return array
     */
    public function register( $productTabs ) : array {
        $productTabs['tiered-pricing-tab'] = array(
            'label'  => __( 'Tiered Pricing', 'tier-pricing-table' ),
            'target' => 'tiered-pricing-data',
            'class'  => (function () {
                $types = array_merge( TierPricingTablePlugin::getSupportedSimpleProductTypes(), TierPricingTablePlugin::getSupportedVariableProductTypes() );
                return array_map( function ( $type ) {
                    return 'show_if_' . $type;
                }, $types );
            })(),
        );
        return $productTabs;
    }

    /**
     * Render content for the tiered pricing tab
     */
    public function render() {
        global $post;
        ?>
		<div id="tiered-pricing-data" class="panel woocommerce_options_panel">
			
			<?php 
        if ( !tpt_fs()->can_use_premium_code() ) {
            //$this->renderUpgradeNotice();
        }
        if ( tpt_fs()->can_use_premium_code() && !tpt_fs()->is_premium() ) {
            $this->getContainer()->getFileManager()->includeTemplate( 'admin/banners/free-version-used-premium-available.php', array(
                'is_product' => true,
            ) );
        }
        do_action( 'tiered_pricing_table/admin/pricing_tab_begin', $post->ID );
        ?>

			<div class="hidden show_if_variable options_group">
				<?php 
        $type = PriceManager::getPricingType( $post->ID, 'fixed', 'edit' );
        $percentageRules = PriceManager::getPercentagePriceRules( $post->ID, 'edit' );
        $fixedRules = PriceManager::getFixedPriceRules( $post->ID, 'edit' );
        TieredPricingRulesForm::render(
            $post->ID,
            null,
            null,
            $type,
            $percentageRules,
            $fixedRules,
            '_variable'
        );
        ?>
			</div>

			<div class="options_group">
				<?php 
        $min = PriceManager::getProductQtyMin( $post->ID, 'edit' );
        MinimumOrderQuantityForm::render( null, null, $min );
        do_action( 'tiered_pricing_table/admin/after_minimum_order_quantity_field', $post->ID, null );
        ?>
			</div>
			
			<?php 
        do_action( 'tiered_pricing_table/admin/before_advance_product_options', $post->ID );
        ?>

			<div class="tiered_pricing_tab_product_advance_options">
				<div class="tiered_pricing_tab_product_advance_options__header">
					<h4>
						<span class="dashicons dashicons-admin-settings"></span>
						<?php 
        esc_html_e( 'Additional Options', 'tier-pricing-table' );
        ?>
					</h4>
					<div>
						<span class="tiered_pricing_arrow_down">▼</span>
						<span class="tiered_pricing_arrow_up">▲</span>
					</div>
				</div>

				<div class="tiered_pricing_tab_product_advance_options__content">
					
					<?php 
        $availableTemplates = TierPricingTablePlugin::getAvailablePricingLayouts();
        $availableTemplates = array_merge( array(
            'default' => __( 'Default' ),
        ), $availableTemplates );
        woocommerce_wp_select( array(
            'id'          => '_tiered_pricing_template',
            'value'       => self::getProductTemplate( $post->ID ),
            'options'     => $availableTemplates,
            'label'       => __( 'Pricing layout', 'tier-pricing-table' ),
            'description' => __( 'Override the global layout for this specific product.', 'tier-pricing-table' ),
            'default'     => 'default',
            'desc_tip'    => true,
        ) );
        ?>

					<p class="form-field">
						<label>
							<?php 
        esc_html_e( 'Unit label', 'tier-pricing-table' );
        ?>
						</label>
						
						<?php 
        $productBaseUnitName = self::getProductBaseUnitName( $post->ID );
        ?>

						<input type="text"
							   value="<?php 
        echo esc_attr( $productBaseUnitName['singular'] );
        ?>"
							   placeholder="<?php 
        esc_attr_e( 'Singular (e.g. piece)', 'tier-pricing-table' );
        ?>"
							   style="width: 24%; margin-right: 20px;"
							   name="_tiered_pricing_base_unit_name[singular]">

						<input type="text"
							   value="<?php 
        echo esc_attr( $productBaseUnitName['plural'] );
        ?>"
							   placeholder="<?php 
        esc_attr_e( 'Plural (e.g. pieces)', 'tier-pricing-table' );
        ?>"
							   style="width: 24%"
							   name="_tiered_pricing_base_unit_name[plural]">
					</p>

					<div style="    padding: 0 20px 0px 162px;
    margin-bottom: 10px;
    color: #666;
    margin-top: -10px;
    font-size: 12px;">
						<?php 
        esc_html_e( 'Displayed next to quantities. Leave empty to inherit global settings.', 'tier-pricing-table' );
        ?>
					</div>
					
					<?php 
        do_action( 'tiered_pricing_table/admin/advance_product_options', $post->ID );
        ?>
				</div>
			</div>
			
			<?php 
        do_action( 'tiered_pricing_table/admin/pricing_tab_end', $post->ID );
        ?>
		</div>
		<?php 
    }

    protected function renderUpgradeNotice() {
        ?>
		<div
				style="display:flex; align-items:center; justify-content: space-between; background: #fafafa; padding: 10px; margin: 0 0 20px;border-bottom: 1px solid #eee;">
			<div>
			<span style="color: red;">
			🚀
					<?php 
        esc_html_e( 'Upgrade your plan to unlock all great features.', 'tier-pricing-table' );
        ?>
				 
			</span>
			</div>

			<div>
				<a target="_blank" class="button button-primary"
				   href="<?php 
        echo esc_attr( tpt_fs()->get_upgrade_url() );
        ?>">
					<?php 
        esc_html_e( 'Upgrade', 'tier-pricing-table' );
        ?>
				</a>
			</div>

		</div>
		<?php 
    }

    /**
     * Save tiered pricing tab data
     *
     * @param  $productId
     */
    public function save( $productId ) {
        if ( wp_verify_nonce( true, true ) ) {
            // as phpcs comments at Woo is not available, we have to do such a trash
            $woo = 'Woo, please add ignoring comments to your phpcs checker';
        }
        $template = ( isset( $_POST['_tiered_pricing_template'] ) ? sanitize_text_field( $_POST['_tiered_pricing_template'] ) : 'default' );
        self::updateProductTemplate( $productId, sanitize_text_field( $template ) );
        $baseUnitName = ( isset( $_POST['_tiered_pricing_base_unit_name'] ) ? array_map( 'sanitize_text_field', $_POST['_tiered_pricing_base_unit_name'] ) : array() );
        self::updateProductBaseUnitName( $productId, $baseUnitName );
    }

    public static function getProductTemplate( $productId ) {
        $template = get_post_meta( $productId, '_tiered_pricing_template', true );
        $availableTemplates = TierPricingTablePlugin::getAvailablePricingLayouts();
        return ( array_key_exists( $template, $availableTemplates ) ? $template : 'default' );
    }

    public static function updateProductTemplate( $productId, $template ) {
        $availableTemplates = TierPricingTablePlugin::getAvailablePricingLayouts();
        $template = ( array_key_exists( $template, $availableTemplates ) ? $template : 'default' );
        if ( 'default' !== $template ) {
            update_post_meta( $productId, '_tiered_pricing_template', $template );
        } else {
            delete_post_meta( $productId, '_tiered_pricing_template' );
        }
    }

    public static function getProductBaseUnitName( $productId ) : array {
        $_baseUnitName = (array) get_post_meta( $productId, '_tiered_pricing_base_unit_name', true );
        $baseUnitName['singular'] = $_baseUnitName['singular'] ?? null;
        $baseUnitName['plural'] = $_baseUnitName['plural'] ?? null;
        return $baseUnitName;
    }

    public static function updateProductBaseUnitName( $productId, array $unitNames ) {
        $baseUnitName['singular'] = $unitNames['singular'] ?? null;
        $baseUnitName['plural'] = $unitNames['plural'] ?? null;
        if ( empty( $baseUnitName['singular'] ) && empty( $baseUnitName['plural'] ) ) {
            delete_post_meta( $productId, '_tiered_pricing_base_unit_name' );
        } else {
            update_post_meta( $productId, '_tiered_pricing_base_unit_name', $baseUnitName );
        }
    }

}
