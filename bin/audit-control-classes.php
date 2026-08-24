<?php
/**
 * Control-class sweep over every shipped PHP file.
 *
 * WordPress.org's review reports a SAMPLE of what its scanner hit, not an
 * inventory (KANUN 0). PHPCS is the same kind of instrument from the other
 * direction: it flags a LINE. The defects this script looks for are ABSENCES
 * -- "this handler verifies no nonce", "this capability says nothing about
 * the object being written" -- and an absence has no line to flag.
 *
 * ---------------------------------------------------------------------------
 * A REPORT, NOT A GATE. Exit code is always 0 and it is deliberately not wired
 * into CI. Read section 0 before trusting any "(none)".
 * ---------------------------------------------------------------------------
 *
 * A gate that under-reports is the exact failure this whole exercise is about:
 * our PHPCS run said "0 ERROR" while honouring ten of our own suppressions.
 * So this script prints what it could not see next to what it flagged.
 *
 * WHERE THIS SCRIPT STARTS, and why that sentence is here
 *
 * The reference implementation (mhm-rentiva) walks out from
 * `add_action( 'wp_ajax_*' )` registrations. Ported unchanged it would have
 * printed "(none)" for every class in this plugin and been telling the truth:
 * this plugin registers ZERO wp_ajax handlers, so no code would ever have
 * reached the checks. A scanner that starts from a registry no registration
 * feeds is not a clean scanner, it is a silent one.
 *
 * So this one starts from three registries that DO carry traffic here --
 * add_action(), register_rest_route(), and the WP-CLI command map -- and then
 * section H sweeps from the OPPOSITE end: every method that writes state,
 * listed against whether any registration above reached it. A writer that
 * section H could not reach is either dead or wired somewhere this script
 * cannot see; both are worth a human's minute.
 *
 * Usage: php bin/audit-control-classes.php
 *
 * @package MhmCurrencySwitcher
 */

declare( strict_types=1 );

$mhmcs_root = str_replace( '\\', '/', dirname( __DIR__ ) );

// Shipped PHP with logic. The generated files (the webpack asset map, the
// compiled .l10n.php catalogue) carry no control flow; phpcs covers them.
$mhmcs_files = array();
$mhmcs_it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $mhmcs_root . '/src' ) );
foreach ( $mhmcs_it as $mhmcs_f ) {
	if ( 'php' === $mhmcs_f->getExtension() ) {
		$mhmcs_files[] = str_replace( '\\', '/', $mhmcs_f->getPathname() );
	}
}
$mhmcs_files[] = $mhmcs_root . '/mhm-currency-switcher.php';
$mhmcs_files[] = $mhmcs_root . '/uninstall.php';
sort( $mhmcs_files );

