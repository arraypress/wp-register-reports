<?php
/**
 * Page chrome tests: tabs, date picker, refresh.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Traits\DateRangeHandler;
use ArrayPress\RegisterReports\Traits\PageRenderer;
use ArrayPress\RegisterReports\Traits\TabManager;
use PHPUnit\Framework\TestCase;

/**
 * Nothing on this page's chrome carries a glyph in front of its label.
 *
 * The controls bar had a calendar in the date picker, an icon-only refresh
 * button, and a dashicon on every tab. WordPress does none of those in its
 * own admin: .nav-tab has no icon, no core button puts a picture before its
 * text, and the one icon-only control core does draw — the collapse arrow —
 * is a control with nowhere to put a label.
 *
 * The heights were worse than the icons. Three separate rules set the date
 * picker's height, at 36px and then twice at 30px, against a core button
 * whose min-height is 40px in this version of WordPress. All three lost, so
 * the picker was 40px anyway and the CSS said three contradictory things
 * about a number none of it controlled.
 */
final class ChromeTest extends TestCase {

	/**
	 * A harness carrying the traits that draw the chrome.
	 *
	 * @param array<string, mixed> $tabs Tabs to draw.
	 *
	 * @return object
	 */
	private function page( array $tabs = [] ): object {
		return new class( $tabs ) {
			use DateRangeHandler;
			use PageRenderer;
			use TabManager;

			/**
			 * @var array<string, mixed>
			 */
			public array $config = [];

			/**
			 * @var array<string, mixed>
			 */
			public array $tabs = [];

			/**
			 * @var array<string, mixed>
			 */
			public array $components = [];

			/**
			 * @var array<string, mixed>
			 */
			public array $date_range = [ 'preset' => 'this_month', 'start' => '', 'end' => '' ];

			/**
			 * @var string
			 */
			public string $id = 'demo';

			/**
			 * @param array<string, mixed> $tabs Tabs to draw.
			 */
			public function __construct( array $tabs ) {
				$this->tabs = $tabs;
			}

			/**
			 * A tab's URL. The real one reads the current screen.
			 *
			 * @param string $tab Tab key.
			 *
			 * @return string
			 */
			public function get_tab_url( string $tab ): string {
				return 'https://example.test/wp-admin/admin.php?page=demo&tab=' . $tab;
			}

			/**
			 * Capture whatever a method echoes.
			 *
			 * @param string $method Method to call.
			 * @param mixed  ...$args Arguments for it.
			 *
			 * @return string
			 */
			public function capture( string $method, ...$args ): string {
				ob_start();

				try {
					$this->$method( ...$args );
				} finally {
					// Or a method that throws leaves its buffer on the stack
					// and every later test reads as risky for its trouble.
					$markup = (string) ob_get_clean();
				}

				return $markup;
			}
		};
	}

	/**
	 * The date picker says which range is showing, and nothing else.
	 *
	 * The caret stays: it is the only cue that the button opens a menu
	 * rather than doing something, which is a different job from decoration.
	 */
	public function test_the_date_picker_carries_no_calendar(): void {
		$html = $this->page()->capture( 'render_date_picker' );

		$this->assertStringNotContainsString( 'dashicons-calendar-alt', $html );
		$this->assertStringContainsString( 'dashicons-arrow-down-alt2', $html );
		$this->assertStringContainsString( 'aria-expanded="false"', $html );
	}

	/**
	 * It is a core button and says so, rather than restating core's metrics.
	 */
	public function test_the_date_picker_is_a_core_button(): void {
		$html = $this->page()->capture( 'render_date_picker' );

		$this->assertMatchesRegularExpression(
			'/class="reports-date-picker-toggle button"/',
			$html
		);

		// Nothing here may set its height: core's min-height decides it, and
		// a smaller number simply loses without saying so. Checked rule by
		// rule rather than with one expression over the whole file, so a
		// descendant rule -- the caret's own 16px box -- is not mistaken for
		// the button's.
		foreach ( $this->rules_for( '.reports-date-picker-toggle' ) as $selector => $body ) {
			$this->assertDoesNotMatchRegularExpression(
				'/(^|[^-])height:/',
				$body,
				$selector . ' sets a height core already decides.'
			);
		}
	}

