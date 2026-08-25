<?php
/**
 * Export Endpoints
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Rest;

use ArrayPress\RegisterReports\Registry;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Starting an export, feeding it a batch at a time, and handing over the file.
 *
 * Batched because the alternative is a report that works until someone has a
 * year of data and then times out. Each batch is its own request with its own
 * permission check — a job started by someone allowed to start it is not a
 * licence for anyone else to keep pulling rows out of it.
 *
 * The download is a file read off disk and streamed, so the path is checked
 * against the directory it is supposed to be in rather than trusted.
 */
trait ExportEndpoints {

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
}
