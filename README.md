# MHM Currency Switcher

Multi-currency support for WooCommerce with real-time exchange rates and seamless checkout integration.

## Features

- **Real-time exchange rates** — automatic fetching from ExchangeRate-API, with a jsDelivr (Fawaz Ahmed currency API) fallback
- **Cache compatibility mode** — catalogue pages are cached in the base currency and converted in the browser, so a page cache cannot serve one visitor's currency to everybody
- **Cookie-based currency switching** — visitors select their preferred currency, persisted for 30 days
- **Full WooCommerce integration** — product prices, cart, shipping, coupons, and orders all converted
- **React admin panel** — manage currencies, display options, and advanced settings
- **Shortcodes** — `[mhm_currency_switcher]` dropdown and `[mhm_currency_prices]` product price display
- **Elementor widgets** — Currency Switcher and Price Display widgets for page builder
- **WP-CLI support** — sync rates, manage currencies, flush cache from the command line
- **Navigation menu** — add currency switcher directly to any WordPress nav menu
- **Flag icons** — high-quality SVG country flags for 283 countries
- **Turkish translation** — full admin panel and frontend localization

## Cache compatibility mode

On by default, under **WooCommerce > MHM Currency > Advanced**.

A page cache stores the HTML produced for whoever asked first, so server-side
conversion means the first visitor's currency reaches every later visitor. With
the mode on, anonymous shop, archive and product pages render in the base
currency and the browser converts the displayed prices through
`POST /wp-json/mhmcs/v1/convert`. Displayed prices are converted in the browser;
cart, checkout, order totals, order emails and the WooCommerce REST API are
always converted on the server, so the amount charged cannot be changed from the
browser.

### Accepted limits

Full explanations are in [readme.txt](readme.txt) under "Known limits". In short:

- **Off ≠ 1.0.0 exactly.** Three fixes sit above the setting and stay either way:
  no conversion on admin screens or admin AJAX, `wc/v3` reads pinned to the base
  currency, no conversion under cron/WP-CLI.
- **Crawlers see base prices**, including the structured product data.
- **Base prices are visible from first paint** and are swapped for the converted
  ones when the request returns, each fading over 200ms; `prefers-reduced-motion`
  gets the swap without the fade.
- **No JavaScript, or an unreachable endpoint** → base prices stay, reason goes to
  the browser console, nothing visible breaks.
- **Variable products** are forced onto WooCommerce's AJAX variation path, so
  `data-product_variations` is `false` and third-party swatch plugins that read
  prices out of that JSON may stop showing one.
- **The mini-cart** renders in base and is corrected by WooCommerce's cart
  fragment refresh; if fragments are dequeued the cached mini-cart stays in base.
  The plugin detects that case and warns in the admin.
- **A cart or checkout outside the pages WooCommerce assigned** must be excluded
  from the cache yourself.
- **`?currency=` multiplies cache entries.** The switcher does not generate such
  URLs — it sets a cookie and converts in place without reloading.
- **Logged-in visitors** convert server-side, which assumes your cache bypasses
  them; verify that if you cache at the edge.

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

## Installation

1. Upload the `mhm-currency-switcher` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **WooCommerce > MHM Currency** to configure currencies and exchange rates

## Shortcodes

### Currency Switcher Dropdown

```
[mhm_currency_switcher size="medium"]
```

**Attributes:**
| Attribute | Values | Default | Description |
|-----------|--------|---------|-------------|
| `size` | `small`, `medium`, `large` | `medium` | Dropdown size |

### Multi-Currency Price Display

```
[mhm_currency_prices currencies="USD,EUR,GBP"]
```

**Attributes:**
| Attribute | Example | Description |
|-----------|---------|-------------|
| `currencies` | `USD,EUR,GBP` | Comma-separated currency codes |
| `product_id` | `123` | Specific product ID (optional) |
| `price` | `29.99` | Override price value (optional) |
| `show_flags` | `true` / `false` | Show flag icons (optional, defaults to the saved Display Options setting) |

## WP-CLI Commands

```bash
wp mhm-cs rates-sync          # Sync exchange rates
wp mhm-cs rates-get EUR       # Get rate for a currency
wp mhm-cs cache-flush         # Flush rate cache
wp mhm-cs currencies-list     # List configured currencies
wp mhm-cs status              # Plugin status overview
```

## Development

### Prerequisites

- Composer
- Node.js 18+
- Docker (for integration tests)

### Setup

```bash
composer install
cd admin-app && npm install && npm run build
```

### Testing

```bash
composer test              # PHPUnit unit tests
composer lint              # Code style (PHPCS)
composer analyze           # Static analysis (PHPStan)
npm run test:js            # Front-end script tests (Jest + jsdom)
```

Unit tests (`composer test`) have no external dependencies and run anywhere.
`npm run test:js` covers `assets/js/`, which no PHP gate can see.

Integration tests run against a real WordPress + WooCommerce install. The
one-command Docker runner needs nothing but Docker and matches what CI does:

```bash
bin/test-integration-docker.sh                                    # WP latest
PHP_VERSION=8.1 WC_VERSION=8.7.0 bin/test-integration-docker.sh 6.4
```

It tests one WordPress/WooCommerce pair per run; CI runs three
(PHP 7.4/WP 6.0/WC 7.4.0, PHP 8.1/WP 6.4/WC 8.7.0, PHP 8.2/WP latest/WC latest).

If you would rather use a local MySQL and the WP PHPUnit test library directly:

```bash
bin/install-wp-tests.sh wordpress_test root '' localhost latest   # once, needs MySQL
composer test:integration
```

### Build Admin App

```bash
cd admin-app
npm run build
```

## License

GPLv2 or later. See [LICENSE](LICENSE) for details.

## Author

[MaxHandMade](https://maxhandmade.com)
