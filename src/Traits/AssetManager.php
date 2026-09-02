<?php
/**
 * Asset Manager Trait
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

use ArrayPress\FieldKit\Assets;
use ArrayPress\RegisterReports\RestApi;
use ArrayPress\RegisterReports\Utils\Runtime;

/**
 * Trait AssetManager
 *
 * Handles enqueueing of scripts and styles using wp-composer-assets library.
 */
trait AssetManager {

	/**
	 * Maybe enqueue assets on the reports page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Enqueue all required assets.
	 *
	 * @return void
	 */
	protected function enqueue_assets(): void {
		$this->enqueue_core_assets();
		$this->localize_scripts();
	}

	/**
	 * Enqueue core CSS and JS using wp-composer-assets library.
	 *
	 * @return void
	 */
	protected function enqueue_core_assets(): void {
		// The kit's, because the header is the kit's. Without it the markup
		// renders and none of the rules for it load, so the header falls back
		// to core's .privacy-settings-header and the tabs stack one per line.
		( new Assets() )->enqueue();

		wp_enqueue_composer_style(
			Runtime::handle(),
			__FILE__,
			'css/reports.css'
		);

		wp_enqueue_composer_script(
			Runtime::handle( 'chartjs' ),
			__FILE__,
			'js/chart.js',
			[]
		);

		wp_enqueue_composer_script(
			Runtime::handle(),
			__FILE__,
			'js/reports.js',
			[ 'jquery', Runtime::handle( 'chartjs' ) ]
		);
	}

	/**
	 * Localize scripts with necessary data.
	 *
	 * @return void
	 */
	protected function localize_scripts(): void {
		$config = [
			'restUrl'       => rest_url( RestApi::rest_namespace() . '/' ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'reportId'      => $this->id,

			// The preset a request with no date_preset means. The script had
			// its own idea of this and so did PHP, so a report configuring a
			// different default got it on the first render and lost it on the
			// first refresh.
			'defaultPreset' => (string) ( $this->config['default_preset'] ?? 'this_month' ),
			'i18n'          => [
				// General
				'loading'        => __( 'Loading...', 'arraypress' ),
				'error'          => __( 'Error', 'arraypress' ),
				'noData'         => __( 'No data available', 'arraypress' ),

				// Export
				'exporting'      => __( 'Exporting...', 'arraypress' ),
				'preparing'      => __( 'Preparing export...', 'arraypress' ),
				/* translators: 1: number of rows processed so far, 2: total rows */
				'processing'     => __( 'Processing %1$d / %2$d', 'arraypress' ),
				'complete'       => __( 'Export complete!', 'arraypress' ),
				'download'       => __( 'Download CSV', 'arraypress' ),
				'exportFailed'   => __( 'Export failed', 'arraypress' ),
				'batchFailed'    => __( 'Batch failed', 'arraypress' ),

				// Refresh / Last Updated
				'updatedJustNow' => __( 'Updated just now', 'arraypress' ),
				/* translators: %d: number of seconds since the last refresh */
				'updatedSeconds' => __( 'Updated %ds ago', 'arraypress' ),
				/* translators: %d: number of minutes since the last refresh */
				'updatedMinutes' => __( 'Updated %dm ago', 'arraypress' ),
				/* translators: %d: number of hours since the last refresh */
				'updatedHours'   => __( 'Updated %dh ago', 'arraypress' ),

				// Table Pagination
				/* translators: 1: first row on this page, 2: last row on this page, 3: total rows */
				'showing'        => __( 'Showing %1$d-%2$d of %3$d', 'arraypress' ),
			],
			'chartDefaults' => [
				'colors' => [
					'#3b82f6', // blue
					'#10b981', // emerald
					'#f59e0b', // amber
					'#ef4444', // red
					'#8b5cf6', // violet
					'#ec4899', // pink
					'#06b6d4', // cyan
					'#84cc16', // lime
				],
			],
		];

		$handle = Runtime::handle();

		// Published into a registry keyed by script handle rather than to a
		// bare global. Two Strauss-prefixed copies of this library each load
		// their own reports.js under their own handle, and a shared global
		// would leave whichever localized last serving both — with one
		// plugin's REST namespace, nonce and report id. The script resolves
		// its own entry from the id WordPress stamps on its <script> tag.
		wp_localize_script( $handle, Runtime::js_object( 'Admin' ), $config );

		wp_add_inline_script(
			$handle,
			sprintf(
				'window.ArrayPressReports=window.ArrayPressReports||{};window.ArrayPressReports[%s]=%s;',
				wp_json_encode( $handle ),
				wp_json_encode( $config )
			),
			'before'
		);
	}
}
