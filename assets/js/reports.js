/**
 * Reports Admin JavaScript
 *
 * Handles date picker, AJAX refreshes, Chart.js initialization,
 * table sorting/filtering, and export functionality.
 *
 * @package ArrayPress\RegisterReports
 */

(function($) {
	'use strict';

	/**
	 * Reports Admin Controller
	 */
	const ReportsController = {
		
		/**
		 * Chart instances storage
		 */
		charts: {},

		/**
		 * Initialize
		 */
		init: function() {
			this.bindEvents();
			this.initDatePicker();
			this.initCharts();
			this.initTables();
			this.initExports();
		},

		/**
		 * Bind DOM events
		 */
		bindEvents: function() {
			// Date picker preset change
			$(document).on('change', '.reports-date-preset', this.onPresetChange.bind(this));
			
			// Custom date inputs
			$(document).on('change', '.reports-date-start, .reports-date-end', this.onCustomDateChange.bind(this));
			
			// Apply date button
			$(document).on('click', '.reports-apply-date', this.onApplyDate.bind(this));
			
			// Component refresh buttons
			$(document).on('click', '.reports-refresh-component', this.onRefreshComponent.bind(this));
			
			// Table sorting
			$(document).on('click', '.reports-table th.sortable', this.onTableSort.bind(this));
			
			// Table search
			$(document).on('input', '.reports-table-search input', this.onTableSearch.bind(this));
			
			// Table pagination
			$(document).on('click', '.reports-table-pages button', this.onTablePage.bind(this));
			
			// Export button (with filters)
			$(document).on('click', '.reports-export-button', this.onExportClick.bind(this));
		},

		/**
		 * Initialize date picker
		 */
		initDatePicker: function() {
			const $picker = $('.reports-date-picker');
			if (!$picker.length) return;

			const preset = $picker.find('.reports-date-preset').val();
			this.toggleCustomDates(preset === 'custom');
		},

		/**
		 * Handle preset change
		 */
		onPresetChange: function(e) {
			const preset = $(e.target).val();
			this.toggleCustomDates(preset === 'custom');

			// Auto-apply if not custom
			if (preset !== 'custom') {
				this.applyDateRange(preset);
			}
		},

		/**
		 * Toggle custom date inputs visibility
		 */
		toggleCustomDates: function(show) {
			const $customDates = $('.reports-custom-dates');
			const $applyButton = $('.reports-apply-date');

			if (show) {
				$customDates.addClass('active');
				$applyButton.show();
			} else {
				$customDates.removeClass('active');
				$applyButton.hide();
			}
		},

		/**
		 * Handle custom date change
		 */
		onCustomDateChange: function(e) {
			// Could add validation here
		},

		/**
		 * Handle apply date button click
		 */
		onApplyDate: function(e) {
			e.preventDefault();
			
			const $picker = $('.reports-date-picker');
			const preset = $picker.find('.reports-date-preset').val();
			const startDate = $picker.find('.reports-date-start').val();
			const endDate = $picker.find('.reports-date-end').val();

			if (preset === 'custom' && startDate && endDate) {
				this.applyDateRange('custom', startDate, endDate);
			}
		},

		/**
		 * Apply date range - redirect with new params
		 */
		applyDateRange: function(preset, startDate, endDate) {
			const url = new URL(window.location.href);
			
			url.searchParams.set('date_preset', preset);
			
			if (preset === 'custom' && startDate && endDate) {
				url.searchParams.set('date_start', startDate);
				url.searchParams.set('date_end', endDate);
			} else {
				url.searchParams.delete('date_start');
				url.searchParams.delete('date_end');
			}

			window.location.href = url.toString();
		},

		/**
		 * Initialize all charts on page
		 */
		initCharts: function() {
			const self = this;
			
			$('.reports-chart-canvas').each(function() {
				const $canvas = $(this);
				const chartId = $canvas.data('chart-id');
				const chartConfig = $canvas.data('chart-config');

				if (chartConfig) {
					self.createChart($canvas[0], chartId, chartConfig);
				}
			});
		},

		/**
		 * Create a Chart.js instance
		 */
		createChart: function(canvas, chartId, config) {
			// Destroy existing chart if present
			if (this.charts[chartId]) {
				this.charts[chartId].destroy();
			}

			const ctx = canvas.getContext('2d');
			
			// Merge with default options
			const chartOptions = this.getChartOptions(config.type, config.options || {});
			
			// Apply default colors if not specified
			if (config.data && config.data.datasets) {
				config.data.datasets = config.data.datasets.map((dataset, index) => {
					if (!dataset.backgroundColor) {
						const colors = ReportsAdmin.chartDefaults.colors;
						const color = colors[index % colors.length];
						
						if (config.type === 'line' || config.type === 'area') {
							dataset.borderColor = color;
							dataset.backgroundColor = this.hexToRgba(color, 0.1);
						} else {
							dataset.backgroundColor = colors;
						}
					}
					return dataset;
				});
			}

			this.charts[chartId] = new Chart(ctx, {
				type: config.type === 'area' ? 'line' : config.type,
				data: config.data,
				options: chartOptions
			});
		},

		/**
		 * Get chart options based on type
		 */
		getChartOptions: function(type, customOptions) {
			const baseOptions = {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: customOptions.legend !== false,
						position: customOptions.legendPosition || 'top'
					},
					tooltip: {
						mode: 'index',
						intersect: false
					}
				}
			};

			// Type-specific options
			if (type === 'line' || type === 'area') {
				baseOptions.interaction = {
					mode: 'nearest',
					axis: 'x',
					intersect: false
				};
				baseOptions.scales = {
					x: {
						display: true,
						title: {
							display: !!customOptions.xAxisLabel,
							text: customOptions.xAxisLabel || ''
						}
					},
					y: {
						display: true,
						title: {
							display: !!customOptions.yAxisLabel,
							text: customOptions.yAxisLabel || ''
						},
						beginAtZero: customOptions.beginAtZero !== false
					}
				};

				// Area chart fill
				if (type === 'area') {
					baseOptions.elements = {
						line: {
							fill: true
						}
					};
				}
			}

			if (type === 'bar') {
				baseOptions.scales = {
					x: {
						display: true,
						title: {
							display: !!customOptions.xAxisLabel,
							text: customOptions.xAxisLabel || ''
						},
						stacked: customOptions.stacked || false
					},
					y: {
						display: true,
						title: {
							display: !!customOptions.yAxisLabel,
							text: customOptions.yAxisLabel || ''
						},
						beginAtZero: true,
						stacked: customOptions.stacked || false
					}
				};
			}

			if (type === 'pie' || type === 'doughnut') {
				baseOptions.plugins.legend.position = customOptions.legendPosition || 'right';
			}

			return $.extend(true, baseOptions, customOptions);
		},

		/**
		 * Convert hex color to rgba
		 */
		hexToRgba: function(hex, alpha) {
			const r = parseInt(hex.slice(1, 3), 16);
			const g = parseInt(hex.slice(3, 5), 16);
			const b = parseInt(hex.slice(5, 7), 16);
			return `rgba(${r}, ${g}, ${b}, ${alpha})`;
		},

		/**
		 * Refresh a component via AJAX
		 */
		onRefreshComponent: function(e) {
			e.preventDefault();
			
			const $button = $(e.currentTarget);
			const $component = $button.closest('.reports-component');
			const componentId = $component.data('component-id');

			this.refreshComponent(componentId, $component);
		},

		/**
		 * Refresh component via REST API
		 */
		refreshComponent: function(componentId, $component) {
			const $body = $component.find('.reports-component-body');
			
			$body.addClass('reports-loading');
			$body.html('<span class="spinner is-active"></span> ' + ReportsAdmin.i18n.loading);

			$.ajax({
				url: ReportsAdmin.restUrl + 'component',
				method: 'GET',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', ReportsAdmin.restNonce);
				},
				data: {
					report_id: ReportsAdmin.reportId,
					component_id: componentId,
					date_preset: this.getCurrentDatePreset(),
					date_start: this.getCurrentDateStart(),
					date_end: this.getCurrentDateEnd()
				},
				success: function(response) {
					$body.removeClass('reports-loading');
					
					if (response.html) {
						$body.html(response.html);
					}
					
					// Reinitialize charts if needed
					if (response.type === 'chart') {
						ReportsController.initCharts();
					}
				},
				error: function() {
					$body.removeClass('reports-loading');
					$body.html('<p class="error">' + ReportsAdmin.i18n.error + '</p>');
				}
			});
		},

		/**
		 * Get current date preset
		 */
		getCurrentDatePreset: function() {
			return $('.reports-date-preset').val() || 'this_month';
		},

		/**
		 * Get current date start
		 */
		getCurrentDateStart: function() {
			return $('.reports-date-start').val() || '';
		},

		/**
		 * Get current date end
		 */
		getCurrentDateEnd: function() {
			return $('.reports-date-end').val() || '';
		},

		/**
		 * Initialize tables
		 */
		initTables: function() {
			$('.reports-table-container').each(function() {
				const $container = $(this);
				const $table = $container.find('.reports-table');
				
				if ($table.data('sortable')) {
					ReportsController.initTableSort($table);
				}
				
				if ($table.data('paginated')) {
					ReportsController.initTablePagination($container);
				}
			});
		},

		/**
		 * Initialize table sorting
		 */
		initTableSort: function($table) {
			$table.find('th.sortable').each(function() {
				const $th = $(this);
				if (!$th.find('.sort-indicator').length) {
					$th.append('<span class="sort-indicator dashicons dashicons-sort"></span>');
				}
			});
		},

		/**
		 * Handle table sort click
		 */
		onTableSort: function(e) {
			const $th = $(e.currentTarget);
			const $table = $th.closest('.reports-table');
			const columnIndex = $th.index();
			const currentDirection = $th.data('sort-direction') || 'none';
			
			// Cycle: none -> asc -> desc -> none
			let newDirection;
			if (currentDirection === 'none' || currentDirection === 'desc') {
				newDirection = 'asc';
			} else {
				newDirection = 'desc';
			}

			// Reset other columns
			$table.find('th').removeClass('sorted').data('sort-direction', 'none');
			$th.addClass('sorted').data('sort-direction', newDirection);
			
			// Update indicator
			const $indicator = $th.find('.sort-indicator');
			$indicator.removeClass('dashicons-sort dashicons-arrow-up dashicons-arrow-down');
			$indicator.addClass(newDirection === 'asc' ? 'dashicons-arrow-up' : 'dashicons-arrow-down');

			// Sort rows
			this.sortTable($table, columnIndex, newDirection);
		},

		/**
		 * Sort table rows
		 */
		sortTable: function($table, columnIndex, direction) {
			const $tbody = $table.find('tbody');
			const rows = $tbody.find('tr').toArray();

			rows.sort(function(a, b) {
				const aVal = $(a).find('td').eq(columnIndex).text().trim();
				const bVal = $(b).find('td').eq(columnIndex).text().trim();

				// Try numeric sort first
				const aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
				const bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));

				if (!isNaN(aNum) && !isNaN(bNum)) {
					return direction === 'asc' ? aNum - bNum : bNum - aNum;
				}

				// Fall back to string sort
				return direction === 'asc' 
					? aVal.localeCompare(bVal) 
					: bVal.localeCompare(aVal);
			});

			$tbody.empty().append(rows);
		},

		/**
		 * Handle table search
		 */
		onTableSearch: function(e) {
			const $input = $(e.target);
			const $container = $input.closest('.reports-table-container');
			const $table = $container.find('.reports-table');
			const query = $input.val().toLowerCase();

			$table.find('tbody tr').each(function() {
				const $row = $(this);
				const text = $row.text().toLowerCase();
				$row.toggle(text.includes(query));
			});

			// Update pagination info
			this.updatePaginationInfo($container);
		},

		/**
		 * Initialize table pagination
		 */
		initTablePagination: function($container) {
			const $table = $container.find('.reports-table');
			const perPage = $table.data('per-page') || 10;
			
			$container.data('current-page', 1);
			$container.data('per-page', perPage);
			
			this.paginateTable($container);
		},

		/**
		 * Handle pagination click
		 */
		onTablePage: function(e) {
			const $button = $(e.currentTarget);
			const $container = $button.closest('.reports-table-container');
			const page = $button.data('page');
			
			$container.data('current-page', page);
			this.paginateTable($container);
		},

		/**
		 * Paginate table
		 */
		paginateTable: function($container) {
			const $table = $container.find('.reports-table');
			const $rows = $table.find('tbody tr:not(.hidden-by-search)');
			const currentPage = $container.data('current-page') || 1;
			const perPage = $container.data('per-page') || 10;
			const totalRows = $rows.length;
			const totalPages = Math.ceil(totalRows / perPage);
			const start = (currentPage - 1) * perPage;
			const end = start + perPage;

			// Show/hide rows
			$rows.hide().slice(start, end).show();

			// Update pagination controls
			this.renderPaginationControls($container, currentPage, totalPages, totalRows);
		},

		/**
		 * Render pagination controls
		 */
		renderPaginationControls: function($container, currentPage, totalPages, totalRows) {
			const $pagination = $container.find('.reports-table-pagination');
			const perPage = $container.data('per-page');
			const start = ((currentPage - 1) * perPage) + 1;
			const end = Math.min(currentPage * perPage, totalRows);

			// Update info text
			$pagination.find('.reports-table-info').text(
				`Showing ${start}-${end} of ${totalRows}`
			);

			// Build page buttons
			let pagesHtml = '';
			
			// Previous button
			pagesHtml += `<button class="button" data-page="${currentPage - 1}" ${currentPage <= 1 ? 'disabled' : ''}>
				<span class="dashicons dashicons-arrow-left-alt2"></span>
			</button>`;

			// Page numbers (simplified)
			for (let i = 1; i <= totalPages; i++) {
				if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
					pagesHtml += `<button class="button ${i === currentPage ? 'button-primary' : ''}" data-page="${i}">${i}</button>`;
				} else if (i === currentPage - 2 || i === currentPage + 2) {
					pagesHtml += '<span class="ellipsis">...</span>';
				}
			}

			// Next button
			pagesHtml += `<button class="button" data-page="${currentPage + 1}" ${currentPage >= totalPages ? 'disabled' : ''}>
				<span class="dashicons dashicons-arrow-right-alt2"></span>
			</button>`;

			$pagination.find('.reports-table-pages').html(pagesHtml);
		},

		/**
		 * Update pagination info after search
		 */
		updatePaginationInfo: function($container) {
			const $table = $container.find('.reports-table');
			const visibleRows = $table.find('tbody tr:visible').length;
			const totalRows = $table.find('tbody tr').length;

			$container.find('.reports-table-info').text(
				`Showing ${visibleRows} of ${totalRows}`
			);
		},

		/**
		 * Initialize exports
		 */
		initExports: function() {
			// Any export-specific initialization
		},

		/**
		 * Handle export button click - starts batched export
		 */
		onExportClick: function(e) {
			e.preventDefault();

			const $button = $(e.currentTarget);
			const $card = $button.closest('.reports-export-card');
			const exportId = $button.data('export-id');
			const reportId = $button.data('report-id');

			// Prevent double-clicks
			if ($button.prop('disabled')) {
				return;
			}

			// Gather filters from the card
			const filters = this.gatherExportFilters($card);

			// Start the export
			this.startExport(reportId, exportId, filters, $card, $button);
		},

		/**
		 * Gather filter values from export card
		 */
		gatherExportFilters: function($card) {
			const filters = {};

			$card.find('.reports-filter-input').each(function() {
				const $field = $(this);
				const name = $field.attr('name');

				if (!name) return;

				// Remove 'filter_' prefix for cleaner filter names
				const filterKey = name.replace(/^filter_/, '').replace(/\[\]$/, '');

				if ($field.is(':checkbox')) {
					if (!filters[filterKey]) {
						filters[filterKey] = [];
					}
					if ($field.is(':checked')) {
						filters[filterKey].push($field.val());
					}
				} else if ($field.is('select[multiple]')) {
					filters[filterKey] = $field.val() || [];
				} else {
					const val = $field.val();
					if (val) {
						filters[filterKey] = val;
					}
				}
			});

			return filters;
		},

		/**
		 * Start batched export process
		 */
		startExport: function(reportId, exportId, filters, $card, $button) {
			const self = this;
			const $progress = $card.find('.reports-export-progress');
			const $progressFill = $card.find('.reports-export-progress-fill');
			const $progressLabel = $card.find('.reports-export-progress-label');
			const $progressPercent = $card.find('.reports-export-progress-percent');

			// Disable button and show progress
			$button.prop('disabled', true);
			$button.find('.button-text').text(ReportsAdmin.i18n.exporting || 'Exporting...');
			$progress.show();
			$progressFill.css('width', '0%');
			$progressLabel.text(ReportsAdmin.i18n.preparing || 'Preparing export...');
			$progressPercent.text('0%');

			// Build request data
			const requestData = {
				report_id: reportId,
				export_id: exportId,
				filters: filters,
				date_preset: this.getCurrentDatePreset(),
			};

			const dateStart = this.getCurrentDateStart();
			const dateEnd = this.getCurrentDateEnd();

			if (dateStart) requestData.date_start = dateStart;
			if (dateEnd) requestData.date_end = dateEnd;

			// Start export via REST API
			$.ajax({
				url: ReportsAdmin.restUrl + 'export/start',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', ReportsAdmin.restNonce);
				},
				contentType: 'application/json',
				data: JSON.stringify(requestData),
				success: function(response) {
					if (response.success) {
						// Start processing batches
						self.processExportBatches(
							response.export_token,
							response.total_items,
							response.batch_size,
							0,
							$card,
							$button
						);
					} else {
						self.handleExportError(response.message || 'Export failed', $card, $button);
					}
				},
				error: function(xhr) {
					const message = xhr.responseJSON?.message || 'Export failed';
					self.handleExportError(message, $card, $button);
				}
			});
		},

		/**
		 * Process export batches recursively
		 */
		processExportBatches: function(exportToken, totalItems, batchSize, currentBatch, $card, $button) {
			const self = this;
			const $progressFill = $card.find('.reports-export-progress-fill');
			const $progressLabel = $card.find('.reports-export-progress-label');
			const $progressPercent = $card.find('.reports-export-progress-percent');

			$.ajax({
				url: ReportsAdmin.restUrl + 'export/batch',
				method: 'POST',
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', ReportsAdmin.restNonce);
				},
				contentType: 'application/json',
				data: JSON.stringify({
					export_token: exportToken,
					batch: currentBatch
				}),
				success: function(response) {
					if (!response.success) {
						self.handleExportError(response.message || 'Batch failed', $card, $button);
						return;
					}

					// Update progress
					const percent = Math.round((response.processed_items / response.total_items) * 100);
					$progressFill.css('width', percent + '%');
					$progressPercent.text(percent + '%');
					$progressLabel.text(
						(ReportsAdmin.i18n.processing || 'Processing') + ' ' +
						response.processed_items + ' / ' + response.total_items
					);

					if (response.is_complete) {
						// Export complete - trigger download
						$progressLabel.text(ReportsAdmin.i18n.complete || 'Export complete!');
						$progressFill.css('width', '100%');
						$progressPercent.text('100%');

						// Brief delay then download
						setTimeout(function() {
							window.location.href = response.download_url;
							self.resetExportUI($card, $button);
						}, 500);
					} else {
						// Process next batch
						self.processExportBatches(
							exportToken,
							totalItems,
							batchSize,
							currentBatch + 1,
							$card,
							$button
						);
					}
				},
				error: function(xhr) {
					const message = xhr.responseJSON?.message || 'Batch processing failed';
					self.handleExportError(message, $card, $button);
				}
			});
		},

		/**
		 * Handle export error
		 */
		handleExportError: function(message, $card, $button) {
			const $progressLabel = $card.find('.reports-export-progress-label');

			$progressLabel.text(ReportsAdmin.i18n.error + ': ' + message).addClass('error');

			// Reset after delay
			setTimeout(function() {
				this.resetExportUI($card, $button);
			}.bind(this), 3000);
		},

		/**
		 * Reset export UI to initial state
		 */
		resetExportUI: function($card, $button) {
			const $progress = $card.find('.reports-export-progress');
			const $progressLabel = $card.find('.reports-export-progress-label');

			$button.prop('disabled', false);
			$button.find('.button-text').text(ReportsAdmin.i18n.download || 'Download CSV');
			$progress.hide();
			$progressLabel.removeClass('error');
		}
	};

	// Initialize on DOM ready
	$(function() {
		ReportsController.init();
	});

	// Expose for external use
	window.ReportsController = ReportsController;

})(jQuery);
