<?php
/**
 * Filter tests.
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
 * Which filters a tab shows, and where they came from.
 *
 * Most useful filters — by product, by country, by gateway — apply to a whole
 * report rather than to one tab of it. Declaring them per tab means declaring
 * them four times, and four copies of a thing is three chances for one of
 * them to drift.
 *
 * The other half is that the page and the REST call have to agree about the
 * set. They read it from two different places, and a filter the page draws
 * but the endpoint ignores is a control that visibly does nothing.
 */
final class FilterTest extends TestCase {

	/**
	 * Forget the report.
	 */
	protected function tearDown(): void {
		Registry::instance()->unregister( 'takings' );

		$_GET = [];
	}

	/**
	 * A report with filters at both levels.
	 *
	 * @return Reports
	 */
	private function report(): Reports {
		return new Reports(
			'takings',
			[
				'filters'    => [
					'country' => [ 'type' => 'select', 'options' => [ '' => 'All', 'gb' => 'UK' ] ],
					'product' => [ 'type' => 'select', 'options' => [ '' => 'All' ] ],
				],
				'tabs'       => [
					'sales'   => [ 'label' => 'Sales' ],
					'refunds' => [
						'label'   => 'Refunds',
						'filters' => [
							'reason'  => [ 'type' => 'text' ],

							// Same key as a report-wide one, deliberately.
							'product' => [ 'type' => 'text' ],
						],
					],
				],
				'components' => [ 'total' => [ 'type' => 'tile', 'tab' => 'sales' ] ],
			]
		);
	}

	/**
	 * A tab with none of its own still gets the report's.
	 */
	public function test_a_tab_inherits_the_reports_filters(): void {
		$this->assertSame(
			[ 'country', 'product' ],
			array_keys( $this->report()->filters_for( 'sales' ) )
		);
	}

	/**
	 * A tab's own are added to them.
	 */
	public function test_a_tab_adds_its_own(): void {
		$filters = $this->report()->filters_for( 'refunds' );

		$this->assertArrayHasKey( 'country', $filters );
		$this->assertArrayHasKey( 'reason', $filters );
	}

	/**
	 * And a tab wins on a shared key.
	 *
	 * So a tab can narrow or replace a report-wide filter rather than only
	 * ever adding to it — a product filter that is a select everywhere and a
	 * free-text search on one tab.
	 */
	public function test_a_tab_can_replace_one(): void {
		$this->assertSame( 'text', $this->report()->filters_for( 'refunds' )['product']['type'] ?? null );
		$this->assertSame( 'select', $this->report()->filters_for( 'sales' )['product']['type'] ?? null );
	}

	/**
	 * The values read back are for the same set the page drew.
	 *
	 * These are read in two different places — one to render the controls,
	 * one to hand to the data callbacks — and a filter the page shows but
	 * the callbacks never receive is a control that visibly does nothing.
	 */
	public function test_the_values_read_back_cover_the_whole_set(): void {
		$report = $this->report();

		$_GET['filter_country'] = 'gb';
		$_GET['filter_reason']  = 'faulty';

		$values = $report->get_current_filters( 'refunds' );

		$this->assertSame( 'gb', $values['country'] ?? null, 'A report-wide filter was not read back.' );
		$this->assertSame( 'faulty', $values['reason'] ?? null );
	}

	/**
	 * A multiselect's values are read back as a list on the page too.
	 *
	 * The page and the refresh read the same query string, and they used to
	 * read it differently: the page ran sanitize_text_field() over whatever
	 * was there, which on an array is the word Array.
	 */
	public function test_a_multiselect_is_read_back_as_a_list(): void {
		$report = $this->report();

		$_GET['filter_country'] = [ 'gb', '<b>us</b>' ];

		$this->assertSame( [ 'gb', 'us' ], $report->get_current_filters( 'sales' )['country'] ?? null );
	}

	/**
	 * A report with no filters at all is not an error.
	 */
	public function test_a_report_without_filters_is_fine(): void {
		$report = new Reports( 'takings', [ 'tabs' => [ 'sales' => [ 'label' => 'Sales' ] ] ] );

		$this->assertSame( [], $report->filters_for( 'sales' ) );
	}
	/**
	 * Render one filter control and hand back what it printed.
	 *
	 * @param string               $key    Filter key.
	 * @param array<string, mixed> $filter Its configuration.
	 *
	 * @return string
	 */
	private function control( string $key, array $filter ): string {
		$report = new class( 'takings', [] ) extends Reports {

			/**
			 * @param string $key    Filter key.
			 * @param array  $filter Its configuration.
			 *
			 * @return string
			 */
			public function draw( string $key, array $filter ): string {
				ob_start();

				try {
					$this->render_filter_field( $key, $filter );
				} finally {
					return (string) ob_get_clean();
				}
			}
		};

		return $report->draw( $key, $filter );
	}

	/**
	 * A select filter is the searchable one, not a plain dropdown.
	 *
	 * A filter exists to find something in a list, which is the combobox's
	 * whole job — and a product filter with four hundred entries in it is
	 * unusable as a native select. The kit's class is what its script looks
	 * for, so this is the difference between a combobox and a promise.
	 */
	public function test_a_select_filter_is_searchable(): void {
		$html = $this->control( 'country', [ 'type' => 'select', 'label' => 'Country', 'options' => [ 'gb' => 'UK' ] ] );

		$this->assertStringContainsString( 'field-kit__select--enhanced', $html );
	}

