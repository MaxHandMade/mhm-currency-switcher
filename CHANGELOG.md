# Changelog

All notable changes to the MHM Currency Switcher plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.3] - 2026-07-29

### Fixed

- **Upgrading from a version older than 0.3.0 silently reset the plugin's settings, and could lose a shop's entire currency configuration.** The 0.3.0 prefix rename (`mhm_currency_switcher_*` → `mhmcs_*`) shipped without a migration, on the recorded assumption that only one installation existed. Two consequences followed. The current option names are seeded by the activation hook alone, and that hook does not fire on an in-place update, so a site crossing the rename ends up with no settings row at all — measured on a real 0.2.0 install, where geolocation detection went from on to off with nothing reported anywhere. And because `CurrencyStore::save()` is byte-identical either side of the rename, a shop that had configured currencies was holding them in exactly the shape the current reader expects: the data was never incompatible, only the key was, and it was being left behind. A one-time migration now carries `auto_detect`, `rate_update_interval` and the product-widget settings onto the current names, carries a configured currency payload across unchanged, deletes the old rows, and unschedules the pre-rename rate-update event so an upgraded site is not left running an orphan alongside the live one.
- The pre-0.3.0 activation default — a flat list of four currency codes — is deliberately **not** carried. `CurrencyStore::load()` has never been able to read that shape, which was confirmed by loading it on a running 0.2.0 install: the store reported an empty currency set while that row sat in the table. Those four currencies were never live, so carrying them would switch on currencies the shop has never displayed rather than restore anything lost.

### Changed

- The fresh-install settings defaults moved to `LegacyOptionMigrator::default_settings()`, so activation and the upgrade path seed from one definition instead of two hand-written copies. Two copies drifting apart is what produced the unreadable currency row in the first place.

## [1.1.2] - 2026-07-29

### Fixed

- **The settings screen never loaded on WordPress 6.0 to 6.5, and the plugin claimed to support them.** The admin bundle declares `react-jsx-runtime` as a script dependency — `@wordpress/scripts` emits it automatically — but WordPress core only registers that handle from 6.6. An unregistered dependency makes `wp_enqueue_script` drop the script without a word, so on 6.0–6.5 the settings page has been an empty container in **every release to date** — the dependency is present in the built asset of every tag back to 0.3.0, and each of those releases advertised `Requires at least: 6.0`. The floor is now **6.6**, which is the oldest version the plugin has actually worked on. The integration matrix moved with it: its lowest pair was testing 6.0, a version we no longer claim, and nothing tested the version we do.
- **Every control in the currency table was unnamed for assistive technology.** Only the currency picker's missing label was fixed first; sweeping the class found seven more — the enable toggle, both rate controls, both fee controls and both rounding controls — each announced as a bare "combo box" or "edit" with no indication of what it changed or which currency it belonged to. All of them now carry a per-row name ("Rate type for EUR", "Enable TRY"), hidden from sight so the table looks the same.
- **The currency picker was announced to screen readers as an unlabelled button.** Its visible "Currency" label was never associated with the control it labels. The label is now tied to the trigger, and the trigger reports whether its list is open.

### Changed

- **Cart & Checkout Blocks compatibility is now declared.** The plugin has always worked with the block cart and checkout — they read their amounts from the Store API, which `ConversionContext` treats as a money context and converts on the server — but WooCommerce had no way to know that, so it warned shop owners about the plugin on those screens. Verified end to end before declaring: every amount on both block pages converts, and subtotal plus fees plus shipping plus tax equals the displayed total.
- `npm run lint:js` is now part of CI. It existed as a script that nothing ran, and had rotted to eleven errors — one of which was an accessibility defect. Note that the linter sees raw `<label>` elements only; it cannot read the props of `@wordpress/components` controls, which is why the seven unnamed controls above stayed invisible to it.

## [1.1.1] - 2026-07-28

### Fixed

