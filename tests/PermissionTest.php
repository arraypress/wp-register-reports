<?php
/**
 * REST permission tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Registry;
use ArrayPress\RegisterReports\Reports;
use ArrayPress\RegisterReports\RestApi;
use ArrayPress\RegisterReports\Utils\Runtime;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * Who may read a report over REST.
 *
 * A report is somebody's revenue, and every endpoint here answers with it: the
 * refresh, the export, the download. The capability is the report's own, so
 * the check has to resolve the report before it can ask — which means "report
 * not found" and "you may not see this" are two different answers, and getting
 * them the wrong way round tells an anonymous caller which report ids exist.
 */
final class PermissionTest extends TestCase {

	/**
	 * Everyone can do everything again.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['rp_caps'], $GLOBALS['rp_transients'] );

		Registry::instance()->unregister( 'takings' );
	}

	/**
	 * A registered report with a capability of its own.
	 *
	 * @param string $capability What it takes to see it.
	 *
	 * @return Reports
	 */
	private function report( string $capability = 'view_takings' ): Reports {
		return new Reports(
			'takings',
			[
				'capability' => $capability,
				'components' => [ 'total' => [ 'type' => 'tile' ] ],
			]
		);
	}

	/**
	 * A request for one.
	 *
	 * @param array<string, string> $params Query parameters.
	 *
	 * @return WP_REST_Request
	 */
	private function request( array $params ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Someone with the capability is let in.
	 */
	public function test_the_right_capability_is_allowed(): void {
		$this->report();

		$GLOBALS['rp_caps'] = [ 'view_takings' ];

		$this->assertTrue( RestApi::check_permissions( $this->request( [ 'report_id' => 'takings' ] ) ) );
	}

	/**
	 * Someone without it is refused, with a 403.
	 */
	public function test_the_wrong_capability_is_refused(): void {
		$this->report();

		$GLOBALS['rp_caps'] = [ 'read' ];

		$result = RestApi::check_permissions( $this->request( [ 'report_id' => 'takings' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * `manage_options` is the default, not "anyone".
	 *
	 * A report that forgets to name a capability should be an administrator's
	 * to read, not the internet's.
	 */
	public function test_the_default_capability_is_manage_options(): void {
		$report = new Reports( 'takings', [ 'components' => [ 'total' => [] ] ] );

		$this->assertSame( 'manage_options', $report->get_config( 'capability', 'manage_options' ) );

		$GLOBALS['rp_caps'] = [ 'read' ];

		$this->assertInstanceOf(
			\WP_Error::class,
			RestApi::check_permissions( $this->request( [ 'report_id' => 'takings' ] ) )
		);
	}

	/**
	 * An unknown report is a 404, before any capability is consulted.
	 *
	 * There is nothing to check a capability against, and answering 403 would
	 * tell an anonymous caller that the id exists.
	 */
	public function test_an_unknown_report_is_not_found(): void {
		$result = RestApi::check_permissions( $this->request( [ 'report_id' => 'no_such_report' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * An export batch is checked against the report the session names.
	 *
	 * Not against the request: the token is the only thing the caller
	 * supplies, and it resolves to a session the server wrote. Trusting a
	 * report id from the request would let anyone point a live export session
	 * at a report they are allowed to read.
	 */
	public function test_an_export_batch_is_checked_against_the_session(): void {
		$this->report();

		// Through Runtime rather than spelled out: the prefix is derived from
		// the namespace so that a Strauss-prefixed copy keeps its own
		// transients, and a literal here would pass in this repository and
		// fail in any build of it.
		set_transient( Runtime::key( 'export' ) . '_a-token', [ 'report_id' => 'takings' ] );

		$GLOBALS['rp_caps'] = [ 'view_takings' ];
		$this->assertTrue( RestApi::check_batch_permissions( $this->request( [ 'export_token' => 'a-token' ] ) ) );

		$GLOBALS['rp_caps'] = [ 'read' ];
		$this->assertInstanceOf(
			\WP_Error::class,
			RestApi::check_batch_permissions( $this->request( [ 'export_token' => 'a-token' ] ) )
		);
	}

	/**
	 * An expired or invented token is refused.
	 */
	public function test_an_unknown_export_token_is_refused(): void {
		$result = RestApi::check_batch_permissions( $this->request( [ 'export_token' => 'never-existed' ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}
}
