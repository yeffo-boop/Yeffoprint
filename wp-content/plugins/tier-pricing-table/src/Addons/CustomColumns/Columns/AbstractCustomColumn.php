<?php

namespace TierPricingTable\Addons\CustomColumns\Columns;

use Exception;
use TierPricingTable\Addons\CustomColumns\CustomColumnsManager;
use TierPricingTable\Addons\CustomColumns\Schema;
use TierPricingTable\PricingRule;
abstract class AbstractCustomColumn {
    protected $slug;

    /**
     * Data
     *
     * @var array
     */
    protected $data;

    /**
     * Column constructor
     *
     * @throws Exception
     */
    public function __construct( $slug, array $data = array() ) {
        $this->slug = $slug;
        $this->data = $data;
        if ( !$this->isValid() ) {
            throw new Exception('Wrong data to create column instance');
        }
        $this->hooks();
    }

    public function isValid() : bool {
        if ( !is_string( $this->slug ) ) {
            return false;
        }
        if ( empty( $this->data['name'] ) ) {
            return false;
        }
        return true;
    }

    protected function hooks() {
    }

    public function getData() : array {
        return $this->data;
    }

    public function getSlug( ?string $prefix = null ) : string {
        // Prefix is used to store custom field for percentage and fixed type
        return ( $prefix ? $prefix . '_' . $this->slug : $this->slug );
    }

    public function getName() : string {
        return (string) apply_filters( 'tiered_pricing_table/custom_columns/name', $this->data['name'], $this );
    }

    public function addColumnHeaderColumn() {
        ?>
		<th>
			<span class="nobr">
				<?php 
        echo esc_attr( $this->getName() );
        ?>
			</span>
		</th>
		<?php 
    }

    public function renderColumn( PricingRule $pricingRule, $currentTierQuantity = null ) {
        ?>
		<td>
			<?php 
        echo wp_kses_post( $this->getSingleRowValue( $pricingRule, $currentTierQuantity ) );
        ?>
		</td>
		<?php 
    }

    public function getSingleRowValue( PricingRule $pricingRule, $currentTierQuantity = null ) {
        $value = $this->_getSingleRowValue( $pricingRule, $currentTierQuantity );
        return apply_filters(
            'tiered_pricing_table/custom_columns/value',
            $value,
            $pricingRule,
            $currentTierQuantity,
            $this
        );
    }

    public function isColumnVisible() : bool {
        return true;
    }

    /**
     * Save custom column
     *
     * @throws Exception
     */
    public function save() : bool {
        if ( !$this->isValid() ) {
            throw new Exception('Wrong data to save column');
        }
        $columnsManager = CustomColumnsManager::getInstance();
        return $columnsManager->saveColumn( $this );
    }

    public function remove() {
        CustomColumnsManager::getInstance()->removeColumn( $this->getSlug() );
    }

    public function getFormattedType() {
        $types = Schema::getAvailableCustomColumnsTypes();
        return ( isset( $types[$this->getType()] ) ? $types[$this->getType()] : '' );
    }

    public function getFormattedDataType() {
        $types = Schema::getAvailableDataTypes();
        return ( isset( $types[$this->getDataType()] ) ? $types[$this->getDataType()] : '' );
    }

    public abstract function getType() : string;

    public abstract function getDataType() : string;

    protected abstract function _getSingleRowValue( PricingRule $pricingRule, $currentTierQuantity = null );

}
