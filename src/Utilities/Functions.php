<?php
/**
 * Helper Functions
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

namespace ArrayPress\RegisterReports;

if ( ! function_exists( __NAMESPACE__ . '\\register_reports' ) ) {
	/**
	 * Register a new reports page.
	 *
	 * @param string $id     Unique identifier.
	 * @param array  $config Configuration array.
	 *
	 * @return Reports
	 */
	function register_reports( string $id, array $config ): Reports {
		return new Reports( $id, $config );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_reports' ) ) {
	/**
	 * Get a registered reports instance.
	 *
	 * @param string $id Reports ID.
	 *
	 * @return Reports|null
	 */
	function get_reports( string $id ): ?Reports {
		return Registry::instance()->get( $id );
	}
}