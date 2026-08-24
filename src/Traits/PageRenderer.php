<?php
/**
 * Page Rendering
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

use ArrayPress\FieldKit\Support\PageHeader;

/**
 * The page around the components: the heading, the tabs, the filter bar.
 *
 * All of it in core's own markup, so a report from this library looks like a
 * WordPress screen rather than like a plugin. The components themselves are
 * drawn empty and filled in over REST once the page is up — a report that
 * blocks its own render on nine database queries is a report that feels
 * broken even when it works.
 */
trait PageRenderer {

/**
     * Render the reports page.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( $this->config['capability'] ) ) {
            return;
        }

        // Get current tab and date range
        $current_tab      = $this->get_current_tab();
        $this->date_range = $this->get_current_date_range();

        // Add current filter values to date_range for callbacks
        $this->date_range['filters'] = $this->get_current_filters( $current_tab );

        // Render header OUTSIDE .wrap (matches RegisterSettingFields pattern)
        $this->render_header( $current_tab );

        ?>
        <div class="wrap reports-wrap" data-report-id="<?php echo esc_attr( $this->id ); ?>">

            <div class="reports-notices">
                <?php settings_errors( $this->id . '_notices' ); ?>
            </div>

            <div class="reports-content">
                <?php $this->render_tab_content( $current_tab ); ?>
            </div>
        </div>
        <?php
    }

/**
     * Render the modern header with optional logo, tabs, and date picker.
     *
     * Rendered outside .wrap to match RegisterSettingFields/EDD pattern.
     *
     * @param string $current_tab Current active tab.
     *
     * @return void
     */
    protected function render_header( string $current_tab ): void {
        $header_title = ! empty( $this->config['header_title'] )
                ? $this->config['header_title']
                : $this->config['page_title'];

        $show_title  = $this->config['show_title'] ?? true;
        $has_title   = $show_title && ! empty( $header_title );
        $has_tabs    = $this->config['show_tabs'] && ! empty( $this->tabs );
        $logo_url    = (string) ( $this->config['logo'] ?? '' );
        $tab_filters = $this->tabs[ $current_tab ]['filters'] ?? [];

        // Nothing to show: the rule still has to be there, since it is where
        // core moves admin notices to.
        if ( '' === $logo_url && ! $has_title && ! $has_tabs && ! $this->config['show_date_picker'] ) {
            echo '<hr class="wp-header-end">';

            return;
        }

        // The kit's header, which is core's own privacy-settings header. This
        // used to be a header of its own — a different height, a different
        // type scale, a slash between the logo and the title — so a plugin
        // with a settings page, a list table and a reports screen had three
        // headers that were nearly but not quite the same. There is one now.
        //
        // The refresh control and the date range go in the actions slot on
        // the right, which is the whole reason that slot exists.
        ob_start();
        $this->render_header_actions();
        $actions = (string) ob_get_clean();

        $header = PageHeader::render(
                [
					'title'         => $has_title ? $header_title : '',
					'logo'          => $logo_url,
					'logo_position' => (string) ( $this->config['logo_position'] ?? 'beside' ),
					'tabs'          => $has_tabs ? $this->header_tabs() : [],
					'current'       => $current_tab,
					'actions'       => $actions,
                ]
        );

        echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.

        if ( ! empty( $tab_filters ) ) {
            $this->render_filter_bar( $tab_filters );
        }
    }

/**
     * The tabs, in the shape the kit's header wants.
     *
     * @return array<string, array{label: string, url: string, icon: string}>
     */
    protected function header_tabs(): array {
        $tabs = [];

        foreach ( $this->tabs as $key => $tab ) {
            $tabs[ $key ] = [
				'label' => (string) ( $tab['label'] ?? $key ),
				'url'   => $this->get_tab_url( (string) $key ),

				// The library's own configuration spells these
				// 'dashicons-chart-bar'; the kit takes the name alone.
				'icon'  => ltrim( (string) ( $tab['icon'] ?? '' ), 'dashicons-' ),
            ];
        }

        return $tabs;
    }

/**
     * The refresh control and the date range, for the header's actions slot.
     *
     * @return void
     */
    protected function render_header_actions(): void {
        $show_refresh = $this->config['show_refresh'] ?? true;
        $auto_refresh = (int) ( $this->config['auto_refresh'] ?? 0 );

        if ( $show_refresh || $auto_refresh > 0 ) {
            printf(
                    '<div class="reports-refresh-controls" data-auto-refresh="%s" data-report-id="%s">',
                    esc_attr( (string) $auto_refresh ),
                    esc_attr( $this->id )
            );

            if ( $auto_refresh > 0 ) {
                printf(
                        '<span class="reports-last-updated"><span class="reports-last-updated-text">%s</span></span>',
                        esc_html__( 'Updated just now', 'arraypress' )
                );
            }

            if ( $show_refresh ) {
                printf(
                        '<button type="button" class="reports-refresh-button" title="%s">' .
                        '<span class="dashicons dashicons-update" aria-hidden="true"></span>' .
                        '<span class="screen-reader-text">%1$s</span></button>',
                        esc_attr__( 'Refresh', 'arraypress' )
                );
            }

            echo '</div>';
        }

        if ( $this->config['show_date_picker'] ) {
            $this->render_date_picker();
        }
    }

/**
     * Render the filter bar for a tab.
     *
     * @param array $filters Filter configuration.
     *
     * @return void
     */
    protected function render_filter_bar( array $filters ): void {
        ?>
        <div class="reports-filter-bar">
            <form class="reports-filter-form" method="get">
                <?php
                // Preserve existing params
                $preserve = [ 'page', 'tab', 'date_preset', 'date_start', 'date_end' ];
                foreach ( $preserve as $param ) {
                    if ( isset( $_GET[ $param ] ) ) {
                        printf(
                                '<input type="hidden" name="%s" value="%s">',
                                esc_attr( $param ),
                                esc_attr( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) )
                        );
                    }
                }
                ?>

