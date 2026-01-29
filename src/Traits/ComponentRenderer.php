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
		 * @param bool   $rendered    Whether the component was rendered.
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

		$value         = $data['value'] ?? 0;
		$compare_value = $data['compare_value'] ?? null;
		$change        = $data['change'] ?? null;
		$label         = $data['label'] ?? $component['title'];

		$width_class = $this->get_width_class( $component['width'] ?? 'auto' );
		$color_class = ! empty( $component['color'] ) ? 'reports-tile--' . $component['color'] : '';

		?>
		<div class="reports-tile <?php echo esc_attr( $width_class . ' ' . $color_class . ' ' . ( $component['class'] ?? '' ) ); ?>"
		     data-component-id="<?php echo esc_attr( $component_id ); ?>"
		     data-ajax-refresh="<?php echo $component['ajax_refresh'] ? 'true' : 'false'; ?>">

			<div class="reports-tile-header">
				<?php if ( ! empty( $component['icon'] ) ) : ?>
					<span class="reports-tile-icon dashicons <?php echo esc_attr( $component['icon'] ); ?>"></span>
				<?php endif; ?>
				<h3 class="reports-tile-title"><?php echo esc_html( $label ); ?></h3>
			</div>

			<div class="reports-tile-content">
				<div class="reports-tile-value">
					<?php echo esc_html( $this->format_value( $value, $component['value_format'] ?? 'number' ) ); ?>
				</div>

				<?php if ( $compare_value !== null && $component['compare'] ) : ?>
					<?php
					$trend_class = 'neutral';
					$trend_icon  = 'minus';

					if ( $change !== null && $change != 0 ) {
						$is_positive = $change > 0;
						$is_good     = ( $component['trend_direction'] ?? 'up_good' ) === 'up_good' ? $is_positive : ! $is_positive;
						$trend_class = $is_good ? 'positive' : 'negative';
						$trend_icon  = $is_positive ? 'arrow-up-alt' : 'arrow-down-alt';
					}
					?>
					<div class="reports-tile-compare reports-tile-compare--<?php echo esc_attr( $trend_class ); ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $trend_icon ); ?>"></span>
						<span class="reports-tile-change">
							<?php echo esc_html( $this->format_change( $change ) ); ?>
						</span>
						<?php if ( ! empty( $component['compare_label'] ) ) : ?>
							<span class="reports-tile-compare-label">
								<?php echo esc_html( $component['compare_label'] ); ?>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $component['description'] ) ) : ?>
					<p class="reports-tile-description"><?php echo esc_html( $component['description'] ); ?></p>
				<?php endif; ?>
			</div>

			<div class="reports-tile-loading" style="display: none;">
				<span class="spinner is-active"></span>
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
		<div class="reports-tiles-group reports-tiles-group--cols-<?php echo esc_attr( $columns ); ?>"
		     data-component-id="<?php echo esc_attr( $component_id ); ?>">

			<?php if ( ! empty( $component['title'] ) ) : ?>
				<h3 class="reports-tiles-group-title"><?php echo esc_html( $component['title'] ); ?></h3>
			<?php endif; ?>

			<div class="reports-tiles-grid">
				<?php foreach ( $tiles as $tile_id => $tile ) :
					$tile = wp_parse_args( $tile, [
						'type'          => 'tile',
						'icon'          => 'dashicons-chart-bar',
						'value_format'  => 'number',
						'compare'       => false,
						'ajax_refresh'  => true,
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
				<div class="reports-chart-header">
					<h3 class="reports-chart-title"><?php echo esc_html( $component['title'] ); ?></h3>
					<?php if ( ! empty( $component['description'] ) ) : ?>
						<p class="reports-chart-description"><?php echo esc_html( $component['description'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="reports-chart-container" style="height: <?php echo esc_attr( $height ); ?>px;">
				<canvas id="chart-<?php echo esc_attr( $component_id ); ?>"
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
				'legend' => [
					'display'  => $component['show_legend'] ?? true,
					'position' => $component['legend_position'] ?? 'top',
				],
				'tooltip' => [
					'enabled' => true,
					'mode'    => 'index',
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
					'display'    => true,
					'beginAtZero' => true,
					'title'      => [
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

		$width_class = $this->get_width_class( $component['width'] ?? 'full' );

		?>
		<div class="reports-table-wrapper <?php echo esc_attr( $width_class . ' ' . ( $component['class'] ?? '' ) ); ?>"
		     data-component-id="<?php echo esc_attr( $component_id ); ?>"
		     data-ajax-refresh="<?php echo $component['ajax_refresh'] ? 'true' : 'false'; ?>">

			<?php if ( ! empty( $component['title'] ) ) : ?>
				<div class="reports-table-header">
					<h3 class="reports-table-title"><?php echo esc_html( $component['title'] ); ?></h3>
					<?php if ( ! empty( $component['description'] ) ) : ?>
						<p class="reports-table-description"><?php echo esc_html( $component['description'] ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $component['searchable'] ) ) : ?>
						<div class="reports-table-search">
							<input type="search" placeholder="<?php esc_attr_e( 'Search...', 'reports' ); ?>"
							       class="reports-table-search-input"/>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( empty( $rows ) ) : ?>
				<div class="reports-table-empty">
					<p><?php echo esc_html( $empty_message ); ?></p>
				</div>
			<?php else : ?>
				<table class="reports-table widefat striped">
					<thead>
					<tr>
						<?php foreach ( $columns as $key => $column ) :
							$column_label = is_array( $column ) ? ( $column['label'] ?? $key ) : $column;
							$sortable     = is_array( $column ) && ( $column['sortable'] ?? $component['sortable'] ?? true );
							?>
							<th class="<?php echo $sortable ? 'sortable' : ''; ?>" data-column="<?php echo esc_attr( $key ); ?>">
								<?php echo esc_html( $column_label ); ?>
								<?php if ( $sortable ) : ?>
									<span class="dashicons dashicons-sort"></span>
								<?php endif; ?>
							</th>
						<?php endforeach; ?>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<?php foreach ( $columns as $key => $column ) :
								$column_key = is_string( $key ) ? $key : $column;
								$cell_value = $row[ $column_key ] ?? '';
								$format     = is_array( $column ) ? ( $column['format'] ?? '' ) : '';

								if ( $format ) {
									$cell_value = $this->format_value( $cell_value, $format );
								}
								?>
								<td data-column="<?php echo esc_attr( $column_key ); ?>">
									<?php echo wp_kses_post( $cell_value ); ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( ! empty( $component['paginated'] ) && count( $rows ) > ( $component['per_page'] ?? 10 ) ) : ?>
					<div class="reports-table-pagination">
						<!-- Pagination will be handled by JS -->
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="reports-table-loading" style="display: none;">
				<span class="spinner is-active"></span>
			</div>
		</div>
		<?php
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
			'full'    => 'reports-component--full',
			'half'    => 'reports-component--half',
			'third'   => 'reports-component--third',
			'quarter' => 'reports-component--quarter',
			'two-thirds' => 'reports-component--two-thirds',
			default   => 'reports-component--auto',
		};
	}

	/**
	 * Format a value based on type.
	 *
	 * Uses wp-currencies library for currency formatting if available.
	 * Uses wp-date-utils library for date formatting.
	 *
	 * @param mixed  $value    The value to format.
	 * @param string $format   The format type.
	 * @param string $currency Currency code for currency formatting.
	 *
	 * @return string
	 */
	protected function format_value( $value, string $format, string $currency = 'USD' ): string {
		switch ( $format ) {
			case 'currency':
				// Use wp-currencies library (amounts should be in cents)
				$cents = is_float( $value ) ? (int) ( $value * 100 ) : (int) $value;

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
				return Dates::format( $value, 'datetime' );

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
