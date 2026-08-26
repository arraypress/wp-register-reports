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

use ArrayPress\Dates\Preset;
use ArrayPress\Dates\Range;
use ArrayPress\Dates\Site;

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
        $preset     = isset( $_GET['date_preset'] ) ? sanitize_key( wp_unslash( $_GET['date_preset'] ) ) : '';
        $date_start = isset( $_GET['date_start'] ) ? sanitize_text_field( wp_unslash( $_GET['date_start'] ) ) : '';
        $date_end   = isset( $_GET['date_end'] ) ? sanitize_text_field( wp_unslash( $_GET['date_end'] ) ) : '';

        // Use default preset if none specified
        if ( empty( $preset ) ) {
            $preset = $this->config['default_preset'] ?? 'this_month';
        }

        return self::range_to_array( Preset::resolve( $preset, $date_start, $date_end ), $preset );
    }

    /**
     * A Range as the array the rest of this library passes around.
     *
     * The library predates the value object and threads an array through
     * every screen, filter and REST route. Converting at the edge keeps that
     * one shape rather than rewriting all of it.
     *
     * `start` and `end` are UTC, for querying. The `_local` pair is the same
     * moment in the site's timezone, for showing somebody.
     *
     * @param Range  $range  The range.
     * @param string $preset Which preset produced it.
     *
     * @return array
     */
    protected static function range_to_array( Range $range, string $preset = 'custom' ): array {
        return [
            'start'       => $range->start(),
            'end'         => $range->end(),
            'start_local' => Site::format( $range->start(), 'Y-m-d H:i:s' ),
            'end_local'   => Site::format( $range->end(), 'Y-m-d H:i:s' ),
            'preset'      => $preset,
        ];
    }

    /**
     * Calculate date range from preset.
     *
     * Resolves the preset in the site's timezone, then hands back UTC
     * then converts to UTC for database queries.
     *
     * @param string $preset Preset name.
     *
     * @return array Contains UTC start/end for queries and local dates for display.
     */
    public function calculate_date_range( string $preset ): array {
        return self::range_to_array( Preset::resolve( $preset ), $preset );
    }

    /**
     * Get the previous period for comparison.
     *
     * @param array $date_range Current date range.
     *
     * @return array
     */
    public function get_previous_period( array $date_range ): array {
        $previous = Range::between( $date_range['start'], $date_range['end'] )->previous();

        return self::range_to_array( $previous, 'previous' );
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

        return Preset::options();
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

        $preset_label = $presets[ $preset ] ?? __( 'Custom Range', 'arraypress' );

        if ( $preset === 'custom' ) {
            $preset_label = self::format_range( $current_range['start'], $current_range['end'] );
        }

        ?>
        <div class="reports-date-picker" data-report-id="<?php echo esc_attr( $this->id ); ?>">
            <?php
            /*
             * No calendar glyph. The button says which range is showing,
             * which is the thing worth reading, and core puts no icon in
             * front of its own button text anywhere in the admin.
             *
             * The caret stays: it is not decoration but the only cue that
             * this opens a menu rather than doing something. aria-expanded
             * says the same thing to anyone not looking at it.
             */
            ?>
            <button type="button" class="reports-date-picker-toggle button" aria-expanded="false">
                <span class="reports-date-picker-label"><?php echo esc_html( $preset_label ); ?></span>
                <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
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
                            <?php esc_html_e( 'Start Date', 'arraypress' ); ?>
                            <input type="date"
                                    class="reports-date-start"
                                    value="<?php echo esc_attr( $current_range['start_local'] ?? '' ); ?>"/>
                        </label>
                    </div>
                    <div class="reports-date-picker-custom-row">
                        <label>
                            <?php esc_html_e( 'End Date', 'arraypress' ); ?>
                            <input type="date"
                                    class="reports-date-end"
                                    value="<?php echo esc_attr( $current_range['end_local'] ?? '' ); ?>"/>
                        </label>
                    </div>
                    <div class="reports-date-picker-custom-actions">
                        <button type="button" class="button reports-date-picker-cancel">
                            <?php esc_html_e( 'Cancel', 'arraypress' ); ?>
                        </button>
                        <button type="button" class="button button-primary reports-date-picker-apply">
                            <?php esc_html_e( 'Apply', 'arraypress' ); ?>
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
        return Site::format( $utc_date, $format );
    }

    /**
     * Get number of days in date range.
     *
     * @param array $date_range Date range array with UTC dates.
     *
     * @return int
     */
    public function get_days_in_range( array $date_range ): int {
        return Range::between( $date_range['start'], $date_range['end'] )->days();
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
            return self::format_range( $this->date_range['start'], $this->date_range['end'] );
        }

        return '';
    }

    /**
     * "1 – 31 May 2026", or the two dates in full when they straddle a month.
     *
     * @param string $start UTC start.
     * @param string $end   UTC end.
     *
     * @return string
     */
    protected static function format_range( string $start, string $end ): string {
        $from = Site::format( $start, 'j M Y' );
        $to   = Site::format( $end, 'j M Y' );

        if ( '' === $from || '' === $to ) {
            return '';
        }

        return $from === $to ? $from : $from . ' – ' . $to;
    }
}