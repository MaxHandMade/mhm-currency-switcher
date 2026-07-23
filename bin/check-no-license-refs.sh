#!/usr/bin/env bash
#
# WP.org Guideline 5 compliance gate.
#
# Fails when any licence-gating, Pro-tier, or quota surface exists in the
# shipped source. Run in CI on every push.
#
set -uo pipefail

cd "$(dirname "$0")/.." || exit 2

STATUS=0

# Scan surface: the full shipped footprint, not just src/. A licence-gating
# reference in the root bootstrap file, uninstall.php, templates/, or
# readme.txt is just as much a Guideline 5 violation as one in src/ — and
# readme.txt is the first thing a WP.org reviewer reads.
SCAN_PHP="src/ mhm-currency-switcher.php uninstall.php templates/"
SCAN_ALL="src/ mhm-currency-switcher.php uninstall.php templates/ admin-app/src/ readme.txt"

check() {
	local label="$1"
	local pattern="$2"
	shift 2

	local hits
	hits=$(grep -rniE "$pattern" "$@" 2>/dev/null || true)

	if [ -n "$hits" ]; then
		echo "FAIL [$label]"
		echo "$hits"
		echo
		STATUS=1
	else
		echo "ok   [$label]"
	fi
}

# NOTE: namespace separators are backslashes, which do not survive the trip
# through shell quoting into an ERE intact — an earlier version of this check
# used '\\\\' and silently matched nothing at all. Match the separator with '.'
# instead. Over-matching is the safe direction for a compliance gate.
check "License namespace" 'MhmCurrencySwitcher.License|License.(LicenseManager|Mode|ClientSecrets|ResponseVerifier|FeatureTokenVerifier|LicenseServerPublicKey|VerifyEndpoint)' $SCAN_PHP
check "Mode gates"        'Mode::' $SCAN_PHP
check "Quota"             'enforce_limit|free_limit|currency_limit' $SCAN_PHP
check "Dev bypass"        'MHM_CS_DEV_PRO|MHMCS_DEV_PRO' $SCAN_ALL
check "Pro UI"            'ProGate|isPro|is_pro|pro-gate|pro-overlay|upgrade-cta|license-card|License tab|Upgrade CTA' $SCAN_ALL

if [ -d src/License ]; then
	echo "FAIL [src/License directory still exists]"
	STATUS=1
else
	echo "ok   [src/License directory absent]"
fi

if [ "$STATUS" -eq 0 ]; then
	echo
	echo "PASS — no licence-gating surface found."
else
	echo
	echo "FAILED — licence-gating surface detected (WP.org Guideline 5)."
fi

exit "$STATUS"
