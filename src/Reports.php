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

use ArrayPress\FieldKit\Support\PageHeader;

use ArrayPress\DateUtils\Dates;
use ArrayPress\RegisterReports\Traits\AssetManager;
use ArrayPress\RegisterReports\Traits\ComponentRenderer;
use ArrayPress\RegisterReports\Traits\ConfigParser;
use ArrayPress\RegisterReports\Traits\DateRangeHandler;
use ArrayPress\RegisterReports\Traits\ExportHandler;
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
     * Add custom body class to the reports page.
     *
     * @param string $classes Space-separated list of body classes.
     *
     * @return string
     */
    public function add_body_class( string $classes ): string {
        $screen = get_current_screen();

        if ( ! $screen || $screen->id !== $this->hook_suffix ) {
            return $classes;
        }

        // Add generic reports class
        $classes .= ' reports';

        // Add report-specific class
        $classes .= ' reports-' . $this->id;

        // Add custom class from config if provided
        if ( ! empty( $this->config['body_class'] ) ) {
            $classes .= ' ' . sanitize_html_class( $this->config['body_class'] );
        }

        return $classes;
    }

    /**
     * Fix parent menu highlight for report pages.
     *
     * @param string $parent_file The parent file.
     *
     * @return string
     */
    public function fix_parent_menu_highlight( string $parent_file ): string {
        global $plugin_page;

        if ( $plugin_page === $this->config['menu_slug'] ) {
            return $this->config['parent_slug'];
        }

        return $parent_file;
    }

    /**
     * Fix submenu highlight for report pages.
     *
     * @param string|null $submenu_file The submenu file.
     *
     * @return string|null
     */
    public function fix_submenu_highlight( ?string $submenu_file ): ?string {
        global $plugin_page;

        if ( $plugin_page === $this->config['menu_slug'] ) {
            return $this->config['menu_slug'];
        }

        return $submenu_file;
    }

    /**
     * Register the admin menu page.
     *
     * @return void
     */
    public function register_menu(): void {
        if ( ! empty( $this->config['parent_slug'] ) ) {
            $this->hook_suffix = add_submenu_page(
                    $this->config['parent_slug'],
                    $this->config['page_title'],
                    $this->config['menu_title'],
                    $this->config['capability'],
                    $this->config['menu_slug'],
                    [ $this, 'render_page' ]
            );
        } else {
            $this->hook_suffix = add_menu_page(
                    $this->config['page_title'],
                    $this->config['menu_title'],
                    $this->config['capability'],
                    $this->config['menu_slug'],
                    [ $this, 'render_page' ],
                    $this->config['icon'],
                    $this->config['position']
            );
        }

        // Register help tabs
        if ( ! empty( $this->config['help_tabs'] ) || ! empty( $this->config['help_sidebar'] ) ) {
            add_action( 'load-' . $this->hook_suffix, [ $this, 'register_help_tabs' ] );
        }
    }

    /**
     * Register help tabs for the reports screen.
     *
     * @return void
     */
    public function register_help_tabs(): void {
        $screen = get_current_screen();

        if ( ! $screen ) {
            return;
        }

        if ( ! empty( $this->config['help_tabs'] ) ) {
            foreach ( $this->config['help_tabs'] as $tab_id => $tab ) {
                $screen->add_help_tab( [
					'id'       => $this->id . '_' . $tab_id,
					'title'    => $tab['title'] ?? $tab_id,
					'content'  => $tab['content'] ?? '',
					'callback' => $tab['callback'] ?? null,
					'priority' => $tab['priority'] ?? 10,
                ] );
            }
        }

        if ( ! empty( $this->config['help_sidebar'] ) ) {
            $screen->set_help_sidebar( $this->config['help_sidebar'] );
        }
    }

    /**
     * Render the reports page.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( $this->config['capability'] ) ) {
            return;
        }

        // Get current tab and date range
        $current_tab      = $this->get_current_tab();
        $this->date_range = $this->get_current_date_range();

        // Add current filter values to date_range for callbacks
        $this->date_range['filters'] = $this->get_current_filters( $current_tab );

        // Render header OUTSIDE .wrap (matches RegisterSettingFields pattern)
        $this->render_header( $current_tab );

        ?>
        <div class="wrap reports-wrap" data-report-id="<?php echo esc_attr( $this->id ); ?>">

            <div class="reports-notices">
                <?php settings_errors( $this->id . '_notices' ); ?>
            </div>

            <div class="reports-content">
                <?php $this->render_tab_content( $current_tab ); ?>
            </div>
        </div>
        <?php
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
     * Render the modern header with optional logo, tabs, and date picker.
     *
     * Rendered outside .wrap to match RegisterSettingFields/EDD pattern.
     *
     * @param string $current_tab Current active tab.
     *
     * @return void
     */
    protected function render_header( string $current_tab ): void {
        $header_title = ! empty( $this->config['header_title'] )
                ? $this->config['header_title']
                : $this->config['page_title'];

        $show_title  = $this->config['show_title'] ?? true;
        $has_title   = $show_title && ! empty( $header_title );
        $has_tabs    = $this->config['show_tabs'] && ! empty( $this->tabs );
        $logo_url    = (string) ( $this->config['logo'] ?? '' );
        $tab_filters = $this->tabs[ $current_tab ]['filters'] ?? [];

        // Nothing to show: the rule still has to be there, since it is where
        // core moves admin notices to.
        if ( '' === $logo_url && ! $has_title && ! $has_tabs && ! $this->config['show_date_picker'] ) {
            echo '<hr class="wp-header-end">';

            return;
        }

        // The kit's header, which is core's own privacy-settings header. This
        // used to be a header of its own — a different height, a different
        // type scale, a slash between the logo and the title — so a plugin
        // with a settings page, a list table and a reports screen had three
        // headers that were nearly but not quite the same. There is one now.
        //
        // The refresh control and the date range go in the actions slot on
        // the right, which is the whole reason that slot exists.
        ob_start();
        $this->render_header_actions();
        $actions = (string) ob_get_clean();

        $header = PageHeader::render(
                [
					'title'         => $has_title ? $header_title : '',
					'logo'          => $logo_url,
					'logo_position' => (string) ( $this->config['logo_position'] ?? 'beside' ),
					'tabs'          => $has_tabs ? $this->header_tabs() : [],
					'current'       => $current_tab,
					'actions'       => $actions,
                ]
        );

        echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.

        if ( ! empty( $tab_filters ) ) {
            $this->render_filter_bar( $tab_filters );
        }
    }

    /**
     * The tabs, in the shape the kit's header wants.
     *
     * @return array<string, array{label: string, url: string, icon: string}>
     */
    protected function header_tabs(): array {
        $tabs = [];

        foreach ( $this->tabs as $key => $tab ) {
            $tabs[ $key ] = [
				'label' => (string) ( $tab['label'] ?? $key ),
				'url'   => $this->get_tab_url( (string) $key ),

				// The library's own configuration spells these
				// 'dashicons-chart-bar'; the kit takes the name alone.
				'icon'  => ltrim( (string) ( $tab['icon'] ?? '' ), 'dashicons-' ),
            ];
        }

        return $tabs;
    }

    /**
     * The refresh control and the date range, for the header's actions slot.
     *
     * @return void
     */
    protected function render_header_actions(): void {
        $show_refresh = $this->config['show_refresh'] ?? true;
        $auto_refresh = (int) ( $this->config['auto_refresh'] ?? 0 );

        if ( $show_refresh || $auto_refresh > 0 ) {
            printf(
                    '<div class="reports-refresh-controls" data-auto-refresh="%s" data-report-id="%s">',
                    esc_attr( (string) $auto_refresh ),
                    esc_attr( $this->id )
            );

            if ( $auto_refresh > 0 ) {
                printf(
                        '<span class="reports-last-updated"><span class="reports-last-updated-text">%s</span></span>',
                        esc_html__( 'Updated just now', 'arraypress' )
                );
            }

            if ( $show_refresh ) {
                printf(
                        '<button type="button" class="reports-refresh-button" title="%s">' .
                        '<span class="dashicons dashicons-update" aria-hidden="true"></span>' .
                        '<span class="screen-reader-text">%1$s</span></button>',
                        esc_attr__( 'Refresh', 'arraypress' )
                );
            }

            echo '</div>';
        }

        if ( $this->config['show_date_picker'] ) {
            $this->render_date_picker();
        }
    }

    /**
     * Render the filter bar for a tab.
     *
     * @param array $filters Filter configuration.
     *
     * @return void
     */
    protected function render_filter_bar( array $filters ): void {
        ?>
        <div class="reports-filter-bar">
            <form class="reports-filter-form" method="get">
                <?php
                // Preserve existing params
                $preserve = [ 'page', 'tab', 'date_preset', 'date_start', 'date_end' ];
                foreach ( $preserve as $param ) {
                    if ( isset( $_GET[ $param ] ) ) {
                        printf(
                                '<input type="hidden" name="%s" value="%s">',
                                esc_attr( $param ),
                                esc_attr( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) )
                        );
                    }
                }
                ?>

                <div class="reports-filter-fields">
                    <?php
                    foreach ( $filters as $filter_key => $filter ) :
                        $this->render_filter_field( $filter_key, $filter );
                    endforeach;
                    ?>
                </div>

                <button type="submit" class="button reports-filter-submit">
                    <?php esc_html_e( 'Filter', 'arraypress' ); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Render a single filter field.
     *
     * @param string $filter_key Filter key.
     * @param array  $filter     Filter configuration.
     *
     * @return void
     */
    protected function render_filter_field( string $filter_key, array $filter ): void {
        $type          = $filter['type'] ?? 'select';
        $label         = $filter['label'] ?? ucfirst( $filter_key );
        $param_name    = 'filter_' . $filter_key;
        $current_value = isset( $_GET[ $param_name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param_name ] ) ) : ( $filter['default'] ?? '' );

        ?>
        <div class="reports-filter-field reports-filter-<?php echo esc_attr( $type ); ?>">
            <label for="<?php echo esc_attr( $param_name ); ?>"><?php echo esc_html( $label ); ?></label>

            <?php if ( $type === 'select' ) : ?>
                <select name="<?php echo esc_attr( $param_name ); ?>" id="<?php echo esc_attr( $param_name ); ?>">
                    <?php foreach ( $filter['options'] ?? [] as $value => $option_label ) : ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_value, $value ); ?>>
                            <?php echo esc_html( $option_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            <?php elseif ( $type === 'checkbox' ) : ?>
                <input type="checkbox"
                        name="<?php echo esc_attr( $param_name ); ?>"
                        id="<?php echo esc_attr( $param_name ); ?>"
                        value="1"
                        <?php checked( $current_value, '1' ); ?>>

            <?php elseif ( $type === 'text' ) : ?>
                <input type="text"
                        name="<?php echo esc_attr( $param_name ); ?>"
                        id="<?php echo esc_attr( $param_name ); ?>"
                        value="<?php echo esc_attr( $current_value ); ?>"
                        placeholder="<?php echo esc_attr( $filter['placeholder'] ?? '' ); ?>">

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render content for a specific tab.
     *
     * @param string $tab Tab key.
     *
     * @return void
     */
    protected function render_tab_content( string $tab ): void {
        $tab_components = $this->get_components_for_tab( $tab );
        $tab_exports    = $this->get_exports_for_tab( $tab );

        // Check for custom render callback on tab
        if ( isset( $this->tabs[ $tab ]['render_callback'] ) && is_callable( $this->tabs[ $tab ]['render_callback'] ) ) {
            call_user_func( $this->tabs[ $tab ]['render_callback'], $this->date_range, $this );

            return;
        }

        // Render exports section if present
        if ( ! empty( $tab_exports ) ) {
            $exports_columns = $this->tabs[ $tab ]['exports_columns'] ?? $this->config['exports_columns'] ?? 0;
            $this->render_exports_section( $tab_exports, $exports_columns );
        }

        // Render components
        if ( ! empty( $tab_components ) ) {
            $this->render_components( $tab_components );
        }

        // Show empty state if no content
        if ( empty( $tab_components ) && empty( $tab_exports ) && ! isset( $this->tabs[ $tab ]['render_callback'] ) ) {
            $this->render_empty_state();
        }
    }

    /**
     * Render empty state.
     *
     * @return void
     */
    protected function render_empty_state(): void {
        ?>
        <div class="reports-empty-state">
            <span class="dashicons dashicons-chart-bar"></span>
            <h3><?php esc_html_e( 'No Reports Configured', 'arraypress' ); ?></h3>
            <p><?php esc_html_e( 'Add components or a render callback to display reports here.', 'arraypress' ); ?></p>
        </div>
        <?php
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