<?php
/**
 * Loading-context gate: admin-only core functions called off the admin path.
 *
 * Functions defined in wp-admin/includes/*.php exist ONLY when the admin API
 * has been loaded. A /wp-json/ request stops at wp-load.php; so do shortcode
 * rendering, block rendering, template_redirect, WP-Cron and WP-CLI. Calling
 * add_meta_box(), dbDelta(), is_plugin_active(), get_plugins(),
 * wp_handle_upload() or wp_delete_user() from one of those paths is a fatal.
 *
 * WHY A SEPARATE TOOL: every other gate we own is structurally blind to this.
 *   - PHPUnit: the test bootstrap loads wp-admin/includes/admin.php, so the
 *     function IS defined and a behaviour test for that route passes for free
 *     while proving nothing.
 *   - PHPCS and PHPStan: they see a call to a function that exists somewhere.
 *     Neither models load context.
 *   - Plugin Check: does not model it either.
 * A defect of this class therefore ships green through all four.
 *
 * THE INVENTORY IS DERIVED, NEVER LISTED. Every function defined under
 * wp-admin/includes/ and NOT defined under wp-includes/ is admin-only. Two
 * traps, both paid for upstream and avoided here by construction:
 *   - Do not derive from a RUNNING site with get_defined_functions(): other
 *     active plugins pre-load part of the admin API, those names land in the
 *     "before" snapshot and drop out of the difference. Measured upstream at
 *     348 names that way against 962 from the files, with the whole
 *     plugin.php family missing.
 *   - Skip class, interface and trait bodies by brace depth. The WP_Filesystem
 *     classes define file_exists() and is_file() as METHODS; counting those as
 *     functions produces roughly 1300 phantom names, and a phantom name in
 *     this inventory turns every ordinary call into a finding.
 *
 * WHAT THIS GATE CANNOT SEE -- state it, never let a green imply it:
 *   - Indirect calls: call_user_func( 'dbDelta' ), variable functions,
 *     a function name arriving as a string callback.
 *   - Whether a require that IS present actually runs before the call at
 *     runtime; this checks source order inside the enclosing function only.
 *   - A call inside a closure assigned to a variable and invoked elsewhere.
 *   - Which hook a method is ultimately reached from. It reports by FILE, and
 *     the exemptions below are named one by one for that reason.
 *
 * FAIL-CLOSED. No core tree, or a derivation that lost a known name, exits 1
 * rather than reporting clean. Run it where WordPress lives:
 *
 *   docker exec mhmcs-dev-wordpress-1 php \
 *     /var/www/html/wp-content/plugins/mhm-currency-switcher/bin/check-admin-only-functions.php
 *
 * or set WP_CORE_DIR to a directory holding both wp-admin/ and wp-includes/.
 *
 * @package MhmCurrencySwitcher
 */

declare( strict_types=1 );

$mhmcs_plugin_dir = str_replace( '\\', '/', dirname( __DIR__ ) );

/**
 * Names that MUST survive the derivation.
 *
 * Fail-closed by NAME, not by count. A count check looked reasonable upstream
 * at 348 while an entire function family was missing from the inventory.
 */
const MHMCS_INVENTORY_CANARIES = array(
	'add_meta_box',
	'dbDelta',
	'get_plugins',
	'is_plugin_active',
	'wp_delete_user',
	'wp_handle_upload',
);

/**
 * Calls that are correct where they stand.
 *
 * Named one by one, with the reason and the evidence. NOT a pattern, and in
 * particular NOT "anything under src/Admin/":
 *
 *   - An `admin_[a-z_]+` hook pattern would also exempt admin_bar_menu, which
 *     runs on the FRONT END.
 *   - A path rule for src/Admin/ would exempt src/Admin/RestAPI.php, whose
 *     whole job is answering /wp-json/ requests -- the one context in this
 *     plugin where the admin API is guaranteed ABSENT. A get_plugins() call
 *     there is exactly the fatal this gate exists to catch, and a path rule
 *     would hide it.
 *
 * So the unit of exemption is the METHOD, and the reason names the hook that
 * makes it safe.
 *
 * Format: 'relative/path.php::function_name' => 'why'.
 */
