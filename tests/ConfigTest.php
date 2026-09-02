<?php
/**
 * Configuration parsing tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Traits\ConfigParser;
use PHPUnit\Framework\TestCase;

/**
 * A report is a configuration array, and this is what it becomes.
 *
 * The part worth pinning is the defaulting: a component that names no tab, a
 * tab written as a bare string, an ordering nobody set. Get one wrong and the
 * page still renders — with a component in the wrong tab, or in an order that
 * changes between requests, which reads as a caching problem rather than a
 * sorting one.
 */
final class ConfigTest extends TestCase {

	/**
	 * Parse a configuration and hand back what it produced.
	 *
	 * @param array<string, mixed> $config The configuration.
	 *
	 * @return object
	 */
	private function parse( array $config ): object {
		$parser = new class {
			use ConfigParser;

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
			public array $exports = [];

			/**
			 * Run the parse.
			 *
			 * @param array<string, mixed> $config The configuration.
			 *
			 * @return void
			 */
			public function run( array $config ): void {
				$this->config = $config;

				$this->parse_config();
			}
		};

		$parser->run( $config );

		return $parser;
	}

	/**
	 * Components with no tabs get one, so they have somewhere to be.
	 *
	 * A tab list of nothing and components that name a tab called `default`
	 * is a page that renders empty — every component filed under a heading
	 * that was never drawn.
	 */
	public function test_components_without_tabs_get_a_default_one(): void {
		$parsed = $this->parse( [ 'components' => [ 'sales' => [ 'type' => 'tile' ] ] ] );

		$this->assertArrayHasKey( 'default', $parsed->tabs );
		$this->assertArrayHasKey( 'sales', $parsed->components['default'] );
	}

	/**
	 * No components means no tabs either.
	 */
	public function test_nothing_configured_produces_nothing(): void {
		$parsed = $this->parse( [] );

		$this->assertSame( [], $parsed->tabs );
		$this->assertSame( [], $parsed->components );
	}

	/**
	 * A tab can be a bare string.
	 *
	 * `'overview' => 'Overview'` is what anyone writes first, and it should
	 * mean the same as the full array.
	 */
	public function test_a_tab_can_be_a_string(): void {
		$parsed = $this->parse( [ 'tabs' => [ 'overview' => 'Overview' ] ] );

		$this->assertSame( 'Overview', $parsed->tabs['overview']['label'] );
		$this->assertSame( '', $parsed->tabs['overview']['icon'] );
	}

	/**
	 * A tab with no label is named after its key.
	 */
	public function test_a_tab_without_a_label_is_named_after_its_key(): void {
		$parsed = $this->parse( [ 'tabs' => [ 'revenue' => [ 'icon' => 'dashicons-chart-bar' ] ] ] );

		$this->assertSame( 'Revenue', $parsed->tabs['revenue']['label'] );
	}

	/**
	 * A component with no tab goes in the first one.
	 *
	 * The first, not `default`: a report that declares tabs has no tab called
	 * `default`, so a component that fell back to that name would vanish.
	 */
	public function test_a_component_without_a_tab_goes_in_the_first(): void {
		$parsed = $this->parse(
			[
				'tabs'       => [ 'revenue' => 'Revenue', 'traffic' => 'Traffic' ],
				'components' => [ 'sales' => [ 'type' => 'tile' ] ],
			]
		);

		$this->assertArrayHasKey( 'sales', $parsed->components['revenue'] );
		$this->assertArrayNotHasKey( 'default', $parsed->components );
	}

	/**
	 * A component says which tab it is in.
	 */
	public function test_a_component_can_choose_its_tab(): void {
		$parsed = $this->parse(
			[
				'tabs'       => [ 'revenue' => 'Revenue', 'traffic' => 'Traffic' ],
				'components' => [ 'visits' => [ 'type' => 'tile', 'tab' => 'traffic' ] ],
			]
		);

		$this->assertArrayHasKey( 'visits', $parsed->components['traffic'] );
	}

	/**
	 * A component is titled after its key when it has no title.
	 *
	 * Underscores and hyphens become spaces, so `average_order_value` reads
	 * as "Average order value" rather than as itself.
	 */
	public function test_a_component_is_titled_after_its_key(): void {
		$parsed = $this->parse( [ 'components' => [ 'average_order_value' => [ 'type' => 'tile' ] ] ] );

		$this->assertSame( 'Average order value', $parsed->components['default']['average_order_value']['title'] );
	}

	/**
	 * A tile takes a tile's defaults.
	 */
	public function test_a_tile_takes_the_tile_defaults(): void {
		$parsed = $this->parse( [ 'components' => [ 'sales' => [ 'type' => 'tile' ] ] ] );
		$tile   = $parsed->components['default']['sales'];

		$this->assertSame( 'number', $tile['value_format'] );
		$this->assertSame( 'dashicons-chart-bar', $tile['icon'] );
		$this->assertSame( 10, $tile['order'] );
	}

	/**
	 * What a component sets wins over the default.
	 */
	public function test_a_component_overrides_its_defaults(): void {
		$parsed = $this->parse(
			[
				'components' => [
					'revenue' => [
						'type'         => 'tile',
						'title'        => 'Money in',
						'value_format' => 'currency',
						'order'        => 1,
					],
				],
			]
		);

		$tile = $parsed->components['default']['revenue'];

		$this->assertSame( 'Money in', $tile['title'] );
		$this->assertSame( 'currency', $tile['value_format'] );
		$this->assertSame( 1, $tile['order'] );
	}

	/**
	 * A component with no type is a tile.
	 *
	 * Which is the one most reports are mostly made of.
	 */
	public function test_a_component_without_a_type_is_a_tile(): void {
		$parsed = $this->parse( [ 'components' => [ 'sales' => [] ] ] );

		$this->assertSame( 'tile', $parsed->components['default']['sales']['type'] );
	}

	/**
	 * Components are grouped by tab, not left in one flat list.
	 */
	public function test_components_are_grouped_by_tab(): void {
		$parsed = $this->parse(
			[
				'tabs'       => [ 'a' => 'A', 'b' => 'B' ],
				'components' => [
					'one'   => [ 'tab' => 'a' ],
					'two'   => [ 'tab' => 'b' ],
					'three' => [ 'tab' => 'a' ],
				],
			]
		);

		$this->assertSame( [ 'one', 'three' ], array_keys( $parsed->components['a'] ) );
		$this->assertSame( [ 'two' ], array_keys( $parsed->components['b'] ) );
	}
}
