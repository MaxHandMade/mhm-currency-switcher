#!/usr/bin/env bash
#
# One-command integration test runner. Spins up disposable, uniquely-named
# MySQL + PHP containers, mounts the repo, installs the WP PHPUnit test
# library + WordPress + WooCommerce inside the PHP container, runs
# `composer test:integration`, then tears every container/network it
# created back down -- even on Ctrl-C or a mid-run failure.
#
# Exists because the host (Windows + Git Bash, in this repo's case) has
# neither a local MySQL nor a PHP build with mysqli, and several unrelated
# WordPress Docker stacks are already running that must not be touched.
#
# Usage:
#   bin/test-integration-docker.sh [wp-version]
#
# Env overrides:
#   PHP_VERSION   PHP image tag to test against (default: 7.4, the
#                 plugin's "Requires PHP" floor -- see mhm-currency-switcher.php)
#   WC_VERSION    WooCommerce version to install (default: latest)
#
# Requires: Docker only.

set -euo pipefail

RED="\033[0;31m"
GREEN="\033[0;32m"
YELLOW="\033[0;33m"
CYAN="\033[0;36m"
RESET="\033[0m"

WP_VERSION=${1:-latest}
PHP_VERSION=${PHP_VERSION:-7.4}
WC_VERSION=${WC_VERSION:-latest}

# --- Resolve the repo root as a path Docker Desktop can bind-mount -------
# On Git Bash / MSYS, `pwd -W` gives the Windows-native form (C:/foo/bar),
# which Docker Desktop accepts directly. On real Linux/macOS bash, `pwd -W`
# doesn't exist, so fall back to a plain POSIX pwd there.
case "$(uname -s)" in
	MINGW* | MSYS* | CYGWIN*)
		REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -W)"
		;;
	*)
		REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
		;;
esac

# --- Disposable, uniquely-named resources ---------------------------------
# Prefixed so they can never collide with the always-on WP stacks already
# running on this Docker daemon (rentiva-dev, wpalemi, maxhandmade, ...).
RUN_ID="mhmcs-inttest"
NETWORK_NAME="${RUN_ID}-net"
DB_CONTAINER="${RUN_ID}-mysql"
PHP_CONTAINER="${RUN_ID}-php"

DB_NAME="wordpress_test"
DB_USER="root"
DB_PASS="root"
DB_HOST="$DB_CONTAINER"

# Where the WP PHPUnit test library and a scratch WP core checkout go
# *inside the PHP container*. tests/bootstrap-integration.php falls back to
# sys_get_temp_dir() . '/wordpress-tests-lib' when WP_TESTS_DIR is unset;
# we pass it explicitly so the alignment between this script and the
# bootstrap's fallback is a deliberate choice, not an accident of both
# sides independently defaulting to /tmp.
CONTAINER_WP_TESTS_DIR="/tmp/wordpress-tests-lib"
CONTAINER_WP_CORE_DIR="/tmp/wordpress-core"
CONTAINER_JUNIT_PATH="/tmp/mhmcs-integration-junit.xml"

cleanup() {
	local exit_code=$?
	echo -e "${CYAN}[cleanup] removing disposable containers + network...${RESET}"
	MSYS_NO_PATHCONV=1 docker rm -f "$PHP_CONTAINER" "$DB_CONTAINER" >/dev/null 2>&1 || true
	docker network rm "$NETWORK_NAME" >/dev/null 2>&1 || true
	exit "$exit_code"
}

# --- Docker availability --------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
	echo -e "${RED}Error: Docker is not installed or not on PATH.${RESET}"
	echo "This runner needs Docker to provide MySQL + PHP with mysqli, since"
	echo "the host has neither. Install Docker Desktop and try again."
	exit 1
fi

if ! docker info >/dev/null 2>&1; then
	echo -e "${RED}Error: Docker is installed but the daemon is not reachable.${RESET}"
	echo "Start Docker Desktop and try again."
	exit 1
fi

# From here on, anything we create must be cleaned up on exit, including
# Ctrl-C and a failing step under set -e.
trap cleanup EXIT

# --- Defensive pre-cleanup (in case a previous run was killed) -----------
MSYS_NO_PATHCONV=1 docker rm -f "$PHP_CONTAINER" "$DB_CONTAINER" >/dev/null 2>&1 || true
docker network rm "$NETWORK_NAME" >/dev/null 2>&1 || true

echo -e "${CYAN}[1/5] Creating disposable network ${NETWORK_NAME}...${RESET}"
docker network create "$NETWORK_NAME" >/dev/null

# MariaDB, not MySQL 8. The Debian package the PHP container installs as
# `default-mysql-client` IS the MariaDB client, and MySQL 8 enforces TLS by
# default, so that pairing dies with "Certificate verification failure: the
# certificate is NOT trusted". Matching the server to the client sidesteps
# the whole TLS dance; WordPress supports MariaDB as a first-class database.
echo -e "${CYAN}[2/5] Starting throwaway MariaDB (${DB_CONTAINER})...${RESET}"
docker run -d \
	--name "$DB_CONTAINER" \
	--network "$NETWORK_NAME" \
	-e MARIADB_ROOT_PASSWORD="$DB_PASS" \
	mariadb:11 >/dev/null

