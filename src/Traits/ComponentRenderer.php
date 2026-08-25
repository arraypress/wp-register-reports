<?php
/**
 * Component Renderer Trait
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

use ArrayPress\RegisterReports\Format;
use ArrayPress\RegisterReports\Utils\Runtime;

/**
 * Trait ComponentRenderer
 *
 * Handles rendering of report components.
 */
trait ComponentRenderer {

    use ChartRenderer;
    use TableRenderer;
    use TileRenderer;

    /**
     * Render all components for a tab.
     *
     * @param array $components Components to render.
     *
     * @return void
     */
    protected function render_components( array $components ): void {
        if ( empty( $components ) ) {
            return;
        }

        echo '<div class="reports-components">';

        // Group tiles together for grid layout
        $current_group = [];
        $current_type  = null;

        foreach ( $components as $component_id => $component ) {
            $type = $component['type'] ?? 'tile';

            // If we're switching from tiles to something else, render the tile group
            if ( $current_type === 'tile' && $type !== 'tile' && ! empty( $current_group ) ) {
                $this->render_tiles_grid( $current_group );
                $current_group = [];
            }

            if ( $type === 'tile' ) {
                $current_group[ $component_id ] = $component;
                $current_type                   = 'tile';
            } else {
                $current_type = $type;
                $this->render_component( $component_id, $component );
            }
        }

        // Render any remaining tiles
        if ( ! empty( $current_group ) ) {
            $this->render_tiles_grid( $current_group );
        }

        echo '</div>';
    }

    /**
     * Render a single component.
     *
     * @param string $component_id Component ID.
     * @param array  $component    Component configuration.
     *
     * @return void
     */
    protected function render_component( string $component_id, array $component ): void {
        $type = $component['type'] ?? 'tile';

        /**
         * Allow custom component rendering.
         *
         * @param bool   $rendered     Whether the component was rendered.
         * @param string $component_id Component ID.
         * @param array  $component    Component configuration.
         * @param array  $date_range   Current date range.
         */
        $rendered = apply_filters( Runtime::hook( 'render_component' ), false, $component_id, $component, $this->date_range );

        if ( $rendered ) {
            return;
        }

        switch ( $type ) {
            case 'tile':
                $this->render_tile( $component_id, $component );
                break;

            case 'tiles_group':
                $this->render_tiles_group( $component_id, $component );
                break;

            case 'chart':
                $this->render_chart( $component_id, $component );
                break;

            case 'table':
                $this->render_table( $component_id, $component );
                break;

            case 'html':
                $this->render_html_component( $component_id, $component );
                break;

            default:
                // Check for render callback
                if ( ! empty( $component['render_callback'] ) && is_callable( $component['render_callback'] ) ) {
                    call_user_func( $component['render_callback'], $component, $this->date_range, $this );
                }
                break;
        }
    }

    /**
     * Render an HTML component.
     *
     * @param string $component_id Component ID.
     * @param array  $component    Component configuration.
     *
     * @return void
     */
    protected function render_html_component( string $component_id, array $component ): void {
        $width_class = $this->get_width_class( $component['width'] ?? 'full' );

        ?>
        <div class="reports-html-component <?php echo esc_attr( $width_class . ' ' . ( $component['class'] ?? '' ) ); ?>"
            data-component-id="<?php echo esc_attr( $component_id ); ?>">

            <?php if ( ! empty( $component['title'] ) ) : ?>
                <h3 class="reports-html-title"><?php echo esc_html( $component['title'] ); ?></h3>
            <?php endif; ?>

            <div class="reports-html-content">
                <?php
                if ( ! empty( $component['render_callback'] ) && is_callable( $component['render_callback'] ) ) {
                    call_user_func( $component['render_callback'], $this->date_range, $component, $this );
                } elseif ( ! empty( $component['content'] ) ) {
                    echo wp_kses_post( $component['content'] );
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Get CSS width class for component.
     *
     * @param string $width Width setting.
     *
     * @return string
     */
    protected function get_width_class( string $width ): string {
        return match ( $width ) {
            'full' => 'reports-component--full',
            'half' => 'reports-component--half',
            'third' => 'reports-component--third',
            'quarter' => 'reports-component--quarter',
            'two-thirds' => 'reports-component--two-thirds',
            default => 'reports-component--auto',
        };
    }

    /**
     * Format a value based on type.
     *
     * Uses wp-currencies library for currency formatting if available.
     * Uses wp-date-utils library for date formatting.
     *
     * @param mixed  $value     The value to format.
     * @param string $format    The format type.
     * @param array  $component Component configuration (for currency option).
     *
     * @return string
     */
    protected function format_value( $value, string $format, array $component = [] ): string {
        return Format::value( $value, $format, (string) ( $component['currency'] ?? 'USD' ) );
    }

    /**
     * Format a change value.
     *
     * @param mixed $change The change value.
     *
     * @return string
     */
    protected function format_change( $change ): string {
        return Format::change( $change );
    }
}
