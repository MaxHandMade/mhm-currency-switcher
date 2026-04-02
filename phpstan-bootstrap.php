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
define( 'MHM_CS_VERSION', '0.1.0' );
define( 'MHM_CS_FILE', __DIR__ . '/mhm-currency-switcher.php' );
define( 'MHM_CS_PATH', __DIR__ . '/' );
define( 'MHM_CS_URL', 'https://example.com/wp-content/plugins/mhm-currency-switcher/' );
define( 'MHM_CS_BASENAME', 'mhm-currency-switcher/mhm-currency-switcher.php' );
