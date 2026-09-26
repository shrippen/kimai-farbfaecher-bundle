#!/usr/bin/env bash
# CI: installs the release zip into an installed Kimai and tests it end to end.
#
#   dev/ci.sh <kimai-dir> <FarbfaecherBundle-x.y.z.zip>
#
#   zip ──► var/plugins/FarbfaecherBundle ──► kimai:reload ──► lint:container / twig / xliff
#       ──► admin + sample data (dev/seed.php) ──► kimai:farbfaecher:analyze (no critical clash left, no duplicate color)
#       ──► php -S: login, overview, plan preview, suggest JSON
#
# env: DATABASE_URL, e.g. mysql://kimai:kimai@127.0.0.1:3306/kimai?charset=utf8mb4&serverVersion=10.11.0-MariaDB
set -euo pipefail
trap 'echo "FAIL: unexpected error in line $LINENO" >&2' ERR

KIMAI="$(cd "$1" && pwd)"
ZIP="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$KIMAI/var/plugins/FarbfaecherBundle"
PORT="${CI_PORT:-8765}"
BASE="http://127.0.0.1:$PORT"
ADMIN_PASS="ci-admin-pass"
: "${DATABASE_URL:?DATABASE_URL is required}"

# unlimited memory: warming up the Twig cache needs more than the usual 128 MB CLI limit
console() { php -d memory_limit=-1 "$KIMAI/bin/console" --no-interaction "$@"; }
fail() { echo "FAIL: $*" >&2; exit 1; }
step() { echo; echo "── $*"; }

step "Install zip"
rm -rf "$PLUGIN"
mkdir -p "$KIMAI/var/plugins"
php -r '$z = new ZipArchive(); $z->open($argv[1]) === true || exit(1); $z->extractTo($argv[2]) || exit(1);' "$ZIP" "$KIMAI/var/plugins"
test -f "$PLUGIN/FarbfaecherBundle.php" || fail "zip does not contain FarbfaecherBundle/FarbfaecherBundle.php"
test ! -e "$PLUGIN/dev" || fail "dev/ must not be part of the zip"
console kimai:reload
console kimai:plugin | grep -q FarbfaecherBundle || fail "plugin not loaded"

step "Lint"
console lint:container
console lint:twig "$PLUGIN/Resources/views"
console lint:xliff "$PLUGIN/Resources/translations"
test "$(console debug:router | grep -c farbfaecher)" -ge 6 || fail "routes missing"

step "Sample data"
console kimai:user:create admin admin@example.test ROLE_SUPER_ADMIN "$ADMIN_PASS"
php "$HERE/seed.php" | php -r '
    $url = parse_url(getenv("DATABASE_URL"));
    $dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $url["host"], $url["port"] ?? 3306, ltrim($url["path"], "/"));
    $pdo = new PDO($dsn, urldecode($url["user"]), urldecode($url["pass"] ?? ""), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (array_filter(array_map("trim", explode(";\n", stream_get_contents(STDIN)))) as $sql) { $pdo->exec($sql); }
    echo $pdo->query("SELECT COUNT(*) FROM kimai2_timesheet")->fetchColumn(), " timesheets\n";
'
console cache:pool:clear cache.app

step "Analysis"
for strategy in hierarchical distinct; do
    out="$(console kimai:farbfaecher:analyze --limit 0 --plan clashes --strategy "$strategy")"
    line="$(grep -E '^Plan' <<< "$out")"
    echo "$strategy: $line"
    after="$(sed -E 's/.*→ ([0-9]+)\/([0-9]+)\/([0-9]+).*/\1/' <<< "$line")"
    test "$after" = "0" || fail "$strategy leaves $after critical clashes"
    dupes="$(grep -E '^  customer ' <<< "$out" | awk '{print $(NF-1)}' | sort | uniq -d | wc -l)"
    test "$dupes" = "0" || fail "$strategy gives $dupes colors to several customers"
done

step "HTTP"
# EGPCS: the built-in server otherwise hides environment variables (DATABASE_URL) from Symfony
php -d memory_limit=-1 -d variables_order=EGPCS -S "127.0.0.1:$PORT" -t "$KIMAI/public" "$KIMAI/public/index.php" > /tmp/farbfaecher-ci-http.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do curl -sf -o /dev/null "$BASE/en/login" && break; sleep 1; done

JAR="$(mktemp)"
token="$(curl -sf -c "$JAR" -b "$JAR" "$BASE/en/login" | grep -oE 'name="_csrf_token" value="[^"]+"' | sed -E 's/.*value="([^"]+)"/\1/')"
test -n "$token" || fail "no login token"
curl -sf -o /dev/null -c "$JAR" -b "$JAR" --data-urlencode "_username=admin" --data-urlencode "_password=$ADMIN_PASS" --data-urlencode "_csrf_token=$token" "$BASE/en/login_check"

check() {
    local url="$1" expect="$2" body code
    body="$(mktemp)"
    code="$(curl -s -o "$body" -w '%{http_code}' -c "$JAR" -b "$JAR" -H 'Accept: text/html,application/json' "$BASE$url")"
    test "$code" = "200" || { tail -20 /tmp/farbfaecher-ci-http.log >&2; fail "$url: HTTP $code"; }
    grep -q "$expect" "$body" || fail "$url: missing \"$expect\""
    echo "ok $url"
}
check "/en/admin/farbfaecher" "farbfaecher_clashes"
check "/en/admin/farbfaecher/plan?scope=clashes&strategy=hierarchical" "farbfaecher-plan"
check "/en/admin/farbfaecher/suggest?type=project&id=1&color=%230000ff" '"suggestions"'
check "/en/admin/system-config/edit/farbfaecher" "Palette size"

echo
echo "All checks passed."
