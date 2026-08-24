<?php
/**
 * REST Routes
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Rest;

use WP_REST_Server;

/**
 * Which endpoints exist, and what each will accept.
 *
 * The argument schemas are the interesting half. Every one declares its type,
 * its default and its sanitiser, so a request that does not fit is refused by
 * WordPress before any of this library's code runs — which is a great deal
 * safer than checking it afterwards and a great deal shorter than checking it
 * by hand in every callback.
 */
trait Routes {

/**
	 * Register REST API endpoints.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		self::$registered = true;
	}

/**
	 * Register REST routes.
	 */
	public static function register_routes(): void {
		// Get single component data
		register_rest_route( self::rest_namespace(), '/component', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_component_data' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_component_args(),
		] );

		// Get all components for a tab (for refresh)
		register_rest_route( self::rest_namespace(), '/components', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_all_components_data' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_tab_args(),
		] );

		// Start export
		register_rest_route( self::rest_namespace(), '/export/start', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'start_export' ],
			'permission_callback' => [ __CLASS__, 'check_permissions' ],
			'args'                => self::get_export_start_args(),
		] );

		// Process export batch
		register_rest_route( self::rest_namespace(), '/export/batch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'process_export_batch' ],
			'permission_callback' => [ __CLASS__, 'check_batch_permissions' ],
			'args'                => self::get_export_batch_args(),
		] );

		// Download the finished file. Browser-navigated, so it authenticates
		// with the standard REST cookie nonce (_wpnonce) rather than a
		// bespoke one, and reuses the batch permission check — which resolves
		// the report from the export session and tests its capability.
		register_rest_route( self::rest_namespace(), '/export/download', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'handle_download_export' ],
			'permission_callback' => [ __CLASS__, 'check_batch_permissions' ],
			'args'                => [
				'export_token' => [
					'description'       => __( 'Export session token.', 'arraypress' ),
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
			],
		] );
	}

/**
	 * Get component endpoint args.
	 */
	private static function get_component_args(): array {
		return [
			'report_id'    => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'component_id' => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_preset'  => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_start'   => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_end'     => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

/**
	 * Get tab endpoint args.
	 */
	private static function get_tab_args(): array {
		return [
			'report_id'   => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'tab'         => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_preset' => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_start'  => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_end'    => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

/**
	 * Get export start endpoint args.
	 */
	private static function get_export_start_args(): array {
		return [
			'report_id'   => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'export_id'   => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_preset' => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
			'date_start'  => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'date_end'    => [
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'filters'     => [
				'type' => 'object',
				'default' => [],
			],
		];
	}

/**
	 * Get export batch endpoint args.
	 */
	private static function get_export_batch_args(): array {
		return [
			'export_token' => [
				'required' => true,
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'batch'        => [
				'required' => true,
				'type' => 'integer',
			],
		];
	}
}
