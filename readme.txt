=== MHM Currency Switcher ===
Contributors: maxhandmade
Tags: woocommerce, currency, multi-currency, currency switcher, exchange rate
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 7.0
WC tested up to: 9.0

Multi-currency support for WooCommerce. Let your customers browse, shop, and checkout in their preferred currency with real-time exchange rates.

== Description ==

MHM Currency Switcher adds multi-currency support to your WooCommerce store. Customers can browse products, add items to their cart, and complete checkout in their preferred currency with real-time exchange rates.

**Key Features**

* Add multiple currencies with real-time exchange rates
* Currency switcher via shortcode, widget, nav menu, or Elementor
* Product page price display widget with country flags
* Navigation menu integration — add switcher to any WordPress menu
* Automatic exchange rate fetching (ExchangeRate-API)
* Fee & rounding configuration per currency
* Cookie-based currency persistence
* WooCommerce HPOS compatible
* Elementor widgets included
* Unlimited currencies
* Scheduled automatic exchange rate updates
* Geolocation-based currency detection
* Fixed prices per product
* Payment gateway restrictions per currency
* Multilingual currency names

== Installation ==

1. Upload the `mhm-currency-switcher` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Make sure WooCommerce is installed and activated.
4. Go to **WooCommerce > Currency Switcher** to configure your currencies and exchange rates.

== Frequently Asked Questions ==

= How many currencies can I add? =

As many as you like — there is no limit on the number of currencies.

= How are exchange rates fetched? =

Exchange rates are fetched from ExchangeRate-API in real time, either on demand or on a schedule you configure (hourly, twice daily, or daily) so your rates stay current without manual intervention.

= Is the plugin compatible with WooCommerce HPOS? =

Yes. MHM Currency Switcher fully supports WooCommerce High-Performance Order Storage (HPOS / Custom Order Tables).

== Screenshots ==

== External services ==

This plugin connects to third-party exchange rate APIs to keep currency
conversion rates up to date. No personal data is transmitted; only the
three-letter base currency code (for example `USD`) is sent.

**ExchangeRate-API**

Used as the primary source of exchange rates. A request is sent to
`https://api.exchangerate-api.com/v4/latest/{BASE_CURRENCY}` when you press
"Sync rates" in the admin panel, and on the schedule you configure under
automatic rate updates (hourly, twice daily, or daily). Only the base
currency code is sent.

Terms of service: https://www.exchangerate-api.com/terms
Privacy policy: https://www.exchangerate-api.com/privacy-policy

**Fawaz Ahmed Currency API (served over jsDelivr)**

Used as a fallback when ExchangeRate-API is unreachable. A request is sent
to `https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{base}.json`
under the same conditions as above. Only the base currency code is sent.

Currency API: https://github.com/fawazahmed0/exchange-api
jsDelivr terms of service: https://www.jsdelivr.com/terms
jsDelivr privacy policy: https://www.jsdelivr.com/privacy-policy-jsdelivr-net

== Changelog ==

= 1.0.0 =
* First public release.
* All features are available to everyone: unlimited currencies, automatic
  exchange rate updates, geolocation-based currency detection, fixed
  per-product prices, per-currency payment method restrictions,
  multilingual currency names, and a WooCommerce REST API currency filter.
* Identifiers renamed to the `mhmcs` prefix. Settings from earlier
  development builds are not carried over.
