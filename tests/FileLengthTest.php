<?php
/**
 * File length tests.
 *
 * @package ArrayPress\RegisterReports
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterReports\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Nothing in `src` is allowed to get very long again.
 *
 * RestApi was 730 lines and the component renderer 684. Neither of them
 * got there on purpose — a file grows by fifty lines at a time, every one of
 * them reasonable, and the point at which it stopped being readable is not
 * visible from inside any single commit.
 *
 * So the limit is a test rather than an intention. It does not say the code
 * is well arranged; it says that when a file gets too big to hold in your
 * head, splitting it is a decision someone makes deliberately instead of a
 * job that quietly never happens.
 */
final class FileLengthTest extends TestCase {

	/**
	 * How long a source file may be.
	 */
	private const LIMIT = 500;

	/**
	 * No source file is longer than the limit.
	 */
	public function test_no_source_file_is_too_long(): void {
		$long = [];

		foreach ( $this->sources() as $path ) {
			$lines = count( (array) file( $path ) );

			if ( $lines > self::LIMIT ) {
				$long[] = sprintf( '%s (%d lines)', basename( $path ), $lines );
			}
		}

		sort( $long );

		$this->assertSame(
			[],
			$long,
			sprintf( "Longer than %d lines:\n  ", self::LIMIT ) . implode( "\n  ", $long )
		);
	}

	/**
	 * Every PHP file under `src`.
	 *
	 * @return string[]
	 */
	private function sources(): array {
		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' )
		);

		$paths = [];

		foreach ( $files as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$paths[] = $file->getPathname();
			}
		}

		return $paths;
	}
}
