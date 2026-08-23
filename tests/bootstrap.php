<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

/*
 * Several dependencies guard their files-autoloaded entrypoints with
 * `defined( 'ABSPATH' ) || exit;`. Composer runs those on require of the
 * autoloader, so without this the whole suite exits before PHPUnit prints
 * anything — status 0, no output, indistinguishable from a pass.
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
