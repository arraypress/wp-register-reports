<?php
/**
 * Progress And Breakdown Rendering
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

use ArrayPress\RegisterReports\Format;

/**
 * Two shapes that a chart is the wrong answer to.
 *
 * A **progress** component is one number against a target — takings against a
 * monthly goal, storage used against a quota. A chart of two values is not a
 * chart, and a number on its own leaves the reader doing the division.
 *
 * A **breakdown** is a ranked list with a bar behind each row: top products,
 * traffic by source, orders by status. This is the job a pie chart is usually
 * given and does badly — people read angles poorly, ranked order is what the
 * question was actually about, and a pie needs a legend that a narrow admin
 * column has no room for. A horizontal bar list is read top to bottom, sorted
 * already, and each row can carry its own number.
 *
 * Both are markup and CSS. Neither needs Chart.js, neither needs a canvas, and
 * both are legible to a screen reader without any extra work: a progress bar
 * carries its own ARIA role and values, and a breakdown is a list of labels
 * and numbers that happens to have bars drawn behind it.
 */
trait ProgressRenderer {

	/**
	 * What a component's callback has to say, on the first render.
	 *
	 * These draw with their data rather than empty-and-then-over-REST. A
	 * component that arrives blank and fills in a moment later is a page
	 * that flashes, and one where the fetch fails is a page that says
	 * nothing at all — while the server had the numbers the whole time.
	 * The refresh path updates them in place afterwards.
	 *
	 * @param array<string, mixed> $component The component's configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function component_data( array $component ): array {
		$callback = $component['data_callback'] ?? null;

		if ( ! $callback || ! is_callable( $callback ) ) {
			return [];
		}

		return (array) call_user_func( $callback, $this->date_range, $component );
	}

	/**
	 * One number against a target.
	 *
	 * @param string               $component_id The component.
	 * @param array<string, mixed> $component    Its configuration.
	 *
	 * @return void
	 */
	protected function render_progress( string $component_id, array $component ): void {
		$width  = $this->get_width_class( (string) ( $component['width'] ?? 'full' ) );
		$format = (string) ( $component['value_format'] ?? 'number' );
		$data   = $this->component_data( $component );

		?>
		<div class="reports-progress-wrapper <?php echo esc_attr( $width . ' ' . ( $component['class'] ?? '' ) ); ?>"
			data-component-id="<?php echo esc_attr( $component_id ); ?>"
			data-component-type="progress">
			<?php if ( ! empty( $component['title'] ) ) : ?>
				<h3 class="reports-progress-title"><?php echo esc_html( $component['title'] ); ?></h3>
			<?php endif; ?>

			<div class="reports-progress" data-value-format="<?php echo esc_attr( $format ); ?>">
				<?php $this->render_progress_bar( $data, $component ); ?>
			</div>

			<?php if ( ! empty( $component['description'] ) ) : ?>
				<p class="reports-progress-description"><?php echo esc_html( $component['description'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The bar itself, and the sentence under it.
	 *
	 * Rendered empty on load and filled in over REST, like every other
	 * component here — but the element is present either way, so the script
	 * has something to write into rather than markup to invent.
	 *
	 * @param array<string, mixed> $data      What the callback returned.
	 * @param array<string, mixed> $component Its configuration.
	 *
	 * @return void
	 */
	protected function render_progress_bar( array $data, array $component ): void {
		$value    = (float) ( $data['value'] ?? 0 );
		$target   = (float) ( $data['target'] ?? $component['target'] ?? 0 );
		$format   = (string) ( $component['value_format'] ?? 'number' );
		$currency = (string) ( $component['currency'] ?? 'USD' );

		// Nought to a hundred, whatever the numbers are. A target of nought
		// is a target nobody set, and dividing by it is the one arithmetic
		// mistake that takes the page with it.
		$percent = $target > 0 ? min( 100, max( 0, ( $value / $target ) * 100 ) ) : 0;

		printf(
			'<div class="reports-progress-track" role="progressbar" aria-valuenow="%s" aria-valuemin="0" aria-valuemax="100" aria-label="%s">' .
			'<div class="reports-progress-fill" style="width:%s%%"></div></div>',
			esc_attr( (string) round( $percent ) ),
			esc_attr( (string) ( $component['title'] ?? __( 'Progress', 'arraypress' ) ) ),
			esc_attr( (string) round( $percent, 2 ) )
		);

		printf(
			'<p class="reports-progress-meta"><span class="reports-progress-percent">%s</span>' .
			'<span class="reports-progress-figures">%s</span></p>',
			esc_html( number_format_i18n( $percent, $percent < 10 ? 1 : 0 ) . '%' ),
			esc_html(
				sprintf(
					/* translators: 1: the value reached, 2: the target */
					__( '%1$s of %2$s', 'arraypress' ),
					Format::value( $value, $format, $currency ),
					Format::value( $target, $format, $currency )
				)
			)
		);
	}

	/**
	 * A ranked list with a bar behind each row.
	 *
	 * @param string               $component_id The component.
	 * @param array<string, mixed> $component    Its configuration.
	 *
	 * @return void
	 */
	protected function render_breakdown( string $component_id, array $component ): void {
		$width = $this->get_width_class( (string) ( $component['width'] ?? 'full' ) );
		$rows  = (array) ( $this->component_data( $component )['rows'] ?? [] );

		?>
		<div class="reports-breakdown-wrapper <?php echo esc_attr( $width . ' ' . ( $component['class'] ?? '' ) ); ?>"
			data-component-id="<?php echo esc_attr( $component_id ); ?>"
			data-component-type="breakdown">
			<?php if ( ! empty( $component['title'] ) ) : ?>
				<h3 class="reports-breakdown-title"><?php echo esc_html( $component['title'] ); ?></h3>
			<?php endif; ?>

			<ul class="reports-breakdown"
				data-value-format="<?php echo esc_attr( (string) ( $component['value_format'] ?? 'number' ) ); ?>">
				<?php $this->render_breakdown_rows( $rows, $component ); ?>
			</ul>

			<p class="reports-breakdown-empty"<?php echo [] === $rows ? '' : ' hidden'; ?>>
				<?php echo esc_html( (string) ( $component['empty_message'] ?? __( 'No data available', 'arraypress' ) ) ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The rows of a breakdown.
	 *
	 * Each bar is drawn against the largest value rather than against the
	 * total, so the top row always fills the width. Against the total, a list
	 * of eight roughly equal things is eight identical stubs and the shape
	 * says nothing — which is the failure a breakdown exists to avoid.
	 *
	 * @param array<int, mixed>    $rows      Rows to draw.
	 * @param array<string, mixed> $component Its configuration.
	 *
	 * @return void
	 */
	protected function render_breakdown_rows( array $rows, array $component ): void {
		$rows = [] === $rows ? (array) ( $component['rows'] ?? [] ) : $rows;

		if ( [] === $rows ) {
			return;
		}

		$format   = (string) ( $component['value_format'] ?? 'number' );
		$currency = (string) ( $component['currency'] ?? 'USD' );
		$values   = array_map( static fn( $row ): float => (float) ( ( (array) $row )['value'] ?? 0 ), $rows );
		$largest  = max( $values ) ?: 1.0;
		$total    = array_sum( $values ) ?: 1.0;

		foreach ( $rows as $row ) {
			$row   = (array) $row;
			$value = (float) ( $row['value'] ?? 0 );

			printf(
				'<li class="reports-breakdown-row">' .
				'<span class="reports-breakdown-label">%s</span>' .
				'<span class="reports-breakdown-track"><span class="reports-breakdown-fill" style="width:%s%%"></span></span>' .
				'<span class="reports-breakdown-share">%s</span>' .
				'<span class="reports-breakdown-value">%s</span></li>',
				esc_html( (string) ( $row['label'] ?? '' ) ),
				esc_attr( (string) round( ( $value / $largest ) * 100, 2 ) ),
				esc_html( number_format_i18n( ( $value / $total ) * 100, 1 ) . '%' ),
				esc_html( Format::value( $value, $format, $currency ) )
			);
		}
	}

	/**
	 * Label and value rows in a panel.
	 *
	 * The place for the dozen secondary numbers that do not each deserve a
	 * tile of their own — average order value, refund rate, items per order.
	 * A row of fifteen tiles is not fifteen times as informative as one tile;
	 * it is a wall, and the number somebody actually wanted is in it
	 * somewhere.
	 *
	 * @param string               $component_id The component.
	 * @param array<string, mixed> $component    Its configuration.
	 *
	 * @return void
	 */
	protected function render_stat_list( string $component_id, array $component ): void {
		$width = $this->get_width_class( (string) ( $component['width'] ?? 'full' ) );
		$rows  = (array) ( $this->component_data( $component )['rows'] ?? [] );

		?>
		<div class="reports-stat-list-wrapper <?php echo esc_attr( $width . ' ' . ( $component['class'] ?? '' ) ); ?>"
			data-component-id="<?php echo esc_attr( $component_id ); ?>"
			data-component-type="stat_list">
			<?php if ( ! empty( $component['title'] ) ) : ?>
				<h3 class="reports-stat-list-title"><?php echo esc_html( $component['title'] ); ?></h3>
			<?php endif; ?>

			<dl class="reports-stat-list">
				<?php $this->render_stat_rows( $rows, $component ); ?>
			</dl>
		</div>
		<?php
	}

	/**
	 * The rows of a stat list.
	 *
	 * A definition list, because that is what it is: each row is a term and
	 * the thing it is defined as. A table would need a header row saying
	 * "Name" and "Value", which tells the reader nothing they did not know.
	 *
	 * @param array<int, mixed>    $rows      Rows to draw.
	 * @param array<string, mixed> $component Its configuration.
	 *
	 * @return void
	 */
	protected function render_stat_rows( array $rows, array $component ): void {
		$rows = [] === $rows ? (array) ( $component['rows'] ?? [] ) : $rows;

		$currency = (string) ( $component['currency'] ?? 'USD' );

		foreach ( $rows as $row ) {
			$row = (array) $row;

			printf(
				'<div class="reports-stat-row"><dt>%s</dt><dd>%s</dd></div>',
				esc_html( (string) ( $row['label'] ?? '' ) ),
				esc_html(
					Format::value(
						$row['value'] ?? '',
						(string) ( $row['format'] ?? $component['value_format'] ?? 'number' ),
						$currency
					)
				)
			);
		}
	}
}
