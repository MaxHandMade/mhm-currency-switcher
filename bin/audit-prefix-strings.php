<?php
/**
 * Prefix surface report — NOT a gate.
 *
 * Lists WordPress declaration sites whose name argument is a literal, so a
 * human can see the prefix surface phpcs cannot reach. Exit code is ALWAYS 0:
 * a tool that under-reports while saying "0 found" manufactures false
 * confidence, which is the same defect as phpcs respecting our own ignores.
 */
declare( strict_types=1 );

$root = dirname( __DIR__ );

echo "WHAT THIS TOOL CANNOT SEE:\n";
echo "  - handles assigned to a variable or class constant before the call\n";
echo "  - names built at runtime (sprintf, concatenation)\n";
echo "  - DOM ids, CSS classes and selectors in .css/.js/.jsx\n";
echo "  - the compiled bundle under admin-app/build/\n";
echo "  Use bin/check-legacy-tokens.sh for the pass/fail answer.\n\n";

$patterns = array(
	'enqueue/register script' => '/wp_(?:enqueue|register)_script\(\s*[\'"]([^\'"]+)/',
	'enqueue/register style'  => '/wp_(?:enqueue|register)_style\(\s*[\'"]([^\'"]+)/',
	'localize object'         => '/wp_localize_script\(\s*[^,]+,\s*[\'"]([^\'"]+)/',
	'script translations'     => '/wp_set_script_translations\(\s*[\'"]([^\'"]+)/',
	'wp-cli command'          => '/WP_CLI::add_command\(\s*[\'"]([^\'"]+)/',
	'shortcode'               => '/add_shortcode\(\s*[\'"]([^\'"]+)/',
	'rest namespace'          => '/register_rest_route\(\s*[\'"]([^\'"]+)/',
	'cookie'                  => '/setcookie\(\s*([^,]+)/',
	'wc panel target/id'      => '/[\'"](?:target|id)[\'"]\s*=>\s*[\'"]([^\'"]+)/',
);

$files = array( $root . '/mhm-currency-switcher.php' );
$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );
foreach ( $it as $f ) {
	if ( $f->isFile() && 'php' === $f->getExtension() ) {
		$files[] = $f->getPathname();
	}
}

$rows = 0;
foreach ( $files as $file ) {
	$src = (string) file_get_contents( $file );
	foreach ( $patterns as $label => $re ) {
		if ( ! preg_match_all( $re, $src, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $m[1] as $hit ) {
			$line = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
			$rel  = str_replace( $root . DIRECTORY_SEPARATOR, '', $file );
			printf( "%-24s %s\n  %s:%d\n", $label, trim( $hit[0] ), $rel, $line );
			++$rows;
		}
	}
}

printf( "\n%d declaration site(s) listed. Exit code is 0 by design.\n", $rows );
exit( 0 );
