<?php namespace TierPricingTable\Addons\Tools\API;

use TierPricingTable\Addons\GlobalTieredPricing\CPT\GlobalTieredPricingCPT;
use WP_REST_Request;

class ToolsEndpoints {
	
	const NAMESPACE = 'tier-pricing-table/features/tools/v1';
	
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}
	
	public function registerRoutes() {
		
		register_rest_route( self::NAMESPACE, '/cleanup', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cleanupData' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
		
		register_rest_route( self::NAMESPACE, '/reset_settings', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resetSettings' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
		
		register_rest_route( self::NAMESPACE, '/delete_global_rules', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'deleteGlobalRules' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
		
		register_rest_route( self::NAMESPACE, '/roles', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getRoles' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
		
		register_rest_route( self::NAMESPACE, '/roles_capabilities', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getRolesAndCapabilities' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'createRole' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'updateRoleCapabilities' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'deleteRole' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			),
		) );
	}
	
	public function getRoles() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles();
		}
		$roles = $wp_roles->get_names();
		
		$response = array();
		foreach ( $roles as $key => $name ) {
			$response[] = array(
				'value' => $key,
				'label' => $name,
			);
		}
		
		return rest_ensure_response( $response );
	}
	
	public function cleanupData( WP_REST_Request $request ) {
		$cleanStandard = filter_var( $request->get_param( 'clean_standard' ), FILTER_VALIDATE_BOOLEAN );
		$cleanUsers    = filter_var( $request->get_param( 'clean_users' ), FILTER_VALIDATE_BOOLEAN );
		$cleanAllRoles = filter_var( $request->get_param( 'clean_all_roles' ), FILTER_VALIDATE_BOOLEAN );
		
		if ( $cleanAllRoles ) {
			global $wp_roles;
			if ( ! isset( $wp_roles ) ) {
				$wp_roles = new \WP_Roles();
			}
			$roles = array_keys( $wp_roles->get_names() );
		} else {
			$roles = $request->get_param( 'roles' ); // array of role keys
		}
		
		$categories    = $request->get_param( 'categories' ); // array of term ids
		$products      = $request->get_param( 'products' ); // array of product ids
		
		$metaKeysToDelete = array();
		
		if ( $cleanStandard ) {
			$metaKeysToDelete = array_merge( $metaKeysToDelete, array(
				'_fixed_price_rules',
				'_percentage_price_rules',
				'_tiered_price_rules_type',
				'_tiered_price_minimum_qty',
				'_tiered_pricing_maximum_quantity',
				'_tiered_pricing_group_of_quantity',
				'_tiered_pricing_template',
				'_tiered_pricing_base_unit_name',
				'_tiered_pricing_default_variation_id',
				'_tiered_pricing_variable_product_same_prices',
			) );
		}
		
		if ( ! empty( $roles ) && is_array( $roles ) ) {
			foreach ( $roles as $roleKey ) {
				$roleKey          = sanitize_key( $roleKey );
				$metaKeysToDelete = array_merge( $metaKeysToDelete, array(
					"_{$roleKey}_tiered_price_pricing_type",
					"_{$roleKey}_tiered_price_regular_price",
					"_{$roleKey}_tiered_price_sale_price",
					"_{$roleKey}_tiered_price_discount",
					"_{$roleKey}_tiered_price_discount_type",
					"_{$roleKey}_tiered_price_rules_type",
					"_{$roleKey}_percentage_price_rules",
					"_{$roleKey}_fixed_price_rules",
					"_{$roleKey}_tiered_price_minimum_qty",
					"_{$roleKey}_tiered_pricing_maximum_quantity",
					"_{$roleKey}_tiered_pricing_group_of_quantity",
				) );
			}
		}
		
		$deletedCount = 0;
		
		if ( ! empty( $metaKeysToDelete ) ) {
			global $wpdb;
			$metaKeysIn = "'" . implode( "','", array_map( 'esc_sql', $metaKeysToDelete ) ) . "'";
			
			$postIds = array();
			
			if ( ! empty( $products ) && is_array( $products ) ) {
				$postIds = array_map( 'intval', $products );
			}
			
			if ( ! empty( $categories ) && is_array( $categories ) ) {
				$categoryIds = array_map( 'intval', $categories );
				$args        = array(
					'limit'    => - 1,
					'return'   => 'ids',
					'category' => array_map( function ( $id ) {
						$term = get_term( $id, 'product_cat' );
						
						return $term ? $term->slug : '';
					}, $categoryIds ),
				);
				
				$catProducts = wc_get_products( $args );
				$postIds     = array_unique( array_merge( $postIds, $catProducts ) );
			}
			
			if ( empty( $products ) && empty( $categories ) ) {
				$query        = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($metaKeysIn)";
				$deletedCount = $wpdb->query( $query );
			} else {
				if ( ! empty( $postIds ) ) {
					$postIdsIn    = implode( ',', $postIds );
					$query        = "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($metaKeysIn) AND post_id IN ($postIdsIn)";
					$deletedCount = $wpdb->query( $query );
				}
			}
			
			if ( $deletedCount === false ) {
				return rest_ensure_response( array(
					'success' => false,
					'message' => 'Database error during cleanup.',
				) );
			}
		}
		
		if ( $cleanUsers ) {
			global $wpdb;
			
			$userMetaLikeQueries = array(
				"meta_key LIKE '\_user\_%\_tiered_price\_pricing\_type'",
				"meta_key LIKE '\_user\_%\_tiered_price\_regular\_price'",
				"meta_key LIKE '\_user\_%\_tiered_price\_sale\_price'",
				"meta_key LIKE '\_user\_%\_tiered_price\_discount'",
				"meta_key LIKE '\_user\_%\_tiered_price\_discount\_type'",
				"meta_key LIKE '\_user\_%\_tiered_price\_rules\_type'",
				"meta_key LIKE '\_user\_%\_percentage\_price\_rules'",
				"meta_key LIKE '\_user\_%\_fixed\_price\_rules'",
				"meta_key LIKE '\_user\_%\_tiered_price\_minimum\_qty'",
				"meta_key LIKE '\_user\_%\_tiered_price\_tax\_status'",
				"meta_key LIKE '\_user\_%\_tiered_price\_tax\_class'"
			);
			
			$likeQuery = implode( ' OR ', $userMetaLikeQueries );
			
			if ( empty( $products ) && empty( $categories ) ) {
				$query = "DELETE FROM {$wpdb->postmeta} WHERE ($likeQuery)";
				$userDeletedCount = $wpdb->query( $query );
			} else {
				if ( ! empty( $postIds ) ) {
					$postIdsIn = implode( ',', $postIds );
					$query = "DELETE FROM {$wpdb->postmeta} WHERE ($likeQuery) AND post_id IN ($postIdsIn)";
					$userDeletedCount = $wpdb->query( $query );
				}
			}
			
			if ( $userDeletedCount !== false ) {
				$deletedCount += $userDeletedCount;
			}
		}
		
		delete_option( \TierPricingTable\Admin\Tips\Tip::SEEN_TIPS_OPTION_KEY );
		
		$message = sprintf( 'Successfully cleaned up %d product records.', $deletedCount );
		
		return rest_ensure_response( array(
			'success'      => true,
			'deleted_rows' => $deletedCount,
			'message'      => $message,
		) );
	}
	
	public function resetSettings( WP_REST_Request $request ) {
		global $wpdb;
		
		if ( class_exists( '\TierPricingTable\Settings\Settings' ) ) {
			\TierPricingTable\Settings\Settings::deleteOptions();
		}
		
		// Wipe all other plugin settings that might be stored via different addons
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tier_pricing_table_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tiered_pricing_table/%'" );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tpt_custom_table_columns%'" );
		
		return rest_ensure_response( array(
			'success' => true,
			'message' => 'Plugin settings were successfully reset to defaults.',
		) );
	}
	
	public function deleteGlobalRules( WP_REST_Request $request ) {
		$globalRules = get_posts( array(
			'numberposts' => - 1,
			'post_type'   => GlobalTieredPricingCPT::SLUG,
			'post_status' => 'any',
			'fields'      => 'ids',
		) );
		
		$deletedCount = 0;
		foreach ( $globalRules as $ruleId ) {
			if ( wp_delete_post( $ruleId, true ) ) {
				$deletedCount ++;
			}
		}
		
		return rest_ensure_response( array(
			'success'      => true,
			'deleted_rows' => $deletedCount,
			'message'      => sprintf( 'Successfully deleted %d global rules.', $deletedCount ),
		) );
	}
	
	public function getRolesAndCapabilities() {
		global $wp_roles;
		if ( ! isset( $wp_roles ) ) {
			$wp_roles = new \WP_Roles();
		}
		
		$allRoles = $wp_roles->roles;
		
		$allCapabilities = array();
		foreach ( $allRoles as $role ) {
			if ( ! empty( $role['capabilities'] ) ) {
				foreach ( $role['capabilities'] as $cap => $granted ) {
					$allCapabilities[ $cap ] = true;
				}
			}
		}
		
		$rolesResponse = array();
		foreach ( $allRoles as $key => $roleData ) {
			$rolesResponse[] = array(
				'key'          => $key,
				'name'         => $roleData['name'],
				'capabilities' => ! empty( $roleData['capabilities'] ) ? $roleData['capabilities'] : array(),
			);
		}
		
		$capabilitiesList = array_keys( $allCapabilities );
		sort( $capabilitiesList );
		
		return rest_ensure_response( array(
			'roles'            => $rolesResponse,
			'all_capabilities' => $capabilitiesList,
		) );
	}
	
	public function createRole( WP_REST_Request $request ) {
		$roleKey      = sanitize_key( $request->get_param( 'key' ) );
		$roleName     = sanitize_text_field( $request->get_param( 'name' ) );
		$capabilities = $request->get_param( 'capabilities' );
		
		if ( empty( $roleKey ) || empty( $roleName ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => 'Role key and name are required.' ) );
		}
		
		if ( get_role( $roleKey ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => 'Role already exists.' ) );
		}
		
		$capsToSave = array();
		if ( ! empty( $capabilities ) && is_array( $capabilities ) ) {
			foreach ( $capabilities as $cap ) {
				$capsToSave[ $cap ] = true;
			}
		}
		
		$result = add_role( $roleKey, $roleName, $capsToSave );
		
		if ( null !== $result ) {
			return rest_ensure_response( array( 'success' => true, 'message' => 'Role created successfully.' ) );
		}
		
		return rest_ensure_response( array( 'success' => false, 'message' => 'Failed to create role.' ) );
	}
	
	public function updateRoleCapabilities( WP_REST_Request $request ) {
		$roleKey      = sanitize_key( $request->get_param( 'key' ) );
		$capabilities = $request->get_param( 'capabilities' );
		
		$role = get_role( $roleKey );
		if ( ! $role ) {
			return rest_ensure_response( array( 'success' => false, 'message' => 'Role not found.' ) );
		}
		
		foreach ( $role->capabilities as $cap => $granted ) {
			$role->remove_cap( $cap );
		}
		
		if ( ! empty( $capabilities ) && is_array( $capabilities ) ) {
			foreach ( $capabilities as $cap ) {
				$role->add_cap( $cap );
			}
		}
		
		return rest_ensure_response( array( 'success' => true, 'message' => 'Role capabilities updated.' ) );
	}
	
	public function deleteRole( WP_REST_Request $request ) {
		$roleKey = sanitize_key( $request->get_param( 'key' ) );
		
		if ( empty( $roleKey ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => 'Role key is required.' ) );
		}
		
		$protected_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		if ( in_array( $roleKey, $protected_roles ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'message' => 'Cannot delete a core WordPress role.',
			) );
		}
		
		$role = get_role( $roleKey );
		if ( ! $role ) {
			return rest_ensure_response( array( 'success' => false, 'message' => 'Role not found.' ) );
		}
		
		remove_role( $roleKey );
		
		return rest_ensure_response( array( 'success' => true, 'message' => 'Role deleted successfully.' ) );
	}
}
