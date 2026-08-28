#!/usr/bin/env bash
#
# Verify the WordPress version floor this plugin CLAIMS by actually opening it
# on that version.
#
# WHY THIS EXISTS
#   `Requires at least:` is a factual claim, and the test suite cannot check it.
#   The integration matrix installs WordPress but only ever runs PHP — it never
#   loads an admin page, so anything that breaks in the asset/JS layer passes
#   every gate. That is not hypothetical: the admin bundle depends on the
#   `react-jsx-runtime` script handle, which core only registers from WP 6.6.
#   WordPress drops a script whose dependency is unregistered — silently, no
#   error, no log — so the settings screen was an empty page on WP 6.0–6.5 in
#   every release from 0.3.0 to 1.1.1, while readme.txt advertised 6.0.
#   Measured and fixed 2026-07-29; the floor is now 6.6.
#
#   Run this whenever `Requires at least:` changes, and before any WordPress.org
#   submission.
#
# WHAT IT DOES
#   Brings up a throwaway MySQL + WordPress pair on its own network and port,
#   installs WooCommerce and the given plugin ZIP, and reports whether the
#   admin bundle's dependencies are registered. It touches no existing stack.
#   Tear it down with `down` when you have finished looking at it in a browser.
#
# USAGE
#   bin/verify-wp-floor.sh up   <wp-image> <port> <name> [zip]
#   bin/verify-wp-floor.sh down <name>
#
# EXAMPLE — the floor, and the version below it as a negative control:
#   bin/verify-wp-floor.sh up wordpress:6.6-php8.1-apache 8150 floor-ok
#   bin/verify-wp-floor.sh up wordpress:6.5-php8.1-apache 8151 floor-neg
#   # open both in a browser, compare, then:
#   bin/verify-wp-floor.sh down floor-ok && bin/verify-wp-floor.sh down floor-neg
#
# A pass is NOT "the containers came up". A pass is: you opened
# WooCommerce > MHM Currency in a browser and the panel rendered.
set -euo pipefail

MODE="${1:-}"; shift || true

if [ "$MODE" = "down" ]; then
	NAME="${1:?ad gerekli}"
	docker rm -f "${NAME}-wp" "${NAME}-db" >/dev/null 2>&1 || true
	docker network rm "${NAME}-net" >/dev/null 2>&1 || true
	echo "[floor] $NAME temizlendi"
	exit 0
fi

if [ "$MODE" != "up" ]; then
	sed -n '2,36p' "$0" | sed 's/^# \?//'
	exit 1
fi

WPIMG="${1:?wp imaji gerekli, or. wordpress:6.6-php8.1-apache}"
PORT="${2:?port gerekli}"
NAME="${3:?ad gerekli}"
ZIP="${4:-/c/tmp/plugin-builds/mhm-currency-switcher.2.1.0.zip}"
WC_PIN="${WC_VERSION:-9.1.4}"

[ -f "$ZIP" ] || { echo "[floor] ZIP yok: $ZIP" >&2; exit 1; }

NET="${NAME}-net"; DB="${NAME}-db"; WP="${NAME}-wp"

docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" \
	-e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp \
	mysql:8.0 --default-authentication-plugin=mysql_native_password >/dev/null
docker run -d --name "$WP" --network "$NET" -p "${PORT}:80" \
	-e WORDPRESS_DB_HOST="$DB" -e WORDPRESS_DB_USER=root \
	-e WORDPRESS_DB_PASSWORD=root -e WORDPRESS_DB_NAME=wp \
	-e WORDPRESS_DEBUG=1 "$WPIMG" >/dev/null

for _ in $(seq 1 90); do
	docker exec "$DB" mysqladmin ping -proot --silent >/dev/null 2>&1 && break
	sleep 2
done

docker exec "$WP" sh -c 'curl -sO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x wp-cli.phar && mv wp-cli.phar /usr/local/bin/wp' >/dev/null 2>&1

# Generated, not written down: this container lives for the length of one
# probe, and a credential-shaped literal in a public repository is a finding
# for the secret gate whether or not the value protects anything. Printed
# below, because the whole point of the probe is to open it in a browser.
PROBE_PASS="$(head -c 18 /dev/urandom | base64 | tr -d '/+=' | cut -c1-16)"

for _ in $(seq 1 30); do
	docker exec "$WP" sh -c "wp --allow-root core install --url=http://localhost:${PORT} --title=FloorProbe --admin_user=admin --admin_password=${PROBE_PASS} --admin_email=a@b.test --skip-email" >/dev/null 2>&1 && break
	sleep 3
done

printf 'WordPress        : '; docker exec "$WP" sh -c 'wp --allow-root core version'
docker exec "$WP" sh -c "wp --allow-root plugin install woocommerce --version=${WC_PIN} --activate" >/dev/null 2>&1
printf 'WooCommerce      : '; docker exec "$WP" sh -c 'wp --allow-root plugin get woocommerce --field=version 2>/dev/null || echo KURULMADI'

cat "$ZIP" | docker exec -i "$WP" sh -c 'cat > /tmp/plugin.zip'
docker exec "$WP" sh -c 'wp --allow-root plugin install /tmp/plugin.zip --activate' 2>&1 | grep -iE 'requires|error|warning' || true
printf 'Eklenti          : '; docker exec "$WP" sh -c 'wp --allow-root plugin list --name=mhm-currency-switcher --fields=version,status --format=csv 2>/dev/null | tail -1 || echo KURULAMADI'

# Bundle'in ilan ettigi HER bagimliligi cekirdege karsi dogrula — kusur tek bir
# handle'a ozgu degil, `@wordpress/scripts` bu listeyi sen istemeden buyutur.
printf 'Bagimliliklar    : '
docker exec "$WP" sh -c 'wp --allow-root eval "
\$f = WP_PLUGIN_DIR . \"/mhm-currency-switcher/admin-app/build/index.asset.php\";
if ( ! file_exists( \$f ) ) { echo \"asset dosyasi yok (eklenti kurulmadi mi?)\"; return; }
\$a = include \$f;
wp_scripts();
\$eksik = array();
foreach ( \$a[\"dependencies\"] as \$h ) { if ( ! wp_script_is( \$h, \"registered\" ) ) { \$eksik[] = \$h; } }
echo \$eksik ? \"KAYITSIZ: \" . implode( \", \", \$eksik ) . \"  -> admin paneli BOS gelir\" : \"hepsi kayitli (\" . count( \$a[\"dependencies\"] ) . \")\";
"' 2>/dev/null || echo '?'
echo
echo "Tarayicida ac    : http://localhost:${PORT}/wp-admin/admin.php?page=mhmcs-settings  (admin / ${PROBE_PASS})"
echo "Bitince temizle  : bin/verify-wp-floor.sh down ${NAME}"
