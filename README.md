# WordPress Reports Registration Library

A comprehensive WordPress library for creating beautiful, feature-rich admin report pages with tiles, charts, tables,
and CSV exports.

## Features

- **Tiles** - Display key metrics with icons, colors, and auto-calculated comparison percentages
- **Charts** - Line, bar, pie, doughnut charts powered by Chart.js
- **Tables** - Sortable, paginated tables with row actions (edit, view, delete)
- **Exports** - Batched CSV exports with filters and progress tracking
- **Tab Filters** - Per-tab filters (dropdowns, checkboxes) that affect all components
- **Date Picker** - Preset ranges (Today, This Week, This Month, etc.) or custom dates
- **AJAX Refresh** - Manual refresh button or auto-refresh at configurable intervals
- **Modern Header** - Full-width header with optional logo, refresh controls, and date picker

## Requirements

- PHP 7.4+
- WordPress 5.8+

## Installation

```bash
composer require arraypress/wp-register-reports
```

## Quick Start

```php
register_reports( 'my-analytics', [
	'page_title'   => 'Analytics Dashboard',
	'menu_title'   => 'Analytics',
	'parent_slug'  => 'tools.php',
	'show_refresh' => true,

	'tabs' => [
		'overview' => [
			'label' => 'Overview',
			'icon'  => 'dashicons-dashboard',
		],
		'sales'    => [
			'label'   => 'Sales',
			'icon'    => 'dashicons-cart',
			'filters' => [
				'status' => [
					'type'    => 'select',
					'label'   => 'Status',
					'options' => [
						''          => 'All',
						'completed' => 'Completed',
						'pending'   => 'Pending',
					],
				],
			],
		],
	],

	'components' => [
		'revenue' => [
			'type'          => 'tile',
			'title'         => 'Total Revenue',
			'tab'           => 'overview',
			'icon'          => 'money-alt',
			'icon_color'    => 'green',
			'value_format'  => 'currency',
			'data_callback' => 'my_get_revenue',
		],

		'revenue_chart' => [
			'type'          => 'chart',
			'title'         => 'Revenue Trend',
			'tab'           => 'overview',
			'chart_type'    => 'line',
			'height'        => 300,
			'data_callback' => 'my_get_revenue_chart',
		],
	],
] );

// Tile callback - return value and previous_value for auto-calculated change %
function my_get_revenue( array $date_range, array $config ): array {
	return [
		'value'          => 150000,  // In cents for currency
		'previous_value' => 120000,  // Auto-calculates +25% change
	];
}

// Chart callback
function my_get_revenue_chart( array $date_range, array $config ): array {
	return [
		'labels'   => [ 'Jan', 'Feb', 'Mar', 'Apr', 'May' ],
		'datasets' => [
			[
				'label' => 'Revenue',
				'data'  => [ 1200, 1900, 3000, 5000, 4000 ],
			],
		],
	];
}
```

## Configuration Options

### Report-Level Options

```php
register_reports( 'my-reports', [
	// Basic
	'page_title'       => 'My Reports',
	'menu_title'       => 'Reports',
	'menu_slug'        => 'my-reports',
	'capability'       => 'manage_options',
	'parent_slug'      => '',                // Empty = top-level menu
	'icon'             => 'dashicons-chart-area',
	'position'         => null,

	// Header
	'show_title'       => true,
	'header_title'     => '',                // Override page_title in header
	'logo'             => '',                // URL to logo image

	// Features
	'show_tabs'        => true,
	'show_date_picker' => true,
	'show_refresh'     => true,              // Manual refresh button
	'auto_refresh'     => 0,                 // Seconds between auto-refresh (0 = disabled)

	// Exports
	'exports_columns'  => 4,                 // Number of export cards per row

	// Date presets
	'date_presets'     => [
		'today'      => 'Today',
		'yesterday'  => 'Yesterday',
		'this_week'  => 'This Week',
		'this_month' => 'This Month',
		'custom'     => 'Custom Range',
	],
	'default_preset'   => 'this_month',

	// Help screen
	'help_tabs'        => [
		'overview' => [
			'title'   => 'Overview',
			'content' => '<p>Help content here...</p>',
		],
	],

	// Data
	'tabs'             => [],
	'components'       => [],
	'exports'          => [],
] );
```

### Tab Options with Filters

Tabs can have filters that appear in a bar below the tabs. Filter values are passed to all component callbacks via
`$date_range['filters']`.