- **The settings screen emitted a WordPress deprecation warning on every load.** `SelectControl` and `TextControl` still used the 36px default size, which is deprecated since WordPress 6.8 and removed in 7.1. All nine call sites now pass `__next40pxDefaultSize`, so the panel is already on the size that becomes the default.
- **`Plugin URI` pointed at `wpalemi.com/plugins/mhm-currency-switcher`, which returns 404.** It now points at the page that exists.

### Changed

- `Requires Plugins: woocommerce` is declared in the plugin header and `readme.txt`. The plugin has always been WooCommerce-only — guarded by `class_exists( 'WooCommerce' )` — but WordPress 6.5+ can now enforce that at activation instead of letting the plugin activate into a no-op.
- **`WC requires at least` 7.0 → 7.4 and `WC tested up to` 9.0 → 10.9.** Neither number described what is tested: the lowest pair in the CI matrix installs WooCommerce 7.4.0, and the highest installs whatever is current. Both readmes carried the same stale claim and were corrected with them.
- `bin/install-wp-tests.sh` now reads the WooCommerce version back out of the installed plugin and prints it. `WC_VERSION=latest` downloads a moving target, so the label the script used to print proved nothing about what actually ran — and "tested up to" is only allowed to claim what that line shows.
- **`WC_VERSION=latest` was installing WooCommerce trunk, not the latest release.** The new version line above exposed it on its first CI run: the job reported `WC latest -> 11.0.0` while the released version was 10.9.4, and `woocommerce.11.0.0.zip` did not exist — `downloads.wordpress.org/plugin/woocommerce.zip` serves trunk, which WooCommerce bumps to the next version during development (it was `11.0.0-rc.1` an hour later). So the top pair of the matrix tested an unreleased build and no pair covered what shops actually run. It now fetches `woocommerce.latest-stable.zip`.

## [1.1.0] - 2026-07-28

### Added

- **Cache compatibility mode (on by default).** Anonymous shop, archive and product renders stay in the base currency and are wrapped in `<span class="mhmcs-price" data-mhmcs-product="ID">` markers; `assets/js/price-converter.js` converts them through the new `POST mhmcs/v1/convert` endpoint. A page cache can therefore serve one HTML document to every visitor. Cart, checkout, order totals, order emails and the WooCommerce REST API remain server-side, so the amount charged cannot be altered from the browser.
- **`ConversionContext`** (`src/Core/ConversionContext.php`) — one decision, asked by every price, format, coupon, shipping, fee and variation-hash surface, with a one-way memoisation latch. Previously each surface decided for itself.
- **`POST mhmcs/v1/convert`** — public, read-only, `Cache-Control: no-store`, 50 IDs per request, rate limited to 120 requests a minute per address (`mhmcs_convert_rate_limit`).
- **Two admin diagnostics** (`src/Core/CacheCompatDiagnostic.php`): cache compatibility silently defeated by a theme that defines WooCommerce's cart constant site-wide, and a mini-cart left in the base currency because `wc-cart-fragments` is not loaded. Both clear themselves when the cause goes.
- Switcher no longer reloads the page in cache mode: it sets the cookie, converts in place, drops WooCommerce's cached fragments and asks for fresh ones.
- Front-end JavaScript test suite (Jest + jsdom) and a real WordPress+WooCommerce integration suite running three WP×WC pairs in CI.

### Fixed

- **Prices were converted on admin screens and in admin AJAX.** The order editor reads product prices through the same filter it writes with, so "add product" on the order screen could store a converted price as the line item — data, not display.
- **`wc/v3` REST reads depended on the caller's cookies.** They are now pinned to the base currency unless the request asks for a currency, and a cookie plus `?currency=` no longer double-converts.
- **Scheduled tasks and WP-CLI converted prices**, because `WC_Geolocation` resolved the server's own IP to a country.
- **Cart totals were not recalculated when the visitor changed currency**, so after a switch the mini-cart showed the previous currency's amount under the new currency's symbol.
- **A percentage fee per currency never applied.** The admin screen wrote `percent` and the sanitiser expected `percentage`, so the fee was dropped and the effective rate was wrong.
- **A currency with a zero exchange rate failed open**, showing base amounts as though they were converted.
- **Rounding applied only to product prices**; it now covers shipping, fees and coupon discounts. The coupon *threshold* deliberately stays unrounded.
- **Shipping tax was not converted** with the shipping cost, so customers saw base-currency tax on a converted shipping line.
- **Block themes overwrote converted prices** ~700ms after load, when `wc-block-components-product-price` re-rendered from the page's embedded data.
- Manual exchange rates were overwritten by a rate sync; `RateProvider::apply_rates()` is now the single writer.
- Order-email currency context was set up and torn down on the same action.

