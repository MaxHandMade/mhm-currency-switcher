#!/usr/bin/env bash
#
# Bring the mhmcs-dev stack up and seed it into a state where the currency
# switcher is immediately observable in a browser: WordPress installed,
# WooCommerce active, products of every price shape the plugin has to handle,
# and two extra currencies configured.
#
# Idempotent: safe to re-run. Re-running does not duplicate products.
#
# Usage:
#   docker/seed.sh
#
# Then open http://currency.localhost (or http://localhost:8113) and log in as
# `admin` with the password this script prints in its closing summary. That
# value comes from WP_ADMIN_PASS in docker/.env (gitignored); the script
# generates and records one there the first time if the key is missing.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

# .env is gitignored (it carries local credentials), so a fresh clone has
# only .env.example. Bootstrap it rather than failing on an unset ${WP_PORT}.
if [ ! -f .env ]; then
	cp .env.example .env
	echo "Created docker/.env from .env.example"
fi

# shellcheck disable=SC1091
. ./.env

CYAN="\033[0;36m"
GREEN="\033[0;32m"
RESET="\033[0m"

SITE_URL="http://localhost:${WP_PORT:-8113}"
ADMIN_USER="admin"
ADMIN_EMAIL="admin@localhost.test"

# The admin password lives in .env (gitignored), never in a tracked file — the
# repository is public, and a credential-shaped literal here is a finding for
# the secret gate whether or not the value is a real secret. With no value set,
# one is generated for this stack and printed in the summary at the end of this
# script, so a fresh clone still gets a working login without the repository
# carrying one.
ADMIN_PASS="${WP_ADMIN_PASS:-}"
if [ -z "$ADMIN_PASS" ]; then
	ADMIN_PASS="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | cut -c1-16)"
	echo "WP_ADMIN_PASS=${ADMIN_PASS}" >>.env
	echo "Generated an admin password and recorded it in docker/.env"
fi

wp() {
	docker compose exec -T wpcli wp --allow-root "$@"
}

echo -e "${CYAN}[1/7] Starting containers...${RESET}"
docker compose up -d

# Wait for the DATABASE, not for WP-CLI. An earlier version polled
# `wp core version`, which reads a file and never touches MySQL — so it
# succeeded immediately, broke the loop, and the install then died with
# "Error establishing a database connection". Ask the thing we actually
# need to be ready.
echo -e "${CYAN}    waiting for the database to accept connections...${RESET}"

# No fallback literal here: the value belongs in .env, which this script
# bootstraps from .env.example above, so an unset key means that file was
# edited wrongly. Say so instead of pinging with a guessed password and
# reporting "database never came up" ninety attempts later.
if [ -z "${MYSQL_ROOT_PASSWORD:-}" ]; then
	echo "MYSQL_ROOT_PASSWORD must be set in docker/.env" >&2
	exit 1
fi

db_ready=0
for _ in $(seq 1 90); do
	if docker compose exec -T db mysqladmin ping -uroot -p"${MYSQL_ROOT_PASSWORD}" --silent >/dev/null 2>&1; then
		db_ready=1
		break
	fi
	sleep 2
done

if [ "$db_ready" -ne 1 ]; then
	echo "Database never became ready. Logs:"
	docker compose logs --tail 30 db
	exit 1
fi

# MySQL answering the socket is not the same as WordPress being able to
# connect with the app credentials; give the grant a moment to settle.
for _ in $(seq 1 30); do
	if wp db check >/dev/null 2>&1; then
		break
	fi
	sleep 2
done

echo -e "${CYAN}[2/7] Installing WordPress (if needed)...${RESET}"
if ! wp core is-installed >/dev/null 2>&1; then
	wp core install \
		--url="$SITE_URL" \
		--title="MHM Currency Switcher Dev" \
		--admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASS" \
		--admin_email="$ADMIN_EMAIL" \
		--skip-email
else
	echo "    already installed."
fi

echo -e "${CYAN}[3/7] Installing + activating WooCommerce...${RESET}"
wp plugin is-installed woocommerce >/dev/null 2>&1 || wp plugin install woocommerce
wp plugin activate woocommerce

echo -e "${CYAN}[4/7] Activating MHM Currency Switcher...${RESET}"
wp plugin activate mhm-currency-switcher

# WooCommerce 10.x ships "coming soon" mode ON for fresh installs, which
# hides the entire storefront behind a placeholder. Without this the site
# looks broken for browser verification: no prices, no switcher, nothing to
# look at — which is the one thing this stack exists to provide.
wp option update woocommerce_coming_soon no
wp option update woocommerce_store_pages_only no

