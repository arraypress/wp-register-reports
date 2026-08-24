<?php
/**
 * REST payload tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Registry;
use ArrayPress\RegisterReports\Reports;
use ArrayPress\RegisterReports\RestApi;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * What the refresh endpoint sends, and why it is already formatted.
 *
 * A report table is drawn twice: once by PHP when the page loads, and again by
 * the script every time the data refreshes. Those were two different pieces of
 * code formatting the same rows, and they did not agree — which is not a thing
 * you notice by looking at either one.
 *
 * The script used the browser's locale for separators and the browser's format
 * for dates, so a site configured for one and viewed from another changed its
 * numbers on refresh. It printed a hardcoded `$`, so a shop in euros showed
 * dollars. And it wrote cells with `.html()` while PHP writes them through
 * `wp_kses_post()`, so a value holding a script tag was inert on load and ran
 * the first time the table refreshed.
 *
 * All four are the same bug, and the fix is that there is one formatter now:
 * the payload arrives ready to put on screen.
 */
final class PayloadTest extends TestCase {

	/**
	 * Forget the report.
	 */
	protected function tearDown(): void {
		Registry::instance()->unregister( 'takings' );
	}

	/**
	 * A report with one table, whose rows are whatever is passed in.
	 *
	 * @param array<int, array<string, mixed>> $rows    The rows its callback returns.
	 * @param array<string, mixed>             $columns The column configuration.
	 *
	 * @return void
	 */
	private function report( array $rows, array $columns ): void {
		new Reports(
			'takings',
			[
				'tabs'       => [ 'main' => [ 'label' => 'Main' ] ],
				'components' => [
					'orders' => [
						'type'          => 'table',
						'tab'           => 'main',
						'columns'       => $columns,
						'data_callback' => static fn(): array => [ 'rows' => $rows ],
					],
				],
			]
		);
	}

	/**
	 * The table component out of a refresh response.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_param( 'report_id', 'takings' );
		$request->set_param( 'tab', 'main' );

		$response = RestApi::get_all_components_data( $request );
		$data     = is_array( $response ) ? $response : $response->get_data();

		return $data['components']['orders'] ?? [];
	}

	/**
	 * Cells arrive formatted, by the same formatter that renders the page.
	 */
	public function test_cells_are_formatted_before_they_are_sent(): void {
		$this->report(
			[ [ 'name' => 'Widget', 'sold' => 1204, 'takings' => 1050 ] ],
			[
				'name'    => 'Name',
				'sold'    => [ 'label' => 'Sold', 'format' => 'number' ],
				'takings' => [ 'label' => 'Takings', 'format' => 'currency' ],
			]
		);

		$row = $this->payload()['rows'][0] ?? [];

		$this->assertSame( 'Widget', $row['name'] ?? null, 'A column with no format is sent as it was.' );
		$this->assertSame( '1,204', $row['sold'] ?? null, 'A number column arrives unformatted.' );
		$this->assertSame( '$10.50', $row['takings'] ?? null, 'A currency column arrives unformatted.' );
	}

	/**
	 * Markup in a cell is sanitized, because the script writes it as HTML.
	 *
	 * The whole point. PHP's first render puts every cell through
	 * wp_kses_post(); the refresh put nothing through anything, so a script
	 * tag that was inert on page load ran as soon as the table refreshed.
	 */
	public function test_a_script_tag_in_a_cell_does_not_survive(): void {
		$this->report(
			[ [ 'name' => '<script>alert(1)</script>Widget' ] ],
			[ 'name' => 'Name' ]
		);

		$name = $this->payload()['rows'][0]['name'] ?? '';

		$this->assertStringNotContainsString( '<script', $name );
		$this->assertStringContainsString( 'Widget', $name, 'The rest of the value was thrown away with it.' );
	}

	/**
	 * Markup a report meant is kept.
	 *
	 * Escaping everything would be the easy answer and the wrong one: a
	 * formatter returning a badge is a feature, and this is the same rule the
	 * page render already applies rather than a stricter one invented here.
	 */
	public function test_markup_a_report_meant_is_kept(): void {
		$this->report(
			[ [ 'name' => '<strong>Widget</strong>' ] ],
			[ 'name' => 'Name' ]
		);

		$this->assertSame( '<strong>Widget</strong>', $this->payload()['rows'][0]['name'] ?? '' );
	}

	/**
	 * The unformatted values are sent alongside, for row action URLs.
	 *
	 * A row action's href substitutes {id} out of the row. Formatted for
	 * display an id is 1,204 and links to nothing, so the raw values travel
	 * too — and only the scalar ones, since nothing else can go in a URL.
	 */
	public function test_the_raw_values_travel_alongside(): void {
		$this->report(
			[ [ 'id' => 1204, 'meta' => [ 'nested' => true ], 'name' => 'Widget' ] ],
			[ 'id' => [ 'label' => 'ID', 'format' => 'number' ], 'name' => 'Name' ]
		);

		$payload = $this->payload();

		$this->assertSame( '1,204', $payload['rows'][0]['id'] ?? null, 'The display value is formatted.' );
		$this->assertSame( 1204, $payload['raw'][0]['id'] ?? null, 'The raw value is not.' );
		$this->assertArrayNotHasKey( 'meta', $payload['raw'][0] ?? [], 'A value that cannot go in a URL was sent anyway.' );

		// And it is not a cell either. There is no honest way to turn an
		// array into one — casting it warns and prints the word Array, which
		// is worse than an empty cell because it looks like data.
		$this->assertArrayNotHasKey( 'meta', $payload['rows'][0] ?? [] );
	}

	/**
	 * Columns can be a plain list of names.
	 *
	 * Three shapes are configuration a consumer already writes — a list of
	 * names, a map of name to label, a map of name to an array carrying a
	 * format — so all three are read rather than one being declared correct.
	 */
	public function test_columns_given_as_a_list_still_work(): void {
		$this->report( [ [ 'name' => 'Widget' ] ], [ 'name', 'sold' ] );

		$this->assertSame( 'Widget', $this->payload()['rows'][0]['name'] ?? null );
	}

	/**
	 * A table with no rows answers with no rows, not with an error.
	 */
	public function test_an_empty_table_is_an_empty_list(): void {
		$this->report( [], [ 'name' => 'Name' ] );

		$this->assertSame( [], $this->payload()['rows'] ?? null );
	}
}