### Changed

- `fee.type` now defaults to `none` (the reader and the three writers disagreed).
- Variable products are forced onto WooCommerce's AJAX variation path in cache mode, so `data-product_variations` is `false`.
- `readme.txt` gained a "Known limits" section documenting the accepted trade-offs of cache mode; `README.md` carries the summary.

### Removed

- Dead compatibility stub `MhmRentiva` and `CompatibleInterface`.

## [1.0.0] - 2026-07-23

### Changed

- **First public release as a single free plugin.** The licensing layer (`src/License/`, 7 files), the currency quota, the `ProGate` React UI and the License tab were removed. Everything the plugin actually implements is unconditionally available: unlimited currencies, scheduled exchange-rate updates, geolocation-based detection, per-product fixed prices and the WooCommerce REST API currency filter. Two of the gates guarded features that were never built — per-currency payment-gateway restrictions and multilingual currency names — and their dead settings are purged on upgrade rather than announced.
- All identifiers renamed to the single-token prefix `mhmcs_` / `MHMCS_`. Settings from earlier development builds are not carried over.
- Licensed under GPLv2 or later.

### Added

- `uninstall.php`, removing every option, transient, post meta and scheduled event the plugin creates, including pre-1.0.0 names.
- `== External services ==` documentation for ExchangeRate-API and the jsDelivr-served Fawaz Ahmed currency API.

## [0.7.1] - 2026-04-27

### Fixed

- **CRITICAL: `daily_verification()` failed open on server errors.** When the licence server was unreachable, returned a 404 (`rest_no_route`), or a transport error (cURL timeout, SSL failure) occurred, `daily_verification()` returned silently without touching the cached option. A `status='active'` row from a previously-successful activation stayed valid forever. Real-world reproduction: `maxhandmade.com` server decommissioned; plugin migrated to `wpalemi.com`; old key `U9SP****S4N7` unknown on the new server; daily cron returned `_error`; plugin held Pro state indefinitely even after the switch. Fix mirrors Rentiva `LicenseManager` (validate() lines 354-387): on `_error`, immediately write `status='inactive'` + clear `activation_id` + clear `feature_token` + update `last_check`. Transient transport failures recover on the next 6-hourly cron run; persistent errors (404, licence_not_found) drop the plugin to Lite within one validation cycle.
- **`daily_verification()` return type changed from `void` to `array`.** Returns `{ok: bool, status: string, message: string}`. Existing cron callers ignore the return value — backward-compatible.
- **Re-validate Now notice was always-success regardless of outcome.** The `Settings.php` re-validate handler printed a success notice unconditionally. Now the handler captures the `daily_verification()` result, carries `revalidate_ok=0|1` and `revalidate_status` in the redirect query arg, and the notice block branches accordingly: success (green) when `ok=true`; warning (amber) with "check your licence key on the License tab" when `ok=false`.

### Tests

- 4 new tests in `LicenseManagerDailyVerificationTest`: server 404 fail-closed, transport error fail-closed, active-state regression, inactive-state regression.
- 158 → 162 PHPUnit, 0 PHPCS errors on touched files, PHPStan level-6 0 errors on touched files.
- 6 new i18n strings translated to Turkish (fuzzy count = 0 after msgmerge).

## [0.6.5] - 2026-04-26

### Added