echo -e "${CYAN}[5/7] Seeding products (one of each price shape)...${RESET}"
# Guard on a marker option rather than counting products, so re-running after
# manual edits does not silently re-seed.
if [ "$(wp option get mhmcs_dev_seeded 2>/dev/null || echo '')" != "1" ]; then
	wp wc product create --name="Simple 40" --type=simple --regular_price=40 --user="$ADMIN_USER"
	wp wc product create --name="On Sale 40/30" --type=simple --regular_price=40 --sale_price=30 --user="$ADMIN_USER"
	wp wc product create --name="Free Sample" --type=simple --regular_price=0 --user="$ADMIN_USER"
	wp option update mhmcs_dev_seeded 1
else
	echo "    already seeded (option mhmcs_dev_seeded=1)."
fi

echo -e "${CYAN}[5b/7] Creating a page that renders the switcher...${RESET}"
# Without this there is nowhere on the front end to actually see the
# switcher: it is a shortcode/nav-menu/widget component, and a fresh install
# places it nowhere. One page with both shortcodes gives a single URL for
# browser verification.
if ! wp post list --post_type=page --name=currency-test --format=count 2>/dev/null | grep -q '^1$'; then
	wp post create \
		--post_type=page \
		--post_title="Currency Test" \
		--post_name=currency-test \
		--post_status=publish \
		--post_content='<!-- wp:shortcode -->[mhmcs_currency_switcher]<!-- /wp:shortcode --><!-- wp:shortcode -->[mhmcs_currency_prices]<!-- /wp:shortcode -->'
else
	echo "    page already exists."
fi

echo -e "${CYAN}[5c/7] Seeding a variable product (variation price shape)...${RESET}"
# Guarded on the product itself rather than on the mhmcs_dev_seeded marker: a
# stack seeded before this product existed would otherwise never receive it,
# and the forced-AJAX variation path cannot be checked in a browser without a
# variable product to select a variation on.
#
# Built through the CRUD classes rather than `wp wc product create`, because a
# variable product is really four objects — the parent, its "used for
# variations" attribute, and one variation per option — and the WC CLI has no
# single call that wires an attribute to its variations.
if ! wp post list --post_type=product --name=variable-tee --format=count 2>/dev/null | grep -q '^1$'; then
	wp eval '
$attribute = new WC_Product_Attribute();
$attribute->set_id( 0 );
$attribute->set_name( "Size" );
$attribute->set_options( array( "Small", "Medium", "Large" ) );
$attribute->set_position( 0 );
$attribute->set_visible( true );
$attribute->set_variation( true );

$product = new WC_Product_Variable();
$product->set_name( "Variable Tee" );
$product->set_slug( "variable-tee" );
$product->set_attributes( array( $attribute ) );
$product->set_status( "publish" );
$product_id = $product->save();

foreach ( array( "Small" => 20, "Medium" => 25, "Large" => 30 ) as $size => $price ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $product_id );
	$variation->set_attributes( array( "size" => $size ) );
	$variation->set_regular_price( (string) $price );
	$variation->set_status( "publish" );
	$variation->save();
}

WC_Product_Variable::sync( $product_id );
echo "variable product seeded (id {$product_id}, 3 variations at 20/25/30)\n";
'
else
	echo "    variable product already exists."
fi

echo -e "${CYAN}[6/7] Configuring currencies (base USD + EUR/TRY)...${RESET}"
wp option update woocommerce_currency USD
wp eval '
$data = array(
	"base_currency" => "USD",
	"currencies"    => array(
		array(
			"code" => "EUR", "enabled" => true, "sort_order" => 0,
			"rate" => array( "type" => "manual", "value" => 0.92 ),
			"fee" => array( "type" => "none", "value" => 0 ),
			"rounding" => array( "type" => "disabled", "value" => 0, "subtract" => 0 ),
			"format" => array( "symbol" => "€", "position" => "left", "thousand_sep" => ".", "decimal_sep" => ",", "decimals" => 2 ),
		),
		array(
			"code" => "TRY", "enabled" => true, "sort_order" => 1,
			"rate" => array( "type" => "manual", "value" => 34.5 ),
			"fee" => array( "type" => "percentage", "value" => 2 ),
			"rounding" => array( "type" => "nearest", "value" => 1, "subtract" => 0.01 ),
			"format" => array( "symbol" => "₺", "position" => "right_space", "thousand_sep" => ".", "decimal_sep" => ",", "decimals" => 2 ),
		),
	),
);
update_option( "mhmcs_currencies", $data );
echo "currencies seeded\n";
'

echo -e "${CYAN}[7/7] Configuring tax, shipping and a cart fee...${RESET}"

