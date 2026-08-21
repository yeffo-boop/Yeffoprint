<?php

	use TierPricingTable\Addons\UserBasedPricing\UserBasedPricingRule;
	use TierPricingTable\Core\ServiceContainer;

	defined( 'ABSPATH' ) || die;

	/**
	 * Available variables
	 *
	 * @var UserBasedPricingRule $pricing_rule
	 * @var WC_Product $product
	 * @var WP_User $user
	 * @var int $loop
	 *
	 */

	$loop = isset( $loop ) ? $loop : null;

	$fileManager = ServiceContainer::getInstance()->getFileManager();

	try {
		$customer = new WC_Customer( $user->ID );

		$displayName = sprintf( /* translators: $1: customer name, $2 customer id, $3: customer email */ esc_html__( '%1$s (#%2$s &ndash; %3$s)',
				'woocommerce' ), $customer->get_first_name() . ' ' . $customer->get_last_name(), $customer->get_id(),
				$customer->get_email() );

	} catch ( Exception $e ) {
		$displayName = $user->display_name ? $user->display_name : $user->ID;
	}

?>

<div class="tpt-user-based-user tpt-user-based-user--<?php echo esc_attr( $pricing_rule->getUserId() ); ?>"
     data-user-id="<?php echo esc_attr( $pricing_rule->getUserId() ); ?>"
     data-user-name="<?php echo esc_attr( $displayName ); ?>">
	<div class="tpt-user-based-user__header">
		<div class="tpt-user-based-user__name">
			<b><?php echo esc_html( $displayName ); ?></b>
		</div>
		<div class="tpt-user-based-user__actions">
			<span class="tpt-user-based-user__action-toggle-view tpt-user-based-user__action-toggle-view--open"></span>
			<a href="#" class="tpt-user-based-user-action--delete"><?php esc_attr_e( 'Remove', 'woocommerce' ); ?></a>
		</div>
	</div>
	<div class="tpt-user-based-user__content">
		<?php
			$fileManager->includeTemplate( 'addons/user-based-pricing/user-pricing-form.php', array(
					'pricing_rule' => $pricing_rule,
					'product'      => $product,
					'loop'         => $loop,
			) );
		?>
	</div>
</div>
