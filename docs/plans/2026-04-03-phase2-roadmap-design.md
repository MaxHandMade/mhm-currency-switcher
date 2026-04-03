# MHM Currency Switcher — Phase 2 Roadmap Design

**Goal:** Fix known bugs, complete missing Pro features (cron rate updates, geolocation), and prepare the plugin for production use on live sites.

**Scope:** 3 work items across 2 phases. Checkout UI excluded (FormatFilter already handles it). Mini cart excluded (PriceFilter already covers it). Order email excluded (OrderFilter already implemented).

---

## Phase 1: Bug Fix + Cron Rate Updates

### 1.1 License `expires_at` Bug Fix

**Problem:** `LicenseManager::activate()` stores `$result['expires_at']` from the license server as-is (line 182). When the server returns an empty string or unexpected format, the React admin shows "21 Ocak 1970" (Unix epoch).

**Solution:**

Backend — `src/License/LicenseManager.php`:
- Add a `normalize_expires_at()` private method:
  - Numeric (timestamp) → `gmdate('c', $value)` → ISO 8601
  - Non-empty string → `gmdate('c', strtotime($value))` → ISO 8601
  - Empty / unparseable → empty string (UI shows "—")
- Call in `activate()` and `daily_verification()`

Frontend — `admin-app/src/components/tabs/License.jsx`:
- `formatDate()` already returns "—" for empty strings
- Add `isNaN` guard on `new Date()` result as extra safety

**Files:**
- Modify: `src/License/LicenseManager.php`

### 1.2 Automatic Rate Update Cron

**Problem:** Plugin.php Phase 9 defines the `mhm_cs_update_rates` action hook with rate fetch logic, but `wp_schedule_event()` is never called. Admin panel has interval UI but it's not wired to the backend.

**Solution:**

Scheduling — `src/Plugin.php` Phase 9:
- Read `update_interval` from settings (`hourly` / `twicedaily` / `daily` / empty)
- If empty or `manual` → `wp_clear_scheduled_hook('mhm_cs_update_rates')`
- If value set → check `wp_next_scheduled()`, reschedule if interval changed
- Gate behind `Mode::can_use_auto_rate_update()`

Settings save side-effect — `src/Admin/RestAPI.php`:
- In `save_settings()`, detect interval change → clear old schedule + reschedule with new interval

Deactivation cleanup — `mhm-currency-switcher.php`:
- Add `wp_clear_scheduled_hook('mhm_cs_update_rates')` to deactivation hook

Supported intervals:
- `hourly` — every hour (WP built-in)
- `twicedaily` — every 12 hours (WP built-in)
- `daily` — every 24 hours (WP built-in)
- `manual` — no cron, manual sync only (default)

**Files:**
- Modify: `src/Plugin.php`
- Modify: `src/Admin/RestAPI.php`
- Modify: `mhm-currency-switcher.php`

---

## Phase 2: Geolocation Currency Detection

### 2.1 GeolocationService

**Purpose:** Detect visitor's country from IP address using a cascading provider chain.

**Detection chain (priority order):**
1. **CloudFlare header** — `$_SERVER['HTTP_CF_IPCOUNTRY']` → zero cost, instant
2. **WooCommerce MaxMind** — `WC_Geolocation::geolocate_ip()` → WC's built-in GeoIP database
3. **Skip** — neither available → return `null`, no geolocation applied

**Class:** `src/Core/GeolocationService.php`
- Public: `detect_country(): ?string` — returns ISO 3166-1 alpha-2 code or null
- Private: `detect_from_cloudflare(): ?string`
- Private: `detect_from_wc_maxmind(): ?string`

### 2.2 CountryCurrencyMap

**Purpose:** Map country codes to default currency codes.

**Class:** `src/Core/CountryCurrencyMap.php`
- Static: `get_currency(string $country_code): ?string`
- Static array: ~60 common countries (TR→TRY, US→USD, DE→EUR, GB→GBP, JP→JPY, etc.)
- Eurozone countries all map to EUR
- Returns `null` if no mapping found (base currency used)

### 2.3 DetectionService Integration

**Modified detection chain:**
1. Cookie (`mhm_cs_currency`)
2. URL parameter (`?currency=XXX`, if enabled)
3. **Geolocation** (new — Pro only, if enabled in settings)
4. Base currency (fallback)

**New private method:** `detect_from_geolocation(): ?string`
- Check `Mode::can_use_geolocation()` — Pro gate
- Check `settings['geolocation_enabled']` — user toggle
- Call `GeolocationService::detect_country()`
- Map country → currency via `CountryCurrencyMap::get_currency()`
- Validate currency is in enabled currencies list
- If valid → set cookie (prevents re-detection on next visit) + return code
- If invalid → return `null`

### 2.4 Admin Panel

- Geolocation toggle already exists in Advanced tab (`geolocation_enabled`)
- Provider select already exists — keep "WooCommerce MaxMind" as label
- Add helper text: "CloudFlare kullanan sitelerde otomatik algılanır. Diğer sitelerde WooCommerce MaxMind GeoIP veritabanı kullanılır."

**Files:**
- Create: `src/Core/GeolocationService.php`
- Create: `src/Core/CountryCurrencyMap.php`
- Modify: `src/Core/DetectionService.php`
- Modify: `src/Plugin.php`

---

## Summary

| # | Item | Type | Phase | Pro/Free | Files |
|---|------|------|-------|----------|-------|
| 1 | expires_at bug fix | Bug fix | 1 | Both | LicenseManager.php |
| 2 | Cron rate updates | Missing feature | 1 | Pro | Plugin.php, RestAPI.php, main file |
| 3 | Geolocation detection | New feature | 2 | Pro | 2 new + 2 modified |

**Not in scope:**
- Checkout UI banner — FormatFilter already converts all prices
- Mini cart conversion — PriceFilter already covers via WC hooks
- Order email conversion — OrderFilter already implemented
- Custom cron intervals — WP built-in intervals sufficient
- Admin geolocation country→currency override UI — static map sufficient for now
