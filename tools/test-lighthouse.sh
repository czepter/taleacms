#!/usr/bin/env bash
# Talea – output budget: installs each starter site and runs Lighthouse (mobile) on its pages. Fails when a page drops
# below the budget: performance MIN_PERF (default 90 – the PHP built-in server compresses nothing, a real host scores
# higher), accessibility, best practices and SEO MIN_OTHER (default 100).
# Needs PHP, MySQL, Node and Google Chrome (or CHROME=/path/to/chrome).
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (talea_test_lighthouse) DB_USER (root) DB_PASS () PORT (8099) SITES (all)
#   LOOKS: "all" or a list of look keys (system/looks): installs ONE sample site (SITES, default business), applies and publishes each look
#   and measures the pages with it; MIN_PERF/MIN_OTHER still apply (the launch bar, docs/LAUNCH.md, uses LOOKS=all MIN_PERF=99 MIN_OTHER=99).
#   PAGES (5): how many pages of the sitemap are measured.
# The database DB_NAME is DROPPED and recreated for every starter site. No mysql client is needed (PDO does the work).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-talea_test_lighthouse}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
MIN_PERF="${MIN_PERF:-90}"; MIN_OTHER="${MIN_OTHER:-100}"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
SITES="${SITES:-$(cd "$ROOT" && php -r 'require "system/bootstrap.php"; echo implode(" ", array_keys(Talea\Builder\Library::SITES));')}"
WORK="$(mktemp -d)"; ERRORS=0
LOOKS="${LOOKS:-}"; PAGES_MAX="${PAGES:-5}"
# runs one SQL statement through PDO: sql "<statement>" (the host, port and user come from the environment)
sql() { DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_USER="$DB_USER" DB_PASS="$DB_PASS" php -r '(new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), getenv("DB_USER"), getenv("DB_PASS")))->exec($argv[1]);' "$1"; }
stop_server() { [ -z "${SERVER_PID:-}" ] || { pkill -P "$SERVER_PID" 2>/dev/null || true; kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; }; }
cleanup() { stop_server; rm -rf "$WORK"; }
trap cleanup EXIT