	/**
	 * Its name is the one the query string and the endpoint agree on.
	 *
	 * The kit can prefix names itself, and asking it to would have produced
	 * `filter[country]` — which every reader of these values, on both sides,
	 * would have stopped recognising.
	 */
	public function test_a_filter_keeps_its_query_name(): void {
		$html = $this->control( 'country', [ 'type' => 'select', 'options' => [ 'gb' => 'UK' ] ] );

		$this->assertStringContainsString( 'name="filter_country"', $html );
	}

	/**
	 * The label is there, and not visible.
	 *
	 * A word above every control makes the bar twice as tall as the controls
	 * in it. No label at all makes the control unidentifiable to anyone using
	 * a screen reader. It is hidden, not removed.
	 */
	public function test_a_filter_is_labelled_without_showing_one(): void {
		$html = $this->control( 'country', [ 'type' => 'select', 'label' => 'Country', 'options' => [ 'gb' => 'UK' ] ] );

		$this->assertMatchesRegularExpression( '/<label[^>]*class="screen-reader-text"[^>]*>Country<\/label>/', $html );
		$this->assertStringContainsString( 'for="filter_country"', $html );
	}

	/**
	 * An "all" entry becomes the placeholder rather than a second option.
	 *
	 * The kit renders a placeholder option of its own, so an empty-keyed
	 * option offered alongside it appears twice — once as the placeholder and
	 * once selected below it.
	 */
	public function test_an_all_option_is_the_placeholder(): void {
		$html = $this->control(
			'country',
			[ 'type' => 'select', 'options' => [ '' => 'All countries', 'gb' => 'UK' ] ]
		);

		$this->assertSame( 1, substr_count( $html, 'All countries</option>' ) );
		$this->assertStringContainsString( 'data-placeholder="All countries"', $html );
	}

	/**
	 * A value in the query string is the one selected.
	 */
	public function test_the_current_value_is_selected(): void {
		$_GET['filter_country'] = 'gb';

		$html = $this->control( 'country', [ 'type' => 'select', 'options' => [ 'gb' => 'UK', 'us' => 'US' ] ] );

		$this->assertMatchesRegularExpression( '/value="gb"[^>]*selected/', $html );
	}

	/**
	 * A type the kit does not have draws nothing, rather than dying.
	 *
	 * The kit answers null for a field it cannot build, and handing null to
	 * its renderer is a type error from inside a printf -- the whole page,
	 * over one misspelt filter.
	 */
	public function test_an_unknown_filter_type_draws_nothing(): void {
		$this->assertSame( '', $this->control( 'country', [ 'type' => 'nonsense' ] ) );
	}

	/**
	 * A multiselect's values reach the callback as a list.
	 *
	 * They arrive as an array, and the refresh used to sanitize them with
	 * sanitize_text_field() -- which warns on an array and returns the word
	 * Array. So every callback behind a multiselect was handed a value
	 * nobody chose, and filtered by it.
	 */
	public function test_a_multiselect_filter_reaches_the_callback_as_a_list(): void {
		$seen = null;

		new Reports(
			'takings',
			[
				'tabs'       => [ 'sales' => [ 'label' => 'Sales' ] ],
				'components' => [
					'total' => [
						'type'          => 'tile',
						'tab'           => 'sales',
						'data_callback' => static function ( array $range ) use ( &$seen ): array {
							$seen = $range['filters'];

							return [ 'value' => 1 ];
						},
					],
				],
			]
		);

		$request = new WP_REST_Request( 'GET', '/' );
		$request->set_param( 'report_id', 'takings' );
		$request->set_param( 'tab', 'sales' );
		$request->set_param( 'filter_country', [ 'gb', '<b>us</b>', [ 'nested' ] ] );
		$request->set_param( 'filter_reason', ' faulty ' );

		RestApi::get_all_components_data( $request );

		$this->assertSame( [ 'gb', 'us' ], $seen['country'] ?? null );
		$this->assertSame( 'faulty', $seen['reason'] ?? null );
	}

	/**
	 * An export's filters are reduced to plain values before anything reads them.
	 *
	 * The `filters` argument is an object, and an object with no sanitiser
	 * reached the consumer's callbacks -- and a transient, replayed for every
	 * batch -- exactly as the request body had it. What survives is a string
	 * per filter, or a list of strings for a multiselect, under a key that
	 * could be a filter key.
	 */
	public function test_export_filters_are_reduced_to_plain_values(): void {
		$clean = RestApi::sanitize_filters(
			[
				'country'        => '<b>gb</b>',
				'product'        => [ '1', 2, [ 'deep' => 'no' ], null ],
				'Odd Key!'       => 'x',
				'reason'         => [ 'a' => 'b' ],
				'nothing'        => null,
			]
		);

		$this->assertSame( 'gb', $clean['country'] );
		$this->assertSame( [ '1', '2' ], $clean['product'] );
		$this->assertSame( 'x', $clean['oddkey'] ?? null, 'The key was not cut down.' );
		$this->assertSame( [ 'b' ], $clean['reason'], 'A map is a list of its scalar values.' );
		$this->assertSame( '', $clean['nothing'] );
		$this->assertSame( [], RestApi::sanitize_filters( 'not an object' ) );
	}
}
