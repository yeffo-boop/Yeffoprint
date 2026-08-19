<?php namespace TierPricingTable\Addons\RequestAQuote\Models;

use TierPricingTable\Addons\RequestAQuote\CPT\QuoteRequestCPT;

class QuoteRequest {
	
	protected int $id = 0;
	
	protected array $data = array(
		'status'           => 'unread',
		'title'            => '',
		'content'          => '',
		'date'             => '',
		'productId'        => 0,
		'quantity'         => 1,
		'price'            => '',
		'userId'           => 0,
		'customerEmail'    => '',
		'customFields'     => array(),
		'convertedOrderId' => 0,
	);
	
	protected array $metaData = array();
	
	protected array $changes = array();
	
	public function __construct( $quote = 0 ) {
		if ( is_numeric( $quote ) && $quote > 0 ) {
			$this->id = absint( $quote );
			$this->read();
		} elseif ( $quote instanceof \WP_Post ) {
			$this->id = absint( $quote->ID );
			$this->read();
		} elseif ( is_array( $quote ) ) {
			$this->setProps( $quote );
		}
	}
	
	public function getId() {
		return $this->id;
	}
	
	public function setId( $id ) {
		$this->id = absint( $id );
	}
	
	public function read() {
		if ( ! $this->getId() ) {
			return;
		}
		
		$post = get_post( $this->getId() );
		if ( ! $post || $post->post_type !== QuoteRequestCPT::POST_TYPE ) {
			$this->setId( 0 );
			
			return;
		}
		
		$this->setProps( array(
			'status'  => $post->post_status,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'date'    => $post->post_date,
		) );
		
		$meta = get_post_meta( $this->getId() );
		if ( $meta && is_array( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				$val = $value[0];
				if ( is_string( $val ) && is_serialized( $val ) ) {
					$val = maybe_unserialize( $val );
				}
				$this->metaData[ $key ] = $val;
			}
		}
		
		$customFields = $this->metaData['_custom_fields'] ?? array();
		if ( ! is_array( $customFields ) ) {
			$customFields = array();
		}
		
		// Hydrate known properties
		$this->setProps( array(
			'productId'        => $this->metaData['_product_id'] ?? 0,
			'quantity'         => $this->metaData['_quantity'] ?? 1,
			'price'            => $this->metaData['_price'] ?? '',
			'userId'           => $this->metaData['_user_id'] ?? 0,
			'customerEmail'    => $this->metaData['_email'] ?? '',
			'customFields'     => $customFields,
			'convertedOrderId' => $this->metaData['_converted_order_id'] ?? 0,
		) );
		
		$this->applyChanges();
	}
	
	public function save() {
		$post_data = array();
		
		if ( array_key_exists( 'status', $this->changes ) ) {
			$post_data['post_status'] = $this->changes['status'];
		}
		if ( array_key_exists( 'title', $this->changes ) ) {
			$post_data['post_title'] = $this->changes['title'];
		}
		if ( array_key_exists( 'content', $this->changes ) ) {
			$post_data['post_content'] = $this->changes['content'];
		}
		if ( array_key_exists( 'date', $this->changes ) ) {
			$post_data['post_date'] = $this->changes['date'];
		}
		
		if ( ! $this->getId() ) {
			$post_data['post_type'] = QuoteRequestCPT::POST_TYPE;
			if ( empty( $post_data['post_title'] ) ) {
				$post_data['post_title'] = __( 'Quote Request', 'tier-pricing-table' );
			}
			if ( empty( $post_data['post_status'] ) ) {
				$post_data['post_status'] = 'unread';
			}
			
			$post_id = wp_insert_post( $post_data );
			if ( ! is_wp_error( $post_id ) ) {
				$this->setId( $post_id );
			} else {
				return false;
			}
		} elseif ( ! empty( $post_data ) ) {
			$post_data['ID'] = $this->getId();
			wp_update_post( $post_data );
		}
		
		// Save mapped properties to meta
		$meta_mapping = array(
			'productId'        => '_product_id',
			'quantity'         => '_quantity',
			'price'            => '_price',
			'userId'           => '_user_id',
			'customerEmail'    => '_email',
			'customFields'     => '_custom_fields',
			'convertedOrderId' => '_converted_order_id',
		);
		
		foreach ( $meta_mapping as $prop => $meta_key ) {
			if ( array_key_exists( $prop, $this->changes ) ) {
				update_post_meta( $this->getId(), $meta_key, $this->changes[ $prop ] );
			}
		}
		
		// Save direct meta changes
		foreach ( $this->metaData as $key => $value ) {
			update_post_meta( $this->getId(), $key, $value );
		}
		
		$this->applyChanges();
		
		return $this->getId();
	}
	
