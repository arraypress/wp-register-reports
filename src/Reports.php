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
		// Branded header options
		'logo'             => '',
		'header_title'     => '',
		'header_class'     => '',
		// Date range options
		'date_presets'     => [
			'today'      => 'Today',
			'yesterday'  => 'Yesterday',
			'this_week'  => 'This Week',
			'last_week'  => 'Last Week',
			'this_month' => 'This Month',
			'last_month' => 'Last Month',
			'this_year'  => 'This Year',
			'last_year'  => 'Last Year',
			'custom'     => 'Custom Range',
		],
		'default_preset'   => 'this_month',
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
		$this->id     = sanitize_key( $id );
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
		add_action( 'admin_init', [ $this, 'handle_export_request' ] );

		// Fix menu highlight for submenu pages
		if ( ! empty( $this->config['parent_slug'] ) ) {
			add_filter( 'parent_file', [ $this, 'fix_parent_menu_highlight' ] );
			add_filter( 'submenu_file', [ $this, 'fix_submenu_highlight' ] );
		}
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

		?>
		<div class="wrap reports-wrap" data-report-id="<?php echo esc_attr( $this->id ); ?>">

			<?php $this->render_header( $current_tab ); ?>

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
	 * Render the modern header with optional logo, tabs, and date picker.
	 *
	 * @param string $current_tab Current active tab.
	 *
	 * @return void
	 */
	protected function render_header( string $current_tab ): void {
		$logo_url     = $this->config['logo'] ?? '';
		$header_title = ! empty( $this->config['header_title'] ) ? $this->config['header_title'] : $this->config['page_title'];
		$show_title   = $this->config['show_title'] ?? true;

		?>
		<div class="reports-header">
			<div class="reports-header-top">
				<div class="reports-header-branding">
					<?php if ( $logo_url ) : ?>
						<img src="<?php echo esc_url( $logo_url ); ?>" alt="" class="reports-header-logo">
					<?php endif; ?>
					<?php if ( $show_title ) : ?>
						<h1 class="reports-header-title"><?php echo esc_html( $header_title ); ?></h1>
					<?php endif; ?>
				</div>

				<?php if ( $this->config['show_date_picker'] ) : ?>
					<div class="reports-header-actions">
						<?php $this->render_date_picker(); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $this->config['show_tabs'] && ! empty( $this->tabs ) ) : ?>
				<div class="reports-header-tabs">
					<?php $this->render_tabs( $current_tab ); ?>
				</div>
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
			$this->render_exports_section( $tab_exports );
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
			<h3><?php esc_html_e( 'No Reports Configured', 'reports' ); ?></h3>
			<p><?php esc_html_e( 'Add components or a render callback to display reports here.', 'reports' ); ?></p>
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
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	public function get_config( string $key, $default = null ) {
		return $this->config[ $key ] ?? $default;
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
