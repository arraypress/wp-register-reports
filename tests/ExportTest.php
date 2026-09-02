<?php
/**
 * Export tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Traits\ExportHandler;
use PHPUnit\Framework\TestCase;

/**
 * A report exports to CSV, and a CSV is a text file right up until somebody
 * opens it in Excel.
 *
 * At that point a cell beginning `=`, `+`, `-`, `@` or `|` is a formula and runs.
 * The rows come out of a database anybody with a checkout form can write to,
 * so an exported customer name is attacker-controlled text landing in a
 * finance person's spreadsheet — which is the whole of CSV injection, and it
 * was live here.
 */
final class ExportTest extends TestCase {

	/**
	 * Somewhere to write.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * A file per test.
	 */
	protected function setUp(): void {
		$this->path = (string) tempnam( sys_get_temp_dir(), 'rp' );
	}

	/**
	 * Clean up.
	 */
	protected function tearDown(): void {
		if ( '' !== $this->path && file_exists( $this->path ) ) {
			unlink( $this->path );
		}
	}

	/**
	 * An object with the export trait on it.
	 *
	 * @return object
	 */
	private function exporter(): object {
		return new class {
			use ExportHandler;
		};
	}

	/**
	 * Write some rows and read the file back as text.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param array<string, string>            $headers Optional headers.
	 *
	 * @return string
	 */
	private function write( array $rows, array $headers = [] ): string {
		$this->exporter()->write_csv_batch( $this->path, $rows, true, $headers );

		// Past the byte-order mark, which is there for Excel's benefit.
		return ltrim( (string) file_get_contents( $this->path ), "\xEF\xBB\xBF" );
	}

	/**
	 * Write some rows and read the cells back.
	 *
	 * Parsed rather than matched as text: whether a given field ends up
	 * quoted is the CSV writer's business, and asserting on it would be
	 * testing PHP. What matters is the value a spreadsheet reads out.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows.
	 * @param array<string, string>            $headers Optional headers.
	 *
	 * @return array<int, array<int, string|null>>
	 */
	private function cells( array $rows, array $headers = [] ): array {
		$this->exporter()->write_csv_batch( $this->path, $rows, true, $headers );

		// fgetcsv rather than splitting on newlines: a value may contain one,
		// and a parser that does not know that reads half a cell as a row.
		$handle = fopen( $this->path, 'r' );
		$parsed = [];

		// Past the byte-order mark, which would otherwise be part of the
		// first header.
		fseek( $handle, 3 );

		while ( false !== ( $row = fgetcsv( $handle, 0, ',', '"', '' ) ) ) {
			$parsed[] = $row;
		}

		fclose( $handle );

		return $parsed;
	}

	/**
	 * A cell that would be a formula is written as text.
	 *
	 * @dataProvider formulaProvider
	 *
	 * @param string $cell A cell a spreadsheet would execute.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'formulaProvider' )]
	public function test_a_formula_is_written_as_text( string $cell ): void {
		$this->assertSame(
			"'" . $cell,
			$this->cells( [ [ 'name' => $cell ] ] )[1][0],
			sprintf( '%s was left executable.', $cell )
		);
	}

	/**
	 * One of each character a spreadsheet acts on.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function formulaProvider(): array {
		return [
			'equals'    => [ '=1+1' ],
			'plus'      => [ '+1+2' ],
			'minus'     => [ '-1+2' ],
			'at'        => [ '@SUM(A1:A9)' ],
			'pipe'      => [ '|cmd /c calc' ],
			'hyperlink' => [ '=HYPERLINK("http://evil.test","Click")' ],
			'tab'       => [ "\t=1+1" ],
			'return'    => [ "\r=1+1" ],
		];
	}

	/**
	 * A header is defused too.
	 *
	 * Headers are as consumer-supplied as the rows: a column label comes from
	 * a report's configuration, and that can come from a database.
	 */
	public function test_a_header_is_defused(): void {
		$this->assertSame( "'=1+1", $this->cells( [ [ 'a' => 1 ] ], [ 'a' => '=1+1' ] )[0][0] );
	}

	/**
	 * An ordinary value is left alone.
	 */
	public function test_an_ordinary_value_is_untouched(): void {
		$this->assertSame(
			[ 'Ada Lovelace', 'ordinary' ],
			$this->cells( [ [ 'name' => 'Ada Lovelace', 'note' => 'ordinary' ] ] )[1]
		);
	}

