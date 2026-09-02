<?php
/**
 * Screen Options tests.
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
 * Which components a person may hide, and how the choice is kept.
 *
 * The panel used to list every component on every tab and had nothing to
 * save it: no Apply button, because core only draws one for options that
 * ask, and no script. So every box on it was a box that did nothing. Now it
 * lists the tab on screen and the choice goes over REST, and what matters
 * is that saving one tab leaves the others alone.
 */
final class ScreenOptionsTest extends TestCase {

	/**
	 * Nothing hidden and no report registered.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['rp_user_meta'], $_GET['tab'] );

		Registry::instance()->unregister( 'takings' );
	}

	/**
	 * Two tabs, three components with names, one that may never be hidden.
	 *
	 * @return Reports
	 */
	private function report(): Reports {
		return new Reports(
			'takings',
			[
				'tabs'       => [
					'sales' => [ 'label' => 'Sales' ],
					'stock' => [ 'label' => 'Stock' ],
				],
				'components' => [
					'revenue'      => [ 'type' => 'tile', 'title' => 'Revenue', 'tab' => 'sales' ],
					'orders'       => [ 'type' => 'tile', 'title' => 'Orders', 'tab' => 'sales' ],
					'stock_levels' => [ 'type' => 'table', 'title' => 'Stock levels', 'tab' => 'stock' ],
					'reorder'      => [ 'type' => 'tile', 'title' => 'Reorder', 'tab' => 'stock', 'always_show' => true ],
				],
			]
		);
	}

	/**
	 * A request for the save route.
	 *
	 * @param array<string, mixed> $params Parameters.
	 *
	 * @return WP_REST_Request
	 */
	private function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * The panel offers the tab on screen, not every tab.
	 */
	public function test_the_panel_lists_the_current_tabs_components(): void {
		$report      = $this->report();
		$_GET['tab'] = 'stock';

		$markup = $report->render_screen_options( '', (object) [ 'id' => $report->get_hook_suffix() ] );

		$this->assertStringContainsString( 'data-tab="stock"', $markup );
		$this->assertStringContainsString( 'data-report="takings"', $markup );
		$this->assertStringContainsString( 'value="stock_levels" checked', $markup );
		$this->assertStringNotContainsString( 'value="revenue"', $markup );
		$this->assertStringNotContainsString( 'value="reorder"', $markup );
		$this->assertStringNotContainsString( '<form', $markup );
		$this->assertStringNotContainsString( 'nonce', $markup );
	}

	/**
	 * Saving one tab keeps what was hidden on the others.
	 *
	 * The request can only speak for the tab whose panel was drawn, so a
	 * person who hid the stock table last week does not get it back because
	 * they hid a sales tile today.
	 */
	public function test_saving_one_tab_keeps_the_others_choices(): void {
		$report = $this->report();

		$report->save_hidden_components( 'stock', [] );
		$this->assertSame( [ 'stock_levels' ], $report->hidden_components() );

		$hidden = $report->save_hidden_components( 'sales', [ 'revenue' ] );

		$this->assertEqualsCanonicalizing( [ 'stock_levels', 'orders' ], $hidden );
		$this->assertEqualsCanonicalizing( [ 'stock_levels', 'orders' ], $report->hidden_components() );

		// And ticking it again takes it out without touching the other tab.
		$this->assertEqualsCanonicalizing( [ 'stock_levels' ], $report->save_hidden_components( 'sales', [ 'revenue', 'orders' ] ) );
	}

	/**
	 * A component marked always_show is not offered and cannot be hidden.
	 */
	public function test_an_always_shown_component_cannot_be_hidden(): void {
		$report = $this->report();

		$this->assertArrayNotHasKey( 'reorder', $report->hideable_components( 'stock' ) );
		$this->assertSame( [ 'stock_levels' ], $report->save_hidden_components( 'stock', [ 'reorder' ] ) );
	}

	/**
	 * The route saves for the report and tab it is given, and refuses a tab
	 * the report does not have.
	 */
	public function test_the_route_saves_and_checks_the_tab(): void {
		$this->report();

		$result = RestApi::save_screen_options( $this->request( [ 'report_id' => 'takings', 'tab' => 'sales', 'shown' => [ 'orders' ] ] ) );

		$this->assertSame( [ 'revenue' ], $result['hidden'] );

		$refused = RestApi::save_screen_options( $this->request( [ 'report_id' => 'takings', 'tab' => 'returns', 'shown' => [] ] ) );

		$this->assertInstanceOf( \WP_Error::class, $refused );
		$this->assertSame( 404, $refused->get_error_data()['status'] ?? null );
	}

	/**
	 * The route is registered under the same namespace as the rest.
	 */
	public function test_the_route_is_registered(): void {
		$GLOBALS['fk_routes'] = [];

		RestApi::register_routes();

		$this->assertContains( RestApi::rest_namespace() . '/screen-options', $GLOBALS['fk_routes'] );
	}
}