	public function getProp( $prop, $context = 'view' ) {
		return array_key_exists( $prop, $this->changes ) ? $this->changes[ $prop ] : ( $this->data[ $prop ] ?? null );
	}
	
	public function setProp( $prop, $value ) {
		if ( array_key_exists( $prop, $this->data ) && $this->data[ $prop ] !== $value ) {
			$this->changes[ $prop ] = $value;
		}
	}
	
	public function setProps( $props ) {
		foreach ( $props as $prop => $value ) {
			$this->setProp( $prop, $value );
		}
	}
	
	public function getStatus() {
		return $this->getProp( 'status' );
	}
	
	public function setStatus( $value ) {
		$this->setProp( 'status', $value );
	}
	
	public function getTitle() {
		return $this->getProp( 'title' );
	}
	
	public function setTitle( $value ) {
		$this->setProp( 'title', $value );
	}
	
	public function getContent() {
		return $this->getProp( 'content' );
	}
	
	public function setContent( $value ) {
		$this->setProp( 'content', $value );
	}
	
	public function getDate() {
		return $this->getProp( 'date' );
	}
	
	public function setDate( $value ) {
		$this->setProp( 'date', $value );
	}
	
	public function getProductId(): int {
		return (int) $this->getProp( 'productId' );
	}
	
	public function setProductId( $value ) {
		$this->setProp( 'productId', (int) $value );
	}
	
	public function getQuantity(): int {
		return (int) $this->getProp( 'quantity' );
	}
	
	public function setQuantity( $value ) {
		$this->setProp( 'quantity', (int) $value );
	}
	
	public function getPrice() {
		return $this->getProp( 'price' );
	}
	
	public function setPrice( $value ) {
		$this->setProp( 'price', $value );
	}
	
	public function getUserId(): int {
		return (int) $this->getProp( 'userId' );
	}
	
	public function setUserId( $value ) {
		$this->setProp( 'userId', (int) $value );
	}
	
	public function applyChanges() {
		if ( ! empty( $this->changes ) ) {
			$this->data = array_merge( $this->data, $this->changes );
			$this->changes = array();
		}
	}
	
	public function getCustomerEmail() {
		return $this->getProp( 'customerEmail' );
	}
	
	public function setCustomerEmail( $value ) {
		$this->setProp( 'customerEmail', $value );
	}
	
	public function getCustomFields(): array {
		return (array) $this->getProp( 'customFields' );
	}
	
	public function setCustomFields( $value ) {
		$this->setProp( 'customFields', (array) $value );
	}
	
	public function getConvertedOrderId(): int {
		return (int) $this->getProp( 'convertedOrderId' );
	}
	
	public function setConvertedOrderId( $value ) {
		$this->setProp( 'convertedOrderId', (int) $value );
	}
	
	public function getMeta( $key ) {
		return $this->metaData[ $key ] ?? '';
	}
	
	public function addMetaData( $key, $value, $unique = false ) {
		if ( $unique && isset( $this->metaData[ $key ] ) ) {
			return;
		}
		$this->metaData[ $key ] = $value;
	}
	
	public function updateMetaData( $key, $value ) {
		$this->metaData[ $key ] = $value;
	}
	
	public function deleteMetaData( $key ) {
		unset( $this->metaData[ $key ] );
		if ( $this->getId() ) {
			delete_post_meta( $this->getId(), $key );
		}
	}
	
	public function getMetaData(): array {
		return $this->metaData;
	}
	
		public function getCustomerName() {
		$fields = $this->getCustomFields();
		
		// Helper to extract value if it's an array
		$getValue = function($field) {
			if ( is_array( $field ) && isset( $field['value'] ) ) {
				return $field['value'];
			}
			return $field;
		};

		if ( isset( $fields['name'] ) ) {
			return $getValue( $fields['name'] );
		}
		if ( isset( $fields['first_name'] ) ) {
			return $getValue( $fields['first_name'] );
		}
		return $this->getMeta( '_name' ) ?: $this->getMeta( '_first_name' );
	}
}