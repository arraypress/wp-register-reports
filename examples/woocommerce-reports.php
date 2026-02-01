<?php
/**
 * Example Usage: WooCommerce Sales Reports
 *
 * This file demonstrates how to use the Reports Registration Library
 * to create a comprehensive sales reporting dashboard.
 *
 * @package     ArrayPress\RegisterReports
 */

use ArrayPress\RegisterReports\Reports;

// Prevent direct access
defined( 'ABSPATH' ) || exit;

/**
 * Register the Sales Reports page.
 */
add_action( 'init', function () {

	// Only register if WooCommerce is active (example dependency)
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	new Reports( 'wc-sales-reports', [

		// Basic page settings
		'page_title'       => __( 'Sales Reports', 'my-plugin' ),
		'menu_title'       => __( 'Reports', 'my-plugin' ),
		'parent_slug'      => 'woocommerce',
		'capability'       => 'manage_woocommerce',

		// Branded header
		'logo'             => plugins_url( 'assets/images/logo.png', __FILE__ ),
		'header_title'     => __( 'Sales Analytics', 'my-plugin' ),

		// Date range configuration
		'show_date_picker' => true,
		'default_preset'   => 'last_30_days',
		
		// Optional: Override date presets (otherwise uses Dates::get_range_options())
		// 'date_presets'  => [
		//     'today'        => __( 'Today', 'my-plugin' ),
		//     'last_7_days'  => __( 'Last 7 Days', 'my-plugin' ),
		//     'last_30_days' => __( 'Last 30 Days', 'my-plugin' ),
		//     'this_month'   => __( 'This Month', 'my-plugin' ),
		//     'custom'       => __( 'Custom Range', 'my-plugin' ),
		// ],

		// Tab definitions
		'tabs'             => [
			'overview' => __( 'Overview', 'my-plugin' ),
			'products' => [
				'label' => __( 'Products', 'my-plugin' ),
				'icon'  => 'dashicons-products',
			],
			'customers' => [
				'label' => __( 'Customers', 'my-plugin' ),
				'icon'  => 'dashicons-groups',
			],
			'exports'  => [
				'label' => __( 'Exports', 'my-plugin' ),
				'icon'  => 'dashicons-download',
			],
		],

		// Component definitions
		'components'       => [

			// =====================================================
			// Overview Tab - Tiles
			// =====================================================

			'total_revenue' => [
				'type'           => 'tile',
				'title'          => __( 'Total Revenue', 'my-plugin' ),
				'tab'            => 'overview',
				'icon'           => 'dashicons-money-alt',
				'color'          => 'green',
				'value_format'   => 'currency',
				'compare'        => true,
				'trend_direction'=> 'up_is_good',
				'data_callback'  => 'my_get_revenue_data',
			],

			'total_orders' => [
				'type'           => 'tile',
				'title'          => __( 'Orders', 'my-plugin' ),
				'tab'            => 'overview',
				'icon'           => 'dashicons-cart',
				'color'          => 'blue',
				'value_format'   => 'number',
				'compare'        => true,
				'data_callback'  => 'my_get_orders_count_data',
			],

			'average_order' => [
				'type'           => 'tile',
				'title'          => __( 'Avg. Order Value', 'my-plugin' ),
				'tab'            => 'overview',
				'icon'           => 'dashicons-chart-line',
				'color'          => 'purple',
				'value_format'   => 'currency',
				'compare'        => true,
				'data_callback'  => 'my_get_aov_data',
			],

			'new_customers' => [
				'type'           => 'tile',
				'title'          => __( 'New Customers', 'my-plugin' ),
				'tab'            => 'overview',
				'icon'           => 'dashicons-admin-users',
				'color'          => 'orange',
				'value_format'   => 'number',
				'compare'        => true,
				'data_callback'  => 'my_get_new_customers_data',
			],

			// =====================================================
			// Overview Tab - Charts
			// =====================================================

			'revenue_chart' => [
				'type'          => 'chart',
				'title'         => __( 'Revenue Over Time', 'my-plugin' ),
				'tab'           => 'overview',
				'chart_type'    => 'line',
				'height'        => 350,
				'width'         => 'full',
				'options'       => [
					'legend'       => true,
					'yAxisLabel'   => __( 'Revenue ($)', 'my-plugin' ),
					'beginAtZero'  => true,
				],
				'data_callback' => 'my_get_revenue_chart_data',
			],

			'orders_by_status' => [
				'type'          => 'chart',
				'title'         => __( 'Orders by Status', 'my-plugin' ),
				'tab'           => 'overview',
				'chart_type'    => 'doughnut',
				'height'        => 300,
				'width'         => 'half',
				'options'       => [
					'legendPosition' => 'right',
				],
				'data_callback' => 'my_get_orders_by_status_data',
			],

			'daily_orders' => [
				'type'          => 'chart',
				'title'         => __( 'Daily Orders', 'my-plugin' ),
				'tab'           => 'overview',
				'chart_type'    => 'bar',
				'height'        => 300,
				'width'         => 'half',
				'data_callback' => 'my_get_daily_orders_data',
			],

			// =====================================================
			// Products Tab
			// =====================================================

			'top_products_table' => [
				'type'          => 'table',
				'title'         => __( 'Top Selling Products', 'my-plugin' ),
				'tab'           => 'products',
				'columns'       => [
					'product'  => __( 'Product', 'my-plugin' ),
					'sku'      => __( 'SKU', 'my-plugin' ),
					'quantity' => __( 'Qty Sold', 'my-plugin' ),
					'revenue'  => __( 'Revenue', 'my-plugin' ),
				],
				'sortable'      => true,
				'searchable'    => true,
				'paginated'     => true,
				'per_page'      => 15,
				'data_callback' => 'my_get_top_products_data',
			],

			'product_performance' => [
				'type'          => 'chart',
				'title'         => __( 'Product Category Performance', 'my-plugin' ),
				'tab'           => 'products',
				'chart_type'    => 'bar',
				'height'        => 350,
				'options'       => [
					'stacked'    => true,
					'xAxisLabel' => __( 'Category', 'my-plugin' ),
					'yAxisLabel' => __( 'Revenue ($)', 'my-plugin' ),
				],
				'data_callback' => 'my_get_category_performance_data',
			],

			// =====================================================
			// Customers Tab
			// =====================================================

			'customer_stats' => [
				'type'    => 'tiles_group',
				'title'   => __( 'Customer Overview', 'my-plugin' ),
				'tab'     => 'customers',
				'columns' => 4,
				'tiles'   => [
					'total_customers' => [
						'title'          => __( 'Total Customers', 'my-plugin' ),
						'icon'           => 'dashicons-groups',
						'value_format'   => 'number',
						'data_callback'  => 'my_get_total_customers_data',
					],
					'returning_customers' => [
						'title'          => __( 'Returning', 'my-plugin' ),
						'icon'           => 'dashicons-update',
						'value_format'   => 'number',
						'data_callback'  => 'my_get_returning_customers_data',
					],
					'customer_ltv' => [
						'title'          => __( 'Avg. LTV', 'my-plugin' ),
						'icon'           => 'dashicons-chart-area',
						'value_format'   => 'currency',
						'data_callback'  => 'my_get_customer_ltv_data',
					],
					'repeat_rate' => [
						'title'          => __( 'Repeat Rate', 'my-plugin' ),
						'icon'           => 'dashicons-yes-alt',
						'value_format'   => 'percentage',
						'data_callback'  => 'my_get_repeat_rate_data',
					],
				],
			],

			'customers_table' => [
				'type'          => 'table',
				'title'         => __( 'Top Customers', 'my-plugin' ),
				'tab'           => 'customers',
				'columns'       => [
					'name'         => __( 'Customer', 'my-plugin' ),
					'email'        => __( 'Email', 'my-plugin' ),
					'orders'       => __( 'Orders', 'my-plugin' ),
					'total_spent'  => __( 'Total Spent', 'my-plugin' ),
					'last_order'   => __( 'Last Order', 'my-plugin' ),
				],
				'sortable'      => true,
				'searchable'    => true,
				'paginated'     => true,
				'per_page'      => 20,
				'data_callback' => 'my_get_top_customers_data',
			],

		],

		// Export definitions - Batched exports with total_callback and data_callback
		'exports'          => [

			'orders_export' => [
				'title'         => __( 'Export Orders', 'my-plugin' ),
				'description'   => __( 'Download a CSV of all orders within the selected date range.', 'my-plugin' ),
				'tab'           => 'exports',
				'filename'      => 'orders-export',
				'icon'          => 'dashicons-clipboard',
				
				// Column headers for CSV
				'headers'       => [
					'order_id'      => __( 'Order ID', 'my-plugin' ),
					'date'          => __( 'Date', 'my-plugin' ),
					'status'        => __( 'Status', 'my-plugin' ),
					'customer_name' => __( 'Customer', 'my-plugin' ),
					'email'         => __( 'Email', 'my-plugin' ),
					'total'         => __( 'Total', 'my-plugin' ),
					'payment_method'=> __( 'Payment Method', 'my-plugin' ),
				],
				
				// Filters shown to user
				'filters'       => [
					'status' => [
						'type'        => 'select',
						'label'       => __( 'Order Status', 'my-plugin' ),
						'placeholder' => __( 'All Statuses', 'my-plugin' ),
						'options'     => [
							'completed'  => __( 'Completed', 'my-plugin' ),
							'processing' => __( 'Processing', 'my-plugin' ),
							'pending'    => __( 'Pending', 'my-plugin' ),
							'refunded'   => __( 'Refunded', 'my-plugin' ),
						],
					],
					'payment_method' => [
						'type'        => 'select',
						'label'       => __( 'Payment Method', 'my-plugin' ),
						'placeholder' => __( 'All Methods', 'my-plugin' ),
						'options'     => [
							'stripe'  => __( 'Stripe', 'my-plugin' ),
							'paypal'  => __( 'PayPal', 'my-plugin' ),
							'cod'     => __( 'Cash on Delivery', 'my-plugin' ),
						],
					],
				],
				
				// Required: Returns total count for progress tracking
				'total_callback' => 'my_get_orders_export_count',
				
				// Required: Returns batch of rows (receives offset, limit)
				'data_callback'  => 'my_get_orders_export_data',
			],

			'products_export' => [
				'title'          => __( 'Export Products Report', 'my-plugin' ),
				'description'    => __( 'Download product sales data for the selected period.', 'my-plugin' ),
				'tab'            => 'exports',
				'filename'       => 'products-report',
				'icon'           => 'dashicons-products',
				'headers'        => [
					'product_id'   => __( 'Product ID', 'my-plugin' ),
					'product_name' => __( 'Product Name', 'my-plugin' ),
					'sku'          => __( 'SKU', 'my-plugin' ),
					'quantity'     => __( 'Quantity Sold', 'my-plugin' ),
					'gross_revenue'=> __( 'Gross Revenue', 'my-plugin' ),
					'net_revenue'  => __( 'Net Revenue', 'my-plugin' ),
				],
				'filters'        => [
					'category' => [
						'type'        => 'select',
						'label'       => __( 'Category', 'my-plugin' ),
						'placeholder' => __( 'All Categories', 'my-plugin' ),
						'options'     => [], // Populate dynamically
					],
				],
				'total_callback' => 'my_get_products_export_count',
				'data_callback'  => 'my_get_products_export_data',
			],

			'customers_export' => [
				'title'          => __( 'Export Customers', 'my-plugin' ),
				'description'    => __( 'Download customer data with purchase history.', 'my-plugin' ),
				'tab'            => 'exports',
				'filename'       => 'customers-export',
				'icon'           => 'dashicons-groups',
				'headers'        => [
					'customer_id' => __( 'Customer ID', 'my-plugin' ),
					'name'        => __( 'Name', 'my-plugin' ),
					'email'       => __( 'Email', 'my-plugin' ),
					'orders'      => __( 'Total Orders', 'my-plugin' ),
					'total_spent' => __( 'Total Spent', 'my-plugin' ),
					'registered'  => __( 'Registered', 'my-plugin' ),
				],
				'filters'        => [
					'min_orders' => [
						'type'    => 'number',
						'label'   => __( 'Minimum Orders', 'my-plugin' ),
						'default' => 1,
					],
				],
				'total_callback' => 'my_get_customers_export_count',
				'data_callback'  => 'my_get_customers_export_data',
			],

		],

		// Help tabs
		'help_tabs'        => [
			'overview' => [
				'title'   => __( 'Overview', 'my-plugin' ),
				'content' => '<p>' . __( 'This reports page shows your store performance metrics.', 'my-plugin' ) . '</p>',
			],
			'exports' => [
				'title'   => __( 'Exports', 'my-plugin' ),
				'content' => '<p>' . __( 'Use the exports tab to download CSV files of your data.', 'my-plugin' ) . '</p>',
			],
		],

	] );

} );


