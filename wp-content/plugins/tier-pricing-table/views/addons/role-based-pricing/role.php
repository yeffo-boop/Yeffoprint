<?php

use TierPricingTable\Addons\RoleBasedPricing\RoleBasedPricingRule;
use TierPricingTable\Core\ServiceContainer;

defined( 'ABSPATH' ) || die;

/**
 * Available variables
 *
 * @var RoleBasedPricingRule $pricing_rule
 * @var WC_Product $product
 * @var int $loop
 *
 */

$loop = isset( $loop ) ? $loop : null;

global $wp_roles;

$roleName = isset( $wp_roles->role_names[ $pricing_rule->getRole() ] ) ? translate_user_role( $wp_roles->role_names[ $pricing_rule->getRole() ] ) : $pricing_rule->getRole();

$fileManager = ServiceContainer::getInstance()->getFileManager();
?>

<div class="tpt-role-based-role tpt-role-based-role--<?php echo esc_attr( $pricing_rule->getRole() ); ?>"
	 data-role-slug="<?php echo esc_attr( $pricing_rule->getRole() ); ?>"
	 data-role-name="<?php echo esc_attr( $roleName ); ?>">
	<div class="tpt-role-based-role__header">
		<div class="tpt-role-based-role__name">
			<b><?php echo esc_attr( $roleName ); ?></b>
		</div>
		<div class="tpt-role-based-role__actions">
			<span class="tpt-role-based-role__action-toggle-view tpt-role-based-role__action-toggle-view--open"></span>
			<a href="#" class="tpt-role-based-role-action--delete"><?php esc_attr_e( 'Remove', 'woocommerce' ); ?></a>
		</div>
	</div>
	<div class="tpt-role-based-role__content">
		<?php
		$fileManager->includeTemplate( 'addons/role-based-pricing/role-pricing-form.php', array(
			'pricing_rule' => $pricing_rule,
			'product'      => $product,
			'loop'         => $loop,
		) );
		?>
	</div>
</div>
