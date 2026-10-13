#!/usr/bin/env bash
#
# End-to-end test run: WordPress + MariaDB + the mock SendFleet API + Mailpit.
#
#   bash tests/e2e/run.sh          # start everything, install, run the scenarios
#   docker compose down -v         # tear down afterwards
#
# Requires Docker with the compose plugin. Never touches api.sendfleet.net:
# WordPress is pointed at the mock through SENDFLEET_API_URL.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

DC=(docker compose)
if ! docker info >/dev/null 2>&1; then
	DC=(sudo docker compose)
fi

wp() {
	"${DC[@]}" run --rm -T cli wp "$@"
}

"${DC[@]}" up -d

echo "Waiting for WordPress files…"
for _ in $(seq 1 90); do
	if "${DC[@]}" exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then
		break
	fi
	sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
	wp core install --url=http://localhost:8088 --title="SendFleet Test" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi

wp plugin activate sendfleet
wp eval-file wp-content/plugins/sendfleet/tests/e2e/scenarios.php
