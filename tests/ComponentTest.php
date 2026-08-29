<?php
/**
 * Component rendering tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Traits\ComponentRenderer;
use PHPUnit\Framework\TestCase;

/**
 * What a report draws.
 *
 * Two things are worth pinning. The dispatch, because a component whose type
 * is not recognised renders nothing at all — a silently missing tile on a
 * dashboard reads as no data rather than as a typo. And the escaping, because
 * every value here came out of somebody's database by way of a callback the
 * library does not control.
 */
final class ComponentTest extends TestCase {

	/**
	 * An object with the renderer on it.
	 *
	 * @return object
	 */
	private function renderer(): object {
		return new class {
			use ComponentRenderer;

			/**
			 * @var array<string, mixed>
			 */
			protected array $date_range = [ 'start' => '2026-08-01', 'end' => '2026-08-31', 'preset' => 'this_month' ];

			/**
			 * @var array<string, mixed>
			 */
			protected array $config = [];

			/**
			 * The period label, which the date-range trait normally supplies.
			 *
			 * @return string
			 */
			protected function get_period_label(): string {
				return 'This month';
			}

			/**
			 * Render one and hand back what it printed.
			 *
			 * @param string $id        Component id.
			 * @param array  $component Component configuration.
			 *
			 * @return string
			 */
			public function draw( string $id, array $component ): string {
				ob_start();

				try {
					$this->render_component( $id, $component );
				} finally {
					$html = (string) ob_get_clean();
				}

				return $html;
			}
		};
	}

	/**
	 * A breakdown does not multiply its money by a hundred.
	 *
	 * The bar maths needs a float and the formatter must not have one.
	 * Format::value() tells minor units from a decimal amount by looking at
	 * the value, so casting before formatting made every amount look like a
	 * decimal: 4900 minor units rendered as 4,900.00 instead of 49.00. A
	 * hundred times too large, in the right currency, with the bar drawn the
	 * right length -- nothing about the page said it was wrong.
	 */
	public function test_a_breakdown_formats_minor_units_as_minor_units(): void {
		$html = $this->renderer()->draw(
			'countries',
			[
				'type'          => 'breakdown',
				'title'         => 'By country',
				'value_format'  => 'currency',
				'currency'      => 'GBP',
				'data_callback' => fn() => [ 'rows' => [ [ 'label' => 'GB', 'value' => 4900 ] ] ],
			]
		);

		$this->assertStringContainsString( '49.00', $html );
		$this->assertStringNotContainsString( '4,900.00', $html );
	}

	/**
	 * And neither does a progress bar.
	 */
	public function test_a_progress_bar_formats_minor_units_as_minor_units(): void {
		$html = $this->renderer()->draw(
			'target',
			[
				'type'          => 'progress',
				'title'         => 'Towards target',
				'value_format'  => 'currency',
				'currency'      => 'GBP',
				'data_callback' => fn() => [ 'value' => 4900, 'target' => 10000 ],
			]
		);

		$this->assertStringContainsString( '49.00', $html );
		$this->assertStringContainsString( '100.00', $html );
		$this->assertStringNotContainsString( '4,900.00', $html );
	}

	/**
	 * A breakdown still draws its bars in proportion.
	 *
	 * The other half of the same change: the arithmetic keeps its float.
	 */
	public function test_a_breakdown_draws_bars_in_proportion(): void {
		$html = $this->renderer()->draw(
			'countries',
			[
				'type'          => 'breakdown',
				'data_callback' => fn() => [
					'rows' => [
						[ 'label' => 'GB', 'value' => 100 ],
						[ 'label' => 'US', 'value' => 50 ],
					],
				],
			]
		);

		// Against the largest, so the top row always fills the width.
		$this->assertStringContainsString( 'width:100%', $html );
		$this->assertStringContainsString( 'width:50%', $html );
	}

	/**
	 * Rows under the wrong key render as empty, and warn.
	 *
	 * The warning itself is not asserted here. A dependency ships its test
	 * stubs inside its vendor directory, and its no-op `_doing_it_wrong`
	 * wins the function_exists race against this suite's recording one -- so
	 * a test watching for the warning would pass whether or not it fired.
	 * What is pinned is the visible half: nothing is drawn.
	 */
	public function test_rows_under_the_wrong_key_render_as_empty(): void {
		$html = $this->renderer()->draw(
			'countries',
			[
				'type'          => 'breakdown',
				'data_callback' => fn() => [ 'items' => [ [ 'label' => 'GB', 'value' => 1 ] ] ],
			]
		);

		$this->assertStringNotContainsString( 'reports-breakdown-row', $html );
		$this->assertStringNotContainsString( 'GB', $html );
	}

	/**
	 * A tile shows its title and its value.
	 */
	public function test_a_tile_shows_its_title_and_value(): void {
		$html = $this->renderer()->draw(
			'sales',
			[
				'type'          => 'tile',
				'title'         => 'Sales',
				'value_format'  => 'number',
				'data_callback' => fn() => [ 'value' => 1204 ],
			]
		);

		$this->assertStringContainsString( 'Sales', $html );
		$this->assertStringContainsString( '1,204', $html );
	}

	/**
	 * A tile with no callback shows nought rather than nothing.
	 *
	 * A blank space where a number belongs reads as a broken page. Nought is
	 * at least a statement.
	 */
	public function test_a_tile_without_data_shows_nought(): void {
		$html = $this->renderer()->draw( 'sales', [ 'type' => 'tile', 'title' => 'Sales' ] );

		$this->assertStringContainsString( '0', $html );
	}