const MHMCS_EXEMPT = array(
	'src/Frontend/NavMenu.php::add_menu_metabox' =>
		'Registered on admin_head-nav-menus.php, which only ever fires while '
		. 'wp-admin/nav-menus.php is being served -- wp-admin/admin.php has '
		. 'already required wp-admin/includes/admin.php by then. The file sits '
		. 'under Frontend/ because the same class also filters the menu on the '
		. 'front end, but THIS method is admin-only.',

	'src/Integration/WooCommerce/ProductPricing.php::enqueue_admin_assets' =>
		'Registered on admin_enqueue_scripts, which fires from wp-admin only '
		. '(the front end uses wp_enqueue_scripts and the login screen uses '
		. 'login_enqueue_scripts). wp-admin/admin.php requires '
		. 'wp-admin/includes/screen.php long before that hook, so '
		. 'get_current_screen() is defined. This entry was added because the '
		. 'gate flagged the method on the day it was written -- which is the '
		. 'gate working, not a reason to soften it.',

	'src/Admin/Settings.php::add_menu_page' =>
		'Registered on admin_menu, which fires from wp-admin/menu.php during an '
		. 'admin request only. add_submenu_page() lives in '
		. 'wp-admin/includes/plugin.php and is loaded well before that hook.',

	'src/Core/CacheCompatDiagnostic.php::render_notice' =>
		'Registered on admin_notices (see init()), which fires from '
		. 'wp-admin/admin-header.php during an admin request only -- '
		. 'wp-admin/admin.php has already required wp-admin/includes/screen.php '
		. 'by then, so get_current_screen() is defined. Added when Guideline 11 '
		. '(capability + screen scoping) put the call here; the gate flagged it '
		. 'on the day it was written, which is the gate working, not a reason '
		. 'to soften it.',

	'src/Core/WooCommerceMissingNotice.php::render' =>
		'Registered on admin_notices only when WooCommerce is missing (see '
		. 'mhm-currency-switcher.php\'s plugins_loaded callback), same hook and '
		. 'same reasoning as CacheCompatDiagnostic::render_notice() above: '
		. 'admin_notices never fires outside wp-admin, and screen.php is '
		. 'already loaded by the time it does.',
);

/**
 * Locate the WordPress core tree without loading WordPress.
 *
 * @param string $plugin_dir Plugin root.
 * @return string|null Core root, or null when not found.
 */
function mhmcs_locate_core( string $plugin_dir ): ?string {
	$env        = getenv( 'WP_CORE_DIR' );
	$candidates = array(
		is_string( $env ) ? $env : '',
		// wp-content/plugins/<slug> -> core root.
		dirname( $plugin_dir, 3 ),
	);

	foreach ( $candidates as $candidate ) {
		if ( '' === $candidate ) {
			continue;
		}
		if ( is_dir( $candidate . '/wp-admin/includes' ) && is_dir( $candidate . '/wp-includes' ) ) {
			return rtrim( str_replace( '\\', '/', $candidate ), '/' );
		}
	}

	return null;
}

/**
 * Collect GLOBAL function names defined under a directory.
 *
 * Tokenizer, not regex: "function" appears in comments and strings, and the
 * brace-depth walk below is what keeps class methods out of the result.
 *
 * @param string $dir       Directory to scan.
 * @param bool   $recursive Whether to descend into subdirectories.
 * @return array<string, true>
 */
function mhmcs_defined_functions( string $dir, bool $recursive ): array {
	$names = array();

	$iterator = $recursive
		? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) )
		: new FilesystemIterator( $dir, FilesystemIterator::SKIP_DOTS );

	foreach ( $iterator as $file ) {
		if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$tokens = @token_get_all( (string) file_get_contents( $file->getPathname() ) );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				continue;
			}

			// Skip the whole body of a class / interface / trait / enum.
			if ( in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true )
				|| ( defined( 'T_ENUM' ) && T_ENUM === $token[0] ) ) {
				$i = mhmcs_skip_block( $tokens, $i, $count );
				continue;
			}

			if ( T_FUNCTION !== $token[0] ) {
				continue;
			}

			// The next meaningful token is the name; anything else is a closure.
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$next = $tokens[ $j ];
				if ( is_array( $next ) && in_array( $next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( is_array( $next ) && T_STRING === $next[0] ) {
					$names[ $next[1] ] = true;
				}
				break;
			}
		}
	}

	return $names;
}

/**
 * Advance past a brace-delimited block starting at or after $i.
 *
 * @param array<int, mixed> $tokens Token list.
 * @param int               $i      Index of the block keyword.
 * @param int               $count  Token count.
 * @return int Index of the closing brace, or $i when there is no body.
 */