// =============================================================================
// EXAMPLE DATA CALLBACKS
// =============================================================================

/**
 * Get revenue data for tile.
 *
 * @param array $date_range Date range with 'start' and 'end'.
 * @param array $config     Component configuration.
 *
 * @return array
 */
function my_get_revenue_data( array $date_range, array $config ): array {
	// In real implementation, query WooCommerce orders
	// This is example data
	return [
		'value'         => 125430.50,
		'compare_value' => 98250.00,
		'change'        => 27.7,
	];
}

/**
 * Get orders count data for tile.
 */
function my_get_orders_count_data( array $date_range, array $config ): array {
	return [
		'value'         => 847,
		'compare_value' => 720,
		'change'        => 17.6,
	];
}

/**
 * Get average order value data.
 */
function my_get_aov_data( array $date_range, array $config ): array {
	return [
		'value'         => 148.15,
		'compare_value' => 136.46,
		'change'        => 8.6,
	];
}

/**
 * Get new customers data.
 */
function my_get_new_customers_data( array $date_range, array $config ): array {
	return [
		'value'         => 234,
		'compare_value' => 189,
		'change'        => 23.8,
	];
}

/**
 * Get revenue chart data.
 *
 * @param array $date_range Date range.
 * @param array $config     Component configuration.
 *
 * @return array Chart.js compatible data structure.
 */
