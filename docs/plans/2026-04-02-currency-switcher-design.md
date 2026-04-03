# MHM Currency Switcher — Design Document

**Date:** 2026-04-02
**Status:** Approved
**Author:** MaxHandMade + Claude

## 1. Overview

WooCommerce multi-currency switcher plugin. Visitors can view prices and checkout in their preferred currency. Product-level quality — personal use + commercial sale via mhm-license-server.

**Plugin Name:** MHM Currency Switcher
**Slug:** `mhm-currency-switcher`
**Namespace:** `MhmCurrencySwitcher`
**Project Path:** `C:\projects\mhm-currency-switcher`
**GitHub:** `MaxHandMade/mhm-currency-switcher` — **PRIVATE** (ürünleştirmede public yapılacak)

## 2. Architecture Decision

**Approach B — Options API + Service Layer** (chosen over CPT-based or custom table approaches).

- Single `wp_option` JSON for currency data (1 DB query vs N+1 with CPT)
- Service classes for conversion, rate fetching, detection
- Modular WooCommerce hook integration (separate files per responsibility)
- Lazy-loaded compatibility modules
- ~35-40 PHP files (vs yaycurrency's 112)
- WPCS compliant, PSR-4 autoload

## 3. Free vs Pro Feature Split

| Feature | Free | Pro |
|---------|------|-----|
| Currencies | 3 total (base + 2) | Unlimited |
| Auto exchange rate (one-time fetch) | Yes | Yes |
| Scheduled rate auto-update (cron) | No | Yes |
| Switcher (shortcode/widget/block/menu) | Yes | Yes |
| Elementor widgets | Yes | Yes |
| Fee & rounding | Yes | Yes |
| Product page price widget (info-only) | Yes | Yes |
| Geolocation (auto currency by country) | No | Yes |
| Fixed price per product | No | Yes |
| Payment method restriction per currency | No | Yes |
| Multilingual mapping (TranslatePress/WPML/Polylang) | No | Yes |
| Advanced compatibility modules | No | Yes |
| Rentiva integration module | No | Yes |
| Cache plugin compatibility | No | Yes |
| WC REST API currency parameter | No | Yes |
| Priority support | No | Yes |

## 4. Data Model

### 4.1 Currency Data — `mhm_currency_switcher_currencies`

Single wp_option, autoload=yes. Typical size: 2-3 KB for 15-20 currencies.

```json
{
  "base_currency": "TRY",
  "currencies": [
    {
      "code": "USD",
      "enabled": true,
      "sort_order": 0,
      "rate": { "type": "auto", "value": 0.029 },
      "fee": { "type": "percentage", "value": 2.5 },
      "rounding": { "type": "nearest", "value": 0.99, "subtract": 0.01 },
      "format": {
        "symbol": "$",
        "position": "left",
        "thousand_sep": ",",
        "decimal_sep": ".",
        "decimals": 2
      },
      "payment_methods": ["all"],
      "countries": ["US", "CA"]
    }
  ]
}
```

### 4.2 Settings — `mhm_currency_switcher_settings`

```json
{
  "switcher": {
    "show_flag": true,
    "show_name": false,
    "show_symbol": true,
    "show_code": true,
    "size": "medium"
  },
  "product_widget": {
    "enabled": true,
    "currencies": ["USD", "EUR", "GBP"],
    "show_flag": true
  },
  "detection": {
    "method": "cookie",
    "cookie_expiry": 30,
    "url_param": false
  },
  "geolocation": {
    "enabled": false,
    "provider": "woocommerce"
  },
  "rate_update": {
    "auto": false,
    "interval": "daily"
  }
}
```

### 4.3 Transient Cache

- `mhm_cs_rates_{base}` — fetched rates, expires per cron interval

### 4.4 Cookies (client-side)

- `mhm_cs_currency` — selected currency code, 30-day expiry

### 4.5 Order Meta

- `_mhm_cs_currency_code` — currency at time of order
- `_mhm_cs_exchange_rate` — rate at time of order
- `_mhm_cs_base_total` — total in base currency
- `_mhm_cs_fee_applied` — fee percentage/amount applied

## 5. Conversion Engine

### 5.1 Formula

```
effective_rate = rate + (rate * fee% / 100)    [percentage fee]
effective_rate = rate + fee                     [fixed fee]
converted      = original_price * effective_rate
```

### 5.2 Rounding

```
nearest → round(converted / rounding_value) * rounding_value - subtract
up      → ceil(converted / rounding_value) * rounding_value - subtract
down    → floor(converted / rounding_value) * rounding_value - subtract
```

### 5.3 Converter Public API

| Method | Description |
|--------|-------------|
| `convert( float $price, string $to )` | Basic conversion |
| `convert_with_rounding( float $price, string $to )` | Conversion + rounding |
| `get_rate( string $code )` | Effective rate (fee included) |
| `get_raw_rate( string $code )` | Raw rate (fee excluded) |
| `revert( float $price, string $from )` | Reverse conversion (checkout → base) |

### 5.4 Safety Rules

- Base currency conversion always returns 1.0 (short-circuit)
- Zero or negative prices are not converted
- Double-conversion protection via cart item meta (`mhm_cs_currency_code`)

## 6. Exchange Rate Provider

### 6.1 Fallback Chain

1. **ExchangeRate-API** (primary) — `api.exchangerate-api.com/v4/latest/{base}`
2. **Fawaz Currency API** (fallback) — `cdn.jsdelivr.net/npm/@fawazahmed0/currency-api`
3. **Manual entry** (always available)

Yahoo Finance API intentionally excluded — undocumented endpoint, unreliable.

### 6.2 Update Strategy

- Free: Manual refresh button in admin (one-time API call)
- Pro: WP-Cron scheduled (configurable: 1h, 6h, 12h, 24h)
- Transient cache: `mhm_cs_rates_{base}` — expires per interval

## 7. WooCommerce Integration

### 7.1 Price Conversion Pipeline

```
Visitor loads page
    → DetectionService::get_current_currency()
        Cookie → URL param → Default
    → PriceFilter hooks (priority 100)
        woocommerce_product_get_price
        woocommerce_product_get_regular_price
        woocommerce_product_get_sale_price
        woocommerce_product_variation_get_price
        woocommerce_variation_prices_price
        woocommerce_variation_prices_regular_price
        woocommerce_variation_prices_sale_price
    → Converter::convert_with_rounding()
    → FormatFilter hooks
        woocommerce_currency
        woocommerce_currency_symbol
        pre_option_woocommerce_currency_pos
        wc_get_price_thousand_separator
        wc_get_price_decimal_separator
        wc_get_price_decimals
```

### 7.2 Modular Hook Files

| File | Responsibility | Key Hooks |
|------|---------------|-----------|
| `PriceFilter.php` | Product prices | `product_get_price`, `variation_prices_*` |
| `CartFilter.php` | Cart + checkout | `cart_calculate_fees`, `cart_totals_*`, `checkout_create_order` |
| `ShippingFilter.php` | Shipping | `package_rates`, formula evaluation |
| `CouponFilter.php` | Coupons | `coupon_get_amount`, `coupon_get_min/max_amount` |
| `OrderFilter.php` | Orders + emails | `order_item_totals`, email currency override |
| `FormatFilter.php` | Currency formatting | `currency_symbol`, separators, decimals |
| `RestApiFilter.php` | WC REST API | `rest_prepare_product_object`, currency param |

### 7.3 Order Flow

```
Checkout completed
    → OrderFilter::on_checkout_create_order()
        → save _mhm_cs_currency_code, _mhm_cs_exchange_rate, _mhm_cs_base_total
    → Email sent
        → OrderFilter::filter_email_currency()
        → reads order meta, displays in order's currency
```

### 7.4 Cache Compatibility

- Variation price hash includes currency ID (per-currency WC cache)
- Transient-based rate caching
- Cookie-based currency detection + page reload (not AJAX) — cache-friendly
- WC Store API (`/wc/store/`) gets conversion; other REST endpoints skip unless `?currency=` param

## 8. Frontend

### 8.1 Currency Switcher — 4 Display Methods

1. **Shortcode:** `[mhm_currency_switcher]`
2. **WordPress Widget:** `MHM_Currency_Switcher_Widget`
3. **Gutenberg Block:** `mhm-cs/currency-switcher`
4. **Nav Menu Item:** auto-inject via `wp_nav_menu_items` filter

### 8.2 Elementor Widgets

| Widget | Description |
|--------|-------------|
| `MHM_CS_Switcher_Widget` | Currency switcher dropdown with style/size/flag controls |
| `MHM_CS_Price_Display_Widget` | Flagged price display — currency selection, layout options |

Located in `src/Integration/Elementor/`, lazy-loaded only when Elementor is active.

### 8.3 Switcher Behavior

Cookie-based + page reload approach (not AJAX).

```
User selects currency
    → Set cookie: mhm_cs_currency = "USD" (30 days)
    → Full page reload
    → PHP reads cookie → all prices converted server-side
```

Reliable, cache-compatible, SEO-friendly. AJAX update can be added as future Pro feature.

### 8.4 Product Widget — Flagged Price Display

Shows converted prices in selected currencies below the main product price:

```
₺1.500,00                          ← Main price (WC default)
─────────────────────────────────
🇺🇸  $43.50  │  🇪🇺  €41.20  │  🇬🇧  £36.80
```

- Admin-configurable: which currencies to show (max 5)
- SVG flags — lightweight, retina-ready (~1-2 KB each)
- Server-side rendered — SEO friendly, no JS dependency
- Hook positions: `woocommerce_single_product_summary` (priority 15) or `woocommerce_after_single_product_summary`
- Shortcode alternative: `[mhm_currency_prices]`
- MVP: info-only display. Future: clickable to switch currency.

## 9. REST API

### 9.1 Admin API — `/wp-json/mhm-currency/v1/`

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/settings` | GET/POST | Read/save all settings |
| `/currencies` | GET/POST | Currency CRUD |
| `/rates/sync` | POST | Manual rate refresh |
| `/rates/preview` | GET | Live rate preview for admin |
| `/rates` | GET | Public rates endpoint (for mobile apps) |

### 9.2 WC REST API Extension

```
GET /wp-json/wc/v3/products?currency=USD
→ Prices returned converted to USD
```

Mobile app support — two approaches:
- **Server-side:** Pass `?currency=` param, get converted prices
- **Client-side:** Fetch `/mhm-currency/v1/rates`, convert locally (faster, offline capable)

## 10. Compatibility Layer

### 10.1 Architecture Pattern

```php
interface CompatibleInterface {
    public static function is_active(): bool;
    public function init(): void;
}
```

Each compatible module:
1. Checks if target plugin is active (`is_active()`)
2. Registers its hooks (`init()`)
3. Applies plugin-specific conversion logic

Modules are lazy-loaded — only initialized when target plugin is detected.

### 10.2 Planned Modules

**MVP:**
- MHM Rentiva (Pro)

**Post-MVP:**
- TranslatePress (Pro) — URL prefix → language-currency mapping
- WPML (Pro) — `wpml_current_language` filter
- Polylang (Pro) — `pll_current_language()` detection
- Dokan (Pro) — vendor commission conversion
- Additional payment gateways as needed

## 11. Admin Panel

### 11.1 Technology

React via `@wordpress/scripts` (wp-element). Mounted on `WooCommerce > MHM Currency` admin page.

### 11.2 Tabs

| Tab | Content | Tier |
|-----|---------|------|
| Manage Currencies | Currency table with drag-drop, rate/fee/rounding, preview | Free |
| Display Options | Switcher config, product widget config | Free |
| Checkout Options | Payment method restrictions, fallback currency | Pro |
| Advanced Settings | Geolocation, auto rate cron, cache compat, multilingual | Pro |
| License | Key input, status, Pro feature list | Free (visible) |

Pro features shown with locked overlay + "Unlock with Pro" CTA.

### 11.3 React Component Structure

```
admin-app/src/
├── index.js
├── App.jsx
├── components/
│   ├── TabNavigation.jsx
│   ├── tabs/
│   │   ├── ManageCurrencies.jsx
│   │   │   ├── CurrencyTable.jsx (@dnd-kit drag-and-drop)
│   │   │   ├── CurrencyRow.jsx
│   │   │   └── AddCurrencyModal.jsx
│   │   ├── DisplayOptions.jsx
│   │   │   ├── SwitcherPreview.jsx
│   │   │   └── ProductWidgetConfig.jsx
│   │   ├── CheckoutOptions.jsx (Pro gate)
│   │   ├── AdvancedSettings.jsx (Pro gate)
│   │   └── License.jsx
│   └── shared/
│       └── ProGate.jsx
└── api/
    └── settings.js
```

## 12. License Integration

Uses existing mhm-license-server infrastructure via mhm-plugin-updater Composer package.

```
composer require maxhandmade/mhm-plugin-updater
```

- `Updater::init()` on `admin_init`
- `LicenseManager::is_pro()` — PHP-side Pro feature gate
- REST API returns `is_pro` flag for React UI gating
- Grace period: 7 days (mhm-license-server standard)
- Weekly instance check-in via cron

## 13. Test & Quality Infrastructure

### 13.1 Tools

| Tool | Scope | Config |
|------|-------|--------|
| PHPUnit | Unit + Integration | `phpunit.xml.dist`, WP_UnitTestCase |
| PHPCS | WPCS compliance | `phpcs.xml.dist` — WordPress-Extra + WordPress-Docs |
| PHPStan | Static analysis | `phpstan.neon` — level 6 |
| WP-CLI | Rate sync, cache flush | Custom commands under `wp mhm-cs` |
| wp-scripts | React build + lint | `@wordpress/scripts` |
| ESLint | JS quality | `@wordpress/eslint-plugin` |

### 13.2 PHPUnit Test Plan

```
tests/
├── Unit/
│   ├── ConverterTest.php
│   ├── CurrencyStoreTest.php
│   ├── RateProviderTest.php
│   └── DetectionServiceTest.php
├── Integration/
│   ├── PriceFilterTest.php
│   ├── CartFilterTest.php
│   ├── OrderFilterTest.php
│   ├── ShippingFilterTest.php
│   ├── CouponFilterTest.php
│   └── SwitcherTest.php
└── bootstrap.php
```

### 13.3 WP-CLI Commands

```bash
wp mhm-cs rates sync              # Sync all exchange rates
wp mhm-cs rates get USD           # Query single rate
wp mhm-cs cache flush             # Clear transient cache
wp mhm-cs currencies list         # List currencies (table format)
wp mhm-cs status                  # License + overall status
```

### 13.4 Composer Scripts

```json
{
  "scripts": {
    "test": "phpunit",
    "lint": "phpcs",
    "analyze": "phpstan analyse",
    "build": "npm run build"
  }
}
```

## 14. File Structure

```
mhm-currency-switcher/
├── mhm-currency-switcher.php
├── composer.json
├── package.json
├── webpack.config.js
├── phpunit.xml.dist
├── phpcs.xml.dist
├── phpstan.neon
├── readme.txt
├── languages/
├── assets/
│   ├── css/
│   │   ├── switcher.css
│   │   └── admin.css
│   ├── js/
│   │   └── switcher.js
│   └── images/
│       └── flags/                     # SVG country flags
├── src/
│   ├── Plugin.php                     # Singleton, hook registration hub
│   ├── Admin/
│   │   ├── Settings.php               # React admin mount point
│   │   └── RestAPI.php                # /mhm-currency/v1/ endpoints
│   ├── Core/
│   │   ├── CurrencyStore.php          # wp_option CRUD (JSON model)
│   │   ├── Converter.php              # Price conversion engine
│   │   ├── RateProvider.php           # Exchange rate API fetcher
│   │   └── DetectionService.php       # Cookie → URL param → default
│   ├── Integration/
│   │   ├── WooCommerce/
│   │   │   ├── PriceFilter.php
│   │   │   ├── CartFilter.php
│   │   │   ├── OrderFilter.php
│   │   │   ├── ShippingFilter.php
│   │   │   ├── CouponFilter.php
│   │   │   ├── FormatFilter.php
│   │   │   └── RestApiFilter.php
│   │   ├── Elementor/
│   │   │   ├── SwitcherWidget.php
│   │   │   └── PriceDisplayWidget.php
│   │   └── Compatibles/
│   │       ├── CompatibleInterface.php
│   │       ├── MhmRentiva.php
│   │       ├── TranslatePress.php
│   │       ├── WPML.php
│   │       └── Polylang.php
│   ├── Frontend/
│   │   ├── Switcher.php               # Shortcode + Widget + Block
│   │   ├── ProductWidget.php          # Flagged price display
│   │   └── Enqueue.php                # Frontend asset loading
│   ├── CLI/
│   │   └── Commands.php               # WP-CLI commands
│   └── License/
│       └── LicenseManager.php         # mhm-plugin-updater integration
├── admin-app/
│   ├── src/
│   │   ├── index.js
│   │   ├── App.jsx
│   │   ├── components/
│   │   └── api/
│   └── build/
└── tests/
    ├── Unit/
    ├── Integration/
    └── bootstrap.php
```

## 15. Technical Requirements

- **PHP:** >= 7.4
- **WordPress:** >= 6.0
- **WooCommerce:** >= 7.0
- **HPOS:** Compatible (declare_compatibility)
- **WC Blocks:** Compatible (Store API filter)
- **Coding Standards:** WPCS (WordPress-Extra + WordPress-Docs)
- **Static Analysis:** PHPStan level 6

## 16. Competitive Positioning

| Aspect | YayCurrency | FOX (WOOCS) | Aelia | MHM CS |
|--------|-------------|-------------|-------|--------|
| Free currencies | 3 | Unlimited | N/A (paid) | 3 |
| Architecture | CPT + 112 files | Session-based | Premium monolith | Options API + ~40 files |
| Admin panel | React | jQuery | PHP templates | React |
| Product price widget | No | No | No | Yes (unique) |
| Elementor widgets | No | Limited | No | Yes |
| Code quality | Mixed | Legacy | Good | WPCS + PHPStan L6 |
| License system | External | External | External | Own (mhm-license-server) |

## 17. Decisions Log

| Decision | Chosen | Alternatives Considered | Rationale |
|----------|--------|------------------------|-----------|
| Data storage | wp_option JSON | CPT, custom table | 1 query vs N+1, simple CRUD |
| Admin panel | React (wp-element) | PHP templates, Vue.js | Interactive UI needs, WP ecosystem alignment |
| Rate API | ExchangeRate-API | Yahoo Finance, Fixer | Free, stable, documented |
| Switcher mechanism | Cookie + page reload | AJAX live update | Cache-compatible, reliable |
| Namespace | MhmCurrencySwitcher | Mhm_Currency_Switcher | PSR-4 standard |
| Flag icons | SVG | PNG, emoji | Lightweight, retina, consistent cross-browser |
| License | mhm-license-server | Freemius, EDD | Own infrastructure, zero dependency |
