<?php
/**
 * Menu Registration
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

/**
 * Putting the report in the admin menu, and keeping it highlighted.
 *
 * The highlighting is the awkward half: core decides what to highlight from
 * the query string alone, and gets it wrong the moment a page is not where
 * its slug says it is — so the parent and the submenu are corrected by hand.
 */
trait MenuBuilder {

/**
     * Register the admin menu page.
     *
     * @return void
     */
    public function register_menu(): void {
        if ( ! empty( $this->config['parent_slug'] ) ) {
            $this->hook_suffix = add_submenu_page(
                    $this->config['parent_slug'],
                    $this->config['page_title'],
                    $this->config['menu_title'],
                    $this->config['capability'],
                    $this->config['menu_slug'],
                    [ $this, 'render_page' ]
            );
        } else {
            $this->hook_suffix = add_menu_page(
                    $this->config['page_title'],
                    $this->config['menu_title'],
                    $this->config['capability'],
                    $this->config['menu_slug'],
                    [ $this, 'render_page' ],
                    $this->config['icon'],
                    $this->config['position']
            );
        }

        // Register help tabs
        if ( ! empty( $this->config['help_tabs'] ) || ! empty( $this->config['help_sidebar'] ) ) {
            add_action( 'load-' . $this->hook_suffix, [ $this, 'register_help_tabs' ] );
        }
    }

/**
     * Register help tabs for the reports screen.
     *
     * @return void
     */
    public function register_help_tabs(): void {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        if ( ! empty( $this->config['help_tabs'] ) ) {
            foreach ( $this->config['help_tabs'] as $tab_id => $tab ) {
                $screen->add_help_tab( [
					'id'       => $this->id . '_' . $tab_id,
					'title'    => $tab['title'] ?? $tab_id,
					'content'  => $tab['content'] ?? '',
					'callback' => $tab['callback'] ?? null,
					'priority' => $tab['priority'] ?? 10,
                ] );
            }
        }

        if ( ! empty( $this->config['help_sidebar'] ) ) {
            $screen->set_help_sidebar( $this->config['help_sidebar'] );
        }
    }

/**
     * Add custom body class to the reports page.
     *
     * @param string $classes Space-separated list of body classes.
     *
     * @return string
     */
    public function add_body_class( string $classes ): string {
        $screen = get_current_screen();

        if ( ! $screen || $screen->id !== $this->hook_suffix ) {
            return $classes;
        }

        // Add generic reports class
        $classes .= ' reports';

        // Add report-specific class
        $classes .= ' reports-' . $this->id;

        // Add custom class from config if provided
        if ( ! empty( $this->config['body_class'] ) ) {
            $classes .= ' ' . sanitize_html_class( $this->config['body_class'] );
        }

        return $classes;
    }

/**
     * Fix parent menu highlight for report pages.
     *
     * @param string $parent_file The parent file.
     *
     * @return string
     */
    public function fix_parent_menu_highlight( string $parent_file ): string {
        global $plugin_page;

        if ( $plugin_page === $this->config['menu_slug'] ) {
            return $this->config['parent_slug'];
        }

        return $parent_file;
    }

/**
     * Fix submenu highlight for report pages.
     *
     * @param string|null $submenu_file The submenu file.
     *
     * @return string|null
     */
    public function fix_submenu_highlight( ?string $submenu_file ): ?string {
        global $plugin_page;

        if ( $plugin_page === $this->config['menu_slug'] ) {
            return $this->config['menu_slug'];
        }

        return $submenu_file;
    }
}