npm i --silent --prefix "$WORK/lh" lighthouse@12 > /dev/null 2>&1
for SITE in $SITES; do
  echo "== starter site $SITE"
  stop_server
  SITE_PORT=$PORT; PORT=$((PORT + 1)); B="http://127.0.0.1:$SITE_PORT" # workers of the built-in server may linger on the previous port
  rm -rf "$WORK/web" && mkdir "$WORK/web"
  (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
  [ ! -d "$ROOT/vendor" ] || ln -s "$ROOT/vendor" "$WORK/web/vendor" # Phinx (git ignores vendor/)
  mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
  sql "DROP DATABASE IF EXISTS \`$DB_NAME\`"; sql "CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
  (cd "$WORK/web" && PHP_CLI_SERVER_WORKERS=4 exec php -S "127.0.0.1:$SITE_PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
  PASSWORD="Lighthouse-$(openssl rand -hex 8)"
  curl -s -o "$WORK/response" -X POST "$B/install.php" -d language=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
    --data-urlencode "db_password=$DB_PASS" -d db_prefix=tl_ --data-urlencode "site_name=Lighthouse Test Ltd" -d "starter=$SITE" -d username=admin -d "name=Tester" -d email= \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
    -d 'extensions[]=news' -d 'extensions[]=enquiries' -d 'extensions[]=stats' -d 'extensions[]=redirects'
  [ ! -f "$WORK/web/install.php" ] || { echo "  FAIL   install of $SITE failed"; grep -o 'role="alert">[^<]*' "$WORK/response" | sed 's/role="alert">//'; exit 1; }
  # the home page and the pages of the starter site from the sitemap
  measure() { # measure "<label>": every page of the sitemap (PAGES_MAX), a warm page cache first
    curl -s "$B/sitemap.xml" > "$WORK/sitemap.xml"
    PAGES=$(grep -o '<loc>[^<]*</loc>' "$WORK/sitemap.xml" | sed 's#<loc>[^/]*//[^/]*##;s#</loc>##' | head -"$PAGES_MAX")
    for P in ${PAGES:-/}; do
      curl -s -o /dev/null "$B$P"
      CHROME_PATH="$CHROME" "$WORK/lh/node_modules/.bin/lighthouse" "$B$P" --quiet --chrome-flags="--headless=new --no-sandbox" --output=json --output-path="$WORK/lh.json" \
        --only-categories=performance,accessibility,best-practices,seo > /dev/null 2>&1 || { echo "  FAIL   $1 Lighthouse failed on $P"; ERRORS=$((ERRORS+1)); continue; }
      RESULT=$(node -e '
        const r = require(process.argv[1]), c = r.categories, perf = +process.argv[2], other = +process.argv[3];
        const s = k => Math.round(c[k].score * 100), low = [];
        if (s("performance") < perf) low.push("performance");
        for (const k of ["accessibility", "best-practices", "seo"]) if (s(k) < other) low.push(k);
        const failed = [];
        for (const [id, a] of Object.entries(r.audits)) if (a.score !== null && a.score < 1 && ["binary", "numeric"].includes(a.scoreDisplayMode) && r.categories.accessibility.auditRefs.concat(r.categories["best-practices"].auditRefs, r.categories.seo.auditRefs, r.categories.performance.auditRefs).some(x => x.id === id && x.weight > 0)) failed.push(id);
        console.log((low.length ? "LOW " : "OK ") + Object.keys(c).map(k => k + " " + s(k)).join(", ") + " · LCP " + r.audits["largest-contentful-paint"].displayValue + ", CLS " + r.audits["cumulative-layout-shift"].displayValue + (failed.length ? " · failing: " + failed.join(", ") : ""));
      ' "$WORK/lh.json" "$MIN_PERF" "$MIN_OTHER")
      if [ "${RESULT%% *}" = OK ]; then echo "  ok     $1 $P: ${RESULT#OK }"; else echo "  FAIL   $1 $P: ${RESULT#LOW }"; ERRORS=$((ERRORS+1)); fi
    done
  }
  if [ -z "$LOOKS" ]; then
    measure "$SITE"
  else # every look on the same sample site: apply_look + publish_look over MCP, then measure
    TOKEN="talea_$(openssl rand -hex 24)"
    sql "INSERT INTO \`$DB_NAME\`.tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, 'lighthouse', '$(printf '%s' "$TOKEN" | shasum -a 256 | cut -d' ' -f1)', NOW() FROM \`$DB_NAME\`.tl_users WHERE username = 'admin'"
    mcp() { curl -s "$B/mcp" -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
    [ "$LOOKS" = all ] && LOOKS=$(cd "$ROOT" && ls system/looks | sed 's/\.json$//' | tr '\n' ' ')
    for LOOK in $LOOKS; do
      mcp apply_look "{\"look\":\"$LOOK\"}" | grep -q '"isError":true' && { echo "  FAIL   apply_look $LOOK"; ERRORS=$((ERRORS+1)); continue; }
      mcp publish_look '{}' | grep -q '"isError":true' && { echo "  FAIL   publish_look $LOOK"; ERRORS=$((ERRORS+1)); continue; }
      measure "look $LOOK"
    done
  fi
  if [ -s "$WORK/web/storage/log/errors.log" ]; then echo "  FAIL   application error log:"; cat "$WORK/web/storage/log/errors.log"; ERRORS=$((ERRORS+1)); fi
  [ -z "$LOOKS" ] || break # one sample site for all looks
done

echo
[ "$ERRORS" -eq 0 ] && echo "OUTPUT BUDGET OK" || { echo "ERRORS: $ERRORS"; exit 1; }
