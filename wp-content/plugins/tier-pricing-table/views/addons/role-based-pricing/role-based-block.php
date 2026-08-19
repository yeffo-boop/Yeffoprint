<?php defined( 'ABSPATH' ) || die;

	use TierPricingTable\Addons\RoleBasedPricing\ProductManager;
	use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingRule;
	use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPriceManager;
	use TierPricingTable\Core\ServiceContainer;

	/**
	 * Available variables
	 *
	 * @var int $product_id
	 * @var int $loop
	 */

	$product = wc_get_product( $product_id );

	if ( ! $product ) {
		return;
	}

	$loop = ! is_null( $loop ) ? $loop : null;

	$fileManager = ServiceContainer::getInstance()->getFileManager();

?>

<div class="form-field tpt-role-based-block" id="tpt-role-based-block-<?php echo esc_attr( $product_id ); ?>"
     data-product-type="<?php echo esc_attr( $product->get_type() ); ?>"
     data-add-action="<?php echo esc_attr( ProductManager::GET_ROLE_ROW_HTML__ACTION ); ?>"
     data-add-action-nonce="<?php echo esc_attr( wp_create_nonce( ProductManager::GET_ROLE_ROW_HTML__ACTION ) ); ?>"
     data-product-id="<?php echo esc_attr( $product_id ); ?>"
     data-loop="<?php echo esc_attr( $loop ); ?>">
	<label class="tpt-role-based-block__name" style="line-height: normal"><?php esc_attr_e( 'Role-based pricing', 'tier-pricing-table' ); ?></label>
	<div class="tpt-role-based-block__content">
		<div class="tpt-role-based-roles">
			<?php

				$presentRoles = array();

				foreach ( wp_roles()->roles as $WPRole => $role_data ) {

					if ( RoleBasedPriceManager::roleHasRules( $WPRole, $product_id, 'edit' ) ) {

						$roleBasedRule = RoleBasedPricingRule::build( $product_id, $WPRole );

						$fileManager->includeTemplate( 'addons/role-based-pricing/role.php', array(
								'pricing_rule' => $roleBasedRule,
								'role'         => $WPRole,
								'product_id'   => $product_id,
								'product'      => $product,
								'loop'         => $loop,
						) );

						$presentRoles[] = $WPRole;
					}
				}
			?>
		</div>

		<div class="tpt-role-based-no-roles"
		     style="<?php echo esc_attr( ! empty( $presentRoles ) ? 'display: none;' : '' ); ?>">
			<span>
				<?php
					esc_attr_e( 'Set up specific pricing and rules for different user roles.',
							'tier-pricing-table' );
				?>
			</span>
			<p class="description" style="display: block; margin: 0">
				<?php
					$settingsLink = add_query_arg( array(
							'section' => 'advanced',
					), ServiceContainer::getInstance()->getSettings()->getLink() );

					$settingsLink = sprintf( '<a target="_blank" href="%s">%s</a>', esc_url( $settingsLink ),
							esc_html__( 'settings', 'tier-pricing-table' ) );
					// translators: %s: settings link
					echo wp_kses_post( sprintf( __( 'You can disable this feature in the %s if you don\'t need role-based pricing.',
							'tier-pricing-table' ), $settingsLink ) );

				?>

			</p>
		</div>

		<div class="tpt-role-based-adding-form">
			<select class="tpt-role-based-adding-form__role-selector">
				<?php foreach ( wp_roles()->roles as $key => $WPRole ) : ?>
					<?php if ( ! in_array( $key, $presentRoles ) ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>">
							<?php echo esc_attr( $WPRole['name'] ); ?>
						</option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>

			<button class="button tpt-role-based-adding-form__add-button">
				<?php
					esc_attr_e( 'Add role pricing', 'tier-pricing-table' );
				?>
			</button>
			<div class="clear"></div>
		</div>

		<?php $rolesToDeleteName = ! is_null( $loop ) ? "tiered_price_rules_roles_to_delete_variation[$loop][]" : 'tiered_price_rules_roles_to_delete[]'; ?>

		<select name="<?php echo esc_attr( $rolesToDeleteName ); ?>" class="tiered_price_rules_roles_to_delete" multiple
		        style="display:none;">
			<?php foreach ( wp_roles()->roles as $key => $WPRole ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_attr( $WPRole['name'] ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>