                <div class="reports-filter-fields">
                    <?php
                    foreach ( $filters as $filter_key => $filter ) :
                        $this->render_filter_field( $filter_key, $filter );
                    endforeach;
                    ?>
                </div>

                <button type="submit" class="button reports-filter-submit">
                    <?php esc_html_e( 'Filter', 'arraypress' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

/**
     * Render a single filter field.
     *
     * @param string $filter_key Filter key.
     * @param array  $filter     Filter configuration.
     *
     * @return void
     */
    protected function render_filter_field( string $filter_key, array $filter ): void {
        $type          = $filter['type'] ?? 'select';
        $label         = $filter['label'] ?? ucfirst( $filter_key );
        $param_name    = 'filter_' . $filter_key;
        $current_value = isset( $_GET[ $param_name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param_name ] ) ) : ( $filter['default'] ?? '' );

        ?>
        <div class="reports-filter-field reports-filter-<?php echo esc_attr( $type ); ?>">
            <label for="<?php echo esc_attr( $param_name ); ?>"><?php echo esc_html( $label ); ?></label>

            <?php if ( $type === 'select' ) : ?>
                <select name="<?php echo esc_attr( $param_name ); ?>" id="<?php echo esc_attr( $param_name ); ?>">
                    <?php foreach ( $filter['options'] ?? [] as $value => $option_label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_value, $value ); ?>>
                            <?php echo esc_html( $option_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ( $type === 'checkbox' ) : ?>
                <input type="checkbox"
                        name="<?php echo esc_attr( $param_name ); ?>"
                        id="<?php echo esc_attr( $param_name ); ?>"
                        value="1"
                        <?php checked( $current_value, '1' ); ?>>

            <?php elseif ( $type === 'text' ) : ?>
                <input type="text"
                        name="<?php echo esc_attr( $param_name ); ?>"
                        id="<?php echo esc_attr( $param_name ); ?>"
                        value="<?php echo esc_attr( $current_value ); ?>"
                        placeholder="<?php echo esc_attr( $filter['placeholder'] ?? '' ); ?>">

            <?php endif; ?>
        </div>
        <?php
    }

/**
     * Render content for a specific tab.
     *
     * @param string $tab Tab key.
     *
     * @return void
     */
    protected function render_tab_content( string $tab ): void {
        $tab_components = $this->get_components_for_tab( $tab );
        $tab_exports    = $this->get_exports_for_tab( $tab );

        // Check for custom render callback on tab
        if ( isset( $this->tabs[ $tab ]['render_callback'] ) && is_callable( $this->tabs[ $tab ]['render_callback'] ) ) {
            call_user_func( $this->tabs[ $tab ]['render_callback'], $this->date_range, $this );

            return;
        }

        // Render exports section if present
        if ( ! empty( $tab_exports ) ) {
            $exports_columns = $this->tabs[ $tab ]['exports_columns'] ?? $this->config['exports_columns'] ?? 0;
            $this->render_exports_section( $tab_exports, $exports_columns );
        }

        // Render components
        if ( ! empty( $tab_components ) ) {
            $this->render_components( $tab_components );
        }

        // Show empty state if no content
        if ( empty( $tab_components ) && empty( $tab_exports ) && ! isset( $this->tabs[ $tab ]['render_callback'] ) ) {
            $this->render_empty_state();
        }
    }

/**
     * Render empty state.
     *
     * @return void
     */
    protected function render_empty_state(): void {
        ?>
        <div class="reports-empty-state">
            <span class="dashicons dashicons-chart-bar"></span>
            <h3><?php esc_html_e( 'No Reports Configured', 'arraypress' ); ?></h3>
            <p><?php esc_html_e( 'Add components or a render callback to display reports here.', 'arraypress' ); ?></p>
        </div>
        <?php
    }
}
