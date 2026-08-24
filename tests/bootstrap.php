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

/*
 * The kit's WordPress stubs. This library depends on it, so they are here,
 * and writing a second set would mean two answers to "what does esc_attr do
 * in a test" — which is how a suite ends up passing on markup that would not
 * escape in production.
 *
 * Required before the autoloader for the same reason ABSPATH is.
 */
require_once dirname( __DIR__ ) . '/vendor/arraypress/wp-field-kit/tests/stubs.php';

/*
 * The handful this library reaches for that a field kit has no reason to.
 */
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		return array_merge( $defaults, (array) $args );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		return $url . ( str_contains( (string) $url, '?' ) ? '&' : '?' ) . http_build_query( (array) $args );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return [ 'basedir' => sys_get_temp_dir() . '/rp-uploads' ];
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		return is_dir( $dir ) || mkdir( $dir, 0777, true );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( str_repeat( 'abcdef0123456789', 4 ), 0, (int) $length );
	}
}

if ( ! function_exists( 'format_currency' ) ) {
	function format_currency( $cents, $currency = 'USD' ) {
		return sprintf( '%s%s', 'USD' === $currency ? '$' : $currency . ' ', number_format( $cents / 100, 2 ) );
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
