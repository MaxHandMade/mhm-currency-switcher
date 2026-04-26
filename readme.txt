=== MHM Currency Switcher ===
Contributors: maxhandmade
Tags: woocommerce, currency, multi-currency, currency switcher, exchange rate
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.7.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
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

= 0.7.0 =
* **New: "Manage Subscription" button on the License tab.** Opens the Polar customer portal in a new browser tab — cancel auto-renewal, update payment, switch plans, or resubscribe without leaving WP admin. Renders next to "Re-validate Now" and "Deactivate License" inside the License Management zone, only when the license is active.
* **State-driven button emphasis.** Standard primary blue at >30 days, yellow at <=30 days, amber + glow at <=7 days. Customer always sees how close their renewal is regardless of whether they read the email reminders. Disabled-state styling preserves visual feedback while the portal session is being minted.
* **New:** `LicenseManager::create_customer_portal_session()` public method (snake_case parity to Rentiva v4.32.0). Calls the new `mhm-license-server v1.11.0+` endpoint `/licenses/customer-portal-session` via the existing RSA-signed request pipeline.
* **New:** REST endpoint `POST /mhm-currency/v1/license/manage-subscription` (manage_options gated).
* **Pairs with `mhm-license-server v1.11.0+` and `mhm-polar-bridge v1.9.0+`.** Older servers without the customer-portal endpoint return a graceful in-tab notice instead of breaking.
* **Tests:** 148 -> 158 PHPUnit (+10: 5 LicenseManagerCustomerPortalSession, 5 ManageSubscriptionEndpoint). PHPCS new files: 0 errors. PHPStan level 6: 0 errors. i18n: 4 new strings, all translated to Turkish.

= 0.6.0 =
* **BREAKING — Asymmetric crypto licence security:** The 6 Pro feature gates (`can_use_geolocation`, `can_use_fixed_prices`, `can_use_payment_restrictions`, `can_use_auto_rate_update`, `can_use_multilingual`, `can_use_rest_api_filter`) now require an RSA-signed feature token from `mhm-license-server` v1.10.0+. The legacy `is_pro()`-only fallback (engaged whenever `MHM_CS_LICENSE_FEATURE_TOKEN_KEY` was unset, which was the zero-config default) has been removed. A cracked binary that patched `Mode::can_use_*()` or `LicenseManager::is_active()` to always-return-true could re-enable Pro features on a real-license site under v0.5.x; v0.6.0 closes that hole because public keys can verify but cannot mint, so a forged token is rejected.
* **New:** `License\LicenseServerPublicKey` — embedded RSA-2048 public key (no operator config required, ships with the build).
* **Changed:** `FeatureTokenVerifier` migrated from HMAC to `openssl_verify`. New API surface — `verify($token, $expected_site_hash): bool` + `has_feature($token, $feature_name): bool`.
* **Changed:** `Mode::feature_granted()` requires an active license AND a valid RSA-signed token whose `site_hash` matches the local site AND which carries the requested feature flag. `MHM_CS_DEV_PRO` constant still works as a developer escape hatch (same trade-off as `LicenseManager::is_active()` already had).
* **Removed:** `ClientSecrets::get_feature_token_key()` and the `MHM_CS_LICENSE_FEATURE_TOKEN_KEY` wp-config constant — both were the symmetric remnants of v0.5.x. Safe to delete from wp-config.
* **Deploy ordering:** Upgrade `mhm-license-server` to v1.10.0 BEFORE upgrading clients to v0.6.0. New clients against an old server cannot verify the HMAC-signed token the old server still emits — Pro silently goes dark.
* **Tests:** 137 → 148 (+11). RSA verify roundtrip with paired fixture key, foreign-key forge rejection, signature-byte tamper, payload tamper, expired-token, site_hash mismatch, no-legacy-fallback enforcement. PHPCS clean.

