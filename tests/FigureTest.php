<?php
/**
 * Progress, breakdown and stat list tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Traits\ComponentRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The three shapes a chart is the wrong answer to.
 *
 * A progress bar is one number against a target — a chart of two values is
 * not a chart, and a number alone leaves the reader doing the division. A
 * breakdown is the ranked list a pie chart is usually asked to be and is bad
 * at, since people read angles poorly and a legend needs room a narrow admin
 * column does not have. A stat list is where the dozen secondary numbers go
 * that would otherwise each get a tile, turning a useful row into a wall.
 *
 * What is pinned here is mostly arithmetic and escaping, because that is what
 * silently goes wrong: a bar past the end of its track, a division by a target
 * nobody set, a label out of somebody's database landing in markup.
 */
final class FigureTest extends TestCase {

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
			 * @return string
			 */
			protected function get_period_label(): string {
				return 'This month';
			}

			/**
			 * @param string $id        Component id.
			 * @param array  $component Component configuration.
			 *
			 * @return string
			 */
			public function draw( string $id, array $component ): string {
				return $this->capture( fn() => $this->render_component( $id, $component ) );
			}

			/**
			 * @param array $data      What a callback returned.
			 * @param array $component Component configuration.
			 *
			 * @return string
			 */
			public function draw_bar( array $data, array $component ): string {
				return $this->capture( fn() => $this->render_progress_bar( $data, $component ) );
			}

			/**
			 * @param array $rows      Rows to draw.
			 * @param array $component Component configuration.
			 *
			 * @return string
			 */
			public function draw_breakdown_rows( array $rows, array $component ): string {
				return $this->capture( fn() => $this->render_breakdown_rows( $rows, $component ) );
			}

			/**
			 * @param array $rows      Rows to draw.
			 * @param array $component Component configuration.
			 *
			 * @return string
			 */
			public function draw_stat_rows( array $rows, array $component ): string {
				return $this->capture( fn() => $this->render_stat_rows( $rows, $component ) );
			}

