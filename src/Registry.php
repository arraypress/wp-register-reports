<?php
/**
 * Reports Registry
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports;

/**
 * Class Registry
 *
 * Singleton registry for managing multiple report pages.
 */
class Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $instance = null;

	/**
	 * Registered report pages.
	 *
	 * @var array<string, Reports>
	 */
	private array $reports = [];

	/**
	 * Get singleton instance.
	 *
	 * @return Registry
	 */
	public static function instance(): Registry {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor for singleton.
	 */
	private function __construct() {
	}

	/**
	 * Register a reports instance.
	 *
	 * @param string  $id      Unique identifier.
	 * @param Reports $reports Reports instance.
	 *
	 * @return void
	 */
	public static function register( string $id, Reports $reports ): void {
		self::instance()->reports[ $id ] = $reports;
	}

	/**
	 * Get a registered reports page.
	 *
	 * @param string $id Reports ID.
	 *
	 * @return Reports|null
	 */
	public function get( string $id ): ?Reports {
		return $this->reports[ $id ] ?? null;
	}

	/**
	 * Check if a reports page is registered.
	 *
	 * @param string $id Reports ID.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->reports[ $id ] );
	}

	/**
	 * Get all registered reports pages.
	 *
	 * @return array<string, Reports>
	 */
	public function all(): array {
		return $this->reports;
	}

	/**
	 * Unregister a reports page.
	 *
	 * @param string $id Reports ID.
	 *
	 * @return bool
	 */
	public function unregister( string $id ): bool {
		if ( isset( $this->reports[ $id ] ) ) {
			unset( $this->reports[ $id ] );

			return true;
		}

		return false;
	}

}
