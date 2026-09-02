<?php
/**
 * REST: Screen Options
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Rest;

use ArrayPress\RegisterReports\Registry;
use WP_Error;
use WP_REST_Request;

/**
 * Saves what a person turned off in Screen Options.
 *
 * The panel posts the ids still ticked for the tab it was drawn for; the
 * report works out which of that tab's components that leaves hidden and
 * keeps the other tabs' choices as they were. The capability check is the
 * report's own, shared with every other route, so somebody who may not read
 * a report may not rearrange it either.
 */
trait Preferences {

	/**
	 * Remember which of a tab's components the current user has hidden.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public static function save_screen_options( WP_REST_Request $request ) {
		$report = Registry::instance()->get( (string) $request->get_param( 'report_id' ) );

		if ( ! $report ) {
			return new WP_Error( 'invalid_report', __( 'Invalid report.', 'arraypress' ), [ 'status' => 404 ] );
		}

		// An empty tab is a report with no tabs, whose one set of components
		// is what the panel showed; anything else has to be a tab it has.
		$tab = (string) $request->get_param( 'tab' );

		if ( '' !== $tab && ! isset( $report->get_components()[ $tab ] ) ) {
			return new WP_Error( 'invalid_tab', __( 'Invalid tab.', 'arraypress' ), [ 'status' => 404 ] );
		}

		return [
			'success' => true,
			'hidden'  => $report->save_hidden_components( $tab, (array) $request->get_param( 'shown' ) ),
		];
	}
}