$mhmcs_find = array_fill_keys( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H' ), array() );

$mhmcs_nonce_re = '(wp_verify_nonce|check_admin_referer|check_ajax_referer)';
$mhmcs_cap_re   = '(current_user_can|is_user_logged_in|WP_UNINSTALL_PLUGIN)';

/*
 * Hooks that fire INSIDE a flow WordPress or WooCommerce has already gated.
 * Demanding our own nonce on these would be wrong, not thorough: there is no
 * second form submission to protect, and a handler that bailed without one
 * would stop recording data during a legitimate checkout. They are LABELLED
 * rather than skipped, because "inherited" is a claim about somebody else's
 * code and it should be re-read when that code changes.
 */
$mhmcs_inherited = array(
	'woocommerce_checkout_create_order',
	'woocommerce_store_api_checkout_update_order_meta',
	'woocommerce_store_api_checkout_order_processed',
	'woocommerce_cart_calculate_fees',
	'woocommerce_after_calculate_totals',
	'woocommerce_cart_loaded_from_session',
	'woocommerce_add_to_cart',
	'plugins_loaded',
	'init',
	'template_redirect',
	'wp_footer',
);

/*
 * What counts as "writes state".
 *
 * 🔴 The WooCommerce CRUD family was missing until an independent audit named
 * it: `CartFilter::save_order_meta()` writes three order metas through
 * `$order->update_meta_data()` and persists them with `$order->save()`, and
 * NEITHER spelling appeared here -- so the plugin's order-writing handlers
 * were absent from the "state-writing methods" count entirely, and section H
 * could never have reported them. A sweep that names the WordPress spellings
 * of a write and not the WooCommerce ones is not a sweep of writes; it is a
 * sweep of one vocabulary.
 *
 * `->save(` is deliberately loose. This is a REPORT: over-listing costs a
 * human one minute, under-listing costs a defect.
 */
$mhmcs_mutates_re = '/(?:\b(?:update_option|add_option|delete_option|update_post_meta|add_post_meta'
	. '|delete_post_meta|update_user_meta|delete_user_meta|wp_insert_post|wp_update_post'
	. '|wp_delete_post|wp_insert_user|wp_create_user|wp_update_user|wp_delete_user'
	. '|set_transient|delete_transient|setcookie|wp_schedule_event|wp_clear_scheduled_hook'
	. '|update_meta_data|add_meta_data|delete_meta_data)\b'
	. '|\$wpdb->(?:insert|update|delete|replace|query)\b'
	. '|->save\()/';

$mhmcs_reached  = array();
$mhmcs_mutating = array();

foreach ( $mhmcs_files as $mhmcs_path ) {
	$mhmcs_src   = (string) file_get_contents( $mhmcs_path );
	$mhmcs_short = substr( $mhmcs_path, strlen( $mhmcs_root ) + 1 );
	$mhmcs_subs  = mhmcs_split_functions( $mhmcs_src );

	// ---- Registry 1: add_action ------------------------------------------
	$mhmcs_hooked = array();
	preg_match_all(
		'/add_action\(\s*\'([a-z_0-9\-\/\.]+)\'\s*,\s*array\(\s*(?:\$this|self::class|self)\s*,\s*\'([a-zA-Z_][a-zA-Z0-9_]*)\'/',
		$mhmcs_src,
		$mhmcs_m,
		PREG_SET_ORDER
	);
	foreach ( $mhmcs_m as $mhmcs_hit ) {
		$mhmcs_hooked[ $mhmcs_hit[2] ][] = $mhmcs_hit[1];
	}

	/*
	 * Registry 2: register_rest_route.
	 *
	 * Pair each 'callback' with the 'permission_callback' from the SAME route
	 * array. In a REST controller the gate sits several lines above the
	 * handler, so reading the handler body on its own tells you nothing about
	 * whether it is guarded.
	 */
	$mhmcs_rest = array();
	preg_match_all(
		/*
		 * 🔴 The `(?:\s|/\*...\*\/)*` between the two keys is not cosmetic.
		 * With plain `\s*` this pattern matched 8 routes in RestAPI.php and
		 * ZERO in ConvertController.php -- because `/convert` carries a twelve
		 * line block comment between its callback and its permission_callback,
		 * explaining why the route is public. So the plugin's only public
		 * write-adjacent route was invisible to class D, and section 0 said the
		 * blindness was about TRANSITIVE writes when in fact the route was
		 * never seen at all. A comment that documents a security decision must
		 * not hide the decision from the scanner reading it.
		 */
		'/\'callback\'\s*=>\s*array\(\s*\$this\s*,\s*\'([a-zA-Z_][a-zA-Z0-9_]*)\'\s*\)\s*,'
			. '(?:\s|\/\*[\s\S]*?\*\/)*\'permission_callback\'\s*=>\s*([^,\n]+)/',
		$mhmcs_src,
		$mhmcs_rm,
		PREG_SET_ORDER
	);
	foreach ( $mhmcs_rm as $mhmcs_hit ) {
		$mhmcs_rest[ $mhmcs_hit[1] ] = trim( $mhmcs_hit[2] );
	}

	// ---- Registry 3: WP-CLI ----------------------------------------------
	$mhmcs_is_cli = (bool) preg_match( '/\bWP_CLI::/', $mhmcs_src );

	foreach ( $mhmcs_subs as $mhmcs_sub ) {
		list( $mhmcs_name, $mhmcs_body, $mhmcs_line ) = $mhmcs_sub;

		$mhmcs_writes = (bool) preg_match( $mhmcs_mutates_re, $mhmcs_body );
		if ( $mhmcs_writes ) {
			$mhmcs_mutating[] = array( $mhmcs_short, $mhmcs_line, $mhmcs_name );
		}

		$mhmcs_hooks   = isset( $mhmcs_hooked[ $mhmcs_name ] ) ? $mhmcs_hooked[ $mhmcs_name ] : array();
		$mhmcs_is_rest = isset( $mhmcs_rest[ $mhmcs_name ] );

		if ( $mhmcs_hooks || $mhmcs_is_rest || $mhmcs_is_cli ) {
			$mhmcs_reached[ $mhmcs_short . '::' . $mhmcs_name ] = true;
		}

		if ( ! $mhmcs_writes ) {
			continue;
		}

		// ---- D. REST route that writes behind an open door ----------------
		if ( $mhmcs_is_rest ) {
			if ( false !== strpos( $mhmcs_rest[ $mhmcs_name ], '__return_true' ) ) {
				$mhmcs_find['D'][] = $mhmcs_short . ':' . $mhmcs_line . '  ' . $mhmcs_name
					. '()  permission_callback => ' . $mhmcs_rest[ $mhmcs_name ];
			}
			continue;
		}

		foreach ( $mhmcs_hooks as $mhmcs_hook ) {
			$mhmcs_label = $mhmcs_short . ':' . $mhmcs_line . '  ' . $mhmcs_name . '()  [' . $mhmcs_hook . ']'
				. ( in_array( $mhmcs_hook, $mhmcs_inherited, true ) ? '  {inherited context}' : '' );

			if ( ! preg_match( '/' . $mhmcs_nonce_re . '\s*\(/', $mhmcs_body ) ) {
				$mhmcs_find['A'][] = $mhmcs_label;
			}
			if ( ! preg_match( '/' . $mhmcs_cap_re . '\s*\(/', $mhmcs_body ) ) {
				$mhmcs_find['B'][] = $mhmcs_label;
			}

			/*
			 * C. The IDOR shape: an id arrives with the request and the
			 * capability checked is a blanket one. `manage_options` says the
			 * caller may change settings, not that they may touch THIS post.
			 * WordPress models the per-target question as a meta capability
			 * (edit_post, $id), which is what a clean handler here uses.
			 */
			$mhmcs_takes_id = (bool) preg_match(
				'/\$_(POST|GET|REQUEST)\s*\[\s*\'[a-z_]*id\'/i',
				$mhmcs_body
			);
			$mhmcs_blanket = (bool) preg_match(
				'/current_user_can\(\s*\'(manage_options|manage_woocommerce|edit_posts|edit_products)\'\s*\)/',
				$mhmcs_body
			);
			if ( $mhmcs_takes_id && $mhmcs_blanket ) {
				$mhmcs_find['C'][] = $mhmcs_short . ':' . $mhmcs_line . '  ' . $mhmcs_name . '()  [' . $mhmcs_hook . ']';
			}
		}
	}

	// ---- E. shortcode callback that echoes --------------------------------
	preg_match_all(
		'/add_shortcode\(\s*\'([a-z_0-9]+)\'\s*,\s*array\(\s*\$this\s*,\s*\'([a-zA-Z_][a-zA-Z0-9_]*)\'/',
		$mhmcs_src,
		$mhmcs_sm,
		PREG_SET_ORDER
	);
	foreach ( $mhmcs_sm as $mhmcs_hit ) {
		foreach ( $mhmcs_subs as $mhmcs_sub ) {
			list( $mhmcs_name, $mhmcs_body, $mhmcs_line ) = $mhmcs_sub;
			if ( $mhmcs_name !== $mhmcs_hit[2] ) {
				continue;
			}
			$mhmcs_reached[ $mhmcs_short . '::' . $mhmcs_name ] = true;
			if ( preg_match( '/^\s*echo\b/m', $mhmcs_body ) && false === strpos( $mhmcs_body, 'ob_start' ) ) {
				$mhmcs_find['E'][] = $mhmcs_short . ':' . $mhmcs_line . '  ' . $mhmcs_name . '()  [' . $mhmcs_hit[1] . ']';
			}
		}
	}

	// ---- F / G: line-local shapes -----------------------------------------
	$mhmcs_lines = preg_split( '/\n/', $mhmcs_src );
	foreach ( $mhmcs_lines as $mhmcs_i => $mhmcs_line_src ) {
		if ( preg_match( '/\bin_array\s*\(/', $mhmcs_line_src ) && false === strpos( $mhmcs_line_src, 'true' ) ) {
			$mhmcs_find['F'][] = $mhmcs_short . ':' . ( $mhmcs_i + 1 ) . '  ' . trim( substr( $mhmcs_line_src, 0, 84 ) );
		}
		if ( preg_match( '/esc_attr\(\s*\$?[a-zA-Z_>\-\[\]\'"]*(url|link|href|permalink)/i', $mhmcs_line_src ) ) {
			$mhmcs_find['G'][] = $mhmcs_short . ':' . ( $mhmcs_i + 1 ) . '  ' . trim( substr( $mhmcs_line_src, 0, 84 ) );
		}
	}
}

/*
 * H. The opposite end: writers that nothing above reached.
 *
 * The first run of this section listed ten methods and nine of them were
 * ordinary internal helpers -- CurrencyStore::save(), OptionWriter::write() --
 * called from another method rather than wired to a hook. A list where 90% is
 * expected is a list nobody reads, so a writer is only reported here when NO
 * call to it exists anywhere in the shipped tree either. What survives both
 * questions is wired through a shape section 0 admits it cannot see (the one
 * real hit was a string callable on plugins_loaded) or is genuinely dead.
 */
$mhmcs_all_src = '';
foreach ( $mhmcs_files as $mhmcs_path ) {
	$mhmcs_all_src .= (string) file_get_contents( $mhmcs_path ) . "\n";
}

foreach ( $mhmcs_mutating as $mhmcs_w ) {
	list( $mhmcs_short, $mhmcs_line, $mhmcs_name ) = $mhmcs_w;

	if ( isset( $mhmcs_reached[ $mhmcs_short . '::' . $mhmcs_name ] ) ) {
		continue;
	}

	$mhmcs_called = (bool) preg_match(
		'/(->|::)' . preg_quote( $mhmcs_name, '/' ) . '\s*\(/',
		$mhmcs_all_src
	);
	if ( $mhmcs_called ) {
		continue;
	}

	$mhmcs_find['H'][] = $mhmcs_short . ':' . $mhmcs_line . '  ' . $mhmcs_name . '()';
}

$mhmcs_titles = array(
	'A' => 'Writes state, no nonce verification in the handler',
	'B' => 'Writes state, no capability check in the handler',
	'C' => 'Takes an id from the request, checks a capability that is not about it',
	'D' => 'REST route that writes state behind permission_callback => __return_true',
	'E' => 'Shortcode callback that echoes instead of returning',
	'F' => 'in_array() without strict comparison',
	'G' => 'URL escaped with esc_attr() instead of esc_url()',
	'H' => 'Writes state but no registration in this script reached it',
);

echo "\n";
echo "MHM Currency Switcher - control-class sweep\n";
echo str_repeat( '=', 78 ) . "\n\n";
echo "0. WHERE THIS STARTS, AND WHAT IT THEREFORE CANNOT SEE\n";
echo str_repeat( '-', 78 ) . "\n";
echo "   Starts from: add_action() with an array(\$this|self, 'method') callback;\n";
echo "   register_rest_route()'s 'callback'/'permission_callback' pairs; files that\n";
echo "   reference WP_CLI::. Section H sweeps from the opposite end.\n\n";
echo "   Blind to:\n";
echo "     - closures and string callables passed straight to a hook\n";
echo "     - a callback stored in a variable first (\$cb = array( ... ))\n";
echo "     - handlers behind a dispatcher: the gate is upstream, so the sub-method\n";
echo "       reads as ungated and would appear here as a false positive\n";
echo "     - a capability decided inside a helper this script cannot name\n";
echo "     - TRANSITIVE writes: class D reads the route callback's own body only.\n";
echo "       /convert is public and does write, through is_rate_limited(), and D\n";
echo "       does not see it. That one is deliberate -- rate limiting is what a\n";
echo "       public endpoint should do -- but a dangerous write one call deep\n";
echo "       would be just as invisible\n";
echo "     - routes whose registration is not the literal shape this script greps\n";
echo "       for: a callback built in a variable, or routes emitted from a loop.\n";
echo "       Until 2.0.0 the pattern also required the two keys to be ADJACENT,\n";
echo "       so /convert -- which explains its public permission_callback in a\n";
echo "       block comment between them -- matched zero times and was missing\n";
echo "       from D entirely, while the bullet above blamed transitivity. It is\n";
echo "       parsed now; the transitive limit above is what actually remains\n";
echo "     - writes through an object method this script does not name. The\n";
echo "       WordPress and WooCommerce CRUD spellings are listed, but a write\n";
echo "       wrapped in one of our own helpers reads as no write at all\n";
echo "     - anything PHPCS covers (escape / sanitize / prepared SQL). For those\n";
echo "       run phpcs with --ignore-annotations and read THAT number, never the\n";
echo "       annotated one\n";
echo "     - {inherited context} on A/B means the hook fires inside a WordPress or\n";
echo "       WooCommerce flow that is already gated. That is a claim about someone\n";
echo "       else's code; re-read it when their code changes\n\n";

$mhmcs_total = 0;
foreach ( $mhmcs_titles as $mhmcs_k => $mhmcs_title ) {
	$mhmcs_rows   = array_values( array_unique( $mhmcs_find[ $mhmcs_k ] ) );
	$mhmcs_total += count( $mhmcs_rows );
	printf( "%s. %s: %d\n", $mhmcs_k, $mhmcs_title, count( $mhmcs_rows ) );
	echo str_repeat( '-', 78 ) . "\n";
	foreach ( $mhmcs_rows as $mhmcs_r ) {
		echo '   ' . $mhmcs_r . "\n";
	}
	if ( ! $mhmcs_rows ) {
		echo "   (none)\n";
	}
	echo "\n";
}

printf(
	"Scanned %d files, %d state-writing methods, %d rows reported.\n",
	count( $mhmcs_files ),
	count( $mhmcs_mutating ),
	$mhmcs_total
);

/**
 * Split a source file into its functions and methods.
 *
 * @param string $src File contents.
 * @return array<int, array{0:string,1:string,2:int}> Name, body, 1-based line.
 */
function mhmcs_split_functions( string $src ): array {
	$out = array();
	if ( ! preg_match_all( '/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		return $out;
	}
	foreach ( $m[1] as $i => $hit ) {
		$start = strpos( $src, '{', $m[0][ $i ][1] );
		if ( false === $start ) {
			continue;
		}
		$depth = 0;
		$end   = $start;
		$len   = strlen( $src );
		for ( $j = $start; $j < $len; $j++ ) {
			if ( '{' === $src[ $j ] ) {
				++$depth;
			} elseif ( '}' === $src[ $j ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $j;
					break;
				}
			}
		}
		$out[] = array( $hit[0], substr( $src, $start, $end - $start + 1 ), substr_count( $src, "\n", 0, $hit[1] ) + 1 );
	}
	return $out;
}
