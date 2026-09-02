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
 * position, same place people already look for it, and it behaves the way
 * core's column toggles do: unticking a box hides the thing at once and the
 * choice is saved for this user over REST, with no Apply button to find. The
 * panel lists the tab on screen, not every tab. What is stored is only the
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
	 * @return void
	 */
	protected function init_screen_options(): void {
		if ( ! ( $this->config['show_screen_options'] ?? true ) ) {
			return;
		}

		add_filter( 'screen_settings', [ $this, 'render_screen_options' ], 10, 2 );
	}

	/**
	 * The panel's markup, appended to core's own.
	 *
	 * Only the tab on screen. The panel is about the page in front of the
	 * person, and a list of every component on every tab asked them to
	 * remember what "Top sources" was on a screen that was not showing it.
	 *
	 * No form and no Apply button. Core draws the button only for options
	 * that need one, such as items per page; the boxes it draws for meta
	 * boxes and columns act as soon as they are ticked, and these do the
	 * same -- the script hides the component and posts the choice. The
	 * fieldset carries what the script needs to say which report and tab it
	 * is speaking for.
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

		$tab        = $this->get_current_tab();
		$components = $this->hideable_components( $tab );

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
			'<fieldset class="metabox-prefs reports-screen-options" data-report="%s" data-tab="%s">' .
			'<legend>%s</legend>%s</fieldset>',
			esc_attr( $this->id ),
			esc_attr( $tab ),
			esc_html__( 'Components', 'arraypress' ),
			$boxes
		);
	}

	/**
	 * Components a person may turn off, as id => label.
	 *
	 * Everything with a title. A component with nothing to call it cannot be
	 * offered in a list of checkboxes, and inventing a name for it — "Chart
	 * 3" — offers a choice nobody can make.
	 *
	 * @param string $tab One tab's, or every tab's when empty.
	 *
	 * @return array<string, string>
	 */
	public function hideable_components( string $tab = '' ): array {
		$hideable = [];

		// Components are keyed by tab and then by id, so this is two levels
		// deep rather than one.
		$tabs = '' === $tab ? $this->components : [ $tab => $this->components[ $tab ] ?? [] ];

		foreach ( $tabs as $tab_components ) {
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
	 * Remember which of a tab's components this user has turned off.
	 *
	 * Only that tab's ids change hands. The panel shows one tab, so a
	 * request can only speak for one, and whatever is hidden on the others
	 * is carried across untouched. Ids the tab does not offer are ignored,
	 * so a request cannot hide a component that is always shown.
	 *
	 * @param string   $tab   The tab the panel was drawn for; every tab when empty.
	 * @param string[] $shown The ids still ticked.
	 *
	 * @return string[] Every id hidden for this user and report, after the change.
	 */
	public function save_hidden_components( string $tab, array $shown ): array {
		$offered = array_map( 'strval', array_keys( $this->hideable_components( $tab ) ) );
		$shown   = array_map( 'sanitize_key', array_map( 'strval', $shown ) );
		$hidden  = array_values( array_diff( $this->hidden_components(), $offered ) );

		foreach ( $offered as $component_id ) {
			if ( ! in_array( $component_id, $shown, true ) ) {
				$hidden[] = $component_id;
			}
		}

		// Only the hidden ones are stored. Storing the shown ones instead
		// would make every component added later invisible to everybody who
		// had ever saved a preference — which is a bug that arrives weeks
		// after the change that caused it.
		update_user_meta( get_current_user_id(), $this->screen_options_key(), $hidden );

		return $hidden;
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
