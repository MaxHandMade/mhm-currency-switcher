# Changelog

All notable changes to the MHM Currency Switcher plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
