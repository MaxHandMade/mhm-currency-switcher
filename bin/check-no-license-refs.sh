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
check "License namespace" 'MhmCurrencySwitcher.License|License.(LicenseManager|Mode|ClientSecrets|ResponseVerifier|FeatureTokenVerifier|LicenseServerPublicKey|VerifyEndpoint)' src/
check "Mode gates"        'Mode::' src/
check "Quota"             'enforce_limit|free_limit|currency_limit' src/
check "Dev bypass"        'MHM_CS_DEV_PRO|MHMCS_DEV_PRO' src/ admin-app/src/
check "Pro UI"            'ProGate|isPro|is_pro' src/ admin-app/src/

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