function my_get_revenue_chart_data( array $date_range, array $config ): array {
	// Generate labels for date range
	$labels = [];
	$data   = [];

	$current = strtotime( $date_range['start'] );
	$end     = strtotime( $date_range['end'] );

	while ( $current <= $end ) {
		$labels[] = date_i18n( 'M j', $current );
		$data[]   = rand( 2000, 8000 ); // Example random data
		$current  = strtotime( '+1 day', $current );
	}

	return [
		'labels'   => $labels,
		'datasets' => [
			[
				'label' => __( 'Revenue', 'my-plugin' ),
				'data'  => $data,
			],
		],
	];
}

/**
 * Get orders by status chart data.
 */
function my_get_orders_by_status_data( array $date_range, array $config ): array {
	return [
		'labels'   => [
			__( 'Completed', 'my-plugin' ),
			__( 'Processing', 'my-plugin' ),
			__( 'Pending', 'my-plugin' ),
			__( 'Refunded', 'my-plugin' ),
		],
		'datasets' => [
			[
				'data'            => [ 542, 187, 89, 29 ],
				'backgroundColor' => [ '#00a32a', '#2271b1', '#dba617', '#d63638' ],
			],
		],
	];
}

/**
 * Get daily orders chart data.
 */
function my_get_daily_orders_data( array $date_range, array $config ): array {
	return [
		'labels'   => [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ],
		'datasets' => [
			[
				'label' => __( 'Orders', 'my-plugin' ),
				'data'  => [ 45, 52, 38, 61, 55, 72, 48 ],
			],
		],
	];
}

