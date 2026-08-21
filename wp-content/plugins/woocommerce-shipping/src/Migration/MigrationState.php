<?php
/**
 * WCS&T to WCShipping migration states.
 *
 * @package Automattic\WCShipping\Migration
 */

namespace Automattic\WCShipping\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for WCS&T to WCShipping migration states.
 */
class MigrationState {
	// These are used for WCS&T to WCShipping migration. 1-11 is not applicable to WCShipping but we include the whole range for clarity.
	public const NOT_STARTED              = 1;
	public const STARTED                  = 2;
	public const ERROR_STARTED            = 3;
	public const INSTALLING               = 4;
	public const ERROR_INSTALLING         = 5;
	public const ACTIVATING               = 6;
	public const ERROR_ACTIVATING         = 7;
	public const DB_MIGRATION             = 8;
	public const ERROR_DB_MIGRATION       = 9;
	public const DEACTIVATING             = 10;
	public const ERROR_DEACTIVATING       = 11;
	public const INSTALLATION_COMPLETED   = 12;
	public const DATA_MIGRATION_STARTED   = 13;
	public const DATA_MIGRATION_COMPLETED = 14;

	public const LABELS_TYPE   = 'labels';
	public const SETTINGS_TYPE = 'settings';
	public const ALL_TYPE      = 'all';
	public const NO_TYPE       = 'none';

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wcshipping_labels_migration_completed', array( __CLASS__, 'labels_migration_completed' ) );
		add_action( 'wcshipping_settings_migration_completed', array( __CLASS__, 'settings_migration_completed' ) );
	}

	/**
	 * Check if the given state is valid.
	 *
	 * @param int $state The migration state.
	 *
	 * @return bool
	 */
	public static function is_valid_state( int $state ): bool {
		return in_array(
			$state,
			array(
				self::NOT_STARTED,
				self::STARTED,
				self::ERROR_STARTED,
				self::INSTALLING,
				self::ERROR_INSTALLING,
				self::ACTIVATING,
				self::ERROR_ACTIVATING,
				self::DB_MIGRATION,
				self::ERROR_DB_MIGRATION,
				self::DEACTIVATING,
				self::ERROR_DEACTIVATING,
				self::INSTALLATION_COMPLETED,
				self::DATA_MIGRATION_STARTED,
				self::DATA_MIGRATION_COMPLETED,
			),
			true
		);
	}

	/**
	 * Check if the given type is valid.
	 *
	 * @param string $type The migration type.
	 */
	public static function is_valid_type( string $type ): bool {
		return in_array(
			$type,
			array(
				self::LABELS_TYPE,
				self::SETTINGS_TYPE,
				self::ALL_TYPE,
				self::NO_TYPE,
			),
			true
		);
	}

	/**
	 * Set the migration state in the database.
	 *
	 * @param int $state The migration state.
	 * @return void
	 */
	public static function set_state( int $state ): void {
		if ( ! self::is_valid_state( $state ) ) {
			return;
		}

		// Once the data migration is completed, we can remove the migration required and processes to run flags.
		if ( self::DATA_MIGRATION_COMPLETED === $state ) {
			delete_option( 'wcst_data_migration_required' );
			delete_option( 'wcst_data_migration_processes_to_run' );
		}

		update_option( 'wcshipping_migration_state', $state, false );
	}

	/**
	 * Get the migration state from the database.
	 *
	 * @return int|bool Current state in the DB or false if not set.
	 */
	public static function get_state(): int {
		return get_option( 'wcshipping_migration_state' );
	}

	/**
	 * Get data migration required bool from DB.
	 *
	 * @return bool True if migration is required, false otherwise.
	 */
	public static function is_data_migration_required(): bool {
		if ( self::NO_TYPE === self::get_data_migration_required_type() ) {
			return false;
		}

		return (bool) self::get_data_migration_required_type();
	}

	/**
	 * Set the data migration required type in the database.
	 *
	 * @param string $type The data migration required type.
	 * @return void
	 */
	public static function set_data_migration_required_type( string $type ): void {
		if ( ! self::is_valid_type( $type ) ) {
			return;
		}

		// We have 2 types of migrations that can run async so we need to set a flag to adjust as each completes.
		switch ( $type ) {
			case self::LABELS_TYPE:
			case self::SETTINGS_TYPE:
				update_option( 'wcst_data_migration_processes_to_run', 1, false );
				break;
			case self::ALL_TYPE:
				update_option( 'wcst_data_migration_processes_to_run', 2, false );
				break;
		}

		update_option( 'wcst_data_migration_required', $type );
	}

	/**
	 * Get the number of data migration processes to run from the database.
	 *
	 * @return int The number data migration processes to run, -1 if nothing has been set.
	 */
	public static function get_data_migration_processes_to_run(): int {
		return (int) get_option( 'wcst_data_migration_processes_to_run', '-1' );
	}

	/**
	 * Get the data migration required type from the database.
	 *
	 * @return string|false The data migration required type, or false if not set.
	 */
	public static function get_data_migration_required_type() {
		return get_option( 'wcst_data_migration_required' );
	}

	/**
	 * Determine if the migration is complete and set the state accordingly.
	 */
	public static function maybe_mark_migration_complete(): void {
		if ( self::DATA_MIGRATION_STARTED !== self::get_state() ) {
			return;
		}
		$processes_to_run = self::get_data_migration_processes_to_run();
		if ( 0 > $processes_to_run ) {
			switch ( self::get_data_migration_required_type() ) {
				case self::LABELS_TYPE:
				case self::SETTINGS_TYPE:
					$processes_to_run = 1;
					break;
				case self::ALL_TYPE:
					$processes_to_run = 2;
					break;
				default:
					$processes_to_run = 0;
					break;
			}
		}

		// We subtract 1 from the processes to run each time a migration completes, once it reaches 0 we can mark the migration as complete.
		if ( 0 >= ( $processes_to_run - 1 ) ) {
			self::set_state( self::DATA_MIGRATION_COMPLETED );
		} else {
			update_option( 'wcst_data_migration_processes_to_run', $processes_to_run - 1, false );
		}
	}

	/**
	 * When label migration finished, decrement the processes to run and mark the migration as complete if needed.
	 */
	public static function labels_migration_completed(): void {
		self::maybe_mark_migration_complete();
	}

	/**
	 * When settings migration finished, decrement the processes to run and mark the migration as complete if needed.
	 */
	public static function settings_migration_completed(): void {
		self::maybe_mark_migration_complete();
	}
}
