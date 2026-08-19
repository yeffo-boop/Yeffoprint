<?php namespace TierPricingTable\Addons\CustomColumns;

use TierPricingTable\Addons\CustomColumns\Columns\AbstractCustomColumn;
use TierPricingTable\Forms\Form;

class CustomColumnsManager {
	
	const CUSTOM_COLUMNS_OPTION_NAME = 'tpt_custom_table_columns';
	
	protected static $instance = null;
	
	/**
	 * Custom columns
	 *
	 * @var AbstractCustomColumn[]
	 */
	public $columns = null;
	
	/**
	 * Raw custom columns data
	 *
	 * @var array
	 */
	public $rawColumns = null;
	
	public static function getInstance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	private function __construct() {
		add_action( 'init', function () {
			$this->columns = $this->getColumns();
		} );
	}
	
	public function getColumns(): array {
		
		if ( is_null( $this->columns ) ) {
			
			$rawColumns = $this->getRawColumns();
			
			$instances = array();
			
			foreach ( $rawColumns as $slug => $columnData ) {
				$instances[] = $this->getColumnInstance( $slug, $columnData );
			}
			
			$instances = array_values( array_filter( $instances ) );
			
			$this->columns = $instances;
		}
		
		return $this->columns;
	}
	
	public function getRawColumns(): array {
		
		if ( is_null( $this->rawColumns ) ) {
			$columns = (array) get_option( self::CUSTOM_COLUMNS_OPTION_NAME, array() );
			
			$this->rawColumns = $this->sanitizeAndFilterRawColumns( $columns );
		}
		
		return $this->rawColumns;
	}
	
	public function updateRawColumns( array $columns ) {
		$columns = $this->sanitizeAndFilterRawColumns( $columns );
		
		update_option( self::CUSTOM_COLUMNS_OPTION_NAME, $columns );
		
		$this->rawColumns = $columns;
		$this->columns    = null;
	}
	
	public function saveColumn( AbstractCustomColumn $column ): bool {
		
		$columnData = $this->sanitizeColumnData( $column->getData() );
		
		if ( ! $columnData ) {
			return false;
		}
		
		$columns                       = $this->getRawColumns();
		$columns[ $column->getSlug() ] = $columnData;
		
		$this->updateRawColumns( $columns );
		
		return true;
	}
	
	public function removeColumn( $slug ): bool {
		
		$columns = $this->getRawColumns();
		
		if ( array_key_exists( $slug, $columns ) ) {
			unset( $columns[ $slug ] );
			
			delete_post_meta_by_key( Form::getFieldName( $slug ) );
		} else {
			return false;
		}
		
		$this->updateRawColumns( $columns );
		
		return true;
	}
	
	public function sanitizeColumnData( array $customColumn ) {
		
		$name = $customColumn['name'] ?? false;
		$type = $customColumn['type'] ?? false;
		$dataType = $customColumn['data_type'] ?? 'text';
		
		$type = Schema::isValidColumnType( $type ) ? $type : false;
		$dataType = Schema::isValidDataType( $dataType ) ? $dataType : 'text';
		
		if ( ! $name || ! $type ) {
			return false;
		}

		$customColumn['data_type'] = $dataType;
		
		return $customColumn;
	}
	
	/**
	 * Get custom column instance
	 *
	 * @param $slug
	 * @param $data
	 *
	 * @return AbstractCustomColumn|null
	 */
	public function getColumnInstance( $slug, $data ): ?AbstractCustomColumn {
		$class = Schema::getClassForType( $data['type'] );
		
		try {
			return new $class( $slug, $data );
		} catch ( \Exception $exception ) {
			return null;
		}
	}
	
	protected function sanitizeAndFilterRawColumns( $columns ): array {
		
		if ( ! array( $columns ) ) {
			return array();
		}
		
		$validRawColumns = array();
		
		foreach ( $columns as $slug => $column ) {
			$column = is_array( $column ) ? $column : array();
			
			if ( empty( $column ) ) {
				continue;
			}
			
			$data = $this->sanitizeColumnData( $column );
			
			if ( $data ) {
				$validRawColumns[ $slug ] = $data;
			}
		}
		
		return $validRawColumns;
	}
}
