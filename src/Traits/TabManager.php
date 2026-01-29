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
		$args = [
			'page' => $this->config['menu_slug'],
			'tab'  => $tab,
		];

		// Preserve date range parameters
		if ( ! empty( $_GET['date_preset'] ) ) {
			$args['date_preset'] = sanitize_key( $_GET['date_preset'] );
		}
		if ( ! empty( $_GET['date_start'] ) ) {
			$args['date_start'] = sanitize_text_field( $_GET['date_start'] );
		}
		if ( ! empty( $_GET['date_end'] ) ) {
			$args['date_end'] = sanitize_text_field( $_GET['date_end'] );
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
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

			printf(
				'<a href="%s" class="reports-tab%s" data-tab="%s">%s%s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_attr( $tab_key ),
				! empty( $tab['icon'] ) ? '<span class="dashicons ' . esc_attr( $tab['icon'] ) . '"></span> ' : '',
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
