<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines constants that are normally defined in the main plugin file,
 * which cannot be scanned directly due to the ABSPATH guard.
 *
 * @package MhmCurrencySwitcher
 */

define( 'ABSPATH', '/tmp/' );

// Core defines this in wp-includes/default-constants.php, which the stubs
// package does not carry.
define( 'DAY_IN_SECONDS', 86400 );
define( 'MHMCS_VERSION', '0.1.0' );
define( 'MHMCS_FILE', __DIR__ . '/mhm-currency-switcher.php' );
define( 'MHMCS_PATH', __DIR__ . '/' );
define( 'MHMCS_URL', 'https://example.com/wp-content/plugins/mhm-currency-switcher/' );
define( 'MHMCS_BASENAME', 'mhm-currency-switcher/mhm-currency-switcher.php' );
