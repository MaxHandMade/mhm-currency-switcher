<?php
/**
 * Permanent security gate for the plugin's REST surface.
 *
 * @package MhmCurrencySwitcher\Tests\Integration\Security
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration\Security;

use MhmCurrencySwitcher\Tests\Integration\MhmcsIntegrationTestCase;
use MhmCurrencySwitcher\Tests\Integration\Security\Support\StateFingerprint;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The member set is DERIVED from rest_get_server()->get_routes(): every
 * handler under /mhmcs/v1. Three rules:
 *
 *  1. The whole surface — route, methods, permission callback — is PINNED
 *     exactly. A new route, a removed one, or a handler whose permission
 *     callback changes fails here until someone reviews it and updates
 *     SURFACE. A closure as permission_callback is never accepted: nobody can
 *     review it from this list.
 *  2. The public (no-login) surface is exactly one route, `/convert`, and it is
 *     public on purpose (cached pages carry no nonce; see ConvertController).
 *     Its input bounds are locked by ConvertEndpointTest and
 *     ConvertHostileInputTest.
 *  3. Every non-public handler refuses an anonymous caller and a subscriber
 *     (with a valid wp_rest nonce) with 401/403, changes nothing, and — as a
 *     positive control — its permission callback DOES admit an administrator,
 *     so the refusals are about the user, not about a path that never
 *     resolved.
 *
 * Where this starts: registered REST routes. Not covered here: cookie-auth
 * CSRF on the RestAPI routes is enforced by WordPress core
 * (rest_cookie_check_errors), which a dispatched request does not exercise;
 * the snooze routes check the nonce themselves and ARE attacked with a forged
 * one below. Non-REST writers have their own gate
 * (RequestHandlerSecurityGateTest).
 */
class RestRouteSecurityGateTest extends MhmcsIntegrationTestCase {

	use StateFingerprint;

	private const NS = '/mhmcs/v1';

	private const REST_API = 'MhmCurrencySwitcher\Admin\RestAPI';

	private const DIAGNOSTIC = 'MhmCurrencySwitcher\Core\CacheCompatDiagnostic';

	/**
	 * Route|METHODS => permission callback. Changing this list is a security
	 * review.
	 *
	 * @var array<string, string>
	 */
	private const SURFACE = array(
		'/mhmcs/v1/cache-notice/snooze-anomaly|POST'   => self::DIAGNOSTIC . '::check_snooze_permission',
		'/mhmcs/v1/cache-notice/snooze-fragments|POST' => self::DIAGNOSTIC . '::check_snooze_permission',
		'/mhmcs/v1/convert|POST'                       => '__return_true',
		'/mhmcs/v1/currencies|GET'                     => self::REST_API . '::check_admin_permission',
		'/mhmcs/v1/currencies|POST'                    => self::REST_API . '::check_admin_permission',
		'/mhmcs/v1/rates/preview|GET'                  => self::REST_API . '::check_admin_permission',
		'/mhmcs/v1/rates/preview|POST'                 => self::REST_API . '::check_admin_permission',
		'/mhmcs/v1/rates/sync|POST'                    => self::REST_API . '::check_admin_permission',
		'/mhmcs/v1/settings|GET'                       => self::REST_API . '::check_admin_permission',
		'/mhmcs/v1/settings|POST'                      => self::REST_API . '::check_admin_permission',
	);

	/**
	 * The only callbacks allowed to make a route public.
	 *
	 * @var list<string>
	 */
	private const PUBLIC_AUTHORIZERS = array( '__return_true' );

	/**
	 * Fresh REST server per test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		// rates/sync must never reach the network from a test.
		add_filter( 'pre_http_request', array( $this, 'block_http' ), 10, 3 );
	}

	/**
	 * 🔴 The server is cleared AFTER parent::tear_down(). The parent restores
	 * hooks but not the route table, so a server built by the negative control
	 * would otherwise carry its fake open route into every later test.
	 *
	 * @return void
	 */
	public function tear_down() {
		wp_set_current_user( 0 );

		parent::tear_down();

		$GLOBALS['wp_rest_server'] = null;
	}

	/**
	 * Network guard.
	 *
	 * @return \WP_Error
	 */
	public function block_http() {
		return new \WP_Error( 'mhmcs_gate_offline', 'Blocked by RestRouteSecurityGateTest.' );
	}

	/**
	 * Name a permission callback the way SURFACE does; null = unreviewable.
	 *
	 * @param mixed $cb Permission callback.
	 * @return string|null
	 */
	private static function name_of( $cb ): ?string {
		if ( is_string( $cb ) ) {
			return $cb;
		}
		if ( is_array( $cb ) && 2 === count( $cb ) && is_string( $cb[1] ) ) {
			return ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . $cb[1];
		}
		return null; // Closures and invokables cannot be reviewed from a list.
	}

