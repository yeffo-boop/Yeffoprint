<?php

namespace TierPricingTable\Integrations\Plugins\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use TierPricingTable\Core\ServiceContainer;
use TierPricingTable\PricingTable;
class ElementorWidget extends Widget_Base {
    public function get_name() : string {
        return 'tiered-pricing-table';
    }

    public function get_title() : string {
        return esc_html__( 'Tiered Pricing Table', 'tier-pricing-table' );
    }

    public function get_icon() : string {
        return 'eicon-price-table';
    }

    public function get_custom_help_url() : string {
        return 'https://u2code.com/plugins/tiered-pricing-table-for-woocommerce/';
    }

    public function get_categories() : array {
        return array('woocommerce', 'general');
    }

    public function get_keywords() : array {
        return array('tiered', 'table', 'pricing');
    }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => esc_html__( 'Main', 'tier-pricing-table' ),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ] );
        $this->add_control( 'product_id', [
            'label'       => __( 'Product ID', 'tier-pricing-table' ),
            'type'        => Controls_Manager::NUMBER,
            'label_block' => true,
            'description' => __( 'Leave empty to use the current product.', 'tier-pricing-table' ),
        ] );
        $this->add_control( 'display_type', [
            'label'       => __( 'Display type', 'tier-pricing-table' ),
            'type'        => Controls_Manager::SELECT,
            'default'     => 'table',
            'options'     => [
                'table'            => esc_html__( 'Table', 'tier-pricing-table' ),
                'blocks'           => esc_html__( 'Blocks', 'tier-pricing-table' ),
                'options'          => esc_html__( 'Options', 'tier-pricing-table' ),
                'dropdown'         => esc_html__( 'Dropdown', 'tier-pricing-table' ),
                'horizontal-table' => esc_html__( 'Horizontal-table', 'tier-pricing-table' ),
            ],
            'label_block' => true,
        ] );
        $this->add_control( 'title', [
            'label'       => __( 'Title', 'tier-pricing-table' ),
            'type'        => Controls_Manager::TEXT,
            'label_block' => true,
            'description' => __( 'Tiered pricing title. Leave empty to use default title.', 'tier-pricing-table' ),
        ] );
        $this->add_control( 'options_show_original_product_price', [
            'label'       => __( 'Show prices without a discount', 'tier-pricing-table' ),
            'type'        => Controls_Manager::SWITCHER,
            'label_block' => true,
            'default'     => 'yes',
            'description' => __( 'Show price with no discount in the option.', 'tier-pricing-table' ),
            'condition'   => [
                'display_type' => ['options', 'dropdown'],
            ],
        ] );
        $this->add_control( 'options_show_default_option', [
            'label'       => __( 'Show the default option', 'tier-pricing-table' ),
            'type'        => Controls_Manager::SWITCHER,
            'label_block' => true,
            'default'     => 'yes',
            'description' => __( 'Show the option without a discount (option with a regular product price)', 'tier-pricing-table' ),
            'condition'   => [
                'display_type' => ['options', 'dropdown'],
            ],
        ] );
        $this->add_control( 'active_tier_color', [
            'label'       => __( 'Active tier color', 'tier-pricing-table' ),
            'type'        => Controls_Manager::COLOR,
            'label_block' => true,
            'description' => __( 'Leave empty to use the default color.', 'tier-pricing-table' ),
        ] );
        $this->add_control( 'quantity_column_title', [
            'label'       => __( 'Quantity column text', 'tier-pricing-table' ),
            'type'        => Controls_Manager::TEXT,
            'label_block' => true,
            'description' => __( 'Leave empty to use default title.', 'tier-pricing-table' ),
            'condition'   => [
                'display_type' => 'table',
            ],
        ] );
        $this->add_control( 'discount_column_title', [
            'label'       => __( 'Discount column text', 'tier-pricing-table' ),
            'type'        => Controls_Manager::TEXT,
            'label_block' => true,
            'description' => __( 'Leave empty to use default title.', 'tier-pricing-table' ),
            'condition'   => [
                'display_type' => 'table',
            ],
        ] );
        $this->add_control( 'price_column_title', [
            'label'       => __( 'Price column text', 'tier-pricing-table' ),
            'type'        => Controls_Manager::TEXT,
            'label_block' => true,
            'description' => __( 'Leave empty to use default title.', 'tier-pricing-table' ),
            'condition'   => [
                'display_type' => 'table',
            ],
        ] );
        $quantity_measurement = ServiceContainer::getInstance()->getSettings()->get( 'table_quantity_measurement', array(
            'singular' => '',
            'plural'   => '',
        ) );
        $this->add_control( 'quantity_measurement_singular', [
            'label'       => __( 'Unit label (singular)', 'tier-pricing-table' ),
            'type'        => Controls_Manager::TEXT,
            'label_block' => true,
            'description' => __( 'Leave empty to use default', 'tier-pricing-table' ),
            'condition'   => [
                'display_type' => ['table', 'blocks'],
            ],
            'default'     => $quantity_measurement['singular'],
        ] );
        $this->add_control( 'quantity_measurement_plural', [
            'label'       => __( 'Unit label (plural)', 'tier-pricing-table' ),
            'type'        => Controls_Manager::TEXT,
            'label_block' => true,
            'description' => __( 'Leave empty to use default', 'tier-pricing-table' ),
            'condition'   => [
                'display_type' => ['table', 'blocks'],
            ],
            'default'     => $quantity_measurement['plural'],
        ] );
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $args = array();
        if ( isset( $settings['display_type'] ) ) {
            $args['display_type'] = $settings['display_type'];
        }
        if ( isset( $settings['title'] ) ) {
            $args['title'] = $settings['title'];
        }
        if ( !empty( $settings['active_tier_color'] ) ) {
            $args['active_tier_color'] = $settings['active_tier_color'];
        }
        if ( !empty( $settings['price_column_title'] ) ) {
            $args['price_column_title'] = $settings['price_column_title'];
        }
        if ( !empty( $settings['discount_column_title'] ) ) {
            $args['discount_column_title'] = $settings['discount_column_title'];
        }
        if ( !empty( $settings['quantity_column_title'] ) ) {
            $args['quantity_column_title'] = $settings['quantity_column_title'];
        }
        if ( isset( $settings['quantity_measurement_singular'] ) ) {
            $args['quantity_measurement_singular'] = $settings['quantity_measurement_singular'];
        }
        if ( isset( $settings['quantity_measurement_plural'] ) ) {
            $args['quantity_measurement_plural'] = $settings['quantity_measurement_plural'];
        }
        if ( isset( $settings['options_show_total'] ) ) {
            $args['options_show_total'] = 'yes' === $settings['options_show_total'];
        } else {
            $args['options_show_total'] = false;
        }
        if ( isset( $settings['options_show_original_product_price'] ) ) {
            $args['options_show_original_product_price'] = 'yes' === $settings['options_show_original_product_price'];
        } else {
            $args['options_show_original_product_price'] = false;
        }
        if ( isset( $settings['options_show_default_option'] ) ) {
            $args['options_show_default_option'] = 'yes' === $settings['options_show_default_option'];
        } else {
            $args['options_show_default_option'] = false;
        }
        if ( $settings['product_id'] ) {
            $productId = intval( $settings['product_id'] );
        } else {
            global $post;
            if ( !$post ) {
                return;
            }
            $productId = $post->ID;
        }
        $args['display'] = true;
        PricingTable::getInstance()->renderPricingTable( $productId, null, $args );
    }

}
