<?php
/**
 * Tab URL tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use ArrayPress\RegisterReports\Registry;
use ArrayPress\RegisterReports\Reports;
use PHPUnit\Framework\TestCase;

/**
 * Where a report's tabs link to.
 *
 * Every tab URL was built on admin.php, which is right only for a report with
 * no parent. WordPress serves a submenu page under whatever file its parent
 * slug names, so a report under `edit.php?post_type=book` lives at
 * `edit.php?post_type=book&page=my-report` — and `admin.php?page=my-report`
 * answers "Sorry, you are not allowed to access this page." A report anywhere
 * but the top level had tabs that refused to load.
 */
final class TabUrlTest extends TestCase {

	/**
	 * Forget the report.
	 */
	protected function tearDown(): void {
		Registry::instance()->unregister( 'takings' );

		$_GET = [];
	}

	/**
	 * A report under the given parent, with its URL builder reachable.
	 *
	 * @param string $parent_slug Where it hangs in the menu.
	 *
	 * @return Reports
	 */
	private function report( string $parent_slug ): Reports {
		return new class( 'takings', [ 'parent_slug' => $parent_slug, 'tabs' => [ 'sales' => 'Sales' ] ] ) extends Reports {

			/**
			 * @param string $tab Tab key.
			 *
			 * @return string
			 */
			public function url( string $tab ): string {
				return $this->get_tab_url( $tab );
			}

			/**
			 * @return array<string, string>
			 */
			public function args(): array {
				return $this->page_args();
			}
		};
	}

	/**
	 * The query arguments of a URL, as a map.
	 *
	 * @param string $url The URL.
	 *
	 * @return array<string, string>
	 */
	private function query( string $url ): array {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $args );

		return $args;
	}

	/**
	 * A top-level report lives under admin.php.
	 */
	public function test_a_top_level_report_is_under_admin_php(): void {
		$url = $this->report( '' )->url( 'sales' );

		$this->assertStringContainsString( 'admin.php?', $url );
		$this->assertSame( [ 'page' => 'takings', 'tab' => 'sales' ], $this->query( $url ) );
	}

	/**
	 * A parent that is itself a plugin page is served by admin.php too.
	 */
	public function test_a_plugin_page_parent_is_under_admin_php(): void {
		$this->assertStringContainsString( 'admin.php?', $this->report( 'my-plugin' )->url( 'sales' ) );
	}

	/**
	 * A report under a core file lives under that file.
	 */
	public function test_a_report_under_a_core_file_lives_there(): void {
		$url = $this->report( 'tools.php' )->url( 'sales' );

		$this->assertStringContainsString( 'tools.php?', $url );
		$this->assertStringNotContainsString( 'admin.php', $url );
	}

	/**
	 * A report under a post type keeps the post type in every link.
	 *
	 * The query string in the parent slug is part of the address. Without it
	 * WordPress cannot build the page hook, and the tab is a dead link.
	 */
	public function test_a_report_under_a_post_type_keeps_it(): void {
		$url = $this->report( 'edit.php?post_type=book' )->url( 'sales' );

		$this->assertStringContainsString( 'edit.php?', $url );
		$this->assertSame(
			[ 'post_type' => 'book', 'page' => 'takings', 'tab' => 'sales' ],
			$this->query( $url )
		);
	}

	/**
	 * The date range travels with the tab.
	 */
	public function test_the_date_range_travels_with_the_tab(): void {
		$_GET['date_preset'] = 'last_month';

		$this->assertSame( 'last_month', $this->query( $this->report( '' )->url( 'sales' ) )['date_preset'] ?? null );
	}

	/**
	 * The filter form writes back whatever identifies the page.
	 *
	 * A GET form replaces the query string outright, so the arguments that
	 * name the page have to be hidden inputs — and they are read back out of
	 * the same URL the tabs use, so the two cannot disagree.
	 */
	public function test_the_page_arguments_are_read_back_from_the_url(): void {
		$this->assertSame(
			[ 'post_type' => 'book', 'page' => 'takings' ],
			$this->report( 'edit.php?post_type=book' )->args()
		);
	}
}
