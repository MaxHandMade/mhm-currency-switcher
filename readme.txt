=== MHM Currency Switcher ===
Contributors: maxhandmade
Tags: woocommerce, currency, multi-currency, currency switcher, exchange rate
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
WC requires at least: 7.0
WC tested up to: 9.0

Multi-currency support for WooCommerce. Let your customers browse, shop, and checkout in their preferred currency with real-time exchange rates.

== Description ==

MHM Currency Switcher adds multi-currency support to your WooCommerce store. Customers can browse products, add items to their cart, and complete checkout in their preferred currency with real-time exchange rates.

**Key Features**

* Add multiple currencies with real-time exchange rates
* Currency switcher via shortcode, widget, or Elementor
* Product page price display widget with country flags
* Automatic exchange rate fetching (ExchangeRate-API)
* Fee & rounding configuration per currency
* Cookie-based currency persistence
* WooCommerce HPOS compatible
* Elementor widgets included

**Pro Version**

Upgrade to Pro for advanced features:

* Unlimited currencies (Free: 3 total — base + 2)
* Scheduled automatic rate updates (Free: one-time fetch)
* Geolocation-based currency detection
* Fixed prices per product
* Payment gateway restrictions per currency
* Multilingual support
* Advanced compatibility modules

== Installation ==

1. Upload the `mhm-currency-switcher` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Make sure WooCommerce is installed and activated.
4. Go to **WooCommerce > Currency Switcher** to configure your currencies and exchange rates.

== Frequently Asked Questions ==

= How many currencies can I add? =

The free version supports up to 3 currencies total (your base currency plus 2 additional currencies). The Pro version supports unlimited currencies.

= How are exchange rates fetched? =

Exchange rates are fetched from ExchangeRate-API in real time. In the free version, rates are fetched on demand (one-time). The Pro version supports scheduled automatic updates so your rates stay current without manual intervention.

= What is the difference between Free and Pro? =

The free version covers the essentials: up to 3 currencies, on-demand rate fetching, a currency switcher shortcode/widget, and Elementor widgets. Pro adds unlimited currencies, scheduled rate updates, geolocation, fixed per-product prices, payment gateway restrictions, multilingual support, and advanced compatibility modules.

= Is the plugin compatible with WooCommerce HPOS? =

Yes. MHM Currency Switcher fully supports WooCommerce High-Performance Order Storage (HPOS / Custom Order Tables).

== Screenshots ==

== Changelog ==

= 0.2.0 =
* Added: Turkish (tr_TR) translation — full admin panel and frontend coverage
* Added: CHANGELOG.md and README.md documentation
* Added: WP 6.5+ .l10n.php performance-optimized translations
* Fixed: Pro license bypass — enforce_limit now only applies for Lite users
* Fixed: Base currency symbol showing code instead of symbol in switcher
* Fixed: Product widget double-conversion issue with raw price meta
* Fixed: Base currency reads from WooCommerce setting correctly
* Fixed: Format data auto-filled from WooCommerce defaults for new currencies

= 0.1.0 =
* Initial release
* Core currency conversion engine
* Real-time exchange rates with fallback API chain
* Currency switcher shortcode and widget
* Product page multi-currency display
* WooCommerce cart, checkout, and order integration
* Elementor widgets (Currency Switcher + Price Display)
* WP-CLI commands (rates sync, cache flush, currencies list, status)
* Admin settings panel (React with 5 tabs)
* Freemium licensing: Lite (2 currencies) / Pro (unlimited)
* SVG country flag icons for 22 currencies
* 79 PHPUnit tests with 154 assertions

== Upgrade Notice ==

= 0.2.0 =
Bug fixes for Pro license and currency symbol display. Turkish translation added.

= 0.1.0 =
Initial release.
