<?php
/**
 * Times the public /mhmcs/v1/convert endpoint at several batch sizes.
 *
 * ---------------------------------------------------------------------------
 * A REPORT, NOT A GATE. Timings depend on the machine, the database, the
 * object cache and what else is running; a threshold asserted here would fail
 * for reasons that have nothing to do with this plugin. Nothing in CI reads
 * this. It exists so a number in the code can be defended with a measurement.
 * ---------------------------------------------------------------------------
 *
 * WHY IT WAS WRITTEN
 *
 * `ConvertController::MAX_PRODUCT_IDS` is 50 and the rate limit is 120 requests
 * a minute. An independent audit pointed out that one address can therefore ask
 * for roughly 120 x 50 product prices per minute, and that the endpoint does
 * not do arithmetic — it loads WooCommerce products and runs them through the
 * pricing and rendering pipeline. The observation was fair and the cap had been
 * chosen by judgement rather than measurement. This script supplies the
 * measurement.
 *
 * WHAT IT CANNOT TELL YOU
 *
 *   - Anything about a real host. It measures the machine it runs on.
 *   - Anything about concurrency. It issues requests one after another; it does
 *     not model 120 simultaneous callers.
 *   - Anything about the network. `rest_do_request()` is dispatched in process,
 *     so HTTP, TLS and PHP-FPM startup are all excluded.
 *
 * So read it as "what does the server-side work cost, relative to batch size",
 * which is the question the cap actually turns on.
 *
 * Usage, inside a container that has WordPress and WooCommerce:
 *   wp eval-file bin/bench-convert-endpoint.php
 *
 * @package MhmCurrencySwitcher
 */

/*
 * No `declare( strict_types=1 )` here, deliberately. This script is executed
 * with `wp eval-file`, which eval()s the file's contents — and a strict_types
 * declaration is only legal as the very first statement of a script, never
 * inside an eval. Measured: with it present WP-CLI dies with "strict_types
 * declaration must be the very first statement in the script" before a single
 * line runs. The other bin/ tools are invoked as `php bin/...` and keep theirs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this through WP-CLI: wp eval-file bin/bench-convert-endpoint.php\n" );
	exit( 1 );
}

if ( ! function_exists( 'wc_get_product' ) ) {
	fwrite( STDERR, "WooCommerce is not active; there is nothing to price.\n" );
	exit( 1 );
}

/**
 * Create throwaway simple products.
 *
 * @param int $count How many.
 * @return int[] Product IDs.
 */
function mhmcs_bench_make_products( int $count ): array {
	$ids = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'MHMCS bench ' . $i );
		$product->set_regular_price( (string) ( 10 + $i ) );
		$product->set_catalog_visibility( 'hidden' );
		$ids[] = $product->save();
	}

	return $ids;
}

/**
 * Dispatch one conversion request and return its wall time in milliseconds.
 *
 * @param int[]  $ids      Product IDs to price.
 * @param string $currency Target currency code.
 * @return array{ms: float, status: int, priced: int}
 */