function mhmcs_skip_block( array $tokens, int $i, int $count ): int {
	$depth = 0;
	$open  = false;

	for ( $j = $i; $j < $count; $j++ ) {
		$token = $tokens[ $j ];
		$text  = is_array( $token ) ? $token[1] : $token;

		if ( '{' === $text ) {
			++$depth;
			$open = true;
		} elseif ( '}' === $text ) {
			--$depth;
			if ( $open && 0 === $depth ) {
				return $j;
			}
		} elseif ( ';' === $text && ! $open ) {
			// Forward declaration or an interface constant; no body follows.
			return $j;
		}
	}

	return $i;
}

// ---------------------------------------------------------------------------

echo PHP_EOL;
echo 'MHM Currency Switcher - loading-context gate' . PHP_EOL;
echo str_repeat( '=', 78 ) . PHP_EOL;

$mhmcs_core = mhmcs_locate_core( $mhmcs_plugin_dir );

if ( null === $mhmcs_core ) {
	echo 'FAIL: could not find a WordPress core tree.' . PHP_EOL;
	echo '  Looked for a directory holding both wp-admin/includes and wp-includes,' . PHP_EOL;
	echo '  via WP_CORE_DIR and via ' . dirname( $mhmcs_plugin_dir, 3 ) . PHP_EOL;
	echo '  Run this inside the container, or set WP_CORE_DIR. Failing closed.' . PHP_EOL;
	exit( 1 );
}

$mhmcs_admin_fns  = mhmcs_defined_functions( $mhmcs_core . '/wp-admin/includes', false );
$mhmcs_public_fns = mhmcs_defined_functions( $mhmcs_core . '/wp-includes', true );
$mhmcs_admin_only = array_diff_key( $mhmcs_admin_fns, $mhmcs_public_fns );

printf(
    'Core: %s  |  wp-admin/includes: %d  wp-includes: %d  admin-only: %d%s',
    $mhmcs_core,
    count( $mhmcs_admin_fns ),
    count( $mhmcs_public_fns ),
    count( $mhmcs_admin_only ),
    PHP_EOL
);

$mhmcs_missing = array();
foreach ( MHMCS_INVENTORY_CANARIES as $mhmcs_canary ) {
	if ( ! isset( $mhmcs_admin_only[ $mhmcs_canary ] ) ) {
		$mhmcs_missing[] = $mhmcs_canary;
	}
}

if ( $mhmcs_missing ) {
	echo PHP_EOL . 'FAIL: the derivation lost known admin-only names: '
		. implode( ', ', $mhmcs_missing ) . PHP_EOL;
	echo '  The inventory is wrong, so every "clean" below would be meaningless.' . PHP_EOL;
	echo '  Failing closed.' . PHP_EOL;
	exit( 1 );
}

echo 'Canaries present: ' . implode( ', ', MHMCS_INVENTORY_CANARIES ) . PHP_EOL . PHP_EOL;

// ---- Scan the shipped tree -------------------------------------------------

$mhmcs_files = array();
$mhmcs_it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $mhmcs_plugin_dir . '/src' ) );
foreach ( $mhmcs_it as $mhmcs_f ) {
	if ( 'php' === $mhmcs_f->getExtension() ) {
		$mhmcs_files[] = str_replace( '\\', '/', $mhmcs_f->getPathname() );
	}
}
$mhmcs_files[] = $mhmcs_plugin_dir . '/mhm-currency-switcher.php';
$mhmcs_files[] = $mhmcs_plugin_dir . '/uninstall.php';
sort( $mhmcs_files );

$mhmcs_findings = array();
$mhmcs_exempted = array();

