<?php
namespace Automattic\WCShipping\DOM;

class Manipulation {
	/**
	 * Output a script root element that entry point script can attach to.
	 *
	 * @param string $root_view The root view name.
	 * @param string $context   The context of the root view.
	 */
	public static function create_root_script_element( $root_view, $context = '' ) {
		$element_id = $root_view;
		if ( ! empty( $context ) ) {
			$element_id .= '-' . $context;
		}

		$debug_page_uri = esc_url(
			add_query_arg(
				array(
					'page' => 'wc-status',
					'tab'  => 'woocommerce-shipping',
				),
				admin_url( 'admin.php' )
			)
		);

		?>
			<div class="wcc-root woocommerce <?php echo esc_attr( $root_view ); ?>" id="<?php echo esc_attr( $element_id ); ?>">
				<span class="form-troubles" style="opacity: 0">
					<?php
						printf(
							wp_kses(
								// translators: %s is a link to the status page.
								__( 'Section not loading? Visit the <a href="%s">status page</a> for troubleshooting steps.', 'woocommerce-shipping' ),
								array(
									'a' => array(
										'href' => array(),
									),
								)
							),
							esc_url( $debug_page_uri )
						);
					?>
				</span>
			</div>
		<?php
	}
}
