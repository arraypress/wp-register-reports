<?php
/**
 * Tab Manager Trait
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

/**
 * Trait TabManager
 *
 * Handles tab navigation and rendering.
 */
trait TabManager {

	/**
	 * Get the current active tab.
	 *
	 * @return string
	 */
	protected function get_current_tab(): string {
		if ( empty( $this->tabs ) ) {
			return '';
		}

		$current = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : '';

		// Validate tab exists
		if ( ! empty( $current ) && isset( $this->tabs[ $current ] ) ) {
			return $current;
		}

		// Return first tab as default
		return array_key_first( $this->tabs );
	}

	/**
	 * Get the URL for a specific tab.
	 *
	 * @param string $tab Tab key.
	 *
	 * @return string
	 */
	protected function get_tab_url( string $tab ): string {
		$args = [ 'tab' => $tab ];

		// Preserve date range parameters
		if ( ! empty( $_GET['date_preset'] ) ) {
			$args['date_preset'] = sanitize_key( wp_unslash( $_GET['date_preset'] ) );
		}
		if ( ! empty( $_GET['date_start'] ) ) {
			$args['date_start'] = sanitize_text_field( wp_unslash( $_GET['date_start'] ) );
		}
		if ( ! empty( $_GET['date_end'] ) ) {
			$args['date_end'] = sanitize_text_field( wp_unslash( $_GET['date_end'] ) );
		}

		return $this->page_url( $args );
	}

	/**
	 * The URL of the report's own admin page.
	 *
	 * This was hardcoded to admin.php, which is right only for a report with
	 * no parent. WordPress puts a submenu page under whatever file its parent
	 * slug names -- a report under `edit.php?post_type=book` lives at
	 * `edit.php?post_type=book&page=my-report`, and asking for
	 * `admin.php?page=my-report` gets "Sorry, you are not allowed to access
	 * this page." Every tab link was built that way, so a report anywhere
	 * but the top level had tabs that refused to load.
	 *
	 * The rule is core's own: a parent slug naming a .php file is the file;
	 * anything else is a plugin page under admin.php. Query arguments in the
	 * parent slug are part of the address and are kept.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 *
	 * @return string
	 */
	protected function page_url( array $args = [] ): string {
		$parent = (string) ( $this->config['parent_slug'] ?? '' );
		$file   = 'admin.php';
		$extra  = [];

		if ( '' !== $parent ) {
			$parts = explode( '?', $parent, 2 );

			// A parent that is itself a plugin page -- 'my-plugin' -- is
			// served by admin.php like any other.
			if ( str_contains( $parts[0], '.php' ) ) {
				$file = $parts[0];

				if ( isset( $parts[1] ) ) {
					parse_str( $parts[1], $extra );
				}
			}
		}

		return add_query_arg(
			array_merge( $extra, [ 'page' => $this->config['menu_slug'] ], $args ),
			admin_url( $file )
		);
	}

	/**
	 * The query arguments that identify this page.
	 *
	 * Everything page_url() puts in the query string, read back out of it:
	 * `page`, plus whatever the parent menu carries, which for a report
	 * under a post type is `post_type`. A GET form replaces the query string
	 * outright, so the filter form has to write these back as hidden inputs
	 * or they are gone the moment it submits -- and losing post_type is
	 * losing the screen. Derived rather than listed a second time, so the
	 * form cannot disagree with the tab links about where the page is.
	 *
	 * @return array<string, string>
	 */
	protected function page_args(): array {
		$args  = [];
		$query = (string) wp_parse_url( $this->page_url(), PHP_URL_QUERY );

		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	/**
	 * Render the tab navigation.
	 *
	 * @param string $current_tab Currently active tab.
	 *
	 * @return void
	 */
	protected function render_tabs( string $current_tab ): void {
		if ( empty( $this->tabs ) ) {
			return;
		}

		echo '<nav class="reports-tabs-nav">';

		foreach ( $this->tabs as $tab_key => $tab ) {
			$active_class = ( $tab_key === $current_tab ) ? ' reports-tab-active' : '';
			$url          = $this->get_tab_url( $tab_key );

			// No icon. Core draws none on .nav-tab, and a row of pictures
			// above a row of words is the same list twice.
			printf(
				'<a href="%s" class="reports-tab%s" data-tab="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_attr( $tab_key ),
				esc_html( $tab['label'] )
			);
		}

		echo '</nav>';
	}

	/**
	 * Check if the current page has multiple tabs.
	 *
	 * @return bool
	 */
	protected function has_tabs(): bool {
		return count( $this->tabs ) > 1;
	}

	/**
	 * Get all tabs.
	 *
	 * @return array
	 */
	public function get_tabs(): array {
		return $this->tabs;
	}

	/**
	 * Get a specific tab configuration.
	 *
	 * @param string $tab Tab key.
	 *
	 * @return array|null
	 */
	public function get_tab( string $tab ): ?array {
		return $this->tabs[ $tab ] ?? null;
	}
}