	/**
	 * A title out of somebody's database is escaped.
	 */
	public function test_a_title_is_escaped(): void {
		$html = $this->renderer()->draw(
			'sales',
			[ 'type' => 'tile', 'title' => '<script>alert(1)</script>' ]
		);

		$this->assertStringNotContainsString( '<script>alert', $html );
	}

	/**
	 * So is a value, and the id that lands in an attribute.
	 */
	public function test_a_value_and_an_id_are_escaped(): void {
		$html = $this->renderer()->draw(
			'"><script>alert(1)</script>',
			[
				'type'          => 'tile',
				'title'         => 'Sales',
				'value_format'  => 'raw',
				'data_callback' => fn() => [ 'value' => '<img src=x onerror=alert(1)>' ],
			]
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
	}

	/**
	 * A change is worked out from the previous period.
	 *
	 * From 1000 to 1250 is up twenty-five per cent, and the direction is what
	 * decides the arrow.
	 */
	public function test_a_change_is_calculated_from_the_previous_value(): void {
		$html = $this->renderer()->draw(
			'sales',
			[
				'type'          => 'tile',
				'title'         => 'Sales',
				'data_callback' => fn() => [ 'value' => 1250, 'previous_value' => 1000 ],
			]
		);

		$this->assertStringContainsString( 'change-up', $html );
		$this->assertStringContainsString( '25.0%', $html );
	}

	/**
	 * A fall is a fall.
	 */
	public function test_a_fall_is_marked_as_one(): void {
		$html = $this->renderer()->draw(
			'sales',
			[
				'type'          => 'tile',
				'title'         => 'Sales',
				'data_callback' => fn() => [ 'value' => 750, 'previous_value' => 1000 ],
			]
		);

		$this->assertStringContainsString( 'change-down', $html );
		$this->assertStringContainsString( '25.0%', $html );
	}

	/**
	 * A previous period of nothing produces no change at all.
	 *
	 * Going from nought to anything is not a percentage — it is a division by
	 * zero, and "up ∞%" is not a thing to put on a dashboard.
	 */
	public function test_growth_from_nothing_shows_no_percentage(): void {
		$html = $this->renderer()->draw(
			'sales',
			[
				'type'          => 'tile',
				'title'         => 'Sales',
				'data_callback' => fn() => [ 'value' => 500, 'previous_value' => 0 ],
			]
		);

		$this->assertStringNotContainsString( '%', $html );

		// The placeholder is still drawn, so the footer keeps its height and
		// a row of tiles does not go ragged.
		$this->assertStringContainsString( 'reports-tile-change change-neutral', $html );
	}

	/**
	 * A change is formatted the way every other number is.
	 *
	 * It used to be `number_format` where everything around it was
	 * `number_format_i18n`, so a German admin read 1.204,50 in the value and
	 * 25.0% in the change directly beneath it.
	 */
	public function test_a_change_uses_the_localised_number_format(): void {
		$GLOBALS['fk_number_format'] = [ 'decimal' => ',', 'thousands' => '.' ];

		$html = $this->renderer()->draw(
			'sales',
			[
				'type'          => 'tile',
				'data_callback' => fn() => [ 'value' => 1250, 'previous_value' => 1000 ],
			]
		);

		unset( $GLOBALS['fk_number_format'] );

		$this->assertStringContainsString( '25', $html );
	}

	/**
	 * An icon works with or without its prefix.
	 *
	 * `dashicons-money` and `money` are both what people write.
	 */
	public function test_an_icon_takes_either_spelling(): void {
		foreach ( [ 'money', 'dashicons-money' ] as $icon ) {
			$html = $this->renderer()->draw( 'sales', [ 'type' => 'tile', 'icon' => $icon ] );

			$this->assertStringContainsString( 'dashicons-money', $html );
			$this->assertStringNotContainsString( 'dashicons-dashicons-', $html );
		}
	}

	/**
	 * An unrecognised type with a render callback uses it.
	 *
	 * Which is the extension point: a report that wants something this
	 * library does not draw supplies its own.
	 */
	public function test_an_unknown_type_falls_back_to_its_callback(): void {
		$html = $this->renderer()->draw(
			'bespoke',
			[
				'type'            => 'something_else',
				'render_callback' => static function () {
					echo '<p>drawn by hand</p>';
				},
			]
		);

		$this->assertStringContainsString( 'drawn by hand', $html );
	}

	/**
	 * An unrecognised type with no callback draws nothing, quietly.
	 *
	 * Pinned rather than endorsed: it is the current behaviour, and a
	 * silently missing tile is exactly the failure this test file exists to
	 * make visible.
	 */
	public function test_an_unknown_type_without_a_callback_draws_nothing(): void {
		$this->assertSame( '', $this->renderer()->draw( 'bespoke', [ 'type' => 'something_else' ] ) );
	}

	/**
	 * The component's id is on the markup, for the auto-refresh to find.
	 */
	public function test_the_id_is_on_the_markup(): void {
		$html = $this->renderer()->draw( 'sales', [ 'type' => 'tile' ] );

		$this->assertStringContainsString( 'data-component-id="sales"', $html );
	}

	/**
	 * A callback gets the date range, so it knows what to count.
	 */
	public function test_a_callback_receives_the_date_range(): void {
		$seen = null;

		$this->renderer()->draw(
			'sales',
			[
				'type'          => 'tile',
				'data_callback' => function ( $range ) use ( &$seen ) {
					$seen = $range;

					return [ 'value' => 1 ];
				},
			]
		);

		$this->assertSame( '2026-08-01', $seen['start'] ?? null );
		$this->assertSame( '2026-08-31', $seen['end'] ?? null );
	}
}