function mhmcs_bench_once( array $ids, string $currency ): array {
	$request = new WP_REST_Request( 'POST', '/mhmcs/v1/convert' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( (string) wp_json_encode( array( 'product_ids' => array_values( $ids ), 'currency' => $currency ) ) );

	$start    = microtime( true );
	$response = rest_do_request( $request );
	$ms       = ( microtime( true ) - $start ) * 1000;

	$data   = $response->get_data();
	$priced = is_array( $data ) && isset( $data['prices'] ) && is_array( $data['prices'] ) ? count( $data['prices'] ) : 0;

	return array(
		'ms'     => $ms,
		'status' => $response->get_status(),
		'priced' => $priced,
	);
}

$mhmcs_bench_currency = '';

foreach ( ( new \MhmCurrencySwitcher\Core\CurrencyStore() )->get_currencies() as $mhmcs_row ) {
	if ( ! empty( $mhmcs_row['enabled'] ) && ! empty( $mhmcs_row['code'] ) ) {
		$mhmcs_bench_currency = (string) $mhmcs_row['code'];
		break;
	}
}

if ( '' === $mhmcs_bench_currency ) {
	fwrite( STDERR, "No enabled currency configured; the endpoint would have nothing to convert to.\n" );
	exit( 1 );
}

echo "\nMHM Currency Switcher - /convert benchmark\n";
echo str_repeat( '=', 78 ) . "\n";
printf( "target currency : %s\n", $mhmcs_bench_currency );
printf( "object cache    : %s\n", wp_using_ext_object_cache() ? 'external (persistent)' : 'none (per-request array cache only)' );
printf( "cap under test  : MAX_PRODUCT_IDS = %d\n\n", \MhmCurrencySwitcher\Rest\ConvertController::MAX_PRODUCT_IDS );

$mhmcs_bench_batches = array( 1, 10, 50 );
$mhmcs_bench_repeats = 5;
$mhmcs_bench_pool    = mhmcs_bench_make_products( max( $mhmcs_bench_batches ) );

printf( "created %d throwaway products\n\n", count( $mhmcs_bench_pool ) );
printf( "%-8s %-10s %-10s %-10s %-8s %s\n", 'batch', 'median', 'min', 'max', 'per-id', 'status/priced' );
echo str_repeat( '-', 78 ) . "\n";

foreach ( $mhmcs_bench_batches as $mhmcs_bench_n ) {
	$mhmcs_bench_ids = array_slice( $mhmcs_bench_pool, 0, $mhmcs_bench_n );

	// One discarded run first: the first call of the process pays for autoloads
	// and WooCommerce lazy initialisation, which is not what the cap turns on.
	mhmcs_bench_once( $mhmcs_bench_ids, $mhmcs_bench_currency );

	$mhmcs_bench_times = array();
	$mhmcs_bench_last  = array();

	for ( $mhmcs_bench_r = 0; $mhmcs_bench_r < $mhmcs_bench_repeats; $mhmcs_bench_r++ ) {
		$mhmcs_bench_last    = mhmcs_bench_once( $mhmcs_bench_ids, $mhmcs_bench_currency );
		$mhmcs_bench_times[] = $mhmcs_bench_last['ms'];
	}

	sort( $mhmcs_bench_times );
	$mhmcs_bench_median = $mhmcs_bench_times[ (int) floor( count( $mhmcs_bench_times ) / 2 ) ];

	printf(
		"%-8d %-10s %-10s %-10s %-8s %d / %d\n",
		$mhmcs_bench_n,
		number_format( $mhmcs_bench_median, 1 ) . 'ms',
		number_format( min( $mhmcs_bench_times ), 1 ) . 'ms',
		number_format( max( $mhmcs_bench_times ), 1 ) . 'ms',
		number_format( $mhmcs_bench_median / $mhmcs_bench_n, 2 ) . 'ms',
		$mhmcs_bench_last['status'],
		$mhmcs_bench_last['priced']
	);
}

echo "\n";
printf(
	"rate limit allows %d requests / %ds from one address, so the worst case one\n",
	\MhmCurrencySwitcher\Rest\ConvertController::RATE_LIMIT_REQUESTS,
	\MhmCurrencySwitcher\Rest\ConvertController::RATE_LIMIT_WINDOW
);
printf(
	"address can ask for is %d x %d = %d priced products per minute.\n",
	\MhmCurrencySwitcher\Rest\ConvertController::RATE_LIMIT_REQUESTS,
	\MhmCurrencySwitcher\Rest\ConvertController::MAX_PRODUCT_IDS,
	\MhmCurrencySwitcher\Rest\ConvertController::RATE_LIMIT_REQUESTS * \MhmCurrencySwitcher\Rest\ConvertController::MAX_PRODUCT_IDS
);

echo "\ncleaning up...\n";

foreach ( $mhmcs_bench_pool as $mhmcs_bench_id ) {
	wp_delete_post( $mhmcs_bench_id, true );
}

printf( "deleted %d products.\n\n", count( $mhmcs_bench_pool ) );
