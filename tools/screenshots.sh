#!/usr/bin/env bash
# Talea – screenshots for the project website, README and docs (docs/screenshots/*.png and docs/screenshots/site/*.png).
# A clean English install of each kind of business (installer blueprint and look) with sample content, then tools/screenshots.mjs drives Chrome (Playwright).
# Needs PHP, MySQL, Node, Google Chrome (or CHROME=/path/to/chrome) and vendor/ (composer install: the installer migrates with Phinx). The database DB_NAME is DROPPED and recreated.
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (talea_screens) DB_USER (root) DB_PASS () PORT (8097)
#   KEEP=1 leaves the last install running for a look around (Ctrl+C to stop).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-talea_screens}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8097}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"; OUT="$ROOT/docs/screenshots"
CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT
mkdir -p "$OUT/site"

echo "== Playwright"
npm i --silent --prefix "$WORK/pw" playwright-core@1 > /dev/null

[ -f "$ROOT/vendor/autoload.php" ] || { echo "vendor/ is missing: run composer install first"; exit 1; }

# screenshot name => installer blueprint, look (chosen for contrast between the three, not the best fit) and site name for the sample company
for SITE in business crafts consulting; do
  case "$SITE" in
    business) BLUEPRINT=manufacturer; LOOK=noir; NAME="Northfield Engineering" ;;
    crafts) BLUEPRINT=craftsman; LOOK=pulse; NAME="Oak & Iron Joinery" ;;
    consulting) BLUEPRINT=professional_services; LOOK=atelier; NAME="Clearpath Advisory" ;;
  esac
  echo "== $SITE: clean install"
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null || true
  rm -rf "$WORK/web"; mkdir "$WORK/web"
  (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
  ln -s "$ROOT/vendor" "$WORK/web/vendor"
  mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
  (cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
  for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
  if [ "$SITE" = business ]; then
    NODE_PATH="$WORK/pw/node_modules" BASE="$B" OUT="$OUT" CHROME="$CHROME" INSTALLER=1 node "$ROOT/tools/screenshots-site.mjs"
  fi
  PASSWORD="Screens-$(openssl rand -hex 8)"
  curl -s -o "$WORK/response" -X POST "$B/install.php" -d language=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
    --data-urlencode "db_password=$DB_PASS" -d db_prefix=tl_ --data-urlencode "site_name=$NAME" -d "blueprint=$BLUEPRINT" -d "look=$LOOK" -d username=admin -d "name=Alex Morgan" -d email=alex@example.com \
    --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
    -d 'extensions[]=news' -d 'extensions[]=enquiries' -d 'extensions[]=newsletter_signup' -d 'extensions[]=stats' -d 'extensions[]=redirects' -d 'extensions[]=bookings' -d 'extensions[]=claude'
  [ ! -f "$WORK/web/install.php" ] || { echo "install failed"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
  # sample data the empty install lacks: enquiries in the inbox and visits for the dashboard
  "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -e "
    INSERT INTO tl_enquiries (created_at, form, page, email, data, status) VALUES
      (NOW() - INTERVAL 3 DAY, 'Enquiry', '/contact', 'priya@example.com', '[[\"Name\",\"Priya Shah\"],[\"Email\",\"priya@example.com\"],[\"How can we help you?\",\"Thanks for the call yesterday. Sending the floor plan as promised.\"]]', 2),
      (NOW() - INTERVAL 1 DAY, 'Enquiry', '/services', 'daniel@example.com', '[[\"Name\",\"Daniel Novak\"],[\"Email\",\"daniel@example.com\"],[\"How can we help you?\",\"Do you also maintain existing installations, or only new ones?\"]]', 1),
      (NOW() - INTERVAL 2 HOUR, 'Enquiry', '/contact', 'hannah@example.com', '[[\"Name\",\"Hannah Clarke\"],[\"Email\",\"hannah@example.com\"],[\"Phone\",\"+44 20 7946 0958\"],[\"How can we help you?\",\"Could you quote for a new staircase and two oak doors? We are in Leeds and flexible on dates.\"]]', 0);
    -- 60 days of visits, so the dashboard and Analytics show figures (deterministic, roughly 40-90 visits a day)
    INSERT INTO tl_stats_days (day, visits, views)
      WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 60)
      SELECT CURRENT_DATE - INTERVAL i DAY, 40 + (i * 37) % 50, 2 * (40 + (i * 37) % 50) + (i * 13) % 30 FROM n;
    INSERT INTO tl_stats_pages (day, path, views)
      WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 60)
      SELECT CURRENT_DATE - INTERVAL i DAY, p.path, (40 + (i * 37) % 50) * p.share DIV 10 FROM n
      CROSS JOIN (SELECT '/' AS path, 9 AS share UNION ALL SELECT '/services', 5 UNION ALL SELECT '/contact', 3 UNION ALL SELECT '/about-us', 2) p;
    INSERT INTO tl_stats_sources (day, source, count)
      WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 60)
      SELECT CURRENT_DATE - INTERVAL i DAY, s.source, (40 + (i * 37) % 50) * s.share DIV 10 FROM n
      CROSS JOIN (SELECT 'google.com' AS source, 5 AS share UNION ALL SELECT 'bing.com', 1 UNION ALL SELECT 'linkedin.com', 1) s;
    INSERT INTO tl_stats_devices (day, device, visits)
      WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 60)
      SELECT CURRENT_DATE - INTERVAL i DAY, d.device, (40 + (i * 37) % 50) * d.share DIV 10 FROM n
      CROSS JOIN (SELECT 'phone' AS device, 6 AS share UNION ALL SELECT 'computer', 3 UNION ALL SELECT 'tablet', 1) d;
    UPDATE tl_settings SET value = CURRENT_DATE - INTERVAL 61 DAY WHERE name = 'stats_counting_since';"
  # the admin screenshots of docs/screenshots/site/ need bookings, requests and scheduled runs too
  [ "$SITE" = business ] && "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" < "$ROOT/tools/screenshots.sql"
  echo "== $SITE: screenshots"
  NODE_PATH="$WORK/pw/node_modules" BASE="$B" SITE="$SITE" PASSWORD="$PASSWORD" OUT="$OUT" CHROME="$CHROME" node "$ROOT/tools/screenshots.mjs"
  if [ "$SITE" = business ]; then
    NODE_PATH="$WORK/pw/node_modules" BASE="$B" PASSWORD="$PASSWORD" OUT="$OUT" CHROME="$CHROME" node "$ROOT/tools/screenshots-site.mjs"
  fi
done

ls -1 "$OUT"
if [ -n "${KEEP:-}" ]; then echo "Running at $B/ (admin / password in \$PASSWORD above) – Ctrl+C to stop"; wait "$SERVER_PID"; fi
