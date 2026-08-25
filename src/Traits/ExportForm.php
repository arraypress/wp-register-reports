<?php
/**
 * Export Form Rendering
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

/**
 * The card each export gets, and the fields inside it.
 *
 * One renderer per kind of filter — a select, a date, a range, a checkbox —
 * because the alternative is one method with a switch in it that everything
 * has to be threaded through. Kept apart from the file writing, which has
 * nothing in common with it beyond the word export.
 */
trait ExportForm {

	/**
	 * Render exports section.
	 *
	 * @param array $exports Exports for current tab.
	 *
	 * @return void
	 */
	protected function render_exports_section( array $exports, int $columns = 0 ): void {
		$columns_class = $columns > 0 ? ' reports-exports-columns-' . $columns : '';

		?>
		<div class="reports-exports-section">
			<h3 class="reports-exports-section-title"><?php esc_html_e( 'Export', 'arraypress' ); ?></h3>
			<div class="reports-exports-grid<?php echo esc_attr( $columns_class ); ?>">
				<?php foreach ( $exports as $export_id => $export ) : ?>
					<?php $this->render_export_card( $export_id, $export ); ?>
				<?php endforeach; ?>
			</div>
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
		// Normalize icon - allow both 'dashicons-download' and 'download'
		$icon = $export['icon'] ?? 'download';
		if ( ! str_starts_with( $icon, 'dashicons-' ) ) {
			$icon = 'dashicons-' . $icon;
		}

		?>
		<div class="reports-export-card" data-export-id="<?php echo esc_attr( $export_id ); ?>">
			<h4 class="reports-export-title">
				<?php echo esc_html( $export['title'] ?? __( 'Export Data', 'arraypress' ) ); ?>
			</h4>

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
				<div class="reports-export-progress-info">
					<span class="reports-export-progress-label"><?php esc_html_e( 'Preparing...', 'arraypress' ); ?></span>
					<span class="reports-export-progress-percent">0%</span>
				</div>
			</div>

			<button type="button"
			        class="button button-primary reports-export-button"
			        data-export-id="<?php echo esc_attr( $export_id ); ?>"
			        data-report-id="<?php echo esc_attr( $this->id ); ?>">
				<?php echo esc_html( $export['button_text'] ?? __( 'Generate CSV', 'arraypress' ) ); ?>
			</button>
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
					placeholder="<?php esc_attr_e( 'Start Date', 'arraypress' ); ?>">
			<span class="reports-daterange-separator"><?php esc_html_e( 'to', 'arraypress' ); ?></span>
			<input type="date"
					id="<?php echo esc_attr( $field_id ); ?>_end"
					name="<?php echo esc_attr( $field_name ); ?>_end"
					value="<?php echo esc_attr( $default_end ); ?>"
					class="reports-filter-input"
					placeholder="<?php esc_attr_e( 'End Date', 'arraypress' ); ?>">
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
}
