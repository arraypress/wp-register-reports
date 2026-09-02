<?php
/**
 * Export Handler Trait
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

use ArrayPress\RegisterReports\RestApi;
use ArrayPress\RegisterReports\Utils\Runtime;

/**
 * Trait ExportHandler
 *
 * Handles batched CSV export functionality with progress tracking.
 */
trait ExportHandler {

	use ExportForm;

	/**
	 * Get export directory path.
	 *
	 * @return string
	 */
	public function get_export_dir(): string {
		$upload_dir = wp_upload_dir();
		$export_dir = trailingslashit( $upload_dir['basedir'] ) . Runtime::key( 'exports' );

		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
			file_put_contents( $export_dir . '/.htaccess', "deny from all\n" );
			file_put_contents( $export_dir . '/index.php', '<?php // Silence is golden' );
		}

		return $export_dir;
	}

	/**
	 * Get export file path.
	 *
	 * @param string $export_id Export ID.
	 *
	 * @return string
	 */
	public function get_export_path( string $export_id ): string {
		return $this->get_export_dir() . '/' . $export_id . '.csv';
	}

	/**
	 * Get download URL for export.
	 *
	 * @param string $export_id Export ID.
	 *
	 * @return string
	 */
	public function get_download_url( string $export_id ): string {
		return add_query_arg( [
			'export_token' => $export_id,
			'_wpnonce'     => wp_create_nonce( 'wp_rest' ),
		], rest_url( RestApi::rest_namespace() . '/export/download' ) );
	}

	/**
	 * The characters a spreadsheet treats as the start of a formula.
	 *
	 * @var string[]
	 */
	private const FORMULA_STARTS = [ '=', '+', '-', '@', '|', "\t", "\r" ];

	/**
	 * Write one row, with every cell defused.
	 *
	 * A CSV is a text file until somebody opens it in Excel, at which point a
	 * cell beginning `=`, `+`, `-`, `@` or `|` is a formula and runs. An exported
	 * customer name of `=HYPERLINK("http://evil.test","Click")` becomes a
	 * link in whoever's spreadsheet, and the more interesting formulas reach
	 * the filesystem or the network. The data came out of a database that
	 * anybody with a checkout form can write to.
	 *
	 * The fix is the boring one: a leading apostrophe, which every
	 * spreadsheet reads as "this is text" and does not display.
	 *
	 * `escape: ''` is passed because PHP's default is a backslash escape that
	 * is not in RFC 4180 and confuses other readers — and because leaving it
	 * out is deprecated from PHP 8.4, which was printing a notice on every
	 * row of every export.
	 *
	 * @param resource $handle An open file.
	 * @param array    $row    The cells.
	 *
	 * @return void
	 */
	private static function write_csv_row( $handle, array $row ): void {
		fputcsv( $handle, array_map( [ self::class, 'defuse' ], $row ), ',', '"', '' );
	}

	/**
	 * One cell, as text rather than as a formula.
	 *
	 * A number is left as a number. `-12.50` starts with a formula trigger
	 * and is also just a refund; a spreadsheet reading it as a formula gets
	 * -12.50, so there is nothing to defuse. Prefixing it anyway exported
	 * every negative figure as text, which is a column that will not sum.
	 * The apostrophe goes on a trigger followed by something that is not a
	 * number -- `-1+2`, `=SUM(A1)` -- which is the only shape that runs.
	 *
	 * @param mixed $value The cell.
	 *
	 * @return string
	 */
	private static function defuse( $value ): string {
		$value = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );

		if ( '' === $value || is_numeric( $value ) ) {
			return $value;
		}

		return in_array( $value[0], self::FORMULA_STARTS, true ) ? "'" . $value : $value;
	}

	/**
	 * Write batch data to CSV file.
	 *
	 * @param string $file_path      File path to write to.
	 * @param array  $data           Data rows to write.
	 * @param bool   $is_first_batch Whether this is the first batch.
	 * @param array  $headers        Optional column headers mapping.
	 *
	 * @return bool Success status.
	 */
	public function write_csv_batch( string $file_path, array $data, bool $is_first_batch, array $headers = [] ): bool {
		$fp = fopen( $file_path, $is_first_batch ? 'w' : 'a' );

		if ( ! $fp ) {
			return false;
		}

		// Add BOM for Excel UTF-8 compatibility on first batch
		if ( $is_first_batch ) {
			fprintf( $fp, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );
		}

		// Write headers on first batch
		if ( $is_first_batch && ! empty( $data ) ) {
			$first_row = reset( $data );

			if ( ! empty( $headers ) ) {
				// Use custom headers, maintaining the order of the data keys
				$header_row = [];
				foreach ( array_keys( $first_row ) as $key ) {
					$header_row[] = $headers[ $key ] ?? $key;
				}
				self::write_csv_row( $fp, $header_row );
			} else {
				// Fall back to using keys as headers
				self::write_csv_row( $fp, array_keys( $first_row ) );
			}
		}

		// Write data rows
		foreach ( $data as $row ) {
			if ( is_array( $row ) ) {
				self::write_csv_row( $fp, array_values( $row ) );
			}
		}

		fclose( $fp );

		return true;
	}

	/**
	 * Clean up old export files and transients.
	 *
	 * @return void
	 */
	public function cleanup_exports(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		$export_dir = $this->get_export_dir();
		$expired    = time() - HOUR_IN_SECONDS;

		// Clean up old files
		if ( $wp_filesystem->exists( $export_dir ) && $wp_filesystem->is_dir( $export_dir ) ) {
			$files = $wp_filesystem->dirlist( $export_dir );

			if ( $files ) {
				foreach ( $files as $file ) {
					if ( $file['type'] !== 'f' || ! str_ends_with( $file['name'], '.csv' ) ) {
						continue;
					}

					$file_path = trailingslashit( $export_dir ) . $file['name'];
					$file_time = $wp_filesystem->mtime( $file_path );

					if ( $file_time && $file_time < $expired ) {
						$wp_filesystem->delete( $file_path );
					}
				}
			}
		}

		// Clean up expired transients
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . Runtime::key( 'export' ) . '_' ) . '%';

		// Enumerating this build's own export transients has no API: there is
		// no "list transients by prefix". The result is the cleanup worklist
		// itself, so caching it would defeat the sweep.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);

		foreach ( $transients as $transient ) {
			$export_id = str_replace( '_transient_' . Runtime::key( 'export' ) . '_', '', $transient );
			$config    = get_transient( Runtime::key( 'export' ) . '_' . $export_id );

			if ( ! $config || ! isset( $config['file_path'] ) || ! $wp_filesystem->exists( $config['file_path'] ) ) {
				delete_transient( Runtime::key( 'export' ) . '_' . $export_id );
			}
		}
	}

	/**
	 * Find export configuration by ID.
	 *
	 * @param string $export_id Export ID.
	 *
	 * @return array|null Export config or null if not found.
	 */
	public function find_export_config( string $export_id ): ?array {
		foreach ( $this->exports as $tab_exports ) {
			if ( isset( $tab_exports[ $export_id ] ) ) {
				return $tab_exports[ $export_id ];
			}
		}

		return null;
	}
}
