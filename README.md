# Register Reports

An analytics screen — tiles, charts, tables and a date range — from a
description of what to show.

## What it does

A reports page is the same furniture every time: a date-range control, tabs,
filters, a row of headline figures, some charts and a table or two, all
reloading together when the range changes.

None of that is the interesting part. This builds it, and asks you only for
the callbacks that fetch the numbers.

## Features

* Get a reports screen with a date-range picker and comparison periods
* Show headline figures as tiles, with the change against the previous period
* Add line, bar and pie charts without touching a charting library
* Add a data table, sortable and paged, beside them
* Group everything into tabs, each with its own filters
* Refresh in place when the range or a filter changes, without a page load
* Offer a CSV export of what is on screen, batched so it does not time out

## Installation

```bash
composer require arraypress/wp-register-reports
```

## Quick start

```php
register_reports( 'my-analytics', [
	'page_title'  => __( 'Analytics', 'my-plugin' ),
	'menu_title'  => __( 'Analytics', 'my-plugin' ),
	'parent_slug' => 'tools.php',

	'tabs'        => [
		'overview' => [ 'label' => __( 'Overview', 'my-plugin' ) ],
	],

	'components'  => [
		'revenue' => [
			'type'          => 'tile',
			'tab'           => 'overview',
			'label'         => __( 'Revenue', 'my-plugin' ),
			'value_format'  => 'currency',
			'compare'       => true,
			'data_callback' => '\MyPlugin\get_revenue',
		],
	],
] );
```

`data_callback` is handed the date range and the active filters, and returns
a number. The tile, its formatting and the comparison against the previous
period are not yours to build.

## Requirements

* PHP 8.3 or later
* WordPress 7.1 or later

## License

GPL-2.0-or-later
