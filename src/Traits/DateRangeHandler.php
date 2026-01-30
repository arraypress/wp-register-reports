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

        // Use default preset if none specified
        if ( empty( $preset ) ) {
            $preset = $this->config['default_preset'] ?? 'this_month';
        }

        return Dates::get_range_full( $preset, $date_start, $date_end );
    }

    /**
     * Calculate date range from preset.
     *
     * Uses Dates::get_range_full() which calculates in local timezone
     * then converts to UTC for database queries.
     *
     * @param string $preset Preset name.
     *
     * @return array Contains UTC start/end for queries and local dates for display.
     */
    public function calculate_date_range( string $preset ): array {
        return Dates::get_range_full( $preset );
    }

    /**
     * Get the previous period for comparison.
     *
     * @param array $date_range Current date range.
     *
     * @return array
     */
    public function get_previous_period( array $date_range ): array {
        $previous = Dates::get_previous_period( $date_range['start'], $date_range['end'] );

        return array_merge( $previous, [ 'preset' => 'previous' ] );
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

        return Dates::get_range_options( true, true );
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
            $preset_label = Dates::format_range( $current_range['start'], $current_range['end'] );
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
        return Dates::days_in_range( $date_range['start'], $date_range['end'] );
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

        // For custom, return the formatted date range
        if ( $preset === 'custom' && ! empty( $this->date_range['start'] ) && ! empty( $this->date_range['end'] ) ) {
            return Dates::format_range( $this->date_range['start'], $this->date_range['end'] );
        }

        return '';
    }

}