	/**
	 * The layout rule out-specifies the core rule it is overriding.
	 *
	 * Core declares `.wp-core-ui .button { display: inline-block }` at a
	 * specificity of 0,2,0. A bare `.reports-date-picker-toggle` is 0,1,0,
	 * so a `display: inline-flex` written that way loses without any warning
	 * and the caret drops onto the text's baseline -- a 20px glyph in a 20px
	 * line box beside 13px text, sitting low and making the button taller.
	 *
	 * The tempting fix is to restyle `.wp-core-ui .button .dashicons`
	 * globally, which reaches every core button on the page including ones
	 * this library never drew.
	 */
	public function test_the_toggles_layout_beats_cores_button_rule(): void {
		foreach ( $this->rules_for( '.reports-date-picker-toggle' ) as $selector => $body ) {
			if ( ! str_contains( $body, 'display:' ) ) {
				continue;
			}

			$this->assertStringContainsString(
				'.wp-core-ui',
				$selector,
				'A bare class loses to .wp-core-ui .button on specificity.'
			);
		}

		// Comments here discuss that selector by name; they are not rules.
		$css = (string) preg_replace(
			'#/\*.*?\*/#s',
			'',
			(string) file_get_contents( dirname( __DIR__ ) . '/assets/css/reports.css' )
		);

		$this->assertDoesNotMatchRegularExpression(
			'/\.wp-core-ui \.button(-primary|-secondary)? \.dashicons/',
			$css,
			'Styling every core button reaches buttons this library did not draw.'
		);
	}

	/**
	 * Every rule whose subject is the given selector.
	 *
	 * The subject, not a descendant of it: `.x .y` styles y, and a height on
	 * y says nothing about x.
	 *
	 * @param string $selector The selector.
	 *
	 * @return array<string, string> Selector list to declaration body.
	 */
	private function rules_for( string $selector ): array {
		$css   = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/reports.css' );
		$found = [];

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER );

		foreach ( $matches as $rule ) {
			foreach ( explode( ',', $rule[1] ) as $one ) {
				$one = trim( $one );

				// The subject is the last compound selector in the list.
				$subject = (string) strrchr( ' ' . $one, ' ' );

				if ( str_starts_with( trim( $subject ), $selector ) ) {
					$found[ $one ] = $rule[2];
				}
			}
		}

		return $found;
	}

	/**
	 * Refresh is a word, not a picture.
	 */
	public function test_refresh_is_a_labelled_button(): void {
		$html = $this->page()->capture( 'render_refresh_controls' );

		$this->assertStringContainsString( '>Refresh<', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
		$this->assertStringNotContainsString( 'screen-reader-text', $html );
	}

	/**
	 * The busy state is core's, so it spins and respects reduced motion.
	 */
	public function test_refreshing_uses_cores_updating_message(): void {
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/reports.js' );

		$this->assertStringContainsString( "addClass('refreshing updating-message')", $js );
		$this->assertStringContainsString( "removeClass('refreshing updating-message')", $js );

		// And the local imitation of it is gone.
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/reports.css' );
		$this->assertStringNotContainsString( 'reports-spin', $css );
	}

	/**
	 * A tab is its label.
	 */
	public function test_tabs_carry_no_icons(): void {
		$page = $this->page(
			[
				'tiles'  => [ 'label' => 'Tiles', 'icon' => 'dashicons-chart-bar' ],
				'charts' => [ 'label' => 'Charts', 'icon' => 'dashicons-chart-area' ],
			]
		);

		$html = $page->capture( 'render_tabs', 'tiles' );

		$this->assertStringNotContainsString( 'dashicons', $html );
		$this->assertStringContainsString( '>Tiles</a>', $html );
		$this->assertStringContainsString( '>Charts</a>', $html );
	}

	/**
	 * And the header tabs handed to the field kit carry none either.
	 */
	public function test_header_tabs_pass_no_icon_through(): void {
		$page = $this->page( [ 'tiles' => [ 'label' => 'Tiles', 'icon' => 'dashicons-chart-bar' ] ] );

		$reflection = new \ReflectionMethod( $page, 'header_tabs' );
		$tabs       = $reflection->invoke( $page );

		$this->assertArrayNotHasKey( 'icon', $tabs['tiles'] );
	}
}
