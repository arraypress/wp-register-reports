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
 * Declared before the kit's stubs, which have a `current_user_can` that always
 * says yes. Fine for a field library; useless for testing that a REST endpoint
 * refuses somebody, which is the one thing about it worth testing.
 *
 * $GLOBALS['rp_caps'] is the list of capabilities the current user has. Null
 * means all of them, which keeps every other test from having to care.
 */
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		$allowed = $GLOBALS['rp_caps'] ?? null;

		return null === $allowed || in_array( $capability, (array) $allowed, true );
	}
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

/*
 * What date utils reaches for and nothing else declares. Resolving a date
 * range is on the way in to every REST call, so without these the endpoints
 * are untestable rather than one date assertion being wrong.
 *
 * Fixed to a known moment rather than to now: a suite whose answers depend on
 * the day it runs is a suite that fails on the first of the month.
 */
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql', $gmt = 0 ) {
		return 'timestamp' === $type ? 1756080000 : gmdate( 'Y-m-d H:i:s', 1756080000 );
	}
}

if ( ! function_exists( 'get_gmt_from_date' ) ) {
	function get_gmt_from_date( $date, $format = 'Y-m-d H:i:s' ) {
		return gmdate( $format, strtotime( (string) $date ) ?: 1756080000 );
	}
}

if ( ! function_exists( 'get_date_from_gmt' ) ) {
	function get_date_from_gmt( $date, $format = 'Y-m-d H:i:s' ) {
		return gmdate( $format, strtotime( (string) $date ) ?: 1756080000 );
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() {
		return 'UTC';
	}
}

if ( ! function_exists( 'format_currency' ) ) {
	function format_currency( $cents, $currency = 'USD' ) {
		return sprintf( '%s%s', 'USD' === $currency ? '$' : $currency . ' ', number_format( $cents / 100, 2 ) );
	}
}

/*
 * Filters, which the kit's stubs record but never run. This library applies
 * its own and expects the value back.
 */
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['fk_filters'][ $hook ] ?? [] as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['fk_actions'][ $hook ] ?? [] as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $text ) {
		return str_replace( [ '\\', "'", '"', "\n", "\r" ], [ '\\\\', "\\'", '\\"', '', '' ], (string) $text );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['rp_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expires = 0 ) {
		$GLOBALS['rp_transients'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['rp_transients'][ $key ] );

		return true;
	}
}

/*
 * The REST classes, to the extent this library uses them. Only what the
 * permission callbacks touch: a request is a bag of parameters and an error is
 * a code, a message and a status.
 */
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		/**
		 * @var array<string, mixed>
		 */
		private array $params = [];

		/**
		 * @param string $method The method.
		 * @param string $route  The route.
		 */
		public function __construct( string $method = 'GET', string $route = '' ) {
		}

		/**
		 * @param string $key   Parameter name.
		 * @param mixed  $value Parameter value.
		 *
		 * @return void
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * @param string $key Parameter name.
		 *
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_params(): array {
			return $this->params;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		/**
		 * @var mixed
		 */
		private $data;

		/**
		 * @param mixed $data   The payload.
		 * @param int   $status HTTP status.
		 */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data = $data;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		/**
		 * @var array<string, mixed>
		 */
		private array $data;

		/**
		 * @var string
		 */
		private string $code;

		/**
		 * @var string
		 */
		private string $message;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', $data = [] ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = (array) $data;
		}

		/**
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