```php
'tabs' => [
	'overview'  => [
		'label' => 'Overview',
		'icon'  => 'dashicons-dashboard',
	],
	'sales'     => [
		'label'           => 'Sales',
		'icon'            => 'dashicons-chart-bar',
		'filters'         => [
			'category'    => [
				'type'    => 'select',
				'label'   => 'Category',
				'default' => '',
				'options' => [
					''            => 'All Categories',
					'electronics' => 'Electronics',
					'clothing'    => 'Clothing',
				],
			],
			'status'      => [
				'type'    => 'select',
				'label'   => 'Status',
				'options' => [
					''          => 'All',
					'completed' => 'Completed',
					'pending'   => 'Pending',
				],
			],
			'exclude_tax' => [
				'type'  => 'checkbox',
				'label' => 'Exclude Tax',
			],
		],
		'exports_columns' => 3,  // Override exports columns for this tab
	],
	'customers' => [
		'label'   => 'Customers',
		'icon'    => 'dashicons-groups',
		'filters' => [
			'country' => [
				'type'    => 'select',
				'label'   => 'Country',
				'options' => [
					''   => 'All Countries',
					'US' => 'United States',
					'UK' => 'United Kingdom',
				],
			],
			'type'    => [
				'type'    => 'select',
				'label'   => 'Type',
				'options' => [
					''          => 'All',
					'new'       => 'New Customers',
					'returning' => 'Returning',
				],
			],
		],
	],
],
```

**Filter Types:**

- `select` - Dropdown with options
- `checkbox` - Toggle checkbox
- `text` - Text input with optional placeholder

**Accessing Filters in Callbacks:**

```php
function my_callback( array $date_range, array $config ): array {
	$filters  = $date_range['filters'] ?? [];
	$category = $filters['category'] ?? '';
	$status   = $filters['status'] ?? '';

	// Adjust query based on filters...

	return [ 'value' => $result ];
}
```

### Tile Component

```php
'my_tile' => [
	'type'          => 'tile',
	'title'         => 'Total Users',
	'tab'           => 'overview',
	'icon'          => 'admin-users',      // Shorthand or 'dashicons-admin-users'
	'icon_color'    => 'blue',             // blue, green, red, orange, purple, gray
	'value_format'  => 'number',           // number, currency, percentage, decimal, text
	'currency'      => 'USD',              // For currency format
	'data_callback' => 'my_callback',
],
```

**Tile Callback - Auto-calculated change:**

```php
function my_callback( array $date_range, array $config ): array {
	return [
		'value'          => 1500,
		'previous_value' => 1200,  // Auto-calculates +25% up
	];
}
```

**Tile Callback - Manual change:**

```php
function my_callback( array $date_range, array $config ): array {
	return [
		'value'            => 1500,
		'change'           => 25,        // Percentage
		'change_direction' => 'up',      // 'up', 'down', 'neutral'
	];
}
```

### Tiles Group Component

Group multiple tiles with a shared title:

```php
'key_metrics' => [
	'type'    => 'tiles_group',
	'title'   => 'Key Metrics',
	'tab'     => 'overview',
	'columns' => 4,  // 2, 3, or 4
	'tiles'   => [
		'users'   => [
			'title'         => 'Total Users',
			'icon'          => 'admin-users',
			'icon_color'    => 'blue',
			'value_format'  => 'number',
			'data_callback' => 'get_total_users',
		],
		'revenue' => [
			'title'         => 'Revenue',
			'icon'          => 'money-alt',
			'icon_color'    => 'green',
			'value_format'  => 'currency',
			'currency'      => 'GBP',
			'data_callback' => 'get_revenue',
		],
	],
],
```

### Chart Component

```php
'my_chart' => [
	'type'          => 'chart',
	'title'         => 'Sales Over Time',
	'tab'           => 'overview',
	'chart_type'    => 'line',   // line, bar, pie, doughnut
	'height'        => 300,
	'stacked'       => false,    // For bar charts
	'data_callback' => 'my_chart_callback',
],
```

**Chart Callback:**

```php
function my_chart_callback( array $date_range, array $config ): array {
	return [
		'labels'   => [ 'Jan', 'Feb', 'Mar' ],
		'datasets' => [
			[
				'label' => 'Sales',
				'data'  => [ 100, 200, 150 ],
			],
			[
				'label' => 'Returns',
				'data'  => [ 10, 15, 8 ],
			],
		],
	];
}
```

### Table Component with Row Actions

