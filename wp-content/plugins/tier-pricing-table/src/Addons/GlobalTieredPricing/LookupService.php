<?php namespace TierPricingTable\Addons\GlobalTieredPricing;

use Exception;
use TierPricingTable\Core\ServiceContainerTrait;
use WC_Customer;
use WP_Term;
use WP_User_Query;

class LookupService {

	use ServiceContainerTrait;

	const CATEGORIES_SEARCH_ACTION = 'woocommerce_json_search_tpt_categories';
	const TAGS_SEARCH_ACTION       = 'woocommerce_json_search_tpt_tags';
	const BRANDS_SEARCH_ACTION     = 'woocommerce_json_search_tpt_brands';
	const CUSTOMERS_SEARCH_ACTION  = 'woocommerce_json_search_tpt_customers';

	public function __construct() {
		add_action( 'wp_ajax_' . self::CATEGORIES_SEARCH_ACTION, array( $this, 'categoriesSearchHandler' ) );
		add_action( 'wp_ajax_' . self::TAGS_SEARCH_ACTION, array( $this, 'tagsSearchHandler' ) );
		add_action( 'wp_ajax_' . self::BRANDS_SEARCH_ACTION, array( $this, 'brandsSearchHandler' ) );
		add_action( 'wp_ajax_' . self::CUSTOMERS_SEARCH_ACTION, array( $this, 'customersSearchHandler' ) );
	}

	public function customersSearchHandler() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array() );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : false;

		if ( $term ) {
			$wp_user_query = new WP_User_Query(
				array(
					'search'         => '*' . $term . '*',
					'search_columns' => array(
						'user_login',
						'user_nicename',
						'user_email',
					),
					'fields'         => 'ID',
				) );

			$users = $wp_user_query->get_results();

			if ( $users ) {
				$_users = array();

				foreach ( $users as $userId ) {
					try {
						$customer = new WC_Customer( $userId );
					} catch ( Exception $e ) {
						continue;
					}

					if ( $customer instanceof WC_Customer ) {
						$_users[ $userId ] = Formatter::formatCustomerString( $customer );
					}

				}

				wp_send_json( $_users );
			}
		}

		wp_send_json( array() );
	}

	public function categoriesSearchHandler() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array() );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : false;

		if ( $term ) {
			$args = array(
				'taxonomy'   => array( 'product_cat' ),
				'orderby'    => 'id',
				'order'      => 'ASC',
				'limit'      => 5,
				'hide_empty' => false,
				'fields'     => 'all',
				'name__like' => $term
			);

			$terms = get_terms( $args );

			if ( $terms ) {
				$_terms = array();

				foreach ( $terms as $term ) {
					if ( $term instanceof WP_Term ) {
						$_terms[ $term->term_id ] = self::getCategoryLabel( $term );
					}
				}

				wp_send_json( $_terms );
			}
		}

		wp_send_json( array() );
	}

	public function tagsSearchHandler() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array() );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : false;

		if ( $term ) {
			$args = array(
				'taxonomy'   => array( 'product_tag' ),
				'orderby'    => 'id',
				'order'      => 'ASC',
				'limit'      => 5,
				'hide_empty' => false,
				'fields'     => 'all',
				'name__like' => $term
			);

			$terms = get_terms( $args );

			if ( $terms && ! is_wp_error( $terms ) ) {
				$_terms = array();

				foreach ( $terms as $term ) {
					if ( $term instanceof WP_Term ) {
						$_terms[ $term->term_id ] = $term->name;
					}
				}

				wp_send_json( $_terms );
			}
		}

		wp_send_json( array() );
	}

	public function brandsSearchHandler() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json( array() );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( $_GET['term'] ) : false;

		if ( $term ) {
			if ( taxonomy_exists( 'product_brand' ) ) {
				$args = array(
					'taxonomy'   => array( 'product_brand' ),
					'orderby'    => 'id',
					'order'      => 'ASC',
					'limit'      => 5,
					'hide_empty' => false,
					'fields'     => 'all',
					'name__like' => $term
				);

				$terms = get_terms( $args );

				if ( $terms && ! is_wp_error( $terms ) ) {
					$_terms = array();

					foreach ( $terms as $term ) {
						if ( $term instanceof WP_Term ) {
							$_terms[ $term->term_id ] = $term->name;
						}
					}

					wp_send_json( $_terms );
				}
			}
		}

		wp_send_json( array() );
	}

	public static function getCategoryLabel( WP_Term $category ): string {
		$parentTermName = '';

		if ( $category->parent ) {
			$parentTerm = get_term( $category->parent );

			if ( $parentTerm ) {
				$parentTermName = ' (' . $parentTerm->name . ')';
			}
		}

		return $category->name . $parentTermName;
	}
}
