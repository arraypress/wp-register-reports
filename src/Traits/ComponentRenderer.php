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

use ArrayPress\DateUtils\Dates;

/**
 * Trait ComponentRenderer
 *
 * Handles rendering of report components.
 */
trait ComponentRenderer {

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
        $rendered = apply_filters( 'reports_render_component', false, $component_id, $component, $this->date_range );

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

        if ( $change === null && $previous_value !== null && is_numeric( $value ) && is_numeric( $previous_value ) && $previous_value != 0 ) {
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
                        <?php echo esc_html( number_format( $change, 1 ) . '%' ); ?>
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
                <?php foreach ( $tiles as $tile_id => $tile ) :
                    $tile = wp_parse_args( $tile, [
                            'type'         => 'tile',
                            'icon'         => 'dashicons-chart-bar',
                            'icon_color'   => 'gray',
                            'value_format' => 'number',
                    ] );
                    $this->render_tile( $component_id . '_' . $tile_id, $tile );
                endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a chart component.
     *
     * @param string $component_id Component ID.
     * @param array  $component    Component configuration.
     *
     * @return void
     */
    protected function render_chart( string $component_id, array $component ): void {
        $data = [];

        // Get data if callback exists
        if ( ! empty( $component['data_callback'] ) && is_callable( $component['data_callback'] ) ) {
            $data = call_user_func( $component['data_callback'], $this->date_range, $component );
        }

        $chart_type = $component['chart_type'] ?? 'line';
        $height     = $component['height'] ?? 300;

        // Prepare chart configuration
        $chart_config = [
                'type'    => $chart_type,
                'data'    => [
                        'labels'   => $data['labels'] ?? [],
                        'datasets' => $data['datasets'] ?? [],
                ],
                'options' => $this->get_chart_options( $component, $chart_type ),
        ];

        $width_class = $this->get_width_class( $component['width'] ?? 'full' );

        ?>
        <div class="reports-chart-wrapper <?php echo esc_attr( $width_class . ' ' . ( $component['class'] ?? '' ) ); ?>"
             data-component-id="<?php echo esc_attr( $component_id ); ?>"
             data-ajax-refresh="<?php echo $component['ajax_refresh'] ? 'true' : 'false'; ?>">

            <?php if ( ! empty( $component['title'] ) ) : ?>
                <?php $period_label = $this->get_period_label(); ?>
                <div class="reports-chart-header">
                    <h3 class="reports-chart-title">
                        <?php echo esc_html( $component['title'] ); ?>
                        <?php if ( $period_label ) : ?>
                            <span class="reports-chart-period">— <?php echo esc_html( $period_label ); ?></span>
                        <?php endif; ?>
                    </h3>
                    <?php if ( ! empty( $component['description'] ) ) : ?>
                        <p class="reports-chart-description"><?php echo esc_html( $component['description'] ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="reports-chart-container" style="height: <?php echo esc_attr( $height ); ?>px;">
                <canvas id="chart-<?php echo esc_attr( $component_id ); ?>"
                        class="reports-chart-canvas"
                        data-chart-id="<?php echo esc_attr( $component_id ); ?>"
                        data-chart-config="<?php echo esc_attr( wp_json_encode( $chart_config ) ); ?>"></canvas>
            </div>

            <div class="reports-chart-loading" style="display: none;">
                <span class="spinner is-active"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Get Chart.js options based on component configuration.
     *
     * @param array  $component  Component configuration.
     * @param string $chart_type Chart type.
     *
     * @return array
     */
    protected function get_chart_options( array $component, string $chart_type ): array {
        $options = [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins'             => [
                        'legend'  => [
                                'display'  => $component['show_legend'] ?? true,
                                'position' => $component['legend_position'] ?? 'top',
                        ],
                        'tooltip' => [
                                'enabled'   => true,
                                'mode'      => 'index',
                                'intersect' => false,
                        ],
                ],
        ];

        // Add scales for line, bar, area charts
        if ( in_array( $chart_type, [ 'line', 'bar', 'area' ], true ) ) {
            $options['scales'] = [
                    'x' => [
                            'display' => true,
                            'title'   => [
                                    'display' => ! empty( $component['x_axis_label'] ),
                                    'text'    => $component['x_axis_label'] ?? '',
                            ],
                    ],
                    'y' => [
                            'display'     => true,
                            'beginAtZero' => true,
                            'title'       => [
                                    'display' => ! empty( $component['y_axis_label'] ),
                                    'text'    => $component['y_axis_label'] ?? '',
                            ],
                    ],
            ];

            // Stacked option
            if ( ! empty( $component['stacked'] ) ) {
                $options['scales']['x']['stacked'] = true;
                $options['scales']['y']['stacked'] = true;
            }
        }

        // Line chart specific options
        if ( $chart_type === 'line' || $chart_type === 'area' ) {
            $options['elements'] = [
                    'line' => [
                            'tension' => $component['tension'] ?? 0.4,
                    ],
            ];
        }

        return $options;
    }

    /**
     * Render a table component.
     *
     * @param string $component_id Component ID.
     * @param array  $component    Component configuration.
     *
     * @return void
     */
    protected function render_table( string $component_id, array $component ): void {
        $data = [];

        // Get data if callback exists
        if ( ! empty( $component['data_callback'] ) && is_callable( $component['data_callback'] ) ) {
            $data = call_user_func( $component['data_callback'], $this->date_range, $component );
        }

        $columns       = $component['columns'] ?? [];
        $rows          = $data['rows'] ?? $data ?? [];
        $empty_message = $component['empty_message'] ?? __( 'No data available.', 'reports' );
        $row_actions   = $component['row_actions'] ?? [];
        $is_paginated  = ! empty( $component['paginated'] );
        $per_page      = $component['per_page'] ?? 10;

        $width_class = $this->get_width_class( $component['width'] ?? 'full' );

        // Prepare column config for JavaScript (for refresh support)
        $js_columns = [];
        foreach ( $columns as $key => $column ) {
            $column_key   = is_string( $key ) ? $key : $column;
            $column_label = is_array( $column ) ? ( $column['label'] ?? $key ) : $column;
            $column_format = is_array( $column ) ? ( $column['format'] ?? '' ) : '';

            $js_columns[] = [
                    'key'    => $column_key,
                    'label'  => $column_label,
                    'format' => $column_format,
            ];
        }

        // Prepare row actions config for JavaScript
        $js_row_actions = [];
        foreach ( $row_actions as $action_key => $action ) {
            $js_row_actions[] = [
                    'key'     => $action_key,
                    'label'   => $action['label'] ?? ucfirst( $action_key ),
                    'url'     => $action['url'] ?? '#',
                    'class'   => $action['class'] ?? '',
                    'confirm' => $action['confirm'] ?? '',
                    'target'  => $action['target'] ?? '',
            ];
        }

        // Build table config data attribute
        $table_config = [
                'columns'      => $js_columns,
                'rowActions'   => $js_row_actions,
                'emptyMessage' => $empty_message,
                'paginated'    => $is_paginated,
                'perPage'      => $per_page,
        ];

        ?>
        <div class="reports-table-wrapper <?php echo esc_attr( $width_class . ' ' . ( $component['class'] ?? '' ) ); ?>"
             data-component-id="<?php echo esc_attr( $component_id ); ?>"
             data-ajax-refresh="<?php echo ! empty( $component['ajax_refresh'] ) ? 'true' : 'false'; ?>"
             data-table-config="<?php echo esc_attr( wp_json_encode( $table_config ) ); ?>">

            <?php if ( ! empty( $component['title'] ) ) : ?>
                <div class="reports-table-header">
                    <h3 class="reports-table-title"><?php echo esc_html( $component['title'] ); ?></h3>
                    <?php if ( ! empty( $component['description'] ) ) : ?>
                        <p class="reports-table-description"><?php echo esc_html( $component['description'] ); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="reports-table-container"
                 data-paginated="<?php echo $is_paginated ? 'true' : 'false'; ?>"
                 data-per-page="<?php echo esc_attr( $per_page ); ?>">

                <?php if ( empty( $rows ) ) : ?>
                    <div class="reports-table-empty">
                        <p><?php echo esc_html( $empty_message ); ?></p>
                    </div>
                <?php else : ?>
                    <table class="reports-table widefat striped">
                        <thead>
                        <tr>
                            <?php foreach ( $columns as $key => $column ) :
                                $column_key   = is_string( $key ) ? $key : $column;
                                $column_label = is_array( $column ) ? ( $column['label'] ?? $key ) : $column;
                                $is_sortable = $component['sortable'] ?? false;
                                if ( is_array( $column ) && isset( $column['sortable'] ) ) {
                                    $is_sortable = $column['sortable'];
                                }
                                ?>
                                <th class="<?php echo $is_sortable ? 'sortable' : ''; ?>"
                                    data-column="<?php echo esc_attr( $column_key ); ?>">
                                    <?php echo esc_html( $column_label ); ?>
                                </th>
                            <?php endforeach; ?>
                            <?php if ( ! empty( $row_actions ) ) : ?>
                                <th class="reports-table-actions-col"><?php esc_html_e( 'Actions', 'reports' ); ?></th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <?php foreach ( $columns as $key => $column ) :
                                    $column_key = is_string( $key ) ? $key : $column;
                                    $cell_value = $row[ $column_key ] ?? '';
                                    $format = is_array( $column ) ? ( $column['format'] ?? '' ) : '';

                                    if ( $format ) {
                                        $cell_value = $this->format_value( $cell_value, $format );
                                    }
                                    ?>
                                    <td data-column="<?php echo esc_attr( $column_key ); ?>">
                                        <?php echo wp_kses_post( $cell_value ); ?>
                                    </td>
                                <?php endforeach; ?>
                                <?php if ( ! empty( $row_actions ) ) : ?>
                                    <td class="reports-table-actions">
                                        <?php $this->render_row_actions( $row_actions, $row ); ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ( $is_paginated && count( $rows ) > $per_page ) : ?>
                        <div class="reports-table-pagination">
                            <span class="reports-table-info"></span>
                            <div class="reports-table-pages"></div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="reports-table-loading" style="display: none;">
                <span class="spinner is-active"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render row actions for a table row.
     *
     * @param array $actions Action definitions.
     * @param array $row     Row data.
     *
     * @return void
     */
    protected function render_row_actions( array $actions, array $row ): void {
        $action_links = [];

        foreach ( $actions as $action_key => $action ) {
            $label   = $action['label'] ?? ucfirst( $action_key );
            $class   = $action['class'] ?? '';
            $confirm = $action['confirm'] ?? '';
            $url     = '#';

            // Get URL from callback or template
            if ( ! empty( $action['url_callback'] ) && is_callable( $action['url_callback'] ) ) {
                $url = call_user_func( $action['url_callback'], $row );
            } elseif ( ! empty( $action['url'] ) ) {
                // Replace {placeholders} with row values
                $url = $action['url'];
                foreach ( $row as $key => $value ) {
                    if ( is_scalar( $value ) ) {
                        $url = str_replace( '{' . $key . '}', urlencode( (string) $value ), $url );
                    }
                }
            }

            // Build attributes
            $attrs = sprintf( 'href="%s"', esc_url( $url ) );
            $attrs .= sprintf( ' class="reports-row-action reports-row-action-%s %s"', esc_attr( $action_key ), esc_attr( $class ) );

            if ( $confirm ) {
                $attrs .= sprintf( ' onclick="return confirm(\'%s\')"', esc_js( $confirm ) );
            }

            if ( ! empty( $action['target'] ) ) {
                $attrs .= sprintf( ' target="%s"', esc_attr( $action['target'] ) );
            }

            $action_links[] = sprintf( '<a %s>%s</a>', $attrs, esc_html( $label ) );
        }

        echo '<div class="reports-row-actions-wrap">' . implode( ' <span class="sep">|</span> ', $action_links ) . '</div>';
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
        switch ( $format ) {
            case 'currency':
                // Use wp-currencies library (amounts should be in cents)
                $cents    = is_float( $value ) ? (int) ( $value * 100 ) : (int) $value;
                $currency = $component['currency'] ?? 'USD';

                return format_currency( $cents, $currency );

            case 'percentage':
                return number_format_i18n( (float) $value, 1 ) . '%';

            case 'number':
                if ( is_float( $value ) && floor( $value ) != $value ) {
                    return number_format_i18n( (float) $value, 2 );
                }

                return number_format_i18n( (int) $value );

            case 'decimal':
                return number_format_i18n( (float) $value, 2 );

            case 'date':
                // Use wp-date-utils for proper UTC to local conversion
                return Dates::format( $value, 'date' );

            case 'datetime':
                // Use wp-date-utils for proper UTC to local conversion
                return Dates::format( $value );

            default:
                return (string) $value;
        }
    }

    /**
     * Format a change value.
     *
     * @param mixed $change The change value.
     *
     * @return string
     */
    protected function format_change( $change ): string {
        if ( $change === null ) {
            return '';
        }

        $prefix = $change > 0 ? '+' : '';

        return $prefix . number_format_i18n( (float) $change, 1 ) . '%';
    }

}