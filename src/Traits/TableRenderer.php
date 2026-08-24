<?php
/**
 * Table Component Rendering
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

/**
 * The tables inside a report, which are not list tables.
 *
 * A report table is a fixed set of rows about a period — top products, recent
 * refunds — with no paging, no bulk actions and no screen options. Sharing
 * WP_List_Table for that would mean inheriting the whole apparatus to use
 * none of it.
 */
trait TableRenderer {

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
        $empty_message = $component['empty_message'] ?? __( 'No data available.', 'arraypress' );
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
                            <?php
                            foreach ( $columns as $key => $column ) :
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
                                <th class="reports-table-actions-col"><?php esc_html_e( 'Actions', 'arraypress' ); ?></th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <?php
                                foreach ( $columns as $key => $column ) :
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

        // Each link in $action_links was assembled above from esc_attr()/esc_html()
        // parts; there is nothing left here for the sniff to escape.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div class="reports-row-actions-wrap">' . implode( ' <span class="sep">|</span> ', $action_links ) . '</div>';
    }
}
