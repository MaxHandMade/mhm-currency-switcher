<?php
/**
 * Permanent security gate for every request handler that can write state.
 *
 * @package MhmCurrencySwitcher\Tests\Integration\Security
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration\Security;

use MhmCurrencySwitcher\Tests\Integration\MhmcsIntegrationTestCase;
use MhmCurrencySwitcher\Tests\Integration\Security\Support\GateFakeHandlers;
use MhmCurrencySwitcher\Tests\Integration\Security\Support\StateFingerprint;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * The member set is DERIVED at runtime from $wp_filter, never listed by hand:
 * every callback owned by this plugin (its file is under src/ or is the main
 * plugin file) that is either hooked on admin_post_* / wp_ajax_* / wc_ajax_*
 * or whose own body reads $_POST or $_REQUEST. This plugin registers no
 * admin_post_* or wp_ajax_* handler at all (2026-11-04), so a scan for those
 * prefixes alone would match nothing and pass — the request-reading clause is
 * what finds its real writers, the WooCommerce product-save hooks.
 *
 * For every member, three requests against a real variable product:
 *
 *  1. Administrator, valid nonce — POSITIVE CONTROL. The handler must CHANGE
 *     state. These handlers refuse by silently returning, so without this an
 *     "unchanged" verdict below could just mean the payload never reached the
 *     write path.
 *  2. Subscriber holding the valid nonce (derived from the handler's own
 *     source). Must stop on the capability check — the nonce passes, a
 *     capability is asked, nothing changes. A nonce is not authorisation.
 *  3. Administrator with a forged nonce. Must stop on the nonce check (CSRF):
 *     a nonce failure is recorded, nothing changes.
 *
 * Plus negative controls: a fake handler with no capability check and one
 * with no nonce check must each turn the gate red.
 *
 * Where this starts: callbacks registered by the time the test runs. Not
 * seen: hooks added behind is_admin() (none of them read request data today),
 * and a hooked callback that reads $_POST only inside a helper it calls —
 * the body scan reads the hooked function itself. REST has its own gate
 * (RestRouteSecurityGateTest).
 */
class RequestHandlerSecurityGateTest extends MhmcsIntegrationTestCase {

	use StateFingerprint;

	/**
	 * Deliberately not a real nonce.
	 */
	private const FORGED = 'f0f0f0f0f0';

	/**
	 * The derived member set, pinned EXACTLY (hook => callback). A new
	 * request-reading handler, or one that disappears, fails here until
	 * someone reviews it and updates this list. Equality rather than a
	 * minimum also means a scan that silently matches nothing cannot pass.
	 *
	 * @var array<string, string>
	 */
	private const MEMBERS = array(
		'woocommerce_process_product_meta|10'   => 'MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing::save_prices',
		'woocommerce_save_product_variation|10' => 'MhmCurrencySwitcher\Integration\WooCommerce\ProductPricing::save_variation_prices',
	);

	/**
	 * Capabilities asked during the current request.
	 *
	 * @var list<string>
	 */
	private $cap_checks = array();

	/**
	 * Nonce failures during the current request.
	 *
	 * @var int
	 */
	private $nonce_failures = 0;

	/**
	 * Variation the handlers are pointed at.
	 *
	 * @var int
	 */
	private $target_id = 0;

	/**
	 * Fixture product + recorders.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->target_id = $this->create_variable_product( array( 10.0 ) )['variations'][0]->get_id();

		add_filter( 'user_has_cap', array( $this, 'record_cap_check' ), PHP_INT_MAX, 4 );
		add_action( 'wp_verify_nonce_failed', array( $this, 'record_nonce_failure' ) );
	}

	/**
	 * Leave no request data behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Recorder.
	 *
	 * @param array<string, bool> $allcaps All caps.
	 * @param array<int, string>  $caps    Required primitive caps.
	 * @param array<int, mixed>   $args    [0] = requested cap.
	 * @return array<string, bool>
	 */
	public function record_cap_check( $allcaps, $caps, $args ) {
		$this->cap_checks[] = (string) ( $args[0] ?? '' );
		return $allcaps;
	}

	/**
	 * Recorder.
	 *
	 * @return void
	 */
	public function record_nonce_failure(): void {
		++$this->nonce_failures;
	}

