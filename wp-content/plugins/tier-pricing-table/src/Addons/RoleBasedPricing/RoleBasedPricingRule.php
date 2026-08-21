<?php namespace TierPricingTable\Addons\RoleBasedPricing;

use Exception;

class RoleBasedPricingRule {
	
	protected $productId;
	protected $role;
	protected $pricingType = 'flat';
	protected $discount = null;
	protected $discountType = 'sale_price';
	protected $salePrice = null;
	protected $regularPrice = null;
	protected $minimumOrderQuantity = null;
	protected $tieredPricingType = 'fixed';
	protected $percentageTieredPricingRules = array();
	protected $fixedTieredPricingRules = array();
	protected $taxStatus = '';
	protected $taxClass = '';
	
	public function __construct( $productId, $role ) {
		$this->productId = $productId;
		$this->role      = $role;
	}
	
	public function getProductId(): ?int {
		return $this->productId;
	}
	
	public function setProductId( int $productId ) {
		$this->productId = $productId;
	}
	
	public function getRole(): string {
		return $this->role;
	}
	
	public function setRole( string $role ) {
		$this->role = $role;
	}
	
	public function getPricingType(): string {
		return $this->pricingType ? $this->pricingType : 'flat';
	}
	
	public function setPricingType( string $pricingType ) {
		$this->pricingType = in_array( $pricingType, array( 'flat', 'percentage' ) ) ? $pricingType : 'flat';
	}
	
	public function getDiscount(): ?float {
		return $this->discount;
	}
	
	public function setDiscount( ?float $discount ) {
		$this->discount = $discount;
	}
	
	public function getDiscountType(): string {
		return $this->discountType;
	}
	
	public function setDiscountType( string $discountType ) {
		$this->discountType = in_array( $discountType,
			array( 'sale_price', 'regular_price' ) ) ? $discountType : 'sale_price';
	}
	
	public function getSalePrice(): ?float {
		return $this->salePrice;
	}
	
	public function setSalePrice( ?float $salePrice ) {
		$this->salePrice = $salePrice;
	}
	
	public function getRegularPrice(): ?float {
		return $this->regularPrice;
	}
	
	public function setRegularPrice( ?float $regularPrice ) {
		$this->regularPrice = $regularPrice;
	}
	
	public function getMinimumOrderQuantity(): ?int {
		return $this->minimumOrderQuantity;
	}
	
	public function setMinimumOrderQuantity( ?int $minimumOrderQuantity ) {
		$this->minimumOrderQuantity = intval( $minimumOrderQuantity ) > 1 ? $minimumOrderQuantity : null;
	}
	
	public function getTieredPricingType(): string {
		return $this->tieredPricingType ? $this->tieredPricingType : 'fixed';
	}
	
	public function setTieredPricingType( string $tieredPricingType ) {
		$this->tieredPricingType = in_array( $tieredPricingType,
			array( 'fixed', 'percentage' ) ) ? $tieredPricingType : 'fixed';
	}
	
	public function getPercentageTieredPricingRules(): array {
		return $this->percentageTieredPricingRules;
	}
	
	public function setPercentageTieredPricingRules( array $percentageTieredPricingRules ) {
		$this->percentageTieredPricingRules = $percentageTieredPricingRules;
	}
	
	public function getFixedTieredPricingRules(): array {
		return $this->fixedTieredPricingRules;
	}
	
	public function setFixedTieredPricingRules( array $fixedTieredPricingRules ) {
		$this->fixedTieredPricingRules = $fixedTieredPricingRules;
	}
	
	public function getTieredPricingRules(): array {
		return $this->getTieredPricingType() === 'percentage' ? $this->getPercentageTieredPricingRules() : $this->getFixedTieredPricingRules();
	}
	
	public function getTaxStatus(): string {
		return $this->taxStatus;
	}
	
	public function setTaxStatus( string $taxStatus ) {
		$this->taxStatus = $taxStatus;
	}
	
	public function getTaxClass(): string {
		return $this->taxClass;
	}
	
	public function setTaxClass( string $taxClass ) {
		$this->taxClass = $taxClass;
	}
	
	public function asArray(): array {
		return array(
			// main
			'product_id'          => $this->getProductId(),
			'role'                => $this->getRole(),
			
			// Pricing
			'pricing_type'        => $this->getPricingType(),
			'regular_price'       => $this->getRegularPrice(),
			'sale_price'          => $this->getSalePrice(),
			'discount'            => $this->getDiscount(),
			'discount_type'       => $this->getDiscountType(),
			
			// Tiered Pricing
			'tiered_pricing_type' => $this->getTieredPricingType(),
			'percentage_rules'    => $this->getPercentageTieredPricingRules(),
			'fixed_rules'         => $this->getFixedTieredPricingRules(),
			
			// MOQ
			'minimum'             => $this->getMinimumOrderQuantity(),
			
			// Tax
			'tax_status'          => $this->getTaxStatus(),
			'tax_class'           => $this->getTaxClass(),
		);
	}
	
