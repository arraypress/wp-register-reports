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
		wp_enqueue_composer_style(
			'arraypress-reports',
			__FILE__,
			'css/reports.css'
		);

		wp_enqueue_composer_script(
			'arraypress-chartjs',
			__FILE__,
			'js/chart.js',
			[]
		);

		wp_enqueue_composer_script(
			'arraypress-reports',
			__FILE__,
			'js/reports.js',
			[ 'jquery', 'arraypress-chartjs' ]
		);
	}

	/**
	 * Localize scripts with necessary data.
	 *
	 * @return void
	 */
	protected function localize_scripts(): void {
		wp_localize_script( 'arraypress-reports', 'ReportsAdmin', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'restUrl'       => rest_url( 'reports/v1/' ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'reportId'      => $this->id,
			'dateRange'     => $this->date_range,
			'i18n'          => [
				// General
				'loading'        => __( 'Loading...', 'arraypress' ),
				'error'          => __( 'Error', 'arraypress' ),
				'noData'         => __( 'No data available', 'arraypress' ),

				// Export
				'exporting'      => __( 'Exporting...', 'arraypress' ),
				'preparing'      => __( 'Preparing export...', 'arraypress' ),
				'processing'     => __( 'Processing %1$d / %2$d', 'arraypress' ),
				'complete'       => __( 'Export complete!', 'arraypress' ),
				'download'       => __( 'Download CSV', 'arraypress' ),
				'exportFailed'   => __( 'Export failed', 'arraypress' ),
				'batchFailed'    => __( 'Batch failed', 'arraypress' ),

				// Refresh / Last Updated
				'updatedJustNow' => __( 'Updated just now', 'arraypress' ),
				'updatedSeconds' => __( 'Updated %ds ago', 'arraypress' ),
				'updatedMinutes' => __( 'Updated %dm ago', 'arraypress' ),
				'updatedHours'   => __( 'Updated %dh ago', 'arraypress' ),

				// Table Pagination
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
		] );
	}

}