	/**
	 * Plugin code = src/ or the main plugin file, never tests or vendor.
	 *
	 * @param string $file Absolute path.
	 * @return bool
	 */
	private static function is_plugin_file( string $file ): bool {
		$root = str_replace( '\\', '/', dirname( __DIR__, 3 ) );
		$file = str_replace( '\\', '/', $file );

		return 0 === strpos( $file, $root . '/src/' ) || $file === $root . '/mhm-currency-switcher.php';
	}

	/**
	 * Source of a function's own body.
	 *
	 * @param ReflectionFunctionAbstract $ref Function.
	 * @return string
	 */
	private static function body( ReflectionFunctionAbstract $ref ): string {
		$lines = file( (string) $ref->getFileName() );

		return implode( '', array_slice( (array) $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
	}

	/**
	 * Whether CODE (not a comment explaining it) reads $_POST or $_REQUEST.
	 *
	 * A text search would count a docblock that says "never trust $_POST" as a
	 * read, and the only way to quiet it would be to delete the explanation.
	 * Known over-match: a variable interpolated into a double-quoted string
	 * ("... $_POST ...") still tokenises as T_VARIABLE. That errs LOUD — the
	 * exact pin in MEMBERS breaks — never silent.
	 *
	 * @param string $body Function source.
	 * @return bool
	 */
	private static function reads_request_data( string $body ): bool {
		foreach ( token_get_all( '<?php ' . $body ) as $token ) {
			if ( is_array( $token ) && T_VARIABLE === $token[0] && in_array( $token[1], array( '$_POST', '$_REQUEST' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Positive control for the tokenizer above: it must still see a read in
	 * real code and must not see one in a comment. If token_get_all() ever
	 * changed shape, the derivation would go blind and EXACT pinning would
	 * catch it — this names the cause.
	 *
	 * @return void
	 */
	public function test_the_body_scan_reads_code_not_comments(): void {
		$this->assertTrue( self::reads_request_data( 'function a() { return $_POST["x"]; }' ) );
		$this->assertFalse( self::reads_request_data( "function a() { /* never trust \$_POST */ return 1; }" ) );
	}

	/**
	 * Derive the member set from $wp_filter.
	 *
	 * @return array<string, array{callable: callable, ref: ReflectionFunctionAbstract, label: string}>
	 */
	private function members(): array {
		global $wp_filter;

		$out = array();

		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$f = $cb['function'];

					// Methods, closures and plain functions alike: ownership is
					// decided by the FILE the code lives in, so a closure cannot
					// slip past the gate.
					if ( is_string( $f ) && false !== strpos( $f, '::' ) ) {
						$f = explode( '::', $f, 2 );
					}
					if ( is_array( $f ) ) {
						$class = is_object( $f[0] ) ? get_class( $f[0] ) : (string) $f[0];
						// Core hooks static callbacks of classes it has not loaded
						// yet (Language_Pack_Upgrader). Nothing that cannot load
						// can run, and autoloading it here could only load core's.
						if ( ! method_exists( $class, (string) $f[1] ) ) {
							continue;
						}
						$ref = new ReflectionMethod( $class, (string) $f[1] );
						$label = $class . '::' . $f[1];
					} elseif ( $f instanceof \Closure || ( is_string( $f ) && function_exists( $f ) ) ) {
						$ref   = new ReflectionFunction( $f );
						$label = $f instanceof \Closure ? 'Closure@' . basename( (string) $ref->getFileName() ) . ':' . $ref->getStartLine() : $f;
					} else {
						continue;
					}

					if ( ! self::is_plugin_file( (string) $ref->getFileName() ) ) {
						continue;
					}

					$by_prefix = 1 === preg_match( '/^(admin_post_|wp_ajax_|wc_ajax_)/', (string) $hook );
					$by_body   = self::reads_request_data( self::body( $ref ) );

					if ( $by_prefix || $by_body ) {
						$out[ $hook . '|' . $priority ] = array(
							'callable' => $f,
							'ref'      => $ref,
							'label'    => $label,
						);
					}
				}
			}
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Nonce field => action pairs the handler verifies, read from its body.
	 *
	 * @param ReflectionFunctionAbstract $ref Handler.
	 * @return array<string, string>
	 */
	private static function nonce_map( ReflectionFunctionAbstract $ref ): array {
		$map = array();

		if ( preg_match_all( '/wp_verify_nonce\(.*?\$_(?:POST|GET|REQUEST)\[\s*\'(\w+)\'\s*\].*?,\s*\'(\w+)\'\s*\)/', self::body( $ref ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$map[ $x[1] ] = $x[2];
			}
		}
		if ( preg_match_all( '/check_(?:admin|ajax)_referer\(\s*\'(\w+)\'(?:\s*,\s*\'(\w+)\')?/', self::body( $ref ), $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$map[ ( $x[2] ?? '' ) !== '' ? $x[2] : '_wpnonce' ] = $x[1];
			}
		}

		return $map;
	}

	/**
	 * Set the request: a payload that WOULD write, plus the given nonces.
	 *
	 * @param array<string, string> $nonces Field => value.
	 * @return void
	 */
	private function request( array $nonces ): void {
		$_POST    = array(
			'mhmcs_fixed_prices'     => array( 'EUR' => '12.50' ),
			'mhmcs_variation_prices' => array( 0 => array( 'EUR' => '12.50' ) ),
		) + $nonces;
		$_REQUEST = $_POST;
	}

	/**
	 * Invoke a handler the way its hook would: (target id, loop 0), trimmed to
	 * what it accepts.
	 *
	 * @param array{callable: callable, ref: ReflectionFunctionAbstract} $member Member.
	 * @return array{changed: bool, how: string}
	 */
	private function invoke( array $member ): array {
		$this->cap_checks     = array();
		$this->nonce_failures = 0;

		$args   = array_slice( array( $this->target_id, 0 ), 0, $member['ref']->getNumberOfParameters() );
		$before = $this->state_fingerprint();
		$how    = 'returned';

		try {
			call_user_func_array( $member['callable'], $args );
		} catch ( \Throwable $e ) {
			$how = 'threw ' . get_class( $e ) . ': ' . $e->getMessage();
		}

		$changed = $before !== $this->state_fingerprint();

		// Undo whatever got through, so one member cannot mask the next.
		delete_post_meta( $this->target_id, '_mhmcs_fixed_prices' );
		delete_post_meta( $this->target_id, GateFakeHandlers::META_KEY );

		return array(
			'changed' => $changed,
			'how'     => $how,
		);
	}

	/**
	 * Valid nonces for the handler, created for the CURRENT user.
	 *
	 * @param ReflectionFunctionAbstract $ref Handler.
	 * @return array<string, string>
	 */
	private static function valid_nonces( ReflectionFunctionAbstract $ref ): array {
		$out = array();
		foreach ( self::nonce_map( $ref ) as $field => $action ) {
			$out[ $field ] = wp_create_nonce( $action );
		}
		return $out;
	}

	/**
	 * Failures of the subscriber attack.
	 *
	 * @param array<string, array> $members Members.
	 * @return list<string>
	 */
	private function subscriber_failures( array $members ): array {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$failures   = array();

		foreach ( $members as $key => $member ) {
			wp_set_current_user( $subscriber );
			$this->request( self::valid_nonces( $member['ref'] ) );

			$r = $this->invoke( $member );

			if ( $r['changed'] || 'returned' !== $r['how'] || ! in_array( 'edit_post', $this->cap_checks, true ) || $this->nonce_failures > 0 ) {
				$failures[] = sprintf( '%s: %s, state %s, caps asked [%s], nonce failures %d', $key, $r['how'], $r['changed'] ? 'CHANGED' : 'unchanged', implode( ',', $this->cap_checks ), $this->nonce_failures );
			}
		}

		return $failures;
	}

	/**
	 * Failures of the forged-nonce attack.
	 *
	 * @param array<string, array> $members Members.
	 * @return list<string>
	 */
	private function forged_failures( array $members ): array {
		$failures = array();

		foreach ( $members as $key => $member ) {
			wp_set_current_user( self::$admin_id );
			$forged = array(
				'_wpnonce'    => self::FORGED,
				'_ajax_nonce' => self::FORGED,
				'nonce'       => self::FORGED,
			);
			foreach ( array_keys( self::nonce_map( $member['ref'] ) ) as $field ) {
				$forged[ $field ] = self::FORGED;
			}
			// The field the plugin actually uses, even if the derivation missed it.
			$forged['mhmcs_product_prices_nonce'] = self::FORGED;
			$this->request( $forged );

			$r = $this->invoke( $member );

			if ( $r['changed'] || 'returned' !== $r['how'] || 0 === $this->nonce_failures ) {
				$failures[] = sprintf( '%s: %s, state %s, nonce failures %d', $key, $r['how'], $r['changed'] ? 'CHANGED' : 'unchanged', $this->nonce_failures );
			}
		}

		return $failures;
	}

	/**
	 * A fake member built from GateFakeHandlers.
	 *
	 * @param string $method Fake method.
	 * @return array<string, array>
	 */
	private function fake( string $method ): array {
		$handler = new GateFakeHandlers();

		return array(
			'fake|' . $method => array(
				'callable' => array( $handler, $method ),
				'ref'      => new ReflectionMethod( GateFakeHandlers::class, $method ),
				'label'    => 'fake',
			),
		);
	}

	// ─── The gate ─────────────────────────────────────────────────────

	/**
	 * The derivation finds exactly the reviewed set, and none of it is
	 * reachable without logging in.
	 *
	 * @return void
	 */
	public function test_the_derived_member_set_is_exactly_the_reviewed_one(): void {
		$found = array_map(
			static function ( array $m ): string {
				return $m['label'];
			},
			$this->members()
		);
		$pinned = self::MEMBERS;
		ksort( $pinned );

		$this->assertSame( $pinned, $found, 'The set of plugin callbacks that read request data changed. Review each one, then update MEMBERS.' );

		foreach ( array_keys( $found ) as $key ) {
			$this->assertStringNotContainsString( 'nopriv_', $key, "$key is reachable without logging in." );
		}
	}

	/**
	 * Positive control: an administrator with a valid nonce DOES write. Without
	 * it, every "unchanged" below could be a payload that never reached the
	 * write path.
	 *
	 * @return void
	 */
	public function test_an_administrator_with_a_valid_nonce_writes(): void {
		$members = $this->members();
		$this->assertNotEmpty( $members );

		foreach ( $members as $key => $member ) {
			wp_set_current_user( self::$admin_id );
			$this->request( self::valid_nonces( $member['ref'] ) );

			$r = $this->invoke( $member );

			$this->assertTrue( $r['changed'], "$key did not write for an administrator with a valid nonce ({$r['how']}); the attacks below would measure nothing." );
		}
	}

	/**
	 * Attacker 1.
	 *
	 * @return void
	 */
	public function test_a_subscriber_with_a_valid_nonce_is_stopped_by_the_capability_check(): void {
		$members = $this->members();
		$this->assertNotEmpty( $members );

		$failures = $this->subscriber_failures( $members );
		$this->assertSame( array(), $failures, "A subscriber holding a valid nonce must be refused by the capability check:\n" . implode( "\n", $failures ) );
	}

	/**
	 * Attacker 2.
	 *
	 * @return void
	 */
	public function test_an_administrator_with_a_forged_nonce_is_stopped_by_the_nonce_check(): void {
		$members = $this->members();
		$this->assertNotEmpty( $members );

		$failures = $this->forged_failures( $members );
		$this->assertSame( array(), $failures, "An administrator with a forged nonce must be refused (CSRF):\n" . implode( "\n", $failures ) );
	}

	/**
	 * Negative control: a handler with no capability check must be caught.
	 *
	 * @return void
	 */
	public function test_the_gate_catches_a_handler_without_a_capability_check(): void {
		$failures = $this->subscriber_failures( $this->fake( 'no_cap_check' ) );

		// Caught for the RIGHT reason: the valid nonce passed (no failure) and
		// the handler wrote. If the nonce derivation ever stopped matching, the
		// fake would stop on its nonce instead and still be "caught" — by the
		// nonce-failure clause, which is not what this control is about.
		$this->assertCount( 1, $failures );
		$this->assertStringContainsString( 'state CHANGED', $failures[0] );
		$this->assertStringContainsString( 'nonce failures 0', $failures[0] );
	}

	/**
	 * Negative control: a handler with no nonce check must be caught.
	 *
	 * @return void
	 */
	public function test_the_gate_catches_a_handler_without_a_nonce_check(): void {
		$failures = $this->forged_failures( $this->fake( 'no_nonce_check' ) );

		// Caught because the forged nonce was never checked and the write went
		// through, not because of some other refusal.
		$this->assertCount( 1, $failures );
		$this->assertStringContainsString( 'state CHANGED', $failures[0] );
		$this->assertStringContainsString( 'nonce failures 0', $failures[0] );
	}
}
