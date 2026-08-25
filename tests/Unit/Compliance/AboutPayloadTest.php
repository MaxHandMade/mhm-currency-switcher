<?php
/**
 * The About tab's payload, and the one guideline it has to keep.
 *
 * 🔴 The plugin directory's rule on the admin dashboard (guideline 11) says
 * advertising "should be avoided" — advice — and then draws exactly one hard
 * line: "tracking referrals via those ads is not permitted", which points at
 * guideline 7, the user-tracking rule. A tab a shop owner opens on purpose is
 * not the nagging guideline 11 is aimed at. A URL carrying a campaign tag would
 * be the thing it forbids.
 *
 * That distinction is invisible in a code review of a URL list: `?utm_source=`
 * looks like housekeeping. So it is pinned here, where adding one costs a red
 * build instead of a review round.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Admin\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Class AboutPayloadTest
 */
class AboutPayloadTest extends TestCase {

	/**
	 * Read the private payload builder.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$method = new ReflectionMethod( Settings::class, 'about_payload' );
		$method->setAccessible( true );

		return (array) $method->invoke( null );
	}

	/**
	 * Every URL the tab prints is plain — no query string at all.
	 *
	 * Deliberately stricter than "no utm_*". A referral tag can be spelled
	 * `?ref=`, `?via=`, `?aff=` or a bare path segment, and a test naming the
	 * spellings it knows would pass the first time somebody used a different
	 * one. "No query string" is a shape, and shapes are what hold.
	 *
	 * @return void
	 */
	public function test_no_link_in_the_about_tab_carries_a_query_string(): void {
		$urls = array_filter(
			$this->payload(),
			static function ( $value ): bool {
				return is_string( $value ) && 0 === strpos( $value, 'http' );
			}
		);

		$this->assertNotEmpty( $urls, 'Guard: the scan found no URLs, so it proved nothing.' );

		foreach ( $urls as $key => $url ) {
			$this->assertStringNotContainsString(
				'?',
				$url,
				"about.{$key} carries a query string. Guideline 11 forbids tracking referrals "
					. 'through admin-side promotion; a plain link is the only kind this tab prints.'
			);
		}
	}

	/**
	 * Nothing on that tab talks to a server.
	 *
	 * The payload is the tab's whole data source, so if every value is a
	 * literal there is nothing left to fetch. This pins the shape rather than
	 * the intent.
	 *
	 * @return void
	 */
	public function test_the_payload_is_literals_only(): void {
		foreach ( $this->payload() as $key => $value ) {
			$this->assertTrue(
				is_string( $value ) || is_bool( $value ),
				"about.{$key} is neither a string nor a bool. The About tab renders static "
					. 'content; anything else here is a moving part that has to be justified.'
			);
		}
	}

	/**
	 * The sibling block is hidden exactly when the sibling is present.
	 *
	 * Telling somebody about software they are already running is noise, and
	 * every install where the block is hidden is one less admin screen carrying
	 * promotion at all.
	 *
	 * 🔴 The marker is the sibling's own version constant, NOT
	 * is_plugin_active(): that function lives in wp-admin/includes/plugin.php
	 * and would tie this payload to the admin request context. The failure
	 * direction is safe — a renamed constant reads as "not installed" and the
	 * block simply shows.
	 *
	 * @return void
	 */
	public function test_sibling_block_is_flagged_off_when_the_sibling_is_loaded(): void {
		$this->assertFalse(
			$this->payload()['siblingActive'],
			'Guard: the sibling constant must be undefined in this process for the next '
				. 'assertion to mean anything.'
		);

		define( 'MHMRENTIVA_VERSION', '6.1.0' );

		$this->assertTrue(
			$this->payload()['siblingActive'],
			'With the sibling loaded the About tab must stop promoting it.'
		);
	}
}