= 0.5.2 =
* **Reverse-validation UX fix:** v0.5.1 made `MHM_CS_LICENSE_PING_SECRET` mandatory — without it, the verify endpoint returned `ping_secret_not_configured` and the license server rejected activation with `site_unreachable`. That meant every customer site needed an operator-pinned secret in `wp-config.php`, which is unworkable for an end-customer product. v0.5.2 falls back to the per-activation `site_hash` (already shared between server and client via the activate body) when `PING_SECRET` is unset. **Customers can now activate licenses without any `wp-config.php` edits.**
* **Backward compatible:** When `MHM_CS_LICENSE_PING_SECRET` is defined the endpoint still uses it, so v0.5.1 deploys with the operator config baked in keep working unchanged.
* **Pairs with `mhm-license-server v1.9.1+`:** The server applies the matching `site_hash` fallback in `Security\SiteVerifier::verify()`. Older v1.9.0 servers that already pin `PING_SECRET` work unchanged via the legacy path.
* **Tests:** Updated `VerifyEndpointTest` to cover the site_hash fallback path. 137/137 PHPUnit, baseline preserved.

= 0.5.1 =
* Fixed: `is_staging()` used PHP 8.0's `str_ends_with()` while the plugin supports PHP 7.4+. Replaced with a `substr_compare()` call so license activation no longer fails on PHP 7.4 when the host matches the staging/local pattern list.
* Fixed: `OrderFilterTest::test_get_order_currency_returns_code` was failing on every CI matrix since v0.4.1 because the anonymous order stub did not satisfy the `instanceof \WC_Order` guard in `OrderFilter::get_order_currency()`. Added a minimal `WC_Order` stub in `tests/bootstrap.php` and made the test stub extend it.

= 0.5.0 =
* Security: Hardened license client against source-level tampering. Verifies the HMAC signature on every server response, exposes a public reverse-validation ping endpoint, and consults a server-issued feature token for every Pro feature gate. Requires mhm-license-server v1.9.0+ and three new wp-config secrets (`MHM_CS_LICENSE_RESPONSE_HMAC_SECRET`, `MHM_CS_LICENSE_FEATURE_TOKEN_KEY`, `MHM_CS_LICENSE_PING_SECRET`). When the feature-token secret is unset, gates fall back to the v0.4.x behaviour so existing installs keep working.
* Added: `/wp-json/mhm-currency-switcher-verify/v1/ping` REST route answering the license server's activation-time reverse-validation challenge.

= 0.4.1 =
* Security: License client participates in per-product license binding introduced by mhm-license-server v1.8.0+. Requires the matching server version.

= 0.3.0 =
* Added: Navigation menu integration — add currency switcher to any WordPress nav menu
* Added: Geolocation-based currency detection with CloudFlare + WC MaxMind cascade (Pro)
* Added: Automatic exchange rate updates via WordPress cron scheduling (Pro)
* Added: Per-product fixed pricing with WC Product Data tab and variation support (Pro)
* Added: Built-in currency symbol map for 50+ currencies (third-party plugin compatibility)
* Added: High-quality SVG flag icons for 283 countries (upgraded from 20x15 to 640x480)
* Added: License tab with detailed info card, masked key, plan, expiry, and refresh button
* Fixed: Dropdown forced open on page load by theme CSS overrides
* Fixed: Dropdown position and header resize issues in nav menu context
* Fixed: Currency symbols hijacked by third-party plugins (YayCurrency compatibility)
* Fixed: WooCommerce admin settings symbol corruption when format filters ran on admin pages
* Fixed: License expires_at normalization to ISO 8601 format

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
* SVG country flag icons for 283 countries
* 79 PHPUnit tests with 154 assertions

== Upgrade Notice ==

= 0.3.0 =
Major update: nav menu integration, 283 high-quality flag icons, geolocation detection (Pro), automatic rate updates (Pro), per-product fixed pricing (Pro), and multiple theme compatibility fixes.

= 0.2.0 =
Bug fixes for Pro license and currency symbol display. Turkish translation added.

= 0.1.0 =
Initial release.
