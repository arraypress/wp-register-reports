<?php
/**
 * REST API Class
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports;

use ArrayPress\DateUtils\Dates;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class RestApi
 *
 * Handles REST API endpoints for reports including batched exports.
 */
class RestApi {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'reports/v1';

	/**
	 * Whether the API has been registered.
	 */
	private static bool $registered = false;

	/**
	 * Export batch size.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Register REST API endpoints.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_action( 'wp_ajax_reports_download_export', [ __CLASS__, 'handle_download_export' ] );

		self::$registered = true;
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		// Get single component data
		register_rest_route( self::NAMESPACE, '/component', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_component_data' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_component_args(),
		] );

		// Get all components for a tab (for refresh)
		register_rest_route( self::NAMESPACE, '/components', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_all_components_data' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_tab_args(),
		] );

		// Refresh entire tab (legacy)
		register_rest_route( self::NAMESPACE, '/tab', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_tab_data' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_tab_args(),
		] );

		// Start export
		register_rest_route( self::NAMESPACE, '/export/start', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'start_export' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_export_start_args(),
		] );

		// Process export batch
		register_rest_route( self::NAMESPACE, '/export/batch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'process_export_batch' ],
			'permission_callback' => [ __CLASS__, 'check_batch_permissions' ],
			'args'                => self::get_export_batch_args(),
		] );
	}

	/**
	 * Get component endpoint args.
	 */
	private static function get_component_args(): array {
		return [
			'report_id'    => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'component_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'date_preset'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'date_start'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'date_end'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
		];
	}

	/**
	 * Get tab endpoint args.
	 */
	private static function get_tab_args(): array {
		return [
			'report_id'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'tab'         => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'date_preset' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'date_start'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'date_end'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
		];
	}

	/**
	 * Get export start endpoint args.
	 */
	private static function get_export_start_args(): array {
		return [
			'report_id'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'export_id'   => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'date_preset' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			'date_start'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'date_end'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'filters'     => [ 'type' => 'object', 'default' => [] ],
		];
	}

	/**
	 * Get export batch endpoint args.
	 */
	private static function get_export_batch_args(): array {
		return [
			'export_token' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
			'batch'        => [ 'required' => true, 'type' => 'integer' ],
		];
	}

	/**
	 * Check permissions for REST API access.
	 */
	public static function check_permissions( WP_REST_Request $request ) {
		$report_id = $request->get_param( 'report_id' );
		$report    = Registry::instance()->get( $report_id );

		if ( ! $report ) {
			return new WP_Error( 'invalid_report', __( 'Invalid report ID.', 'developer-portal' ), [ 'status' => 404 ] );
		}

		$capability = $report->get_config( 'capability', 'manage_options' );

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Permission denied.', 'developer-portal' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Check permissions for batch export requests.
	 */
	public static function check_batch_permissions( WP_REST_Request $request ) {
		$export_token = $request->get_param( 'export_token' );
		$config       = get_transient( 'reports_export_' . $export_token );

		if ( ! $config ) {
			return new WP_Error( 'invalid_export', __( 'Export session expired.', 'developer-portal' ), [ 'status' => 400 ] );
		}

		$report = Registry::instance()->get( $config['report_id'] );

		if ( ! $report ) {
			return new WP_Error( 'invalid_report', __( 'Invalid report.', 'developer-portal' ), [ 'status' => 404 ] );
		}

		$capability = $report->get_config( 'capability', 'manage_options' );

		return current_user_can( $capability ) ? true : new WP_Error( 'rest_forbidden', __( 'Permission denied.', 'developer-portal' ), [ 'status' => 403 ] );
	}

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

		foreach ( $components as $tab => $tab_components ) {
			if ( isset( $tab_components[ $component_id ] ) ) {
				$component = $tab_components[ $component_id ];
				break;
			}
		}

		if ( ! $component ) {
			return new WP_Error( 'invalid_component', __( 'Invalid component.', 'developer-portal' ), [ 'status' => 404 ] );
		}

		$callback = $component['data_callback'] ?? null;

		if ( ! $callback || ! is_callable( $callback ) ) {
			return new WP_Error( 'invalid_callback', __( 'No data callback.', 'developer-portal' ), [ 'status' => 400 ] );
		}

		try {
			$data = call_user_func( $callback, $date_range, $component );
			return new WP_REST_Response( [ 'success' => true, 'data' => $data, 'type' => $component['type'] ?? 'unknown' ] );
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
			return new WP_Error( 'invalid_report', __( 'Invalid report.', 'reports' ), [ 'status' => 404 ] );
		}

		$date_range = self::get_date_range_from_request( $request, $report );
		$all_components = $report->get_components();

		// If no tab specified, use first tab
		if ( empty( $tab ) ) {
			$tabs = $report->get_tabs();
			$tab  = array_key_first( $tabs );
		}

		if ( ! isset( $all_components[ $tab ] ) ) {
			return new WP_Error( 'invalid_tab', __( 'Invalid tab.', 'reports' ), [ 'status' => 404 ] );
		}

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

						if ( $change === null && $previous_value !== null && is_numeric( $value ) && is_numeric( $previous_value ) && $previous_value != 0 ) {
							$change           = ( ( $value - $previous_value ) / abs( $previous_value ) ) * 100;
							$change_direction = $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'neutral' );
							$change           = abs( $change );
						}

