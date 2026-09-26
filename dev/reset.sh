#!/usr/bin/env bash
# Rebuilds the local Kimai from scratch: fresh database, plugin installed, sample data.
# Usage: dev/reset.sh [--knust]
set -euo pipefail
cd "$(dirname "$0")/.."

FILES="-f dev/compose.yaml"
if [ "${1:-}" = "--knust" ]; then
    FILES="$FILES -f dev/compose.knust.yaml"
fi

COMPOSE="docker compose $FILES"
# www-data, not root: cache files written as root break Apache's worker (500 "not writable")
CONSOLE="$COMPOSE exec -T --user www-data kimai /opt/kimai/bin/console"
PLUGIN=/opt/kimai/var/plugins/FarbfaecherBundle

$COMPOSE down -v
$COMPOSE up -d
until [ "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8093/en/login)" = 200 ]; do sleep 3; done

if [ "${1:-}" = "--knust" ]; then
    $CONSOLE kimai:bundle:knust:install
fi

# The image creates the admin (ADMINMAIL/ADMINPASS); seed: finished onboarding, customers, projects, activities, 4 months of timesheets
$COMPOSE exec -T kimai php "$PLUGIN/dev/seed.php" | $COMPOSE exec -T db mariadb -ukimai -pkimai-dev kimai
$CONSOLE kimai:reload
echo "Ready: http://localhost:8093  admin@example.test / admin-dev-pass"
