#!/usr/bin/env bash
# Kaleta – the public demo (2.6): a site installed from the command line, switched to demo mode, snapshotted, changed
# through the admin and reset. Checks the shared sign-in, what the demo refuses and that the reset brings everything
# back. Same env as tools/test.sh: DB_HOST DB_PORT DB_NAME (kaleta_test_demo) DB_USER DB_PASS PORT (8097).
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test_demo}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8097}"
WORK="$(mktemp -d)"; B="http://127.0.0.1:$PORT"; JAR="$WORK/jar"; FOUND=0
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
cleanup() { [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT
ok() { echo "  ok     $1"; }
fail() { echo "  CHYBA  $1"; FOUND=$((FOUND+1)); }
sql() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
token() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }

echo "== Demo site"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web"
(cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' f; do [ -e "$f" ] && printf '%s\0' "$f"; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web")
rm -f "$WORK/web/config.php"; mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
(cd "$WORK/web" && exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done
curl -s -o "$WORK/install.html" -X POST "$B/install.php" -d jazyk=en --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" \
  --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ -d nazev_webu=Demo -d web=firemni -d user=demo -d email= -d password=Demo-kaleta-2026 -d password2=Demo-kaleta-2026
[ -f "$WORK/web/config.php" ] || { sed 's/<[^>]*>//g' "$WORK/install.html" | grep -v '^\s*$' | head -20; exit 1; }
# demo mode: the shared account in config.php
php -r '$f = $argv[1]; $c = require $f; $c["demo"] = ["user" => "demo", "password" => "Demo-kaleta-2026"]; file_put_contents($f, "<?php\nreturn " . var_export($c, true) . ";\n");' "$WORK/web/config.php"
(cd "$WORK/web" && php system/demo.php snapshot > "$WORK/snapshot.txt" 2>&1) && ok "snapshot saved" || { fail "snapshot"; cat "$WORK/snapshot.txt"; }

echo "== What visitors see"
curl -s -o "$WORK/login.html" -c "$JAR" "$B/admin.php"
grep -q 'Sign in as demo with the password Demo-kaleta-2026' "$WORK/login.html" && grep -q 'value="Demo-kaleta-2026"' "$WORK/login.html" && ok "sign-in page shows and fills in the shared account" || fail "demo account on the sign-in page"
curl -s -D "$WORK/headers" -o "$WORK/home.html" "$B/"
grep -qi '^x-robots-tag: noindex' "$WORK/headers" && grep -q 'Kaleta demo – try the admin' "$WORK/home.html" && ok "public pages: noindex and the demo badge" || fail "noindex / badge"
curl -s "$B/robots.txt" | grep -q '^Disallow: /$' && ok "robots.txt disallows everything" || fail "robots.txt"
[ "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp")" = 403 ] && ok "the Claude connection is off" || fail "MCP in the demo"

echo "== What the demo refuses"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$(token "$WORK/login.html")" -d user=demo -d password=Demo-kaleta-2026
curl -s -b "$JAR" -c "$JAR" -o "$WORK/page.html" "$B/admin.php?module=pages"; T=$(token "$WORK/page.html")
grep -q 'Public demo: everything you change here is reset in' "$WORK/page.html" && ok "admin shows the reset notice" || fail "admin demo notice"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$T" -d idu=0 -d user=intruder --data-urlencode "password=Intruder-2026-x" -d admin=2
[ "$(sql "SELECT COUNT(*) FROM ka_uzivatele WHERE user = 'intruder'")" = 0 ] && ok "no new users" || fail "a user was created in the demo"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$T" -d co=heslo --data-urlencode "stare=Demo-kaleta-2026" --data-urlencode "nove=Taken-over-2026" --data-urlencode "nove2=Taken-over-2026"
curl -s -c "$WORK/jar2" -o "$WORK/login2.html" "$B/admin.php"
code=$(curl -s -b "$WORK/jar2" -c "$WORK/jar2" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$(token "$WORK/login2.html")" -d user=demo -d password=Demo-kaleta-2026)
curl -s -b "$WORK/jar2" -o "$WORK/page.html" "$B/admin.php?module=pages"; grep -q 'module=pages' "$WORK/page.html" && ok "the shared password cannot be changed" || fail "demo password changed"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=analytics --data-urlencode 'head_code=<script>alert(1)</script>' -d stats=1
[ "$(sql "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'head_code' AND hodnota LIKE '%alert%'")" = 0 ] && ok "no code fields" || fail "head code saved in the demo"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?module=settings&action=download_backup&soubor=x")
case "$code" in 302*) ok "backups cannot be downloaded";; *) fail "backup download: $code";; esac
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=mail -d mail_mode=smtp --data-urlencode smtp_host=evil.example
[ "$(sql "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna = 'smtp_host' AND hodnota = 'evil.example'")" = 0 ] && ok "mail settings stay" || fail "mail settings changed"

echo "== Changes and the reset"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$T" -d tab=general --data-urlencode "site_name=Changed by a visitor"
sql "UPDATE ka_stranky SET titulek = 'Defaced' WHERE seo_link = 'uvod'"
echo "visitor upload" > "$WORK/web/media/visitor.txt"
[ "$(sql "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" = "Changed by a visitor" ] && ok "visitors can change the site" || fail "the general settings did not save"
(cd "$WORK/web" && php system/demo.php reset > "$WORK/reset.txt" 2>&1) || { fail "reset"; cat "$WORK/reset.txt"; }
[ "$(sql "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" != "Changed by a visitor" ] && [ "$(sql "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'Defaced'")" = 0 ] && [ ! -e "$WORK/web/media/visitor.txt" ] \
  && ok "the reset brings back the database and media" || fail "after the reset"
curl -s -b "$JAR" -o "$WORK/page.html" "$B/admin.php?module=pages"; grep -q 'module=pages' "$WORK/page.html" && ok "the admin works after the reset" || fail "admin after the reset"
php "$WORK/web/system/demo.php" reset > /dev/null 2>&1 < /dev/null; [ "$(curl -s -o /dev/null -w '%{http_code}' "$B/system/demo.php")" != 200 ] && ok "demo.php does not run from the web" || fail "demo.php reachable from the web"

[ "$FOUND" = 0 ] && echo "  ok     the public demo" || { echo "NALEZENO CHYB: $FOUND"; exit 1; }
