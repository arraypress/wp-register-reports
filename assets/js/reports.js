/**
 * Reports Admin JavaScript
 *
 * Handles date picking, chart rendering, table pagination/sorting,
 * data exports, and auto-refresh functionality for the reports admin.
 *
 * @package ArrayPress\RegisterReports
 */

(function ($) {
    'use strict';

    /**
     * Main Reports Controller
     *
     * @namespace ReportsController
     */
    const ReportsController = {

        /* ========================================================================
         * PROPERTIES
         * ======================================================================== */

        /**
         * Chart.js instances indexed by chart ID
         *
         * @type {Object.<string, Chart>}
         */
        charts: {},

        /**
         * Auto-refresh interval timer
         *
         * @type {number|null}
         */
        refreshTimer: null,

        /**
         * Timestamp of last data refresh
         *
         * @type {Date|null}
         */
        lastUpdated: null,

        /**
         * Timer for updating "last updated" text
         *
         * @type {number|null}
         */
        lastUpdatedTimer: null,

        /* ========================================================================
         * INITIALIZATION
         * ======================================================================== */

        /**
         * Initialize the reports controller
         *
         * @returns {void}
         */
        init: function () {
            this.bindEvents();
            this.initCharts();
            this.initTables();
            this.initRefresh();
        },

        /**
         * Bind all event handlers
         *
         * @returns {void}
         */
        bindEvents: function () {
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

        /* ========================================================================
         * HELPERS
         * ======================================================================== */

        /**
         * Get localized string with optional sprintf-style replacements
         *
         * @param {string}    key  - i18n key from ReportsAdmin.i18n
         * @param {...*}      args - Replacement values for %s, %d, %1$s, %2$d, etc.
         * @returns {string}
         */
        i18n: function (key, ...args) {
            let str = ReportsAdmin.i18n[key] || key;

            if (args.length === 0) {
                return str;
            }

            // Handle positional placeholders like %1$d, %2$s
            str = str.replace(/%(\d+)\$([sd])/g, (match, position, type) => {
                const index = parseInt(position, 10) - 1;
                return args[index] !== undefined ? args[index] : match;
            });

            // Handle simple placeholders like %d, %s
            let argIndex = 0;
            str = str.replace(/%([sd])/g, (match, type) => {
                return args[argIndex] !== undefined ? args[argIndex++] : match;
            });

            return str;
        },

        /* ========================================================================
         * AUTO-REFRESH
         * ======================================================================== */

        /**
         * Initialize auto-refresh functionality
         *
         * @returns {void}
         */
        initRefresh: function () {
            const $controls = $('.reports-refresh-controls');

            if (!$controls.length) {
                return;
            }

            const autoRefresh = parseInt($controls.data('auto-refresh'), 10);

            this.lastUpdated = new Date();

            if (autoRefresh > 0) {
                this.startAutoRefresh(autoRefresh);
                this.startLastUpdatedTimer();

                $(document).on('visibilitychange', this.onVisibilityChange.bind(this));
            }
        },

        /**
         * Start the auto-refresh interval
         *
         * @param {number} interval - Refresh interval in seconds
         * @returns {void}
         */
        startAutoRefresh: function (interval) {
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
            }

            this.refreshTimer = setInterval(() => {
                this.refreshAllComponents();
            }, interval * 1000);
        },

        /**
         * Start the timer that updates "last updated" text
         *
         * @returns {void}
         */
        startLastUpdatedTimer: function () {
            if (this.lastUpdatedTimer) {
                clearInterval(this.lastUpdatedTimer);
            }

            this.lastUpdatedTimer = setInterval(() => {
                this.updateLastUpdatedText();
            }, 10000);
        },

        /**
         * Update the "last updated" display text
         *
         * @returns {void}
         */
        updateLastUpdatedText: function () {
            if (!this.lastUpdated) {
                return;
            }

            const seconds = Math.floor((new Date() - this.lastUpdated) / 1000);
            let text = '';

            if (seconds < 10) {
                text = this.i18n('updatedJustNow');
            } else if (seconds < 60) {
                text = this.i18n('updatedSeconds', seconds);
            } else if (seconds < 3600) {
                const minutes = Math.floor(seconds / 60);
                text = this.i18n('updatedMinutes', minutes);
            } else {
                const hours = Math.floor(seconds / 3600);
                text = this.i18n('updatedHours', hours);
            }

            $('.reports-last-updated-text').text(text);
        },

        /**
         * Handle page visibility changes (pause/resume auto-refresh)
         *
         * @returns {void}
         */
        onVisibilityChange: function () {
            const $controls = $('.reports-refresh-controls');
            const autoRefresh = parseInt($controls.data('auto-refresh'), 10);

            if (document.hidden) {
                if (this.refreshTimer) {
                    clearInterval(this.refreshTimer);
                    this.refreshTimer = null;
                }
            } else {
                if (autoRefresh > 0) {
                    this.startAutoRefresh(autoRefresh);
                }
            }
        },

        /**
         * Handle refresh button click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onRefreshClick: function (e) {
            e.preventDefault();
            this.refreshAllComponents();
        },

        /**
         * Refresh all components on the current tab
         *
         * @returns {void}
         */
        refreshAllComponents: function () {
            const $button = $('.reports-refresh-button');
            const $wrap = $('.reports-wrap');
            const reportId = $wrap.data('report-id');

            if (!reportId || $button.hasClass('refreshing')) {
                return;
            }

            $button.addClass('refreshing');
            $('.reports-content').addClass('reports-component-refreshing');

            const filters = this.getCurrentFilters();

            const requestData = {
                report_id: reportId,
                tab: this.getCurrentTab(),
                date_preset: this.getCurrentDatePreset(),
                date_start: this.getCurrentDateStart(),
                date_end: this.getCurrentDateEnd(),
                ...filters
            };

            $.ajax({
                url: ReportsAdmin.restUrl + 'components',
                method: 'GET',
                data: requestData,
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', ReportsAdmin.restNonce);
                },
                success: (response) => {
                    if (response.success && response.components) {
                        this.updateComponents(response.components);
                    }
                },
                error: (xhr) => {
                    console.error('Refresh failed:', xhr.responseJSON);
                },
                complete: () => {
                    $button.removeClass('refreshing');
                    $('.reports-content').removeClass('reports-component-refreshing');

                    this.lastUpdated = new Date();
                    this.updateLastUpdatedText();
                }
            });
        },

        /**
         * Update multiple components with new data
         *
         * @param {Object} components - Component data keyed by component ID
         * @returns {void}
         */
        updateComponents: function (components) {
            $.each(components, (componentId, data) => {
                const $component = $('[data-component-id="' + componentId + '"]');

                if (!$component.length) {
                    return;
                }

                if ($component.hasClass('reports-tile')) {
                    this.updateTile($component, data);
                } else if ($component.hasClass('reports-chart-wrapper')) {
                    this.updateChart(componentId, data);
                } else if ($component.hasClass('reports-table-wrapper')) {
                    this.updateTable($component, data);
                }
            });
        },

        /**
         * Update a tile component with new data
         *
         * @param {jQuery} $tile - Tile element
         * @param {Object} data  - Tile data
         * @returns {void}
         */
        updateTile: function ($tile, data) {
            if (data.value !== undefined) {
                $tile.find('.reports-tile-value').text(data.formatted_value || data.value);
            }

            if (data.change !== undefined) {
                const $change = $tile.find('.reports-tile-change');
                let changeClass = 'change-neutral';
                let icon = 'minus';

                if (data.change_direction === 'up') {
                    changeClass = 'change-up';
                    icon = 'arrow-up-alt';
                } else if (data.change_direction === 'down') {
                    changeClass = 'change-down';
                    icon = 'arrow-down-alt';
                }

                $change
                    .removeClass('change-up change-down change-neutral')
                    .addClass(changeClass)
                    .html('<span class="dashicons dashicons-' + icon + '"></span> ' + Math.abs(data.change).toFixed(1) + '%');
            }
        },

        /**
         * Update a chart component with new data
         *
         * @param {string} chartId - Chart identifier
         * @param {Object} data    - Chart data with labels and datasets
         * @returns {void}
         */
        updateChart: function (chartId, data) {
            const chart = this.charts[chartId];

            if (!chart || !data.labels || !data.datasets) {
                return;
            }

            chart.data.labels = data.labels;
            chart.data.datasets = data.datasets;
            chart.update('none');
        },

        /**
         * Update a table component with new data
         *
         * @param {jQuery} $wrapper - Table wrapper element
         * @param {Object} data     - Table data with rows
         * @returns {void}
         */
        updateTable: function ($wrapper, data) {
            if (!data.rows) {
                return;
            }

            const $tbody = $wrapper.find('.reports-table tbody');
            $tbody.empty();

            // TODO: Properly rebuild table rows with column config
        },

        /* ========================================================================
         * URL PARAMETER HELPERS
         * ======================================================================== */

        /**
         * Get current tab from URL
         *
         * @returns {string}
         */
        getCurrentTab: function () {
            const url = new URL(window.location.href);

            return url.searchParams.get('tab') || '';
        },

        /**
         * Get current date preset from URL
         *
         * @returns {string}
         */
        getCurrentDatePreset: function () {
            const url = new URL(window.location.href);

            return url.searchParams.get('date_preset') || 'this_month';
        },

        /**
         * Get current date start from URL
         *
         * @returns {string}
         */
        getCurrentDateStart: function () {
            const url = new URL(window.location.href);

            return url.searchParams.get('date_start') || '';
        },

        /**
         * Get current date end from URL
         *
         * @returns {string}
         */
        getCurrentDateEnd: function () {
            const url = new URL(window.location.href);

            return url.searchParams.get('date_end') || '';
        },

        /**
         * Get all filter parameters from URL
         *
         * @returns {Object}
         */
        getCurrentFilters: function () {
            const url = new URL(window.location.href);
            const filters = {};

            url.searchParams.forEach((value, key) => {
                if (key.indexOf('filter_') === 0) {
                    filters[key] = value;
                }
            });

            return filters;
        },

        /* ========================================================================
         * DATE PICKER
         * ======================================================================== */

        /**
         * Handle date picker toggle click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onDatePickerToggle: function (e) {
            e.preventDefault();
            e.stopPropagation();

            const $picker = $(e.currentTarget).closest('.reports-date-picker');
            const $dropdown = $picker.find('.reports-date-picker-dropdown');

            $('.reports-date-picker-dropdown').not($dropdown).hide();
            $dropdown.toggle();
        },

        /**
         * Handle preset button click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onPresetClick: function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const preset = $button.data('preset');
            const $picker = $button.closest('.reports-date-picker');

            $picker.find('.reports-date-preset').removeClass('active');
            $button.addClass('active');

            if (preset === 'custom') {
                $picker.find('.reports-date-picker-presets').hide();
                $picker.find('.reports-date-picker-custom').show();
            } else {
                this.applyDateRange(preset);
            }
        },

        /**
         * Handle apply custom date button click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onApplyCustomDate: function (e) {
            e.preventDefault();

            const $picker = $(e.currentTarget).closest('.reports-date-picker');
            const startDate = $picker.find('.reports-date-start').val();
            const endDate = $picker.find('.reports-date-end').val();

            if (startDate && endDate) {
                this.applyDateRange('custom', startDate, endDate);
            }
        },

        /**
         * Handle cancel custom date button click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onCancelCustomDate: function (e) {
            e.preventDefault();

            const $picker = $(e.currentTarget).closest('.reports-date-picker');

            $picker.find('.reports-date-picker-custom').hide();
            $picker.find('.reports-date-picker-presets').show();
            $picker.find('.reports-date-picker-dropdown').hide();
        },

        /**
         * Handle document click (close open dropdowns)
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onDocumentClick: function (e) {
            if (!$(e.target).closest('.reports-date-picker').length) {
                $('.reports-date-picker-dropdown').hide();
                $('.reports-date-picker-custom').hide();
                $('.reports-date-picker-presets').show();
            }
        },

        /**
         * Apply a date range and reload the page
         *
         * @param {string}  preset    - Date preset key
         * @param {string=} startDate - Custom start date (YYYY-MM-DD)
         * @param {string=} endDate   - Custom end date (YYYY-MM-DD)
         * @returns {void}
         */
        applyDateRange: function (preset, startDate, endDate) {
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

        /* ========================================================================
         * CHARTS
         * ======================================================================== */

        /**
         * Initialize all chart components
         *
         * @returns {void}
         */
        initCharts: function () {
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js not loaded');
                return;
            }

            $('.reports-chart-canvas').each((index, element) => {
                const $canvas = $(element);
                const chartId = $canvas.data('chart-id');
                const chartConfig = $canvas.data('chart-config');

                if (chartConfig && chartId) {
                    this.createChart(element, chartId, chartConfig);
                }
            });
        },

        /**
         * Create a Chart.js instance
         *
         * @param {HTMLCanvasElement} canvas  - Canvas element
         * @param {string}            chartId - Chart identifier
         * @param {Object}            config  - Chart.js configuration
         * @returns {void}
         */
        createChart: function (canvas, chartId, config) {
            if (this.charts[chartId]) {
                this.charts[chartId].destroy();
            }

            const ctx = canvas.getContext('2d');
            const defaultColors = ReportsAdmin.chartDefaults?.colors || [
                '#3b82f6', '#10b981', '#f59e0b', '#ef4444',
                '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'
            ];

            if (config.data && config.data.datasets) {
                config.data.datasets = config.data.datasets.map((dataset, index) => {
                    const color = defaultColors[index % defaultColors.length];

                    if (config.type === 'line') {
                        dataset.borderColor = dataset.borderColor || color;
                        dataset.backgroundColor = dataset.backgroundColor || this.hexToRgba(color, 0.1);
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

            const options = $.extend(true, {
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
                    y: {beginAtZero: true}
                };
            }

            this.charts[chartId] = new Chart(ctx, {
                type: config.type,
                data: config.data,
                options: options
            });
        },

        /**
         * Convert hex color to rgba
         *
         * @param {string} hex   - Hex color (e.g., '#3b82f6')
         * @param {number} alpha - Alpha value (0-1)
         * @returns {string} RGBA color string
         */
        hexToRgba: function (hex, alpha) {
            const r = parseInt(hex.slice(1, 3), 16);
            const g = parseInt(hex.slice(3, 5), 16);
            const b = parseInt(hex.slice(5, 7), 16);

            return 'rgba(' + r + ', ' + g + ', ' + b + ', ' + alpha + ')';
        },

        /* ========================================================================
         * TABLES
         * ======================================================================== */

        /**
         * Initialize all table components
         *
         * @returns {void}
         */
        initTables: function () {
            $('.reports-table-container[data-paginated="true"]').each((index, element) => {
                const $container = $(element);

                $container.data('current-page', 1);
                this.applyTablePagination($container);
            });
        },

        /**
         * Handle table column sort click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onTableSort: function (e) {
            const $th = $(e.currentTarget);
            const $table = $th.closest('.reports-table');
            const columnIndex = $th.index();
            const isAsc = $th.hasClass('sorted-asc');

            $table.find('th').removeClass('sorted-asc sorted-desc');
            $th.addClass(isAsc ? 'sorted-desc' : 'sorted-asc');

            const $tbody = $table.find('tbody');
            const rows = $tbody.find('tr').toArray();

            rows.sort((a, b) => {
                const aVal = $(a).find('td').eq(columnIndex).text().trim();
                const bVal = $(b).find('td').eq(columnIndex).text().trim();

                const aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
                const bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));

                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return isAsc ? bNum - aNum : aNum - bNum;
                }

                return isAsc ? bVal.localeCompare(aVal) : aVal.localeCompare(bVal);
            });

            $tbody.append(rows);
        },

        /**
         * Handle table pagination button click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onTablePage: function (e) {
            const $button = $(e.currentTarget);

            if ($button.prop('disabled')) {
                return;
            }

            const page = parseInt($button.data('page'), 10);
            const $container = $button.closest('.reports-table-container');

            $container.data('current-page', page);
            this.applyTablePagination($container);
        },

        /**
         * Apply pagination to a table
         *
         * @param {jQuery} $container - Table container element
         * @returns {void}
         */
        applyTablePagination: function ($container) {
            const $table = $container.find('.reports-table');
            const $rows = $table.find('tbody tr');
            const currentPage = $container.data('current-page') || 1;
            const perPage = $container.data('per-page') || 10;
            const totalRows = $rows.length;
            const start = (currentPage - 1) * perPage;
            const end = start + perPage;
            const showing = Math.min(end, totalRows);

            $rows.hide().slice(start, end).show();

            const $pagination = $container.find('.reports-table-pagination');

            $pagination.find('.reports-table-info').text(
                this.i18n('showing', start + 1, showing, totalRows)
            );
        },

        /* ========================================================================
         * EXPORTS
         * ======================================================================== */

        /**
         * Handle export button click
         *
         * @param {Event} e - Click event
         * @returns {void}
         */
        onExportClick: function (e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $card = $button.closest('.reports-export-card');
            const exportId = $button.data('export-id');
            const reportId = $button.data('report-id');

            if ($button.prop('disabled')) {
                return;
            }

            const filters = this.gatherExportFilters($card);

            this.startExport(reportId, exportId, filters, $card, $button);
        },

        /**
         * Gather filter values from export card
         *
         * @param {jQuery} $card - Export card element
         * @returns {Object} Filter values keyed by filter name
         */
        gatherExportFilters: function ($card) {
            const filters = {};

            $card.find('.reports-filter-input').each(function () {
                const $field = $(this);
                const name = $field.attr('name');

                if (!name) {
                    return;
                }

                const filterKey = name.replace(/^filter_/, '').replace(/\[]$/, '');

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
         * Start an export process
         *
         * @param {string} reportId - Report identifier
         * @param {string} exportId - Export identifier
         * @param {Object} filters  - Filter values
         * @param {jQuery} $card    - Export card element
         * @param {jQuery} $button  - Export button element
         * @returns {void}
         */
        startExport: function (reportId, exportId, filters, $card, $button) {
            const $progress = $card.find('.reports-export-progress');
            const $progressFill = $card.find('.reports-export-progress-fill');
            const $progressLabel = $card.find('.reports-export-progress-label');
            const $progressPercent = $card.find('.reports-export-progress-percent');

            $button.prop('disabled', true);
            $button.find('.button-text').text(this.i18n('exporting'));
            $progress.show();
            $progressFill.css('width', '0%');
            $progressLabel.text(this.i18n('preparing'));
            $progressPercent.text('0%');

            const requestData = {
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
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', ReportsAdmin.restNonce);
                },
                contentType: 'application/json',
                data: JSON.stringify(requestData),
                success: (response) => {
                    if (response.success) {
                        this.processExportBatches(
                            response.export_token,
                            response.total_items,
                            response.batch_size,
                            0,
                            $card,
                            $button
                        );
                    } else {
                        this.handleExportError(response.message || this.i18n('exportFailed'), $card, $button);
                    }
                },
                error: (xhr) => {
                    const message = xhr.responseJSON ? xhr.responseJSON.message : this.i18n('exportFailed');
                    this.handleExportError(message, $card, $button);
                }
            });
        },

        /**
         * Process export batches recursively
         *
         * @param {string} exportToken  - Export session token
         * @param {number} totalItems   - Total items to export
         * @param {number} batchSize    - Items per batch
         * @param {number} currentBatch - Current batch index
         * @param {jQuery} $card        - Export card element
         * @param {jQuery} $button      - Export button element
         * @returns {void}
         */
        processExportBatches: function (exportToken, totalItems, batchSize, currentBatch, $card, $button) {
            const $progressFill = $card.find('.reports-export-progress-fill');
            const $progressLabel = $card.find('.reports-export-progress-label');
            const $progressPercent = $card.find('.reports-export-progress-percent');

            $.ajax({
                url: ReportsAdmin.restUrl + 'export/batch',
                method: 'POST',
                beforeSend: (xhr) => {
                    xhr.setRequestHeader('X-WP-Nonce', ReportsAdmin.restNonce);
                },
                contentType: 'application/json',
                data: JSON.stringify({
                    export_token: exportToken,
                    batch: currentBatch
                }),
                success: (response) => {
                    if (!response.success) {
                        this.handleExportError(response.message || this.i18n('batchFailed'), $card, $button);
                        return;
                    }

                    const percent = Math.round((response.processed_items / response.total_items) * 100);

                    $progressFill.css('width', percent + '%');
                    $progressPercent.text(percent + '%');
                    $progressLabel.text(this.i18n('processing', response.processed_items, response.total_items));

                    if (response.is_complete) {
                        $progressLabel.text(this.i18n('complete'));
                        $progressFill.css('width', '100%');
                        $progressPercent.text('100%');

                        setTimeout(() => {
                            window.location.href = response.download_url;
                            this.resetExportUI($card, $button);
                        }, 500);
                    } else {
                        this.processExportBatches(
                            exportToken,
                            totalItems,
                            batchSize,
                            currentBatch + 1,
                            $card,
                            $button
                        );
                    }
                },
                error: (xhr) => {
                    const message = xhr.responseJSON ? xhr.responseJSON.message : this.i18n('batchFailed');
                    this.handleExportError(message, $card, $button);
                }
            });
        },

        /**
         * Handle export error
         *
         * @param {string} message - Error message
         * @param {jQuery} $card   - Export card element
         * @param {jQuery} $button - Export button element
         * @returns {void}
         */
        handleExportError: function (message, $card, $button) {
            const $progressLabel = $card.find('.reports-export-progress-label');

            $progressLabel.text(this.i18n('error') + ': ' + message).addClass('error');

            setTimeout(() => {
                this.resetExportUI($card, $button);
            }, 3000);
        },

        /**
         * Reset export UI to initial state
         *
         * @param {jQuery} $card   - Export card element
         * @param {jQuery} $button - Export button element
         * @returns {void}
         */
        resetExportUI: function ($card, $button) {
            const $progress = $card.find('.reports-export-progress');
            const $progressLabel = $card.find('.reports-export-progress-label');

            $button.prop('disabled', false);
            $button.find('.button-text').text(this.i18n('download'));
            $progress.hide();
            $progressLabel.removeClass('error');
        }

    };

    /**
     * Initialize on document ready
     */
    $(function () {
        ReportsController.init();
    });

    /**
     * Expose controller globally
     */
    window.ReportsController = ReportsController;

})(jQuery);