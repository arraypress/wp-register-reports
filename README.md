# WordPress Reports Registration Library

A comprehensive WordPress library for creating beautiful, feature-rich admin report pages with tiles, charts, tables, and CSV exports.

## Features

- **Tiles** - Display key metrics with icons, colors, comparison percentages, and period labels
- **Charts** - Line, bar, pie, doughnut charts powered by Chart.js
- **Tables** - Sortable, paginated tables with row actions
- **Exports** - Batched CSV exports with filters and progress tracking
- **Date Picker** - Preset ranges (Today, This Week, This Month, etc.) or custom dates
- **Tabbed Interface** - Organize reports into logical sections
- **AJAX Refresh** - Manual or auto-refresh without page reload
- **EDD-style Header** - Modern full-width header with logo support

## Requirements

- PHP 7.4+
- WordPress 5.8+
- Composer dependencies:
  - `arraypress/wp-composer-assets`
  - `arraypress/wp-date-utils`
  - `arraypress/wp-currencies`

## Installation

```bash
composer require arraypress/wp-register-reports
```

## Quick Start

```php
use function ArrayPress\RegisterReports\register_reports;

register_reports( 'my-analytics', [
    'page_title'  => 'Analytics Dashboard',
    'menu_title'  => 'Analytics',
    'parent_slug' => 'tools.php',
    'capability'  => 'manage_options',
    
    'tabs' => [
        'overview' => [
            'label' => 'Overview',
            'icon'  => 'dashicons-chart-area',
        ],
        'sales' => [
            'label' => 'Sales',
            'icon'  => 'dashicons-cart',
        ],
    ],
    
    'components' => [
        'total_revenue' => [
            'type'          => 'tile',
            'title'         => 'Total Revenue',
            'tab'           => 'overview',
            'icon'          => 'money-alt',  // or 'dashicons-money-alt'
            'icon_color'    => 'green',
            'value_format'  => 'currency',
            'currency'      => 'USD',
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

// Tile callback
function my_get_revenue( array $date_range, array $config ): array {
    // $date_range contains: start, end, start_local, end_local, preset
    // $config contains the component configuration
    
    return [
        'value'          => 15000,  // Current value (in cents for currency)
        'previous_value' => 12000,  // Optional: auto-calculates change %
    ];
}

// Chart callback
function my_get_revenue_chart( array $date_range, array $config ): array {
    return [
        'labels'   => ['Jan', 'Feb', 'Mar', 'Apr', 'May'],
        'datasets' => [
            [
                'label' => 'Revenue',
                'data'  => [1200, 1900, 3000, 5000, 4000],
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
    'menu_slug'        => 'my-reports',      // Auto-generated from ID if not set
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
    'show_refresh'     => true,              // Show manual refresh button
    'auto_refresh'     => 0,                 // Seconds between auto-refresh (0 = disabled)
    
    // Date presets (or use defaults from wp-date-utils)
    'date_presets'     => [
        'today'      => 'Today',
        'yesterday'  => 'Yesterday',
        'this_week'  => 'This Week',
        'last_7'     => 'Last 7 Days',
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
    'help_sidebar'     => '<p>Sidebar content</p>',
    
    // Data
    'tabs'       => [],
    'components' => [],
    'exports'    => [],
] );
```

### Tab Options

```php
'tabs' => [
    'overview' => [
        'label'           => 'Overview',
        'icon'            => 'dashicons-chart-area',
        'render_callback' => null,  // Optional: fully custom tab rendering
    ],
],
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

**Tile Callback Return:**
```php
function my_callback( array $date_range, array $config ): array {
    return [
        'value'            => 1234,
        'previous_value'   => 1100,       // Optional: auto-calculates change
        // OR manually specify:
        'change'           => 12.2,       // Percentage
        'change_direction' => 'up',       // 'up', 'down', 'neutral'
    ];
}
```

### Chart Component

```php
'my_chart' => [
    'type'          => 'chart',
    'title'         => 'Sales Over Time',
    'tab'           => 'overview',
    'chart_type'    => 'line',             // line, bar, pie, doughnut
    'height'        => 300,
    'width'         => 'full',             // full, half, third, quarter
    'data_callback' => 'my_chart_callback',
],
```

**Chart Callback Return:**
```php
function my_chart_callback( array $date_range, array $config ): array {
    return [
        'labels'   => ['Jan', 'Feb', 'Mar'],
        'datasets' => [
            [
                'label'           => 'Sales',
                'data'            => [100, 200, 150],
                'backgroundColor' => '#3b82f6',  // Optional
                'borderColor'     => '#3b82f6',  // Optional
            ],
        ],
    ];
}
```

### Table Component

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
            'label'    => 'Total',
            'format'   => 'currency',
            'sortable' => true,
        ],
    ],
    'row_actions'   => [
        'view' => [
            'label' => 'View',
            'url'   => admin_url( 'admin.php?page=orders&id={id}' ),
        ],
        'edit' => [
            'label'        => 'Edit',
            'url_callback' => function( $row ) {
                return get_edit_post_link( $row['post_id'] );
            },
        ],
        'delete' => [
            'label'   => 'Delete',
            'url'     => '#',
            'class'   => 'delete',
            'confirm' => 'Are you sure?',
        ],
    ],
    'data_callback' => 'my_table_callback',
],
```

