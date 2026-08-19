<?php namespace TierPricingTable;

use WC_Product;

class PricingRule {
	
	protected ?int $minimum = null;
	protected ?bool $mixAndMatchMinQuantity = null;
	protected array $rules = array();
	protected string $type = 'fixed';
	protected int $productId;
	
	protected ?WC_product $product = null;
	
	public string $provider = 'product';
	public array $providerData = array();
	
	protected array $modificationLog = array();
	
	public array $data = array();
	
	public array $customColumnsData = array();
	
	public array $pricingData = array(
		'regular_price' => null,
		'sale_price'    => null,
		'discount'      => null,
		'pricing_type'  => null, // fixed or percentage
		'tax_status'    => null,
		'tax_class'     => null,
	);
	
	public function __construct( $productOrId ) {
		if ( $productOrId instanceof WC_Product ) {
			$this->product   = $productOrId;
			$this->productId = $productOrId->get_id();
		} else {
			$this->productId = intval( $productOrId );
		}
	}
	
	public function getProductId(): int {
		return $this->productId;
	}
	
	public function getProduct(): ?WC_Product {
		if ( $this->product === null ) {
			$product       = wc_get_product( $this->productId );
			$this->product = $product ?: null;
		}
		
		return $this->product;
	}
	
	public function getMinimum( $forceValue = false ): ?int {
		
		if ( $forceValue ) {
			return $this->minimum ?: 1;
		}
		
		return $this->minimum;
	}
	
	public function setMinimum( ?int $minimum ) {
		$this->minimum = $minimum > 0 ? $minimum : null;
	}
	
	public function isMixAndMatchMinQuantity(): ?bool {
		if ( $this->mixAndMatchMinQuantity ) {
			$product = $this->getProduct();
			if ( ! $product || ( ! $product->is_type( 'variable' ) && ! $product->is_type( 'variation' ) ) ) {
				return false;
			}
		}
		
		return $this->mixAndMatchMinQuantity;
	}
	
	public function setMixAndMatchMinQuantity( ?bool $mixAndMatch ) {
		$this->mixAndMatchMinQuantity = $mixAndMatch;
	}
	
	public function getRules(): array {
		return $this->rules;
	}
	
	public function setRules( array $rules ) {
		$this->rules = $rules;
	}
	
	public function getType(): string {
		return $this->type;
	}
	
	public function setType( string $type ) {
		$this->type = in_array( $type, array( 'fixed', 'percentage' ) ) ? $type : 'fixed';
	}
	
	public function isPercentage(): bool {
		return $this->getType() === 'percentage';
	}
	
	public function isFixed(): bool {
		return $this->getType() === 'fixed';
	}
	
	public function getTierPrice( $quantity, bool $withTaxes = true, $place = 'shop', ?bool $round = null ) {
		return PriceManager::getPriceByRules( $quantity, $this->getProductId(), 'view', $place, $withTaxes, $this,
			$round );
	}
	
	public function logPricingModification( string $modification ) {
		$this->modificationLog[] = $modification;
	}
	
	public function getPricingLog(): array {
		return $this->modificationLog;
	}
}