/**
 * Get top products table data.
 */
function my_get_top_products_data( array $date_range, array $config ): array {
	return [
		'rows' => [
			[
				'product'  => 'Premium Widget Pro',
				'sku'      => 'WIDGET-PRO-001',
				'quantity' => 245,
				'revenue'  => '$12,250.00',
			],
			[
				'product'  => 'Basic Widget',
				'sku'      => 'WIDGET-BASIC-001',
				'quantity' => 189,
				'revenue'  => '$4,725.00',
			],
			[
				'product'  => 'Widget Accessory Pack',
				'sku'      => 'WIDGET-ACC-001',
				'quantity' => 156,
				'revenue'  => '$2,340.00',
			],
		],
	];
}

/**
 * Get orders export count (for progress tracking).
 *
 * @param array $args Contains 'date_range' and 'filters'.
 *
 * @return int Total count of rows to export.
 */
function my_get_orders_export_count( array $args ): int {
	$date_range = $args['date_range'];
	$filters    = $args['filters'];
	
	// In real implementation, query database for count
	// Example: SELECT COUNT(*) FROM orders WHERE date BETWEEN start AND end
	
	return 1250; // Example: 1250 orders to export
}

/**
 * Get orders export data (batched).
 *
 * @param array $args Contains 'date_range', 'filters', 'offset', 'limit'.
 *
 * @return array Array of rows for this batch.
 */