						// Format value
						$format     = $component['value_format'] ?? 'number';
						$currency   = $component['currency'] ?? 'USD';
						$formatted  = self::format_value_for_api( $value, $format, $currency );

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

					if ( $change === null && $previous_value !== null && is_numeric( $value ) && is_numeric( $previous_value ) && $previous_value != 0 ) {
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
	 * Format a value for API response.
	 */
	private static function format_value_for_api( $value, string $format, string $currency = 'USD' ): string {
		switch ( $format ) {
			case 'currency':
				$cents = is_float( $value ) ? (int) ( $value * 100 ) : (int) $value;
				return function_exists( 'format_currency' ) ? format_currency( $cents, $currency ) : '$' . number_format( $cents / 100, 2 );

			case 'percentage':
				return number_format( (float) $value, 1 ) . '%';

			case 'number':
				return number_format( (int) $value );

			case 'decimal':
				return number_format( (float) $value, 2 );

			default:
				return (string) $value;
		}
	}

	/**
	 * Get tab data.
	 */
	public static function get_tab_data( WP_REST_Request $request ) {
		$report_id = $request->get_param( 'report_id' );
		$tab       = $request->get_param( 'tab' );
		$report    = Registry::instance()->get( $report_id );

		$date_range = self::get_date_range_from_request( $request, $report );
		$components = $report->get_components();

		if ( ! isset( $components[ $tab ] ) ) {
			return new WP_Error( 'invalid_tab', __( 'Invalid tab.', 'developer-portal' ), [ 'status' => 404 ] );
		}

		$tab_data = [];

		foreach ( $components[ $tab ] as $component_id => $component ) {
			$callback = $component['data_callback'] ?? null;

			if ( $callback && is_callable( $callback ) ) {
				try {
					$tab_data[ $component_id ] = [
						'type' => $component['type'] ?? 'unknown',
						'data' => call_user_func( $callback, $date_range, $component ),
					];
				} catch ( Exception $e ) {
					$tab_data[ $component_id ] = [ 'type' => $component['type'] ?? 'unknown', 'error' => $e->getMessage() ];
				}
			}
		}

		return new WP_REST_Response( [ 'success' => true, 'data' => $tab_data ] );
	}

	/**
	 * Start export process.
	 */
	public static function start_export( WP_REST_Request $request ) {
		$report_id = $request->get_param( 'report_id' );
		$export_id = $request->get_param( 'export_id' );
		$filters   = $request->get_param( 'filters' ) ?? [];
		$report    = Registry::instance()->get( $report_id );

		$export_config = $report->find_export_config( $export_id );

		if ( ! $export_config ) {
			return new WP_Error( 'invalid_export', __( 'Invalid export.', 'developer-portal' ), [ 'status' => 404 ] );
		}

		if ( empty( $export_config['total_callback'] ) || ! is_callable( $export_config['total_callback'] ) ) {
			return new WP_Error( 'invalid_callback', __( 'Missing total_callback.', 'developer-portal' ), [ 'status' => 400 ] );
		}

		if ( empty( $export_config['data_callback'] ) || ! is_callable( $export_config['data_callback'] ) ) {
			return new WP_Error( 'invalid_callback', __( 'Missing data_callback.', 'developer-portal' ), [ 'status' => 400 ] );
		}

		$date_range = self::get_date_range_from_request( $request, $report );
		$args       = [ 'date_range' => $date_range, 'filters' => $filters ];

		try {
			$total_items = call_user_func( $export_config['total_callback'], $args );
		} catch ( Exception $e ) {
			return new WP_Error( 'callback_error', $e->getMessage(), [ 'status' => 500 ] );
		}

		if ( is_wp_error( $total_items ) ) {
			return $total_items;
		}

		if ( $total_items === 0 ) {
			return new WP_Error( 'no_data', __( 'No data to export.', 'developer-portal' ), [ 'status' => 400 ] );
		}

		$report->cleanup_exports();

		$export_token = wp_generate_uuid4();
		$file_path    = $report->get_export_path( $export_token );

		set_transient( 'reports_export_' . $export_token, [
			'report_id'     => $report_id,
			'export_id'     => $export_id,
			'filters'       => $filters,
			'date_range'    => $date_range,
			'file_path'     => $file_path,
			'total_items'   => $total_items,
			'export_config' => $export_config,
		], HOUR_IN_SECONDS );

		return new WP_REST_Response( [
			'success'      => true,
			'export_token' => $export_token,
			'total_items'  => $total_items,
			'batch_size'   => self::BATCH_SIZE,
		] );
	}

	/**
	 * Process export batch.
	 */
	public static function process_export_batch( WP_REST_Request $request ) {
		$export_token = $request->get_param( 'export_token' );
		$batch        = (int) $request->get_param( 'batch' );

		$config = get_transient( 'reports_export_' . $export_token );

		if ( ! $config ) {
			return new WP_Error( 'invalid_export', __( 'Export session expired.', 'developer-portal' ), [ 'status' => 400 ] );
		}

		$report        = Registry::instance()->get( $config['report_id'] );
		$export_config = $config['export_config'];

		if ( ! $report || ! $export_config ) {
			return new WP_Error( 'invalid_config', __( 'Config not found.', 'developer-portal' ), [ 'status' => 400 ] );
		}

		$args = [
			'date_range' => $config['date_range'],
			'filters'    => $config['filters'],
			'offset'     => $batch * self::BATCH_SIZE,
			'limit'      => self::BATCH_SIZE,
		];

		try {
			$data = call_user_func( $export_config['data_callback'], $args );
		} catch ( Exception $e ) {
			return new WP_Error( 'callback_error', $e->getMessage(), [ 'status' => 500 ] );
		}

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$headers = $export_config['headers'] ?? [];
		$report->write_csv_batch( $config['file_path'], $data, $batch === 0, $headers );

		$processed_items = ( $batch * self::BATCH_SIZE ) + count( $data );
		$is_complete     = $processed_items >= $config['total_items'] || count( $data ) < self::BATCH_SIZE;

		$response = [
			'success'         => true,
			'processed_items' => $processed_items,
			'total_items'     => $config['total_items'],
			'is_complete'     => $is_complete,
		];

		if ( $is_complete ) {
			$response['download_url'] = $report->get_download_url( $export_token );
		}

		return new WP_REST_Response( $response );
	}

	/**
	 * Handle export file downloads via AJAX.
	 */
	public static function handle_download_export(): void {
		$export_token = sanitize_text_field( $_GET['export_id'] ?? '' );
		$config       = get_transient( 'reports_export_' . $export_token );

		if ( ! $config ) {
			wp_die( __( 'Export not found or expired.', 'developer-portal' ) );
		}

		$report = Registry::instance()->get( $config['report_id'] );

		if ( ! $report ) {
			wp_die( __( 'Invalid report.', 'developer-portal' ) );
		}

		if ( ! current_user_can( $report->get_config( 'capability', 'manage_options' ) ) ) {
			wp_die( __( 'Permission denied.', 'developer-portal' ) );
		}

		if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'reports_export_' . $export_token ) ) {
			wp_die( __( 'Invalid request.', 'developer-portal' ) );
		}

