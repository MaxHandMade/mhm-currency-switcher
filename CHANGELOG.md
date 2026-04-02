# Changelog

All notable changes to the MHM Currency Switcher plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
