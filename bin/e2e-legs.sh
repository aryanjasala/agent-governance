#!/usr/bin/env bash
# Runs the end-to-end suite on every WordPress and PHP version CI runs it on, one
# leg after the other, on this plugin's own Docker site (http://localhost:8906).
#
# Usage: bin/e2e-legs.sh [--leg <label>] [--keep] [-- <Playwright arguments>]
#   npm run test:e2e:legs
#   npm run test:e2e:legs -- --leg latest
#   npm run test:e2e:legs -- -- -g "abilities"
#
# Each leg starts from an empty site, so anything on the test site is lost.
# The stack is stopped at the end; --keep leaves the last leg running.
set -uo pipefail
cd "$(dirname "$0")/.."

# label|Docker image tag|WordPress version (empty = what the image ships)|theme
# The same legs as .github/workflows/e2e-tests.yml, generated from one list.
LEGS=(
  "latest|php8.3-apache||twentytwentyfive"
  "6.9, PHP 7.4|php7.4-apache|6.9.10|twentytwentyfive"
)

port="${WP_PORT:-8906}"
only=""
keep=0
while [ $# -gt 0 ]; do
	case "$1" in
		--leg) only="${2:-}"; shift 2 ;;
		--keep) keep=1; shift ;;
		--) shift; break ;;
		-h | --help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
		*) echo "Unknown option: $1 (Playwright arguments go after --)" >&2; exit 2 ;;
	esac
done

compose() { docker compose -f tests/e2e/env/docker-compose.yml "$@"; }
wpcli() { compose exec -T cli wp "$@"; }

summary=()
failed=0
ran=0
for leg in "${LEGS[@]}"; do
	IFS='|' read -r label image core theme <<< "$leg"
	if [ -n "$only" ] && [ "$only" != "$label" ]; then
		continue
	fi
	ran=$((ran + 1))
	echo
	echo "==> WordPress $label (wordpress:$image)"

	export WP_IMAGE_TAG="$image" WP_PORT="$port"
	compose down -v --remove-orphans > /dev/null 2>&1
	if ! started="$(compose up -d --wait --quiet-pull 2>&1)"; then
		echo "$started" >&2
		summary+=("FAIL|$label|the stack did not start")
		failed=1
		continue
	fi

	# The database comes up after WordPress does; install needs it.
	for _ in $(seq 1 60); do
		wpcli db check > /dev/null 2>&1 && break
		sleep 2
	done
	if [ -n "$core" ]; then
		wpcli core download --version="$core" --force --skip-content > /dev/null
	fi
	wpcli core install --url="http://localhost:$port" --title=E2E --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email > /dev/null
	wpcli theme is-installed "$theme" || wpcli theme install "$theme" > /dev/null
	versions="WordPress $(wpcli core version), PHP $(compose exec -T wp php -r 'echo PHP_VERSION;')"
	echo "    $versions, theme $theme"

	if WP_THEME="$theme" npm run --silent test:e2e -- "$@"; then
		summary+=("pass|$label|$versions")
	else
		summary+=("FAIL|$label|$versions")
		failed=1
	fi
done

if [ "$ran" -eq 0 ]; then
	echo "No leg is called \"$only\". Legs:" >&2
	printf '  %s\n' "${LEGS[@]%%|*}" >&2
	exit 2
fi

if [ "$keep" -eq 0 ]; then
	compose down -v > /dev/null 2>&1
fi

echo
echo "End-to-end legs:"
for line in "${summary[@]}"; do
	IFS='|' read -r result label versions <<< "$line"
	printf '  %-5s %-14s %s\n' "$result" "$label" "$versions"
done
exit "$failed"
