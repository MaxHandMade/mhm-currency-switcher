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
composer test              # Run PHPUnit tests
composer phpcs             # Run code style checks
composer phpstan           # Run static analysis
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
