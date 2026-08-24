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
use ArrayPress\RegisterReports\Utils\Runtime;
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
	 * Get the REST namespace for this build.
	 *
	 * Derived from the class namespace so two Strauss-prefixed copies of this
	 * library never register the same routes. See {@see Runtime}.
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return Runtime::rest_namespace();
	}

	/**
	 * Get the transient key holding an export session.
	 *
	 * Prefixed per build so one plugin's copy cannot read, serve or delete
	 * another plugin's export session.
	 *
	 * @param string $export_token Export token.
	 *
	 * @return string
	 */
	private static function export_key( string $export_token ): string {
		return Runtime::key( 'export' ) . '_' . $export_token;
	}

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

		self::$registered = true;
	}

	/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		// Get single component data
		register_rest_route( self::rest_namespace(), '/component', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_component_data' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_component_args(),
		] );

		// Get all components for a tab (for refresh)
		register_rest_route( self::rest_namespace(), '/components', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_all_components_data' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_tab_args(),
		] );

		// Start export
		register_rest_route( self::rest_namespace(), '/export/start', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'start_export' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_export_start_args(),
		] );

		// Process export batch
		register_rest_route( self::rest_namespace(), '/export/batch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'process_export_batch' ],
			'permission_callback' => [ __CLASS__, 'check_batch_permissions' ],
			'args'                => self::get_export_batch_args(),
		] );

		// Download the finished file. Browser-navigated, so it authenticates
		// with the standard REST cookie nonce (_wpnonce) rather than a
		// bespoke one, and reuses the batch permission check — which resolves
		// the report from the export session and tests its capability.
		register_rest_route( self::rest_namespace(), '/export/download', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'handle_download_export' ],
			'permission_callback' => [ __CLASS__, 'check_batch_permissions' ],
			'args'                => [
				'export_token' => [
					'description'       => __( 'Export session token.', 'arraypress' ),
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

	/**
	 * Get component endpoint args.
	 */
	private static function get_component_args(): array {
		return [
			'report_id'    => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'component_id' => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_preset'  => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_start'   => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_end'     => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get tab endpoint args.
	 */
	private static function get_tab_args(): array {
		return [
			'report_id'   => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'tab'         => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_preset' => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_start'  => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_end'    => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get export start endpoint args.
	 */
	private static function get_export_start_args(): array {
		return [
			'report_id'   => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'export_id'   => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_preset' => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_start'  => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_end'    => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'filters'     => [
				'type' => 'object',
				'default' => [],
			],
		];
	}

	/**
	 * Get export batch endpoint args.
	 */
	private static function get_export_batch_args(): array {
		return [
			'export_token' => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'batch'        => [
				'required' => true,
				'type' => 'integer',
			],
		];
	}

	/**
	 * Check permissions for REST API access.
	 */
	public static function check_permissions( WP_REST_Request $request ) {
		$report_id = $request->get_param( 'report_id' );
		$report    = Registry::instance()->get( $report_id );

		if ( ! $report ) {
			return new WP_Error( 'invalid_report', __( 'Invalid report ID.', 'arraypress' ), [ 'status' => 404 ] );
		}

		$capability = $report->get_config( 'capability', 'manage_options' );

		if ( ! current_user_can( $capability ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Permission denied.', 'arraypress' ), [ 'status' => 403 ] );
		}

		return true;
	}

	/**
	 * Check permissions for batch export requests.
	 */
	public static function check_batch_permissions( WP_REST_Request $request ) {
		$export_token = $request->get_param( 'export_token' );
		$config       = get_transient( self::export_key( $export_token ) );

		if ( ! $config ) {
			return new WP_Error( 'invalid_export', __( 'Export session expired.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$report = Registry::instance()->get( $config['report_id'] );

		if ( ! $report ) {
			return new WP_Error( 'invalid_report', __( 'Invalid report.', 'arraypress' ), [ 'status' => 404 ] );
		}

		$capability = $report->get_config( 'capability', 'manage_options' );

		return current_user_can( $capability ) ? true : new WP_Error( 'rest_forbidden', __( 'Permission denied.', 'arraypress' ), [ 'status' => 403 ] );
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
			return new WP_Error( 'invalid_export', __( 'Invalid export.', 'arraypress' ), [ 'status' => 404 ] );
		}

		if ( empty( $export_config['total_callback'] ) || ! is_callable( $export_config['total_callback'] ) ) {
			return new WP_Error( 'invalid_callback', __( 'Missing total_callback.', 'arraypress' ), [ 'status' => 400 ] );
		}

		if ( empty( $export_config['data_callback'] ) || ! is_callable( $export_config['data_callback'] ) ) {
			return new WP_Error( 'invalid_callback', __( 'Missing data_callback.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$date_range = self::get_date_range_from_request( $request, $report );
		$args       = [
			'date_range' => $date_range,
			'filters' => $filters,
		];

		try {
			$total_items = call_user_func( $export_config['total_callback'], $args );
		} catch ( Exception $e ) {
			return new WP_Error( 'callback_error', $e->getMessage(), [ 'status' => 500 ] );
		}

		if ( is_wp_error( $total_items ) ) {
			return $total_items;
		}

		if ( $total_items === 0 ) {
			return new WP_Error( 'no_data', __( 'No data to export.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$report->cleanup_exports();

		$export_token = wp_generate_uuid4();
		$file_path    = $report->get_export_path( $export_token );

		// Resolve filename now if it's a callback (can't serialize closures)
		$filename = $export_config['filename'] ?? $export_id;
		if ( is_callable( $filename ) ) {
			$filename = call_user_func( $filename, $date_range, $export_config );
		}

		// Only store serializable data - no callbacks/closures
		set_transient( self::export_key( $export_token ), [
			'report_id'   => $report_id,
			'export_id'   => $export_id,
			'filters'     => $filters,
			'date_range'  => $date_range,
			'file_path'   => $file_path,
			'total_items' => $total_items,
			'filename'    => $filename,
			'headers'     => $export_config['headers'] ?? [],
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

		$config = get_transient( self::export_key( $export_token ) );

		if ( ! $config ) {
			return new WP_Error( 'invalid_export', __( 'Export session expired.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$report = Registry::instance()->get( $config['report_id'] );

		if ( ! $report ) {
			return new WP_Error( 'invalid_config', __( 'Report not found.', 'arraypress' ), [ 'status' => 400 ] );
		}

		// Re-fetch export config from report (contains callbacks we couldn't serialize)
		$export_config = $report->find_export_config( $config['export_id'] );

		if ( ! $export_config ) {
			return new WP_Error( 'invalid_config', __( 'Export config not found.', 'arraypress' ), [ 'status' => 400 ] );
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

		// Use headers from transient (already resolved) or from export config
		$headers = $config['headers'] ?? $export_config['headers'] ?? [];
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
	 * Stream a finished export file.
	 *
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_download_export( WP_REST_Request $request ) {
		$export_token = (string) $request->get_param( 'export_token' );
		$config       = get_transient( self::export_key( $export_token ) );

		if ( ! $config ) {
			return new WP_Error( 'export_not_found', __( 'Export not found or expired.', 'arraypress' ), [ 'status' => 404 ] );
		}

		$report = Registry::instance()->get( $config['report_id'] );

		if ( ! $report ) {
			return new WP_Error( 'invalid_report', __( 'Invalid report.', 'arraypress' ), [ 'status' => 404 ] );
		}

		$path = $config['file_path'] ?? '';
		$real = '' === $path ? false : realpath( $path );
		$dir  = realpath( $report->get_export_dir() );

		// The transient is server-written, but a stored path is still the
		// wrong thing to hand readfile() unverified — confirm it resolves
		// inside this report's own export directory.
		if ( false === $real || false === $dir || ! str_starts_with( $real, $dir . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error( 'export_file_missing', __( 'File not found.', 'arraypress' ), [ 'status' => 404 ] );
		}

		$base_filename = $config['filename'] ?? $config['export_id'] ?? 'export';
		$filename      = sanitize_file_name( $base_filename . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		// Take over the response rather than returning one. WP_REST_Server has
		// already sent Content-Type: application/json by dispatch time, so the
		// file is streamed from the documented hand-off filter, which runs
		// before the server encodes anything.
		add_filter( 'rest_pre_serve_request', static function () use ( $real, $filename, $export_token ): bool {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'Content-Length: ' . filesize( $real ) );
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			readfile( $real );

			unlink( $real );
			delete_transient( self::export_key( $export_token ) );

			return true;
		} );

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Get date range from request parameters.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param Reports         $report  The report instance.
	 *
	 * @return array Date range with UTC and local representations.
	 */
	protected static function get_date_range_from_request( WP_REST_Request $request, Reports $report ): array {
		$preset     = $request->get_param( 'date_preset' );
		$date_start = $request->get_param( 'date_start' );
		$date_end   = $request->get_param( 'date_end' );

		// Use default preset if none specified
		if ( empty( $preset ) ) {
			$preset = $report->get_config( 'default_preset', 'this_month' );
		}

		return Dates::get_range_full( $preset, $date_start ?? '', $date_end ?? '' );
	}
}