- **Re-validate Now button moved into the License tab.** v0.6.2 placed the manual re-validate button in a server-rendered toolbar above the React mount point — that meant it was visible on every CS admin tab (Manage Currencies, Display Options, Checkout Options, Advanced, License) and rendered above the plugin title heading, which was the wrong visual placement. The button now lives inside the React `License.jsx` component, next to "Deactivate License", so it only appears on the License tab when a licence is active. The PHP-side `?mhm_cs_revalidate=1` handler is unchanged — `Settings.php` now exposes a pre-built nonce-signed URL via the localized `mhmCsAdmin.revalidateUrl` and the React click handler navigates with `window.location.href`, reusing the existing redirect flow.
- **Re-added the React admin-app source.** The `admin-app/src/` directory and `package.json` were removed from this repo on commit `911734d` (2026-04-06) under "Remove internal development files". That broke the source-of-truth invariant — any future React change required a manual `git show 1e6649c:...` recovery to land. Source-of-truth is back, the existing asymmetric-crypto licence enforcement is what protects the commercial model, not source secrecy.

### Fixed

- **License-key placeholder corrected.** The activation form's `placeholder` was `"MHM-XXXX-XXXX-XXXX-XXXX"` — but the licences actually issued by `mhm-license-server` follow the `XXXX-XXXX-XXXX-XXXX` format (no `MHM-` prefix; e.g. `QMLZ-TQZP-2JYH-2AZ3`, `L6TL-K639-ZMWQ-QEU8`). Customers were typing the prefix and hitting validation errors, or staring at the placeholder wondering whether the prefix was required. The placeholder now reflects the real format.

### Notes

- **LITE / PRO badge in the plugin title.** This release does NOT touch the existing `App.jsx` `! isPro && <Lite>` / `isPro && <Pro>` conditional. The badge is driven by `Mode::is_pro()` (server-side), which returns `true` whenever `wp_options.mhm_cs_license_data.status === 'active'`. If an admin sees "LITE" while the License tab reports "Active + PRO", the cause is almost always a stale build/index.js cache or a plugin-upgrade timing race — a hard reload (Ctrl+Shift+R) clears it. v0.6.5 ships a fresh asset hash so this should not recur on first load after upgrade.

### Tests

148 / 148 PHPUnit on PHP 7.4 AND PHP 8.2. PHPCS clean. PHPStan clean (with --memory-limit=2G).

## [0.6.4] - 2026-04-26

### Fixed

- **Default `API_BASE` pointed at the retired licence host.** `LicenseManager::API_BASE` was `https://maxhandmade.com/wp-json/mhm-license/v1`, a leftover from the v0.4.x WC-fulfilment architecture before the licence server moved to wpalemi.com. The old host still served a partial endpoint set, so `/licenses/validate` returned a plausible-looking response and the daily-verification cron never complained, but `POST /licenses/activate` returned the WP-core 404 "no route matched" page (translated to Turkish on a `tr_TR` locale, which made it look like a plugin string). A customer trying to enter a real Currency Switcher key hit that dead end. The constant now defaults to `https://wpalemi.com/wp-json/mhm-license/v1`. Override hierarchy (`MHM_CS_LICENSE_API_BASE` constant → env var → default) is unchanged for installs that already point at a self-hosted server.
- **Self-heal trigger missed the most common corrupt-row shape.** v0.6.3 keyed self-heal on `status !== 'active'`, but the v0.6.0–v0.6.2 activate flow's `$result['status'] ?? 'active'` fallback meant rejected rows were saved with `status: 'active'` (since the WP REST API WP_Error body has no `status` field at all). The check now keys on `activation_id` being empty — the server only returns an activation_id on a successful activate, so an empty value on a row that has a license_key is unambiguously a rejected attempt. Belt-and-suspenders: the previous `status !== 'active'` clause is preserved as a secondary trigger for any future code path that records the real server status.

### Why

Discovered when v0.6.3's self-heal silently no-op'd on a real install: the License tab kept showing "Active + PRO" for a key the server had rejected, because the corrupt row's `status` was the default-fallback string `'active'`, not the `'product_mismatch'` we expected. Switching the self-heal key to `activation_id` makes the fix robust against the actual production data shape.

## [0.6.3] - 2026-04-26

### Fixed

