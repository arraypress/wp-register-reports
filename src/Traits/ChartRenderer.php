<?php
/**
 * Chart Rendering
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

/**
 * The canvas a chart is drawn into, and the options it is drawn with.
 *
 * Only the container is rendered here — the data arrives over REST and the
 * drawing happens in the browser. The options are built server-side anyway so
 * that currency, dates and number formatting come from the site's settings
 * rather than from a guess made in JavaScript.
 */
trait ChartRenderer {

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
}
