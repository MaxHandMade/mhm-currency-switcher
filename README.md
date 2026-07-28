# MHM Currency Switcher

Multi-currency support for WooCommerce with real-time exchange rates and seamless checkout integration.

## Features

- **Real-time exchange rates** — automatic fetching from ExchangeRate-API, with a jsDelivr (Fawaz Ahmed currency API) fallback
- **Cookie-based currency switching** — visitors select their preferred currency, persisted for 30 days
- **Full WooCommerce integration** — product prices, cart, shipping, coupons, and orders all converted
- **React admin panel** — manage currencies, display options, and advanced settings
- **Shortcodes** — `[mhm_currency_switcher]` dropdown and `[mhm_currency_prices]` product price display
- **Elementor widgets** — Currency Switcher and Price Display widgets for page builder
- **WP-CLI support** — sync rates, manage currencies, flush cache from the command line
- **Navigation menu** — add currency switcher directly to any WordPress nav menu
- **Flag icons** — high-quality SVG country flags for 283 countries
- **Turkish translation** — full admin panel and frontend localization

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
