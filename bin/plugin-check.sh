#!/usr/bin/env bash
# Runs the WordPress.org Plugin Check on the folder that would ship, the way
# .github/workflows/plugin-check.yml does: bin/build-zip.sh first, then Plugin
# Check (latest from WordPress.org) on dist/agent-governance/ in this plugin's own Docker
# site (http://localhost:8906). Errors and warnings both fail it.
#
# Usage: bin/plugin-check.sh   (or npm run plugin-check)
#
# The site keeps its content. If it was running, it gets the source folder back
# at the end; if not, it is removed again (npm run env:stop).
set -euo pipefail
cd "$(dirname "$0")/.."

slug="agent-governance"
export WP_PORT="${WP_PORT:-8906}"
compose() { docker compose -f tests/e2e/env/docker-compose.yml "$@"; }
# The same site with dist/agent-governance/ mounted in place of the source.
compose_dist() { docker compose -f tests/e2e/env/docker-compose.yml -f tests/e2e/env/plugin-check.yml "$@"; }

bin/build-zip.sh

was_running="$(compose ps -q wp 2> /dev/null || true)"
restore() {
	if [ -n "$was_running" ]; then
		compose_dist exec -T cli wp plugin deactivate plugin-check > /dev/null 2>&1 || true
		compose up -d --wait > /dev/null 2>&1 || true
	else
		compose down -v > /dev/null 2>&1 || true
	fi
}
trap restore EXIT

if ! started="$(compose_dist up -d --wait --quiet-pull 2>&1)"; then
	echo "$started" >&2
	exit 1
fi
wpcli() { compose_dist exec -T cli wp "$@"; }

# The database comes up after WordPress does.
for _ in $(seq 1 60); do
	wpcli db check > /dev/null 2>&1 && break
	sleep 2
done
if ! wpcli core is-installed > /dev/null 2>&1; then
	wpcli core install --url="http://localhost:$WP_PORT" --title=E2E --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email > /dev/null
fi

if wpcli plugin is-installed plugin-check; then
	wpcli plugin update plugin-check > /dev/null 2>&1 || true
	wpcli plugin activate plugin-check > /dev/null
else
	wpcli plugin install plugin-check --activate > /dev/null
fi
wpcli plugin activate "$slug" > /dev/null
echo "Plugin Check $(wpcli plugin get plugin-check --field=version) on WordPress $(wpcli core version)"

results="$(wpcli plugin check "$slug" --format=table --exclude-files=.wp-env.json --require=./wp-content/plugins/plugin-check/cli.php 2>&1)"
echo "$results"
if grep -q '^FILE: ' <<< "$results" || grep -qi 'fatal error' <<< "$results"; then
	echo "Plugin Check found problems in dist/$slug/." >&2
	exit 1
fi
