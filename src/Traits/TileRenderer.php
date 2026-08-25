<?php
/**
 * Tile Rendering
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

/**
 * The row of figures across the top of a report.
 *
 * A number, what it is, and whether it went up. The comparison is the part
 * worth getting right: an arrow with no period attached to it says a number
 * changed without saying since when, which is not information.
 */
trait TileRenderer {

    /**
     * Render a grid of tiles.
     *
     * @param array $tiles Tile components.
     *
     * @return void
     */
    protected function render_tiles_grid( array $tiles ): void {
        echo '<div class="reports-tiles-grid">';

        foreach ( $tiles as $component_id => $component ) {
            $this->render_tile( $component_id, $component );
        }

        echo '</div>';
    }

    /**
     * Render a tile component.
     *
     * @param string $component_id Component ID.
     * @param array  $component    Component configuration.
     *
     * @return void
     */
    protected function render_tile( string $component_id, array $component ): void {
        $data = [];

        // Get data if callback exists
        if ( ! empty( $component['data_callback'] ) && is_callable( $component['data_callback'] ) ) {
            $data = call_user_func( $component['data_callback'], $this->date_range, $component );
        }

        $value          = $data['value'] ?? 0;
        $previous_value = $data['previous_value'] ?? null;
        $label          = $component['title'] ?? '';
        $icon_color     = $component['icon_color'] ?? 'gray';

        // Auto-calculate change if previous_value is provided and both are numeric
        $change           = $data['change'] ?? null;
        $change_direction = $data['change_direction'] ?? null;

        if ( $change === null && $previous_value !== null && is_numeric( $value ) && is_numeric( $previous_value ) && (float) $previous_value !== 0.0 ) {
            $change           = ( ( $value - $previous_value ) / abs( $previous_value ) ) * 100;
            $change_direction = $change > 0 ? 'up' : ( $change < 0 ? 'down' : 'neutral' );
            $change           = abs( $change );
        }

        // Get period label from date range
        $period_label = $this->get_period_label();

        // Normalize icon - allow both 'dashicons-money' and 'money'
        $icon = $component['icon'] ?? '';
        if ( $icon && ! str_starts_with( $icon, 'dashicons-' ) ) {
            $icon = 'dashicons-' . $icon;
        }

        ?>
        <div class="reports-tile"
            data-component-id="<?php echo esc_attr( $component_id ); ?>">

            <div class="reports-tile-header">
                <?php if ( $icon ) : ?>
                    <span class="reports-tile-icon icon-<?php echo esc_attr( $icon_color ); ?>">
						<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
					</span>
                <?php endif; ?>
                <span class="reports-tile-label"><?php echo esc_html( $label ); ?></span>
            </div>

            <div class="reports-tile-value">
                <?php echo esc_html( $this->format_value( $value, $component['value_format'] ?? 'number', $component ) ); ?>
            </div>

            <?php if ( ! empty( $component['sparkline'] ) ) : ?>
                <?php
                /*
                 * A tile says what a number is now. A sparkline is the cheapest
                 * way to also say where it is going, which is the question the
                 * number on its own always provokes — and unlike a chart it
                 * costs no title, no axes and no legend.
                 *
                 * No axes and no numbers on purpose: it is a shape, not a
                 * reading. Anyone who wants the values wants the chart, which
                 * is a component of its own. Hidden from assistive technology
                 * for the same reason — the figure and the change beside it
                 * already say everything a sparkline could.
                 */
                ?>
                <div class="reports-tile-sparkline" aria-hidden="true">
                    <canvas data-sparkline="<?php echo esc_attr( (string) wp_json_encode( array_map( 'floatval', (array) $component['sparkline'] ) ) ); ?>"
                        data-direction="<?php echo esc_attr( (string) $change_direction ); ?>"></canvas>
                </div>
            <?php endif; ?>

            <div class="reports-tile-footer">
                <?php if ( $change !== null && $change_direction ) : ?>
                    <?php
                    $change_class = 'change-neutral';
                    $change_icon  = 'minus';

                    if ( $change_direction === 'up' ) {
                        $change_class = 'change-up';
                        $change_icon  = 'arrow-up-alt';
                    } elseif ( $change_direction === 'down' ) {
                        $change_class = 'change-down';
                        $change_icon  = 'arrow-down-alt';
                    }
                    ?>
                    <div class="reports-tile-change <?php echo esc_attr( $change_class ); ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr( $change_icon ); ?>"></span>
                        <?php
                        // The magnitude only: the arrow beside it carries the
                        // direction, so a sign here would say it twice.
                        // number_format_i18n, not number_format — a German
                        // admin was reading 1.204,50 in the value above and
                        // 25.0% in the change below it.
                        echo esc_html( number_format_i18n( (float) $change, 1 ) . '%' );
                        ?>
                    </div>
                <?php else : ?>
                    <div class="reports-tile-change change-neutral"></div>
                <?php endif; ?>

                <?php if ( $period_label ) : ?>
                    <span class="reports-tile-period"><?php echo esc_html( $period_label ); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a tiles group component.
     *
     * @param string $component_id Component ID.
     * @param array  $component    Component configuration.
     *
     * @return void
     */
    protected function render_tiles_group( string $component_id, array $component ): void {
        $tiles   = $component['tiles'] ?? [];
        $columns = $component['columns'] ?? 4;

        if ( empty( $tiles ) ) {
            return;
        }

        ?>
        <div class="reports-tiles-wrapper"
            data-component-id="<?php echo esc_attr( $component_id ); ?>">

            <?php if ( ! empty( $component['title'] ) ) : ?>
                <h3 class="reports-tiles-wrapper-title"><?php echo esc_html( $component['title'] ); ?></h3>
            <?php endif; ?>

            <div class="reports-tiles-grid reports-tiles-columns-<?php echo esc_attr( $columns ); ?>">
                <?php
                foreach ( $tiles as $tile_id => $tile ) :
                    $tile = wp_parse_args( $tile, [
						'type'         => 'tile',
						'icon'         => 'dashicons-chart-bar',
						'icon_color'   => 'gray',
						'value_format' => 'number',
                    ] );
                    $this->render_tile( $component_id . '_' . $tile_id, $tile );
                endforeach;
                ?>
            </div>
        </div>
        <?php
    }
}
