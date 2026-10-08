#!/usr/bin/env bash
# Kaleta – browser test: a clean English install walked through in Chrome by tools/test-browser.mjs (admin, builder,
# editors, public site); fails on any script error. Needs PHP, MySQL, Node and Google Chrome (or CHROME=/path/to/chrome).
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test_browser) DB_USER (root) DB_PASS () PORT (8095)
# The database DB_NAME is DROPPED and recreated.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_browser}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8095}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" --init-command="SET time_zone = '+00:00'"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT

# axe-core (3.5) next to playwright-core: the accessibility check injects it from this local copy, never from a CDN
npm i --silent --prefix "$WORK/pw" playwright-core@1 axe-core@4 > /dev/null
mkdir "$WORK/web" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
PASSWORD="Browser-$(openssl rand -hex 8)"
curl -s -o "$WORK/response" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
  --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ --data-urlencode "nazev_webu=Browser Test Ltd" -d web=firemni -d user=admin -d "jmeno=Tester" -d email= \
  --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=newsletter' -d 'rozsireni[]=bookings' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani' -d 'rozsireni[]=whistleblowing' -d 'rozsireni[]=claude'
[ ! -f "$WORK/web/install.php" ] || { echo "install failed"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
# a bookable service on a public page (/booking-test, a stored build has every content key): the browser picks a day (3.2.2)
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_booking_services (id, name, duration_min) VALUES (900, 'Browser consultation', 60);
  INSERT INTO ka_booking_staff (id, name) VALUES (900, 'Browser Staff'); INSERT INTO ka_booking_staff_services VALUES (900, 900);
  INSERT INTO ka_booking_hours (staff_id, weekday, time_from, time_to) VALUES (900,1,'09:00','17:00'),(900,2,'09:00','17:00'),(900,3,'09:00','17:00'),(900,4,'09:00','17:00'),(900,5,'09:00','17:00'),(900,6,'09:00','17:00'),(900,7,'09:00','17:00');
  INSERT INTO ka_stranky (seo_link, titulek, text, v_menu, stavba) VALUES ('booking-test', 'Booking test', '', 0, '{\"v\":1,\"deti\":[{\"id\":\"s1\",\"typ\":\"sekce\",\"znacka\":\"section\",\"obsah\":{\"sirka\":\"obsah\",\"video\":\"\",\"pri_rolovani\":\"\",\"text_nahore\":\"\"},\"deti\":[{\"id\":\"bk1\",\"typ\":\"rezervace\",\"znacka\":\"form\",\"obsah\":{\"sluzba\":0,\"osoba\":0,\"tlacitko\":\"Book\",\"dekujeme\":\"Thank you.\",\"souhlas\":\"I agree.\"}}]}]}')"
# 3.5: the cookie bar shows (lead attribution needs consent to marketing; no outside script), with a link to the policy,
# and the accessibility toolbar is on – the accessibility steps check them together
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('cookies_mode', 'vestavena'), ('lead_attribution', '1'), ('cookies_policy_url', '/contact'), ('accessibility_toolbar', '1')
  ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)"; rm -f "$WORK"/web/storage/cache/stranky/*.html
NODE_PATH="$WORK/pw/node_modules" BASE="$B" PASSWORD="$PASSWORD" CHROME="${CHROME:-/Applications/Google Chrome.app/Contents/MacOS/Google Chrome}" node "$ROOT/tools/test-browser.mjs"
if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== application error log:"; cat "$WORK/web/storage/log/chyby.log"; exit 1; fi