- **Critical: cross-product activation accepted as success.** The v0.6.0–v0.6.2 `LicenseManager::request()` decoded the response body but never checked the HTTP status code. When the licence server replied with `WP_Error('product_mismatch', …)` (REST API → HTTP 400 + JSON `code`/`message` body), the client treated the body as a successful payload, so `activate()`'s `success === false` guard didn't fire and the rejected key got written into `license_data` with `status: 'product_mismatch'`. Mode RSA verify still kept Pro features locked (defense in depth held — the embedded public key never validated a token signed for the wrong product), but the License screen showed a confusing "Active + PRO" badge over a "LITE" plugin header. Mirrors the equivalent `if ($code >= 400)` guard mhm-rentiva has had since the asymmetric-crypto rollout.
- **Self-heal of corrupt license rows.** `register()` now wipes any `license_data` row whose `status` field is anything other than `'active'`, repairing installs that already accepted a rejected key under v0.6.0–v0.6.2 before this fix shipped.

### Why

Surfaced when a customer admin entered the same license key into both Rentiva (correct product) and Currency Switcher (wrong product) on the same site. The Currency Switcher License tab reported the key as "Active" while the plugin header still read "LITE" — a contradiction that could only exist if the activate flow stored a row that the gate logic then refused to honour. The HTTP-status-code guard removes the contradiction at the source.

## [0.6.2] - 2026-04-26

### Added

- **"Re-validate Now" button on the admin page:** Renders a server-rendered toolbar above the React mount point so the SPA does not need to know about it (no JS bundle changes). Clicking it deletes the `mhm_cs_license_visit_throttle` transient, calls `LicenseManager::daily_verification()`, and redirects with `?license=revalidated` to surface a "🔄 License re-validated" success notice. Lets a customer admin force an immediate licence-server check when an activation was just revoked or re-issued, without waiting for the 5-minute throttle or 6-hour cron. Mirrors the v4.31.2 control on the Rentiva client so multi-product customers get a consistent UX.

### Why

v0.6.1 reduced the worst-case server-revocation lag to ~5 minutes (page visit) / 6 hours (cron). That is fine for the steady state but irritating when an operator just changed something on the licence-server side and wants to confirm the customer site picked it up. Re-validate Now closes that gap to "click + a few hundred ms".

## [0.6.1] - 2026-04-26

### Changed

- **Immediate license revocation:** A licence deactivated from the licence-server admin now propagates to the customer site within minutes instead of up to 24 hours, in three reinforcing layers:
  - Cron rotated from `daily` to `every6hours`. Existing daily schedules from prior versions are detected at plugin load and rotated automatically (`wp_get_scheduled_event` → `wp_unschedule_event` → `wp_schedule_event` flow); operators do not need to deactivate/reactivate the plugin.
  - `Settings::enqueue_assets()` now fires a force-validate (`LicenseManager::daily_verification()`) when the admin opens the MHM Currency Switcher page. Throttled by a 5-minute transient so reloads on the same page do not hammer the licence server.
  - `LicenseManager::daily_verification()` now drops the cached `feature_token` whenever the server reports any non-active state, so `Mode::feature_granted()` fails closed on the next page load even before the cron fires.

### Why

The v0.6.0 release shipped the asymmetric-crypto verifier but left the
revocation lag at 24 hours, which produced a counter-intuitive failure
mode: an admin removing an activation row from the licence server saw
the customer site still report Pro until the next cron tick. The
combined throttled visit-validate + 6-hour cron + defensive token
clear collapse that window to ~5 minutes for active operators and ~6
hours for headless installs.

### Tests

148 / 148 PHPUnit (+0; runtime behaviour smoke-tested live), 0 new PHPCS errors.

## [0.5.2] - 2026-04-25

### Fixed

- **Reverse-validation UX:** v0.5.1's `VerifyEndpoint::handle_ping()` returned 503 `ping_secret_not_configured` when `MHM_CS_LICENSE_PING_SECRET` wasn't defined, and the license server then rejected activation with `site_unreachable`. That meant every customer site had to ship a matching secret in `wp-config.php` — unworkable for an end-customer product. The handler now falls back to the per-activation `site_hash` (computed the same way `LicenseManager::site_hash()` does — sha256 of `home_url + site_url + WP version + PHP version`, JSON-encoded) when `ClientSecrets::get_ping_secret()` returns empty. Server-side `mhm-license-server v1.9.1+` applies the matching fallback so the HMAC challenge stays verifiable.

