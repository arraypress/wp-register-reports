<?php
/**
 * Stylesheet tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use PHPUnit\Framework\TestCase;

/**
 * CSS has no errors, so a stylesheet is where a mistake lives longest.
 *
 * A rule for a class nothing renders does nothing and says nothing; a rule
 * whose braces do not balance takes every rule after it with it. Neither is
 * visible until somebody looks at the page.
 */
final class StylesheetTest extends TestCase {

	/**
	 * The stylesheet.
	 *
	 * @return string
	 */
	private function css(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/reports.css' );
	}

	/**
	 * Everything this library renders, markup and script alike.
	 *
	 * @return string
	 */
	private function rendered(): string {
		$rendered = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/reports.js' );

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' )
		);

		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$rendered .= (string) file_get_contents( $file->getPathname() );
			}
		}

		return $rendered;
	}

	/**
	 * Every class styled here is one this library actually renders.
	 */
	public function test_no_rule_targets_a_class_nothing_renders(): void {
		$rendered = $this->rendered();
		$orphans  = [];

		// Comments out of the way first: one naming a class it explains is
		// prose, not a rule, and reporting it sends somebody looking for a
		// selector that is not there.
		$css = (string) preg_replace( '{/\\*.*?\\*/}s', '', $this->css() );

		preg_match_all( '/\\.(reports-[a-z0-9-]+)/', $css, $matches );

		foreach ( array_unique( $matches[1] ) as $class ) {
			if ( str_contains( $rendered, $class ) ) {
				continue;
			}

			// Half of these are built by interpolation — a tiles grid emits
			// `reports-tiles-columns-` and the number, a row action emits
			// `reports-row-action-` and the key — so the whole class name is
			// nowhere in the source. The prefix is what to look for.
			$prefix = (string) preg_replace( '/-[a-z0-9]+$/', '-', $class );

			if ( $prefix !== $class && str_contains( $rendered, $prefix ) ) {
				continue;
			}

			$orphans[] = $class;
		}

		sort( $orphans );

		$this->assertSame(
			[],
			$orphans,
			"Styled, but nothing renders them:\n  " . implode( "\n  ", $orphans )
		);
	}

	/**
	 * Every brace is closed.
	 */
	public function test_the_braces_balance(): void {
		$css = $this->css();

		$this->assertSame( substr_count( $css, '{' ), substr_count( $css, '}' ) );
	}

	/**
	 * No comment sits between a comma and an opening brace.
	 *
	 * That merges two rules: the selectors before it keep matching but take
	 * the next rule's declarations.
	 */
	public function test_no_comment_sits_inside_a_selector_list(): void {
		$merged = [];

		preg_match_all( '/(?:^|\\})([^{}]*)\\{/', $this->css(), $rules );

		foreach ( $rules[1] as $selector ) {
			$before = explode( '/*', $selector )[0];

			if ( str_contains( $selector, '/*' ) && str_contains( $before, ',' ) ) {
				$merged[] = trim( explode( "\n", trim( $before ) )[0] );
			}
		}

		$this->assertSame( [], $merged, implode( ', ', $merged ) );
	}

	/**
	 * The tile grid takes the whole row it is in.
	 *
	 * `.reports-components` is a wrapping flex row and the grid is one of its
	 * children, so it is sized by its content unless told otherwise — which
	 * it was not. `minmax(220px, 1fr)` then resolved against a width the grid
	 * had not been given, came out as a single 220px column, and every tile
	 * stacked down the left-hand side of an empty screen. The report looked
	 * broken and every rule involved was correct.
	 */
	public function test_the_tile_grid_fills_its_row(): void {
		preg_match( '/\.reports-tiles-grid\s*\{([^}]*)\}/', $this->css(), $rule );

		$this->assertNotEmpty( $rule, 'The tile grid has no rule.' );
		$this->assertStringContainsString( 'display: grid', $rule[1] );
		$this->assertMatchesRegularExpression(
			'/(width:\s*100%|flex:\s*[^;]*100%)/',
			$rule[1],
			'The grid is a flex child with no width, so it will collapse to one column.'
		);
	}

	/**
	 * A selector is not declared in two places.
	 *
	 * `.reports-components` was declared twice, five hundred lines apart —
	 * `display: flex` in one and `flex-direction` in the other — so reading
	 * either one told you half of what the container does.
	 */
	public function test_a_selector_is_not_declared_in_two_places(): void {
		// Top-level rules only: the same selector inside a media query is the
		// point of media queries.
		$css = (string) preg_replace( '/@media[^{]*\{(?:[^{}]*\{[^}]*\})*[^}]*\}/s', '', $this->css() );

		preg_match_all( '/(?:^|\})\s*([^{}@\/]+?)\s*\{/m', $css, $found );

		$seen  = [];
		$twice = [];

		foreach ( $found[1] as $selector ) {
			$selector = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );

			if ( '' === $selector || str_starts_with( $selector, '*' ) ) {
				continue;
			}

			if ( isset( $seen[ $selector ] ) ) {
				$twice[] = $selector;
			}

			$seen[ $selector ] = true;
		}

		$this->assertSame( [], array_values( array_unique( $twice ) ) );
	}

	/**
	 * The library styles for WordPress's own breakpoint.
	 *
	 * 782px is where core stacks the form table and grows every control to a
	 * touch target. A library that renders its own layout has to meet it
	 * there or the two disagree halfway down the page.
	 */
	public function test_there_are_styles_for_a_narrow_screen(): void {
		$this->assertStringContainsString( '@media screen and (max-width: 782px)', $this->css() );
	}
}