		if ( ! isset( $config['file_path'] ) || ! file_exists( $config['file_path'] ) ) {
			wp_die( __( 'File not found.', 'developer-portal' ) );
		}

		$export_config = $config['export_config'] ?? [];
		$date_range    = $config['date_range'] ?? [];

		// Support filename callback
		$base_filename = 'export';
		if ( ! empty( $export_config['filename'] ) ) {
			if ( is_callable( $export_config['filename'] ) ) {
				$base_filename = call_user_func( $export_config['filename'], $date_range, $export_config );
			} else {
				$base_filename = $export_config['filename'];
			}
		}

		$filename = sanitize_file_name( $base_filename . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $config['file_path'] ) );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		readfile( $config['file_path'] );

		unlink( $config['file_path'] );
		delete_transient( 'reports_export_' . $export_token );

		exit;
	}

	/**
	 * Get date range from request parameters.
	 */
	protected static function get_date_range_from_request( WP_REST_Request $request, Reports $report ): array {
		$preset     = $request->get_param( 'date_preset' );
		$date_start = $request->get_param( 'date_start' );
		$date_end   = $request->get_param( 'date_end' );

		if ( $preset === 'custom' && $date_start && $date_end ) {
			$utc_range = Dates::range_to_utc( $date_start . ' 00:00:00', $date_end . ' 23:59:59' );

			return [
				'start'       => $utc_range['start'],
				'end'         => $utc_range['end'],
				'start_local' => $date_start,
				'end_local'   => $date_end,
				'preset'      => 'custom',
			];
		}

		if ( empty( $preset ) ) {
			$preset = $report->get_config( 'default_preset', 'this_month' );
		}

		return $report->calculate_date_range( $preset );
	}

}
