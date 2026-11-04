<?php
/**
 * Deliberately broken request handlers: the negative controls of
 * RequestHandlerSecurityGateTest.
 *
 * @package MhmCurrencySwitcher\Tests\Integration\Security
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Tests\Integration\Security\Support;

/**
 * Each method is shaped like ProductPricing::save_prices() with exactly one
 * guard missing. The gate must turn red on each of them; a gate that cannot
 * fail on a handler known to be broken proves nothing when it passes.
 */
final class GateFakeHandlers {

	/**
	 * Meta key the fakes write. Matches the fingerprint's `_mhmcs%` pattern.
	 */
	const META_KEY = '_mhmcs_gate_fake';

	/**
	 * Verifies the nonce, never asks whether the user may edit the post.
	 *
	 * @param int $post_id Target post.
	 * @return void
	 */
	public function no_cap_check( int $post_id ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- deliberately broken fixture.
		if ( ! isset( $_POST['mhmcs_product_prices_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mhmcs_product_prices_nonce'] ) ), 'mhmcs_save_product_prices' ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_KEY, 'written' );
	}

	/**
	 * Asks the capability, never verifies the nonce (CSRF).
	 *
	 * @param int $post_id Target post.
	 * @return void
	 */
	public function no_nonce_check( int $post_id ): void {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_KEY, 'written' );
	}
}