	/**
	 * A negative number is left as a number.
	 *
	 * `-5` starts with a formula trigger and is also just a refund. A
	 * spreadsheet reading it as a formula gets -5, so there is nothing to
	 * defuse — and defusing it anyway, which this used to do, exported every
	 * negative figure as text and left the column unable to sum.
	 *
	 * @dataProvider numberProvider
	 *
	 * @param mixed  $cell     A number, in some form.
	 * @param string $expected What the spreadsheet reads.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'numberProvider' )]
	public function test_a_plain_number_is_left_alone( $cell, string $expected ): void {
		$this->assertSame( $expected, $this->cells( [ [ 'change' => $cell ] ] )[1][0] );
	}

	/**
	 * Numbers that begin with a trigger character.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function numberProvider(): array {
		return [
			'negative integer' => [ '-5', '-5' ],
			'negative decimal' => [ '-12.50', '-12.50' ],
			'negative float'   => [ -12.5, '-12.5' ],
			'signed positive'  => [ '+1', '+1' ],
		];
	}

	/**
	 * But a formula that happens to start with a minus is still a formula.
	 *
	 * The rule is "a number is a number", not "a minus is fine": `-1+2` is
	 * not numeric, and it runs.
	 */
	public function test_a_formula_behind_a_minus_is_still_defused(): void {
		$this->assertSame( "'-1+2", $this->cells( [ [ 'change' => '-1+2' ] ] )[1][0] );
		$this->assertSame( "'-SUM(A1:A9)", $this->cells( [ [ 'change' => '-SUM(A1:A9)' ] ] )[1][0] );
	}

	/**
	 * The keys become the header row when none is given.
	 */
	public function test_the_keys_are_the_default_headers(): void {
		$this->assertSame( [ 'name', 'total' ], $this->cells( [ [ 'name' => 'Ada', 'total' => 10 ] ] )[0] );
	}

	/**
	 * Given headers are used, in the order the data is in.
	 *
	 * Not the order the headers were declared in: a row's keys decide the
	 * columns, and a header map that disagreed would put the labels over the
	 * wrong values.
	 */
	public function test_headers_follow_the_data_order(): void {
		$this->assertSame(
			[ 'Amount', 'Customer' ],
			$this->cells(
				[ [ 'total' => 10, 'name' => 'Ada' ] ],
				[ 'name' => 'Customer', 'total' => 'Amount' ]
			)[0]
		);
	}

	/**
	 * A key with no header keeps its own name.
	 */
	public function test_an_unlabelled_column_keeps_its_key(): void {
		$this->assertSame(
			[ 'Customer', 'total' ],
			$this->cells( [ [ 'name' => 'Ada', 'total' => 10 ] ], [ 'name' => 'Customer' ] )[0]
		);
	}

	/**
	 * A later batch appends, and does not write the headers again.
	 *
	 * An export is written in batches so a big one does not time out. A
	 * second header row halfway down the file is what happens when that is
	 * got wrong, and it is invisible until somebody sorts it.
	 */
	public function test_a_later_batch_appends_without_headers(): void {
		$exporter = $this->exporter();

		$exporter->write_csv_batch( $this->path, [ [ 'name' => 'Ada' ] ], true );
		$exporter->write_csv_batch( $this->path, [ [ 'name' => 'Grace' ] ], false );

		$csv = ltrim( (string) file_get_contents( $this->path ), "\xEF\xBB\xBF" );

		$this->assertSame( "name\nAda\nGrace\n", $csv );
	}

	/**
	 * The file starts with a byte-order mark.
	 *
	 * Without it Excel reads a UTF-8 export as the local codepage, and every
	 * name with an accent in it arrives mangled.
	 */
	public function test_the_file_starts_with_a_byte_order_mark(): void {
		$this->exporter()->write_csv_batch( $this->path, [ [ 'name' => 'Ada' ] ], true );

		$this->assertStringStartsWith( "\xEF\xBB\xBF", (string) file_get_contents( $this->path ) );
	}

	/**
	 * A quote in a value survives the round trip.
	 *
	 * RFC 4180 doubles it. PHP's default is a backslash escape that is not in
	 * the standard and that other readers do not understand, which is why the
	 * escape argument is passed explicitly.
	 */
	public function test_a_quote_survives_the_round_trip(): void {
		$value = 'She said "hello"';

		$this->assertStringNotContainsString( '\\"', $this->write( [ [ 'note' => $value ] ] ) );
		$this->assertSame( $value, $this->cells( [ [ 'note' => $value ] ] )[1][0] );
	}

	/**
	 * A comma and a newline survive it too.
	 */
	public function test_a_comma_and_a_newline_survive(): void {
		$value = "Lovelace, Ada\nSecond line";

		$this->assertSame( $value, $this->cells( [ [ 'note' => $value ] ] )[1][0] );
	}

	/**
	 * A row that is not an array is skipped rather than fatal.
	 */
	public function test_a_malformed_row_is_skipped(): void {
		$exporter = $this->exporter();

		$this->assertTrue( $exporter->write_csv_batch( $this->path, [ [ 'name' => 'Ada' ], 'nonsense' ], true ) );
		$this->assertStringContainsString( 'Ada', (string) file_get_contents( $this->path ) );
	}

	/**
	 * A path that cannot be opened is false, not a warning.
	 */
	public function test_an_unwritable_path_is_false(): void {
		$this->assertFalse(
			@$this->exporter()->write_csv_batch( '/nowhere/at/all/export.csv', [ [ 'a' => 1 ] ], true )
		);
	}
}
