<?php
/**
 * The widget's currency cap is written twice — once in the panel so the
 * counter can read `2 / 5`, once on the server so the limit is real. Two
 * numbers, one meaning.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\Compliance
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Unit\Compliance;

use MhmCurrencySwitcher\Admin\RestAPI;
use PHPUnit\Framework\TestCase;

/**
 * Class WidgetCapParityTest
 */
class WidgetCapParityTest extends TestCase {

	/**
	 * @return void
	 */
	public function test_the_panel_and_the_server_cap_at_the_same_number(): void {
		$source = file_get_contents(
			dirname( __DIR__, 3 ) . '/admin-app/src/components/tabs/DisplayOptions.jsx'
		);

		$this->assertIsString( $source, 'DisplayOptions.jsx must be readable.' );

		$this->assertSame(
			1,
			preg_match( '/MAX_WIDGET_CURRENCIES\s*=\s*(\d+)/', $source, $match ),
			'DisplayOptions.jsx must declare MAX_WIDGET_CURRENCIES as a literal so this pin can read it.'
		);

		$this->assertSame(
			RestAPI::PRODUCT_WIDGET_MAX_CURRENCIES,
			(int) $match[1],
			'The panel counts up to a different number than the server enforces. Whichever is larger, '
				. 'the shop owner gets a selection that silently disappears.'
		);
	}
}
