<?php
/**
 * Config Parser Trait
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

/**
 * Trait ConfigParser
 *
 * Handles parsing and normalizing the configuration array.
 */
trait ConfigParser {

	/**
	 * Parse the configuration array.
	 *
	 * @return void
	 */
	protected function parse_config(): void {
		$this->parse_tabs();
		$this->parse_components();
		$this->parse_exports();
	}

	/**
	 * Parse tabs configuration.
	 *
	 * @return void
	 */
	protected function parse_tabs(): void {
		if ( empty( $this->config['tabs'] ) ) {
			// Create a default tab if components exist but no tabs defined
			if ( ! empty( $this->config['components'] ) ) {
				$this->tabs['default'] = [
					'label' => __( 'Overview', 'reports' ),
					'icon'  => 'dashicons-chart-bar',
				];
			}

			return;
		}

		foreach ( $this->config['tabs'] as $key => $tab ) {
			// Handle simple string format: 'overview' => 'Overview'
			if ( is_string( $tab ) ) {
				$this->tabs[ $key ] = [
					'label' => $tab,
					'icon'  => '',
				];
			} else {
				// Full array format
				$this->tabs[ $key ] = wp_parse_args( $tab, [
					'label'           => ucfirst( $key ),
					'icon'            => '',
					'render_callback' => null,
				] );
			}
		}
	}

	/**
	 * Parse components configuration.
	 *
	 * @return void
	 */
	protected function parse_components(): void {
		if ( empty( $this->config['components'] ) ) {
			$this->components = [];

			return;
		}

		$first_tab = ! empty( $this->tabs ) ? array_key_first( $this->tabs ) : 'default';

		foreach ( $this->config['components'] as $key => $component ) {
			$component = $this->normalize_component( $key, $component, $first_tab );
			$tab       = $component['tab'];

			if ( ! isset( $this->components[ $tab ] ) ) {
				$this->components[ $tab ] = [];
			}

			$this->components[ $tab ][ $key ] = $component;
		}
	}

	/**
	 * Normalize a single component configuration.
	 *
	 * @param string $key       Component key.
	 * @param array  $component Component configuration.
	 * @param string $first_tab First tab key for default.
	 *
	 * @return array
	 */
	protected function normalize_component( string $key, array $component, string $first_tab ): array {
		$defaults = [
			'type'          => 'tile',
			'title'         => ucfirst( str_replace( [ '_', '-' ], ' ', $key ) ),
			'description'   => '',
			'tab'           => $first_tab,
			'order'         => 10,
			'width'         => 'auto',      // auto, full, half, third, quarter
			'data_callback' => null,
			'class'         => '',
			'ajax_refresh'  => true,
		];

		$component = wp_parse_args( $component, $defaults );

		// Type-specific defaults
		$component = $this->apply_component_type_defaults( $component );

		return $component;
	}

	/**
	 * Apply type-specific default values to components.
	 *
	 * @param array $component Component configuration.
	 *
	 * @return array
	 */
	protected function apply_component_type_defaults( array $component ): array {
		switch ( $component['type'] ) {
			case 'tile':
				$component = wp_parse_args( $component, [
					'icon'            => 'dashicons-chart-bar',
					'color'           => '',
					'value_format'    => 'number',  // number, currency, percentage
					'compare'         => false,     // Show comparison with previous period
					'compare_label'   => '',
					'trend_direction' => 'up_good', // up_good, up_bad
				] );
				break;

			case 'chart':
				$component = wp_parse_args( $component, [
					'chart_type'      => 'line',  // line, bar, pie, doughnut, area
					'height'          => 300,
					'show_legend'     => true,
					'legend_position' => 'top',
					'colors'          => [],
					'stacked'         => false,
					'fill'            => false,
					'tension'         => 0.4,
					'x_axis_label'    => '',
					'y_axis_label'    => '',
					'tooltip_format'  => '',
				] );
				break;

			case 'table':
				$component = wp_parse_args( $component, [
					'columns'       => [],
					'sortable'      => true,
					'searchable'    => false,
					'paginated'     => true,
					'per_page'      => 10,
					'empty_message' => __( 'No data available.', 'reports' ),
					'row_actions'   => [],
				] );
				break;

			case 'html':
				$component = wp_parse_args( $component, [
					'content'         => '',
					'render_callback' => null,
				] );
				break;

			case 'tiles_group':
				$component = wp_parse_args( $component, [
					'tiles'   => [],
					'columns' => 4,
				] );
				break;
		}

		return $component;
	}

	/**
	 * Parse exports configuration.
	 *
	 * @return void
	 */
	protected function parse_exports(): void {
		if ( empty( $this->config['exports'] ) ) {
			$this->exports = [];

			return;
		}

		$first_tab = ! empty( $this->tabs ) ? array_key_first( $this->tabs ) : 'default';

		foreach ( $this->config['exports'] as $key => $export ) {
			$export = $this->normalize_export( $key, $export, $first_tab );
			$tab    = $export['tab'];

			if ( ! isset( $this->exports[ $tab ] ) ) {
				$this->exports[ $tab ] = [];
			}

			$this->exports[ $tab ][ $key ] = $export;
		}
	}

	/**
	 * Normalize a single export configuration.
	 *
	 * @param string $key       Export key.
	 * @param array  $export    Export configuration.
	 * @param string $first_tab First tab key for default.
	 *
	 * @return array
	 */
	protected function normalize_export( string $key, array $export, string $first_tab ): array {
		$defaults = [
			'title'         => ucfirst( str_replace( [ '_', '-' ], ' ', $key ) ),
			'description'   => '',
			'tab'           => $first_tab,
			'filename'      => $key,
			'data_callback' => null,
			'columns'       => [],
			'filters'       => [],
			'icon'          => 'dashicons-download',
			'button_text'   => __( 'Export CSV', 'reports' ),
		];

		return wp_parse_args( $export, $defaults );
	}

	/**
	 * Get components for a specific tab.
	 *
	 * @param string $tab Tab key.
	 *
	 * @return array
	 */
	protected function get_components_for_tab( string $tab ): array {
		$components = $this->components[ $tab ] ?? [];

		// Sort by order
		uasort( $components, function ( $a, $b ) {
			return ( $a['order'] ?? 10 ) <=> ( $b['order'] ?? 10 );
		} );

		return $components;
	}

	/**
	 * Get exports for a specific tab.
	 *
	 * @param string $tab Tab key.
	 *
	 * @return array
	 */
	protected function get_exports_for_tab( string $tab ): array {
		return $this->exports[ $tab ] ?? [];
	}

}
