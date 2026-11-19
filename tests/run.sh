#!/usr/bin/env bash
# Runs every Peak SEO test (plugin PHP + brain Python). No WordPress, network or API keys needed.
#
#   tests/run.sh
#   PHP=/path/to/php PHP_ARGS="-d extension=sodium -d extension=openssl" tests/run.sh
#
# PHP needs the sodium and openssl extensions. On Windows with LocalWP, for example:
#   PHP="$APPDATA/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" \
#   PHP_ARGS="-n -d extension_dir=$(cygpath -w "$APPDATA/Local/lightning-services/php-8.2.29+0/bin/win64/ext") -d extension=sodium -d extension=openssl" \
#   tests/run.sh
set -u
cd "$(dirname "$0")/.."
PHP="${PHP:-php}"
PHP_ARGS="${PHP_ARGS:-}"
PY="${PY:-}"
for c in service/.venv/Scripts/python service/.venv/bin/python python3 python; do
	[ -n "$PY" ] && break
	command -v "$c" >/dev/null 2>&1 && PY="$c"
done
INC=peak-seo/includes
fail=0

# A throwaway RSA key for the Search Console JWT tests (never committed).
[ -f tests/php/test_sa_key.pem ] || openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out tests/php/test_sa_key.pem 2>/dev/null

run() {
	local name=$1; shift
	local out last
	out=$("$@" 2>&1)
	last=$(printf '%s\n' "$out" | tail -1)
	printf '%-18s %s (%s checks)\n' "$name" "$last" "$(printf '%s\n' "$out" | grep -c '^PASS')"
	case "$last" in
		*"ALL PASSED"*) ;;
		*) fail=1; printf '%s\n' "$out" | grep -E 'FAIL|Error|Traceback' | head -20 ;;
	esac
}

# shellcheck disable=SC2086
run elementor     "$PHP" $PHP_ARGS tests/php/test_elementor.php "$INC/class-elementor.php"
run approvals     "$PHP" $PHP_ARGS tests/php/test_central.php "$INC"
run owner-facts   "$PHP" $PHP_ARGS tests/php/test_facts.php "$INC"
run gsc-connect   "$PHP" $PHP_ARGS tests/php/test_gsc1.php "$INC"
run gsc-pull      "$PHP" $PHP_ARGS tests/php/test_gsc2.php "$INC"
run gsc-report    "$PHP" $PHP_ARGS tests/php/test_gsc3.php "$INC"
run freshness     "$PHP" $PHP_ARGS tests/php/test_fresh.php "$INC/class-freshness.php"
run discipline    "$PHP" $PHP_ARGS tests/php/test_discipline.php "$INC/class-discipline.php"
run brain         "$PY" tests/brain/test_brain.py .
run brain-select  "$PY" tests/brain/test_brain_select.py .

exit $fail
