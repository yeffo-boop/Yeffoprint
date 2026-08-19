<?php namespace TierPricingTable;

/**
 * Class Freemius
 *
 * @package TierPricingTable
 */
class Freemius {

	/**
	 * License
	 *
	 * @var self
	 */
	private $instance;

	private $mainFile;

	/**
	 * Freemius constructor.
	 *
	 * @param $mainFile
	 */
	public function __construct( $mainFile ) {

		$this->mainFile = $mainFile;
		$this->init();

		if ( $this->isValid() ) {
			$this->hooks();
		}
	}

	public function hooks() {
		add_action( 'admin_menu', [ $this, 'initPages' ] );

		$this->instance->add_filter( 'templates/pricing.php', function ( $template ) {
			ob_start();
			?>

			<style>
				#fs_pricing_app {
					--fs-ds-blue-300: #faf6f9;
					--fs-ds-blue-500: #814c77;
					--fs-ds-blue-600: #814c77;
					--fs-ds-blue-700: #814c77;
					--fs-ds-blue-800: #814c77;
					--fs-ds-blue-900: #814c77;

					--fs-ds-theme-background-color: #faf6f9;
				}
			</style>
			<?php

			$style = ob_get_clean();

			return $style . $template;
		} );

		$this->instance->add_filter( 'pricing/show_annual_in_monthly', '__return_false' );
	}

	public function isValid(): bool {
		return $this->instance instanceof \Freemius;
	}

	public function init() {
		if ( function_exists( 'tpt_fs' ) ) {
			$this->instance = tpt_fs();
		}
	}

	public function initPages() {

		// Account
		add_submenu_page( '__freemius', __( 'Freemius Account', 'tier-price-table' ),
				__( 'Freemius Account', 'tier-price-table' ), 'manage_options', 'tiered-pricing-table-account',
				[ $this, 'renderAccountPage' ] );

		// Contact us
		add_submenu_page( '__freemius', __( 'Contact Us', 'tiered-price-table' ),
				__( 'Contact Us', 'tier-price-table' ), 'manage_options', 'tiered-pricing-table-contact-us',
				[ $this, 'renderContactUsPage' ] );
	}

	public function renderAccountPage() {

		if ( ! $this->instance->get_user() || $this->instance->is_activation_mode() || $this->instance->is_anonymous() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=tier-pricing-table' ) );
			exit;
		} else {
			$this->instance->_account_page_load();
			$this->instance->_account_page_render();
		}
	}

	public function renderContactUsPage() {
		$this->instance->_contact_page_render();
	}
}
