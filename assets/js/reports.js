/**
 * Reports Admin JavaScript
 *
 * @package ArrayPress\RegisterReports
 */

(function($) {
	'use strict';

	const ReportsController = {

		charts: {},
		refreshTimer: null,
		lastUpdated: null,
		lastUpdatedTimer: null,

		init: function() {
			this.bindEvents();
			this.initCharts();
			this.initTables();
			this.initRefresh();
		},

		bindEvents: function() {
			// Date picker
			$(document).on('click', '.reports-date-picker-toggle', this.onDatePickerToggle.bind(this));
			$(document).on('click', '.reports-date-preset', this.onPresetClick.bind(this));
			$(document).on('click', '.reports-date-picker-apply', this.onApplyCustomDate.bind(this));
			$(document).on('click', '.reports-date-picker-cancel', this.onCancelCustomDate.bind(this));
			$(document).on('click', this.onDocumentClick.bind(this));

			// Tables
			$(document).on('click', '.reports-table th.sortable', this.onTableSort.bind(this));
			$(document).on('click', '.reports-table-pages button', this.onTablePage.bind(this));

			// Exports
			$(document).on('click', '.reports-export-button', this.onExportClick.bind(this));

			// Refresh
			$(document).on('click', '.reports-refresh-button', this.onRefreshClick.bind(this));
		},

		/* REFRESH */

		initRefresh: function() {
			var $controls = $('.reports-refresh-controls');
			if (!$controls.length) return;

			var autoRefresh = parseInt($controls.data('auto-refresh'), 10);

			// Set initial last updated time
			this.lastUpdated = new Date();

			if (autoRefresh > 0) {
				// Start auto-refresh timer
				this.startAutoRefresh(autoRefresh);

				// Start last updated display timer
				this.startLastUpdatedTimer();

				// Pause when tab is hidden
				$(document).on('visibilitychange', this.onVisibilityChange.bind(this));
			}
		},

		startAutoRefresh: function(interval) {
			var self = this;

			if (this.refreshTimer) {
				clearInterval(this.refreshTimer);
			}

			this.refreshTimer = setInterval(function() {
				self.refreshAllComponents();
			}, interval * 1000);
		},

		startLastUpdatedTimer: function() {
			var self = this;

			if (this.lastUpdatedTimer) {
				clearInterval(this.lastUpdatedTimer);
			}

			this.lastUpdatedTimer = setInterval(function() {
				self.updateLastUpdatedText();
			}, 10000); // Update every 10 seconds
		},

		updateLastUpdatedText: function() {
			if (!this.lastUpdated) return;

			var seconds = Math.floor((new Date() - this.lastUpdated) / 1000);
			var text = '';

			if (seconds < 10) {
				text = 'Updated just now';
			} else if (seconds < 60) {
				text = 'Updated ' + seconds + 's ago';
			} else if (seconds < 3600) {
				var minutes = Math.floor(seconds / 60);
				text = 'Updated ' + minutes + 'm ago';
			} else {
				var hours = Math.floor(seconds / 3600);
				text = 'Updated ' + hours + 'h ago';
			}

			$('.reports-last-updated-text').text(text);
		},

		onVisibilityChange: function() {
			var $controls = $('.reports-refresh-controls');
			var autoRefresh = parseInt($controls.data('auto-refresh'), 10);

			if (document.hidden) {
				// Tab hidden - pause auto-refresh
				if (this.refreshTimer) {
					clearInterval(this.refreshTimer);
					this.refreshTimer = null;
				}
			} else {
				// Tab visible - resume auto-refresh
				if (autoRefresh > 0) {
					this.startAutoRefresh(autoRefresh);
				}
			}
		},

		onRefreshClick: function(e) {
			e.preventDefault();
			this.refreshAllComponents();
		},

		refreshAllComponents: function() {
			var self = this;
			var $button = $('.reports-refresh-button');
			var $wrap = $('.reports-wrap');
			var reportId = $wrap.data('report-id');

			if (!reportId || $button.hasClass('refreshing')) return;

			// Show loading state
			$button.addClass('refreshing');
			$('.reports-content').addClass('reports-component-refreshing');

			// Fetch all components for current tab
			$.ajax({
				url: ReportsAdmin.restUrl + 'components',
				method: 'GET',
				data: {
					report_id: reportId,
					tab: this.getCurrentTab(),
					date_preset: this.getCurrentDatePreset(),
					date_start: this.getCurrentDateStart(),
					date_end: this.getCurrentDateEnd()
				},
				beforeSend: function(xhr) {
					xhr.setRequestHeader('X-WP-Nonce', ReportsAdmin.restNonce);
				},
				success: function(response) {
					if (response.success && response.components) {
						self.updateComponents(response.components);
					}
				},
				error: function(xhr) {
					console.error('Refresh failed:', xhr.responseJSON);
				},
				complete: function() {
					$button.removeClass('refreshing');
					$('.reports-content').removeClass('reports-component-refreshing');

					// Update last updated time
					self.lastUpdated = new Date();
					self.updateLastUpdatedText();
				}
			});
		},

		updateComponents: function(components) {
			var self = this;

			$.each(components, function(componentId, data) {
				var $component = $('[data-component-id="' + componentId + '"]');
				if (!$component.length) return;

				// Determine component type and update accordingly
				if ($component.hasClass('reports-tile')) {
					self.updateTile($component, data);
				} else if ($component.hasClass('reports-chart-wrapper')) {
					self.updateChart(componentId, data);
				} else if ($component.hasClass('reports-table-wrapper')) {
					self.updateTable($component, data);
				}
			});
		},

		updateTile: function($tile, data) {
			if (data.value !== undefined) {
				$tile.find('.reports-tile-value').text(data.formatted_value || data.value);
			}

			if (data.change !== undefined) {
				var $change = $tile.find('.reports-tile-change');
				var changeClass = 'change-neutral';
				var icon = 'minus';

				if (data.change_direction === 'up') {
					changeClass = 'change-up';
					icon = 'arrow-up-alt';
				} else if (data.change_direction === 'down') {
					changeClass = 'change-down';
					icon = 'arrow-down-alt';
				}

				$change.removeClass('change-up change-down change-neutral').addClass(changeClass);
				$change.html('<span class="dashicons dashicons-' + icon + '"></span> ' + Math.abs(data.change).toFixed(1) + '%');
			}
		},

		updateChart: function(chartId, data) {
			var chart = this.charts[chartId];
			if (!chart || !data.labels || !data.datasets) return;

			chart.data.labels = data.labels;
			chart.data.datasets = data.datasets;
			chart.update('none'); // 'none' = no animation
		},

		updateTable: function($wrapper, data) {
			if (!data.rows) return;

			var $tbody = $wrapper.find('.reports-table tbody');
			$tbody.empty();

			// This would need the column config to properly rebuild rows
			// For now, just trigger a page reload for tables
			// Could enhance later to properly rebuild table rows
		},

		getCurrentTab: function() {
			var url = new URL(window.location.href);
			return url.searchParams.get('tab') || '';
		},

		getCurrentDatePreset: function() {
			var url = new URL(window.location.href);
			return url.searchParams.get('date_preset') || 'this_month';
		},

		getCurrentDateStart: function() {
			var url = new URL(window.location.href);
			return url.searchParams.get('date_start') || '';
		},

		getCurrentDateEnd: function() {
			var url = new URL(window.location.href);
			return url.searchParams.get('date_end') || '';
		},

		/* DATE PICKER */

		onDatePickerToggle: function(e) {
			e.preventDefault();
			e.stopPropagation();

			var $picker = $(e.currentTarget).closest('.reports-date-picker');
			var $dropdown = $picker.find('.reports-date-picker-dropdown');

			$('.reports-date-picker-dropdown').not($dropdown).hide();
			$dropdown.toggle();
		},

		onPresetClick: function(e) {
			e.preventDefault();

			var $button = $(e.currentTarget);
			var preset = $button.data('preset');
			var $picker = $button.closest('.reports-date-picker');

			$picker.find('.reports-date-preset').removeClass('active');
			$button.addClass('active');

			if (preset === 'custom') {
				$picker.find('.reports-date-picker-presets').hide();
				$picker.find('.reports-date-picker-custom').show();
			} else {
				this.applyDateRange(preset);
			}
		},

		onApplyCustomDate: function(e) {
			e.preventDefault();

			var $picker = $(e.currentTarget).closest('.reports-date-picker');
			var startDate = $picker.find('.reports-date-start').val();
			var endDate = $picker.find('.reports-date-end').val();

			if (startDate && endDate) {
				this.applyDateRange('custom', startDate, endDate);
			}
		},

		onCancelCustomDate: function(e) {
			e.preventDefault();

			var $picker = $(e.currentTarget).closest('.reports-date-picker');
			$picker.find('.reports-date-picker-custom').hide();
			$picker.find('.reports-date-picker-presets').show();
			$picker.find('.reports-date-picker-dropdown').hide();
		},

		onDocumentClick: function(e) {
			if (!$(e.target).closest('.reports-date-picker').length) {
				$('.reports-date-picker-dropdown').hide();
				$('.reports-date-picker-custom').hide();
				$('.reports-date-picker-presets').show();
			}
		},

		applyDateRange: function(preset, startDate, endDate) {
			var url = new URL(window.location.href);

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

		/* CHARTS */

		initCharts: function() {
			var self = this;

			if (typeof Chart === 'undefined') {
				console.warn('Chart.js not loaded');
				return;
			}

			$('.reports-chart-canvas').each(function() {
				var $canvas = $(this);
				var chartId = $canvas.data('chart-id');
				var chartConfig = $canvas.data('chart-config');

				if (chartConfig && chartId) {
					self.createChart($canvas[0], chartId, chartConfig);
				}
			});
		},

		createChart: function(canvas, chartId, config) {
			if (this.charts[chartId]) {
				this.charts[chartId].destroy();
			}

			var ctx = canvas.getContext('2d');
			var defaultColors = [
				'#3b82f6', '#10b981', '#f59e0b', '#ef4444',
				'#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'
			];

			if (config.data && config.data.datasets) {
				var self = this;
				config.data.datasets = config.data.datasets.map(function(dataset, index) {
					var color = defaultColors[index % defaultColors.length];

					if (config.type === 'line') {
						dataset.borderColor = dataset.borderColor || color;
						dataset.backgroundColor = dataset.backgroundColor || self.hexToRgba(color, 0.1);
						dataset.tension = dataset.tension || 0.3;
						dataset.fill = dataset.fill !== false;
					} else if (config.type === 'bar') {
						dataset.backgroundColor = dataset.backgroundColor || defaultColors;
						dataset.borderRadius = dataset.borderRadius || 4;
					} else if (config.type === 'pie' || config.type === 'doughnut') {
						dataset.backgroundColor = dataset.backgroundColor || defaultColors;
					}

					return dataset;
				});
			}

			var options = $.extend(true, {
				responsive: true,
				maintainAspectRatio: false,
				plugins: {
					legend: {
						display: config.data.datasets && config.data.datasets.length > 1,
						position: 'top'
					}
				}
			}, config.options || {});

			if (config.type === 'line' || config.type === 'bar') {
				options.scales = options.scales || {
					y: { beginAtZero: true }
				};
			}

			this.charts[chartId] = new Chart(ctx, {
				type: config.type,
				data: config.data,
				options: options
			});
		},

		hexToRgba: function(hex, alpha) {
			var r = parseInt(hex.slice(1, 3), 16);
			var g = parseInt(hex.slice(3, 5), 16);
			var b = parseInt(hex.slice(5, 7), 16);
			return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
		},

		/* TABLES */

		initTables: function() {
			var self = this;

			$('.reports-table-container[data-paginated="true"]').each(function() {
				var $container = $(this);
				$container.data('current-page', 1);
				self.applyTablePagination($container);
			});
		},

		onTableSort: function(e) {
			var $th = $(e.currentTarget);
			var $table = $th.closest('.reports-table');
			var columnIndex = $th.index();
			var isAsc = $th.hasClass('sorted-asc');

			$table.find('th').removeClass('sorted-asc sorted-desc');
			$th.addClass(isAsc ? 'sorted-desc' : 'sorted-asc');

			var $tbody = $table.find('tbody');
			var $rows = $tbody.find('tr').toArray();

			$rows.sort(function(a, b) {
				var aVal = $(a).find('td').eq(columnIndex).text().trim();
				var bVal = $(b).find('td').eq(columnIndex).text().trim();

				var aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
				var bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));

				if (!isNaN(aNum) && !isNaN(bNum)) {
					return isAsc ? bNum - aNum : aNum - bNum;
				}

				return isAsc ? bVal.localeCompare(aVal) : aVal.localeCompare(bVal);
			});

			$tbody.append($rows);
		},

		onTablePage: function(e) {
			var $button = $(e.currentTarget);
			if ($button.prop('disabled')) return;

			var page = parseInt($button.data('page'), 10);
			var $container = $button.closest('.reports-table-container');

			$container.data('current-page', page);
			this.applyTablePagination($container);
		},

		applyTablePagination: function($container) {
			var $table = $container.find('.reports-table');
			var $rows = $table.find('tbody tr');
			var currentPage = $container.data('current-page') || 1;
			var perPage = $container.data('per-page') || 10;
			var totalRows = $rows.length;
			var totalPages = Math.ceil(totalRows / perPage);
			var start = (currentPage - 1) * perPage;
			var end = start + perPage;

			$rows.hide().slice(start, end).show();

			var $pagination = $container.find('.reports-table-pagination');
			var showing = Math.min(end, totalRows);

			$pagination.find('.reports-table-info').text(
				'Showing ' + (start + 1) + '-' + showing + ' of ' + totalRows
			);
		},

		/* EXPORTS */

		onExportClick: function(e) {
			e.preventDefault();

			var $button = $(e.currentTarget);
			var $card = $button.closest('.reports-export-card');
			var exportId = $button.data('export-id');
			var reportId = $button.data('report-id');

			if ($button.prop('disabled')) return;

			var filters = this.gatherExportFilters($card);
			this.startExport(reportId, exportId, filters, $card, $button);
		},

		gatherExportFilters: function($card) {
			var filters = {};

			$card.find('.reports-filter-input').each(function() {
				var $field = $(this);
				var name = $field.attr('name');
				if (!name) return;

				var filterKey = name.replace(/^filter_/, '').replace(/\[\]$/, '');

				if ($field.is(':checkbox')) {
					if (!filters[filterKey]) filters[filterKey] = [];
					if ($field.is(':checked')) filters[filterKey].push($field.val());
				} else if ($field.is('select[multiple]')) {
					filters[filterKey] = $field.val() || [];
				} else {
					var val = $field.val();
					if (val) filters[filterKey] = val;
				}
			});

			return filters;
		},

		startExport: function(reportId, exportId, filters, $card, $button) {
			var self = this;
			var $progress = $card.find('.reports-export-progress');
			var $progressFill = $card.find('.reports-export-progress-fill');
			var $progressLabel = $card.find('.reports-export-progress-label');
			var $progressPercent = $card.find('.reports-export-progress-percent');

			$button.prop('disabled', true);
			$button.find('.button-text').text('Exporting...');
			$progress.show();
			$progressFill.css('width', '0%');
			$progressLabel.text('Preparing export...');
			$progressPercent.text('0%');

			var requestData = {
				report_id: reportId,
				export_id: exportId,
				filters: filters,
				date_preset: this.getCurrentDatePreset(),
				date_start: this.getCurrentDateStart(),
				date_end: this.getCurrentDateEnd()
			};

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
					var message = xhr.responseJSON ? xhr.responseJSON.message : 'Export failed';
					self.handleExportError(message, $card, $button);
				}
			});
		},

		processExportBatches: function(exportToken, totalItems, batchSize, currentBatch, $card, $button) {
			var self = this;
			var $progressFill = $card.find('.reports-export-progress-fill');
			var $progressLabel = $card.find('.reports-export-progress-label');
			var $progressPercent = $card.find('.reports-export-progress-percent');

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

					var percent = Math.round((response.processed_items / response.total_items) * 100);
					$progressFill.css('width', percent + '%');
					$progressPercent.text(percent + '%');
					$progressLabel.text('Processing ' + response.processed_items + ' / ' + response.total_items);

					if (response.is_complete) {
						$progressLabel.text('Export complete!');
						$progressFill.css('width', '100%');
						$progressPercent.text('100%');

						setTimeout(function() {
							window.location.href = response.download_url;
							self.resetExportUI($card, $button);
						}, 500);
					} else {
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
					var message = xhr.responseJSON ? xhr.responseJSON.message : 'Batch failed';
					self.handleExportError(message, $card, $button);
				}
			});
		},

		handleExportError: function(message, $card, $button) {
			var self = this;
			var $progressLabel = $card.find('.reports-export-progress-label');
			$progressLabel.text('Error: ' + message).addClass('error');

			setTimeout(function() {
				self.resetExportUI($card, $button);
			}, 3000);
		},

		resetExportUI: function($card, $button) {
			var $progress = $card.find('.reports-export-progress');
			var $progressLabel = $card.find('.reports-export-progress-label');

			$button.prop('disabled', false);
			$button.find('.button-text').text('Download CSV');
			$progress.hide();
			$progressLabel.removeClass('error');
		}
	};

	$(function() {
		ReportsController.init();
	});

	window.ReportsController = ReportsController;

})(jQuery);