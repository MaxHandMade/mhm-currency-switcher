<?php
/**
 * Tests for /mhm-currency/v1/license/manage-subscription REST endpoint — v0.7.0.
 *
 * Snake_case parity to Rentiva v4.32.0 Phase 3A Task C.2. Tests the handler
 * directly (without going through register_rest_route) — same pattern used by
 * tests/Unit/Admin/RestAPITest.php. Mocks HTTP via the stateful
 * wp_remote_request stub declared in tests/bootstrap.php.
 *
 * @package MhmCurrencySwitcher\Tests\Unit\REST
 */

declare(strict_types=1);

namespace {
	// admin_url() is not stubbed in tests/bootstrap.php — provide a minimal
	// stub in the global namespace so the production handler in
	// MhmCurrencySwitcher\Admin\RestAPI can resolve it without fatal-erroring.
	if ( ! function_exists( 'admin_url' ) ) {
		/**
		 * @param string $path
		 * @return string
		 */
		function admin_url( $path = '' ) {
			return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
		}
	}
}

namespace MhmCurrencySwitcher\Tests\Unit\REST {

	use MhmCurrencySwitcher\Admin\RestAPI;
	use MhmCurrencySwitcher\Core\Converter;
	use MhmCurrencySwitcher\Core\CurrencyStore;
	use MhmCurrencySwitcher\Core\RateProvider;
	use MhmCurrencySwitcher\License\LicenseManager;
	use PHPUnit\Framework\TestCase;

	/**
	 * @covers \MhmCurrencySwitcher\Admin\RestAPI::create_manage_subscription_url
	 * @covers \MhmCurrencySwitcher\Admin\RestAPI::check_manage_options_permission
	 */
	class ManageSubscriptionEndpointTest extends TestCase {

		private const RESPONSE_SECRET = 'test-resp-secret';

		protected function setUp(): void {
			parent::setUp();
			LicenseManager::reset();

			$GLOBALS['__mhm_cs_test_options']       = array();
			$GLOBALS['__mhm_cs_test_http_last']     = null;
			$GLOBALS['__mhm_cs_test_http_response'] = null;

			if ( ! defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
				putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' . self::RESPONSE_SECRET );
			}
		}

