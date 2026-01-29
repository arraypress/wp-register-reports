<?php
/**
 * Date Range Handler Trait
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

use ArrayPress\DateUtils\Dates;

/**
 * Trait DateRangeHandler
 *
 * Handles date range selection and calculation.
 * Uses wp-date-utils library for UTC/local timezone handling.
 */
trait DateRangeHandler {

	/**
	 * Get the current date range from request.
	 *
	 * Returns dates in UTC for database queries.
	 *
	 * @return array
	 */
	protected function get_current_date_range(): array {
		$preset     = isset( $_GET['date_preset'] ) ? sanitize_key( $_GET['date_preset'] ) : '';
		$date_start = isset( $_GET['date_start'] ) ? sanitize_text_field( $_GET['date_start'] ) : '';
		$date_end   = isset( $_GET['date_end'] ) ? sanitize_text_field( $_GET['date_end'] ) : '';

		// If custom dates provided (user enters in local time)
		if ( $preset === 'custom' && $date_start && $date_end ) {
			// Convert local date inputs to UTC for database queries
			$utc_range = Dates::range_to_utc(
				$date_start . ' 00:00:00',
				$date_end . ' 23:59:59'
			);

			return [
				'start'       => $utc_range['start'],
				'end'         => $utc_range['end'],
				'start_local' => $date_start,
				'end_local'   => $date_end,
				'preset'      => 'custom',
			];
		}

		// Use preset
		if ( empty( $preset ) ) {
			$preset = $this->config['default_preset'] ?? 'this_month';
		}

		return $this->calculate_date_range( $preset );
	}

	/**
	 * Calculate date range from preset.
	 *
	 * Uses Dates::get_range() which calculates in local timezone
	 * then converts to UTC for database queries.
	 *
	 * @param string $preset Preset name.
	 *
	 * @return array Contains UTC start/end for queries and local dates for display.
	 */
	public function calculate_date_range( string $preset ): array {
		// Handle all_time specially
		if ( $preset === 'all_time' ) {
			return [
				'start'       => '1970-01-01 00:00:00',
				'end'         => Dates::now_utc(),
				'start_local' => '1970-01-01',
				'end_local'   => Dates::now_local( 'Y-m-d' ),
				'preset'      => $preset,
			];
		}

		// Use wp-date-utils to get range (returns UTC)
		$utc_range = Dates::get_range( $preset, true );

		// Get local dates for display in the picker
		$start_local = Dates::to_local( $utc_range['start'], 'Y-m-d' );
		$end_local   = Dates::to_local( $utc_range['end'], 'Y-m-d' );

		return [
			'start'       => $utc_range['start'],
			'end'         => $utc_range['end'],
			'start_local' => $start_local,
			'end_local'   => $end_local,
			'preset'      => $preset,
		];
	}

	/**
	 * Get the previous period for comparison.
	 *
	 * @param array $date_range Current date range.
	 *
	 * @return array
	 */
	public function get_previous_period( array $date_range ): array {
		$days = Dates::diff( $date_range['start'], $date_range['end'], 'days' ) + 1;

		$prev_end   = Dates::subtract( $date_range['start'], 1, 'seconds' );
		$prev_start = Dates::subtract( $prev_end, $days, 'days' );

		return [
			'start'       => $prev_start,
			'end'         => $prev_end,
			'start_local' => Dates::to_local( $prev_start, 'Y-m-d' ),
			'end_local'   => Dates::to_local( $prev_end, 'Y-m-d' ),
			'preset'      => 'previous',
		];
	}

	/**
	 * Get date range options for dropdown.
	 *
	 * Uses options from wp-date-utils and adds 'custom' option.
	 *
	 * @return array
	 */
	protected function get_date_range_options(): array {
		// Check if custom presets defined in config
		if ( ! empty( $this->config['date_presets'] ) ) {
			return $this->config['date_presets'];
		}

		// Use wp-date-utils options and add custom
		$options            = Dates::get_range_options();
		$options['custom']  = __( 'Custom Range', 'developer-portal' );

		return $options;
	}

