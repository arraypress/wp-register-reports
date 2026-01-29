# WP Register Reports

A WordPress library for registering report pages with charts, tiles, tables, and CSV export functionality. Built with a similar architecture to the settings fields library, providing a clean registration API for creating analytics dashboards.

## Features

- **Modern Header** - Branded header with logo, title, tabs, and date picker
- **Date Range Picker** - Presets (Today, Yesterday, This Week, Last Week, etc.) plus custom range
- **Tiles/Stat Cards** - Display KPIs with comparison data and trend indicators
- **Charts** - Line, bar, pie, doughnut, and area charts via Chart.js
- **Tables** - Sortable, searchable, paginated data tables
- **CSV Exports** - Configurable exports with filter metaboxes
- **REST API** - AJAX component refresh support
- **Responsive** - Mobile-friendly design
- **UTC/Timezone Aware** - Proper handling of date ranges with local display and UTC storage

## Requirements

- PHP 7.4+
- WordPress 5.9+
- Composer

## Installation

```bash
composer require arraypress/wp-register-reports
```

## Dependencies

This library uses the following ArrayPress libraries:

- **[wp-date-utils](https://github.com/arraypress/wp-date-utils)** - UTC/local timezone handling for date ranges
- **[wp-currencies](https://github.com/arraypress/wp-currencies)** - Currency formatting with Stripe compatibility
- **[wp-composer-assets](https://github.com/arraypress/wp-composer-assets)** - Asset management for Composer packages

## Basic Usage

```php
use ArrayPress\RegisterReports\Reports;

new Reports( 'my-reports', [
    'page_title'   => 'Sales Reports',
    'menu_title'   => 'Reports',
    'parent_slug'  => 'woocommerce',
    'capability'   => 'manage_woocommerce',
    
    'tabs' => [
        'overview' => 'Overview',
        'products' => [
            'label' => 'Products',
            'icon'  => 'dashicons-products',
        ],
    ],
    
    'components' => [
        'revenue' => [
            'type'          => 'tile',
            'title'         => 'Total Revenue',
            'tab'           => 'overview',
            'icon'          => 'dashicons-money-alt',
            'value_format'  => 'currency',
            'compare'       => true,
            'data_callback' => 'my_get_revenue_data',
        ],
        
        'sales_chart' => [
            'type'          => 'chart',
            'title'         => 'Sales Over Time',
            'tab'           => 'overview',
            'chart_type'    => 'line',
            'height'        => 350,
            'data_callback' => 'my_get_sales_chart_data',
        ],
    ],
] );
```

## Configuration Options

### Page Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `page_title` | string | 'Reports' | Page title in browser |
| `menu_title` | string | 'Reports' | Menu item text |
| `menu_slug` | string | ID | URL slug |
| `capability` | string | 'manage_options' | Required capability |
| `parent_slug` | string | '' | Parent menu (empty for top-level) |
| `icon` | string | 'dashicons-chart-area' | Menu icon |
| `position` | int | null | Menu position |

### Header Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `logo` | string | '' | Logo image URL |
| `header_title` | string | page_title | Header title text |
| `show_title` | bool | true | Show title in header |
| `show_tabs` | bool | true | Show tab navigation |
| `show_date_picker` | bool | true | Show date range picker |

### Date Range Settings

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `date_presets` | array | [...] | Available presets |
| `default_preset` | string | 'this_month' | Default selected preset |

Available presets: `today`, `yesterday`, `this_week`, `last_week`, `last_7_days`, `last_14_days`, `last_30_days`, `this_month`, `last_month`, `this_quarter`, `last_quarter`, `this_year`, `last_year`, `all_time`, `custom`

## Components

### Tiles

Display KPI stat cards with optional comparison data:

```php
'revenue_tile' => [
    'type'           => 'tile',
    'title'          => 'Revenue',
    'tab'            => 'overview',
    'icon'           => 'dashicons-money-alt',
    'color'          => 'green', // blue, green, red, orange, purple, gray
    'value_format'   => 'currency', // number, currency, percentage
    'compare'        => true,
    'trend_direction'=> 'up_is_good', // up_is_good, down_is_good
    'data_callback'  => function( $date_range, $config ) {
        return [
            'value'         => 12500.00,
            'compare_value' => 10000.00, // Previous period
            'change'        => 25.0,     // Percentage change
        ];
    },
]
```

### Tiles Group

Group multiple tiles with configurable columns:

```php
'stats_group' => [
    'type'    => 'tiles_group',
    'title'   => 'Key Metrics',
    'tab'     => 'overview',
    'columns' => 4, // 2, 3, 4, 5, or 6
    'tiles'   => [
        'metric1' => [ ... ],
        'metric2' => [ ... ],
    ],
]
```

### Charts

Create Chart.js visualizations:

```php
'sales_chart' => [
    'type'       => 'chart',
    'title'      => 'Sales Over Time',
    'tab'        => 'overview',
    'chart_type' => 'line', // line, bar, pie, doughnut, area
    'height'     => 350,
    'width'      => 'full', // full, half, third, quarter, two-thirds
    'options'    => [
        'legend'         => true,
        'legendPosition' => 'top', // top, right, bottom, left
        'xAxisLabel'     => 'Date',
        'yAxisLabel'     => 'Revenue',
        'stacked'        => false,
        'beginAtZero'    => true,
    ],
    'data_callback' => function( $date_range, $config ) {
        return [
            'labels'   => [ 'Jan', 'Feb', 'Mar' ],
            'datasets' => [
                [
                    'label' => 'Sales',
                    'data'  => [ 100, 200, 150 ],
                ],
            ],
        ];
    },
]
```

### Tables

Create sortable, searchable data tables:

```php
'products_table' => [
    'type'       => 'table',
    'title'      => 'Top Products',
    'tab'        => 'products',
    'columns'    => [
        'name'    => 'Product Name',
        'sku'     => 'SKU',
        'sold'    => 'Qty Sold',
        'revenue' => 'Revenue',
    ],
    'sortable'   => true,
    'searchable' => true,
    'paginated'  => true,
    'per_page'   => 20,
    'data_callback' => function( $date_range, $config ) {
        return [
            'rows' => [
                [ 'name' => 'Widget', 'sku' => 'W001', 'sold' => 50, 'revenue' => '$500' ],
                // ...
            ],
        ];
    },
]
```

### HTML

Custom HTML content:

```php
'custom_content' => [
    'type'    => 'html',
    'title'   => 'Custom Section',
    'tab'     => 'overview',
    'content' => '<p>Custom HTML content here</p>',
    // Or use a callback:
    'render_callback' => function( $date_range, $config ) {
        echo '<p>Dynamic content</p>';
    },
]
```

## Exports

Configure CSV exports with optional filters:

```php
'exports' => [
    'orders_export' => [
        'title'       => 'Export Orders',
        'description' => 'Download orders as CSV',
        'tab'         => 'exports',
        'filename'    => 'orders',
        'icon'        => 'dashicons-download',
        'columns'     => [
            'order_id' => 'Order ID',
            'date'     => 'Date',
            'total'    => 'Total',
        ],
        'filters'     => [
            'status' => [
                'type'        => 'select',
                'label'       => 'Status',
                'placeholder' => 'All Statuses',
                'options'     => [
                    'completed'  => 'Completed',
                    'processing' => 'Processing',
                ],
            ],
            'date_range' => [
                'type'  => 'daterange',
                'label' => 'Custom Date Range',
            ],
        ],
        'data_callback' => function( $date_range, $filters, $export ) {
            return [
                [ 'order_id' => '1001', 'date' => '2025-01-15', 'total' => '$99.00' ],
                // ...
            ];
        },
    ],
]
```

### Filter Types

- `select` - Dropdown select
- `multiselect` - Multi-select dropdown
- `text` - Text input
- `date` - Single date picker
- `daterange` - Start/end date range
- `checkbox` - Checkbox group

## Data Callbacks

All data callbacks receive:

1. `$date_range` - Array with `start` and `end` dates (Y-m-d format)
2. `$config` - Component configuration array
3. `$filters` (exports only) - Selected filter values

### Tile Callback Return

```php
[
    'value'         => 12500,      // Required: Current value
    'compare_value' => 10000,      // Optional: Previous period value
    'change'        => 25.0,       // Optional: Percentage change
]
```

### Chart Callback Return

```php
[
    'labels'   => [ 'Label 1', 'Label 2', ... ],
    'datasets' => [
        [
            'label'           => 'Dataset 1',
            'data'            => [ 10, 20, 30 ],
            'backgroundColor' => '#3b82f6', // Optional
            'borderColor'     => '#3b82f6', // Optional
        ],
    ],
]
```

### Table Callback Return

```php
[
    'rows' => [
        [ 'col1' => 'Value 1', 'col2' => 'Value 2' ],
        // ...
    ],
]
```

## Helper Functions

```php
use function ArrayPress\RegisterReports\register_reports;
use function ArrayPress\RegisterReports\get_reports;
use function ArrayPress\RegisterReports\reports_exists;

// Register a reports page
$reports = register_reports( 'my-reports', [ ... ] );

// Get a registered instance
$reports = get_reports( 'my-reports' );

// Check if exists
if ( reports_exists( 'my-reports' ) ) {
    // ...
}
```

### Date & Currency Utilities

For date and currency formatting, use the dedicated libraries:

```php
use ArrayPress\DateUtils\Dates;
use function format_currency;

// Date range for database queries (returns UTC)
$range = Dates::get_range( 'this_month' );
// ['start' => '2025-01-01 00:00:00', 'end' => '2025-01-31 23:59:59']

// Format UTC date for display (converts to local timezone)
echo Dates::format( $utc_date, 'date' );

// Format currency (amounts in cents)
echo format_currency( 1999, 'USD' ); // $19.99
```

## Registry

Access registered reports:

```php
use ArrayPress\RegisterReports\Registry;

// Get a reports instance
$reports = Registry::get( 'my-reports' );

// Check if exists
if ( Registry::has( 'my-reports' ) ) {
    // ...
}

// Get all registered reports
$all = Registry::all();
```

## REST API

The library registers REST endpoints for AJAX refresh:

- `GET /wp-json/reports/v1/component` - Refresh single component
- `GET /wp-json/reports/v1/tab` - Refresh entire tab
- `POST /wp-json/reports/v1/export` - Generate export file

## Styling

The library includes responsive CSS. Custom styles can be added:

```css
/* Target specific report */
.reports-wrap[data-report-id="my-reports"] {
    /* Custom styles */
}

/* Custom tile colors */
.reports-tile-icon.color-custom {
    color: #ff6b6b;
}
```

## License

GPL-2.0-or-later
