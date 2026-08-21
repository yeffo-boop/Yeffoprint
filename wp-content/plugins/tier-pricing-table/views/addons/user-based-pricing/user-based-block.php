<?php defined( 'ABSPATH' ) || die;

	use TierPricingTable\Addons\UserBasedPricing\ProductManager;
	use TierPricingTable\Addons\UserBasedPricing\UserBasedPricingRule;
	use TierPricingTable\Addons\UserBasedPricing\UserBasedPriceManager;
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

<div class="form-field tpt-user-based-block" id="tpt-user-based-block-<?php echo esc_attr( $product_id ); ?>"
     data-product-type="<?php echo esc_attr( $product->get_type() ); ?>"
     data-add-action="<?php echo esc_attr( ProductManager::GET_USER_ROW_HTML__ACTION ); ?>"
     data-add-action-nonce="<?php echo esc_attr( wp_create_nonce( ProductManager::GET_USER_ROW_HTML__ACTION ) ); ?>"
     data-product-id="<?php echo esc_attr( $product_id ); ?>"
     data-loop="<?php echo esc_attr( $loop ); ?>">
	<label class="tpt-user-based-block__name" style="line-height: normal"><?php esc_attr_e( 'Customer-Specific Pricing', 'tier-pricing-table' ); ?></label>
	<div class="tpt-user-based-block__content">
		<div class="tpt-user-based-users">
			<?php

				$presentUsers = array();

				// We need to fetch users that have pricing. But we don't have a direct query for all users with this postmeta.
				// However, we can query postmeta directly to find user IDs.
				global $wpdb;
				$meta_keys_like = $wpdb->esc_like( '_user_' ) . '%' . $wpdb->esc_like( '_tiered_price_rules_type' );
				$results = $wpdb->get_results( $wpdb->prepare( "
					SELECT meta_key FROM {$wpdb->postmeta} 
					WHERE post_id = %d AND meta_key LIKE %s
				", $product_id, $meta_keys_like ) );

				foreach ( $results as $result ) {
					// Extract user ID from meta_key (e.g. _user_123_tiered_price_rules_type)
					if ( preg_match( '/^_user_(\d+)_tiered_price_rules_type$/', $result->meta_key, $matches ) ) {
						$userId = (int) $matches[1];
						$user = get_userdata( $userId );

						if ( $user && UserBasedPriceManager::userHasRules( $userId, $product_id, 'edit' ) ) {

							$userBasedRule = UserBasedPricingRule::build( $product_id, $userId );

							$fileManager->includeTemplate( 'addons/user-based-pricing/user.php', array(
									'pricing_rule' => $userBasedRule,
									'user'         => $user,
									'product_id'   => $product_id,
									'product'      => $product,
									'loop'         => $loop,
							) );

							$presentUsers[] = $userId;
						}
					}
				}
			?>
		</div>

		<div class="tpt-user-based-no-users"
		     style="<?php echo esc_attr( ! empty( $presentUsers ) ? 'display: none;' : '' ); ?>">
			<span>
				<?php
					esc_attr_e( 'Set up specific pricing and rules for individual customers.',
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
					echo wp_kses_post( sprintf( __( 'You can disable this feature in the %s if you don\'t need customer-based pricing.',
							'tier-pricing-table' ), $settingsLink ) );

				?>

			</p>
		</div>

		<div class="tpt-user-based-adding-form">
			<select class="tpt-user-based-adding-form__user-selector wc-customer-search" data-placeholder="<?php esc_attr_e( 'Search for a customer&hellip;', 'woocommerce' ); ?>" data-allow_clear="true" style="width: 250px;">
			</select>

			<button class="button tpt-user-based-adding-form__add-button">
				<?php
					esc_attr_e( 'Add Customer', 'tier-pricing-table' );
				?>
			</button>

			<div class="clear"></div>
		</div>

		<?php $usersToDeleteName = ! is_null( $loop ) ? "tiered_price_rules_users_to_delete_variation[$loop][]" : 'tiered_price_rules_users_to_delete[]'; ?>

		<select name="<?php echo esc_attr( $usersToDeleteName ); ?>" class="tiered_price_rules_users_to_delete" multiple
		        style="display:none;">
			<?php foreach ( $presentUsers as $presentUserId ) : ?>
				<option value="<?php echo esc_attr( $presentUserId ); ?>"><?php echo esc_attr( $presentUserId ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</div>
