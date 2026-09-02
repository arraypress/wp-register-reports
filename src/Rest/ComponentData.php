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

		// Collect filter values from request. Element-wise for a list: a
		// multiselect arrives as one, and sanitize_text_field() on an array
		// hands the callback the word Array as a value nobody chose.
		$filters    = [];
		$all_params = $request->get_params();
		foreach ( $all_params as $key => $value ) {
			if ( str_starts_with( $key, 'filter_' ) ) {
				$filter_key             = substr( $key, 7 ); // Remove 'filter_' prefix
				$filters[ $filter_key ] = self::sanitize_filter_value( $value );
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

					case 'progress':
						$components_data[ $component_id ] = self::progress_payload( $raw_data, $component );
						break;

					case 'breakdown':
						$components_data[ $component_id ] = self::breakdown_payload( $raw_data, $component );
						break;

					case 'stat_list':
						$components_data[ $component_id ] = [
							'type' => 'stat_list',
							'rows' => self::stat_rows( (array) ( $raw_data['rows'] ?? $raw_data ?? [] ), $component ),
						];
						break;

					case 'table':
						$rows = (array) ( $raw_data['rows'] ?? $raw_data ?? [] );

						$components_data[ $component_id ] = [
							'type' => 'table',
							'rows' => self::format_rows( $rows, $component ),

							// The values as the callback gave them, for the
							// {placeholder} substitutions in a row action's
							// URL. An id formatted for display is 1,204 and
							// links to nothing.
							'raw'  => self::scalar_rows( $rows ),
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

	/**
	 * One number against a target, ready to draw.
	 *
	 * The division and the formatting happen here, with the same code that
	 * rendered the page, so the script has a percentage and two strings and
	 * nothing to work out. A second implementation in JavaScript is how the
	 * table came to print dollars on a site set to euros.
	 *
	 * @param array<string, mixed> $raw       What the callback returned.
	 * @param array<string, mixed> $component The component's configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function progress_payload( array $raw, array $component ): array {
		// Kept as given for the formatter, cast only for the arithmetic --
		// the same two copies the page renderer keeps, for the same reason.
		// Format::value() reads an int as minor units and a fraction as a
		// decimal amount, so formatting the cast copy showed $49.00 on load
		// and $4,900.00 after the first refresh.
		$raw_value  = $raw['value'] ?? 0;
		$raw_target = $raw['target'] ?? $component['target'] ?? 0;

		$value    = (float) $raw_value;
		$target   = (float) $raw_target;
		$format   = (string) ( $component['value_format'] ?? 'number' );
		$currency = (string) ( $component['currency'] ?? 'USD' );

		// A target of nought is a target nobody set, and dividing by it takes
		// the request with it.
		$percent = $target > 0 ? min( 100, max( 0, ( $value / $target ) * 100 ) ) : 0.0;

		return [
			'type'              => 'progress',
			'percent'           => $percent,
			'formatted_percent' => number_format_i18n( $percent, $percent < 10 ? 1 : 0 ) . '%',
			'formatted_figures' => sprintf(
				/* translators: 1: the value reached, 2: the target */
				__( '%1$s of %2$s', 'arraypress' ),
				Format::value( $raw_value, $format, $currency ),
				Format::value( $raw_target, $format, $currency )
			),
		];
	}

	/**
	 * A ranked list, with each bar's width already worked out.
	 *
	 * Widths are against the largest row rather than the total, so the top
	 * row always fills. Against the total, eight roughly equal things are
	 * eight identical stubs and the shape says nothing — which is the failure
	 * a breakdown exists to avoid.
	 *
	 * @param array<string, mixed> $raw       What the callback returned.
	 * @param array<string, mixed> $component The component's configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function breakdown_payload( array $raw, array $component ): array {
		$rows     = (array) ( $raw['rows'] ?? $raw ?? [] );
		$format   = (string) ( $component['value_format'] ?? 'number' );
		$currency = (string) ( $component['currency'] ?? 'USD' );

		$values  = array_map( static fn( $row ): float => (float) ( ( (array) $row )['value'] ?? 0 ), $rows );
		$largest = $values ? ( max( $values ) ?: 1.0 ) : 1.0;
		$total   = $values ? ( array_sum( $values ) ?: 1.0 ) : 1.0;

		$payload = [];

		foreach ( $rows as $row ) {
			$row = (array) $row;

			// The bar maths needs a float; the formatter must not have one.
			// Cast first and every amount looks like a decimal, so a row of
			// 4900 minor units that read 49.00 on load read 4,900.00 after
			// the first refresh -- the number appeared to change on its own.
			$value = (float) ( $row['value'] ?? 0 );

			$payload[] = [
				'label'           => wp_kses_post( (string) ( $row['label'] ?? '' ) ),
				'width'           => round( ( $value / $largest ) * 100, 2 ),
				'share'           => number_format_i18n( ( $value / $total ) * 100, 1 ) . '%',
				'formatted_value' => Format::value( $row['value'] ?? 0, $format, $currency ),
			];
		}

		return [
			'type' => 'breakdown',
			'rows' => $payload,
		];
	}

	/**
	 * Label and value rows, formatted.
	 *
	 * @param array<int, mixed>    $rows      What the callback returned.
	 * @param array<string, mixed> $component The component's configuration.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function stat_rows( array $rows, array $component ): array {
		$currency = (string) ( $component['currency'] ?? 'USD' );
		$payload  = [];

		foreach ( $rows as $row ) {
			$row = (array) $row;

			$payload[] = [
				'label'           => wp_kses_post( (string) ( $row['label'] ?? '' ) ),
				'formatted_value' => Format::value(
					$row['value'] ?? '',
					(string) ( $row['format'] ?? $component['value_format'] ?? 'number' ),
					$currency
				),
			];
		}

		return $payload;
	}

	/**
	 * A table's rows with only their scalar values kept.
	 *
	 * What a row action's URL substitutes its {placeholders} from. Anything
	 * that is not a number, a string or a boolean cannot go in a URL and has
	 * no business being sent to the browser twice.
	 *
	 * @param array<int, mixed> $rows Rows as the callback returned them.
	 *
	 * @return array<int, array<string, scalar>>
	 */
	private static function scalar_rows( array $rows ): array {
		$scalar = [];

		foreach ( $rows as $row ) {
			$scalar[] = array_filter( (array) $row, 'is_scalar' );
		}

		return $scalar;
	}

	/**
	 * A table's rows, formatted and sanitized the way the page renders them.
	 *
	 * The refresh used to hand raw values to the browser and let the script
	 * format them, which meant the same table was formatted by two different
	 * pieces of code that did not agree. The script printed dollars on a site
	 * configured for euros, used the browser's locale for separators and the
	 * browser's date format for dates, and — because it wrote the cells with
	 * .html() while PHP writes them through wp_kses_post() — a value holding
	 * a script tag was inert on load and ran on the first auto-refresh.
	 *
	 * Formatting here fixes all four at once: there is one formatter, it is
	 * the one that already renders the page, and the payload arrives ready to
	 * put on screen.
	 *
	 * @param array<int, mixed>    $rows      Rows as the callback returned them.
	 * @param array<string, mixed> $component The component's configuration.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function format_rows( array $rows, array $component ): array {
		$currency = (string) ( $component['currency'] ?? 'USD' );
		$formats  = [];

		// Columns are either a list of names or a map of name => label, and a
		// label is either a string or an array carrying a format. All three
		// shapes are configuration a consumer already writes, so all three
		// are read here rather than one being declared correct.
		foreach ( (array) ( $component['columns'] ?? [] ) as $key => $column ) {
			$name = is_string( $key ) ? $key : (string) $column;

			$formats[ $name ] = is_array( $column ) ? (string) ( $column['format'] ?? '' ) : '';
		}

		$formatted = [];

		foreach ( $rows as $row ) {
			$cells = [];

			foreach ( (array) $row as $key => $value ) {
				// A cell has to end up as a string in a table, and there is
				// no honest way to turn an array into one — casting it warns
				// and prints the word Array, which is worse than an empty
				// cell because it looks like data.
				if ( ! is_scalar( $value ) && null !== $value ) {
					continue;
				}

				$format = $formats[ $key ] ?? '';

				$cells[ $key ] = wp_kses_post(
					'' === $format ? (string) $value : Format::value( $value, $format, $currency )
				);
			}

			$formatted[] = $cells;
		}

		return $formatted;
	}
}