```php
'my_table' => [
	'type'          => 'table',
	'title'         => 'Recent Orders',
	'tab'           => 'overview',
	'sortable'      => true,
	'paginated'     => true,
	'per_page'      => 10,
	'columns'       => [
		'id'       => 'Order ID',
		'customer' => 'Customer',
		'total'    => [
			'label'  => 'Total',
			'format' => 'currency',
		],
		'status'   => 'Status',
	],
	'row_actions'   => [
		'view'   => [
			'label' => 'View',
			'url'   => admin_url( 'admin.php?page=orders&id={id}' ),
		],
		'edit'   => [
			'label'        => 'Edit',
			'url_callback' => function ( $row ) {
				return get_edit_post_link( $row['post_id'] );
			},
		],
		'delete' => [
			'label'   => 'Delete',
			'url'     => '#',
			'class'   => 'delete',      // Red styling
			'confirm' => 'Are you sure?',
			'target'  => '_blank',      // Optional
		],
	],
	'data_callback' => 'my_table_callback',
],
```

**Row Actions Features:**

- `url` - URL template with `{column}` placeholders
- `url_callback` - Function receiving `$row` array, returns URL
- `class` - CSS class (`delete` or `trash` = red styling)
- `confirm` - JavaScript confirm dialog message
- `target` - Link target (`_blank` for new tab)

**Table Callback:**

```php
function my_table_callback( array $date_range, array $config ): array {
	return [
		[ 'id' => 1, 'customer' => 'John', 'total' => 9900, 'status' => 'Completed' ],
		[ 'id' => 2, 'customer' => 'Jane', 'total' => 15000, 'status' => 'Pending' ],
	];
}
```

### Export Configuration

```php
'exports' => [
	'orders_export' => [
		'title'          => 'Export Orders',
		'description'    => 'Download all orders as CSV.',
		'tab'            => 'exports',
		'filename'       => 'orders',  // Or use callback
		'headers'        => [
			'id'       => 'Order ID',
			'customer' => 'Customer',
			'total'    => 'Total',
		],
		'filters'        => [
			'status' => [
				'type'    => 'select',
				'label'   => 'Status',
				'options' => [
					''          => 'All',
					'completed' => 'Completed',
					'pending'   => 'Pending',
				],
			],
		],
		'total_callback' => 'get_total_orders',
		'data_callback'  => 'get_orders_batch',
	],
],
```

**Dynamic Filename:**

```php
'filename' => function ( array $date_range, array $config ): string {
	return 'orders-' . $date_range['start_local'] . '-to-' . $date_range['end_local'];
},
```

**Export Callbacks:**

```php
function get_total_orders( array $args ): int {
	$filters = $args['filters'] ?? [];

	// Return total count
	return 1000;
}

function get_orders_batch( array $args ): array {
	$offset  = $args['offset'];
	$limit   = $args['limit'];
	$filters = $args['filters'] ?? [];

	// Return batch of rows
	return [
		[ 'id' => 1, 'customer' => 'John', 'total' => '$99.00' ],
		// ...
	];
}
```

## Date Range

The `$date_range` array passed to callbacks:

```php
[
	'start'       => '2024-01-01 00:00:00',  // UTC
	'end'         => '2024-01-31 23:59:59',  // UTC
	'start_local' => '2024-01-01',           // Local Y-m-d
	'end_local'   => '2024-01-31',           // Local Y-m-d
	'preset'      => 'this_month',           // Selected preset
	'filters'     => [                       // Tab filter values
		'category' => 'electronics',
		'status'   => '',
	],
]
```

## Value Formats

| Format       | Description               | Example  |
|--------------|---------------------------|----------|
| `number`     | Integer with commas       | 1,234    |
| `decimal`    | Two decimal places        | 1,234.56 |
| `currency`   | Currency (value in cents) | $12.34   |
| `percentage` | With % symbol             | 12.3%    |
| `text`       | Raw text                  | Active   |

## Icon Colors

- `blue` - #2271b1
- `green` - #00a32a
- `red` - #d63638
- `orange` - #dba617
- `purple` - #8b5cf6
- `gray` - #646970

## Auto-Refresh

```php
register_reports( 'live-dashboard', [
	'auto_refresh' => 30,   // Refresh every 30 seconds
	'show_refresh' => true, // Also show manual button
] );
```

- Components refresh via AJAX without page reload
- "Last updated: Xs ago" timestamp in header
- Auto-refresh pauses when browser tab is hidden
- Manual refresh resets the timer

## Chart.js

Charts require Chart.js v4.5.1 at `assets/js/chart.umd.min.js`.

Download: https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js

## License

GPL-2.0-or-later

## Credits

Developed by [ArrayPress](https://arraypress.com/)