# Every amount below is chosen so that ROUNDING VISIBLY MOVES IT in TRY, which
# is the only seeded currency with rounding switched on (nearest 1, subtract
# 0.01) and a fee (2%), giving an effective rate of 35.19:
#
#   shipping 10.00 -> 351.90 -> rounds to 351.99   (unrounded would read 351,90)
#   cart fee  5.00 -> 175.95 -> rounds to 175.99   (unrounded would read 175,95)
#   ship tax  1.00 ->  35.19, NOT rounded          (a derived amount; see
#                                                   ShippingFilter)
#
# An amount that rounded to itself would let an unrounded surface pass the
# browser round unnoticed -- which is exactly how the shipping, fee and coupon
# surfaces went unrounded for as long as they did.
wp option update woocommerce_calc_taxes yes
wp option update woocommerce_prices_include_tax no
wp option update woocommerce_shipping_tax_class ''

wp eval '
global $wpdb;

if ( ! $wpdb->get_var( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates LIMIT 1" ) ) {
	WC_Tax::_insert_tax_rate(
		array(
			"tax_rate_country"  => "",
			"tax_rate"          => "10.0000",
			"tax_rate_name"     => "Test Tax",
			"tax_rate_shipping" => 1,
			"tax_rate_order"    => 0,
			"tax_rate_class"    => "",
		)
	);
	echo "tax rate seeded (10%, applies to shipping)\n";
} else {
	echo "tax rate already exists.\n";
}

$zone = new WC_Shipping_Zone( 0 );

if ( ! $zone->get_shipping_methods() ) {
	$instance_id = $zone->add_shipping_method( "flat_rate" );
	update_option(
		"woocommerce_flat_rate_{$instance_id}_settings",
		array( "title" => "Flat rate", "tax_status" => "taxable", "cost" => "10.00" )
	);
	echo "flat rate shipping seeded (10.00 in the base currency, taxable)\n";
} else {
	echo "shipping method already exists.\n";
}
'

# A cart fee has no admin UI in WooCommerce -- it only exists when code adds
# one -- so the stack grows a tiny mu-plugin rather than leaving CartFilter's
# conversion path unreachable in the browser.
#
# Written by piping a heredoc straight into the container. Generating PHP from
# INSIDE `wp eval` was the obvious route and it is a trap: the source passes
# through bash quoting, then WP-CLI's own argument handling, then PHP's parser,
# and a `$` that survives three of those but not the fourth writes a file that
# fatals the whole site on the next request. MSYS_NO_PATHCONV stops Git Bash
# rewriting the container path into a Windows one.
MU_PLUGIN=/var/www/html/wp-content/mu-plugins/mhmcs-dev-cart-fee.php

if ! MSYS_NO_PATHCONV=1 docker compose exec -T wpcli test -f "$MU_PLUGIN"; then
	MSYS_NO_PATHCONV=1 docker compose exec -T wpcli mkdir -p /var/www/html/wp-content/mu-plugins
	MSYS_NO_PATHCONV=1 docker compose exec -T wpcli tee "$MU_PLUGIN" >/dev/null <<'MUPLUGIN'
<?php
/**
 * Plugin Name: MHMCS dev — cart fee
 *
 * Development stack only, never shipped: docker/ is in .distignore. WooCommerce
 * has no admin UI for cart fees, so without this CartFilter's conversion path
 * cannot be reached in a browser at all.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'woocommerce_cart_calculate_fees',
	static function ( $cart ) {
		$cart->add_fee( 'Handling', 5.00, true );
	}
);
MUPLUGIN
	echo "    cart fee mu-plugin written (5.00 in the base currency)"
else
	echo "    cart fee mu-plugin already exists."
fi

# A file that fatals leaves the site unreachable and the failure looks like the
# stack is broken rather than the seed. Check it here, while the cause is still
# one step away.
if ! wp option get siteurl >/dev/null 2>&1; then
	echo "Seed wrote a mu-plugin the site cannot load; removing it again."
	MSYS_NO_PATHCONV=1 docker compose exec -T wpcli rm -f "$MU_PLUGIN"
	exit 1
fi

wp cache flush

echo
echo -e "${GREEN}Ready.${RESET}"
echo "  Site       : http://currency.localhost  (fallback ${SITE_URL})"
echo "  Admin      : ${SITE_URL}/wp-admin  —  ${ADMIN_USER} / ${ADMIN_PASS}"
echo "  Switcher   : WooCommerce > MHM Currency"
echo "  phpMyAdmin : http://localhost:${PMA_PORT:-8114}"
echo "  Mailpit    : http://localhost:${MAILPIT_PORT:-8040}"
echo
echo "  TRY is seeded with a 2% percentage fee and nearest-1 rounding on"
echo "  purpose: those are the two settings most recently repaired, so the"
echo "  seed exercises them rather than leaving them at inert defaults."
