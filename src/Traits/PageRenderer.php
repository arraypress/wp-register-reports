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

use ArrayPress\FieldKit\Context\ArrayContext;
use ArrayPress\FieldKit\FieldSet;
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
        $tab_filters = $this->filters_for( $current_tab );

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
        // Nothing goes in the actions slot. The date range and the refresh
        // control used to, which centred them under the title and stacked
        // them one above the other; they are in a row under the tabs now,
        // beside the filters they belong with.
        $header = PageHeader::render(
                [
					'title'         => $has_title ? $header_title : '',
					'logo'          => $logo_url,
					'logo_position' => (string) ( $this->config['logo_position'] ?? 'beside' ),
					'tabs'          => $has_tabs ? $this->header_tabs() : [],
					'current'       => $current_tab,
                ]
        );

        echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.

        $this->render_controls_bar( $tab_filters );
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
				//
				// preg_replace, not ltrim: ltrim takes a set of characters,
				// not a prefix, so it ate every leading letter that happened
				// to appear in "dashicons-" — 'dashicons-chart-bar' came out
				// as 'rt-bar' and rendered nothing. 'dashicons-list-view'
				// survived because l is not in the set, which is why exactly
				// one tab on the demo had an icon.
				'icon'  => (string) preg_replace( '/^dashicons-/', '', (string) ( $tab['icon'] ?? '' ) ),
            ];
        }

        return $tabs;
    }

    /**
     * The filters a tab shows.
     *
     * A report's own filters plus that tab's. Most of the useful ones — by
     * product, by country, by gateway — apply to the whole report rather
     * than to one tab of it, and repeating them under every tab is how one
     * of them ends up configured differently from the others.
     *
     * The tab wins on a shared key, so a tab can narrow or replace a
     * report-wide filter rather than only add to it.
     *
     * Public alongside get_tabs() and get_components(), because "what can
     * this report be filtered by" is a question a consumer asks — to build a
     * saved view, or an export that matches what is on screen.
     *
     * @param string $tab The current tab.
     *
     * @return array<string, mixed>
     */
    public function filters_for( string $tab ): array {
        return array_merge(
            (array) ( $this->config['filters'] ?? [] ),
            (array) ( $this->tabs[ $tab ]['filters'] ?? [] )
        );
    }

    /**
     * The bar under the header: date range, filters, refresh.
     *
     * Everything that changes what is on screen, in one row, immediately
     * under the tabs it applies to — which is where a list table puts its
     * filters and where every analytics screen worth using puts its date
     * range. It was in the header's actions slot before, centred under the
     * title, with the refresh button stacked on top of it.
     *
     * Rendered whenever there is anything to put in it, so a report with no
     * filters still gets its date range and a report with no date picker
     * still gets its filters.
     *
     * @param array<string, mixed> $filters The current tab's filters.
     *
     * @return void
     */
    protected function render_controls_bar( array $filters ): void {
        $date    = (bool) ( $this->config['show_date_picker'] ?? true );
        $refresh = (bool) ( $this->config['show_refresh'] ?? true );
        $auto    = (int) ( $this->config['auto_refresh'] ?? 0 );

        if ( ! $date && ! $refresh && $auto < 1 && [] === $filters ) {
            return;
        }

        echo '<div class="reports-controls-bar">';

        // Filters on the left: they are the part with a variable number of
        // controls, and a ragged right edge reads better than a ragged left.
        echo '<div class="reports-controls-bar-start">';

        if ( [] !== $filters ) {
            $this->render_filter_bar( $filters );
        }

        echo '</div>';

        // The date range and the refresh together on the right. They are the
        // two controls that are always there and never change shape, so they
        // are the fixed point the rest of the bar is read against.
        echo '<div class="reports-controls-bar-end">';

        if ( $date ) {
            $this->render_date_picker();
        }

        $this->render_refresh_controls();

        echo '</div>';
        echo '</div>';
    }

    /**
     * The refresh button, and the "updated just now" it sits beside.
     *
     * @return void
     */
    protected function render_refresh_controls(): void {
        $show = (bool) ( $this->config['show_refresh'] ?? true );
        $auto = (int) ( $this->config['auto_refresh'] ?? 0 );

        if ( ! $show && $auto < 1 ) {
            return;
        }

        printf(
                '<div class="reports-refresh-controls" data-auto-refresh="%s" data-report-id="%s">',
                esc_attr( (string) $auto ),
                esc_attr( $this->id )
        );

        if ( $auto > 0 ) {
            printf(
                    '<span class="reports-last-updated"><span class="reports-last-updated-text">%s</span></span>',
                    esc_html__( 'Updated just now', 'arraypress' )
            );
        }

        if ( $show ) {
            printf(
                    '<button type="button" class="button reports-refresh-button" title="%s">' .
                    '<span class="dashicons dashicons-update" aria-hidden="true"></span>' .
                    '<span class="screen-reader-text">%1$s</span></button>',
                    esc_attr__( 'Refresh', 'arraypress' )
            );
        }

        echo '</div>';
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
     * One filter control.
     *
     * Drawn by the field kit rather than by three branches of hand-rolled
     * markup here. A select becomes the same searchable combobox the
     * settings screens use, which is what a filter with four hundred
     * products in it needs and what a plain dropdown cannot be — and every
     * type the kit gains, this gains with it.
     *
     * The label is present and hidden. A bar with a word above every control
     * is twice as tall as the controls in it and reads as a form; a control
     * with no accessible name is one that cannot be identified by anyone
     * using a screen reader. Hidden visually, announced properly.
     *
     * @param string               $filter_key The filter's key.
     * @param array<string, mixed> $filter     Its configuration.
     *
     * @return void
     */
    protected function render_filter_field( string $filter_key, array $filter ): void {
        $name  = 'filter_' . $filter_key;
        $type  = (string) ( $filter['type'] ?? 'select' );
        $label = (string) ( $filter['label'] ?? ucfirst( $filter_key ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $value = isset( $_GET[ $name ] )
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) )
            : (string) ( $filter['default'] ?? '' );

        $config = array_merge(
            $filter,
            [
                'label' => $label,

                // A select filters a list somebody has to find something in,
                // which is the combobox's whole job.
                'type'  => 'select' === $type ? 'enhanced_select' : $type,
            ]
        );

        // An empty "All countries" entry is the kit's placeholder, not one of
        // the options — offered as an option it appears twice, once selected.
        if ( isset( $config['options'][''] ) ) {
            $config['placeholder'] = $config['placeholder'] ?? $config['options'][''];

            unset( $config['options'][''] );
        }

        $set = new FieldSet( [ $name => $config ], new ArrayContext( [ $name => $value ] ), '' );

        printf(
            '<div class="reports-filter-field reports-filter-%s">' .
            '<label for="%s" class="screen-reader-text">%s</label>%s</div>',
            esc_attr( $type ),
            esc_attr( $name ),
            esc_html( $label ),
            // The kit escapes as it builds.
            $set->render_field( $set->field( $name ), '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
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