### Backward compatibility

- When `MHM_CS_LICENSE_PING_SECRET` is defined the endpoint still uses it, so v0.5.1 deploys with the operator config baked in keep working unchanged.
- Pair with `mhm-license-server v1.9.1+`. Older v1.9.0 servers that already pin `PING_SECRET` work unchanged via the legacy path.

### Test coverage

`VerifyEndpointTest::test_returns_error_when_ping_secret_not_configured` was replaced with `test_falls_back_to_site_hash_when_ping_secret_unset`, asserting both the 200 status and that the `challenge_response` equals `HMAC(challenge, expected_site_hash)`. Total suite: 137 tests, 266 assertions.

## [0.5.1] - 2026-04-24

### Fixed
- `LicenseManager::is_staging()` called `str_ends_with()` which requires PHP 8.0+, but the plugin advertises `"php": ">=7.4"` in composer.json. The path was never exercised by pre-v0.5.0 tests; v0.5.0's `LicenseManagerHardeningTest` triggers `activate()`/`daily_verification()` which flow through `is_staging()`, turning a latent compatibility bug into hard CI failures on the PHP 7.4 matrix. Replaced the call with a `substr_compare()` check.
- `OrderFilterTest::test_get_order_currency_returns_code` has been failing on every CI run since v0.4.1 because the anonymous order stub did not extend `\WC_Order`, so the `instanceof` guard in `OrderFilter::get_order_currency()` returned null. Added a minimal `WC_Order` stub to `tests/bootstrap.php`; the test stub now extends it and the check passes.

## [0.5.0] - 2026-04-24

### Added
- License security hardening (Phase C of the v4.30.0 cross-plugin rollout):
  - `src/License/ClientSecrets.php` — resolver for three new shared secrets (`MHM_CS_LICENSE_RESPONSE_HMAC_SECRET`, `MHM_CS_LICENSE_FEATURE_TOKEN_KEY`, `MHM_CS_LICENSE_PING_SECRET`), constants first with `getenv()` fallback.
  - `src/License/ResponseVerifier.php` — HMAC-SHA256 verification of activate/validate responses; mirrors the server's recursive-ksort canonicalization.
  - `src/License/FeatureTokenVerifier.php` — verifier for `{base64}.{hmac}` feature tokens with expiry + per-feature `has_feature()` lookup.
  - `src/License/VerifyEndpoint.php` — public REST route `/wp-json/mhm-currency-switcher-verify/v1/ping` that answers the server's `X-MHM-Challenge` reverse-validation header.

### Changed
- `LicenseManager::activate()` now forwards `client_version = MHM_CS_VERSION` so the server can gate reverse validation on per-product floors.
- `LicenseManager::request()` rejects any response whose `signature` field fails HMAC verification (legacy responses without the field remain accepted during the rollout).
- `LicenseManager::activate()` and `daily_verification()` persist the server-issued `feature_token` alongside the existing license fields.
- `Mode::can_use_*()` now gates each Pro feature on a server-signed feature token (`fixed_pricing`, `geolocation`, `payment_restrictions`, `auto_rate_update`, `multilingual`, `rest_api_filter`). A `return true;` patch on `LicenseManager::is_active()` no longer unlocks features. When `MHM_CS_LICENSE_FEATURE_TOKEN_KEY` is unset, gates fall back to the v0.4.x `is_pro()` behaviour so existing installs keep working.
- `Plugin.php` registers `VerifyEndpoint` during bootstrap so the reverse-validation route is always available.

### Security
- Defends against source-edit attacks previously demonstrated in the v4.27.5 / license-server v1.8.0 era, where a client-only `product_slug` binding could be bypassed by editing the plugin source. The new server-issued feature token shifts authorization off the client binary.

