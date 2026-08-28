<?php
/**
 * Reads a product's catalogue price BEFORE this plugin converts anything.
 *
 * @package MhmCurrencySwitcher\Core
 */

declare(strict_types=1);

namespace MhmCurrencySwitcher\Core;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BasePriceResolver — the one place that knows how to read an unconverted price.
 *
 * 🔴 WHY THIS READS RAW META, AND WHY THAT IS NOT A CRUD BYPASS TO BE "FIXED".
 *
 * `$product->get_price()` runs through `woocommerce_product_get_price`, which
 * is a filter THIS PLUGIN is attached to. A surface that needs the base amount
 * in order to convert it would therefore receive an already-converted amount
 * and convert it a second time. The raw meta is the only reading of the price
 * that our own filter has not touched.
 *
 * So the rule is narrow, and it cuts both ways:
 *
 *   - Anything that wants the price a CUSTOMER should see must use the normal
 *     WooCommerce getters and let the filters run.
 *   - Only a surface that performs the conversion itself may come here.
 *
 * That distinction used to live in a one-line comment, duplicated at two call
 * sites inside a rendering class. An independent audit found both and asked for
 * a single abstraction; `BasePriceKnowledgeTest` now fails the build if the
 * meta key is named anywhere else in the shipped tree.
 *
 * @since 2.1.0
 */
final class BasePriceResolver {

	/**
	 * WooCommerce's computed catalogue price meta.
	 *
	 * WooCommerce keeps this in sync with regular/sale price and the active
	 * sale schedule, which is why it is the right key to read rather than
	 * `_regular_price`: it already answers "what is this product priced at
	 * today", before currency conversion.
	 *
	 * @var string
	 */
	private const PRICE_META = '_price';

	/**
	 * The unconverted catalogue price of a product, or null when there is none.
	 *
	 * 🔴 Null, never 0.0, for a value that is not a number. This is the defect
	 * class 1.3.1 swept out of the write paths: `(float)` on an unusable string
	 * yields `0.0`, which is a perfectly valid price meaning "free", so a
	 * garbage read becomes a free product and every gate stays green because
	 * zero is legitimate. A missing price and a zero price are different facts
	 * and this method refuses to conflate them; callers decide what to do with
	 * "no price".
	 *
	 * @param int $product_id Product or variation ID.
	 * @return float|null Base price, or null when absent or unusable.
	 */
	public static function for_product( int $product_id ): ?float {
		if ( $product_id <= 0 ) {
			return null;
		}

		$raw = get_post_meta( $product_id, self::PRICE_META, true );

		if ( ! is_scalar( $raw ) || '' === $raw || ! is_numeric( $raw ) ) {
			return null;
		}

		return (float) $raw;
	}
}
