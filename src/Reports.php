<?php
/**
 * Reports Main Class
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports;

use ArrayPress\DateUtils\Dates;
use ArrayPress\RegisterReports\Traits\AssetManager;
use ArrayPress\RegisterReports\Traits\ComponentRenderer;
use ArrayPress\RegisterReports\Traits\ConfigParser;
use ArrayPress\RegisterReports\Traits\DateRangeHandler;
use ArrayPress\RegisterReports\Traits\ExportHandler;
use ArrayPress\RegisterReports\Traits\MenuBuilder;
use ArrayPress\RegisterReports\Traits\PageRenderer;
use ArrayPress\RegisterReports\Traits\ScreenOptions;
use ArrayPress\RegisterReports\Traits\TabManager;

/**
 * Class Reports
 *
 * Main class for registering WordPress report pages.
 */
class Reports {

    use AssetManager;
    use ComponentRenderer;
    use ConfigParser;
    use DateRangeHandler;
    use ExportHandler;
    use MenuBuilder;
    use PageRenderer;
    use ScreenOptions;
    use TabManager;

    /**
     * Unique identifier for this reports page.
     *
     * @var string
     */
    protected string $id;

    /**
     * Configuration array.
     *
     * @var array
     */
    protected array $config;

    /**
     * Parsed tabs array.
     *
     * @var array
     */
    protected array $tabs = [];

    /**
     * Parsed components array (organized by tab).
     *
     * @var array
     */
    protected array $components = [];

    /**
     * Parsed exports array.
     *
     * @var array
     */
    protected array $exports = [];

    /**
     * Reports page hook suffix.
     *
     * @var string
     */
    protected string $hook_suffix = '';

    /**
     * Current date range.
     *
     * @var array
     */
    protected array $date_range = [];

    /**
     * Default configuration values.
     *
     * @var array
     */
    protected array $defaults = [
		'page_title'       => 'Reports',
		'menu_title'       => 'Reports',
		'menu_slug'        => '',
		'capability'       => 'manage_options',
		'parent_slug'      => '',
		'icon'             => 'dashicons-chart-area',
		'position'         => null,
		'tabs'             => [],
		'components'       => [],
		'exports'          => [],
		'show_title'       => true,
		'show_tabs'        => true,
		'show_date_picker' => true,
		'body_class'       => '',

        // Branded header options
            'logo'             => '',
		'header_title'     => '',
		'header_class'     => '',

        // Date range options
            'date_presets'     => [],
		'default_preset'   => 'this_month',

        // Refresh options
            'auto_refresh'     => 0,     // Seconds between auto-refresh. 0 = disabled
		'show_refresh'     => true,  // Show manual refresh button

        // Help screen options
		'help_tabs'        => [],
		'help_sidebar'     => '',
    ];

    /**
     * Constructor.
     *
     * @param string $id     Unique identifier for this reports page.
     * @param array  $config Configuration array.
     */
    public function __construct( string $id, array $config ) {
        $this->id = sanitize_key( $id );

        // Populate dynamic defaults before merging
        $this->defaults['date_presets'] = Dates::get_range_options( true, true );

        $this->config = wp_parse_args( $config, $this->defaults );

        // Set defaults based on ID if not provided
        if ( empty( $this->config['menu_slug'] ) ) {
            $this->config['menu_slug'] = $this->id;
        }

        $this->parse_config();

        // Register with the central registry
        Registry::register( $this->id, $this );

        // Register REST API
        RestApi::register();

        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks.
     *
     * @return void
     */
    protected function init_hooks(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );

        // Add body class for styling
        add_filter( 'admin_body_class', [ $this, 'add_body_class' ] );

        // Fix menu highlight for submenu pages
        if ( ! empty( $this->config['parent_slug'] ) ) {
            add_filter( 'parent_file', [ $this, 'fix_parent_menu_highlight' ] );
            add_filter( 'submenu_file', [ $this, 'fix_submenu_highlight' ] );
        }
    }

/**
     * Get current filter values from URL for a tab.
     *
     * @param string $tab Tab key.
     *
     * @return array
     */
    public function get_current_filters( string $tab ): array {
        $tab_filters = $this->tabs[ $tab ]['filters'] ?? [];
        $values      = [];

        foreach ( $tab_filters as $filter_key => $filter ) {
            $param_name = 'filter_' . $filter_key;

            if ( isset( $_GET[ $param_name ] ) ) {
                $values[ $filter_key ] = sanitize_text_field( wp_unslash( $_GET[ $param_name ] ) );
            } else {
                $values[ $filter_key ] = $filter['default'] ?? '';
            }
        }

        return $values;
    }

/**
     * Get the reports ID.
     *
     * @return string
     */
    public function get_id(): string {
        return $this->id;
    }

    /**
     * Get a specific config value.
     *
     * @param string $key     Config key.
     * @param mixed  $fallback Value returned when the key is absent.
     *
     * @return mixed
     */
    public function get_config( string $key, $fallback = null ) {
        return $this->config[ $key ] ?? $fallback;
    }

    /**
     * Get all tabs.
     *
     * @return array
     */
    public function get_tabs(): array {
        return $this->tabs;
    }

    /**
     * Get all components.
     *
     * @return array
     */
    public function get_components(): array {
        return $this->components;
    }

    /**
     * Get all exports.
     *
     * @return array
     */
    public function get_exports(): array {
        return $this->exports;
    }

    /**
     * Get the hook suffix.
     *
     * @return string
     */
    public function get_hook_suffix(): string {
        return $this->hook_suffix;
    }
}