	/**
	 * Save
	 *
	 * @throws Exception
	 */
	public function save() {
		
		if ( ! $this->getProductId() || ! $this->getRole() ) {
			throw new Exception( 'Rule requires product id and role to be saved' );
		}
		
		
		$dataToUpdate = array(
			// Pricing
			'tiered_price_pricing_type'  => $this->getPricingType(),
			'tiered_price_regular_price' => $this->getRegularPrice(),
			'tiered_price_sale_price'    => $this->getSalePrice(),
			'tiered_price_discount'      => $this->getDiscount(),
			'tiered_price_discount_type' => $this->getDiscountType(),
			
			// Tiered Pricing
			'tiered_price_rules_type'    => $this->getTieredPricingType(),
			'percentage_price_rules'     => $this->getPercentageTieredPricingRules(),
			'fixed_price_rules'          => $this->getFixedTieredPricingRules(),
			
			// MOQ
			'tiered_price_minimum_qty'   => $this->getMinimumOrderQuantity(),
			
			// Tax
			'tiered_price_tax_status'    => $this->getTaxStatus(),
			'tiered_price_tax_class'     => $this->getTaxClass(),
		);
		
		$role = $this->getRole();
		
		foreach ( $dataToUpdate as $key => $value ) {
			$metaKey = "_{$role}_" . $key;
			update_post_meta( $this->getProductId(), $metaKey, $value );
		}
	}
	
	public static function build( int $productId, string $role ): self {
		
		$rule = new self( $productId, $role );
		
		// Regular pricing
		$rule->setPricingType( RoleBasedPriceManager::getProductPricingType( $productId, $role ) );
		$rule->setSalePrice( RoleBasedPriceManager::getProductSaleRolePrice( $productId, $role ) );
		$rule->setRegularPrice( RoleBasedPriceManager::getProductRegularRolePrice( $productId, $role ) );
		$rule->setDiscount( RoleBasedPriceManager::getProductDiscount( $productId, $role ) );
		$rule->setDiscountType( RoleBasedPriceManager::getProductDiscountType( $productId, $role ) );
		
		// Tiered Pricing
		$rule->setTieredPricingType( RoleBasedPriceManager::getPricingType( $productId, $role ) );
		$rule->setPercentageTieredPricingRules( RoleBasedPriceManager::getPercentagePriceRules( $productId, $role ) );
		$rule->setFixedTieredPricingRules( RoleBasedPriceManager::getFixedPriceRules( $productId, $role ) );
		
		// MOQ
		$rule->setMinimumOrderQuantity( RoleBasedPriceManager::getProductQtyMin( $productId, $role ) );
		
		// Tax
		$rule->setTaxStatus( RoleBasedPriceManager::getProductTaxStatus( $productId, $role ) );
		$rule->setTaxClass( RoleBasedPriceManager::getProductTaxClass( $productId, $role ) );
		
		return apply_filters( 'tiered_pricing_table/role_based/after_built_rule', $rule );
	}
	
	public static function buildFromArray( int $productId, string $role, array $data ): self {
		$rule = new self( $productId, $role );
		
		// Regular pricing
		$rule->setPricingType( isset( $data['pricing_type'] ) ? (string) $data['pricing_type'] : 'flat' );
		$rule->setRegularPrice( isset( $data['regular_price'] ) ? (float) $data['regular_price'] : null );
		$rule->setSalePrice( isset( $data['sale_price'] ) ? (float) $data['sale_price'] : null );
		$rule->setDiscount( isset( $data['discount'] ) ? (float) min( 100, $data['discount'] ) : null );
		$rule->setDiscountType( isset( $data['discount_type'] ) ? (string) $data['discount_type'] : 'sale_price' );
		
		// Tiered Pricing
		$rule->setTieredPricingType( isset( $data['tiered_pricing_type'] ) ? (string) $data['tiered_pricing_type'] : 'fixed' );
		$rule->setPercentageTieredPricingRules( isset( $data['percentage_tiered_pricing_rules'] ) ? (array) $data['percentage_tiered_pricing_rules'] : array() );
		$rule->setFixedTieredPricingRules( isset( $data['fixed_tiered_pricing_rules'] ) ? (array) $data['fixed_tiered_pricing_rules'] : array() );
		
		// MOQ
		$rule->setMinimumOrderQuantity( isset( $data['minimum_order_quantity'] ) ? (int) $data['minimum_order_quantity'] : null );
		
		// Tax
		$rule->setTaxStatus( isset( $data['tax_status'] ) ? (string) $data['tax_status'] : '' );
		$rule->setTaxClass( isset( $data['tax_class'] ) ? (string) $data['tax_class'] : '' );
		
		return apply_filters( 'tiered_pricing_table/role_based/after_built_rule_from_array', $rule, $role, $data );
	}
}
