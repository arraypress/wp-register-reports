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

/**
 * Trait ExportHandler
 *
 * Handles batched CSV export functionality with progress tracking.
 */
trait ExportHandler {

    /**
     * Get export directory path.
     *
     * @return string
     */
    protected function get_export_dir(): string {
        $upload_dir = wp_upload_dir();
        $export_dir = trailingslashit( $upload_dir['basedir'] ) . 'reports-exports';

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
    protected function get_export_path( string $export_id ): string {
        return $this->get_export_dir() . '/' . $export_id . '.csv';
    }

    /**
     * Get download URL for export.
     *
     * @param string $export_id Export ID.
     *
     * @return string
     */
    protected function get_download_url( string $export_id ): string {
        return add_query_arg( [
                'action'    => 'reports_download_export',
                'export_id' => $export_id,
                'nonce'     => wp_create_nonce( 'reports_export_' . $export_id ),
        ], admin_url( 'admin-ajax.php' ) );
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
    protected function write_csv_batch( string $file_path, array $data, bool $is_first_batch, array $headers = [] ): bool {
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
                fputcsv( $fp, $header_row );
            } else {
                // Fall back to using keys as headers
                fputcsv( $fp, array_keys( $first_row ) );
            }
        }

        // Write data rows
        foreach ( $data as $row ) {
            if ( is_array( $row ) ) {
                fputcsv( $fp, array_values( $row ) );
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

        $like       = $wpdb->esc_like( '_transient_reports_export_' ) . '%';
        $transients = $wpdb->get_col(
                $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $like
                )
        );

        foreach ( $transients as $transient ) {
            $export_id = str_replace( '_transient_reports_export_', '', $transient );
            $config    = get_transient( 'reports_export_' . $export_id );

            if ( ! $config || ! isset( $config['file_path'] ) || ! $wp_filesystem->exists( $config['file_path'] ) ) {
                delete_transient( 'reports_export_' . $export_id );
            }
        }
    }

    /**
     * Render exports section.
     *
     * @param array $exports Exports for current tab.
     *
     * @return void
     */
    protected function render_exports_section( array $exports ): void {
        ?>
        <div class="reports-exports-section">
            <?php foreach ( $exports as $export_id => $export ) : ?>
                <?php $this->render_export_card( $export_id, $export ); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a single export card.
     *
     * @param string $export_id Export identifier.
     * @param array  $export    Export configuration.
     *
     * @return void
     */
    protected function render_export_card( string $export_id, array $export ): void {
        ?>
        <div class="reports-export-card" data-export-id="<?php echo esc_attr( $export_id ); ?>">
            <div class="reports-export-header">
                <?php if ( ! empty( $export['icon'] ) ) : ?>
                    <span class="dashicons <?php echo esc_attr( $export['icon'] ); ?>"></span>
                <?php else : ?>
                    <span class="dashicons dashicons-download"></span>
                <?php endif; ?>
                <h3><?php echo esc_html( $export['title'] ?? __( 'Export Data', 'developer-portal' ) ); ?></h3>
            </div>

            <?php if ( ! empty( $export['description'] ) ) : ?>
                <p class="reports-export-description">
                    <?php echo esc_html( $export['description'] ); ?>
                </p>
            <?php endif; ?>

            <?php if ( ! empty( $export['filters'] ) ) : ?>
                <div class="reports-export-filters">
                    <?php $this->render_export_filters( $export_id, $export['filters'] ); ?>
                </div>
            <?php endif; ?>

            <!-- Progress bar (hidden by default) -->
            <div class="reports-export-progress" style="display: none;">
                <div class="reports-export-progress-bar">
                    <div class="reports-export-progress-fill"></div>
                </div>
                <div class="reports-export-progress-text">
                    <span class="reports-export-progress-label"><?php esc_html_e( 'Preparing export...', 'developer-portal' ); ?></span>
                    <span class="reports-export-progress-percent">0%</span>
                </div>
            </div>

            <div class="reports-export-actions">
                <button type="button"
                        class="button button-primary reports-export-button"
                        data-export-id="<?php echo esc_attr( $export_id ); ?>"
                        data-report-id="<?php echo esc_attr( $this->id ); ?>">
                    <span class="dashicons dashicons-download"></span>
                    <span class="button-text">
						<?php echo esc_html( $export['button_text'] ?? __( 'Download CSV', 'developer-portal' ) ); ?>
					</span>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render export filters.
     *
     * @param string $export_id Export identifier.
     * @param array  $filters   Filter configuration.
     *
     * @return void
     */
    protected function render_export_filters( string $export_id, array $filters ): void {
        foreach ( $filters as $filter_key => $filter ) {
            $field_name = 'filter_' . $filter_key;
            $field_id   = $export_id . '_' . $filter_key;
            $type       = $filter['type'] ?? 'select';
            $label      = $filter['label'] ?? $filter_key;

            ?>
            <div class="reports-filter-field reports-filter-<?php echo esc_attr( $type ); ?>">
                <label for="<?php echo esc_attr( $field_id ); ?>">
                    <?php echo esc_html( $label ); ?>
                </label>

                <?php
                switch ( $type ) {
                    case 'select':
                        $this->render_select_filter( $field_id, $field_name, $filter['options'] ?? [], $filter );
                        break;

                    case 'multiselect':
                        $this->render_multiselect_filter( $field_id, $field_name, $filter['options'] ?? [], $filter );
                        break;

                    case 'date':
                        $this->render_date_filter( $field_id, $field_name, $filter );
                        break;

                    case 'daterange':
                        $this->render_daterange_filter( $field_id, $field_name, $filter );
                        break;

                    case 'checkbox':
                        $this->render_checkbox_filter( $field_id, $field_name, $filter['options'] ?? [], $filter );
                        break;

                    case 'text':
                    default:
                        $this->render_text_filter( $field_id, $field_name, $filter );
                        break;
                }
                ?>

                <?php if ( ! empty( $filter['description'] ) ) : ?>
                    <p class="description"><?php echo esc_html( $filter['description'] ); ?></p>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    /**
     * Render a select filter.
     *
     * @param string $field_id   Field ID.
     * @param string $field_name Field name.
     * @param array  $options    Options array.
     * @param array  $filter     Filter config.
     *
     * @return void
     */
    protected function render_select_filter( string $field_id, string $field_name, array $options, array $filter ): void {
        $default = $filter['default'] ?? '';
        ?>
        <select id="<?php echo esc_attr( $field_id ); ?>"
                name="<?php echo esc_attr( $field_name ); ?>"
                class="reports-filter-input">
            <?php if ( ! empty( $filter['placeholder'] ) ) : ?>
                <option value=""><?php echo esc_html( $filter['placeholder'] ); ?></option>
            <?php endif; ?>
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $default, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render a multiselect filter.
     *
     * @param string $field_id   Field ID.
     * @param string $field_name Field name.
     * @param array  $options    Options array.
     * @param array  $filter     Filter config.
     *
     * @return void
     */
    protected function render_multiselect_filter( string $field_id, string $field_name, array $options, array $filter ): void {
        $default = (array) ( $filter['default'] ?? [] );
        ?>
        <select id="<?php echo esc_attr( $field_id ); ?>"
                name="<?php echo esc_attr( $field_name ); ?>[]"
                class="reports-filter-input"
                multiple>
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( in_array( $value, $default, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Render a date filter.
     *
     * @param string $field_id   Field ID.
     * @param string $field_name Field name.
     * @param array  $filter     Filter config.
     *
     * @return void
     */
    protected function render_date_filter( string $field_id, string $field_name, array $filter ): void {
        $default = $filter['default'] ?? '';
        ?>
        <input type="date"
               id="<?php echo esc_attr( $field_id ); ?>"
               name="<?php echo esc_attr( $field_name ); ?>"
               value="<?php echo esc_attr( $default ); ?>"
               class="reports-filter-input">
        <?php
    }

    /**
     * Render a date range filter.
     *
     * @param string $field_id   Field ID.
     * @param string $field_name Field name.
     * @param array  $filter     Filter config.
     *
     * @return void
     */
    protected function render_daterange_filter( string $field_id, string $field_name, array $filter ): void {
        $default_start = $filter['default_start'] ?? '';
        $default_end   = $filter['default_end'] ?? '';
        ?>
        <div class="reports-daterange-inputs">
            <input type="date"
                   id="<?php echo esc_attr( $field_id ); ?>_start"
                   name="<?php echo esc_attr( $field_name ); ?>_start"
                   value="<?php echo esc_attr( $default_start ); ?>"
                   class="reports-filter-input"
                   placeholder="<?php esc_attr_e( 'Start Date', 'developer-portal' ); ?>">
            <span class="reports-daterange-separator"><?php esc_html_e( 'to', 'developer-portal' ); ?></span>
            <input type="date"
                   id="<?php echo esc_attr( $field_id ); ?>_end"
                   name="<?php echo esc_attr( $field_name ); ?>_end"
                   value="<?php echo esc_attr( $default_end ); ?>"
                   class="reports-filter-input"
                   placeholder="<?php esc_attr_e( 'End Date', 'developer-portal' ); ?>">
        </div>
        <?php
    }

    /**
     * Render checkbox filters.
     *
     * @param string $field_id   Field ID.
     * @param string $field_name Field name.
     * @param array  $options    Options array.
     * @param array  $filter     Filter config.
     *
     * @return void
     */
    protected function render_checkbox_filter( string $field_id, string $field_name, array $options, array $filter ): void {
        $default = (array) ( $filter['default'] ?? [] );
        ?>
        <div class="reports-checkbox-group">
            <?php foreach ( $options as $value => $label ) : ?>
                <label class="reports-checkbox-label">
                    <input type="checkbox"
                           name="<?php echo esc_attr( $field_name ); ?>[]"
                           value="<?php echo esc_attr( $value ); ?>"
                           class="reports-filter-input"
                            <?php checked( in_array( $value, $default, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render a text filter.
     *
     * @param string $field_id   Field ID.
     * @param string $field_name Field name.
     * @param array  $filter     Filter config.
     *
     * @return void
     */
    protected function render_text_filter( string $field_id, string $field_name, array $filter ): void {
        $default     = $filter['default'] ?? '';
        $placeholder = $filter['placeholder'] ?? '';
        ?>
        <input type="text"
               id="<?php echo esc_attr( $field_id ); ?>"
               name="<?php echo esc_attr( $field_name ); ?>"
               value="<?php echo esc_attr( $default ); ?>"
               placeholder="<?php echo esc_attr( $placeholder ); ?>"
               class="reports-filter-input regular-text">
        <?php
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