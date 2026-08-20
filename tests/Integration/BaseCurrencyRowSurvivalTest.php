<?php
/**
 * Integration tests: changing the shop's base currency to one that is already
 * configured must not delete that currency's configuration.
 *
 * `CurrencyStore::get_currencies()` filters out the row whose code matches the
 * current base — correct, because the base is not a conversion target and the
 * admin table should not offer it. But every write path reads through that
 * filtered view and then persists it as the WHOLE option: the REST sync, the
 * panel's save, the cron tick and the CLI command all do
 * `set_data( $base, $filtered )` followed by `save()`.
 *
 * So the moment a shop switches its WooCommerce base currency to a currency it
 * had configured, that row becomes invisible — and the next save of any kind
 * writes it out of existence, taking its symbol, number format, fee and any
 * manually entered rate with it. Switching the base back does not bring them
 * home; there is nothing left to bring.
 *
 * @package MhmCurrencySwitcher\Tests\Integration
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration;

use MhmCurrencySwitcher\Core\RateProvider;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Class BaseCurrencyRowSurvivalTest
 */
class BaseCurrencyRowSurvivalTest extends MhmcsIntegrationTestCase {

	/**
	 * A second configured currency, so the list is never down to one row.
	 *
	 * @var string
	 */
	private const OTHER = 'GBP';

	/**
	 * Marker symbol for the row under test. Deliberately not a real glyph: if
	 * the row is destroyed and something later re-creates a bare EUR entry from
	 * WooCommerce's own tables, the code would come back but this would not.
	 *
	 * @var string
	 */
	private const MARKER_SYMBOL = 'ROWX';

	/**
	 * Spin up a REST server so mhmcs/v1 routes are dispatchable.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Drop the REST server, the HTTP stub and the primed transient.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_transient( RateProvider::TRANSIENT_KEY_PREFIX . 'USD' );
		delete_transient( RateProvider::TRANSIENT_KEY_PREFIX . self::TARGET_CURRENCY );

		global $wp_rest_server;
		$wp_rest_server = null;

		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Configure both currencies against the USD base, giving the row under test
	 * a marker symbol so its survival can be told from its re-creation.
	 *
	 * @return void
	 */
	private function configure_two_currencies(): void {
		$make = static function ( string $code, float $rate, string $symbol ): array {
			return array(
				'code'     => $code,
				'enabled'  => true,
				'rate'     => array(
					'type'  => 'manual',
					'value' => $rate,
				),
				'fee'      => array(
					'type'  => 'none',
					'value' => 0,
				),
				'rounding' => array(
					'type'     => 'disabled',
					'value'    => 0,
					'subtract' => 0,
				),
				'format'   => array(
					'symbol'       => $symbol,
					'decimals'     => 2,
					'decimal_sep'  => '.',
					'thousand_sep' => ',',
					'position'     => 'left',
				),
			);
		};

		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mhmcs/v1/currencies' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'base_currency' => 'USD',
					'currencies'    => array(
						$make( self::TARGET_CURRENCY, 2.0, self::MARKER_SYMBOL ),
						$make( self::OTHER, 3.0, 'OTHX' ),
					),
				)
			)
		);

		$response = rest_do_request( $request );

		if ( $response->is_error() ) {
			$this->fail( 'Could not configure the test currencies: ' . (string) wp_json_encode( $response->get_data() ) );
		}

		wp_set_current_user( 0 );
	}

	/**
	 * Read the stored rows straight out of the option, bypassing every filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function stored_rows(): array {
		$data = get_option( 'mhmcs_currencies', '' );

		if ( is_string( $data ) && '' !== $data ) {
			$data = json_decode( $data, true );
		}

		return is_array( $data ) && isset( $data['currencies'] ) && is_array( $data['currencies'] )
			? $data['currencies']
			: array();
	}

	/**
	 * The stored row for a code, or null.
	 *
	 * @param string $code Currency code.
	 * @return array<string, mixed>|null
	 */
	private function stored_row( string $code ): ?array {
		foreach ( $this->stored_rows() as $row ) {
			if ( ( $row['code'] ?? '' ) === $code ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Answer every outbound rate request, so a sync can complete offline.
	 *
	 * @return void
	 */
	private function stub_rate_api(): void {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => (string) wp_json_encode(
						array(
							'rates' => array(
								'EUR' => 1.1,
								'GBP' => 1.3,
								'USD' => 0.9,
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * A rate sync after a base-currency change must leave the now-hidden row
	 * exactly where it was.
	 *
	 * @return void
	 */
	public function test_a_sync_does_not_delete_the_row_that_became_the_base(): void {
		$this->configure_two_currencies();
		$this->stub_rate_api();

		// The shop switches its base currency to one it had configured.
		update_option( 'woocommerce_currency', self::TARGET_CURRENCY );

		do_action( 'mhmcs_update_rates' );

		$row = $this->stored_row( self::TARGET_CURRENCY );

		$this->assertNotNull(
			$row,
			'Changing the base currency and running one sync deleted that currency\'s stored configuration outright.'
		);
		$this->assertSame(
			self::MARKER_SYMBOL,
			$row['format']['symbol'] ?? '',
			'The row survived in name only — its symbol and number format were lost, and the panel has no field to restore them.'
		);
	}

	/**
	 * The panel's own save must not delete it either. The admin table never
	 * shows the base row, so an admin saving an unrelated setting would
	 * otherwise wipe it without ever seeing it.
	 *
	 * @return void
	 */
	public function test_saving_from_the_panel_does_not_delete_the_row_that_became_the_base(): void {
		$this->configure_two_currencies();

		update_option( 'woocommerce_currency', self::TARGET_CURRENCY );

		// Exactly what the admin UI sends: the rows it can see, which no longer
		// include the one that just became the base.
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mhmcs/v1/currencies' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'base_currency' => self::TARGET_CURRENCY,
					'currencies'    => array( $this->stored_row( self::OTHER ) ),
				)
			)
		);

		$response = rest_do_request( $request );
		$this->assertFalse( $response->is_error(), 'The panel save returned an error.' );

		wp_set_current_user( 0 );

		$row = $this->stored_row( self::TARGET_CURRENCY );

		$this->assertNotNull( $row, 'Saving the panel deleted the configuration of the currency that had become the base.' );
		$this->assertSame( self::MARKER_SYMBOL, $row['format']['symbol'] ?? '', 'The base row lost its format on a panel save.' );
	}

	/**
	 * 🔴 The control. Preserving the hidden base row must not turn into
	 * "nothing can ever be deleted": a currency the admin genuinely removes has
	 * to stay removed.
	 *
	 * Without this, the fix for the test above is trivially satisfied by
	 * merging every old row back in on every save, and the Remove button in the
	 * panel would silently stop working.
	 *
	 * @return void
	 */
	public function test_a_currency_the_admin_removes_is_really_removed(): void {
		$this->configure_two_currencies();

		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', '/mhmcs/v1/currencies' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'base_currency' => 'USD',
					'currencies'    => array( $this->stored_row( self::TARGET_CURRENCY ) ),
				)
			)
		);

		$response = rest_do_request( $request );
		$this->assertFalse( $response->is_error(), 'The panel save returned an error.' );

		wp_set_current_user( 0 );

		$this->assertNull(
			$this->stored_row( self::OTHER ),
			'A currency the admin deleted came back; the Remove button no longer removes anything.'
		);
		$this->assertNotNull( $this->stored_row( self::TARGET_CURRENCY ), 'The currency that was kept went missing instead.' );
	}
}