function my_get_orders_export_data( array $args ): array {
	$date_range = $args['date_range'];
	$filters    = $args['filters'];
	$offset     = $args['offset'] ?? 0;
	$limit      = $args['limit'] ?? 100;
	
	// In real implementation, query with LIMIT and OFFSET
	// Example: SELECT * FROM orders WHERE date BETWEEN start AND end LIMIT $limit OFFSET $offset
	
	// Example data for this batch
	$rows = [];
	
	for ( $i = 0; $i < min( $limit, 50 ); $i++ ) { // Simulate partial batch
		$rows[] = [
			'order_id'       => (string) ( 1001 + $offset + $i ),
			'date'           => '2025-01-15',
			'status'         => 'completed',
			'customer_name'  => 'Customer ' . ( $offset + $i + 1 ),
			'email'          => 'customer' . ( $offset + $i + 1 ) . '@example.com',
			'total'          => '$' . number_format( rand( 50, 500 ), 2 ),
			'payment_method' => 'stripe',
		];
	}
	
	return $rows;
}

/**
 * Get products export count.
 */
function my_get_products_export_count( array $args ): int {
	return 340; // Example count
}

/**
 * Get products export data (batched).
 */
function my_get_products_export_data( array $args ): array {
	$offset = $args['offset'] ?? 0;
	$limit  = $args['limit'] ?? 100;
	
	$rows = [];
	
	for ( $i = 0; $i < min( $limit, 40 ); $i++ ) {
		$rows[] = [
			'product_id'    => (string) ( 100 + $offset + $i ),
			'product_name'  => 'Product ' . ( $offset + $i + 1 ),
			'sku'           => 'SKU-' . ( 100 + $offset + $i ),
			'quantity'      => rand( 10, 200 ),
			'gross_revenue' => '$' . number_format( rand( 500, 5000 ), 2 ),
			'net_revenue'   => '$' . number_format( rand( 400, 4500 ), 2 ),
		];
	}
	
	return $rows;
}

/**
 * Get customers export count.
 */
function my_get_customers_export_count( array $args ): int {
	return 890; // Example count
}

/**
 * Get customers export data (batched).
 */
function my_get_customers_export_data( array $args ): array {
	$offset = $args['offset'] ?? 0;
	$limit  = $args['limit'] ?? 100;
	
	$rows = [];
	
	for ( $i = 0; $i < min( $limit, 100 ); $i++ ) {
		$rows[] = [
			'customer_id' => (string) ( 1000 + $offset + $i ),
			'name'        => 'Customer ' . ( $offset + $i + 1 ),
			'email'       => 'customer' . ( $offset + $i + 1 ) . '@example.com',
			'orders'      => rand( 1, 50 ),
			'total_spent' => '$' . number_format( rand( 100, 5000 ), 2 ),
			'registered'  => '2024-' . sprintf( '%02d', rand( 1, 12 ) ) . '-' . sprintf( '%02d', rand( 1, 28 ) ),
		];
	}
	
	return $rows;
}
