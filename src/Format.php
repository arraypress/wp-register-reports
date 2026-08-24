<?php
/**
 * Value Formatting
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports;

use ArrayPress\DateUtils\Dates;

/**
 * How a report shows a number.
 *
 * There were two of these: one for the page and one for the REST response the
 * auto-refresh replaces it with. They had drifted, so a tile read 1,204.50 on
 * load and 1,204 thirty seconds later — the number appeared to change on its
 * own, which is the worst way for a dashboard to be wrong.
 *
 * A report is read rather than edited, so a formatting mistake does not throw.
 * It prints a plausible wrong number and nobody notices until somebody
 * reconciles it against something else.
 */
final class Format {

	/**
	 * A value, in the format a component asked for.
	 *
	 * @param mixed  $value    The value.
	 * @param string $format   currency, percentage, number, decimal, date, datetime.
	 * @param string $currency Currency code, for the money one.
	 *
	 * @return string
	 */
	public static function value( $value, string $format, string $currency = 'USD' ): string {
		switch ( $format ) {
			case 'currency':
				return format_currency( self::minor_units( $value ), $currency );

			case 'percentage':
				return number_format_i18n( (float) $value, 1 ) . '%';

			case 'number':
				// Decimals only when there are some: a count of orders
				// reading 1,204.00 is what this avoids.
				return self::has_fraction( $value )
					? number_format_i18n( (float) $value, 2 )
					: number_format_i18n( (int) $value );

			case 'decimal':
				return number_format_i18n( (float) $value, 2 );

			case 'date':
				return Dates::format( $value, 'date' );

			case 'datetime':
				return Dates::format( $value );

			default:
				// Not empty: a report naming a format that does not exist
				// should still show its number, so the mistake reads as a
				// missing currency symbol rather than a missing metric.
				return (string) $value;
		}
	}

	/**
	 * A change, with its sign.
	 *
	 * Null rather than zero when there is nothing to compare against: a
	 * period with no previous one has no change, and printing 0.0% would
	 * claim it held steady.
	 *
	 * @param mixed $change The change, as a percentage.
	 *
	 * @return string
	 */
	public static function change( $change ): string {
		if ( null === $change || '' === $change ) {
			return '';
		}

		return ( $change > 0 ? '+' : '' ) . number_format_i18n( (float) $change, 1 ) . '%';
	}

	/**
	 * An amount in minor units, however it arrived.
	 *
	 * A whole number is already minor units — 9900 is ninety-nine pounds. A
	 * number written with a decimal point is a decimal amount, and 99.00 is
	 * the same ninety-nine pounds.
	 *
	 * The distinction used to be `is_float()`, which asks PHP what type the
	 * value is rather than what the value says. `$wpdb` returns every column
	 * as a string, so `SUM(total)` on a DECIMAL column arrives as `'99.00'` —
	 * not a float, so it was cast straight to 99 and a revenue tile read
	 * $0.99. A hundredfold error, on a number nobody would think to check.
	 *
	 * @param mixed $value The amount.
	 *
	 * @return int
	 */
	public static function minor_units( $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return self::is_decimal_amount( $value ) ? (int) round( (float) $value * 100 ) : (int) $value;
	}

	/**
	 * Whether an amount was written as a decimal rather than as minor units.
	 *
	 * Not "has a non-zero fraction": 99.00 is a decimal amount whose fraction
	 * happens to be nought, and reading it as minor units gives 99p.
	 *
	 * Both halves are needed because the two origins look different. A float
	 * is a decimal by construction — but PHP casts 99.00 to the string "99",
	 * so looking for a point would miss it. A string comes from `$wpdb`,
	 * which returns every column as one, so `'99.00'` keeps its point and its
	 * type says nothing.
	 *
	 * @param mixed $value The amount.
	 *
	 * @return bool
	 */
	private static function is_decimal_amount( $value ): bool {
		return is_float( $value ) || str_contains( (string) $value, '.' );
	}

	/**
	 * Whether a value has digits after the point that matter.
	 *
	 * A different question from the one above, and only used for deciding
	 * whether to print decimals: 1204.0 is a whole number however it is
	 * typed, and a count of orders reading 1,204.00 is what this avoids.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private static function has_fraction( $value ): bool {
		return is_numeric( $value ) && 0.0 !== fmod( (float) $value, 1.0 );
	}
}
