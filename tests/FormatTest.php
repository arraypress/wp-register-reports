<?php
/**
 * Value formatting tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Format;
use ArrayPress\RegisterReports\RestApi;
use ArrayPress\RegisterReports\Traits\ComponentRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Every number a report shows goes through one of these.
 *
 * A report is read rather than edited, so a formatting mistake does not throw
 * — it prints a plausible wrong number, and nobody notices until somebody
 * reconciles it against something else. Which is the argument for pinning the
 * rules down rather than eyeballing a dashboard.
 */
final class FormatTest extends TestCase {

	/**
	 * Reach the protected formatters.
	 *
	 * @return object
	 */
	private function renderer(): object {
		return new class {
			use ComponentRenderer;

			/**
			 * @param mixed  $value     The value.
			 * @param string $format    The format.
			 * @param array  $component Component configuration.
			 *
			 * @return string
			 */
			public function format( $value, string $format, array $component = [] ): string {
				return $this->format_value( $value, $format, $component );
			}

			/**
			 * @param mixed $change The change.
			 *
			 * @return string
			 */
			public function change( $change ): string {
				return $this->format_change( $change );
			}
		};
	}

	/**
	 * Money is in minor units.
	 *
	 * An integer is taken as cents and a float as a decimal amount, which is
	 * the one ambiguity worth stating: 100 is a pound, 1.00 is also a pound,
	 * and 100.0 is a hundred pounds. Pass integers.
	 */
	public function test_currency_is_in_minor_units(): void {
		$this->assertSame( '$99.00', $this->renderer()->format( 9900, 'currency' ) );
		$this->assertSame( '$0.00', $this->renderer()->format( 0, 'currency' ) );
		$this->assertStringContainsString( '5.00', $this->renderer()->format( -500, 'currency' ) );
	}

	/**
	 * A decimal amount is read as one, whatever type it arrives as.
	 *
	 * This is the bug the rule exists for. `$wpdb` returns every column as a
	 * string, so `SUM(total)` on a DECIMAL column arrives as `'99.00'` — and
	 * the old check was `is_float()`, which asks PHP what type it is rather
	 * than what the value says. The string was cast straight to 99 and a
	 * revenue tile read $0.99. A hundredfold error, on a number nobody would
	 * think to check.
	 *
	 * @dataProvider amountProvider
	 *
	 * @param mixed  $value    Ninety-nine pounds, in some form.
	 * @param string $expected What it should print.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'amountProvider' )]
	public function test_an_amount_is_read_by_its_shape_not_its_type( $value, string $expected ): void {
		$this->assertSame(
			$expected,
			$this->renderer()->format( $value, 'currency' ),
			sprintf( '%s (%s) formatted wrongly.', var_export( $value, true ), gettype( $value ) )
		);
	}

	/**
	 * The same amount, as each type it might arrive as.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function amountProvider(): array {
		return [
			'integer cents'    => [ 9900, '$99.00' ],
			'string cents'     => [ '9900', '$99.00' ],
			'float decimal'    => [ 99.00, '$99.00' ],
			'string decimal'   => [ '99.00', '$99.00' ],
			'string with part' => [ '99.50', '$99.50' ],
			'not a number'     => [ 'nonsense', '$0.00' ],
			'nothing at all'   => [ null, '$0.00' ],
		];
	}

	/**
	 * The currency comes from the component.
	 */
	public function test_the_currency_comes_from_the_component(): void {
		$this->assertSame( '£99.00', $this->renderer()->format( 9900, 'currency', [ 'currency' => 'GBP' ] ) );
	}

	/**
	 * A percentage keeps one decimal place.
	 */
	public function test_a_percentage_keeps_one_decimal(): void {
		$this->assertSame( '12.5%', $this->renderer()->format( 12.5, 'percentage' ) );
		$this->assertSame( '0.0%', $this->renderer()->format( 0, 'percentage' ) );
	}

	/**
	 * A whole number has no decimal places, and a fractional one has two.
	 *
	 * A count of orders reading `1,204.00` is the thing this avoids.
	 */
	public function test_a_number_shows_decimals_only_when_it_has_them(): void {
		$this->assertSame( '1,204', $this->renderer()->format( 1204, 'number' ) );
		$this->assertSame( '1,204', $this->renderer()->format( 1204.0, 'number' ) );
		$this->assertSame( '12.50', $this->renderer()->format( 12.5, 'number' ) );
	}