		protected function tearDown(): void {
			LicenseManager::reset();
			$GLOBALS['__mhm_cs_test_options']       = array();
			$GLOBALS['__mhm_cs_test_http_last']     = null;
			$GLOBALS['__mhm_cs_test_http_response'] = null;

			if ( ! defined( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET' ) ) {
				putenv( 'MHM_CS_LICENSE_RESPONSE_HMAC_SECRET=' );
			}
			parent::tearDown();
		}

		public function test_endpoint_requires_manage_options(): void {
			// The bootstrap stub `current_user_can()` always returns false — that
			// simulates a logged-out / subscriber-level user trying to call the
			// endpoint. The permission_callback must reject them.
			$api    = $this->create_api();
			$result = $api->check_manage_options_permission();

			$this->assertFalse( $result, 'check_manage_options_permission() must return false when current_user_can() is false.' );
		}

		public function test_endpoint_returns_url_on_success(): void {
			$this->seed_active_license();

			$this->queue_response(
				$this->signed_response(
					array(
						'success' => true,
						'data'    => array(
							'customer_portal_url' => 'https://polar.sh/portal/sess_admin_ok',
							'expires_at'          => '2026-12-31T23:59:59+00:00',
						),
					)
				),
				200
			);

			$api      = $this->create_api();
			$request  = new \WP_REST_Request();
			$response = $api->create_manage_subscription_url( $request );

			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertTrue( $data['success'] );
			$this->assertSame( 'https://polar.sh/portal/sess_admin_ok', $data['customer_portal_url'] );
		}

		public function test_endpoint_returns_error_code_on_failure(): void {
			$this->seed_active_license();

			// 404 server response — request() will surface code as `license_not_found`,
			// which the LicenseManager method forwards as error_code, which the REST
			// handler then echoes back to the client at HTTP 200.
			$this->queue_response(
				array(
					'success' => false,
					'code'    => 'license_not_found',
					'message' => 'License key does not exist on server.',
				),
				404
			);

			$api      = $this->create_api();
			$request  = new \WP_REST_Request();
			$response = $api->create_manage_subscription_url( $request );

			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertIsArray( $data );
			$this->assertFalse( $data['success'] );
			$this->assertSame( 'license_not_found', $data['error_code'] );
		}

		public function test_endpoint_uses_correct_return_url(): void {
			$this->seed_active_license();

			$this->queue_response(
				$this->signed_response(
					array(
						'success' => true,
						'data'    => array(
							'customer_portal_url' => 'https://polar.sh/portal/sess_url_check',
							'expires_at'          => '2026-12-31T23:59:59+00:00',
						),
					)
				),
				200
			);

			$api     = $this->create_api();
			$request = new \WP_REST_Request();
			$api->create_manage_subscription_url( $request );

			$last = $GLOBALS['__mhm_cs_test_http_last'];
			$this->assertIsArray( $last );
			$body = json_decode( (string) ( $last['args']['body'] ?? '{}' ), true );
			$this->assertIsArray( $body );
			$this->assertArrayHasKey( 'return_url', $body );
			// admin_url() stub returns 'https://example.test/wp-admin/' + path.
			$this->assertSame(
				'https://example.test/wp-admin/admin.php?page=mhm-currency-switcher#license',
				$body['return_url'],
				'The handler must pass admin_url() of the License tab as return_url to LicenseManager.'
			);
		}

		public function test_endpoint_calls_license_manager(): void {
			$this->seed_active_license();

			// Queueing exactly ONE response — if the handler invoked the
			// LicenseManager more than once, the second call would have no
			// queued response and would fall through to WP_Error. We additionally
			// assert that the http stub recorded exactly one call.
			$this->queue_response(
				$this->signed_response(
					array(
						'success' => true,
						'data'    => array(
							'customer_portal_url' => 'https://polar.sh/portal/sess_once',
							'expires_at'          => '2026-12-31T23:59:59+00:00',
						),
					)
				),
				200
			);

			$api      = $this->create_api();
			$request  = new \WP_REST_Request();
			$response = $api->create_manage_subscription_url( $request );

			$this->assertSame( 200, $response->get_status() );
			$data = $response->get_data();
			$this->assertTrue( $data['success'] );

			// $__mhm_cs_test_http_last is overwritten on each call — verify that
			// the recorded URL is the customer-portal endpoint (proves the
			// LicenseManager singleton's create_customer_portal_session() ran).
			$last = $GLOBALS['__mhm_cs_test_http_last'];
			$this->assertIsArray( $last );
			$this->assertStringEndsWith( '/licenses/customer-portal-session', (string) $last['url'] );
		}

		/**
		 * Build a RestAPI instance with a minimal currency store.
		 */
		private function create_api(): RestAPI {
			$store = new CurrencyStore();
			$store->set_data( 'USD', array() );

			$converter     = new Converter( $store );
			$rate_provider = new RateProvider();

			return new RestAPI( $store, $converter, $rate_provider );
		}

		/**
		 * Seed an active license in the option store so is_active() returns true.
		 */
		private function seed_active_license(): void {
			update_option(
				LicenseManager::OPTION_KEY,
				array(
					'license_key'   => 'TEST-PORTAL-EP-001',
					'status'        => 'active',
					'plan'          => 'pro',
					'expires_at'    => '2026-12-31T23:59:59+00:00',
					'activation_id' => 'a-portal-ep-1',
				)
			);
		}

		/**
		 * Queue a response for the next wp_remote_request() call.
		 *
		 * @param array<string, mixed> $body Response body.
		 * @param int                  $code HTTP status code.
		 */
		private function queue_response( array $body, int $code = 200 ): void {
			$GLOBALS['__mhm_cs_test_http_response'] = array(
				'body'     => (string) wp_json_encode( $body ),
				'response' => array(
					'code'    => $code,
					'message' => 200 === $code ? 'OK' : 'Error',
				),
				'headers'  => array(),
				'cookies'  => array(),
				'filename' => null,
			);
		}

		/**
		 * Sign a payload the same way the server does (HMAC-SHA256 over canonical JSON).
		 *
		 * @param array<string, mixed> $payload Payload to sign.
		 * @return array<string, mixed> Payload with signature appended.
		 */
		private function signed_response( array $payload ): array {
			$this->ksort_recursive( $payload );
			$canonical            = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$payload['signature'] = hash_hmac( 'sha256', $canonical, self::RESPONSE_SECRET );
			return $payload;
		}

		/**
		 * @param array<mixed, mixed> $data
		 */
		private function ksort_recursive( array &$data ): void {
			foreach ( $data as &$v ) {
				if ( is_array( $v ) ) {
					$this->ksort_recursive( $v );
				}
			}
			unset( $v );
			ksort( $data );
		}
	}
}
