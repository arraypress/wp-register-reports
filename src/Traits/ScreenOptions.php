<?php
/**
 * Screen Options
 *
 * @package     ArrayPress\RegisterReports
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Traits;

use ArrayPress\RegisterReports\Utils\Runtime;

/**
 * Which components a person wants to see, remembered for them.
 *
 * A report gathers up over time. Somebody adds a tile, then a chart, then a
 * table nobody outside finance reads, and the screen that was useful becomes
 * one everybody scrolls past. Core's answer to that is Screen Options, and it
 * has been the answer since 2008: the person reading the page decides what is
 * on it, the choice is theirs alone, and it survives a reload.
 *
 * So this is core's checkbox panel, not a panel of our own — same markup, same
 * position, same place people already look for it. What is stored is only the
 * hidden ones, per user and per report, so a component added later shows up by
 * default rather than being invisible to everyone who ever saved a preference.
 *
 * The date range deliberately does not live here. It is the first thing anyone
 * changes on a report and Screen Options is a drawer people forget exists;
 * burying the main control of the screen inside it is the kind of tidiness
 * that costs more than the clutter did.
 */
trait ScreenOptions {

	/**
	 * Hook the panel up.
	 *
	 * On `load-` for this screen, because core reads the panel while it is
	 * building the page — registering it any later registers it for the next
	 * request rather than this one.
	 *
	 * @return void
	 */
	protected function init_screen_options(): void {
		if ( ! ( $this->config['show_screen_options'] ?? true ) ) {
			return;
		}

		add_action( 'load-' . $this->hook_suffix, [ $this, 'register_screen_options' ] );
		add_filter( 'screen_settings', [ $this, 'render_screen_options' ], 10, 2 );
	}

	/**
	 * Tell core the screen has options, so the tab appears.
	 *
	 * @return void
	 */
	public function register_screen_options(): void {
		$screen = get_current_screen();

		if ( ! $screen || $screen->id !== $this->hook_suffix ) {
			return;
		}

		$this->save_screen_options();
	}

	/**
	 * Store a submitted preference.
	 *
	 * Its own nonce, and a capability check: the values are written against
	 * the current user, so a request that arrives without either is a request
	 * to change somebody's settings on their behalf.
	 *
	 * @return void
	 */
	protected function save_screen_options(): void {
		if ( ! isset( $_POST['reports_screen_options_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['reports_screen_options_nonce'] ) ),
			'reports_screen_options_' . $this->id
		) ) {
			return;
		}

		if ( ! current_user_can( (string) ( $this->config['capability'] ?? 'manage_options' ) ) ) {
			return;
		}

		$shown  = array_map( 'sanitize_key', (array) ( $_POST['reports_components'] ?? [] ) );
		$hidden = [];

		foreach ( array_keys( $this->hideable_components() ) as $component_id ) {
			if ( ! in_array( (string) $component_id, $shown, true ) ) {
				$hidden[] = (string) $component_id;
			}
		}

		// Only the hidden ones are stored. Storing the shown ones instead
		// would make every component added later invisible to everybody who
		// had ever saved a preference — which is a bug that arrives weeks
		// after the change that caused it.
		update_user_meta( get_current_user_id(), $this->screen_options_key(), $hidden );
	}

	/**
	 * The panel's markup, appended to core's own.
	 *
	 * @param string $settings What core and other plugins have put there.
	 * @param mixed  $screen   The screen it is for.
	 *
	 * @return string
	 */
	public function render_screen_options( string $settings, $screen ): string {
		if ( ! is_object( $screen ) || ( $screen->id ?? '' ) !== $this->hook_suffix ) {
			return $settings;
		}

		$components = $this->hideable_components();

		if ( [] === $components ) {
			return $settings;
		}

		$hidden = $this->hidden_components();
		$boxes  = '';

		foreach ( $components as $component_id => $label ) {
			$boxes .= sprintf(
				'<label for="%1$s"><input type="checkbox" name="reports_components[]" id="%1$s" value="%2$s"%3$s>%4$s</label>',
				esc_attr( 'reports-component-' . $this->id . '-' . $component_id ),
				esc_attr( (string) $component_id ),
				in_array( (string) $component_id, $hidden, true ) ? '' : ' checked',
				esc_html( $label )
			);
		}

		return $settings . sprintf(
			'<fieldset class="metabox-prefs reports-screen-options">' .
			'<legend>%s</legend>%s%s</fieldset>',
			esc_html__( 'Components', 'arraypress' ),
			$boxes,
			wp_nonce_field( 'reports_screen_options_' . $this->id, 'reports_screen_options_nonce', false, false )
		);
	}

	/**
	 * Components a person may turn off, as id => label.
	 *
	 * Everything with a title. A component with nothing to call it cannot be
	 * offered in a list of checkboxes, and inventing a name for it — "Chart
	 * 3" — offers a choice nobody can make.
	 *
	 * @return array<string, string>
	 */
	protected function hideable_components(): array {
		$hideable = [];

		// Components are keyed by tab and then by id, so this is two levels
		// deep rather than one.
		foreach ( $this->components as $tab_components ) {
			foreach ( (array) $tab_components as $component_id => $component ) {
				$title = (string) ( $component['title'] ?? '' );

				if ( '' !== $title && ! ( $component['always_show'] ?? false ) ) {
					$hideable[ (string) $component_id ] = $title;
				}
			}
		}

		return $hideable;
	}

	/**
	 * The ids this user has turned off.
	 *
	 * @return string[]
	 */
	public function hidden_components(): array {
		$hidden = get_user_meta( get_current_user_id(), $this->screen_options_key(), true );

		return is_array( $hidden ) ? array_map( 'strval', $hidden ) : [];
	}

	/**
	 * Whether a component should be drawn at all.
	 *
	 * @param string $component_id The component.
	 *
	 * @return bool
	 */
	protected function is_component_visible( string $component_id ): bool {
		return ! in_array( $component_id, $this->hidden_components(), true );
	}

	/**
	 * Where the preference is stored.
	 *
	 * Scoped by report id as well as by user: two reports on one install have
	 * different components, and one list of hidden ids shared between them
	 * would hide whatever happened to share a name.
	 *
	 * @return string
	 */
	protected function screen_options_key(): string {
		return Runtime::key( 'hidden_components_' . $this->id );
	}
}