	/**
	 * A decimal always shows two.
	 */
	public function test_a_decimal_always_shows_two(): void {
		$this->assertSame( '12.00', $this->renderer()->format( 12, 'decimal' ) );
		$this->assertSame( '12.50', $this->renderer()->format( 12.5, 'decimal' ) );
	}

	/**
	 * Zero prints as zero, in every format.
	 *
	 * A metric of nought is a fact: no orders today is not the same as no
	 * data. The kit's Display makes the same distinction for the same reason.
	 */
	public function test_zero_prints_as_zero(): void {
		foreach ( [ 'number', 'decimal', 'percentage', 'currency' ] as $format ) {
			$this->assertStringContainsString(
				'0',
				$this->renderer()->format( 0, $format ),
				sprintf( 'Zero disappeared from a %s.', $format )
			);
		}
	}

	/**
	 * An unknown format is the value, cast.
	 *
	 * Rather than empty: a report naming a format that does not exist should
	 * still show its number, so the mistake is visible as a missing currency
	 * symbol rather than a missing metric.
	 */
	public function test_an_unknown_format_is_the_value(): void {
		$this->assertSame( '42', $this->renderer()->format( 42, 'nonsense' ) );
	}

	/**
	 * A change carries its sign.
	 *
	 * A rise and a fall have to be told apart at a glance, and the minus
	 * arrives with the number while the plus does not.
	 */
	public function test_a_change_carries_its_sign(): void {
		$this->assertSame( '+12.5%', $this->renderer()->change( 12.5 ) );
		$this->assertSame( '-8.0%', $this->renderer()->change( -8 ) );
	}

	/**
	 * No change is zero, not a plus.
	 */
	public function test_no_change_has_no_sign(): void {
		$this->assertSame( '0.0%', $this->renderer()->change( 0 ) );
	}

	/**
	 * A change nobody worked out shows nothing.
	 *
	 * Which is not the same as zero: a period with nothing to compare against
	 * has no change, and printing `0.0%` would claim it held steady.
	 */
	public function test_an_absent_change_shows_nothing(): void {
		$this->assertSame( '', $this->renderer()->change( null ) );
		$this->assertSame( '', $this->renderer()->change( '' ) );
	}

	/**
	 * The page and the auto-refresh format a value the same way.
	 *
	 * They were two implementations and they had drifted: a tile read
	 * 1,204.50 on load and 1,204 after the first refresh, because the REST
	 * copy cast to int and used `number_format` where the page used
	 * `number_format_i18n`. The number appeared to change on its own, which
	 * is the worst way for a dashboard to be wrong.
	 *
	 * @dataProvider driftProvider
	 *
	 * @param mixed  $value  The value.
	 * @param string $format The format.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'driftProvider' )]
	public function test_the_page_and_the_refresh_agree( $value, string $format ): void {
		$mirror = new \ReflectionMethod( RestApi::class, 'format_value_for_api' );

		$this->assertSame(
			$this->renderer()->format( $value, $format ),
			$mirror->invoke( null, $value, $format, 'USD' ),
			sprintf( 'A %s of %s reads differently after a refresh.', $format, var_export( $value, true ) )
		);
	}

	/**
	 * The values the two used to disagree about.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function driftProvider(): array {
		return [
			'fractional number' => [ 1204.5, 'number' ],
			'whole number'      => [ 1204, 'number' ],
			'string decimal'    => [ '99.00', 'currency' ],
			'integer cents'     => [ 9900, 'currency' ],
			'a decimal'         => [ 12.5, 'decimal' ],
			'a percentage'      => [ 12.55, 'percentage' ],
			'nothing'           => [ 0, 'currency' ],
		];
	}

	/**
	 * A whole number written as a float is still whole.
	 *
	 * `1204.0` has no fractional part however it is typed, and printing
	 * `1,204.00` for a count of orders is what the rule avoids.
	 */
	public function test_a_float_with_no_fraction_is_whole(): void {
		$this->assertSame( '1,204', Format::value( 1204.0, 'number' ) );
		$this->assertSame( '1,204', Format::value( '1204.0', 'number' ) );
	}
}
