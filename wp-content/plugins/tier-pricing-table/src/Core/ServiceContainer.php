<?php namespace TierPricingTable\Core;

use Exception;
use TierPricingTable\Admin\Notifications\Notifications;
use TierPricingTable\Admin\Tips\TipsManager;
use TierPricingTable\Settings\Settings;

class ServiceContainer {
	
	private $services = array();
	
	private static $instance;
	
	private function __construct() {}
	
	public static function getInstance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		
		return self::$instance;
	}
	
	public function add( $name, $instance ) {
		$this->services[ $name ] = $instance;
	}
	
	public function initService( $className, $dependencies = [] ) {
		
		$className = apply_filters( 'tiered_pricing_table/container/service_instance', $className );
		
		$this->add( $className, new $className( ...$dependencies ) );
	}
	
	/**
	 * Get service
	 *
	 * @param $name
	 *
	 * @return mixed
	 * @throws Exception
	 */
	public function get( $name ) {
		if ( ! empty( $this->services[ $name ] ) ) {
			return $this->services[ $name ];
		}
		
		throw new Exception( 'Undefined service' );
	}
	
	/**
	 * Get fileManager
	 *
	 * @return FileManager
	 */
	public function getFileManager(): ?FileManager {
		try {
			return $this->get( 'fileManager' );
		} catch ( Exception $e ) {
			return null;
		}
	}
	
	/**
	 * Get Settings
	 *
	 * @return Settings
	 */
	public function getSettings(): ?Settings {
		try {
			return $this->get( 'settings' );
		} catch ( Exception $e ) {
			return null;
		}
	}
	
	/**
	 * Get AdminNotifier
	 *
	 * @return AdminNotifier
	 */
	public function getAdminNotifier(): ?AdminNotifier {
		try {
			return $this->get( 'adminNotifier' );
		} catch ( Exception $e ) {
			return null;
		}
	}
	
	/**
	 * Get Cache
	 *
	 * @return Cache
	 */
	public function getCache(): ?Cache {
		try {
			return $this->get( 'cache' );
		} catch ( Exception $e ) {
			return null;
		}
	}
	
	public function getNotificationManager(): ?Notifications {
		try {
			return $this->get( Notifications::class );
		} catch ( Exception $e ) {
			return null;
		}
	}
}
