<?php namespace TierPricingTable\Addons\UserBasedPricing;

use Exception;

class UserBasedPricingRule {
	
	protected $productId;
	protected $userId;
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
	
	public function __construct( $productId, $userId ) {
		$this->productId = $productId;
		$this->userId    = $userId;
	}
	
	public function getProductId(): ?int {
		return $this->productId;
	}
	
	public function setProductId( int $productId ) {
		$this->productId = $productId;
	}
	
	public function getUserId(): int {
		return $this->userId;
	}
	
	public function setUserId( int $userId ) {
		$this->userId = $userId;
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
			'user_id'             => $this->getUserId(),
			
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
		
		if ( ! $this->getProductId() || ! $this->getUserId() ) {
			throw new Exception( 'Rule requires product id and user id to be saved' );
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
		
		$userId = $this->getUserId();
		
		foreach ( $dataToUpdate as $key => $value ) {
			$metaKey = "_user_{$userId}_" . $key;
			update_post_meta( $this->getProductId(), $metaKey, $value );
		}
	}
	
	public static function build( int $productId, int $userId ): self {
		
		$rule = new self( $productId, $userId );
		
		// Regular pricing
		$rule->setPricingType( UserBasedPriceManager::getProductPricingType( $productId, $userId ) );
		$rule->setSalePrice( UserBasedPriceManager::getProductSaleUserPrice( $productId, $userId ) );
		$rule->setRegularPrice( UserBasedPriceManager::getProductRegularUserPrice( $productId, $userId ) );
		$rule->setDiscount( UserBasedPriceManager::getProductDiscount( $productId, $userId ) );
		$rule->setDiscountType( UserBasedPriceManager::getProductDiscountType( $productId, $userId ) );
		
		// Tiered Pricing
		$rule->setTieredPricingType( UserBasedPriceManager::getPricingType( $productId, $userId ) );
		$rule->setPercentageTieredPricingRules( UserBasedPriceManager::getPercentagePriceRules( $productId, $userId ) );
		$rule->setFixedTieredPricingRules( UserBasedPriceManager::getFixedPriceRules( $productId, $userId ) );
		
		// MOQ
		$rule->setMinimumOrderQuantity( UserBasedPriceManager::getProductQtyMin( $productId, $userId ) );
		
		// Tax
		$rule->setTaxStatus( UserBasedPriceManager::getProductTaxStatus( $productId, $userId ) );
		$rule->setTaxClass( UserBasedPriceManager::getProductTaxClass( $productId, $userId ) );
		
		return apply_filters( 'tiered_pricing_table/user_based/after_built_rule', $rule );
	}
	
	public static function buildFromArray( int $productId, int $userId, array $data ): self {
		$rule = new self( $productId, $userId );
		
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
		
		return apply_filters( 'tiered_pricing_table/user_based/after_built_rule_from_array', $rule, $userId, $data );
	}
}
