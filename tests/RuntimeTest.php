<?php
/**
 * Runtime key derivation tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Utils\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Every runtime string this library registers has to differ between two
 * Strauss-prefixed builds. Strauss rewrites namespaces and nothing else, so
 * these tests load namespace-rewritten copies of Runtime — which is exactly
 * what a prefixed build is — and assert the keys come out distinct.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Class names of the simulated builds, keyed by prefix.
	 *
	 * @var array<string, string>
	 */
	private static array $builds = [];

	/**
	 * Write and load a namespace-rewritten copy per prefix.
	 */
	public static function setUpBeforeClass(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/src/Utils/Runtime.php' );
		$dir    = sys_get_temp_dir() . '/ap-reports-runtime-' . getmypid();

		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0o777, true );
		}

		foreach ( [ 'EDDFF', 'WCR2' ] as $prefix ) {
			$file = $dir . '/' . $prefix . '.php';

			file_put_contents(
				$file,
				str_replace(
					'namespace ArrayPress\RegisterReports\Utils;',
					'namespace ' . $prefix . '\ArrayPress\RegisterReports\Utils;',
					$source
				)
			);

			require_once $file;

			self::$builds[ $prefix ] = $prefix . '\ArrayPress\RegisterReports\Utils\Runtime';
		}
	}

	/**
	 * An unprefixed install keeps the library's own identifiers.
	 */
	public function test_unprefixed_build_uses_the_library_name(): void {
		$this->assertSame( 'reports', Runtime::prefix() );
		$this->assertSame( 'reports/v1', Runtime::rest_namespace() );
		$this->assertSame( 'reports', Runtime::handle() );
		$this->assertSame( 'reports_export', Runtime::key( 'export' ) );
		$this->assertSame( 'ReportsAdmin', Runtime::js_object( 'Admin' ) );
	}

	/**
	 * A prefixed build derives its keys from the consumer's prefix.
	 */
	public function test_prefixed_build_derives_from_the_consumer_prefix(): void {
		$eddff = self::$builds['EDDFF'];

		$this->assertSame( 'eddff-reports', $eddff::prefix() );
		$this->assertSame( 'eddff-reports/v1', $eddff::rest_namespace() );
		$this->assertSame( 'eddff_reports_export', $eddff::key( 'export' ) );
		$this->assertSame( 'EddffReportsAdmin', $eddff::js_object( 'Admin' ) );
	}

	/**
	 * Two prefixed builds must not share a single runtime key.
	 *
	 * This is the property that matters: an EDD plugin and a WooCommerce
	 * plugin bundling this library on one site register nothing in common.
	 */
	public function test_two_builds_share_no_runtime_key(): void {
		$a = self::$builds['EDDFF'];
		$b = self::$builds['WCR2'];

		$methods = [
			'rest_namespace' => [],
			'prefix'         => [],
			'handle'         => [ 'chartjs' ],
			'key'            => [ 'export' ],
			'js_object'      => [ 'Admin' ],
			'hook'           => [ 'render_component' ],
		];

		foreach ( $methods as $method => $args ) {
			$this->assertNotSame(
				$a::$method( ...$args ),
				$b::$method( ...$args ),
				sprintf( 'Runtime::%s() collides between two prefixed builds.', $method )
			);
		}
	}

	/**
	 * A JS object name is written as `var <name> =`, so it has to be a legal
	 * identifier — the hyphen in a derived handle would be a syntax error.
	 */
	public function test_js_object_is_a_valid_identifier(): void {
		foreach ( self::$builds as $prefix => $class ) {
			$this->assertMatchesRegularExpression(
				'/^[A-Za-z_$][A-Za-z0-9_$]*$/',
				$class::js_object( 'Admin' ),
				sprintf( '%s produced an invalid JS identifier.', $prefix )
			);
		}
	}

	/**
	 * A transient key has to fit: WordPress stores it as an option named
	 * `_transient_<key>` in a column capped at 191 characters.
	 */
	public function test_transient_key_leaves_room_for_a_token(): void {
		foreach ( self::$builds as $class ) {
			$key = '_transient_' . $class::key( 'export' ) . '_';

			$this->assertLessThan( 100, strlen( $key ) );
		}
	}

}