## [0.4.0] - 2026-04-06

### Added
- GitHub Actions CI pipeline — PHPCS, PHPStan, and PHPUnit across PHP 7.4/8.1/8.2/8.3 and WP 6.0/6.4/latest

### Fixed
- PHPCS violations — nonce verification for variation prices, input sanitization, array formatting
- PHPStan level 6 errors — Elementor/CLI exclusions, WP/WC dynamic property ignores
- GBP currency symbol incorrectly showing `€` instead of `£` when added from format defaults

### Changed
- Removed internal development files from public repository (plans, React source, Node config)

## [0.3.0] - 2026-04-03

### Added
- Navigation menu integration — add currency switcher to any WordPress nav menu via Appearance > Menus
- Geolocation-based currency detection — CloudFlare + WooCommerce MaxMind cascade (Pro)
- Automatic exchange rate updates via WordPress cron scheduling (Pro)
- Per-product fixed pricing support with WC Product Data tab and variation support (Pro)
- Built-in currency symbol map (50+ currencies) to bypass third-party plugin filter conflicts
- High-quality SVG flag icons for 283 countries (upgraded from 20x15 to 640x480 viewBox)
- Admin page guard — format filters skip WooCommerce admin settings to prevent symbol corruption
- License `expires_at` normalization to ISO 8601 format
- License tab: detailed info card with masked key, plan, expiry, activation date, and refresh button

### Fixed
- Dropdown forced open on page load by theme CSS (`display: flex` override) — added `!important` guards
- Dropdown appearing beside button instead of below in nav menu context
- Header area growing when dropdown opens — forced `position: absolute` on dropdown
- TRY and other currency symbols showing as "$" due to YayCurrency's `woocommerce_currency_symbol` hook hijacking all symbols to base currency
- WooCommerce admin settings showing "$" for all currency symbols when format filters ran on admin pages
- Currency symbol in switcher now reads from store format data with static symbol map fallback

### Changed
- Flag icon set expanded from 159 (22 currencies) to 283 countries with high-quality 640x480 SVG files
- Switcher CSS uses `!important` overrides for theme compatibility (nav-menu rules, flex layouts)

## [0.2.0] - 2026-04-02

### Added
- Turkish (tr_TR) translation files — full admin panel and frontend coverage
- CHANGELOG.md and README.md documentation
- `.l10n.php` translation file for WordPress 6.5+ performance optimization
- JSON translation file for React admin panel

### Fixed
- Pro license bypass: `enforce_limit()` now only applies for Lite users — Pro users can add unlimited currencies
- Base currency symbol showing currency code instead of symbol (e.g. "TRY TRY" instead of "$\u20ba$ TRY") — added WooCommerce `get_woocommerce_currency_symbol()` fallback
- Product widget reads raw `_price` meta to prevent PriceFilter double-conversion
- Base currency reads from WooCommerce setting instead of stored option
- Format data auto-filled from WooCommerce defaults for new currencies

## [0.1.0] - 2026-03-31

### Added
- Multi-currency price conversion for WooCommerce products
- Cookie-based currency detection with 30-day persistence
- URL parameter currency switching (`?currency=EUR`)
- `[mhm_currency_switcher]` shortcode — dropdown with flag icons
- `[mhm_currency_prices]` shortcode — multi-currency price display on product pages
- React admin panel with 5 tabs: Currencies, Display, Checkout, Advanced, License
- Exchange rate fetching with provider fallback chain (ExchangeRate-API, Fixer, ECB)
- Rate caching with configurable duration
- Conversion fee support (percentage or fixed amount)
- Price rounding options
- Cart, shipping, and coupon amount conversion
- Order currency storage in order meta
- WC REST API currency parameter support
- Elementor integration: Currency Switcher and Price Display widgets
- WP-CLI commands: `rates sync`, `rates get`, `cache flush`, `currencies list`, `status`
- Freemium licensing: Lite (2 currencies) / Pro (unlimited)
- Daily license verification via cron
- SVG country flag icons for 22 currencies
- PHPUnit test suite with 79 tests and 154 assertions