	/**
	 * Render the date picker dropdown.
	 *
	 * @return void
	 */
	protected function render_date_picker(): void {
		$presets       = $this->config['date_presets'] ?? $this->get_date_range_options();
		$current_range = $this->date_range;
		$preset        = $current_range['preset'] ?? 'this_month';

		$preset_label = $presets[ $preset ] ?? __( 'Custom Range', 'reports' );

		if ( $preset === 'custom' ) {
			$preset_label = sprintf(
				'%s - %s',
				Dates::to_local( $current_range['start'], 'M j, Y' ),
				Dates::to_local( $current_range['end'], 'M j, Y' )
			);
		}

		?>
		<div class="reports-date-picker" data-report-id="<?php echo esc_attr( $this->id ); ?>">
			<button type="button" class="reports-date-picker-toggle button">
				<span class="dashicons dashicons-calendar-alt"></span>
				<span class="reports-date-picker-label"><?php echo esc_html( $preset_label ); ?></span>
				<span class="dashicons dashicons-arrow-down-alt2"></span>
			</button>

			<div class="reports-date-picker-dropdown" style="display: none;">
				<div class="reports-date-picker-presets">
					<?php foreach ( $presets as $preset_key => $preset_name ) : ?>
						<?php if ( $preset_key === 'custom' ) : ?>
							<div class="reports-date-picker-divider"></div>
						<?php endif; ?>
						<button type="button"
						        class="reports-date-preset<?php echo $preset_key === $preset ? ' active' : ''; ?>"
						        data-preset="<?php echo esc_attr( $preset_key ); ?>">
							<?php echo esc_html( $preset_name ); ?>
						</button>
					<?php endforeach; ?>
				</div>

				<div class="reports-date-picker-custom" style="display: none;">
					<div class="reports-date-picker-custom-row">
						<label>
							<?php esc_html_e( 'Start Date', 'reports' ); ?>
							<input type="date"
							       class="reports-date-start"
							       value="<?php echo esc_attr( $current_range['start_local'] ?? '' ); ?>"/>
						</label>
					</div>
					<div class="reports-date-picker-custom-row">
						<label>
							<?php esc_html_e( 'End Date', 'reports' ); ?>
							<input type="date"
							       class="reports-date-end"
							       value="<?php echo esc_attr( $current_range['end_local'] ?? '' ); ?>"/>
						</label>
					</div>
					<div class="reports-date-picker-custom-actions">
						<button type="button" class="button reports-date-picker-cancel">
							<?php esc_html_e( 'Cancel', 'reports' ); ?>
						</button>
						<button type="button" class="button button-primary reports-date-picker-apply">
							<?php esc_html_e( 'Apply', 'reports' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Format date for display using WordPress settings.
	 *
	 * @param string $utc_date UTC date string.
	 * @param string $format   Date format (defaults to WordPress setting).
	 *
	 * @return string
	 */
	public function format_date( string $utc_date, string $format = '' ): string {
		if ( empty( $format ) ) {
			$format = get_option( 'date_format' );
		}

		return Dates::to_local( $utc_date, $format );
	}

	/**
	 * Get number of days in date range.
	 *
	 * @param array $date_range Date range array with UTC dates.
	 *
	 * @return int
	 */
	public function get_days_in_range( array $date_range ): int {
		return Dates::diff( $date_range['start'], $date_range['end'], 'days' ) + 1;
	}

	/**
	 * Get a human-readable label for the current period.
	 *
	 * @return string
	 */
	protected function get_period_label(): string {
		$preset  = $this->date_range['preset'] ?? 'this_month';
		$presets = $this->get_date_range_options();

		// Return the preset label if found
		if ( isset( $presets[ $preset ] ) && $preset !== 'custom' ) {
			return $presets[ $preset ];
		}

		// For custom, return the date range
		if ( $preset === 'custom' ) {
			$start = $this->date_range['start_local'] ?? '';
			$end   = $this->date_range['end_local'] ?? '';

			if ( $start && $end ) {
				return sprintf(
					'%s — %s',
					date_i18n( 'M j, Y', strtotime( $start ) ),
					date_i18n( 'M j, Y', strtotime( $end ) )
				);
			}
		}

		return '';
	}

}