**Table Callback Return:**
```php
function my_table_callback( array $date_range, array $config ): array {
    return [
        'rows' => [
            ['id' => 1, 'customer' => 'John Doe', 'total' => 9900],
            ['id' => 2, 'customer' => 'Jane Doe', 'total' => 15000],
        ],
    ];
    // Or just return the rows array directly
}
```

### Tiles Group Component

Group multiple tiles together with a shared title and column layout:

```php
'key_metrics' => [
    'type'    => 'tiles_group',
    'title'   => 'Key Metrics',
    'tab'     => 'overview',
    'columns' => 4,  // 2, 3, or 4
    'tiles'   => [
        'users' => [
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
            'data_callback' => 'get_revenue',
        ],
    ],
],
```

### Export Configuration

```php
'exports' => [
    'orders_export' => [
        'title'          => 'Export Orders',
        'description'    => 'Download all orders as CSV.',
        'tab'            => 'exports',
        'filename'       => 'orders',  // Or use callback for dynamic names
        'batch_size'     => 100,
        'total_callback' => 'get_total_orders',
        'data_callback'  => 'get_orders_batch',
        'headers'        => [
            'id'       => 'Order ID',
            'customer' => 'Customer',
            'total'    => 'Total',
            'date'     => 'Date',
        ],
        'filters'        => [
            'status' => [
                'type'    => 'select',
                'label'   => 'Status',
                'options' => [
                    ''          => 'All Statuses',
                    'completed' => 'Completed',
                    'pending'   => 'Pending',
                ],
            ],
        ],
    ],
],
```

**Export Callbacks:**
```php
// Return total count for progress calculation
function get_total_orders( array $args ): int {
    // $args contains: date_range, filters
    return 1000;
}

// Return batch of data
function get_orders_batch( array $args ): array {
    // $args contains: date_range, filters, offset, limit
    $offset = $args['offset'];
    $limit  = $args['limit'];
    
    return [
        ['id' => 1, 'customer' => 'John', 'total' => '$99.00', 'date' => '2024-01-15'],
        // ...
    ];
}
```

**Dynamic Filename:**
```php
'filename' => function( array $date_range, array $config ): string {
    return 'orders-' . $date_range['start_local'] . '-to-' . $date_range['end_local'];
},
```

## Date Range

The `$date_range` array passed to callbacks contains:

```php
[
    'start'       => '2024-01-01 00:00:00',  // UTC
    'end'         => '2024-01-31 23:59:59',  // UTC
    'start_local' => '2024-01-01',           // Local date (Y-m-d)
    'end_local'   => '2024-01-31',           // Local date (Y-m-d)
    'preset'      => 'this_month',           // Selected preset key
]
```

## Value Formats

| Format | Description | Example Output |
|--------|-------------|----------------|
| `number` | Integer with thousands separator | 1,234 |
| `decimal` | Two decimal places | 1,234.56 |
| `currency` | Currency format (value in cents) | $12.34 |
| `percentage` | Percentage with one decimal | 12.3% |
| `text` | Raw text output | Any text |
| `date` | Formatted date | Jan 15, 2024 |
| `datetime` | Formatted datetime | Jan 15, 2024 3:30 PM |

## Icon Colors

Available colors for tile icons:
- `blue` - Blue (#2271b1)
- `green` - Green (#00a32a)
- `red` - Red (#d63638)
- `orange` - Orange (#dba617)
- `purple` - Purple (#8b5cf6)
- `gray` - Gray (#646970)

## Auto-Refresh

Enable auto-refresh to periodically update all components:

```php
register_reports( 'live-dashboard', [
    'auto_refresh' => 30,  // Refresh every 30 seconds
    'show_refresh' => true, // Also show manual refresh button
    // ...
] );
```

When enabled:
- Components refresh via AJAX without page reload
- "Last updated: Xs ago" timestamp shown in header
- Auto-refresh pauses when browser tab is hidden
- Manual refresh button resets the timer

## Helper Functions

```php
use function ArrayPress\RegisterReports\register_reports;
use function ArrayPress\RegisterReports\get_reports;

// Register a report
register_reports( 'my-reports', $config );

// Get a registered report instance
$report = get_reports( 'my-reports' );
```

## Styling

The library includes comprehensive CSS that follows WordPress admin styling conventions. Key CSS classes:

- `.reports-wrap` - Main wrapper
- `.reports-header` - Full-width header
- `.reports-tile` - Individual tile
- `.reports-chart-wrapper` - Chart container
- `.reports-table-wrapper` - Table container
- `.reports-export-card` - Export card

## Chart.js

Charts are powered by Chart.js v4.5.1. The library expects the Chart.js file at:
```
assets/js/chart.umd.min.js
```

Download from: https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js

## License

GPL-2.0-or-later

## Credits

Developed by [ArrayPress](https://arraypress.com/)
