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
# Then open http://currency.localhost (or http://localhost:8113) and log in
# with admin / test1234.

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
ADMIN_PASS="test1234"
ADMIN_EMAIL="admin@localhost.test"

wp() {
	docker compose exec -T wpcli wp --allow-root "$@"
}

echo -e "${CYAN}[1/6] Starting containers...${RESET}"
docker compose up -d

# Wait for the DATABASE, not for WP-CLI. An earlier version polled
# `wp core version`, which reads a file and never touches MySQL — so it
# succeeded immediately, broke the loop, and the install then died with
# "Error establishing a database connection". Ask the thing we actually
# need to be ready.
echo -e "${CYAN}    waiting for the database to accept connections...${RESET}"
db_ready=0
for _ in $(seq 1 90); do
	if docker compose exec -T db mysqladmin ping -uroot -p"${MYSQL_ROOT_PASSWORD:-root}" --silent >/dev/null 2>&1; then
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

echo -e "${CYAN}[2/6] Installing WordPress (if needed)...${RESET}"
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

echo -e "${CYAN}[3/6] Installing + activating WooCommerce...${RESET}"
wp plugin is-installed woocommerce >/dev/null 2>&1 || wp plugin install woocommerce
wp plugin activate woocommerce

echo -e "${CYAN}[4/6] Activating MHM Currency Switcher...${RESET}"
wp plugin activate mhm-currency-switcher

# WooCommerce 10.x ships "coming soon" mode ON for fresh installs, which
# hides the entire storefront behind a placeholder. Without this the site
# looks broken for browser verification: no prices, no switcher, nothing to
# look at — which is the one thing this stack exists to provide.
wp option update woocommerce_coming_soon no
wp option update woocommerce_store_pages_only no

echo -e "${CYAN}[5/6] Seeding products (one of each price shape)...${RESET}"
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

echo -e "${CYAN}[5b/6] Creating a page that renders the switcher...${RESET}"
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
		--post_content='<!-- wp:shortcode -->[mhm_currency_switcher]<!-- /wp:shortcode --><!-- wp:shortcode -->[mhm_currency_prices]<!-- /wp:shortcode -->'
else
	echo "    page already exists."
fi

echo -e "${CYAN}[6/6] Configuring currencies (base USD + EUR/TRY)...${RESET}"
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
