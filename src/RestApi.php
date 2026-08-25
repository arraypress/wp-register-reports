<?php
/**
 * REST API Class
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports;

use ArrayPress\Dates\Preset;
use ArrayPress\Dates\Site;
use ArrayPress\RegisterReports\Utils\Runtime;
use WP_REST_Request;
use ArrayPress\RegisterReports\Rest\ComponentData;
use ArrayPress\RegisterReports\Rest\ExportEndpoints;
use ArrayPress\RegisterReports\Rest\Permissions;
use ArrayPress\RegisterReports\Rest\Routes;

/**
 * Class RestApi
 *
 * Handles REST API endpoints for reports including batched exports.
 */
class RestApi {

	use ComponentData;
	use ExportEndpoints;
	use Permissions;
	use Routes;

	/**
	 * Get the REST namespace for this build.
	 *
	 * Derived from the class namespace so two Strauss-prefixed copies of this
	 * library never register the same routes. See {@see Runtime}.
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return Runtime::rest_namespace();
	}

	/**
	 * Get the transient key holding an export session.
	 *
	 * Prefixed per build so one plugin's copy cannot read, serve or delete
	 * another plugin's export session.
	 *
	 * @param string $export_token Export token.
	 *
	 * @return string
	 */
	private static function export_key( string $export_token ): string {
		return Runtime::key( 'export' ) . '_' . $export_token;
	}

	/**
	 * Whether the API has been registered.
	 */
	private static bool $registered = false;

	/**
	 * Export batch size.
	 */
	const BATCH_SIZE = 100;

/**
	 * Get date range from request parameters.
	 *
	 * @param WP_REST_Request $request The REST request.
	 * @param Reports         $report  The report instance.
	 *
	 * @return array Date range with UTC and local representations.
	 */
	protected static function get_date_range_from_request( WP_REST_Request $request, Reports $report ): array {
		$preset     = $request->get_param( 'date_preset' );
		$date_start = $request->get_param( 'date_start' );
		$date_end   = $request->get_param( 'date_end' );

		// Use default preset if none specified
		if ( empty( $preset ) ) {
			$preset = $report->get_config( 'default_preset', 'this_month' );
		}

		$range = Preset::resolve( $preset, $date_start ?? '', $date_end ?? '' );

		return [
			'start'       => $range->start(),
			'end'         => $range->end(),
			'start_local' => Site::format( $range->start(), 'Y-m-d H:i:s' ),
			'end_local'   => Site::format( $range->end(), 'Y-m-d H:i:s' ),
			'preset'      => $preset,
		];
	}
}