echo -e "${CYAN}    waiting for the database to accept connections...${RESET}"
mysql_ready=0
for _ in $(seq 1 60); do
	if docker exec "$DB_CONTAINER" mariadb-admin ping -uroot -p"$DB_PASS" --silent >/dev/null 2>&1; then
		mysql_ready=1
		break
	fi
	sleep 1
done
if [ "$mysql_ready" -ne 1 ]; then
	echo -e "${RED}Error: the database did not become ready in time.${RESET}"
	docker logs "$DB_CONTAINER" || true
	exit 1
fi
echo -e "${GREEN}    Database is up.${RESET}"

echo -e "${CYAN}[3/5] Starting PHP ${PHP_VERSION} container (${PHP_CONTAINER}) with the repo mounted...${RESET}"
MSYS_NO_PATHCONV=1 docker run -d \
	--name "$PHP_CONTAINER" \
	--network "$NETWORK_NAME" \
	-v "${REPO_DIR}:/app" \
	-w /app \
	-e WP_TESTS_DIR="$CONTAINER_WP_TESTS_DIR" \
	-e WP_CORE_DIR="$CONTAINER_WP_CORE_DIR" \
	-e WC_VERSION="$WC_VERSION" \
	"php:${PHP_VERSION}-cli" \
	sleep infinity >/dev/null

echo -e "${CYAN}[4/5] Installing PHP extensions + Composer inside the container...${RESET}"
MSYS_NO_PATHCONV=1 docker exec "$PHP_CONTAINER" bash -c '
	set -euo pipefail
	apt-get update -qq
	apt-get install -y -qq --no-install-recommends \
		default-mysql-client curl unzip ca-certificates \
		libzip-dev libpng-dev libjpeg-dev libfreetype6-dev \
		libcurl4-openssl-dev libicu-dev libonig-dev >/dev/null
	# Only build what the image is actually missing. The official PHP images
	# already ship curl/mbstring (and sometimes more), and rebuilding those
	# costs minutes per run for no gain.
	missing=""
	for ext in mysqli zip gd mbstring curl intl; do
		if ! php -m | grep -qix "$ext"; then
			missing="$missing $ext"
		fi
	done

	if [ -n "$missing" ]; then
		case "$missing" in
			*gd*) docker-php-ext-configure gd --with-freetype --with-jpeg >/dev/null ;;
		esac
		# shellcheck disable=SC2086 -- word splitting is intended here.
		docker-php-ext-install -j"$(nproc)" $missing >/dev/null
	fi
	php -r "copy(\"https://getcomposer.org/installer\", \"/tmp/composer-setup.php\");"
	php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
	rm /tmp/composer-setup.php
'

echo -e "${CYAN}[5/5] Installing WP test library + WordPress + WooCommerce (WP=${WP_VERSION}, WC=${WC_VERSION})...${RESET}"
MSYS_NO_PATHCONV=1 docker exec "$PHP_CONTAINER" bash bin/install-wp-tests.sh "$DB_NAME" "$DB_USER" "$DB_PASS" "$DB_HOST" "$WP_VERSION"

echo -e "${CYAN}Running composer test:integration...${RESET}"
set +e
MSYS_NO_PATHCONV=1 docker exec "$PHP_CONTAINER" composer test:integration -- --log-junit "$CONTAINER_JUNIT_PATH"
PHPUNIT_EXIT=$?
set -e

TESTS_RUN=""
TESTS_RUN=$(MSYS_NO_PATHCONV=1 docker exec "$PHP_CONTAINER" bash -c "grep -o 'tests=\"[0-9]*\"' '$CONTAINER_JUNIT_PATH' 2>/dev/null | head -1 | grep -o '[0-9]*'" 2>/dev/null) || true

if [ -z "$TESTS_RUN" ]; then
	echo -e "${RED}Error: could not determine how many tests ran (no JUnit report produced).${RESET}"
	echo "PHPUnit exit code was: $PHPUNIT_EXIT"
	exit 1
fi

if [ "$TESTS_RUN" -eq 0 ]; then
	echo -e "${RED}============================================================${RESET}"
	echo -e "${RED}  WARNING: the integration harness ran ZERO tests.${RESET}"
	echo -e "${RED}  tests/Integration/ has no *Test.php files (yet) -- a green${RESET}"
	echo -e "${RED}  PHPUnit exit code here means nothing was executed, not that${RESET}"
	echo -e "${RED}  anything passed. Treated as a FAILURE, not a silent pass.${RESET}"
	echo -e "${RED}============================================================${RESET}"
	exit 1
fi

if [ "$PHPUNIT_EXIT" -ne 0 ]; then
	echo -e "${RED}Integration tests FAILED (${TESTS_RUN} test(s) ran).${RESET}"
	exit "$PHPUNIT_EXIT"
fi

echo -e "${GREEN}Integration tests PASSED (${TESTS_RUN} test(s) ran).${RESET}"