foreach ( $mhmcs_files as $mhmcs_path ) {
	$mhmcs_short  = substr( str_replace( '\\', '/', $mhmcs_path ), strlen( $mhmcs_plugin_dir ) + 1 );
	$mhmcs_tokens = @token_get_all( (string) file_get_contents( $mhmcs_path ) );
	$mhmcs_count  = count( $mhmcs_tokens );

	$mhmcs_fn_name  = '(file scope)';
	$mhmcs_required = false;

	for ( $i = 0; $i < $mhmcs_count; $i++ ) {
		$mhmcs_tok = $mhmcs_tokens[ $i ];

		if ( ! is_array( $mhmcs_tok ) ) {
			continue;
		}

		// Track which function we are inside, so findings name it and so the
		// require check is scoped to the same body.
		if ( T_FUNCTION === $mhmcs_tok[0] ) {
			for ( $j = $i + 1; $j < $mhmcs_count; $j++ ) {
				$mhmcs_next = $mhmcs_tokens[ $j ];
				if ( is_array( $mhmcs_next ) && in_array( $mhmcs_next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				if ( is_array( $mhmcs_next ) && T_STRING === $mhmcs_next[0] ) {
					$mhmcs_fn_name  = $mhmcs_next[1];
					$mhmcs_required = false;
				}
				break;
			}
			continue;
		}

		/*
		 * A require detected from TOKENS, never from text. A require sitting in
		 * a comment satisfies a regex and loads nothing.
		 */
		if ( in_array( $mhmcs_tok[0], array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
			for ( $j = $i + 1; $j < min( $i + 30, $mhmcs_count ); $j++ ) {
				$mhmcs_next = $mhmcs_tokens[ $j ];
				if ( is_array( $mhmcs_next ) && T_CONSTANT_ENCAPSED_STRING === $mhmcs_next[0]
					&& false !== strpos( $mhmcs_next[1], 'wp-admin/includes' ) ) {
					$mhmcs_required = true;
					break;
				}
				if ( ! is_array( $mhmcs_next ) && ';' === $mhmcs_next ) {
					break;
				}
			}
			continue;
		}

		if ( T_STRING !== $mhmcs_tok[0] || ! isset( $mhmcs_admin_only[ $mhmcs_tok[1] ] ) ) {
			continue;
		}

		// Must be a CALL: the next meaningful token is an opening paren.
		$mhmcs_is_call = false;
		for ( $j = $i + 1; $j < $mhmcs_count; $j++ ) {
			$mhmcs_next = $mhmcs_tokens[ $j ];
			if ( is_array( $mhmcs_next ) && in_array( $mhmcs_next[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$mhmcs_is_call = ( ! is_array( $mhmcs_next ) && '(' === $mhmcs_next );
			break;
		}
		if ( ! $mhmcs_is_call ) {
			continue;
		}

		// A method call or a declaration of our own is not a core call.
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$mhmcs_prev = $mhmcs_tokens[ $j ];
			if ( is_array( $mhmcs_prev ) && in_array( $mhmcs_prev[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( is_array( $mhmcs_prev )
				&& in_array( $mhmcs_prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue 2;
			}
			break;
		}

		$mhmcs_key = $mhmcs_short . '::' . $mhmcs_fn_name;
		$mhmcs_row = $mhmcs_short . ':' . $mhmcs_tok[2] . '  ' . $mhmcs_fn_name . '() calls '
			. $mhmcs_tok[1] . '()';

		if ( isset( MHMCS_EXEMPT[ $mhmcs_key ] ) ) {
			$mhmcs_exempted[] = $mhmcs_row;
			continue;
		}
		if ( $mhmcs_required ) {
			continue;
		}

		$mhmcs_findings[] = $mhmcs_row;
	}
}

echo 'Named exemptions (' . count( $mhmcs_exempted ) . ')' . PHP_EOL;
echo str_repeat( '-', 78 ) . PHP_EOL;
foreach ( $mhmcs_exempted as $mhmcs_row ) {
	echo '   ' . $mhmcs_row . PHP_EOL;
}
if ( ! $mhmcs_exempted ) {
	echo '   (none)' . PHP_EOL;
}
echo PHP_EOL;

echo 'Findings (' . count( $mhmcs_findings ) . ')' . PHP_EOL;
echo str_repeat( '-', 78 ) . PHP_EOL;
foreach ( $mhmcs_findings as $mhmcs_row ) {
	echo '   ' . $mhmcs_row . PHP_EOL;
}
if ( ! $mhmcs_findings ) {
	echo '   (none)' . PHP_EOL;
}
echo PHP_EOL;

echo 'Blind to: indirect calls (call_user_func, variable functions, string' . PHP_EOL;
echo 'callbacks); whether a require present in source actually runs before the' . PHP_EOL;
echo 'call at runtime; calls inside a closure invoked elsewhere; which hook a' . PHP_EOL;
echo 'method is ultimately reached from.' . PHP_EOL . PHP_EOL;

printf( 'Scanned %d files. %s%s', count( $mhmcs_files ), $mhmcs_findings ? 'FAIL' : 'PASS', PHP_EOL );

exit( $mhmcs_findings ? 1 : 0 );