	/**
	 * Derive the surface.
	 *
	 * @return array<string, array{route: string, method: string, authorizer: ?string, callback: mixed}>
	 */
	private function members(): array {
		$out = array();

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( 0 !== strpos( (string) $route, self::NS ) || self::NS === $route ) {
				continue; // The namespace index is core's, not ours.
			}
			foreach ( $handlers as $h ) {
				$methods = array_keys( array_filter( (array) $h['methods'] ) );
				sort( $methods );
				foreach ( $methods as $method ) {
					$out[ $route . '|' . $method ] = array(
						'route'      => (string) $route,
						'method'     => (string) $method,
						'authorizer' => self::name_of( $h['permission_callback'] ?? null ),
						'callback'   => $h['permission_callback'] ?? null,
					);
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Rule 1: the surface is exactly the reviewed one.
	 *
	 * @return void
	 */
	public function test_the_rest_surface_is_exactly_the_reviewed_one(): void {
		$found = array_map(
			static function ( array $m ) {
				return $m['authorizer'] ?? '(closure — unreviewable)';
			},
			$this->members()
		);
		$pinned = self::SURFACE;
		ksort( $pinned );

		$this->assertSame( $pinned, $found, 'The REST surface under /mhmcs/v1 changed. Review it, then update SURFACE.' );
	}

	/**
	 * Rule 2: exactly one public route.
	 *
	 * @return void
	 */
	public function test_the_public_surface_is_exactly_convert(): void {
		$public = array_keys(
			array_filter(
				$this->members(),
				static function ( array $m ): bool {
					return in_array( $m['authorizer'], self::PUBLIC_AUTHORIZERS, true );
				}
			)
		);

		$this->assertSame( array( '/mhmcs/v1/convert|POST' ), $public );
	}

	/**
	 * Rule 3: non-public handlers refuse anonymous and subscriber callers and
	 * change nothing; an administrator is admitted by the same callback.
	 *
	 * @return void
	 */
	public function test_non_public_handlers_refuse_non_admins_and_admit_an_admin(): void {
		$guarded = array_filter(
			$this->members(),
			static function ( array $m ): bool {
				return ! in_array( $m['authorizer'], self::PUBLIC_AUTHORIZERS, true );
			}
		);
		$this->assertCount( count( self::SURFACE ) - 1, $guarded, 'Every pinned non-public handler must be attacked.' );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$failures   = array();

		foreach ( $guarded as $key => $m ) {
			foreach ( array(
				'anonymous'  => 0,
				'subscriber' => $subscriber,
			) as $who => $user_id ) {
				wp_set_current_user( $user_id );
				$request = $this->attack_request( $m );
				$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

				$before = $this->state_fingerprint();
				try {
					$status = rest_get_server()->dispatch( $request )->get_status();
				} catch ( \Throwable $e ) {
					$status = 0; // A handler that throws was reached by a non-admin.
				}
				$changed = $before !== $this->state_fingerprint();

				if ( ! in_array( $status, array( 401, 403 ), true ) || $changed ) {
					$failures[] = sprintf( '%s as %s: HTTP %d, state %s', $key, $who, $status, $changed ? 'CHANGED' : 'unchanged' );
				}
			}

			// Positive control, on the permission callback itself so no handler
			// body (rates/sync, settings writes) runs.
			wp_set_current_user( self::$admin_id );
			$request = $this->attack_request( $m );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			if ( true !== call_user_func( $m['callback'], $request ) ) {
				$failures[] = sprintf( '%s: its permission callback refused an administrator with a valid nonce; the refusals above prove nothing', $key );
			}
		}

		$this->assertSame( array(), $failures, "Non-public REST handlers must refuse non-admins:\n" . implode( "\n", $failures ) );
	}

	/**
	 * The snooze routes check the nonce themselves: an administrator with a
	 * forged X-WP-Nonce must be refused and change nothing (CSRF).
	 *
	 * @return void
	 */
	public function test_snooze_routes_refuse_an_administrator_with_a_forged_nonce(): void {
		$snooze = array_filter(
			$this->members(),
			static function ( array $m ): bool {
				return self::DIAGNOSTIC . '::check_snooze_permission' === $m['authorizer'];
			}
		);
		$this->assertCount( 2, $snooze );

		$failures = array();
		foreach ( $snooze as $key => $m ) {
			wp_set_current_user( self::$admin_id );
			$request = $this->attack_request( $m );
			$request->set_header( 'X-WP-Nonce', 'f0f0f0f0f0' );

			$before  = $this->state_fingerprint();
			$status  = rest_get_server()->dispatch( $request )->get_status();
			$changed = $before !== $this->state_fingerprint();

			if ( ! in_array( $status, array( 401, 403 ), true ) || $changed ) {
				$failures[] = sprintf( '%s: HTTP %d, state %s', $key, $status, $changed ? 'CHANGED' : 'unchanged' );
			}
		}

		$this->assertSame( array(), $failures, implode( "\n", $failures ) );
	}

	/**
	 * Negative control: an unreviewed open route must break rules 1 and 2.
	 *
	 * @return void
	 */
	public function test_the_gate_flags_an_unreviewed_open_route(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'mhmcs/v1',
					'/gate-fake-open',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_null',
						'permission_callback' => '__return_true',
					)
				);
				register_rest_route(
					'mhmcs/v1',
					'/gate-fake-closure',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_null',
						'permission_callback' => static function () {
							return true;
						},
					)
				);
			}
		);
		$GLOBALS['wp_rest_server'] = null; // Rebuild so rest_api_init runs again.

		$members = $this->members();

		$this->assertArrayHasKey( '/mhmcs/v1/gate-fake-open|POST', $members, 'The rebuilt server must carry the fake route, or this control measures nothing.' );
		$this->assertNull( $members['/mhmcs/v1/gate-fake-closure|GET']['authorizer'], 'A closure permission callback must be unreviewable.' );

		$public = array_keys(
			array_filter(
				$members,
				static function ( array $m ): bool {
					return in_array( $m['authorizer'], self::PUBLIC_AUTHORIZERS, true );
				}
			)
		);
		$this->assertNotSame( array( '/mhmcs/v1/convert|POST' ), $public, 'Rule 2 must notice a second public route.' );
	}

	/**
	 * A request a write handler would act on.
	 *
	 * @param array{route: string, method: string} $m Member.
	 * @return WP_REST_Request
	 */
	private function attack_request( array $m ): WP_REST_Request {
		$request = new WP_REST_Request( $m['method'], $m['route'] );
		$request->set_body_params(
			array(
				'auto_detect' => true,
				'currencies'  => array(),
				'signature'   => 'attacker',
			)
		);
		return $request;
	}
}
