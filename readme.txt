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
* Currency switcher via shortcode — usable in a text widget, nav menu, or Elementor
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

= Can a per-product fixed price be a sale price? =

No. A fixed price is stored per product and currency, not per price type, so the same amount is used for the regular price and the sale price. A product on sale in your base currency shows as not on sale in a currency you have given a fixed price to. If you need the sale to carry across, leave that currency to the exchange rate instead of fixing it.

= Is there a limit on how often the conversion endpoint can be called? =

Yes. When cache compatibility mode is on, prices on cached pages are converted through a public REST endpoint, and one address may call it 120 times a minute by default. Ordinary browsing is nowhere near that — a page makes one request. If your shop sits behind a reverse proxy or a CDN that makes every visitor look like the same address, raise or disable the limit with the `mhmcs_convert_rate_limit` filter. Note that the address is read from the proxy headers WooCommerce is configured to trust, which can be forged; the limit bounds accidental hammering rather than a determined attacker.

= I see a warning that cache compatibility is not being applied. What is it? =

Some themes and plugins define WooCommerce's cart constant on every page, usually to show a cart total in the header. When that happens the plugin treats every page as a checkout — the customer's money is at stake — and converts prices on the server, which is exactly what cache compatibility mode exists to avoid. Nothing looks wrong on the site: prices are still correct for whoever loads the page first, and then a page cache can serve that person's currency to everyone else. Because there is no visible symptom, the plugin says so in the admin instead. The notice clears itself as soon as a front-end page renders normally again.

= Why does the structured data show a different currency from the price on the page? =

With cache compatibility on, the page is generated in your base currency and the browser converts the prices afterwards, so the machine-readable product data a crawler reads stays in the base currency. This is deliberate and is not corrected: pinning it the other way would leave the data disagreeing with the page in the one case where the two currently agree — with cache compatibility switched off, where the page and the structured data are both converted on the server.

= Does the WooCommerce REST API return converted prices? =

Only when the request asks for a currency: `?currency=EUR` on a `wc/v3` product request converts `price`, `regular_price` and `sale_price` and adds a `currency_code` field. Without the parameter the response is pinned to your base currency, so the answer never depends on the cookies of whoever is calling. A per-product fixed price takes precedence over the exchange rate here, exactly as it does on the shop page.

One known limit: the `price_html` field is not pinned in the same way. It cannot be reached in that state by a normal `wc/v3` client — only by code that dispatches an internal REST request during a page render — so no integration sees it, but it is not consistent with the three numeric fields and is recorded here rather than left unsaid.

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
  per-product prices, and a WooCommerce REST API currency filter.
* Identifiers renamed to the `mhmcs` prefix. Settings from earlier
  development builds are not carried over.
