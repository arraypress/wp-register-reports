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
use PHPUnit\Framework\TestCase;

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
	 * A report with no filters at all is not an error.
	 */
	public function test_a_report_without_filters_is_fine(): void {
		$report = new Reports( 'takings', [ 'tabs' => [ 'sales' => [ 'label' => 'Sales' ] ] ] );

		$this->assertSame( [], $report->filters_for( 'sales' ) );
	}
}
