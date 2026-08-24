<?php
/**
 * Component Data Over REST
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Rest;

use ArrayPress\RegisterReports\Format;
use ArrayPress\RegisterReports\Registry;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Answering with what a component should show.
 *
 * One component at a time, or a whole tab's worth in a single request — which
 * is what the page actually does on load, because a report with nine tiles
 * should not be nine round trips.
 *
 * A consumer's callback is run inside a try/catch here. It is somebody else's
 * code reached from an endpoint, and an exception from it should be one
 * failed component rather than a dead page.
 */
trait ComponentData {

/**
	 * Get component data.
	 */
	public static function get_component_data( WP_REST_Request $request ) {
		$report_id    = $request->get_param( 'report_id' );
		$component_id = $request->get_param( 'component_id' );
		$report       = Registry::instance()->get( $report_id );

		$date_range = self::get_date_range_from_request( $request, $report );
		$components = $report->get_components();
		$component  = null;

		foreach ( $components as $tab_components ) {
			if ( isset( $tab_components[ $component_id ] ) ) {
				$component = $tab_components[ $component_id ];
				break;
			}
		}

		if ( ! $component ) {
			return new WP_Error( 'invalid_component', __( 'Invalid component.', 'arraypress' ), [ 'status' => 404 ] );
		}

		$callback = $component['data_callback'] ?? null;

		if ( ! $callback || ! is_callable( $callback ) ) {
			return new WP_Error( 'invalid_callback', __( 'No data callback.', 'arraypress' ), [ 'status' => 400 ] );
		}

		try {
			$data = call_user_func( $callback, $date_range, $component );

			return new WP_REST_Response( [
				'success' => true,
				'data'    => $data,
				'type'    => $component['type'] ?? 'unknown',
			] );
		} catch ( Exception $e ) {
			return new WP_Error( 'callback_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

/**
	 * Get all components data for refresh.
	 *
	 * Returns data in a format optimized for JS refresh.
	 */
	public static function get_all_components_data( WP_REST_Request $request ) {
		$report_id = $request->get_param( 'report_id' );
		$tab       = $request->get_param( 'tab' );
		$report    = Registry::instance()->get( $report_id );

		if ( ! $report ) {
			return new WP_Error( 'invalid_report', __( 'Invalid report.', 'arraypress' ), [ 'status' => 404 ] );
		}

		$date_range     = self::get_date_range_from_request( $request, $report );
		$all_components = $report->get_components();

		// If no tab specified, use first tab
		if ( empty( $tab ) ) {
			$tabs = $report->get_tabs();
			$tab  = array_key_first( $tabs );
		}

		if ( ! isset( $all_components[ $tab ] ) ) {
			return new WP_Error( 'invalid_tab', __( 'Invalid tab.', 'arraypress' ), [ 'status' => 404 ] );
		}

		// Collect filter values from request
		$filters    = [];
		$all_params = $request->get_params();
		foreach ( $all_params as $key => $value ) {
			if ( str_starts_with( $key, 'filter_' ) ) {
				$filter_key             = substr( $key, 7 ); // Remove 'filter_' prefix
				$filters[ $filter_key ] = sanitize_text_field( $value );
			}
		}
		$date_range['filters'] = $filters;

		$components_data = [];

		foreach ( $all_components[ $tab ] as $component_id => $component ) {
			$callback = $component['data_callback'] ?? null;
			$type     = $component['type'] ?? 'unknown';

			if ( ! $callback || ! is_callable( $callback ) ) {
				continue;
			}

			try {
				$raw_data = call_user_func( $callback, $date_range, $component );

				// Format response based on component type
				switch ( $type ) {
					case 'tile':
						$value          = $raw_data['value'] ?? 0;
						$previous_value = $raw_data['previous_value'] ?? null;

						// Auto-calculate change
						$change           = $raw_data['change'] ?? null;
						$change_direction = $raw_data['change_direction'] ?? null;

						if ( $change === null && $previous_value !== null && is_numeric( $value ) && is_numeric( $previous_value ) && (float) $previous_value !== 0.0 ) {
							$change           = ( ( $value - $previous_value ) / abs( $previous_value ) ) * 100;
							$change_direction = $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'neutral' );
							$change           = abs( $change );
						}

						// Format value
						$format    = $component['value_format'] ?? 'number';
						$currency  = $component['currency'] ?? 'USD';
						$formatted = self::format_value_for_api( $value, $format, $currency );

						$components_data[ $component_id ] = [
							'type'             => 'tile',
							'value'            => $value,
							'formatted_value'  => $formatted,
							'change'           => $change,
							'change_direction' => $change_direction,
						];
						break;

					case 'chart':
						$components_data[ $component_id ] = [
							'type'     => 'chart',
							'labels'   => $raw_data['labels'] ?? [],
							'datasets' => $raw_data['datasets'] ?? [],
						];
						break;

					case 'table':
						$components_data[ $component_id ] = [
							'type' => 'table',
							'rows' => $raw_data['rows'] ?? $raw_data ?? [],
						];
						break;

					default:
						$components_data[ $component_id ] = [
							'type' => $type,
							'data' => $raw_data,
						];
				}
			} catch ( Exception $e ) {
				$components_data[ $component_id ] = [
					'type'  => $type,
					'error' => $e->getMessage(),
				];
			}
		}

		// Also process tiles within tiles_group components
		foreach ( $all_components[ $tab ] as $component_id => $component ) {
			if ( ( $component['type'] ?? '' ) !== 'tiles_group' ) {
				continue;
			}

			$tiles = $component['tiles'] ?? [];
			foreach ( $tiles as $tile_id => $tile ) {
				$tile_callback = $tile['data_callback'] ?? null;
				if ( ! $tile_callback || ! is_callable( $tile_callback ) ) {
					continue;
				}

				$full_tile_id = $component_id . '_' . $tile_id;

				try {
					$raw_data       = call_user_func( $tile_callback, $date_range, $tile );
					$value          = $raw_data['value'] ?? 0;
					$previous_value = $raw_data['previous_value'] ?? null;

					// Auto-calculate change
					$change           = $raw_data['change'] ?? null;
					$change_direction = $raw_data['change_direction'] ?? null;

					if ( $change === null && $previous_value !== null && is_numeric( $value ) && is_numeric( $previous_value ) && (float) $previous_value !== 0.0 ) {
						$change           = ( ( $value - $previous_value ) / abs( $previous_value ) ) * 100;
						$change_direction = $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'neutral' );
						$change           = abs( $change );
					}

					// Format value
					$format    = $tile['value_format'] ?? 'number';
					$currency  = $tile['currency'] ?? 'USD';
					$formatted = self::format_value_for_api( $value, $format, $currency );

					$components_data[ $full_tile_id ] = [
						'type'             => 'tile',
						'value'            => $value,
						'formatted_value'  => $formatted,
						'change'           => $change,
						'change_direction' => $change_direction,
					];
				} catch ( Exception $e ) {
					$components_data[ $full_tile_id ] = [
						'type'  => 'tile',
						'error' => $e->getMessage(),
					];
				}
			}
		}

		return new WP_REST_Response( [
			'success'    => true,
			'components' => $components_data,
		] );
	}

/**
	 * Format a value for an API response.
	 *
	 * The same way the page formats it. These were two implementations and
	 * they had drifted, so a tile read 1,204.50 on load and 1,204 after the
	 * first auto-refresh — the number appeared to change on its own.
	 *
	 * @param mixed  $value    The value.
	 * @param string $format   The format.
	 * @param string $currency Currency code.
	 *
	 * @return string
	 */
	private static function format_value_for_api( $value, string $format, string $currency = 'USD' ): string {
		return Format::value( $value, $format, $currency );
	}
}