			/**
			 * @param callable $render What to run.
			 *
			 * @return string
			 */
			private function capture( callable $render ): string {
				ob_start();

				try {
					$render();
				} finally {
					return (string) ob_get_clean();
				}
			}
		};
	}

	/**
	 * Render one component and hand back what it printed.
	 *
	 * @param array<string, mixed> $component Component configuration.
	 *
	 * @return string
	 */
	private function draw( array $component ): string {
		return $this->renderer()->draw( 'demo', $component );
	}

	/**
	 * A progress bar fills to its share of the target.
	 */
	public function test_a_progress_bar_fills_to_its_share(): void {
		$html = $this->draw(
			[
				'type'   => 'progress',
				'title'  => 'Monthly target',
				'target' => 1000,
				'rows'   => [],
			]
		);

		// Nothing yet: the element exists so the script has somewhere to
		// write, and reads nought until the data arrives.
		$this->assertStringContainsString( 'role="progressbar"', $html );
		$this->assertStringContainsString( 'aria-valuemax="100"', $html );
	}

	/**
	 * The bar stops at full rather than running past its track.
	 *
	 * A target that has been beaten is the normal case at the end of a good
	 * month, and a fill of 142% is a coloured bar sticking out of the panel.
	 */
	public function test_a_beaten_target_stops_at_full(): void {
		$html = $this->renderer()->draw_bar( [ 'value' => 1420, 'target' => 1000 ], [ 'title' => 'Orders' ] );

		$this->assertStringContainsString( 'width:100%', $html );
		$this->assertStringContainsString( 'aria-valuenow="100"', $html );
	}

	/**
	 * A target of nought does not divide by it.
	 *
	 * Which is the one arithmetic mistake here that takes the page with it
	 * rather than just looking wrong.
	 */
	public function test_a_target_of_nothing_is_survivable(): void {
		$html = $this->renderer()->draw_bar( [ 'value' => 500, 'target' => 0 ], [] );

		$this->assertStringContainsString( 'width:0%', $html );
		$this->assertStringContainsString( 'aria-valuenow="0"', $html );
	}

	/**
	 * A breakdown draws each bar against the largest row, not the total.
	 *
	 * Against the total, eight roughly equal things are eight identical stubs
	 * and the shape says nothing — which is the failure the component exists
	 * to avoid. Against the largest, the top row always fills and the rest
	 * are read relative to it.
	 */
	public function test_a_breakdown_is_drawn_against_its_largest_row(): void {
		$html = $this->renderer()->draw_breakdown_rows(
			[
				[ 'label' => 'Google', 'value' => 100 ],
				[ 'label' => 'Direct', 'value' => 50 ],
			],
			[ 'value_format' => 'number' ]
		);

		$this->assertStringContainsString( 'width:100%', $html );
		$this->assertStringContainsString( 'width:50%', $html );

		// And the share is against the total, because that is the question a
		// percentage answers.
		$this->assertStringContainsString( '66.7%', $html );
		$this->assertStringContainsString( '33.3%', $html );
	}

	/**
	 * A breakdown label is escaped.
	 *
	 * Labels are row values out of somebody's database by way of a callback
	 * this library does not control.
	 */
	public function test_a_breakdown_label_is_escaped(): void {
		$html = $this->renderer()->draw_breakdown_rows(
			[ [ 'label' => '<script>alert(1)</script>', 'value' => 1 ] ],
			[]
		);

		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * A breakdown with no rows says so rather than drawing nothing.
	 */
	public function test_an_empty_breakdown_has_something_to_say(): void {
		$html = $this->draw(
			[
				'type'  => 'breakdown',
				'title' => 'Top sources',
				'rows'  => [],
			]
		);

		$this->assertStringContainsString( 'reports-breakdown-empty', $html );
		$this->assertStringNotContainsString( 'hidden', $html );
	}

	/**
	 * A stat list is a definition list.
	 *
	 * Each row is a term and what it is defined as. A table would want a
	 * header saying "Name" and "Value", which tells the reader nothing.
	 */
	public function test_a_stat_list_is_a_definition_list(): void {
		$html = $this->renderer()->draw_stat_rows(
			[ [ 'label' => 'Average order value', 'value' => 4210, 'format' => 'currency' ] ],
			[]
		);

		$this->assertStringContainsString( '<dt>Average order value</dt>', $html );
		$this->assertStringContainsString( '<dd>$42.10</dd>', $html );
	}

	/**
	 * Each figure honours the width it was given.
	 *
	 * The width option did nothing at all for a long time — the renderer
	 * emitted `reports-component--half` and the stylesheet styled
	 * `reports-width-half` — so it is worth asserting rather than assuming.
	 *
	 * @dataProvider figureProvider
	 *
	 * @param string $type A component type.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'figureProvider' )]
	public function test_a_figure_takes_a_width( string $type ): void {
		$html = $this->draw( [ 'type' => $type, 'title' => 'X', 'width' => 'half', 'rows' => [] ] );

		$this->assertStringContainsString( 'reports-component--half', $html );
	}

	/**
	 * The three new types.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function figureProvider(): array {
		$types = [ 'progress', 'breakdown', 'stat_list' ];

		return array_combine( $types, array_map( static fn( string $one ): array => [ $one ], $types ) );
	}

	/**
	 * Each one says what it is, so the script can find it again.
	 *
	 * @dataProvider figureProvider
	 *
	 * @param string $type A component type.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'figureProvider' )]
	public function test_a_figure_is_identifiable( string $type ): void {
		$html = $this->draw( [ 'type' => $type, 'title' => 'X', 'rows' => [] ] );

		$this->assertStringContainsString( sprintf( 'data-component-type="%s"', $type ), $html );
		$this->assertStringContainsString( 'data-component-id="demo"', $html );
	}
}
