#!/usr/bin/env bash
# Kaleta - smoke test: a clean install into a temporary copy and a pass through the main pages.
# Runs locally and in GitHub Actions. Takes the database from environment variables:
#   DB_HOST (127.0.0.1) DB_PORT (3306) DB_NAME (kaleta_test) DB_USER (root) DB_PASS (empty) PORT (8099) WEB (firemni | remeslo | poradenstvi)
# The database DB_NAME is DROPPED during the test and created again.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DB_HOST="${DB_HOST:-127.0.0.1}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-kaleta_test}"; DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; PORT="${PORT:-8099}"
WORK="$(mktemp -d)"; JAR="$WORK/cookies.txt"; B="http://127.0.0.1:$PORT"; ERRORS=0
cleanup() { for pid in "${SERVER_PID:-}" "${SERVER3_PID:-}" "${CHANNEL_PID:-}" "${SERVICE_PID:-}" "${SMTP_PID:-}" "${CAPTCHA_PID:-}" "${OLDSITE_PID:-}"; do [ -z "$pid" ] || kill "$pid" 2>/dev/null || true; done; rm -rf "$WORK"; }
trap cleanup EXIT

echo "== syntaxe PHP"
find "$ROOT" -name '*.php' -not -path '*/.git/*' -not -path '*/dist/*' -print0 | xargs -0 -n1 php -l > /dev/null

echo "== čistá databáze a kopie projektu"
MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER"); [ -n "$DB_PASS" ] && MYSQL+=(-p"$DB_PASS")
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web") # soubory smazané a ještě nezapsané do gitu se nekopírují
mkdir -p "$WORK/web/media" "$WORK/web/storage/log" "$WORK/web/storage/cache"
CAPTCHA_PORT=$((PORT + 9)) # a fake CAPTCHA provider (2.6): Core\Captcha asks it instead of hCaptcha, Google or Cloudflare
(cd "$WORK/web" && KALETA_CAPTCHA_VERIFY="http://127.0.0.1:$CAPTCHA_PORT/" KALETA_IMPORT_LOCAL=1 KALETA_FIREWALL_LOCAL=1 exec php -S "127.0.0.1:$PORT" system/dev-router.php > "$WORK/server.log" 2>&1) & SERVER_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B/install.php" && break; sleep 0.3; done

check() { # over <popis> <očekávaný kód> <adresa> [hledaný text]
  local code; code=$(curl -s -b "$JAR" -c "$JAR" -A "Mozilla/5.0 test" -o "$WORK/response" -w '%{http_code}' "$B$3")
  if [ "$code" != "$2" ] || grep -qE 'Fatal error|Warning:|Deprecated:|Notice:' "$WORK/response" || { [ -n "${4:-}" ] && ! grep -q "$4" "$WORK/response"; }; then
    echo "  CHYBA  $1 ($3): kód $code, čekal jsem $2${4:+, text „$4“}"; ERRORS=$((ERRORS+1))
  else echo "  ok     $1"; fi
}

LAST_MIGRATION=$(ls "$ROOT"/system/sql/migrace/[0-9]*-*.sql "$ROOT"/system/sql/migrace/[0-9]*-*.php 2>/dev/null | sed 's/.*\/\([0-9]*\)-.*/\1/' | sort -n | tail -1 | sed 's/^0*//')
grep -q "const KALETA_DB_VERSION = $LAST_MIGRATION;" "$ROOT/system/bootstrap.php" && echo "  ok     KALETA_DB_VERSION odpovídá poslední migraci ($LAST_MIGRATION)" || { echo "  CHYBA  KALETA_DB_VERSION v system/bootstrap.php neodpovídá poslední migraci ($LAST_MIGRATION)"; ERRORS=$((ERRORS+1)); }

echo "== jednotkové testy"
php "$ROOT/tools/unit-tests.php" || ERRORS=$((ERRORS+1))

csrf() { grep -o 'name="_csrf" value="[a-f0-9]*"' "$WORK/response" | head -1 | sed 's/.*value="//;s/"//'; }
# publish_look: the administrator publishes the draft look (design system, classes, menus – Core\Look) from Site appearance
publish_look() { curl -s -b "$JAR" -o "$WORK/look.html" "$B/admin.php?module=appearance"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=publish_look" -d "_csrf=$(grep -o 'name="_csrf" value="[a-f0-9]*"' "$WORK/look.html" | head -1 | sed 's/.*value="//;s/"//')"; rm -f "$WORK"/web/storage/cache/stranky/*.html; }
expect() { [ "$2" = "$3" ] && echo "  ok     $1" || { echo "  CHYBA  $1: dostal jsem „$2“, čekal jsem „$3“"; ERRORS=$((ERRORS+1)); }; }

echo "== instalace"
PASSWORD="Test-$(date +%s)-heslo"
curl -s -o "$WORK/response" -X POST "$B/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB_NAME" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Testovací firma" -d "web=${WEB:-firemni}" -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" \
  -d 'rozsireni[]=novinky' -d 'rozsireni[]=poptavky' -d 'rozsireni[]=statistika' -d 'rozsireni[]=presmerovani'
grep -q "Hotovo, web běží" "$WORK/response" || { echo "  CHYBA  instalace selhala"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -20; exit 1; }
echo "  ok     instalace"
grep -qE 'ulohy\?token=[a-f0-9]{32}' "$WORK/response" && echo "  ok     2.8: the installer shows the cron line with its own address" || { echo "  CHYBA  instalátor neukázal řádek pro cron"; ERRORS=$((ERRORS+1)); }
[ ! -f "$WORK/web/install.php" ] && echo "  ok     instalátor se po sobě smazal" || { echo "  CHYBA  install.php po instalaci zůstal na místě"; ERRORS=$((ERRORS+1)); }

echo "== web"
check "úvodní stránka" 200 / "Testovací firma"
check "úvodní stránka má navigaci stránek a novinek" 200 / 'href="/o-nas"'
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/uvod"); expect "úvodní stránka má jen jednu adresu" "$code" "301 $B/"
check "stránka" 200 /sluzby "Služby"
check "úvodní stránka je ze sekcí builderu" 200 / 'class="stavba"'
check "služby mají otázky a odpovědi i pro vyhledávače" 200 /sluzby '"FAQPage"'
check "výpis novinek" 200 /novinky "Vítejte v Kaletě"
check "novinka" 200 /novinky/vitejte-v-kalete "Vítejte"
check "kategorie" 200 /novinky/kategorie/aktuality
check "hledání najde novinku i stránku" 200 "/hledani?q=Kontakt" 'href="/kontakt"'
for u in /rss.xml /feed.json /sitemap.xml /robots.txt /llms.txt /novinky/vitejte-v-kalete.md; do check "$u" 200 "$u"; done
check "mapa webu obsahuje novinku" 200 /sitemap.xml "/novinky/vitejte-v-kalete"
check "llms.txt vyjmenuje stránky" 200 /llms.txt "## Stránky"
check "strukturovaná data novinky" 200 /novinky/vitejte-v-kalete '"BlogPosting"'
check "zásady z instalace jsou skryté, dokud je správce nedoplní" 404 /zasady-ochrany-osobnich-udaju
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'zasady-ochrany-osobnich-udaju'"
check "zásady ochrany osobních údajů po zveřejnění" 200 /zasady-ochrany-osobnich-udaju "Jaké údaje zpracováváme"
check "patička odkazuje na zásady" 200 /o-nas 'zasady-ochrany-osobnich-udaju'
check "neexistující stránka" 404 /tohle-neexistuje
check "system/ není přístupný" 403 /system/sql/schema.sql
check "config.php není přístupný" 403 /config.php
mkdir -p "$WORK/web/layout/vlastni" && echo '<?php echo "VLASTNI SABLONA";' > "$WORK/web/layout/vlastni/base.php"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni VALUES ('layout','vlastni')"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "themeless: the site uses the built-in frame, never a custom layout" 200 / "image/sablona.css"
grep -q "VLASTNI SABLONA" "$WORK/response" && { echo "  CHYBA  a custom layout was used"; ERRORS=$((ERRORS+1)); } || echo "  ok     a custom layout in layout/ is ignored"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "bez úvodní stránky je úvodem výpis novinek" 200 / "Vítejte v Kaletě"

echo "== administrace"
check "zapomenuté heslo – formulář" 200 "/admin.php?action=password" "Poslat odkaz"
check "zapomenuté heslo – neplatný odkaz" 400 "/admin.php?action=password&token=$(printf 'a%.0s' $(seq 1 64))" "Odkaz už neplatí"
check "bez přihlášení je jen login" 200 /admin.php "Heslo"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin -d password=spatne-heslo-123); expect "špatné heslo odmítnuto" "$code" 401
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d user=admin --data-urlencode "password=$PASSWORD"); expect "POST bez CSRF odmítnut" "$code" 400
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN" -d user=admin --data-urlencode "password=$PASSWORD"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('extensions','novinky,poptavky,newsletter,statistika,presmerovani,asistent,jazyky,claude') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
check "přehled" 200 /admin.php "Přehled"
check "přehled: nadpis obrazovky je h1" 200 /admin.php "<h1>Přehled</h1>"
grep -q '<li class=""><a href="/admin.php?module=appearance">' "$WORK/response" && grep -q '<li class=""><a href="/admin.php?module=pages"><strong>Připravte stránky' "$WORK/response" && echo "  ok     první kroky nepočítají vzhled a stránky ze startovacího webu za hotové" || { echo "  CHYBA  první kroky odškrtnuté startovacím webem"; ERRORS=$((ERRORS+1)); }
check "administrace: nadpis h1 a hlavní menu v <nav>" 200 "/admin.php?module=pages" '<nav class="menu-obal" aria-label="Hlavní menu">'
for m in pages "pages&action=new" enquiries parts components "components&action=new" collections "collections&action=new" news "news&action=new" "news&action=links" categories "categories&action=new" tags media stats appearance users "users&action=new" redirects changelog transfer extensions; do check "modul $m" 200 "/admin.php?module=$m"; done
check "uživatelé se shrnutím oprávnění" 200 "/admin.php?module=users" "Smí všechno"
for z in general seo analytics cookies mail webhooks backups health; do check "nastavení/$z" 200 "/admin.php?module=settings&tab=$z"; done
check "nastavení: volba úvodní stránky" 200 "/admin.php?module=settings&tab=general" 'name="home_page"'
check "neznámý modul" 403 "/admin.php?module=neexistuje"
check "2.0: the public API of 1.x is gone" 404 /api/novinky
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('additional_languages','en') ON DUPLICATE KEY UPDATE hodnota='en'"
check "anglická verze webu" 200 /en/ 'lang="en"'
# 2.3.1: saving Settings → General as a browser does (every field of the form as it is) keeps the language versions
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post"
expect "saving Settings → General keeps the language versions" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'additional_languages'")" "en"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/en/novinky/vitejte-v-kalete"); expect "novinka jiné jazykové verze přesměruje" "$code" 301

# a failed news item validation must return the form with a message, not error 500
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=news&action=new"
TOKEN=$(csrf)
code=$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN" -d idc=0 -d titulek= -d tema=1)
[ "$code" = 200 ] && grep -q 'name="titulek"' "$WORK/response" && echo "  ok     chyba ve formuláři novinky vrátí formulář" || { echo "  CHYBA  validace novinky: kód $code"; ERRORS=$((ERRORS+1)); }

# news without a category (enabled only after installation): „Nová novinka“ (New news item) is not a dead end – a default category is created in the site language
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DROP TABLE IF EXISTS kat_zaloha; CREATE TABLE kat_zaloha AS SELECT * FROM ka_kategorie; DELETE FROM ka_kategorie; UPDATE ka_nastaveni SET hodnota='en' WHERE promenna='site_language'"
check "nová novinka bez kategorie otevře editor" 200 "/admin.php?module=news&action=new" 'name="titulek"'
expect "výchozí kategorie založená v jazyce webu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(nazev)) FROM ka_kategorie")" "1:News"
"${MYSQL[@]}" "$DB_NAME" -e "SET FOREIGN_KEY_CHECKS=0; DELETE FROM ka_kategorie; INSERT INTO ka_kategorie SELECT * FROM kat_zaloha; DROP TABLE kat_zaloha; UPDATE ka_nastaveni SET hodnota='cs' WHERE promenna='site_language'"

# news author: sees only their own news items and does not publish
NEWS_ID=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky ORDER BY idc LIMIT 1")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d jmeno=Autor -d user=autor --data-urlencode "password=$PASSWORD" -d admin=0
JAR2="$WORK/jar2"
TOKEN2=$(curl -s -c "$JAR2" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN2" -d user=autor --data-urlencode "password=$PASSWORD"
code=$(curl -s -b "$JAR2" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=news")
[ "$code" = 200 ] && ! grep -q "action=edit&amp;id=$NEWS_ID\"" "$WORK/response" && echo "  ok     autor nevidí cizí novinky" || { echo "  CHYBA  autor – výpis: kód $code"; ERRORS=$((ERRORS+1)); }
expect "autor cizí novinku neotevře" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=news&action=edit&id=$NEWS_ID")" 404
expect "autor nemá přístup ke stránkám" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages")" 403
TOKEN2=$(curl -s -b "$JAR2" "$B/admin.php?module=news&action=new" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//')
curl -s -b "$JAR2" -c "$JAR2" -o /dev/null -X POST "$B/admin.php?module=news&action=save" -d "_csrf=$TOKEN2" -d idc=0 -d titulek=XSS-test -d tema=1 \
  --data-urlencode 'uvod=<p onmouseover="alert(1)">Perex</p><script>alert(2)</script>' --data-urlencode 'text=<p><img src=x onerror=alert(3)><a href="javascript:alert(4)">odkaz</a></p>'
expect "autor nevloží do novinky skript" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(uvod, text) REGEXP 'script|onerror|onmouseover|javascript' FROM ka_novinky WHERE titulek = 'XSS-test'")" "0"
check "editor vidí na přehledu novinky od autorů, které čekají na vydání" 200 /admin.php "Novinky od autorů čekají na vydání"
check "výpis novinek: filtr Čekají na vydání" 200 "/admin.php?module=news&stav=ke_vydani" "XSS-test"

echo "== firma"
check "nastavení/firma" 200 "/admin.php?module=settings&tab=company" 'name="company_hours"'
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00
So 9–12" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
expect "údaje firmy uloženy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'")" 12345678
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company -d company_type=LocalBusiness -d company_country=CZ --data-urlencode "company_hours=kdykoli"
expect "nesrozumitelná otevírací doba odmítnuta" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%8:00%' AND hodnota NOT LIKE '%kdykoli%' FROM ka_nastaveni WHERE promenna = 'company_hours'")" 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=company --data-urlencode "company_name=Testovací firma s.r.o." -d company_type=HomeAndConstructionBusiness \
  -d company_id=12345678 -d company_vat_id=CZ12345678 --data-urlencode "company_street=Dlouhá 12" --data-urlencode "company_city=Praha" --data-urlencode "company_postcode=110 00" -d company_country=CZ \
  --data-urlencode "company_phone=+420 123 456 789" --data-urlencode "company_hours=Po–Pá 8:00–17:00" --data-urlencode "company_map=https://mapy.cz/s/abc" --data-urlencode "company_gps=50.0875, 14.4213"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q '"@type":"HomeAndConstructionBusiness"' "$WORK/response" && grep -q '"openingHoursSpecification"' "$WORK/response" && grep -q '"latitude":50.0875' "$WORK/response" && grep -q '"vatID":"CZ12345678"' "$WORK/response" \
  && echo "  ok     firma ve strukturovaných datech (LocalBusiness, otevírací doba, souřadnice)" || { echo "  CHYBA  firma ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"
grep -q 'Dlouhá 12<br>110 00 Praha' "$WORK/response" && grep -q 'href="tel:+420123456789"' "$WORK/response" && grep -q '<li>Po–Pá 8:00–17:00</li>' "$WORK/response" && grep -q 'IČO 12345678, DIČ CZ12345678' "$WORK/response" \
  && echo "  ok     kontakt vypisuje údaje firmy z Nastavení" || { echo "  CHYBA  údaje firmy na kontaktu"; ERRORS=$((ERRORS+1)); }

echo "== vzhled webu (design systém)"
check "vzhled s předvolbami a náhledem" 200 "/admin.php?module=appearance" 'data-predvolba'
TOKEN=$(csrf)
curl -s -b "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=appearance&action=preview" -d "_csrf=$TOKEN" --data-urlencode 'ds[barvy][primarni]=#ff00aa' -d 'ds[zaklad_min]=18'
grep -q 'ka-barva-primarni: #ff00aa' "$WORK/response" && grep -q '"kontrasty"' "$WORK/response" && echo "  ok     živý náhled vrátí tokeny a kontrasty" || { echo "  CHYBA  náhled vzhledu"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=save" -d "_csrf=$TOKEN" -d layout=zakladni -d tmavy_rezim=vypnuto --data-urlencode 'ds[barvy][primarni]=#9a3412' --data-urlencode 'ds[barvy][text]=red;}body{' -d 'ds[pismo_titulky]=klasicke' -d 'ds[sirka]=1280'
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$WORK/response" && { echo "  CHYBA  the saved appearance is on the site before publishing"; ERRORS=$((ERRORS+1)); } || echo "  ok     the saved appearance waits in the draft look"
check "the admin shows the draft look with its changes" 200 "/admin.php?module=appearance" "Nepublikované změny vzhledu"
publish_look
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-barva-primarni: #9a3412' "$WORK/response" && grep -q 'ka-sirka: 80rem' "$WORK/response" && grep -q 'ka-pismo-titulky: Georgia' "$WORK/response" && echo "  ok     uložený vzhled je po publikování na webu" || { echo "  CHYBA  uložení vzhledu"; ERRORS=$((ERRORS+1)); }
grep -q 'body{' "$WORK/response" && { echo "  CHYBA  do CSS proniklo neplatné zadání barvy"; ERRORS=$((ERRORS+1)); } || echo "  ok     neplatná barva se nahradí výchozí"

echo "== builder stránek"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
check "builder se otevře a převede textovou stránku" 200 "/admin.php?module=pages&action=builder&id=$IDS" 'id="stavitel-data"'
TOKEN=$(csrf)
page_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDS" -d "_csrf=$TOKEN" "${@:2}"; }
BUILD='{"v":1,"deti":[{"id":"sek1","typ":"sekce","deti":[{"id":"nad1","typ":"nadpis","znacka":"h1","obsah":{"text":"Builder test"},"styl":{"zaklad":{"barva":"primarni"},"mobil":{"velikost_pisma":"2"}},"tridy":["karta"]},{"id":"faq1","typ":"faq","obsah":{"polozky":[{"otazka":"Kolik to stojí?","odpoved":"<p>Záleží na rozsahu.</p>"}]}},{"id":"txt1","typ":"text","obsah":{"html":"<h2>Jak to funguje</h2><p>Krok za krokem.</p><h2>Jak to funguje</h2><h3 id=\"vlastni\">Vlastní</h3>"}},{"id":"zly1","typ":"skript"}]}]}'
code=$(page_action build_save --data-urlencode "stavba=$BUILD")
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && grep -q 'Neznámý typ prvku' "$WORK/response" && echo "  ok     uložení konceptu vrátí vyčištěnou stavbu a chyby" || { echo "  CHYBA  stavba_uloz: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný JSON stavby odmítnut" "$(page_action build_save -d 'stavba={nesmysl')" 400
expect "uložení z cizí verze odmítnuto (souběžná úprava)" "$(page_action build_save -d verze=0000000000000000 --data-urlencode "stavba=$BUILD")" 409
grep -q '"konflikt":true' "$WORK/response" && grep -q 'Builder test' "$WORK/response" && echo "  ok     konflikt vrátí novější verzi ze serveru" || { echo "  CHYBA  odpověď konfliktu"; ERRORS=$((ERRORS+1)); }
expect "publikování z cizí verze odmítnuto" "$(page_action build_publish -d verze=0000000000000000)" 409
expect "přepsání cizí verze na přání" "$(page_action build_save -d verze=0000000000000000 -d prepsat=1 --data-urlencode "stavba=$BUILD")" 200
expect "builder bez CSRF odmítnut" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_save&id=$IDS" --data-urlencode "stavba=$BUILD")" 400
expect "knihovna sekcí jen přes POST" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=build_section&id=$IDS&klic=faq")" 404
code=$(page_action "build_section&klic=vyhody"); [ "$code" = 200 ] && grep -q '"karta"' "$WORK/response" && echo "  ok     sekce z knihovny založí své třídy" || { echo "  CHYBA  stavba_sekce: kód $code"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_class -d nazev=karta --data-urlencode 'styl={"zaklad":{"pozadi":"plocha","odsazeni_y":"l"}}' --data-urlencode 'css=letter-spacing: 0.01em; background: url(x)')
[ "$code" = 200 ] && grep -q 'Nepovolená deklarace' "$WORK/response" && echo "  ok     třída uložena, nebezpečné CSS zahozeno" || { echo "  CHYBA  stavba_trida: kód $code"; ERRORS=$((ERRORS+1)); }
expect "neplatný název třídy odmítnut" "$(page_action build_class -d 'nazev=Karta Velka')" 400
expect "a change of an existing class in the builder goes to the draft look" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota LIKE '%\"karta\"%' FROM ka_nastaveni WHERE promenna = 'look_draft'")" "1"
publish_look
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     koncept není před publikováním na webu" || { echo "  CHYBA  koncept je na webu dřív, než se publikuje"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu pro editor" 200 "/o-nas?stavba=koncept&editor=1" 'data-ka-id="nad1"'
check "náhled konceptu se neindexuje" 200 "/o-nas?stavba=koncept" 'noindex'
curl -s -o "$WORK/response" "$B/o-nas?stavba=koncept&editor=1"; ! grep -q "Builder test" "$WORK/response" && echo "  ok     náhled konceptu nevidí návštěvník" || { echo "  CHYBA  koncept vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_share -d dni=3); SHARED_LINK=$(php -r 'echo json_decode((string) file_get_contents($argv[1]))->odkaz ?? "";' "$WORK/response")
curl -s -o "$WORK/response" "$SHARED_LINK"
[ "$code" = 200 ] && [[ "$SHARED_LINK" == "$B/o-nas?stavba=koncept&nahled_klic="* ]] && grep -q "Builder test" "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && echo "  ok     sdílený odkaz ukáže koncept bez přihlášení a bez značek editoru" || { echo "  CHYBA  stavba_sdilet: kód $code, odkaz $SHARED_LINK"; ERRORS=$((ERRORS+1)); }
code=$(page_action build_publish); expect "publikování stavby" "$code" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"
grep -q '<h1 id="s-nad1" class="karta">Builder test</h1>' "$WORK/response" && echo "  ok     publikovaná stavba na webu, jedna značka na prvek" || { echo "  CHYBA  stavba na webu"; ERRORS=$((ERRORS+1)); }
grep -q '<h2 id="jak-to-funguje">' "$WORK/response" && grep -q '<h2 id="jak-to-funguje-2">' "$WORK/response" && grep -q '<h3 id="vlastni">' "$WORK/response" && echo "  ok     mezititulky textu mají kotvy (jedinečné, vlastní id zůstane)" || { echo "  CHYBA  kotvy mezititulků v textu"; ERRORS=$((ERRORS+1)); }
grep -q 'data-ka-id' "$WORK/response" && { echo "  CHYBA  značky editoru na veřejném webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     bez značek editoru na veřejném webu"
grep -q '@layer prvky' "$WORK/response" && grep -q '#s-nad1 { color: var(--ka-barva-primarni); }' "$WORK/response" && grep -q '.karta { background-color: var(--ka-barva-plocha)' "$WORK/response" && echo "  ok     CSS prvků a tříd ve vrstvách" || { echo "  CHYBA  CSS stavby"; ERRORS=$((ERRORS+1)); }
grep -q '"FAQPage"' "$WORK/response" && echo "  ok     otázky a odpovědi jako strukturovaná data" || { echo "  CHYBA  FAQPage chybí"; ERRORS=$((ERRORS+1)); }
check "hledání najde obsah stavby" 200 "/hledani?q=Builder+test" 'Nalezeno: 1'
page_action build_save --data-urlencode "stavba=${BUILD/Builder test/Druhá verze}" > /dev/null; page_action build_publish > /dev/null
expect "předchozí publikovaná verze je v historii" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")" 1
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idr FROM ka_stavba_revize WHERE ids = $IDS AND stavba LIKE '%Builder test%'")
page_action build_restore -d "idr=$IDR" > /dev/null; grep -q 'Builder test' "$WORK/response" && echo "  ok     obnovení verze do konceptu" || { echo "  CHYBA  stavba_obnov"; ERRORS=$((ERRORS+1)); }
page_action build_discard > /dev/null; grep -q 'Druhá verze' "$WORK/response" && echo "  ok     zahození změn vrátí publikovanou stavbu" || { echo "  CHYBA  stavba_zahod"; ERRORS=$((ERRORS+1)); }
expect "autor novinek do builderu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=pages&action=builder&id=$IDS")" 403
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=build_text" -d "_csrf=$TOKEN" -d "ids=$IDS"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<h1>Druhá verze</h1>" "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && echo "  ok     návrat k textu zachová obsah stavby bez rozložení" || { echo "  CHYBA  stavba_text"; ERRORS=$((ERRORS+1)); }

echo "== Claude (MCP): builder"
API_TOKEN="kaleta_$(printf 'a%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$API_TOKEN")', NOW() FROM ka_uzivatele WHERE user = 'admin'"
# a grep that reads the whole input: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
contains() { grep "$@" > /dev/null; }
mcp() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}"; }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q '"name":"create_page"' "$WORK/response" && ! grep -q '"name":"vytvor_stranku"' "$WORK/response" && grep -q '"title":{' "$WORK/response" && echo "  ok     MCP: nástroje s anglickými názvy a parametry" || { echo "  CHYBA  MCP tools/list anglicky"; ERRORS=$((ERRORS+1)); }
mcp list_pages '{}' > "$WORK/response"; grep -q 'title\\":' "$WORK/response" && grep -q 'in_menu\\":' "$WORK/response" && echo "  ok     MCP: anglický nástroj vrací anglické klíče" || { echo "  CHYBA  MCP list_pages"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp seznam_stranek '{}' > "$WORK/response"; grep -q 'titulek\\":' "$WORK/response" && echo "  ok     MCP: český název funguje dál jako skrytý alias" || { echo "  CHYBA  MCP český alias"; ERRORS=$((ERRORS+1)); }
mcp get_page '{"id":99999}' > "$WORK/response"; grep -q 'The page does not exist. Use list_pages.' "$WORK/response" && echo "  ok     MCP: chyba anglického nástroje anglicky" || { echo "  CHYBA  MCP anglická chyba"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | grep -q 'build_from_html' && echo "  ok     MCP: pokyny serveru s anglickými názvy" || { echo "  CHYBA  MCP pokyny"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; grep -q 'knihovna' "$WORK/response" && grep -q 'ka-mezera' "$WORK/response" && echo "  ok     MCP: schéma builderu" || { echo "  CHYBA  MCP stavba_schema"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_z_html '{"titulek":"Z HTML","html":"<style>.uvod-x { padding-block: var(--ka-mezera-2xl); } .uvod-x h1 { color: red }</style><header class=\"uvod-x\"><div class=\"container\"><h1>Stránka od Clauda</h1><p>Text <b>tučně</b>.</p><a class=\"btn\" href=\"/kontakt\">Kontakt</a></div></header><form><input></form>"}' > "$WORK/response"
grep -q 'koncept' "$WORK/response" && grep -q 'Formul' "$WORK/response" && grep -q 'vynech.*btn' "$WORK/response" && echo "  ok     MCP: HTML převedeno na koncept stavby s hlášením (i formulář)" || { echo "  CHYBA  MCP stavba_z_html"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDZ=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'z-html'")
expect "MCP: nová stránka zůstává skrytá a bez publikované stavby" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba IS NULL, '/', stavba_koncept LIKE '%od Clauda%') FROM ka_stranky WHERE ids = $IDZ")" "0/1/1"
expect "MCP: třída z <style> uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT css FROM ka_tridy WHERE nazev = 'uvod-x'")" "padding-block: var(--ka-mezera-2xl);"
mcp vloz_sekci "{\"id\":$IDZ,\"sekce\":\"faq\"}" > /dev/null
mcp publikuj_stavbu "{\"id\":$IDZ}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $IDZ"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h1>Stránka od Clauda</h1>' "$WORK/response" && grep -q 'class="uvod-x"' "$WORK/response" && ! grep -q 'container' "$WORK/response" && grep -q '"FAQPage"' "$WORK/response" && echo "  ok     MCP: publikovaná stránka od Clauda na webu" || { echo "  CHYBA  MCP publikování"; ERRORS=$((ERRORS+1)); }
mcp uprav_design_system '{"ds":{"barvy":{"primarni":"#0f766e"},"zaobleni":"l"}}' > "$WORK/response"; mcp publish_look '{}' > /dev/null; grep -q 'citelnost' "$WORK/response" && echo "  ok     MCP: úprava design systému" || { echo "  CHYBA  MCP uprav_design_system"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "design systém z MCP je na webu" 200 / 'ka-barva-primarni: #0f766e'
check "design systém z MCP zachoval ostatní barvy" 200 / 'ka-barva-plocha: #f5f6f8'
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"tmavy","tmavy_prepinac":"1"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; grep -q 'data-tmavy data-tema="tmavy"' "$WORK/response" && grep -q 'data-tema-volba="svetly"' "$WORK/response" && grep -q 'localStorage.getItem(.ka-tema.)' "$WORK/response" && grep -q 'data-tema=\\"tmavy\\"\]\|data-tema="tmavy"\] {' "$WORK/response" \
    && echo "  ok     tmavý vzhled vždy a přepínač vzhledu pro návštěvníky (i přes MCP)" || { echo "  CHYBA  tmavý režim a přepínač vzhledu"; ERRORS=$((ERRORS+1)); }
mcp uprav_nastaveni '{"nastaveni":{"tmavy_rezim":"vypnuto","tmavy_prepinac":"0"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== Claude (MCP): stavba webu bez administrace"
mcp stavba_z_html '{"titulek":"Mrizka","html":"<style>.mriz-t { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .kar-t:hover { box-shadow: var(--ka-stin-m) } @media (max-width: 767px) { .mriz-t { grid-template-columns: 1fr } }</style><section><div class=\"mriz-t\"><div class=\"kar-t\"><h3>Jedna</h3></div><div class=\"kar-t\"><h3>Dva</h3></div></div></section>"}' > "$WORK/response"
IDM2=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'mrizka'")
expect "MCP: @media a :hover z <style> jako stavy třídy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT styl FROM ka_tridy WHERE nazev = 'mriz-t'), (SELECT styl FROM ka_tridy WHERE nazev = 'kar-t'))")" '{"mobil":{"sloupce":"1"}}{"hover":{"stin":"m"}}'
mcp stavba_nacti "{\"id\":$IDM2}" > "$WORK/response"
grep -q 'mriz-t' "$WORK/response" && ! grep -q 'zobrazeni' "$WORK/response" && ! grep -q '\\"odkaz\\":\\"\\"' "$WORK/response" && echo "  ok     MCP: stavba_nacti bez výchozích hodnot, prvek s třídou bez výchozího stylu" || { echo "  CHYBA  MCP stavba_nacti kompaktní"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDH3=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["stavba"]["deti"][0]["deti"][0]["deti"][0]["deti"][0]["id"];' "$WORK/response")
mcp stavba_uprav "{\"id\":$IDM2,\"operace\":[{\"op\":\"uprav\",\"id\":\"$IDH3\",\"obsah\":{\"text\":\"Opraveno\"}},{\"op\":\"smaz\",\"id\":\"neni\"}]}" > "$WORK/response"
grep -q 'chyby_operaci\\":{\\"op\[1\]' "$WORK/response" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%Opraveno%' FROM ka_stranky WHERE ids = $IDM2")" = 1 ] \
    && echo "  ok     MCP: dílčí úprava prvku podle id (chybná operace nahlášena)" || { echo "  CHYBA  MCP stavba_uprav"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
CHECK_RESULT=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo implode("|", array_column($j["kontrola"] ?? [], "zprava"));' "$WORK/response")
[[ "$CHECK_RESULT" == *"(h1)"* ]] && echo "  ok     MCP: zápis stavby vrátí kontrolu před publikováním (stránka bez h1)" || { echo "  CHYBA  MCP kontrola: $CHECK_RESULT"; ERRORS=$((ERRORS+1)); }
PREVIEW=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["nahled"];' "$WORK/response")
curl -s -o "$WORK/response" -w '%{http_code}' "$PREVIEW" > "$WORK/kod"; grep -q 'Opraveno' "$WORK/response" && grep -q 'noindex' "$WORK/response" && [ "$(cat "$WORK/kod")" = 200 ] \
    && echo "  ok     podepsaný náhled konceptu skryté stránky bez přihlášení" || { echo "  CHYBA  podepsaný náhled ($(cat "$WORK/kod"))"; ERRORS=$((ERRORS+1)); }
expect "náhled s cizím nebo pozměněným klíčem nejde" "$(curl -s -o /dev/null -w '%{http_code}' "${PREVIEW%?}x")" 404
expect "klíč náhledu jedné stránky neotevře jinou" "$(curl -s -o /dev/null -w '%{http_code}' "$B/z-html?stavba=koncept&nahled_klic=${PREVIEW##*nahled_klic=}" | tr -d '\n'; curl -s "$B/z-html?stavba=koncept&nahled_klic=${PREVIEW##*nahled_klic=}" | grep -c 'Opraveno')" "2000"
mcp nahled_odkaz '{"cast":"paticka"}' > "$WORK/response"; grep -q 'cast=paticka&stavba=koncept&nahled_klic=' "$WORK/response" && echo "  ok     MCP: odkaz na náhled části webu" || { echo "  CHYBA  MCP nahled_odkaz"; ERRORS=$((ERRORS+1)); }
mcp uloz_tridy '{"css":".stitek-t { padding: var(--ka-mezera-2xs) var(--ka-mezera-s); border-radius: var(--ka-zaobleni) } @media (max-width: 1023px) { .stitek-t { font-size: var(--ka-krok--1) } }"}' > "$WORK/response"
mcp uloz_tridy '{"css":".stitek-t:hover { background-color: #ffe3dc }"}' > /dev/null
mcp seznam_trid '{"nazev":"stitek-t"}' > "$WORK/response"; grep -q 'velikost_pisma\\":\\"-1' "$WORK/response" && grep -q 'hover' "$WORK/response" && grep -q 'border-radius' "$WORK/response" && echo "  ok     MCP: sdílená třída z CSS i se stavem tablet" || { echo "  CHYBA  MCP uloz_tridy"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
PNG=$(php -r '$i = imagecreatetruecolor(40, 30); imagefill($i, 0, 0, imagecolorallocate($i, 255, 79, 46)); ob_start(); imagepng($i); echo base64_encode(ob_get_clean());')
mcp nahraj_soubor "{\"nazev\":\"tym-foto.png\",\"data\":\"$PNG\",\"popis\":\"Tym v dilne\"}" > "$WORK/response"
MEDIUM=$(php -r '$j = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); echo $j["adresa"] ?? "";' "$WORK/response")
[ -n "$MEDIUM" ] && [ -f "$WORK/web/$MEDIUM" ] && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE obr_poloha = '$MEDIUM'")" = "Tym v dilne" ] \
    && echo "  ok     MCP: obrázek nahraný v base64 je v Médiích" || { echo "  CHYBA  MCP nahraj_soubor (obrázek)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor "{\"nazev\":\"pismo.woff2\",\"data\":\"$(base64 < image/pisma/bricolage-grotesque-latin.woff2 | tr -d '\n')\"}" > "$WORK/response"
grep -q 'vlastni_pisma' "$WORK/response" && grep -q 'pismo-[a-f0-9]*\.woff2' "$WORK/response" && echo "  ok     MCP: písmo WOFF2 do Médií s návodem pro design system" || { echo "  CHYBA  MCP nahraj_soubor (písmo)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nahraj_soubor '{"nazev":"skript.php","data":"PD9waHAgZWNobyAxOw=="}' > "$WORK/response"; grep -q 'isError' "$WORK/response" && ! ls "$WORK"/web/media/*/*/skript* > /dev/null 2>&1 && echo "  ok     MCP: PHP ani jiný spustitelný soubor nahrát nejde" || { echo "  CHYBA  MCP nahraj_soubor pustil PHP"; ERRORS=$((ERRORS+1)); }
mcp uprav_nastaveni '{"nastaveni":{"text_paticky":"Paticka od Clauda","email_webu":"utocnik@example.com","firma_ico":"abc"}}' > "$WORK/response"
expect "MCP: nastavení webu – povolené se uloží, e-mail a neplatné IČO ne" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'footer_text'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_email'), '') <> 'utocnik@example.com', '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_id'), '') <> 'abc')")" "Paticka od Clauda|1|1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'spravce@example.cz' WHERE promenna = 'site_email'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/" | contains 'spravce@example.cz' && { echo "  CHYBA  e-mail webu je vidět na webu"; ERRORS=$((ERRORS+1)); } || echo "  ok     e-mail webu (poptávky, upozornění) se na webu neukazuje"
mcp uprav_nastaveni '{"nastaveni":{"firma_email":"info@example.cz"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "veřejný e-mail firmy v patičce" 200 / "info@example.cz"
check "security.txt: without a contact there is none" 404 /.well-known/security.txt
mcp update_settings '{"settings":{"security_contact":"security@example.com"}}' > /dev/null
check "security.txt from the security contact (RFC 9116)" 200 /.well-known/security.txt "Contact: mailto:security@example.com"
mcp uprav_nastaveni '{"nastaveni":{"logo_webu":"image/kaleta-logo.svg","favicon":"../config.php"}}' > "$WORK/response"
expect "MCP: logo webu ze systémových souborů, cesta mimo media/ a image/ neprojde" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'logo'), '|', COALESCE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'favicon'), ''))")" "image/kaleta-logo.svg|"
mcp uloz_presmerovani '{"z":"/stary-web/sluzby","na":"/z-html"}' > /dev/null
expect "MCP: přesměrování staré adresy" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/stary-web/sluzby")" "301 $B/z-html"
mcp smaz_stranku "{\"id\":$IDM2}" > /dev/null
expect "MCP: stránka do koše" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NOT NULL FROM ka_stranky WHERE ids = $IDM2")" 1
mcp smaz_stranku "{\"id\":$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'")}" | grep -q 'isError' && echo "  ok     MCP: úvodní stránku smazat nejde" || { echo "  CHYBA  MCP smazal úvodní stránku"; ERRORS=$((ERRORS+1)); }
mcp vytvor_sablonu '{"nazev":"test-kopie"}' > "$WORK/response"; mcp copy_theme '{"name":"test-kopie2"}' >> "$WORK/response"
[ "$(grep -o 'isError' "$WORK/response" | wc -l | tr -d ' ')" = 2 ] && [ ! -d "$WORK/web/layout/test-kopie" ] && [ ! -d "$WORK/web/layout/test-kopie2" ] && echo "  ok     MCP: vlastní šablony už přes napojení nevznikají" || { echo "  CHYBA  MCP šablony"; ERRORS=$((ERRORS+1)); }
mcp vytvor_kategorii '{"nazev":"Kategorie XSS","popis":"<p>Úvod</p><script>alert(1)</script><img src=x onerror=alert(2)>"}' > /dev/null
check "MCP: popis kategorie se vyčistí" 200 "/novinky/kategorie/kategorie-xss" "Úvod"
! grep -qE '<script>alert|onerror' "$WORK/response" && echo "  ok     MCP: v popisu kategorie nezůstal skript" || { echo "  CHYBA  popis kategorie pustil skript"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_kategorie SET popis = '<p>Stary popis</p><script>alert(3)</script>' WHERE seo_link = 'kategorie-xss'"
check "Uložený starý popis kategorie" 200 "/novinky/kategorie/kategorie-xss" "Stary popis"
! grep -q '<script>alert(3)' "$WORK/response" && echo "  ok     Výpis čistí i dřív uložený popis kategorie" || { echo "  CHYBA  výpis kategorie vypsal skript"; ERRORS=$((ERRORS+1)); }

echo "== části webu v builderu"
check "části webu" 200 "/admin.php?module=parts" "Záhlaví"
check "záhlaví se otevře v builderu s koncept podle šablony" 200 "/admin.php?module=parts&action=builder&typ=hlavicka&jazyk=" 'id="stavitel-data"'
TOKEN=$(csrf)
part_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&typ=$2&jazyk=" -d "_csrf=$TOKEN" "${@:3}"; }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     nepublikované záhlaví kreslí šablona" || { echo "  CHYBA  nepublikované záhlaví je na webu"; ERRORS=$((ERRORS+1)); }
check "náhled konceptu záhlaví pro editor" 200 "/o-nas?cast=hlavicka&stavba=koncept&editor=1" 'data-ka-typ="navigace"'
curl -s -o "$WORK/response" "$B/o-nas?cast=hlavicka&stavba=koncept&editor=1"; ! grep -q 'data-ka-typ' "$WORK/response" && echo "  ok     náhled části nevidí návštěvník" || { echo "  CHYBA  koncept části vidí nepřihlášený"; ERRORS=$((ERRORS+1)); }
expect "publikování záhlaví" "$(part_action build_publish hlavicka)" 200
curl -s -o "$WORK/response" "$B/o-nas"
grep -q 'class="ka-nav"' "$WORK/response" && ! grep -q 'header class="hlavicka"' "$WORK/response" && grep -q 'href="/o-nas" aria-current="page"' "$WORK/response" && echo "  ok     záhlaví z builderu na webu s aktivní položkou menu" || { echo "  CHYBA  záhlaví z builderu"; ERRORS=$((ERRORS+1)); }
[ "$(grep -o '<style>' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && [ "$(grep -o '@layer stavitel {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] && echo "  ok     stránka a části webu mají jedno CSS" || { echo "  CHYBA  CSS částí webu se opakuje"; ERRORS=$((ERRORS+1)); }
WRAPPER='{"v":1,"deti":[{"id":"obs1","typ":"obsah"},{"id":"sek9","typ":"sekce","deti":[{"id":"nad9","typ":"nadpis","obsah":{"text":"Pod článkem"}}]}]}'
check "obálka novinky v builderu" 200 "/admin.php?module=parts&action=builder&typ=novinka&jazyk=" 'id="stavitel-data"'
part_action build_save novinka --data-urlencode "stavba=$WRAPPER" > /dev/null; part_action build_publish novinka > /dev/null
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete"; grep -q 'Pod článkem' "$WORK/response" && grep -q '<main id="obsah" class="stavba">' "$WORK/response" && grep -q 'class="obal obsah"' "$WORK/response" && grep -q 'Vítejte' "$WORK/response" && echo "  ok     obálka kolem novinky" || { echo "  CHYBA  obálka novinky"; ERRORS=$((ERRORS+1)); }
part_action build_save hlavicka --data-urlencode 'stavba={"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"logo"}]}]}' > /dev/null; part_action build_publish hlavicka > /dev/null
expect "předchozí záhlaví je ve verzích" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'hlavicka:'")" 1
part_action template hlavicka > /dev/null
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     vrácení záhlaví na šablonu" || { echo "  CHYBA  vrácení na šablonu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]},"publikovat":true}' > "$WORK/response"
grep -q 'publikováno' "$WORK/response" && echo "  ok     MCP: patička ze stavby" || { echo "  CHYBA  MCP patička"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; grep -q "<p class=\"ka-udaj\">&copy; $(date +%Y) Testovací firma</p>" "$WORK/response" && ! grep -q 'footer class="paticka"' "$WORK/response" && echo "  ok     patička z MCP na webu" || { echo "  CHYBA  patička z MCP na webu"; ERRORS=$((ERRORS+1)); }
expect "autor novinek k částem webu nesmí" "$(curl -s -b "$JAR2" -o /dev/null -w '%{http_code}' "$B/admin.php?module=parts")" 403

echo "== formuláře a poptávky"
# webhook receiver (1.8): logs every call with its signature headers; an address containing "chyba" answers 500
HOOK_PORT=$((PORT + 4)); mkdir -p "$WORK/hook"
cat > "$WORK/hook/router.php" <<'PHP'
<?php
$log = __DIR__ . '/calls.log';
$h = array_change_key_case(getallheaders());
file_put_contents($log, json_encode(['uri' => $_SERVER['REQUEST_URI'], 'event' => $h['x-kaleta-event'] ?? '', 'delivery' => $h['x-kaleta-delivery'] ?? '', 'ts' => $h['x-kaleta-timestamp'] ?? '',
    'sig' => $h['x-kaleta-signature'] ?? '', 'body' => file_get_contents('php://input')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
http_response_code(str_contains($_SERVER['REQUEST_URI'], 'chyba') ? 500 : 204); return true;
PHP
(cd "$WORK/hook" && exec php -S "127.0.0.1:$HOOK_PORT" router.php > /dev/null 2>&1) & HOOK_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$HOOK_PORT/ping" && break; sleep 0.2; done; : > "$WORK/hook/calls.log"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('webhook_enquiries', 'https://hooks.example.com/crm'), ('webhook_test_url', 'http://127.0.0.1:$HOOK_PORT')"
# hook_check <n>: is the n-th logged call signed with the site's secret? prints event|signature ok|uri
hook_check() { php -r '$c = json_decode(explode("\n", trim(file_get_contents($argv[1])))[$argv[2] - 1] ?? "null", true); if (!$c) { echo "none"; exit; }
  echo $c["event"], "|", hash_equals("sha256=" . hash_hmac("sha256", $c["ts"] . "." . $c["body"], $argv[3]), $c["sig"]) ? "signed" : "BAD", "|", $c["uri"];' "$WORK/hook/calls.log" "$1" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_secret'")"; }
curl -s -o "$WORK/formular.html" "$B/kontakt"
grep -q 'class="ka-formular"' "$WORK/formular.html" && grep -q 'name="as_podpis"' "$WORK/formular.html" && echo "  ok     kontakt má poptávkový formulář" || { echo "  CHYBA  formulář na kontaktu"; ERRORS=$((ERRORS+1)); }
field_value() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/formular.html" | head -1 | sed 's/.*value="//;s/"$//'; }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
submit_form() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" "$@"; }
# too fast a submit (autofill): its own code and the message „počkejte chvilku“ (wait a moment), not „nepodařilo se ověřit“ (could not verify)
NOW=$(date +%s); FAST_SIGNATURE=$(php -r 'echo hash_hmac("sha256", $argv[1], $argv[2]);' "formular|$FORM_SOURCE|$FORM_ELEMENT|$NOW" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'secret_key'")")
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$NOW" -d "as_podpis=$FAST_SIGNATURE" -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *vysledek=rychle*) echo "  ok     příliš rychlé odeslání má vlastní výsledek";; *) echo "  CHYBA  příliš rychlé odeslání formuláře"; ERRORS=$((ERRORS+1));; esac
check "hlášení po příliš rychlém odeslání radí počkat" 200 "/kontakt?formular=$FORM_ELEMENT&vysledek=rychle" "Počkejte prosím chvilku a odešlete ho znovu"
grep -q 'type="text" autocomplete="name"' "$WORK/formular.html" && grep -q 'type="tel" autocomplete="tel" maxlength="30" pattern="' "$WORK/formular.html" && echo "  ok     jméno s automatickým vyplněním, telefon s kontrolou v prohlížeči" || { echo "  CHYBA  autocomplete jména nebo vzor telefonu"; ERRORS=$((ERRORS+1)); }
grep -q 'name="as_cas" value="[0-9]*" data-cekat="4"' "$WORK/formular.html" && echo "  ok     formulář nese minimální dobu pro odložené odeslání" || { echo "  CHYBA  data-cekat u formuláře"; ERRORS=$((ERRORS+1)); }
sleep 4
location=$(submit_form -H "Referer: $B/kontakt?utm_source=newsletter&utm_medium=email&utm_campaign=jaro" -d p0=Jana --data-urlencode p1=jana@example.cz -d p2= --data-urlencode "p3=Chci kuchyň na míru." -d p4=1)
case "$location" in *"/kontakt?formular=$FORM_ELEMENT&vysledek=ok#"*"$FORM_ELEMENT") echo "  ok     odeslání formuláře";; *) echo "  CHYBA  odeslání formuláře: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), '/', MAX(email), '/', MAX(stav)) FROM ka_poptavky")" "1/jana@example.cz/0"
expect "webhook: new enquiry delivered after the response, signed" "$(hook_check 1)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(event, '/', status, '/', delivered IS NOT NULL, '/', body IS NULL) FROM ka_webhook_deliveries")" "nova_poptavka|signed|/crm|nova_poptavka/204/1/1"
grep -q 'email.":."jana@example.cz' "$WORK/hook/calls.log" && echo "  ok     webhook: the enquiry data are in the body" || { echo "  CHYBA  webhook body: $(cat "$WORK/hook/calls.log")"; ERRORS=$((ERRORS+1)); }
expect "poptávka nese kampaň z utm_* stránky s formulářem" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT kampan FROM ka_poptavky")" "utm_source=newsletter&utm_medium=email&utm_campaign=jaro"
case "$(submit_form -d p0=Jana -d p1=neni-email -d p3=x -d p4=1)" in *vysledek=pole\&pole=1*) echo "  ok     neplatný e-mail odmítnut s číslem pole";; *) echo "  CHYBA  validace e-mailu"; ERRORS=$((ERRORS+1));; esac
curl -s -o "$WORK/response" "$B/kontakt?formular=$FORM_ELEMENT&vysledek=pole&pole=1"
grep -q 'aria-invalid="true" aria-describedby="f-'"$FORM_ELEMENT"'-1-chyba"' "$WORK/response" && grep -q 'data-obnovit' "$WORK/response" && echo "  ok     chybné pole je označené a vyplněné hodnoty se obnoví" || { echo "  CHYBA  označení chybného pole"; ERRORS=$((ERRORS+1)); }
case "$(submit_form -d p0=Jana --data-urlencode p1=jana@example.cz -d p3=x)" in *vysledek=pole*) echo "  ok     chybějící souhlas odmítnut";; *) echo "  CHYBA  povinný souhlas"; ERRORS=$((ERRORS+1));; esac
submit_form -d p0=Robot --data-urlencode p1=r@example.cz -d p3=spam -d p4=1 -d web_adresa=http://spam.example > /dev/null
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/kontakt -d "as_cas=$FORM_TIME" -d as_podpis=podvrh -d p0=A -d p1=a@example.cz -d p3=x -d p4=1)" in *vysledek=overeni*) echo "  ok     podvržený podpis odmítnut";; *) echo "  CHYBA  podpis formuláře"; ERRORS=$((ERRORS+1));; esac
case "$(submit_form -d zdroj=stranka:999 -d p0=A)" in *formular=*) echo "  CHYBA  neexistující formulář přijat"; ERRORS=$((ERRORS+1));; *) echo "  ok     neexistující formulář nic neuloží";; esac
expect "robot ani chyby poptávku nepřidaly" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_poptavky")" 1
IDP=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_poptavky")
check "poptávky v administraci" 200 "/admin.php?module=enquiries" "jana@example.cz"
check "detail poptávky" 200 "/admin.php?module=enquiries&action=detail&id=$IDP" "Chci kuchyň na míru."
grep -q '>Tester</option>' "$WORK/response" && ! grep -q '>Autor</option>' "$WORK/response" && echo "  ok     poptávku vyřizuje jen ten, kdo má přístup k Poptávkám" || { echo "  CHYBA  výběr Vyřizuje nabízí uživatele bez přístupu k Poptávkám"; ERRORS=$((ERRORS+1)); }
expect "otevřená poptávka je přečtená" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_poptavky")" 1
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=enquiries&action=csv"; grep -q 'Chci kuchyň na míru.' "$WORK/response" && echo "  ok     export poptávek do CSV" || { echo "  CHYBA  CSV poptávek"; ERRORS=$((ERRORS+1)); }
check "poděkování po odeslání (na místě formuláře)" 200 "/kontakt?formular=$FORM_ELEMENT&vysledek=ok" 'class="ka-formular-hotovo"'

echo "== kolekce"
check "kolekce" 200 "/admin.php?module=collections" "Kolekce"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save" -d "_csrf=$TOKEN" -d idk=0 --data-urlencode "nazev=Tým" -d detail=1 \
  --data-urlencode "pole[0][popisek]=Funkce" -d "pole[0][typ]=text" --data-urlencode "pole[1][popisek]=Foto" -d "pole[1][typ]=obrazek" --data-urlencode "pole[2][popisek]=Medailonek" -d "pole[2][typ]=html"
IDK=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
expect "kolekce založena s poli" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT pole LIKE '%\"funkce\"%' AND pole LIKE '%\"medailonek\"%' FROM ka_kolekce WHERE idk = $IDK")" 1
save_item() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$IDK" -d idp=0 "$@"; }
save_item --data-urlencode "nazev=Jana Nováková" --data-urlencode "data[funkce]=Jednatelka" --data-urlencode "data[medailonek]=<p>Dvacet let <b>v oboru</b>.</p><script>x</script>" -d poradi=1 -d zobrazit=1
save_item --data-urlencode "nazev=Skrytý Člen" --data-urlencode "data[funkce]=Tajný" -d poradi=2
check "položky kolekce" 200 "/admin.php?module=collections&action=items&id=$IDK" "Jana Nováková"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"smy1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\"},\"deti\":[{\"id\":\"kar1\",\"typ\":\"kontejner\",\"styl\":{\"zaklad\":{\"pozadi\":\"plocha\"}},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}},{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>{{funkce}}</p>{{medailonek}}\"}},{\"typ\":\"tlacitko\",\"obsah\":{\"text\":\"Profil\",\"odkaz\":\"{{url}}\"}}]}]}]}]}}" > "$WORK/response"
grep -q 'publikováno' "$WORK/response" || { echo "  CHYBA  MCP stránka s výpisem kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
grep -q '<h3>Jana Nováková</h3>' "$WORK/response" && grep -q '<p>Jednatelka</p>' "$WORK/response" && grep -q '^<p>Dvacet let <b>v oboru</b>.</p>' "$WORK/response" && grep -q 'href="/tym/jana-novakova"' "$WORK/response" && ! grep -q 'Skrytý' "$WORK/response" \
  && echo "  ok     výpis kolekce na stránce (jen zveřejněné položky, hodnoty dosazené)" || { echo "  CHYBA  výpis kolekce"; ERRORS=$((ERRORS+1)); }
grep -q 'class="s-kar1"' "$WORK/response" && ! grep -q 'id="s-kar1"' "$WORK/response" && grep -q '\.s-kar1 { background-color' "$WORK/response" && ! grep -q '<script>x' "$WORK/response" \
  && echo "  ok     opakované prvky mají styl přes třídu, ne duplicitní id" || { echo "  CHYBA  styl ve výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "detail položky kolekce" 200 /tym/jana-novakova "Jednatelka"
check "detail má nadpis položky" 200 /tym/jana-novakova "<h1>Jana Nováková</h1>"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/tym/skryty-clen"); expect "skrytá položka nemá detail" "$code" 404
check "mapa webu obsahuje detail položky" 200 /sitemap.xml "/tym/jana-novakova"
check "šablona detailu v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK" 'id="stavitel-data"'
mcp seznam_kolekci '{}' > "$WORK/response"; grep -q 'kolekce\\":\\"tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: seznam kolekcí s poli" || { echo "  CHYBA  MCP seznam_kolekci"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Petr Svoboda","data":{"funkce":"Mistr truhlář"},"zobrazit":true}' > /dev/null
mcp save_collection_item '{"collection":"tym","name":"Text JSON","values":"{\"funkce\":\"Z textu\"}"}' > "$WORK/response"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Z textu"}' | grep -q 'Text JSON' && echo "  ok     MCP: data poslaná jako text JSON se uloží" || { echo "  CHYBA  MCP data jako text JSON"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_menu '{"umisteni":"hlavni","polozky":"nejde precist"}' | grep -q 'musí být seznam' && echo "  ok     MCP: nečitelné položky menu jsou chyba, menu se nevrátí na automatické" || { echo "  CHYBA  MCP nečitelné položky menu"; ERRORS=$((ERRORS+1)); }
mcp save_classes '{"classes":[{"name":"x"}]}' | grep -q 'unknown_parameters' && echo "  ok     MCP: neznámý parametr je ve výsledku, ne tiše vynechaný" || { echo "  CHYBA  MCP neznámé parametry"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Spatna data","data":"funkce=x"}' | grep -q 'musí být objekt' && echo "  ok     MCP: nečitelná data položky jsou chyba, ne tiché vynechání" || { echo "  CHYBA  MCP nečitelná data položky"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman","adresa":"zdenek","zobrazit":true}' > "$WORK/response"
grep -q 'tym\\/zdenek' "$WORK/response" && echo "  ok     MCP: vlastní adresa položky" || { echo "  CHYBA  MCP adresa položky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}}]}]}}' > "$WORK/response"
PREVIEW=$(php -r '$o = json_decode(file_get_contents($argv[1]), true); echo json_decode($o["result"]["content"][0]["text"] ?? "{}", true)["nahled"] ?? "";' "$WORK/response")
[ -n "$PREVIEW" ] && curl -s "$PREVIEW" | grep -q 'Profil: ' && echo "  ok     MCP: šablona detailu kolekce jako koncept s podepsaným náhledem" || { echo "  CHYBA  MCP šablona detailu kolekce"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s "$B/tym/zdenek" | contains 'Profil: ' && { echo "  CHYBA  koncept šablony kolekce je vidět bez publikování"; ERRORS=$((ERRORS+1)); } || echo "  ok     koncept šablony kolekce návštěvník nevidí"
mcp publikuj_stavbu '{"kolekce":"tym"}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: publikovaná šablona detailu kolekce" 200 /tym/zdenek "Profil: Zdenek Zeman"
check "llms.txt vyjmenuje položky kolekcí s detailem" 200 /llms.txt "/tym/zdenek"
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zuzana Zelena","data":{"funkce":"Jednatelka"},"zobrazit":true}' > /dev/null
mcp stavba_uloz '{"kolekce":"tym","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profil: {{nazev}}"}},{"typ":"kolekce","obsah":{"kolekce":"tym","filtr_pole":"funkce","filtr_hodnota":"{{funkce}}","bez_aktualni":true},"deti":[{"typ":"nadpis","znacka":"h3","obsah":{"text":"Kolega: {{nazev}}"}}]}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/tym/jana-novakova"
grep -q 'Kolega: Zuzana Zelena' "$WORK/response" && ! grep -q 'Kolega: Jana' "$WORK/response" && ! grep -q 'Kolega: Petr' "$WORK/response" && ! curl -s "$B/tym/petr-svoboda" | contains 'Kolega: Zuzana' \
    && echo "  ok     související položky: filtr podle pole zobrazené položky, bez ní samotné" || { echo "  CHYBA  související položky kolekce"; ERRORS=$((ERRORS+1)); }
# a collection in several languages: an item's translation has the same slug, another language its own item template, breadcrumbs lead to the translation of the hub page
mcp vytvor_stranku '{"titulek":"Náš tým","adresa":"tym","text":"<p>Tým</p>","zobrazit":true}' > /dev/null; IDTYM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'tym'")
mcp vytvor_stranku "{\"titulek\":\"Our team\",\"adresa\":\"team\",\"jazyk\":\"en\",\"preklad_z\":$IDTYM,\"text\":\"<p>Team</p>\",\"zobrazit\":true}" > /dev/null
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Zdenek Zeman EN","adresa":"zdenek","jazyk":"en","data":{"funkce":"Workshop lead"},"zobrazit":true}' > "$WORK/response"
grep -q 'en\\/tym\\/zdenek\\"' "$WORK/response" && mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Druhy Zdenek","adresa":"zdenek","jazyk":"en"}' | grep -q 'tym\\/zdenek-2' \
  && echo "  ok     adresa položky je jedinečná v jazyce (překlad smí mít stejnou)" || { echo "  CHYBA  adresa položky v jiném jazyce"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s "$B/en/tym/zdenek" | contains 'Profil: Zdenek Zeman EN' && echo "  ok     jazyk bez vlastní šablony použije šablonu výchozího jazyka, koncept je skrytý" || { echo "  CHYBA  šablona detailu jazyka bez publikování"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu '{"kolekce":"tym","jazyk":"en"}' > /dev/null; mcp stavba_uloz '{"kolekce":"tym","jazyk":"en","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","deti":[{"typ":"drobecky"},{"typ":"nadpis","znacka":"h1","obsah":{"text":"Profile: {{nazev}}"}}]}]}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/en/tym/zdenek"
grep -q '<h1>Profile: Zdenek Zeman EN</h1>' "$WORK/response" && grep -q 'href="/en/team">Our team</a>' "$WORK/response" && grep -q 'hreflang="cs" href="[^"]*/tym/zdenek"' "$WORK/response" \
  && curl -s "$B/tym/zdenek" | contains 'Profil: Zdenek Zeman<' && curl -s "$B/tym/zdenek" | contains 'hreflang="en" href="[^"]*/en/tym/zdenek"' \
  && echo "  ok     šablona detailu v jazyce, drobečky přes překlad rozcestníku, hreflang mezi překlady položky" || { echo "  CHYBA  kolekce ve více jazycích"; grep -o '<nav class="ka-drobecky.\{0,300\}' "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -q 'class="logo"[^>]*><img src="/image/kaleta-logo.svg"' "$WORK/response" && ! grep -q 'src="/en/image/' "$WORK/response" && echo "  ok     logo a obrázky šablony na jazykové verzi bez předpony jazyka" || { echo "  CHYBA  adresa loga s předponou jazyka"; ERRORS=$((ERRORS+1)); }
expect "verze šablony jazyka zvlášť" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'kolekce:$IDK:en'")" 1
mcp seznam_stranek '{}' | grep -q 'en\\/team' && echo "  ok     MCP: seznam stránek ukazuje adresu s předponou jazyka" || { echo "  CHYBA  MCP adresa stránky jazykové verze"; ERRORS=$((ERRORS+1)); }
check "šablona detailu jazyka v builderu" 200 "/admin.php?module=collections&action=builder&id=$IDK&jazyk=en" 'en\/tym\/zdenek'
# translation via MCP: the page as a copy of the original's build, texts by id, the language's header and footer start as a copy of the default one
mcp vytvor_stranku '{"titulek":"Bez originalu","adresa":"bez-originalu","kopie_stavby":true}' | grep -q 'potřebuje preklad_z' \
  && expect "kopie stavby bez originálu stránku nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE seo_link = 'bez-originalu'")" 0 || { echo "  CHYBA  kopie stavby bez preklad_z"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"From HTML\",\"adresa\":\"from-html\",\"jazyk\":\"en\",\"preklad_z\":$IDZ,\"kopie_stavby\":true}" > /dev/null
IDZEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'from-html'")
expect "překlad stránky začíná kopií stavby originálu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT n.stavba_koncept = COALESCE(o.stavba_koncept, o.stavba) FROM ka_stranky n JOIN ka_stranky o ON o.ids = n.preklad_z WHERE n.ids = $IDZEN")" 1
mcp get_build "{\"id\":$IDZEN,\"texts_only\":true}" > "$WORK/response"
grep -q 'texts' "$WORK/response" && grep -q '{{nazev}}' "$WORK/response" && ! grep -q '\\"build\\"' "$WORK/response" && ! grep -q 'kolekce\\":\\"tym' "$WORK/response" \
  && echo "  ok     MCP: jen texty stavby pro překlad (bez struktury a technických polí)" || { echo "  CHYBA  MCP texty stavby"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp uloz_polozku_kolekce '{"kolekce":"tym","nazev":"Klic navic","data":{"funkce":"x","nazev":"Jinak"}}' | grep -q 'nezname_klice.*nazev' \
  && echo "  ok     MCP: klíč, který kolekce nemá, je ve výsledku" || { echo "  CHYBA  MCP neznámý klíč položky"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku '{"titulek":"Skryta textem","adresa":"skryta-textem","zobrazit":"false"}' > /dev/null
expect "MCP: zobrazit poslané jako text „false“ nechá stránku skrytou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'skryta-textem'")" 0
mcp stavba_nacti '{"cast":"paticka","jazyk":"en"}' > /dev/null
expect "MCP: čtení části, která ještě není, nic nezaloží" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE typ = 'paticka' AND jazyk = 'en'")" 0
mcp stavba_uprav '{"cast":"paticka","jazyk":"en","operace":[]}' > /dev/null
expect "patička nového jazyka začíná kopií patičky výchozího jazyka" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT e.stavba_koncept = COALESCE(c.stavba_koncept, c.stavba) FROM ka_casti e JOIN ka_casti c ON c.typ = e.typ AND c.jazyk = '' AND c.varianta = '' WHERE e.typ = 'paticka' AND e.jazyk = 'en' AND e.varianta = ''")" 1
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('tasks_token', 'testtoken123'); INSERT INTO ka_souhlasy (id_souhlasu, cas, kategorie) VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', NOW() - INTERVAL 40 MONTH, 'nic'), ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', NOW(), 'nic')"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "úklid maže staré záznamy o souhlasech s cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(LEFT(id_souhlasu, 1) ORDER BY id_souhlasu) FROM ka_souhlasy WHERE id_souhlasu IN ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')")" "b"
echo 'ALTER TABLE ka_neexistuje ADD COLUMN x INT;' > "$WORK/web/system/sql/migrace/0099-rozbita.sql"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '19' WHERE promenna = 'db_version'"; rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK/web/storage/cache/migrace-chyba"
check "nepovedená migrace neshodí web" 200 /
grep -q 'Migrace databáze se nepovedla' "$WORK/web/storage/log/chyby.log" && echo "  ok     nepovedená migrace je v protokolu chyb" || { echo "  CHYBA  nepovedená migrace chybí v protokolu"; ERRORS=$((ERRORS+1)); }
check "nepovedená migrace nezamkne administraci" 200 "/admin.php" "Aktualizace databáze se nepovedla"
rm -f "$WORK/web/system/sql/migrace/0099-rozbita.sql"; sed -i.bak "/Migrace databáze se nepovedla/d" "$WORK/web/storage/log/chyby.log"; rm -f "$WORK/web/storage/log/chyby.log.bak"
expect "migrace, které prošly, zůstanou provedené" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "$LAST_MIGRATION"
mcp uprav_kolekci '{"kolekce":"tym","nazev":"Nas tym"}' > "$WORK/response"
grep -q 'Nas tym' "$WORK/response" && grep -q 'medailonek' "$WORK/response" && echo "  ok     MCP: úprava kolekce ponechá pole" || { echo "  CHYBA  MCP uprav_kolekci"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "MCP: nová položka je ve výpisu" 200 /z-html "Mistr truhlář"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"vyp1\",\"typ\":\"kolekce\",\"obsah\":{\"kolekce\":\"tym\",\"pocet\":1,\"razeni\":\"nazev\",\"filtr_pole\":\"funkce\",\"filtry\":true,\"strankovani\":true},\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h3\",\"obsah\":{\"text\":\"{{nazev}}\"}}]}]}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
[ "$(grep -o '<h3>[^<]*</h3>' "$WORK/response" | tr -d '\n')" = "<h3>Jana Nováková</h3>" ] && grep -q 'href="/z-html?s-vyp1=2"' "$WORK/response" && grep -q 'href="/z-html" aria-current="true">Vše' "$WORK/response" && grep -q 'f-vyp1=Mistr' "$WORK/response" \
  && echo "  ok     výpis kolekce: řazení, stránkování a tlačítka filtru" || { echo "  CHYBA  stránkování výpisu kolekce"; ERRORS=$((ERRORS+1)); }
check "výpis kolekce: druhá strana" 200 "/z-html?s-vyp1=2" "<h3>Petr Svoboda</h3>"
curl -s -o "$WORK/response" "$B/z-html?f-vyp1=Mistr+truhl%C3%A1%C5%99"; grep -q '<h3>Petr Svoboda</h3>' "$WORK/response" && ! grep -q '<h3>Jana' "$WORK/response" && grep -q 'aria-current="true">Mistr truhlář' "$WORK/response" \
  && echo "  ok     výpis kolekce: filtr návštěvníka" || { echo "  CHYBA  filtr výpisu kolekce"; ERRORS=$((ERRORS+1)); }

echo "== komponenty"
check "komponenty" 200 "/admin.php?module=components" "Komponenty"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=components&action=save" -d "_csrf=$TOKEN" -d idm=0 --data-urlencode "nazev=Karta služby" \
  --data-urlencode "vlastnosti[0][popisek]=Nadpis" -d "vlastnosti[0][typ]=text" --data-urlencode "vlastnosti[0][vychozi]=Výchozí nadpis" --data-urlencode "vlastnosti[1][popisek]=Odkaz" -d "vlastnosti[1][typ]=odkaz" -d "vlastnosti[1][vychozi]=/kontakt"
IDM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty ORDER BY idm DESC LIMIT 1")
component_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=$1&id=$IDM" -d "_csrf=$TOKEN" "${@:2}"; }
check "komponenta v builderu" 200 "/admin.php?module=components&action=builder&id=$IDM" 'id="stavitel-data"'
check "2.4: builder links to its guide article" 200 "/admin.php?module=components&action=builder&id=$IDM" '"navod":"https:[^"]*guide[^"]*components"'
check "2.4: settings tab links to its guide article" 200 "/admin.php?module=settings&tab=backups" 'class="navod-odkaz" href="https://kaletacms.com/[a-z/]*guide/backups-updates"'
check "2.4: dashboard links to the guide" 200 "/admin.php" 'guide/first-steps#the-dashboard'
component_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kna1","typ":"nadpis","znacka":"h3","obsah":{"text":"{{nadpis}}"},"styl":{"zaklad":{"barva":"primarni"}}},{"typ":"tlacitko","obsah":{"text":"Více","odkaz":"{{odkaz}}"}},{"typ":"komponenta","obsah":{"komponenta":"'"$IDM"'"}}]}]}' > /dev/null
expect "publikování komponenty" "$(component_action build_publish)" 200
check "náhled komponenty pro editor" 200 "/_komponenta/$IDM?stavba=koncept&editor=1" "Výchozí nadpis"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\",\"hodnoty\":{\"nadpis\":\"První <b>karta</b>\",\"odkaz\":\"javascript:alert(1)\"}}},{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$IDM\"}}]}}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" -w '' "$B/z-html"
grep -q '<h3 class="s-kna1">První karta</h3>' "$WORK/response" && grep -q '<h3 class="s-kna1">Výchozí nadpis</h3>' "$WORK/response" && [ "$(grep -o 'href="/kontakt"' "$WORK/response" | wc -l | tr -d ' ')" -ge 1 ] && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     komponenta na stránce: vlastní i výchozí hodnoty, bez značek, nebezpečný odkaz pryč" || { echo "  CHYBA  komponenta na stránce"; ERRORS=$((ERRORS+1)); }
! grep -q 'id="s-kna1"' "$WORK/response" && ! grep -q 'data-ka-id' "$WORK/response" && [ "$(grep -o '\.s-kna1 {' "$WORK/response" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     komponenta dvakrát na stránce: styl jednou, bez duplicitního id" || { echo "  CHYBA  styl komponenty"; ERRORS=$((ERRORS+1)); }
check "komponenty ukazují počet použití" 200 "/admin.php?module=components" "1×"
grep -q 'data-potvrdit="Komponentu „Karta služby“ používá: stránka „' "$WORK/response" && echo "  ok     potvrzení smazání komponenty vyjmenuje, kde je použitá" || { echo "  CHYBA  potvrzení smazání komponenty"; ERRORS=$((ERRORS+1)); }
# a form inside a component: the submit must find it (it used to be searched only in the page build)
component_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"kse1","typ":"sekce","deti":[{"id":"kfo1","typ":"formular","obsah":{"nazev":"Poptávka z komponenty"}}]}]}' > /dev/null; component_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/z-html"
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$(field_value zdroj)" -d prvek=kfo1 -d zpet=/z-html -d "as_cas=$(field_value as_cas)" -d "as_podpis=$(field_value as_podpis)")
case "$location" in *"formular=kfo1"*) echo "  ok     formulář v komponentě se odešle";; *) echo "  CHYBA  formulář v komponentě: $location"; ERRORS=$((ERRORS+1));; esac
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=components&action=from_element" -d "_csrf=$TOKEN" --data-urlencode "nazev=Výzva" --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Zavolejte nám"}}]}')
[ "$code" = 200 ] && grep -q '"ok":true' "$WORK/response" && echo "  ok     uložení prvku jako komponenty" || { echo "  CHYBA  z_prvku: $code"; ERRORS=$((ERRORS+1)); }

check "náhled hotové sekce pro panel builderu" 200 /_sekce/cenik "Vyberte si balíček"
expect "náhled sekce jen pro přihlášené" "$(curl -s -o /dev/null -w '%{http_code}' "$B/_sekce/cenik")" 404

echo "== varianty záhlaví"
check "formulář varianty" 200 "/admin.php?module=parts&action=variant&typ=hlavicka&jazyk=" 'Název varianty'
TOKEN=$(csrf)
location=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=parts&action=save_variant&typ=hlavicka&jazyk=" -d "_csrf=$TOKEN" --data-urlencode "nazev=Landing page" -d "stranky[]=$IDZ")
case "$location" in *"varianta=landing-page"*) echo "  ok     varianta založena a otevřena v builderu";; *) echo "  CHYBA  založení varianty: $location"; ERRORS=$((ERRORS+1));; esac
variant_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=parts&action=$1&typ=hlavicka&jazyk=&varianta=landing-page" -d "_csrf=$TOKEN" "${@:2}"; }
variant_action build_save --data-urlencode 'stavba={"v":1,"deti":[]}' > /dev/null
expect "publikování varianty" "$(variant_action build_publish)" 200
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; ! grep -q 'header class="hlavicka"' "$WORK/response" && ! grep -q 'ka-nav' "$WORK/response" && echo "  ok     stránka s prázdnou variantou je bez záhlaví" || { echo "  CHYBA  varianta záhlaví na stránce"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kontakt"; grep -q 'header class="hlavicka"' "$WORK/response" && echo "  ok     ostatní stránky mají výchozí záhlaví" || { echo "  CHYBA  varianta se projevila i jinde"; ERRORS=$((ERRORS+1)); }
check "varianta v seznamu částí" 200 "/admin.php?module=parts" "Landing page"

echo "== Claude (MCP): varianty, verze, stránky a poptávky jako v administraci"
# a value from an MCP response: mcpv key [key…] (arrays and objects as JSON)
mcp_value() { php -r '$v = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"], true); foreach (array_slice($argv, 2) as $k) { $v = $v[$k] ?? null; } echo is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$WORK/response" "$@"; }
mcp seznam_casti '{}' > "$WORK/response"; [[ "$(mcp_value)" == *'"varianta":"landing-page"'* ]] && echo "  ok     MCP: seznam částí webu s variantami" || { echo "  CHYBA  MCP seznam_casti"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"nazev\":\"Kampaň\",\"stranky\":[$IDZ]}" > "$WORK/response"; VARIANT=$(mcp_value varianta)
expect "MCP: varianta patičky založena" "$VARIANT|$(mcp_value stranky)" "kampan|[$IDZ]"
mcp stavba_uloz "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"znacka\":\"footer\",\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"p\",\"obsah\":{\"text\":\"Paticka kampane\"}}]}]}}" > "$WORK/response"
VARIANT_PREVIEW=$(mcp_value nahled); curl -s -o "$WORK/response" "$VARIANT_PREVIEW"
[[ "$VARIANT_PREVIEW" == *"varianta=$VARIANT"* ]] && grep -q 'Paticka kampane' "$WORK/response" && echo "  ok     MCP: podepsaný náhled konceptu varianty" || { echo "  CHYBA  náhled varianty: $VARIANT_PREVIEW"; ERRORS=$((ERRORS+1)); }
mcp publikuj_stavbu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\"}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"; grep -q 'Paticka kampane' "$WORK/response" && ! curl -s "$B/kontakt" | contains 'Paticka kampane' && echo "  ok     MCP: publikovaná varianta patičky jen na vybrané stránce" || { echo "  CHYBA  varianta patičky z MCP"; ERRORS=$((ERRORS+1)); }
mcp uloz_variantu "{\"cast\":\"paticka\",\"varianta\":\"$VARIANT\",\"smazat\":true}" > /dev/null
expect "MCP: varianta smazána" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_casti WHERE varianta = '$VARIANT'")" 0
mcp stavba_z_html '{"titulek":"Verze test","html":"<section><h1>Verze A</h1></section>","publikovat":true}' > /dev/null
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'verze-test'")
mcp stavba_z_html "{\"id\":$IDV,\"html\":\"<section><h1>Verze B</h1></section>\",\"publikovat\":true}" > /dev/null
mcp stavba_verze "{\"id\":$IDV}" > "$WORK/response"; IDR=$(mcp_value verze 0 idr)
mcp obnov_verzi "{\"id\":$IDV,\"idr\":$IDR}" > /dev/null
expect "MCP: starší verze v konceptu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(stavba_koncept LIKE '%Verze A%', stavba LIKE '%Verze B%') FROM ka_stranky WHERE ids = $IDV")" 11
mcp zahod_koncept "{\"id\":$IDV}" > /dev/null
expect "MCP: koncept zahozen" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept IS NULL FROM ka_stranky WHERE ids = $IDV")" 1
mcp vytvor_stranku "{\"titulek\":\"Podstranka MCP\",\"nadrazena\":$IDS,\"zverejnit_od\":\"2099-01-01 10:00\"}" > "$WORK/response"
expect "MCP: podstránka s plánovaným zveřejněním" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(seo_link, '|', zverejnit_od, '|', zobrazit) FROM ka_stranky WHERE titulek = 'Podstranka MCP'")" "o-nas/podstranka-mcp|2099-01-01 10:00:00|0"
mcp seznam_poptavek '{"stav":"vse"}' > "$WORK/response"
expect "MCP: poptávky s kampaní" "$(mcp_value 0 email)|$(mcp_value 0 kampan)" "jana@example.cz|newsletter / email / jaro"

echo "== Claude (MCP): trash, deleting and the rest of the admin (1.6)"
sq() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "annotations", "name"); exit($t["list_pages"]["readOnlyHint"] === true && $t["trash_page"]["destructiveHint"] === true && $t["create_page"]["readOnlyHint"] === false && $t["delete_collection"]["destructiveHint"] === true ? 0 : 1);' "$WORK/response" \
  && echo "  ok     MCP: tools carry annotations (read-only, destructive)" || { echo "  CHYBA  MCP annotations"; ERRORS=$((ERRORS+1)); }
mcp site_info '{}' > "$WORK/response"
expect "MCP: site_info lists extensions and languages" "$(mcp_value extensions | grep -c novinky)|$(mcp_value languages default)" "1|cs"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema in the English vocabulary" "$(mcp_value elements heading | grep -c 'content: text')|$(mcp_value style gap | grep -c gap)|$(mcp_value states 2)" "1|1|mobile"
mcp builder_schema '{"elements":["form"]}' > "$WORK/response"
expect "MCP: full definition of an element by its English type" "$(mcp_value elements 0 type)|$(mcp_value elements 0 fields fields item_fields type options 5)" "form|radio"
VPAGE=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL ORDER BY ids LIMIT 1")
mcp save_build "{\"id\":$VPAGE,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Go\",\"variant\":\"outline\",\"icon\":\"arrow\"},\"style\":{\"mobile\":{\"gap\":\"s\",\"background\":\"primary-soft\"}}}]}]}}" > /dev/null
expect "MCP: an English build is stored in the Czech keys" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].typ')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.varianta')), JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.mobil.pozadi')) FROM ka_stranky WHERE ids = $VPAGE" | tr '\t' '|')" "tlacitko|obrys|primarni-jemna"
mcp get_build "{\"id\":$VPAGE}" > "$WORK/response"
expect "MCP: get_build answers in English" "$(mcp_value build children 0 children 0 type)|$(mcp_value build children 0 children 0 content variant)|$(mcp_value build children 0 children 0 style mobile background)" "button|outline|primary-soft"
BUTTON=$(mcp_value build children 0 children 0 id)
mcp edit_build "{\"id\":$VPAGE,\"operations\":[{\"op\":\"update\",\"id\":\"$BUTTON\",\"content\":{\"new_window\":true},\"style\":{\"base\":{\"radius\":\"full\"}}}]}" > /dev/null
expect "MCP: edit_build takes English content and style" "$(sq "SELECT CONCAT(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].obsah.nove_okno'), '|', JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, '$.deti[0].deti[0].styl.zaklad.zaobleni'))) FROM ka_stranky WHERE ids = $VPAGE")" "true|plne"
mcp discard_draft "{\"id\":$VPAGE}" > /dev/null
mcp create_collection '{"name":"Kos test","fields":[{"label":"Popis","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"kos-test","name":"Polozka","visible":true}' > "$WORK/response"; ITEM=$(mcp_value id)
mcp delete_collection_item "{\"collection\":\"kos-test\",\"id\":$ITEM}" > /dev/null
expect "MCP: a collection item goes to the trash, hidden" "$(sq "SELECT CONCAT(smazano IS NOT NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
check "collection trash in the admin" 200 "/admin.php?module=collections&action=items&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'kos-test'")&stav=kos" "Polozka"
mcp list_trash '{}' > "$WORK/response"
expect "MCP: list_trash shows the item" "$(mcp_value collection_items 0 name)" "Polozka"
mcp save_collection_item "{\"collection\":\"kos-test\",\"id\":$ITEM,\"visible\":true}" | grep -q 'is in the trash' && [ "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $ITEM")" = 0 ] \
  && echo "  ok     MCP: an item in the trash cannot be published by saving it (1.9)" || { echo "  CHYBA  MCP saved an item from the trash"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items '{"collection":"kos-test"}' | grep -q 'Polozka' && { echo "  CHYBA  list_collection_items lists the trash"; ERRORS=$((ERRORS+1)); } || echo "  ok     list_collection_items leaves the trash out"
mcp restore_from_trash "{\"type\":\"collection_item\",\"id\":$ITEM}" > /dev/null
expect "MCP: restored from the trash as hidden" "$(sq "SELECT CONCAT(smazano IS NULL, zobrazit) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "10"
mcp delete_collection '{"collection":"kos-test"}' > /dev/null
expect "MCP: delete_collection removes it with its items" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'kos-test'")|$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE idp = $ITEM")" "0|0"
CATEGORY=$(sq "SELECT nazev FROM ka_kategorie WHERE jazyk = '' ORDER BY idt LIMIT 1")
mcp create_news "{\"title\":\"Do kose\",\"category\":\"$CATEGORY\"}" > "$WORK/response"; NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Do kose'")
mcp trash_news "{\"id\":$NEWS}" > /dev/null
expect "MCP: trash_news" "$(sq "SELECT smazano IS NOT NULL FROM ka_novinky WHERE idc = $NEWS")" "1"
mcp restore_from_trash "{\"type\":\"news\",\"id\":$NEWS}" > /dev/null
expect "MCP: a news item back from the trash as a draft" "$(sq "SELECT CONCAT(smazano IS NULL, visible) FROM ka_novinky WHERE idc = $NEWS")" "10"
mcp create_category '{"name":"Docasna"}' > /dev/null; CAT=$(sq "SELECT idt FROM ka_kategorie WHERE nazev = 'Docasna'")
mcp update_category "{\"id\":$CAT,\"name\":\"Docasna 2\",\"slug\":\"docasna-2\"}" > /dev/null
expect "MCP: update_category renames and redirects the old address" "$(sq "SELECT CONCAT(nazev, '|', seo_link) FROM ka_kategorie WHERE idt = $CAT")|$(sq "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy LIKE '%kategorie/docasna'")" "Docasna 2|docasna-2|1"
mcp delete_category "{\"id\":$(sq "SELECT tema FROM ka_novinky WHERE idc = $NEWS")}" | contains 'still has news items' && echo "  ok     MCP: a category with news items is not deleted" || { echo "  CHYBA  MCP: delete_category of a used category"; ERRORS=$((ERRORS+1)); }
mcp delete_category "{\"id\":$CAT}" > /dev/null
expect "MCP: delete_category" "$(sq "SELECT COUNT(*) FROM ka_kategorie WHERE idt = $CAT")" "0"
mcp save_component '{"name":"Karta","properties":[{"klic":"titulek","popisek":"Titulek","typ":"text","vychozi":"Ahoj"}]}' > "$WORK/response"; COMP=$(mcp_value id)
mcp save_build "{\"component\":$COMP,\"build\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"{{titulek}}\"}}]}]}}" > /dev/null
mcp publish_build "{\"component\":$COMP}" > /dev/null
expect "MCP: a component built and published through the component target" "$(sq "SELECT stavba LIKE '%{{titulek}}%' AND stavba_koncept IS NULL FROM ka_komponenty WHERE idm = $COMP")" "1"
mcp list_components '{}' > "$WORK/response"
expect "MCP: list_components" "$(mcp_value 0 name)|$(mcp_value 0 published)" "Karta|1"
mcp delete_component "{\"id\":$COMP}" > /dev/null
expect "MCP: delete_component" "$(sq "SELECT COUNT(*) FROM ka_komponenty WHERE idm = $COMP")" "0"
PAGE=$(sq "SELECT ids FROM ka_stranky WHERE stavba IS NOT NULL AND smazano IS NULL ORDER BY ids LIMIT 1")
ELEMENT=$(sq "SELECT COALESCE(stavba_koncept, stavba) FROM ka_stranky WHERE ids = $PAGE" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["deti"][0]["id"];')
mcp save_section "{\"id\":$PAGE,\"element\":\"$ELEMENT\",\"name\":\"Moje sekce z MCP\"}" > "$WORK/response"; SECTION=$(mcp_value id)
mcp builder_schema '{}' | contains 'Moje sekce z MCP' && echo "  ok     MCP: saved sections in builder_schema" || { echo "  CHYBA  MCP: saved sections missing in builder_schema"; ERRORS=$((ERRORS+1)); }
BEFORE=$(sq "SELECT JSON_LENGTH(COALESCE(stavba_koncept, stavba), '$.deti') FROM ka_stranky WHERE ids = $PAGE")
mcp insert_section "{\"id\":$PAGE,\"saved_section\":$SECTION}" > /dev/null
expect "MCP: insert_section with a saved section adds it with new ids" "$(sq "SELECT JSON_LENGTH(stavba_koncept, '$.deti') FROM ka_stranky WHERE ids = $PAGE")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(stavba_koncept, CONCAT('$.deti[', JSON_LENGTH(stavba_koncept, '$.deti') - 1, '].id'))) <> '$ELEMENT' FROM ka_stranky WHERE ids = $PAGE")" "$((BEFORE + 1))|1"
mcp discard_draft "{\"id\":$PAGE}" > /dev/null; mcp delete_section "{\"id\":$SECTION}" > /dev/null
expect "MCP: delete_section" "$(sq "SELECT COUNT(*) FROM ka_sekce WHERE idx = $SECTION")" "0"
mcp save_popup '{"template":"announcement_bar","name":"Na smazani"}' > "$WORK/response"; POPUP=$(sq "SELECT idpp FROM ka_popupy WHERE nazev = 'Na smazani'")
mcp delete_popup "{\"id\":$POPUP}" > /dev/null
expect "MCP: delete_popup" "$(sq "SELECT COUNT(*) FROM ka_popupy WHERE idpp = $POPUP")" "0"
PNG=$(php -r 'ob_start(); imagepng(imagecreatetruecolor(8, 8)); echo base64_encode(ob_get_clean());')
mcp upload_file "{\"filename\":\"mcp-smazat.png\",\"data\":\"$PNG\"}" > /dev/null; MEDIA=$(sq "SELECT ido FROM ka_media ORDER BY ido DESC LIMIT 1")
mcp update_media "{\"id\":$MEDIA,\"alt\":\"Cerny ctverec\",\"caption\":\"Popisek\"}" > /dev/null
expect "MCP: update_media" "$(sq "SELECT CONCAT(nazev, '|', popis) FROM ka_media WHERE ido = $MEDIA")" "Cerny ctverec|Popisek"
FILE=$(sq "SELECT obr_poloha FROM ka_media WHERE ido = $MEDIA")
mcp delete_media "{\"id\":$MEDIA}" > /dev/null
expect "MCP: delete_media removes the record and the file" "$(sq "SELECT COUNT(*) FROM ka_media WHERE ido = $MEDIA")|$([ -e "$WORK/web/$FILE" ] && echo file || echo gone)" "0|gone"
USED=$(sq "SELECT ido FROM ka_media m WHERE EXISTS (SELECT 1 FROM ka_stranky s WHERE CONCAT_WS(' ', s.stavba, s.stavba_koncept, s.text) LIKE CONCAT('%', REPLACE(m.obr_poloha, '/', '%'), '%')) LIMIT 1")
[ -z "$USED" ] || { mcp delete_media "{\"id\":$USED}" | contains 'still used on the site' && echo "  ok     MCP: a file in use is not deleted" || { echo "  CHYBA  MCP: delete_media deleted a file in use"; ERRORS=$((ERRORS+1)); }; }
READS=$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'"); mcp list_enquiries '{}' > /dev/null
expect "MCP: every enquiry read is in the change log" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'list_enquiries'")" "$((READS + 1))"
sq "INSERT INTO ka_poptavky (datum, email, data) VALUES (NOW(), 'mcp@example.cz', '[]')"; ENQUIRY=$(sq "SELECT MAX(idp) FROM ka_poptavky")
mcp update_enquiry "{\"id\":$ENQUIRY,\"status\":\"resolved\",\"note\":\"Vyrizeno pres Clauda\"}" > /dev/null
expect "MCP: update_enquiry" "$(sq "SELECT CONCAT(stav, '|', poznamka) FROM ka_poptavky WHERE idp = $ENQUIRY")" "2|Vyrizeno pres Clauda"
mcp delete_enquiry "{\"id\":$ENQUIRY}" > /dev/null
expect "MCP: delete_enquiry" "$(sq "SELECT COUNT(*) FROM ka_poptavky WHERE idp = $ENQUIRY")" "0"

echo "== draft look and whole-site preview (1.7)"
mcp discard_look '{}' > /dev/null
OLDPRIMARY=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")
mcp update_design_system '{"ds":{"barvy":{"primarni":"#123456"}}}' > "$WORK/response"
SITEPREVIEW=$(mcp_value preview)
expect "MCP: the design system goes to the draft look, the site keeps the published one" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.design_system.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$OLDPRIMARY|#123456"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; grep -q 'ka-barva-primarni: #123456' "$WORK/response" && { echo "  CHYBA  the draft look is on the public site"; ERRORS=$((ERRORS+1)); } || echo "  ok     visitors do not see the draft look"
mcp save_classes '{"css":".look-test { padding: 1rem }"}' > /dev/null
mcp save_classes '{"css":".look-test { padding: 2rem }"}' > "$WORK/response"
expect "MCP: a new class is live at once, a change of it waits in the draft" "$(sq "SELECT styl LIKE '%\"odsazeni_y\"%' OR css LIKE '%1rem%' FROM ka_tridy WHERE nazev = 'look-test'")|$(mcp_value look_draft 0)" "1|look-test"
mcp list_classes '{"name":"look-test"}' > "$WORK/response"
expect "MCP: list_classes shows the draft" "$(mcp_value 0 draft)" "1"
sq "DROP TABLE IF EXISTS menu_before; CREATE TABLE menu_before AS SELECT * FROM ka_menu"
mcp save_menu '{"location":"main","items":[{"type":"link","text":"Draft link","url":"/draft-link"}]}' > /dev/null
expect "MCP: save_menu goes to the draft look" "$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni' AND polozky LIKE '%draft-link%'")" "0"
mcp site_info '{}' > "$WORK/response"
expect "MCP: site_info lists the draft look" "$(mcp_value look_draft | grep -c 'look-test')" "1"
curl -s -c "$WORK/preview.jar" -o "$WORK/response" "$SITEPREVIEW"
expect "the whole-site preview shows the draft look, the draft menu and the bar, and is not indexed" "$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")|$(grep -c 'draft-link' "$WORK/response")|$(grep -c 'ka-nahled-lista' "$WORK/response")|$(grep -c 'noindex' "$WORK/response")" "1|1|1|1"
DRAFTPAGE=$(sq "SELECT ids FROM ka_stranky WHERE smazano IS NULL AND zobrazit = 1 AND stavba IS NOT NULL ORDER BY ids LIMIT 1")
DRAFTSLUG=$(sq "SELECT seo_link FROM ka_stranky WHERE ids = $DRAFTPAGE")
mcp edit_build "{\"id\":$DRAFTPAGE,\"operations\":[{\"op\":\"insert\",\"elements\":[{\"type\":\"heading\",\"content\":{\"text\":\"Only in the draft\"}}],\"into\":null,\"position\":0}]}" > /dev/null
curl -s -b "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG"
expect "browsing on in the preview (cookie) shows page drafts too" "$(grep -c 'Only in the draft' "$WORK/response")|$(grep -c 'ka-barva-primarni: #123456' "$WORK/response")" "1|1"
curl -s -o "$WORK/response" "$B/$DRAFTSLUG"; ! grep -q 'Only in the draft' "$WORK/response" && echo "  ok     without the preview the page draft stays hidden" || { echo "  CHYBA  page draft visible without the preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$WORK/preview.jar" -c "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG?nahled_konec=1"
curl -s -b "$WORK/preview.jar" -o "$WORK/response" "$B/$DRAFTSLUG"; ! grep -q 'Only in the draft' "$WORK/response" && echo "  ok     ending the preview shows the published site again" || { echo "  CHYBA  the preview did not end"; ERRORS=$((ERRORS+1)); }
mcp discard_draft "{\"id\":$DRAFTPAGE}" > /dev/null
check "the admin shows the look bar on every screen" 200 "/admin.php?module=pages" "Publikovat vzhled"
mcp publish_look '{}' > "$WORK/response"
expect "MCP: publish_look publishes everything and keeps the previous look" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")|$(sq "SELECT css LIKE '%2rem%' OR styl LIKE '%2rem%' OR styl LIKE '%\"xl\"%' FROM ka_tridy WHERE nazev = 'look-test'")|$(sq "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni' AND polozky LIKE '%draft-link%'")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT COUNT(*) > 0 FROM ka_look_versions")" "#123456|1|1||1"
expect "publishing the look is in the change log with what changed" "$(sq "SELECT popis LIKE '%#123456%' FROM ka_protokol WHERE akce = 'publish look' ORDER BY idp DESC LIMIT 1")" "1"
mcp list_look_versions '{}' > "$WORK/response"; VERSION=$(mcp_value versions 0 id)
mcp restore_look_version "{\"id\":$VERSION}" > /dev/null
expect "MCP: an earlier look comes back into the draft" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.design_system.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'look_draft'")" "$OLDPRIMARY"
check "earlier looks in Site appearance" 200 "/admin.php?module=appearance" "Vrátit tento vzhled"
mcp discard_look '{}' > /dev/null
expect "MCP: discard_look" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'look_draft'")|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "|#123456"
mcp update_design_system "{\"ds\":{\"barvy\":{\"primarni\":\"$OLDPRIMARY\"}}}" > /dev/null; mcp publish_look '{}' > /dev/null
sq "DELETE FROM ka_menu; INSERT INTO ka_menu SELECT * FROM menu_before; DROP TABLE menu_before"; rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== ready-made templates of site parts (1.7)"
check "templates of the header" 200 "/admin.php?module=parts&action=templates&typ=hlavicka" "Logo uprostřed"
mcp builder_schema '{}' > "$WORK/response"
expect "MCP: builder_schema lists the part templates" "$(mcp_value part_templates header na-stred | grep -c 'Centred logo')|$(mcp_value part_templates footer tiraz | grep -c 'imprint')" "1|1"
PUBLISHEDFOOTER=$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")
mcp apply_part_template '{"part":"footer","template":"kompaktni"}' > "$WORK/response"
expect "MCP: a template goes to the draft, the published footer stays" "$(sq "SELECT stavba_koncept LIKE '%\"udaj\":\"copyright\"%' AND stavba_koncept NOT LIKE '%\"mrizka\"%' FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")|$(sq "SELECT SHA2(COALESCE(stavba, ''), 256) FROM ka_casti WHERE typ = 'paticka' AND jazyk = '' AND varianta = ''")" "1|$PUBLISHEDFOOTER"
curl -s -o "$WORK/footer.html" "$(mcp_value preview)"; grep -q '<footer' "$WORK/footer.html" && echo "  ok     MCP: the part preview shows the template" || { echo "  CHYBA  part template preview"; ERRORS=$((ERRORS+1)); }
mcp apply_part_template '{"part":"footer","template":"nothing"}' | contains 'Unknown template' && echo "  ok     MCP: an unknown template is refused" || { echo "  CHYBA  unknown template accepted"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=parts&action=templates&typ=nenalezeno"
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/admin.php?module=parts&action=apply_template&typ=nenalezeno" -d "_csrf=$(csrf)" -d sablona=s-hledanim)
case "$code" in "302 "*"module=parts&action=builder&typ=nenalezeno"*) echo "  ok     admin: a template opens in the builder";; *) echo "  CHYBA  admin apply template: $code"; ERRORS=$((ERRORS+1));; esac
expect "admin: the 404 wrapper got the search template as a draft" "$(sq "SELECT stavba_koncept LIKE '%\"typ\":\"hledani\"%' FROM ka_casti WHERE typ = 'nenalezeno' AND jazyk = ''")" "1"
mcp discard_draft '{"part":"footer"}' > /dev/null; sq "DELETE FROM ka_casti WHERE typ = 'nenalezeno' AND jazyk = '' AND stavba IS NULL"

echo "== pop-up okna"
check "pop-up okna v administraci" 200 "/admin.php?module=popups" "Zatím žádná pop-up okna"
check "nové okno ze vzoru" 200 "/admin.php?module=popups&action=new" 'name="vzor" value="newsletter"'
mcp uloz_popup '{"vzor":"prazdny","nazev":"Akce okno"}' > "$WORK/response"; IDPP=$(mcp_value id)
expect "MCP: okno založené vypnuté a nepublikované" "$(mcp_value adresa)|$(mcp_value aktivni)|$(mcp_value publikovano)|$(mcp_value spoustec)" "akce-okno|||klik"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":true}" | grep -q 'nejdřív publikuj' && echo "  ok     MCP: nepublikované okno nejde zapnout" || { echo "  CHYBA  zapnutí nepublikovaného okna"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz "{\"popup\":$IDPP,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"nadpis\",\"znacka\":\"h2\",\"obsah\":{\"text\":\"Okno akce\"}},{\"id\":\"ab12cd3\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Z okna\",\"pole\":[{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true}]}}]}}" > "$WORK/response"
mcp save_popup "{\"id\":$IDPP,\"type\":\"slide_in\",\"trigger\":\"time\",\"value\":3,\"frequency\":\"until_closed\",\"rules\":{\"device\":\"phone\"},\"active\":true}" > "$WORK/response"
expect "MCP anglicky: typ, spouštěč, četnost a pravidla" "$(mcp_value type)|$(mcp_value trigger)|$(mcp_value frequency)|$(mcp_value rules device)|$(mcp_value active)" "slide_in|time|until_closed|phone|1"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q "data-popup=\"$IDPP\"" "$WORK/response" && grep -q 'class="ka-popup ka-popup--panel" popover="manual" role="region"' "$WORK/response" && grep -q 'data-spoustec="cas" data-hodnota="3" data-cetnost="zavreni"' "$WORK/response" \
  && grep -q 'data-zarizeni="telefon"' "$WORK/response" && grep -q 'Okno akce' "$WORK/response" && grep -q 'image/web\.js' "$WORK/response" \
  && echo "  ok     zapnuté okno na webu se spouštěčem, pravidly prohlížeče a skriptem" || { echo "  CHYBA  pop-up okno na webu"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"kde\":\"vybrane\",\"stranky\":[$IDS]}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/" | contains "data-popup=\"$IDPP\"" && curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno jen na vybrané stránce" || { echo "  CHYBA  pravidlo vybraných stránek"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"2099-01-01\"}}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
! curl -s "$B/o-nas" | contains "data-popup=\"$IDPP\"" && echo "  ok     okno mimo období se do stránky nevloží" || { echo "  CHYBA  období okna"; ERRORS=$((ERRORS+1)); }
mcp uloz_popup "{\"id\":$IDPP,\"pravidla\":{\"od\":\"\",\"kde\":\"vse\"}}" > "$WORK/response"; POPUP_PREVIEW=$(mcp_value nahled); rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=zobrazeni; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=konverze; curl -s -o /dev/null -X POST "$B/popup" -d "id=$IDPP" -d udalost=nic
expect "počitadla okna bez cookies" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazeni, '/', zavreni, '/', konverze) FROM ka_popupy WHERE idpp = $IDPP")" "1/0/1"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/_popup/$IDPP?stavba=koncept"); expect "koncept okna bez přihlášení není" "$code" 404
curl -s -o "$WORK/response" "$POPUP_PREVIEW"; grep -q 'data-otevrit="1"' "$WORK/response" && grep -q 'noindex' "$WORK/response" && echo "  ok     podepsaný náhled okno rovnou otevře" || { echo "  CHYBA  náhled okna: $POPUP_PREVIEW"; ERRORS=$((ERRORS+1)); }
check "okno v builderu" 200 "/admin.php?module=popups&action=builder&id=$IDPP" 'id="stavitel-data"'
check "plátno okna v builderu" 200 "/_popup/$IDPP?stavba=koncept&editor=1" 'ka-popup--editor'
curl -s -b "$JAR" "$B/o-nas" | grep -q "data-popup=\"$IDPP\"" && ! curl -s -b "$JAR" "$B/o-nas?stavba=koncept&editor=1" | grep -q "data-popup=" \
  && echo "  ok     plátno builderu stránky je bez pop-up oken webu" || { echo "  CHYBA  pop-up okno v plátně builderu"; ERRORS=$((ERRORS+1)); }
curl -s "$B/" | sed -n '/data-popup=/,$p' > "$WORK/formular.html" # jen okno – stránka může mít vlastní formulář
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis)
expect "formulář v okně má zdroj okna" "$FORM_SOURCE|$FORM_ELEMENT" "popup:$IDPP|ab12cd3"
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/ -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p0=okno@example.cz)
case "$location" in *"vysledek=ok"*) echo "  ok     formulář v okně odeslán";; *) echo "  CHYBA  formulář v okně: $location"; ERRORS=$((ERRORS+1));; esac
expect "poptávka z okna uložena" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(formular, '|', zdroj) FROM ka_poptavky WHERE email = 'okno@example.cz'")" "Z okna|popup:$IDPP"
mcp seznam_popupu '{}' > "$WORK/response"; expect "MCP: seznam oken s počitadly" "$(mcp_value 0 nazev)|$(mcp_value 0 zobrazeni)|$(mcp_value 0 konverze)" "Akce okno|1|1"
mcp uloz_popup "{\"id\":$IDPP,\"aktivni\":false}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html # další testy počítají se stránkami bez okna

# editing right on the site: the link and the form only for signed-in users with the permission
check "úprava na místě – odkaz" 200 /novinky/vitejte-v-kalete "ka-upravit-zde"
check "úprava na místě – formulář" 200 "/novinky/vitejte-v-kalete?upravit=text" "ka-upravit-text"
check "úprava stránky na místě" 200 "/o-nas?upravit=text" "ka-upravit-text"
curl -s -o "$WORK/response" "$B/novinky/vitejte-v-kalete?upravit=text"; grep -q "ka-upravit" "$WORK/response" && { echo "  CHYBA  úprava na místě je vidět bez přihlášení"; ERRORS=$((ERRORS+1)); } || echo "  ok     úprava na místě bez přihlášení není"

echo "== import z WordPressu a export"
check "import a export" 200 "/admin.php?module=transfer" "WordPress"
TOKEN=$(csrf)
wp_batch() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=transfer&action=progress&soubor=wordpress-sample.xml" -d "_csrf=$TOKEN"; }
wp_import() { # náhled (čtení souboru) → volby → import; ukázkový soubor se vejde do jedné dávky
  wp_batch
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=run" -d "_csrf=$TOKEN" -d soubor=wordpress-sample.xml -d koncepty=1 -d stranky=1 -d stavitel=1 -d presmerovani=1 -d rubrika=0
  wp_batch
}
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-sample.xml"
wp_batch
check "import z WordPressu – náhled upozorní na nepřevoditelný typ" 200 "/admin.php?module=transfer&action=preview&soubor=wordpress-sample.xml" "nav_menu_item"
check "import z WordPressu – náhled hlásí SEO data pluginů" 200 "/admin.php?module=transfer&action=preview&soubor=wordpress-sample.xml" "Rank Math"
wp_import
grep -q "Import obsahu je hotový" "$WORK/response" && echo "  ok     import z WordPressu doběhl" || { echo "  CHYBA  import z WordPressu nedoběhl"; ERRORS=$((ERRORS+1)); }
check "importovaná novinka" 200 /novinky/lavka-pres-bystrinu "Lávka přes Bystřinu"
check "importovaná novinka – galerie a video" 200 /novinky/lavka-pres-bystrinu 'class="galerie"'
check "importovaná stránka" 200 /o-zpravodaji "Kontakt"
check "importovaná stránka je rovnou v builderu" 200 /o-zpravodaji '<main id="obsah" class="stavba">'
check "importovaná stránka má nadpis z WordPressu" 200 /o-zpravodaji '<h1>O zpravodaji</h1>'
# SEO plugin data (Core\WpSeo): SmartCrawl title with the site name filled in, Yoast default pattern skipped, Rank Math noindex from a serialized array
expect "SEO ze SmartCrawlu: titulek s názvem webu, popis, bez noindex" "$(sq "SELECT CONCAT(seo_titulek LIKE 'Lávka přes Bystřinu znovu otevřena – %', '|', seo_popis, '|', noindex) FROM ka_novinky WHERE seo_link = 'lavka-pres-bystrinu'")" "1|Po roce oprav se lávka v Horní Lhotě otevřela chodcům i cyklistům.|0"
expect "SEO z Yoastu: výchozí vzor titulku se neimportuje, popis a noindex ano" "$(sq "SELECT CONCAT(seo_titulek, '|', seo_popis, '|', noindex) FROM ka_novinky WHERE seo_link = 'slavnosti-syra'")" "|Rekordní slavnosti sýra: tři tisíce lidí a vítězná farma z Dolní Lhoty.|1"
expect "SEO z Rank Math: titulek s proměnnými, noindex ze serializovaného pole" "$(sq "SELECT CONCAT(seo_titulek LIKE 'Fotografie čtenářů: lávka přes Bystřinu – %', '|', noindex) FROM ka_novinky WHERE seo_link = 'lavka-pres-bystrinu-2'")" "1|1"
expect "SEO ze SmartCrawlu na stránce: titulek a popis" "$(sq "SELECT CONCAT(seo_titulek, '|', popis, '|', noindex) FROM ka_stranky WHERE seo_link = 'o-zpravodaji'")" "O Podhorském zpravodaji – kdo jsme a kde nás najdete|Podhorský zpravodaj vychází od roku 1998 – redakce, kontakt a historie.|0"
check "importovaná novinka s noindex z pluginu ho vypisuje" 200 /novinky/slavnosti-syra 'noindex'
curl -s -o "$WORK/response" "$B/o-zpravodaji"; grep -q 'wp-block' "$WORK/response" && { echo "  CHYBA  třídy WordPressu ve stavbě"; ERRORS=$((ERRORS+1)); } || echo "  ok     třídy WordPressu bez stylu vynechány"
curl -s -o "$WORK/response" "$B/novinky/lavka-pres-bystrinu"; grep -qE "podvrh|onclick|kontaktni-formular|posta\.example" "$WORK/response" && { echo "  CHYBA  importovaná novinka obsahuje skript, zkratku doplňku nebo e-mail komentujícího"; ERRORS=$((ERRORS+1)); } || echo "  ok     importovaný obsah je vyčištěný"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/2026/05/lavka-pres-bystrinu/"); expect "stará adresa WordPressu přesměruje na novinku" "$code" "301 $B/novinky/lavka-pres-bystrinu"
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/?p=102"); expect "stará adresa /?p=102 přesměruje" "$code" 301
# a second import of the same file must not duplicate anything
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=select" -d "_csrf=$TOKEN" -d soubor=wordpress-sample.xml
wp_import
COUNTS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_novinky WHERE seo_link LIKE 'lavka-pres-bystrinu%' OR seo_link LIKE 'slavnosti-syra%' OR seo_link LIKE 'rozpocet-obce%'), '/', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link LIKE 'o-zpravodaji%'))")
expect "opakovaný import nic nezdvojil (novinky/stránky)" "$COUNTS" "4/1"
# 2.7: a custom post type with ACF fields becomes a collection; its items keep their addresses
cpt_batch() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=transfer&action=progress&soubor=wordpress-cpt.xml" -d "_csrf=$TOKEN"; }
cpt_import() {
  cpt_batch
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=run" -d "_csrf=$TOKEN" -d soubor=wordpress-cpt.xml -d koncepty=1 -d stranky=1 -d presmerovani=1 -d rubrika=0 -d kolekce=1
  cpt_batch
}
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=upload" -F "_csrf=$TOKEN" -F "soubor=@$ROOT/tools/fixtures/wordpress-cpt.xml"
cpt_batch
check "import z WordPressu – náhled ukáže vlastní typ obsahu jako kolekci" 200 "/admin.php?module=transfer&action=preview&soubor=wordpress-cpt.xml" "reference"
cpt_import
expect "vlastní typ obsahu → kolekce s poli podle hodnot" "$(sq "SELECT CONCAT(seo_link, '|', detail, '|', JSON_EXTRACT(pole, '\$[*].klic'), '|', JSON_EXTRACT(pole, '\$[*].typ')) FROM ka_kolekce WHERE nazev = 'Reference'")" 'reference|1|["klient", "rok_dokonceni", "datum_predani", "web_klienta", "fotka", "obsah"]|["text", "cislo", "datum", "odkaz", "obrazek", "html"]'
expect "položky kolekce: hodnoty polí, koncept skrytý" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, ':', zobrazit, ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.klient')), ':', JSON_UNQUOTE(JSON_EXTRACT(data, '$.datum_predani'))) ORDER BY idp SEPARATOR '|') FROM ka_kolekce_polozky WHERE idk = (SELECT idk FROM ka_kolekce WHERE seo_link = 'reference')")" "kuchyne-novak:1:Rodina Novákových:2024-03-15|pekarna-u-mlyna:0:Pekárna U Mlýna:2023-11-01"
check "položka kolekce na staré adrese" 200 /reference/kuchyne-novak "Rodina Novákových"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/?p=401"); expect "stará adresa /?p=401 přesměruje na položku" "$code" "301 $B/reference/kuchyne-novak"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=select" -d "_csrf=$TOKEN" -d soubor=wordpress-cpt.xml
cpt_import
expect "opakovaný import vlastního typu nic nezdvojil" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce WHERE nazev LIKE 'Reference%'), '/', (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE seo_link LIKE 'kuchyne-novak%'))")" "1/1"
check "složka importu není přístupná z webu" 403 /storage/import/wordpress-sample.xml
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
check "export webu je v seznamu" 200 "/admin.php?module=transfer" "action=download"
EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.[a-z]*' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/export" "$B/admin.php?module=transfer&action=download&soubor=$EXPORT"
if [ "${EXPORT##*.}" = zip ]; then unzip -p "$WORK/export" obsah.json > "$WORK/obsah.json" 2>/dev/null || true; else cp "$WORK/export" "$WORK/obsah.json"; fi
grep -q '"format":"kaleta-export"' "$WORK/obsah.json" && grep -q '"novinky"' "$WORK/obsah.json" && ! grep -qE '"password"|smtp_heslo|tajny_klic|ai_klic' "$WORK/obsah.json" && echo "  ok     export obsahuje data a žádná tajemství" || { echo "  CHYBA  export"; ERRORS=$((ERRORS+1)); }
grep -q '"kolekce_polozky":\[' "$WORK/obsah.json" && grep -q 'Jana Nováková' "$WORK/obsah.json" && grep -q '"tridy":\[' "$WORK/obsah.json" && grep -q '"casti":\[' "$WORK/obsah.json" && ! grep -q 'Chci kuchyň' "$WORK/obsah.json" \
  && grep -q '"adresa":"akce-okno"' "$WORK/obsah.json" && ! grep -q '"zobrazeni":' "$WORK/obsah.json" \
  && echo "  ok     export obsahuje builder, kolekce a pop-up okna, poptávky ani počitadla ne" || { echo "  CHYBA  export builderu a kolekcí"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/admin.php?module=transfer&action=download&soubor=$EXPORT"; grep -q "Heslo" "$WORK/response" && echo "  ok     export jen pro přihlášeného správce" || { echo "  CHYBA  export jde stáhnout bez přihlášení"; ERRORS=$((ERRORS+1)); }

echo "== koš novinek"
IDC=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idc FROM ka_novinky WHERE seo_link = 'vitejte-v-kalete'")
check "výpis novinek" 200 "/admin.php?module=news" "Smazat označené"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=delete" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
check "novinka v koši není na webu" 404 /novinky/vitejte-v-kalete
check "novinka v koši není ani v náhledu" 404 "/novinky/vitejte-v-kalete?nahled=1"
check "záložka Koš" 200 "/admin.php?module=news&stav=kos" "Vítejte"
check "novinka v koši nejde upravit" 404 "/admin.php?module=news&action=edit&id=$IDC"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=news&action=restore" -d "_csrf=$TOKEN" -d "smaz[]=$IDC"
expect "obnovená novinka se vrátí jako koncept" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(visible, '/', smazano IS NULL) FROM ka_novinky WHERE idc = $IDC")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_novinky SET visible = 1, smazano = NOW() - INTERVAL 31 DAY WHERE idc = $IDC"
check "vstup do administrace vysype starý koš" 200 /admin.php "Přehled"
expect "novinka starší 30 dní v koši je smazaná natrvalo" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE idc = $IDC")" "0"

echo "== přesměrování po změně adresy kategorie a stránky"
check "formulář kategorie" 200 "/admin.php?module=categories" "Kategorie"
TOKEN=$(csrf)
IDT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM ka_kategorie WHERE seo_link = 'aktuality'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=categories&action=save" -d "_csrf=$TOKEN" -d "idt=$IDT" -d nazev=Aktuality -d seo_link=aktuality-firmy -d hodnost=100
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/novinky/kategorie/aktuality"); expect "stará adresa kategorie přesměruje na novou" "$code" "301 $B/novinky/kategorie/aktuality-firmy"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakt'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/kontakt"); expect "stará adresa stránky přesměruje na novou" "$code" "301 $B/kontakty"

echo "== stránky: SEO, koš, duplikace"
IDS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'kontakty'")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>" \
  --data-urlencode "seo_titulek=Kontakt na truhlárnu" -d obrazek=media/2026/01/sdileni.jpg -d noindex=1
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '<title>Kontakt na truhlárnu' "$WORK/response" && grep -q 'og:image" content="http[^"]*/media/2026/01/sdileni.jpg"' "$WORK/response" && grep -q 'noindex, follow' "$WORK/response" \
  && echo "  ok     stránka: vlastní titulek, úplná adresa obrázku pro sdílení, noindex" || { echo "  CHYBA  SEO stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=duplicate" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "duplikát stránky je skrytý a má volnou adresu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', seo_link) FROM ka_stranky ORDER BY ids DESC LIMIT 1")" "0/kontakty-kopie"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDS"
check "stránka v koši není na webu" 404 /kontakty
check "záložka Koš u stránek" 200 "/admin.php?module=pages&stav=kos" "Kontakt"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=restore" -d "_csrf=$TOKEN" -d "ids=$IDS"
expect "obnovená stránka je skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', smazano IS NULL) FROM ka_stranky WHERE ids = $IDS")" "0/1"
IDU=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$IDU' WHERE promenna='home_page'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=delete" -d "_csrf=$TOKEN" -d "ids=$IDU"
expect "úvodní stránku nejde smazat" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT smazano IS NULL FROM ka_stranky WHERE ids = $IDU")" "1"
# a language version in progress (without a published translation of the home page) is not offered in the switcher, hreflang or the sitemap
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1, smazano = NULL WHERE ids = $IDU"; rm -f "$WORK"/web/storage/cache/stranky/*.html
# the pages are saved first: „curl | grep -q“ with pipefail fails when grep exits before curl finishes writing (SIGPIPE)
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
! grep -q 'hreflang="en"' "$WORK/response" && ! grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     jazyk bez zveřejněného překladu úvodu se návštěvníkům nenabízí" || { echo "  CHYBA  rozpracovaný jazyk v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp vytvor_stranku "{\"titulek\":\"About home\",\"adresa\":\"about-home\",\"jazyk\":\"en\",\"preklad_z\":$IDU,\"text\":\"<p>Home</p>\",\"zobrazit\":1}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; curl -s -o "$WORK/mapa.xml" "$B/sitemap.xml"
grep -q 'hreflang="en"' "$WORK/response" && grep -q '/en/</loc>' "$WORK/mapa.xml" \
  && echo "  ok     se zveřejněným překladem úvodu se jazyk nabízí" || { echo "  CHYBA  hotový jazyk chybí v přepínači nebo mapě webu"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}},{"typ":"jazyky"}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q 'ka-jazyky-vyber--nahoru ka-jazyky-prvek' "$WORK/response" && grep -q 'hreflang="en" lang="en"' "$WORK/response" && grep -q 'image/web.js' "$WORK/response" \
  && echo "  ok     prvek Přepínač jazyků v patičce (nabídka nahoru) a web.js pro jazyk prohlížeče" || { echo "  CHYBA  prvek Přepínač jazyků"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"hlavicka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"header","deti":[{"typ":"navigace","obsah":{"jazyky":false}},{"typ":"navigace","obsah":{"menu":"paticka"}}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
expect "Navigace s vypnutým přepínačem jazyků ho nemá, ostatní navigace ano" "$(grep -o '<nav class="ka-jazyky"' "$WORK/response" | wc -l | tr -d ' ')" 1
# 2.7: a header transparent at the top and smaller after scrolling (English vocabulary): fixed after its own sticky style, scroll-driven animation, CSS only now
mcp save_build '{"part":"header","publish":true,"build":{"v":1,"children":[{"type":"section","tag":"header","content":{"on_scroll":"transparent_shrink","text_at_top":"light"},"style":{"base":{"position":"sticky","background":"background"}},"children":[{"type":"navigation","content":{"mega_menu":true}}]}]}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"
grep -q '<header id="s-[a-z0-9]*" class="ka-hlavicka-rolovani ka-hlavicka-rolovani--pruhledna">' "$WORK/response" && grep -q 'position: sticky; background-color: var(--ka-barva-pozadi); position: fixed; top: 0; inset-inline: 0; animation: ka-hlavicka-svetla linear both, ka-hlavicka-mensi linear both; animation-timeline: scroll(root); animation-range: 0 120px;' "$WORK/response" \
  && grep -q '@keyframes ka-hlavicka-svetla' "$WORK/response" && grep -q 'prefers-reduced-motion: reduce) { .ka-hlavicka-rolovani { animation: none !important; } }' "$WORK/response" \
  && echo "  ok     záhlaví nahoře průhledné a po odrolování menší (animace podle posuvu stránky, bez skriptu)" || { echo "  CHYBA  záhlaví při rolování"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_casti WHERE typ = 'hlavicka'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/"; ! grep -q 'ka-hlavicka-' "$WORK/response" && echo "  ok     bez takového záhlaví se CSS rolování nevypisuje" || { echo "  CHYBA  CSS rolování záhlaví i bez záhlaví"; ERRORS=$((ERRORS+1)); }
mcp stavba_uloz '{"cast":"paticka","publikovat":true,"stavba":{"v":1,"deti":[{"typ":"sekce","znacka":"footer","deti":[{"typ":"udaje","obsah":{"udaj":"copyright"}}]}]}}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_stranky WHERE seo_link = 'about-home'"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='0' WHERE promenna='home_page'"

echo "== podstránky, plán, historie, šablony, export"
save_page() { curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" "$@"; }
save_page -d ids=0 --data-urlencode "titulek=Služby firmy" -d seo_link=sluzby-firmy -d zobrazit=1 -d v_menu=0 -d "text=<p>S</p>" > /dev/null
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'sluzby-firmy'")
save_page -d ids=0 --data-urlencode "titulek=Kuchyně" -d "nadrazena=$IDR" -d zobrazit=1 -d v_menu=0 -d "text=<p>Kuchyně na míru</p>" > /dev/null
expect "podstránka má adresu pod nadřazenou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "sluzby-firmy/kuchyne"
rm -f "$WORK"/web/storage/cache/stranky/*.html
check "podstránka na webu" 200 /sluzby-firmy/kuchyne "Kuchyně na míru"
save_page -d "ids=$IDR" --data-urlencode "titulek=Služby firmy" -d seo_link=nase-sluzby -d zobrazit=1 -d v_menu=0 -d "text=<p>S2</p>" > /dev/null
expect "změna adresy nadřazené posune podstránku" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT seo_link FROM ka_stranky WHERE nadrazena = $IDR")" "nase-sluzby/kuchyne"
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/sluzby-firmy/kuchyne"); expect "stará adresa podstránky přesměruje" "$code" "301 $B/nase-sluzby/kuchyne"
expect "změna textu uloží předchozí verzi" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT text FROM ka_stranky_revize WHERE ids = $IDR ORDER BY idr DESC LIMIT 1")" "<p>S</p>"
save_page -d ids=0 --data-urlencode "titulek=Akce" -d v_menu=0 -d "text=<p>A</p>" -d "zverejnit_od=$(date -v+1d '+%Y-%m-%dT%H:%M' 2>/dev/null || date -d '+1 day' '+%Y-%m-%dT%H:%M')" > /dev/null
expect "naplánovaná stránka čeká skrytá" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', zverejnit_od IS NOT NULL) FROM ka_stranky WHERE seo_link = 'akce'")" "0/1"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zverejnit_od = NOW() - INTERVAL 1 MINUTE WHERE seo_link = 'akce'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'notification_check'"
curl -s -o /dev/null "$B/novinky?x=$RANDOM"; sleep 1
expect "naplánovaná stránka se v čase sama zveřejní" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE seo_link = 'akce'")" "1"
location=$(save_page -d ids=0 --data-urlencode "titulek=Nabídka" -d sablona=landing -d zobrazit=0 -d v_menu=0 -d text=)
case "$location" in *action=builder*) echo "  ok     nová stránka ze šablony jde rovnou do builderu";; *) echo "  CHYBA  šablona stránky: $location"; ERRORS=$((ERRORS+1));; esac
expect "šablona složí koncept ze sekcí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba_koncept LIKE '%\"typ\":\"sekce\"%' FROM ka_stranky WHERE seo_link = 'nabidka'")" "1"
IDN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nabidka'")
curl -s -b "$JAR" -o "$WORK/stranka.json" "$B/admin.php?module=pages&action=export&id=$IDN"
grep -q '"format": "kaleta-stranka"' "$WORK/stranka.json" && echo "  ok     export stránky do JSON" || { echo "  CHYBA  export stránky"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/stranka.json;type=application/json"
expect "import stránky vytvoří skrytou kopii se stavbou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(zobrazit, '/', stavba_koncept IS NOT NULL) FROM ka_stranky WHERE seo_link = 'nabidka-2'")" "0/1"
# 1.8: the export carries the classes and components of the build (a component inside a component too)
php -r '$d = json_decode(file_get_contents($argv[1]), true); $d["titulek"] = "Balíček";
  $d["stavba"]["deti"][] = ["typ" => "sekce", "tridy" => ["balicek-karta", "balicek-vlastni"], "deti" => [["typ" => "komponenta", "obsah" => ["komponenta" => "901", "hodnoty" => []]]]];
  $d["tridy"] = [["nazev" => "balicek-karta", "styl" => ["zaklad" => ["odsazeni" => "l"]], "css" => "color: red; behavior: url(x)"], ["nazev" => "balicek-vlastni", "styl" => ["zaklad" => ["pozadi" => "primarni"]], "css" => ""]];
  $d["komponenty"] = [["id" => 901, "nazev" => "Balíček vnější", "vlastnosti" => [], "stavba" => ["v" => 1, "deti" => [["typ" => "sekce", "deti" => [["typ" => "komponenta", "obsah" => ["komponenta" => "902"]]]]]]],
    ["id" => 902, "nazev" => "Balíček vnitřní", "vlastnosti" => [["klic" => "nadpis", "popisek" => "Nadpis", "typ" => "text", "vychozi" => "Ahoj"]], "stavba" => ["v" => 1, "deti" => [["typ" => "nadpis", "obsah" => ["text" => "{{nadpis}}"]]]]]];
  file_put_contents($argv[2], json_encode($d, JSON_UNESCAPED_UNICODE));' "$WORK/stranka.json" "$WORK/balicek.json"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_tridy (nazev, styl, css, zmeneno) VALUES ('balicek-vlastni', '{}', 'color: blue', NOW())"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/balicek.json;type=application/json"
OUTER=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Balíček vnější'"); INNER=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Balíček vnitřní'")
expect "page import creates the missing class (cleaned) and keeps the site's own" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT(css LIKE '%color: red%', '/', css LIKE '%behavior%') FROM ka_tridy WHERE nazev = 'balicek-karta'")|$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', styl, ':', css) FROM ka_tridy WHERE nazev = 'balicek-vlastni'")" "1/0|1:{}:color: blue"
expect "page import creates both components and points the uses at them" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT((SELECT stavba LIKE '%\"komponenta\":\"$INNER\"%' FROM ka_komponenty WHERE idm = '$OUTER'), '/', stavba_koncept LIKE '%\"komponenta\":\"$OUTER\"%') FROM ka_stranky WHERE titulek = 'Balíček'")" "1/1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=import" -F "_csrf=$TOKEN" -F "soubor=@$WORK/balicek.json;type=application/json"
expect "a second import of the same page reuses the components" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_komponenty WHERE nazev LIKE 'Balíček%'")" "2"
IDB=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT MIN(ids) FROM ka_stranky WHERE titulek = 'Balíček'")
curl -s -b "$JAR" -o "$WORK/balicek-export.json" "$B/admin.php?module=pages&action=export&id=$IDB"
expect "the export lists the used classes and both components" "$(php -r '$d = json_decode(file_get_contents($argv[1]), true); echo $d["verze"], "|", implode(",", preg_grep("/^(balicek|karta$)/", array_column($d["tridy"], "nazev"))), "|", implode(",", array_column($d["komponenty"], "nazev"));' "$WORK/balicek-export.json")" "2|balicek-karta,balicek-vlastni,karta|Balíček vnější,Balíček vnitřní"

echo "== 2.7: copy and paste between Kaleta sites, display conditions"
paste_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDN" -d "_csrf=$TOKEN" "${@:2}"; }
code=$(paste_action build_package --data-urlencode "prvky=[{\"typ\":\"sekce\",\"tridy\":[\"balicek-karta\"],\"deti\":[{\"typ\":\"komponenta\",\"obsah\":{\"komponenta\":\"$OUTER\"}}]}]")
expect "copy packs the elements with their classes and components for the clipboard" "$code|$(php -r '$d = json_decode(file_get_contents($argv[1]), true)["schranka"] ?? []; echo $d["kaleta"] ?? "", "/", $d["v"] ?? "", "/", $d["site"] ?? "", "/", implode(",", array_column($d["classes"] ?? [], "nazev")), "/", implode(",", array_column($d["components"] ?? [], "nazev"));' "$WORK/response")" "200|elements/1/$B/balicek-karta/Balíček vnější,Balíček vnitřní"
FOREIGN='{"kaleta":"elements","v":1,"site":"https://jiny.example","elements":[{"id":"cizi1","typ":"sekce","kotva":"cizi","tridy":["schranka-nova","balicek-vlastni"],"deti":[{"id":"cizi2","typ":"obrazek","obsah":{"src":"media/2026/x.jpg","alt":"x"}},{"id":"cizi3","typ":"komponenta","obsah":{"komponenta":"950","hodnoty":{}}}]}],"classes":[{"nazev":"schranka-nova","styl":{"zaklad":{"pozadi":"primarni"}},"css":"color: red"},{"nazev":"balicek-vlastni","styl":{},"css":"color: green"}],"components":[{"id":950,"nazev":"Schránka komponenta","vlastnosti":[],"stavba":{"v":1,"deti":[{"typ":"nadpis","obsah":{"text":"Ze schránky"}}]}}]}'
code=$(paste_action build_paste --data-urlencode "schranka=$FOREIGN")
PASTED=$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT idm FROM ka_komponenty WHERE nazev = 'Schránka komponenta'")
expect "paste from another site: new ids, no anchor, the image points at the https source, the component use at the new component" "$code|$(php -r '$d = json_decode(file_get_contents($argv[1]), true); $p = $d["prvky"][0] ?? []; echo $d["ok"] ? "ok" : "", "/", ($p["id"] ?? "") !== "cizi1" && preg_match("/^[a-z0-9]{3,16}$/", $p["id"] ?? "") ? "new-id" : "old-id", "/", isset($p["kotva"]) ? "anchor" : "no-anchor", "/", $p["deti"][0]["obsah"]["src"] ?? "", "/", $p["deti"][1]["obsah"]["komponenta"] ?? "", "/", (int) str_contains(implode(" ", $d["hlaseni"] ?? []), "1 ") + (int) str_contains(implode(" ", $d["hlaseni"] ?? []), "https://jiny.example"), "/", isset($d["tridy"]["schranka-nova"]) ? "class" : "";' "$WORK/response")" "200|ok/new-id/no-anchor/https://jiny.example/media/2026/x.jpg/$PASTED/2/class"
expect "paste creates the missing class and keeps the site's own" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT((SELECT css FROM ka_tridy WHERE nazev = 'schranka-nova'), '|', (SELECT css FROM ka_tridy WHERE nazev = 'balicek-vlastni'))")" "color: red;|color: blue"
expect "paste of plain text is refused" "$(paste_action build_paste --data-urlencode "schranka=just some text")" 400
expect "paste without the form token is refused" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=build_paste&id=$IDN" --data-urlencode "schranka=$FOREIGN")" 400
code=$(paste_action build_paste --data-urlencode "schranka={\"kaleta\":\"elements\",\"v\":1,\"site\":\"$B\",\"elements\":[{\"id\":\"svuj1\",\"typ\":\"nadpis\",\"tridy\":[\"schranka-stejny\"],\"obsah\":{\"text\":\"Odsud\"}}],\"classes\":[{\"nazev\":\"schranka-stejny\",\"styl\":{},\"css\":\"\"}],\"components\":[]}")
expect "paste from this site inserts the elements without importing anything" "$code|$(grep -c '"text":"Odsud"' "$WORK/response")|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_tridy WHERE nazev = 'schranka-stejny'")" "200|1|0"
# display conditions on the site: a URL parameter switches the element and takes the page out of the cache, a language version does not
paste_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"pod1","typ":"sekce","deti":[{"id":"pod2","typ":"nadpis","obsah":{"text":"Jarní sleva"},"podminky":{"parametr":{"nazev":"utm_campaign","hodnota":"jaro"}}},{"id":"pod3","typ":"nadpis","obsah":{"text":"Nur Deutsch"},"podminky":{"jazyky":["en"]}},{"id":"pod4","typ":"nadpis","obsah":{"text":"Pro všechny"}}]}]}' > /dev/null
grep -q '"podminky":{"parametr":{"nazev":"utm_campaign","hodnota":"jaro"}}' "$WORK/response" && grep -q '"podminky":{"jazyky":\["en"\]}' "$WORK/response" && echo "  ok     the validator keeps the URL parameter and language conditions" || { echo "  CHYBA  conditions after sanitize"; ERRORS=$((ERRORS+1)); }
paste_action build_publish > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE ids = $IDN"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/nabidka?utm_campaign=jaro"; grep -q 'Jarní sleva' "$WORK/response" && ! grep -q 'Nur Deutsch' "$WORK/response" && grep -q 'Pro všechny' "$WORK/response" && echo "  ok     element only with ?utm_campaign=jaro, none for another language version" || { echo "  CHYBA  conditions with the parameter"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/nabidka?utm_campaign=podzim"; ! grep -q 'Jarní sleva' "$WORK/response" && grep -q 'Pro všechny' "$WORK/response" && echo "  ok     another value of the parameter hides the element" || { echo "  CHYBA  parameter value"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/nabidka"; ! grep -q 'Jarní sleva' "$WORK/response" && echo "  ok     without the parameter the element is not on the page" || { echo "  CHYBA  element without the parameter"; ERRORS=$((ERRORS+1)); }
expect "a page with a URL parameter condition stays out of the page cache" "$(ls "$WORK"/web/storage/cache/stranky/*.html 2>/dev/null | wc -l | tr -d ' ')" 0
paste_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"pod1","typ":"sekce","deti":[{"id":"pod3","typ":"nadpis","obsah":{"text":"Nur Deutsch"},"podminky":{"jazyky":["en"]}},{"id":"pod4","typ":"nadpis","obsah":{"text":"Pro všechny"}}]}]}' > /dev/null; paste_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null "$B/nabidka"
expect "a page with only a language condition is cached (each language version has its own address)" "$(ls "$WORK"/web/storage/cache/stranky/*.html 2>/dev/null | wc -l | tr -d ' ')" 1

echo "== builder: vlastní CSS, atributy, animace, moje sekce, přejmenování třídy"
IDV=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'nase-sluzby'")
version_action() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' -X POST "$B/admin.php?module=pages&action=$1&id=$IDV" -d "_csrf=$TOKEN" "${@:2}"; }
version_action build_save --data-urlencode 'stavba={"v":1,"deti":[{"id":"sv1","typ":"sekce","tridy":["karta"],"css":"backdrop-filter: blur(4px); background: url(x)","atributy":{"data-sledovat":"cta","onclick":"x"},"styl":{"zaklad":{"animace":"ka-vyjet","prechod":"linear-gradient(135deg, var(--ka-barva-primarni), var(--ka-barva-sekundarni))","okraj_vlevo":"auto"},"aktivni":{"pruhlednost":"0.8"}},"deti":[{"typ":"nadpis","obsah":{"text":"Test"}}]}]}' > /dev/null
grep -q 'Nepovolená deklarace' "$WORK/response" && grep -q 'Atribut může být jen' "$WORK/response" && echo "  ok     vlastní CSS a atributy prvku se čistí" || { echo "  CHYBA  čištění CSS a atributů"; ERRORS=$((ERRORS+1)); }
version_action build_publish > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/nase-sluzby"
grep -q 'data-sledovat="cta"' "$WORK/response" && ! grep -q 'onclick="x"' "$WORK/response" && grep -q 'backdrop-filter: blur(4px)' "$WORK/response" && grep -q 'animation-timeline: view()' "$WORK/response" \
  && grep -q '@keyframes ka-vyjet' "$WORK/response" && grep -q ':active {' "$WORK/response" && grep -q 'margin-inline-start: auto' "$WORK/response" \
  && echo "  ok     vlastní CSS, atributy, animace, stisknutí a okraj na webu" || { echo "  CHYBA  nové vlastnosti stylu na webu"; ERRORS=$((ERRORS+1)); }
expect "uložení do mých sekcí" "$(version_action build_save_section --data-urlencode 'nazev=Moje karta' --data-urlencode 'prvek={"typ":"sekce","deti":[{"typ":"nadpis","obsah":{"text":"Z knihovny"}}]}')" 200
grep -q '"nazev":"Moje karta"' "$WORK/response" && echo "  ok     moje sekce v seznamu" || { echo "  CHYBA  moje sekce"; ERRORS=$((ERRORS+1)); }
version_action build_class -d nazev=karta -d pouziti=1 > /dev/null; grep -q 'Služby firmy' "$WORK/response" && echo "  ok     přehled použití třídy" || { echo "  CHYBA  použití třídy"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "přejmenování třídy" "$(version_action build_class -d nazev=karta -d novy_nazev=karta-sluzby)" 200
expect "přejmenovaná třída ve stavbách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stavba LIKE '%\"karta-sluzby\"%' AND stavba NOT LIKE '%\"karta\"%' FROM ka_stranky WHERE ids = $IDV")" "1"

echo "== média, přesměrování, poptávky, uživatelé, písma"
php -r '$i = imagecreatetruecolor(1600, 900); imagefill($i, 0, 0, imagecolorallocate($i, 200, 80, 40)); imagejpeg($i, "'"$WORK"'/foto.jpg");'
printf '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 10" onload="alert(1)"><script>alert(2)</script><rect width="20" height="10" fill="red"/></svg>' > "$WORK/logo.svg"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=upload" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/foto.jpg;type=image/jpeg" -F "soubory[]=@$WORK/logo.svg;type=image/svg+xml"
SVG=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE obr_poloha LIKE '%.svg' ORDER BY ido DESC LIMIT 1")
[ -n "$SVG" ] && ! grep -q 'onload\|<script' "$WORK/web/$SVG" && grep -q '<rect' "$WORK/web/$SVG" && echo "  ok     SVG nahrané a vyčištěné" || { echo "  CHYBA  SVG v Médiích"; ERRORS=$((ERRORS+1)); }
# a file over upload_max_filesize (but under post_max_size): a clear message with the limit in MB, not the php.ini shorthand
UPLOAD_LIMIT=$(php -r '$b = fn ($v) => (int) $v * (["k" => 1024, "m" => 1048576, "g" => 1073741824][strtolower(substr(trim($v), -1))] ?? 1); echo $b(ini_get("upload_max_filesize")), " ", $b(ini_get("post_max_size"));')
if [ "${UPLOAD_LIMIT% *}" -gt 0 ] && [ $(( ${UPLOAD_LIMIT% *} + 4096 )) -lt "${UPLOAD_LIMIT#* }" ]; then
  head -c $(( ${UPLOAD_LIMIT% *} + 1024 )) /dev/zero > "$WORK/velky.zip"
  curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?module=media&action=upload&format=json" -F "_csrf=$TOKEN" -F "soubory[]=@$WORK/velky.zip"
  grep -q 'nejvýš [0-9,]* MB' "$WORK/response" && echo "  ok     soubor nad limit serveru: hláška s limitem v MB" || { echo "  CHYBA  hláška o limitu nahrávání"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
fi
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
PHOTO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obr_poloha FROM ka_media WHERE ido = $IDO")
expect "nahraný obrázek nedostane popis (alt) ze jména souboru" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT('[', nazev, ']') FROM ka_media WHERE ido = $IDO")" "[]"
php -r '$i = imagecreatetruecolor(800, 800); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 200)); imagejpeg($i, "'"$WORK"'/nova.jpg");'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=replace" -F "_csrf=$TOKEN" -F "ido=$IDO" -F "soubor=@$WORK/nova.jpg;type=image/jpeg"
expect "náhrada souboru zachová adresu a změní rozměry" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(obr_poloha, ' ', obr_width, 'x', obr_height) FROM ka_media WHERE ido = $IDO")" "$PHOTO 800x800"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=media&action=save" -d "_csrf=$TOKEN" -d "ido=$IDO" -d nazev=Foto -d ohnisko_x=20 -d ohnisko_y=80
expect "ohnisko ořezu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ohnisko FROM ka_media WHERE ido = $IDO")" "20% 80%"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=save" -d "_csrf=$TOKEN" -d z_adresy=/akce-leto -d na_adresu=/kontakty -d typ=302
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/akce-leto"); expect "dočasné přesměrování 302" "$code" "302"
check "hledání v přesměrováních" 200 "/admin.php?module=redirects&hledat=akce-leto" "akce-leto"
check "protokol s filtrem" 200 "/admin.php?module=changelog&kde=stranky" "Protokol"
IDU2=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=pozvany --data-urlencode email=pozvany@example.cz -d admin=2 -d pozvat=1)
expect "pozvaný uživatel má odkaz na heslo s delší platností" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT obnova_otisk <> '' AND obnova_cas > NOW() FROM ka_uzivatele WHERE user = 'pozvany'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d idr=0 -d nazev=Obchodník -d uroven=0 -d 'moduly[]=enquiries' -d 'moduly[]=collections'
IDR=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT MAX(idr) FROM ka_role")
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=save" -d "_csrf=$TOKEN" -d idu=0 -d user=obchodnik --data-urlencode "password=$PASSWORD" -d "admin=r$IDR"
expect "vlastní role dá uživateli své sekce" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(p.ident_modulu ORDER BY p.ident_modulu) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' AND u.role = $IDR")" "collections,enquiries"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=roles&action=save" -d "_csrf=$TOKEN" -d "idr=$IDR" -d nazev=Obchodník -d uroven=1 -d 'moduly[]=enquiries'
expect "změna role se přenese na členy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(u.admin, ':', GROUP_CONCAT(p.ident_modulu)) FROM ka_uzivatele u JOIN ka_uzivatele_prava p ON p.fk_id_user = u.idu WHERE u.user = 'obchodnik' GROUP BY u.idu")" "1:enquiries"
check "přehled rolí" 200 "/admin.php?module=roles" "Obchodník"
check "uživatelé ukazují vlastní roli" 200 "/admin.php?module=users" "Obchodník"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('require_2fa', 'spravci') ON DUPLICATE KEY UPDATE hodnota = 'spravci'"
code=$(curl -s -b "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/admin.php?module=pages"); case "$code" in "302 "*action=account*) echo "  ok     povinné dvoufázové přihlášení pustí jen do Můj účet";; *) echo "  CHYBA  vynucení 2FA: $code"; ERRORS=$((ERRORS+1));; esac
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'require_2fa'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$TOKEN" -d co=totp_start
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"
grep -q '<svg class="qr"' "$WORK/response" && grep -q 'class="totp-klic"' "$WORK/response" && echo "  ok     zapnutí 2FA ukáže QR kód i klíč k ručnímu zadání" || { echo "  CHYBA  QR kód při zapínání 2FA"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = JSON_SET(IF(hodnota = '' OR hodnota IS NULL, '{}', hodnota), '$.vlastni_pisma', JSON_ARRAY(JSON_OBJECT('nazev', 'Znacka Sans', 'soubor', 'media/2026/01/znacka.woff2', 'tucny', '')), '$.pismo_titulky', 'vlastni-1') WHERE promenna = 'design_system'"
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/kontakty"
grep -q '@font-face { font-family: "Znacka Sans"; src: url("/media/2026/01/znacka.woff2")' "$WORK/response" && grep -q -- '--ka-pismo-titulky: "Znacka Sans"' "$WORK/response" && echo "  ok     vlastní písmo z Médií" || { echo "  CHYBA  vlastní písmo"; ERRORS=$((ERRORS+1)); }
expect "statistika po stránkách" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_stat_stranky")" "1"
curl -s -o "$WORK/response" "$B/kontakty"; grep -q 'image/web.js' "$WORK/response" && echo "  CHYBA  web.js i na stránce, která ho nepotřebuje" && ERRORS=$((ERRORS+1)) || echo "  ok     web.js jen tam, kde je potřeba"

echo "== menu"
check "editor menu" 200 "/admin.php?module=menu" 'data-menu-seznam'
IDO=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ids FROM ka_stranky WHERE seo_link = 'o-nas'")
# 2.7: an icon (lide = people) and a description on an item, a group inside the submenu with its own items (a column), an unknown icon drops out
MENU='[{"typ":"stranka","ids":'$IDO',"text":"O firmě","ikona":"lide","popis":"Kdo jsme","deti":[{"typ":"odkaz","text":"Kariéra","url":"https://example.cz/kariera","nove_okno":true},{"typ":"skupina","text":"Tým","ikona":"neexistuje","deti":[{"typ":"odkaz","text":"Vedení","url":"/vedeni"}]}]},{"typ":"novinky"},{"typ":"odkaz","text":"Zlý","url":"javascript:alert(1)"}]'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&umisteni=hlavni" -d "_csrf=$TOKEN" --data-urlencode "polozky=$MENU"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=save&umisteni=paticka" -d "_csrf=$TOKEN" --data-urlencode 'polozky=[{"typ":"odkaz","text":"Zásady ochrany soukromí","url":"/zasady"}]'
expect "the menu waits in the draft look" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'paticka' AND polozky LIKE '%/zasady%'")" "0"
publish_look
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/novinky"
grep -q '<li class="podmenu"><a href="[^"]*/o-nas"><svg class="menu-ikona"' "$WORK/response" && grep -q '</svg>O firmě</a><ul><li><a href="https://example.cz/kariera" target="_blank" rel="noopener">Kariéra</a>' "$WORK/response" && grep -q 'aria-current="page">Novinky' "$WORK/response" && ! grep -q 'javascript:' "$WORK/response" \
  && echo "  ok     menu s podmenu na webu, ikona před textem, nebezpečný odkaz vypadl" || { echo "  CHYBA  menu na webu"; ERRORS=$((ERRORS+1)); }
grep -q '</li><li class="menu-sloupec"><span class="menu-nadpis">Tým</span><ul><li><a href="[^"]*/vedeni">Vedení</a></li></ul></li>' "$WORK/response" && ! grep -q 'menu-popis\|neexistuje' "$WORK/response" \
  && echo "  ok     skupina v podmenu jako sloupec s nadpisem; popis jen v mega menu, neznámá ikona vypadla" || { echo "  CHYBA  sloupec skupiny v menu"; ERRORS=$((ERRORS+1)); }
grep -q 'image/web\.js' "$WORK/response" && echo "  ok     stránka s podmenu načte web.js (Esc podmenu zavře)" || { echo "  CHYBA  stránka s podmenu bez web.js"; ERRORS=$((ERRORS+1)); }
mcp get_menu '{"location":"main"}' > "$WORK/response"; grep -q 'icon\\":\\"people' "$WORK/response" && grep -q 'description\\":\\"Kdo jsme' "$WORK/response" && echo "  ok     MCP: get_menu vrací ikonu anglicky a popis položky" || { echo "  CHYBA  MCP get_menu ikona"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp nacti_menu '{"umisteni":"paticka"}' > "$WORK/response"; grep -q 'Zásady ochrany soukromí' "$WORK/response" && echo "  ok     menu v patičce (MCP)" || { echo "  CHYBA  menu v patičce"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$TOKEN" -d "ids=$IDS" -d titulek=Kontakt -d seo_link=kontakty -d zobrazit=1 -d v_menu=1 -d "text=<p>Adresa.</p>"
expect "zaškrtnutá stránka se přidá na konec sestaveného menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT polozky LIKE '%\"ids\":$IDS%' FROM ka_menu WHERE umisteni = 'hlavni'")" "1"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=menu&action=automatic&umisteni=hlavni" -d "_csrf=$TOKEN"
publish_look
expect "návrat k automatickému menu" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_menu WHERE umisteni = 'hlavni'")" "0"

echo "== ikony, manifest, cache"
expect "favicon.ico bez ikony nevygeneruje stránku 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/favicon.ico")" 204
curl -s -o "$WORK/response" "$B/manifest.webmanifest"; grep -q '"start_url"' "$WORK/response" && echo "  ok     manifest webu" || { echo "  CHYBA  manifest"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o /dev/null "$B/novinky"
expect "odkaz s utm parametry jde z cache" "$(curl -s -o /dev/null -D - "$B/novinky?utm_source=newsletter&fbclid=x" | grep -ci '^x-cache: kaleta')" 1
ETAG=$(curl -s -o /dev/null -D - "$B/novinky" | grep -i '^etag:' | cut -d' ' -f2 | tr -d '\r')
expect "stránka z cache odpoví 304 na shodný ETag" "$(curl -s -o /dev/null -w '%{http_code}' -H "If-None-Match: $ETAG" "$B/novinky")" 304

echo "== nové prvky builderu"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[
{\"typ\":\"drobecky\"},
{\"typ\":\"ikona\",\"obsah\":{\"ikona\":\"telefon\",\"tvar\":\"kruh\"}},
{\"typ\":\"galerie\",\"obsah\":{\"fotky\":[{\"src\":\"media/2026/01/a.jpg\",\"alt\":\"Dílna\"},{\"src\":\"media/2026/01/b.jpg\",\"alt\":\"\"}]}},
{\"typ\":\"zalozky\",\"obsah\":{\"karty\":[{\"nazev\":\"Základ\",\"obsah\":\"<p>A</p>\"},{\"nazev\":\"Plus\",\"obsah\":\"<p>B</p>\"}]}},
{\"typ\":\"karusel\",\"obsah\":{\"naraz\":\"2\"},\"deti\":[{\"typ\":\"text\",\"obsah\":{\"html\":\"<p>Snímek</p>\"}}]},
{\"typ\":\"mapa\",\"obsah\":{\"adresa\":\"Brno, Náměstí Svobody\"}},
{\"typ\":\"faq\",\"obsah\":{\"jedna\":true,\"faq\":false,\"polozky\":[{\"otazka\":\"Co?\",\"odpoved\":\"<p>To.</p>\"}]}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     nové prvky projdou validátorem" || { echo "  CHYBA  validace nových prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'class="ka-drobecky"' 'aria-current="page">Z HTML' 'class="ka-ikona ka-ikona--kruh" aria-hidden="true"><svg' 'class="ka-galerie"' 'alt="Dílna"' 'role="tablist"' 'aria-controls="zp-' 'data-karusel' '--ka-naraz:2' 'data-vlozit="https://maps.google.com/maps?q=Brno' 'name="faq-'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  nový prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
grep -q '"BreadcrumbList"' "$WORK/response" && ! grep -q '"FAQPage"' "$WORK/response" && echo "  ok     nové prvky na webu, drobečky i pro vyhledávače, akordeon bez FAQPage" || { echo "  CHYBA  strukturovaná data stránky"; ERRORS=$((ERRORS+1)); }

echo "== další prvky: počítadlo, průběh, hodnocení, odpočet, sítě, hledání, nahoru, odběr, podmínky"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('social_instagram','https://instagram.com/firma') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota)"
mcp stavba_uloz "{\"id\":$IDZ,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"obsah\":{\"video\":\"media/2026/01/pozadi.mp4\"},\"deti\":[
{\"typ\":\"pocitadlo\",\"obsah\":{\"cislo\":1200,\"za\":\"+\"}},
{\"typ\":\"prubeh\",\"obsah\":{\"polozky\":[{\"nazev\":\"Termíny\",\"hodnota\":96}]}},
{\"typ\":\"hodnoceni\",\"obsah\":{\"hodnota\":\"4,5\"}},
{\"typ\":\"odpocet\",\"obsah\":{\"cil\":\"2099-01-01 09:00\"}},
{\"typ\":\"socialni\"},{\"typ\":\"hledani\"},{\"typ\":\"nahoru\"},{\"typ\":\"newsletter\"},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Jen pro redakci\"},\"podminky\":{\"prihlaseni\":\"ano\"}},
{\"typ\":\"nadpis\",\"obsah\":{\"text\":\"Stará akce\"},\"podminky\":{\"do\":\"2000-01-01\"}},
{\"typ\":\"video\",\"obsah\":{\"url\":\"media/2026/01/film.mp4\",\"plakat\":\"media/2026/01/plakat.jpg\"}}
]}]}}" > "$WORK/response"
grep -q 'chyby\\":\[\]' "$WORK/response" && echo "  ok     další prvky projdou validátorem" || { echo "  CHYBA  validace dalších prvků"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/z-html"
for pattern in 'data-pocitadlo="1200">1' '<meter min="0" max="100"' 'aria-label="Hodnocení 4,5 z 5' 'data-odpocet="2099-01-01T09:00' 'class="ka-socialni"' 'aria-label="Instagram"' 'role="search"' 'class="ka-nahoru"' 'class="ka-newsletter"' 'name="as_podpis"' 'class="ka-video-pozadi"' 'poster="/media/2026/01/plakat.jpg"' 'image/web.js'; do
  grep -qF -- "$pattern" "$WORK/response" || { echo "  CHYBA  další prvek na webu: chybí $pattern"; ERRORS=$((ERRORS+1)); }
done
! grep -q 'Jen pro redakci\|Stará akce' "$WORK/response" && echo "  ok     podmínky zobrazení skryjí prvek nepřihlášenému i po datu" || { echo "  CHYBA  podmínky zobrazení"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/z-html"; ls "$WORK"/web/storage/cache/stranky/*.html >/dev/null 2>&1 && { echo "  CHYBA  stránka s podmínkou zobrazení šla do cache"; ERRORS=$((ERRORS+1)); } || echo "  ok     stránka s podmínkou zobrazení se necachuje"
curl -s -b "$JAR" -o "$WORK/response" "$B/z-html"; grep -q 'Jen pro redakci' "$WORK/response" && echo "  ok     přihlášený vidí prvek jen pro redakci" || { echo "  CHYBA  prvek pro přihlášené"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/z-html"
NEWSLETTER_FORM=$(tr '\n' ' ' < "$WORK/response" | grep -o 'class="ka-newsletter".*' | sed 's#</form>.*##')
NL_SIGNATURE=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_podpis" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'); NL_TIME=$(echo "$NEWSLETTER_FORM" | grep -o 'name="as_cas" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
sleep 5
code=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$B/odber" -d "email=Odber@Example.cz" -d zpet=/z-html -d kotva=x -d "as_podpis=$NL_SIGNATURE" -d "as_cas=$NL_TIME" -d web_adresa=)
case "$code" in "303 "*"/z-html?odber=ok#x") echo "  ok     přihlášení k odběru";; *) echo "  CHYBA  přihlášení k odběru: $code"; ERRORS=$((ERRORS+1));; esac
SUB_TOKEN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT token FROM ka_odberatele WHERE email = 'odber@example.cz' AND stav = 0")
check "odkaz z e-mailu jen nabídne potvrzení" 200 "/odber?potvrdit=$SUB_TOKEN" "Potvrdit odběr"
expect "otevření odkazu (skener pošty) odběr nepotvrdí" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "0"
curl -s -o "$WORK/response" -X POST "$B/odber?potvrdit=$SUB_TOKEN"; grep -q "Odběr je potvrzený" "$WORK/response" && echo "  ok     potvrzení odběru tlačítkem" || { echo "  CHYBA  potvrzení odběru"; ERRORS=$((ERRORS+1)); }
expect "odběratel je potvrzený" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT stav FROM ka_odberatele WHERE email = 'odber@example.cz'")" "1"
check "odběratelé v administraci" 200 "/admin.php?module=subscribers" "odber@example.cz"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers&action=csv"; grep -q "odber@example.cz;.*odber?odhlasit=$SUB_TOKEN" "$WORK/response" && echo "  ok     export odběratelů s odkazem na odhlášení" || { echo "  CHYBA  export odběratelů"; head -3 "$WORK/response"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'newsletter,', '') WHERE promenna = 'extensions'"
check "odhlášení jde i s vypnutým Newsletterem" 200 "/odber?odhlasit=$SUB_TOKEN" "Odhlásit odběr"
curl -s -o "$WORK/response" -X POST "$B/odber?odhlasit=$SUB_TOKEN"; grep -q "Odhlášeno" "$WORK/response" && echo "  ok     odhlášení tlačítkem" || { echo "  CHYBA  odhlášení"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = REPLACE(hodnota, 'poptavky,', 'poptavky,newsletter,') WHERE promenna = 'extensions'"
expect "odhlášený je smazaný" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_odberatele")" "0"

echo "== odběratelé do mailingové služby (falešný server)"
SERVICE_PORT=$((PORT + 2)); mkdir -p "$WORK/sluzba"
cat > "$WORK/sluzba/router.php" <<'PHP'
<?php
$log = __DIR__ . '/pozadavky.log';
if ($_SERVER['REQUEST_URI'] === '/_log') { header('Content-Type: text/plain'); @readfile($log); return true; }
$h = array_change_key_case(getallheaders());
file_put_contents($log, $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . ($h['api-key'] ?? $h['authorization'] ?? $h['key'] ?? '-') . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
if (str_contains($_SERVER['REQUEST_URI'], 'chyba')) { http_response_code(500); echo '{"message":"Invalid list"}'; return true; }
http_response_code(201); header('Content-Type: application/json'); echo '{}'; return true;
PHP
(cd "$WORK/sluzba" && exec php -S "127.0.0.1:$SERVICE_PORT" router.php > /dev/null 2>&1) & SERVICE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$SERVICE_PORT/_log" && break; sleep 0.2; done
set_service() { "${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('newsletter_service','$1'),('newsletter_key','$2'),('newsletter_list','$3'),('newsletter_webhook','$4'),('newsletter_test_url','http://127.0.0.1:$SERVICE_PORT'); DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES ('sluzba@example.cz', 1, '$(php -r 'echo bin2hex(random_bytes(16));')', NOW(), NOW())"; : > "$WORK/sluzba/pozadavky.log"; }
subscriber_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=subscribers"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=subscribers&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
last_request() { tail -1 "$WORK/sluzba/pozadavky.log"; }
set_service brevo brevo-klic 7 ''; subscriber_action sync
case "$(last_request)" in 'POST /brevo/v3/contacts brevo-klic {"email":"sluzba@example.cz","listIds":[7],"updateEnabled":true}') echo "  ok     Brevo: přidání do seznamu";; *) echo "  CHYBA  Brevo: $(last_request)"; ERRORS=$((ERRORS+1));; esac
expect "odběratel ve službě" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(sync, '/', (SELECT COUNT(*) FROM ka_odber_fronta)) FROM ka_odberatele")" "ok/0"
check "stav služby u odběratelů" 200 "/admin.php?module=subscribers" "odesláno"
IDOD=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_odberatele"); subscriber_action delete -d "ido=$IDOD"; subscriber_action retry
case "$(last_request)" in 'POST /brevo/v3/contacts/lists/7/contacts/remove brevo-klic {"emails":["sluzba@example.cz"]}') echo "  ok     Brevo: smazaný odběratel odebrán ze seznamu";; *) echo "  CHYBA  Brevo odebrání: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailchimp 'abc123-us21' 'aud1' ''; subscriber_action sync
case "$(last_request)" in "PUT /mailchimp/3.0/lists/aud1/members/$(php -r 'echo md5("sluzba@example.cz");') Basic $(printf 'kaleta:abc123-us21' | base64) "*'"status":"subscribed"'*) echo "  ok     Mailchimp: člen audience";; *) echo "  CHYBA  Mailchimp: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service mailerlite ml-klic 99 ''; subscriber_action sync
case "$(last_request)" in 'POST /mailerlite/api/subscribers Bearer ml-klic {"email":"sluzba@example.cz","groups":["99"],"status":"active"}') echo "  ok     MailerLite: odběratel ve skupině";; *) echo "  CHYBA  MailerLite: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service smartemailing 'jmeno:klic' 5 ''; subscriber_action sync
case "$(last_request)" in "POST /smartemailing/api/v3/import Basic $(printf 'jmeno:klic' | base64) "*'"contactlists":[{"id":5,"status":"confirmed"}]'*) echo "  ok     SmartEmailing: import do seznamu";; *) echo "  CHYBA  SmartEmailing: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service webhook '' '' 'https://hook.example.com/odber'; subscriber_action sync
case "$(last_request)" in 'POST /webhook/odber - {"udalost":"novy_odberatel",'*'"email":"sluzba@example.cz"'*) echo "  ok     webhook: nový odběratel";; *) echo "  CHYBA  webhook: $(last_request)"; ERRORS=$((ERRORS+1));; esac
set_service ecomail eco-klic chyba ''; subscriber_action sync
expect "nepovedený přenos čeká na další pokus s chybou" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(pokusy, '|', chyba LIKE 'HTTP 500%', '|', dalsi > NOW()) FROM ka_odber_fronta")" "1|1|1"
case "$(last_request)" in 'POST /ecomail/lists/chyba/subscribe eco-klic '*'"skip_confirmation":true'*) echo "  ok     Ecomail: přihlášení do seznamu";; *) echo "  CHYBA  Ecomail: $(last_request)"; ERRORS=$((ERRORS+1));; esac
mcp uprav_nastaveni '{}' | grep -q 'newsletter_klic\|eco-klic' && { echo "  CHYBA  MCP ukazuje klíč mailingové služby"; ERRORS=$((ERRORS+1)); } || echo "  ok     klíč mailingové služby MCP neukazuje"
kill "$SERVICE_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna LIKE 'newsletter\_%'; DELETE FROM ka_odber_fronta; DELETE FROM ka_odberatele"

echo "== webhooks: signature, delivery log and retries (1.8)"
check "Settings → Webhooks shows the secret and the log" 200 "/admin.php?module=settings&tab=webhooks" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_secret'")"
grep -q "<code>nova_poptavka</code>" "$WORK/response" && echo "  ok     the log lists the enquiry call" || { echo "  CHYBA  the delivery log"; ERRORS=$((ERRORS+1)); }
webhook_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=webhooks"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=$1" -d "_csrf=$(csrf)" -d tab=webhooks "${@:2}"; }
: > "$WORK/hook/calls.log"; webhook_action test_webhook
expect "test call signed and logged" "$(hook_check 1)" "test|signed|/crm"
db_q() { "${MYSQL[@]}" "$DB_NAME" -N -e "$1"; }
db_q "UPDATE ka_nastaveni SET hodnota = 'https://hooks.example.com/chyba' WHERE promenna = 'webhook_enquiries'"
: > "$WORK/hook/calls.log"; webhook_action test_webhook
FAILED=$(db_q "SELECT MAX(id) FROM ka_webhook_deliveries")
expect "a failed call waits for the next attempt with the reason" "$(db_q "SELECT CONCAT(attempts, '|', status, '|', error, '|', next_attempt > NOW(), '|', body IS NOT NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")" "1|500|HTTP 500|1|1"
for i in 2 3 4 5 6; do db_q "UPDATE ka_webhook_deliveries SET next_attempt = NOW() WHERE id = $FAILED"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"; done
expect "after six attempts the call is given up but kept for sending again" "$(db_q "SELECT CONCAT(attempts, '|', next_attempt IS NULL, '|', delivered IS NULL, '|', body IS NOT NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")|$(wc -l < "$WORK/hook/calls.log" | tr -d ' ')" "6|1|1|1|6"
check "the given-up call has Send again" 200 "/admin.php?module=settings&tab=webhooks" "name=\"id\" value=\"$FAILED\""
db_q "UPDATE ka_webhook_deliveries SET url = 'https://hooks.example.com/crm' WHERE id = $FAILED"
webhook_action retry_webhook -d "id=$FAILED"
expect "Send again delivers it" "$(db_q "SELECT CONCAT(attempts, '|', status, '|', delivered IS NOT NULL, '|', body IS NULL) FROM ka_webhook_deliveries WHERE id = $FAILED")" "7|204|1|1"
OLD_SECRET=$(db_q "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'webhook_secret'"); webhook_action new_webhook_secret
expect "a new secret replaces the old one" "$(db_q "SELECT hodnota != '$OLD_SECRET' AND hodnota LIKE 'whsec\_%' FROM ka_nastaveni WHERE promenna = 'webhook_secret'")" "1"
mcp site_info '{}' | grep -q "whsec_" && { echo "  CHYBA  MCP shows the webhook secret"; ERRORS=$((ERRORS+1)); } || echo "  ok     the webhook secret stays out of MCP"
db_q "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('webhook_enquiries', 'webhook_test_url')"
kill "$HOOK_PID" 2>/dev/null || true

echo "== newsletters (fake SMTP server)"
SMTP_PORT=$((PORT + 3)); mkdir -p "$WORK/smtp"
php "$ROOT/tools/fake-smtp.php" "$SMTP_PORT" "$WORK/smtp" > /dev/null 2>&1 & SMTP_PID=$!
db() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "$1"; }
tok() { php -r 'echo bin2hex(random_bytes(16));'; }
# eml <file>: headers, the decoded subject and the decoded text and HTML parts of a captured message
eml() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); echo $h, "\n"; preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n";
  preg_match_all("/base64\r\n\r\n([A-Za-z0-9+\/=\r\n]+)/", $b, $p); foreach ($p[1] as $x) { echo base64_decode($x), "\n"; }' "$1"; }
mail_to() { grep -l "^X-Rcpt-To: $1" "$WORK"/smtp/*.eml 2>/dev/null | tail -1; }
newsletter_action() { curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=newsletters"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=newsletters&action=$1" -d "_csrf=$(csrf)" "${@:2}"; }
ANNA=$(tok); PETR=$(tok)
db "UPDATE ka_uzivatele SET email = 'admin@example.cz' WHERE user = 'admin'; DELETE FROM ka_odberatele; INSERT INTO ka_odberatele (email, stav, token, datum, potvrzeno) VALUES
  ('anna@example.cz', 1, '$ANNA', NOW(), NOW()), ('petr@example.cz', 1, '$PETR', NOW(), NOW()), ('odmitnout@example.cz', 1, '$(tok)', NOW(), NOW()), ('ceka@example.cz', 0, '$(tok)', NOW(), NULL);
  REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('tasks_last_run', '0')"
check "newsletters: empty list" 200 "/admin.php?module=newsletters" "Napsat newsletter"
check "newsletters: new draft form" 200 "/admin.php?module=newsletters&action=new" 'name="subject"'
newsletter_action save -d id=0 --data-urlencode "subject=Jarní novinky" --data-urlencode "preheader=Co je nového" --data-urlencode $'intro=Dobrý den,\n\nposíláme novinky. Více na https://example.cz/akce' \
  -d news_mode=latest -d news_count=2 --data-urlencode "button_label=Všechny novinky" -d button_url=/novinky
NL=$(db "SELECT id FROM ka_newsletters ORDER BY id DESC LIMIT 1")
expect "newsletter draft saved" "$(db "SELECT CONCAT(status, '|', subject, '|', news_count) FROM ka_newsletters WHERE id = $NL")" "draft|Jarní novinky|2"
check "newsletter: e-mail preview" 200 "/admin.php?module=newsletters&action=preview&id=$NL" "utm_source=newsletter&amp;utm_medium=email&amp;utm_campaign=jarni-novinky"
expect "preview: 2 news items, linked address, button and unsubscribe" "$(grep -c 'Číst dál' "$WORK/response")|$(grep -c 'href="https://example.cz/akce"' "$WORK/response")|$(grep -c 'Všechny novinky' "$WORK/response")|$(grep -c 'Odhlásit odběr' "$WORK/response")" "2|1|1|1"
newsletter_action send -d "id=$NL" -d when=now
expect "no sending without an SMTP server" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')"
newsletter_action send -d "id=$NL" -d when=now
expect "no sending while cron does not run" "$(db "SELECT status FROM ka_newsletters WHERE id = $NL")" "draft"
check "newsletter form tells why it cannot send" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Cron za posledních 30 minut"
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
newsletter_action test -d "id=$NL"
F=$(mail_to admin@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "test e-mail to the signed-in user" "$(grep -c '^Subject-Decoded: \[Zkouška\] Jarní novinky$' "$WORK/eml.txt" 2>/dev/null)" "1"
newsletter_action send -d "id=$NL" -d when=now
expect "sending started for confirmed subscribers only" "$(db "SELECT CONCAT(status, '|', recipients, '|', html LIKE '%{{unsubscribe}}%') FROM ka_newsletters WHERE id = $NL")" "sending|3|1"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "cron sends a batch: 2 delivered, the refused one waits for a retry" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', (SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL AND next_attempt > NOW())) FROM ka_newsletters WHERE id = $NL")" "sending|2|0|1"
F=$(mail_to anna@example.cz); [ -n "$F" ] && eml "$F" > "$WORK/eml.txt"
expect "subscriber e-mail: one-click unsubscribe with the own link, no one else's" "$(grep -c "^List-Unsubscribe: <http://127.0.0.1:$PORT/odber?odhlasit=$ANNA>" "$WORK/eml.txt")|$(grep -c '^List-Unsubscribe-Post: List-Unsubscribe=One-Click' "$WORK/eml.txt")|$(grep -c "odhlasit=$ANNA" "$WORK/eml.txt")|$(grep -c "$PETR" "$WORK/eml.txt")" "1|1|3|0"
expect "subscriber e-mail: subject, text part and HTML part" "$(grep -c '^Subject-Decoded: Jarní novinky$' "$WORK/eml.txt")|$(grep -c '^Všechny novinky: http' "$WORK/eml.txt")|$(grep -c '<h1 ' "$WORK/eml.txt")" "1|1|1"
expect "newsletter recipients are not in the mail log" "$(db "SELECT COUNT(*) FROM ka_posta WHERE komu IN ('anna@example.cz', 'petr@example.cz')")" "0"
db "UPDATE ka_newsletter_queue SET next_attempt = NOW() WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
db "UPDATE ka_newsletter_queue SET next_attempt = NOW() WHERE next_attempt IS NOT NULL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a refused address is given up after three attempts, the newsletter is sent" "$(db "SELECT CONCAT(status, '|', sent_count, '|', failed_count, '|', finished_at IS NOT NULL) FROM ka_newsletters WHERE id = $NL")" "sent|2|1|1"
check "newsletters: list with counts" 200 "/admin.php?module=newsletters" "Odesláno"
check "a sent newsletter is read-only" 200 "/admin.php?module=newsletters&action=edit&id=$NL" "Příjemci"
contains -c 'name="subject"' "$WORK/response" && { echo "  CHYBA  a sent newsletter can still be edited"; ERRORS=$((ERRORS+1)); } || echo "  ok     a sent newsletter has no form"
curl -s -o /dev/null -X POST "$B/odber?odhlasit=$ANNA" -H 'Content-Type: application/x-www-form-urlencoded' -d 'List-Unsubscribe=One-Click'
expect "one-click unsubscribe from the mail client (RFC 8058)" "$(db "SELECT COUNT(*) FROM ka_odberatele WHERE email = 'anna@example.cz'")" "0"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' | contains '"name":"draft_newsletter"' && echo "  ok     MCP: newsletter tools listed" || { echo "  CHYBA  MCP: newsletter tools missing"; ERRORS=$((ERRORS+1)); }
db "DELETE FROM ka_odberatele WHERE email LIKE 'odmitnout%'"
mcp draft_newsletter '{"subject":"Novinky přes Clauda","intro":"Ahoj,\n\nkrátká zpráva.","news_mode":"none","button_label":"Kontakt","button_url":"/kontakt"}' > "$WORK/response"
NL2=$(mcp_value id)
expect "MCP: draft_newsletter returns the text version" "$(mcp_value status)|$(mcp_value text | grep -c '^Kontakt: http://127.0.0.1')" "draft|1"
mcp send_test_newsletter "{\"id\":$NL2}" > "$WORK/response"
expect "MCP: test goes to the connected user" "$(mcp_value sent_to)" "admin@example.cz"
mcp send_newsletter "{\"id\":$NL2,\"at\":\"2099-01-01 08:00\"}" > "$WORK/response"
expect "MCP: send_newsletter schedules" "$(mcp_value status)|$(mcp_value scheduled_at)" "scheduled|2099-01-01 08:00"
db "UPDATE ka_newsletters SET scheduled_at = NOW() - INTERVAL 1 MINUTE WHERE id = $NL2"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a due scheduled newsletter goes out on the next cron call" "$(db "SELECT CONCAT(status, '|', recipients, '|', sent_count) FROM ka_newsletters WHERE id = $NL2")" "sent|1|1"
mcp list_newsletters '{}' > "$WORK/response"
expect "MCP: list_newsletters with subscribers and no sending problem" "$(mcp_value confirmed_subscribers)|$(mcp_value sending_problem)|$(mcp_value newsletters 0 status)" "1|null|sent"
mcp delete_newsletter "{\"id\":$NL2}" > /dev/null
expect "MCP: delete_newsletter" "$(db "SELECT COUNT(*) FROM ka_newsletters WHERE id = $NL2")" "0"
db "UPDATE ka_newsletters SET finished_at = NOW() - INTERVAL 2 DAY WHERE id = $NL"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "recipients are kept only a day after sending" "$(db "SELECT COUNT(*) FROM ka_newsletter_queue WHERE newsletter_id = $NL")|$(db "SELECT sent_count FROM ka_newsletters WHERE id = $NL")" "0|2"
check "health: cron check" 200 "/admin.php?module=settings&tab=health" "Cron"
# 2.8: the domain and mail watch shows its group and the Check now button; a site on 127.0.0.1 makes no DNS or network request
grep -q "Doména a pošta" "$WORK/response" && grep -q "action=domain_check" "$WORK/response" && grep -q "běží na místní adrese" "$WORK/response" && echo "  ok     health: domain and mail watch – group, Check now, nothing checked on a local address" || { echo "  CHYBA  health: domain and mail watch group missing"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=domain_check" -d "_csrf=$TOKEN"
check "health: Check now stores the result and reports the local address" 200 "/admin.php?module=settings&tab=health" "Naposledy zkontrolováno"
expect "health: the check result is cached in the domain_watch setting" "$(db "SELECT JSON_EXTRACT(hodnota, '$.local') FROM ka_nastaveni WHERE promenna = 'domain_watch'")" "true"
grep -q "vlastni ve složce layout/" "$WORK/response" && echo "  ok     health: a leftover custom layout is reported" || { echo "  CHYBA  health: leftover custom layout not reported"; ERRORS=$((ERRORS+1)); }
kill "$SMTP_PID" 2>/dev/null || true
db "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', ''); DELETE FROM ka_odberatele; DELETE FROM ka_newsletters; DELETE FROM ka_newsletter_queue"

echo "== média, tokeny DTCG, kolekce přes MCP"
IDOM=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT ido FROM ka_media WHERE obr_poloha LIKE '%.jpg' ORDER BY ido DESC LIMIT 1")
check "média: hledání a řazení" 200 "/admin.php?module=media&hledat=jpg&razeni=velikost" 'data-popis-media='
reply=$(curl -s -b "$JAR" -c "$JAR" -X POST "$B/admin.php?module=media&action=save_caption" -d "_csrf=$TOKEN" -d "ido=$IDOM" --data-urlencode "popis=Dilna zevnitr")
expect "popis obrázku bez znovunačtení" "$reply|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT nazev FROM ka_media WHERE ido = $IDOM")" '{"ok":true}|Dilna zevnitr'
curl -s -b "$JAR" -o "$WORK/tokeny.json" "$B/admin.php?module=appearance&action=tokens"
grep -q '"\$type": "color"' "$WORK/tokeny.json" && grep -q '"cz.kaleta"' "$WORK/tokeny.json" && echo "  ok     export tokenů DTCG" || { echo "  CHYBA  export tokenů"; ERRORS=$((ERRORS+1)); }
printf '{"color":{"primary":{"$type":"color","$value":"#aa3300"}}}' > "$WORK/cizi.tokens.json"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/cizi.tokens.json"
publish_look
expect "import barev z cizích tokenů" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) FROM ka_nastaveni WHERE promenna = 'design_system'")" "#aa3300"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=appearance&action=tokens_import" -F "_csrf=$TOKEN" -F "tokeny=@$WORK/tokeny.json"
publish_look
expect "import vlastního exportu vrátí vzhled" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT JSON_UNQUOTE(JSON_EXTRACT(hodnota, '$.barvy.primarni')) <> '#aa3300' FROM ka_nastaveni WHERE promenna = 'design_system'")" "1"
mcp seznam_polozek_kolekce '{"kolekce":"tym","pole":"funkce","hodnota":"Mistr truhlář"}' > "$WORK/response"
grep -q 'Petr Svoboda' "$WORK/response" && grep -q 'celkem\\":1' "$WORK/response" && echo "  ok     kolekce přes MCP: filtr podle pole" || { echo "  CHYBA  kolekce přes MCP s filtrem"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
IDPS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idp FROM ka_kolekce_polozky WHERE nazev = 'Petr Svoboda'")
mcp uloz_polozku_kolekce "{\"kolekce\":\"tym\",\"id\":$IDPS,\"data\":{\"funkce\":\"Vedouci dilny\"}}" > /dev/null
expect "kolekce přes MCP: úprava položky bez názvu název zachová" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(nazev, '|', data LIKE '%Vedouci dilny%') FROM ka_kolekce_polozky WHERE idp = $IDPS")" "Petr Svoboda|1"

echo "== 1.9: collection items as pages, structured data, site audit, privacy template, deprecations"
JANA=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-novakova' AND jazyk = ''")
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"seo_title\":\"Jana Nováková, jednatelka\",\"description\":\"Vede dílnu dvacet let.\",\"share_image\":\"javascript:x\"}" > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/tym/jana-novakova"
grep -q '<title>Jana Nováková, jednatelka – ' "$WORK/response" && grep -q '<meta name="description" content="Vede dílnu dvacet let.">' "$WORK/response" && ! grep -q 'javascript:x' "$WORK/response" \
  && echo "  ok     item page: its own SEO title and description, an unsafe image dropped" || { echo "  CHYBA  item SEO fields: $(grep -o '<title>[^<]*' "$WORK/response")"; ERRORS=$((ERRORS+1)); }
mcp list_item_versions "{\"collection\":\"tym\",\"id\":$JANA}" > "$WORK/response"; VER=$(mcp_value versions 0 id)
mcp restore_item_version "{\"collection\":\"tym\",\"id\":$JANA,\"version\":$VER}" > /dev/null
expect "item versions: the earlier version comes back, the newer one goes to the history" "$(sq "SELECT CONCAT(seo_titulek = '', '|', (SELECT COUNT(*) FROM ka_stavba_revize WHERE cast = 'polozka:$JANA') >= 2) FROM ka_kolekce_polozky WHERE idp = $JANA")" "1|1"
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"noindex\":true}" > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/tym/jana-novakova" # into a file: grep -q on a pipe would cut curl off (pipefail)
grep -q 'content="noindex' "$WORK/response" && ! curl -s "$B/sitemap.xml" | grep -q '/tym/jana-novakova' && ! curl -s "$B/llms.txt" | grep -q '/tym/jana-novakova' \
  && echo "  ok     a noindex item is out of search engines, the sitemap and llms.txt" || { echo "  CHYBA  noindex item"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"tym\",\"id\":$JANA,\"noindex\":false}" > /dev/null
mcp save_collection_item '{"collection":"tym","name":"Planovany Clen","publish_at":"2099-01-01 08:00"}' > "$WORK/response"; PLAN=$(mcp_value id)
expect "a scheduled item waits hidden" "$(sq "SELECT CONCAT(zobrazit, '|', zverejnit_od IS NOT NULL) FROM ka_kolekce_polozky WHERE idp = $PLAN")" "0|1"
sq "UPDATE ka_kolekce_polozky SET zverejnit_od = NOW() - INTERVAL 1 MINUTE WHERE idp = $PLAN" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "the scheduled item publishes itself" "$(sq "SELECT CONCAT(zobrazit, '|', zverejnit_od IS NULL) FROM ka_kolekce_polozky WHERE idp = $PLAN")" "1|1"
IDK_TYM=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'tym'")
check "the item form has SEO fields, scheduling and the history" 200 "/admin.php?module=collections&action=item&id=$IDK_TYM&polozka=$JANA" 'Historie položky'
grep -q 'name="seo_titulek"' "$WORK/response" && grep -q 'name="zverejnit_od"' "$WORK/response" && echo "  ok     item form fields" || { echo "  CHYBA  item form fields"; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","structured_data":{"type":"Person","fields":{"jobTitle":"funkce"}}}' > /dev/null
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/tym/zuzana-zelena"
grep -q '"@type":"Person","name":"Zuzana Zelena"' "$WORK/response" && grep -q '"jobTitle":"Jednatelka"' "$WORK/response" \
  && echo "  ok     structured data of a collection: item pages are a Person" || { echo "  CHYBA  collection structured data"; grep -o '"@graph".\{0,600\}' "$WORK/response" | head -c 800; ERRORS=$((ERRORS+1)); }
mcp update_collection '{"collection":"tym","structured_data":{"type":"Recipe"}}' | grep -q 'Unknown structured data type' && echo "  ok     MCP: an unknown schema type is refused" || { echo "  CHYBA  MCP unknown schema type"; ERRORS=$((ERRORS+1)); }
check "the collection form offers structured data" 200 "/admin.php?module=collections&action=edit&id=$IDK_TYM" "Strukturovaná data pro vyhledávače"
mcp list_collections '{}' | grep -q 'jobTitle' && echo "  ok     MCP: list_collections shows the structured data" || { echo "  CHYBA  list_collections structured data"; ERRORS=$((ERRORS+1)); }
# site audit
mcp create_page '{"title":"Audit test","text":"<p><a href=\"/neexistuje-audit\">x</a> <a href=\"/tym/zuzana-zelena\">ok</a></p>","visible":true}' > /dev/null
check "Administration → Site audit finds a broken internal link" 200 "/admin.php?module=audit" "Odkaz /neexistuje-audit vede na stránku, která neexistuje"
grep -q '/tym/zuzana-zelena vede' "$WORK/response" && { echo "  CHYBA  the audit reports a working item link"; ERRORS=$((ERRORS+1)); } || echo "  ok     a link to an existing item is fine"
mcp site_audit '{"kind":"link"}' > "$WORK/response"
grep -q 'neexistuje-audit' "$WORK/response" && grep -q '\\"page\\":' "$WORK/response" && echo "  ok     MCP: site_audit with the target to fix" || { echo "  CHYBA  MCP site_audit"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_list() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'; }
mcp_list | php -r '$t = array_column(json_decode(stream_get_contents(STDIN), true)["result"]["tools"], null, "name"); exit(($t["site_audit"]["annotations"]["readOnlyHint"] ?? false) === true && ($t["restore_item_version"]["annotations"]["readOnlyHint"] ?? true) === false ? 0 : 1);' \
  && echo "  ok     MCP: site_audit is read-only, restore_item_version writes" || { echo "  CHYBA  MCP annotations of 1.9 tools"; ERRORS=$((ERRORS+1)); }
# addresses not found: bots are not recorded, what works again drops out, the warning can be dismissed
sq "DELETE FROM ka_nenalezeno" > /dev/null
for i in 1 2 3; do curl -s -o /dev/null "$B/wp/v2/users"; curl -s -o /dev/null "$B/_next"; curl -s -o /dev/null "$B/stara-cenik-2019"; curl -s -o /dev/null "$B/stary-kontakt"; done
sq "INSERT INTO ka_nenalezeno (cesta, pocet, naposledy) VALUES ('o-nas', 9, NOW())" > /dev/null
expect "404 log: bot probes are not recorded" "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta IN ('wp/v2/users', '_next')")" "0"
check "the start screen explains the 404 warning and offers to review it" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 2."
grep -q 'module=redirects#nenalezeno' "$WORK/response" && grep -q 'action=ignore_all' "$WORK/response" && echo "  ok     the warning links to the list and can be dismissed" || { echo "  CHYBA  404 warning actions"; ERRORS=$((ERRORS+1)); }
expect "an address that works again drops out of the log" "$(sq "SELECT COUNT(*) FROM ka_nenalezeno WHERE cesta = 'o-nas'")" "0"
check "the 404 list says what to do" 200 "/admin.php?module=redirects" "Ignorovat – nic ji nenahrazuje"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=ignore" -d "_csrf=$(csrf)" -d cesta=stary-kontakt
expect "Ignore hides one address for good" "$(sq "SELECT ignorovano IS NOT NULL FROM ka_nenalezeno WHERE cesta = 'stary-kontakt'")" "1"
curl -s -o /dev/null "$B/stary-kontakt"; check "an ignored address does not come back in the warning" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 1."
mcp ignore_not_found '{"all":true}' > "$WORK/response"
expect "MCP: ignore_not_found dismisses the rest" "$(mcp_value ignored)" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php"; grep -q 'skončily „stránka nenalezena“' "$WORK/response" && { echo "  CHYBA  the 404 warning stays after Ignore all"; ERRORS=$((ERRORS+1)); } || echo "  ok     after ignoring, the start screen has no 404 warning"
for i in 1 2 3; do curl -s -o /dev/null "$B/uplne-nova-adresa"; done
check "a new address brings the warning back" 200 "/admin.php" "opakovaně skončily „stránka nenalezena“: 1."
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=redirects&action=ignore_all" -d "_csrf=$(csrf)" -d zpet=prehled
# privacy policy from the enabled features
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=pages&action=new"; TOKEN=$(csrf)
save_page -d ids=0 --data-urlencode "titulek=Zásady test" -d sablona=zasady -d zobrazit=0 -d v_menu=0 -d text= > /dev/null
expect "privacy template: a disclaimer and only the enabled features" "$(sq "SELECT CONCAT(text LIKE '%nikoli právní rada%', '|', text LIKE '%poptávkovém formuláři%' OR text LIKE '%formuláře%', '|', text LIKE '%[ADDRESS]%' OR text LIKE '%[ADRESA]%' OR text LIKE '%sídlem%') FROM ka_stranky WHERE titulek = 'Zásady test'")" "1|1|1"
# streamed backup download and the media ZIP only on POST
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
LAST_BACKUP=$(ls -t "$WORK"/web/storage/zalohy/ | grep '^kaleta-' | head -1)
curl -s -b "$JAR" -o "$WORK/backup-download" "$B/admin.php?module=settings&action=download_backup&soubor=$LAST_BACKUP"
expect "a backup downloads whole (streamed)" "$(wc -c < "$WORK/backup-download" | tr -d ' ')" "$(wc -c < "$WORK/web/storage/zalohy/$LAST_BACKUP" | tr -d ' ')"
expect "the media ZIP is not built by a GET" "$(curl -s -b "$JAR" -o /dev/null -w '%{content_type}' "$B/admin.php?module=settings&action=media_backup" | tr 'A-Z' 'a-z')" "text/html; charset=utf-8"
expect "the media ZIP by POST, with the originals" "$(curl -s -b "$JAR" -o "$WORK/media.zip" -w '%{content_type}' -X POST "$B/admin.php?module=settings&action=media_backup" -d "_csrf=$TOKEN")|$([ "$(unzip -Z1 "$WORK/media.zip" 2>/dev/null | grep -c '^media/')" -gt 0 ] && echo files)" "application/zip|files"

echo "== moving a site: import of a Kaleta export into a new installation (1.8)"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=transfer&action=export" -d "_csrf=$TOKEN"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=transfer"; MOVE_EXPORT=$(grep -o 'export-[0-9]*-[0-9]*\.zip' "$WORK/response" | head -1)
curl -s -b "$JAR" -o "$WORK/presun.zip" "$B/admin.php?module=transfer&action=download&soubor=$MOVE_EXPORT"
MEDIA_IN_ZIP=$(unzip -Z1 "$WORK/presun.zip" | grep -c '^media/.')
[ "$MEDIA_IN_ZIP" -gt 0 ] && echo "  ok     the export carries the media ($MEDIA_IN_ZIP files)" || { echo "  CHYBA  no media in the export"; ERRORS=$((ERRORS+1)); }
PORT2=$((PORT + 5)); B2="http://127.0.0.1:$PORT2"; DB2="${DB_NAME}_presun"; JAR_MOVE="$WORK/cookies-presun.txt"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`; CREATE DATABASE \`$DB2\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web2" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web2")
mkdir -p "$WORK/web2/media" "$WORK/web2/storage/log" "$WORK/web2/storage/cache"
(cd "$WORK/web2" && exec php -S "127.0.0.1:$PORT2" system/dev-router.php > "$WORK/server2.log" 2>&1) & SERVER2_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B2/install.php" && break; sleep 0.2; done
curl -s -o "$WORK/response" -X POST "$B2/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB2" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Nový web" -d web=export -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=novinky'
grep -q "Pokračovat importem" "$WORK/response" && echo "  ok     installer: Start from an export leads to the import" || { echo "  CHYBA  installer with web=export"; sed 's/<[^>]*>//g' "$WORK/response" | grep -v '^\s*$' | head -10; ERRORS=$((ERRORS+1)); }
expect "installer: Start from an export leaves the site empty" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_stranky), '/', (SELECT COUNT(*) FROM ka_novinky), '/', (SELECT COUNT(*) FROM ka_uzivatele))")" "0/0/1"
curl -s -c "$JAR_MOVE" -b "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php"; curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
grep -q 'id="soubor-kaleta"' "$WORK/response" && echo "  ok     an empty site offers Import from Kaleta" || { echo "  CHYBA  Import from Kaleta form"; ERRORS=$((ERRORS+1)); }
location=$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=upload" -F "_csrf=$(csrf)" -F "soubor=@$WORK/presun.zip;type=application/zip")
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$location"
grep -q "Export webu „Testovací firma“" "$WORK/response" && grep -q 'name="potvrzeni"' "$WORK/response" && echo "  ok     preview of the export with counts and a confirmation" || { echo "  CHYBA  preview of the export: $location"; ERRORS=$((ERRORS+1)); }
MOVE_FILE=$(printf '%s' "$location" | sed 's/.*soubor=//')
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE"
expect "without the confirmation nothing starts" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT COUNT(*) FROM ka_stranky")" "0"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -X POST "$B2/admin.php?module=transfer&action=kaleta_run" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE" -d potvrzeni=1
for i in $(seq 1 80); do
  curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" -X POST "$B2/admin.php?module=transfer&action=kaleta" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE"
  grep -q "Web je naimportovaný\|Import se zastavil" "$WORK/response" && break
done
grep -q "Web je naimportovaný" "$WORK/response" && grep -q 'data-auto-odeslat' "$WORK/response" && { echo "  CHYBA  the result still submits itself"; ERRORS=$((ERRORS+1)); } || true
grep -q "Web je naimportovaný" "$WORK/response" && echo "  ok     the import went through in batches" || { echo "  CHYBA  import: $(sed 's/<[^>]*>//g' "$WORK/response" | grep -i 'import\|chyb' | head -5)"; ERRORS=$((ERRORS+1)); }
move_counts() { "${MYSQL[@]}" "$1" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_stranky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_novinky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_kategorie),
  (SELECT COUNT(*) FROM ka_kolekce), (SELECT COUNT(*) FROM ka_kolekce_polozky WHERE smazano IS NULL), (SELECT COUNT(*) FROM ka_komponenty), (SELECT COUNT(*) FROM ka_tridy), (SELECT COUNT(*) FROM ka_menu),
  (SELECT COUNT(*) FROM ka_popupy), (SELECT COUNT(*) FROM ka_presmerovani), (SELECT COUNT(*) FROM ka_media), (SELECT COUNT(*) FROM ka_novinky_stitky ns JOIN ka_novinky n ON n.idc = ns.idc WHERE n.smazano IS NULL))"; }
expect "the new site has the same content (pages/news/categories/collections/items/components/classes/menus/pop-ups/redirects/media/tags)" "$(move_counts "$DB2")" "$(move_counts "$DB_NAME")"
expect "same numbers: home page, site name and the design system came along" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB2" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'), (SELECT JSON_EXTRACT(hodnota, '$.barvy.primarni') FROM ka_nastaveni WHERE promenna = 'design_system'))")" "$("${MYSQL[@]}" --default-character-set=utf8mb4 "$DB_NAME" -N -e "SELECT CONCAT_WS('|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page'), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'), (SELECT JSON_EXTRACT(hodnota, '$.barvy.primarni') FROM ka_nastaveni WHERE promenna = 'design_system'))")"
expect "accounts and secrets stay on the new site (users, site address, tokens)" "$("${MYSQL[@]}" "$DB2" -N -e "SELECT CONCAT_WS('/', (SELECT COUNT(*) FROM ka_uzivatele), (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'), (SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('webhook_secret', 'smtp_password') AND hodnota <> ''), (SELECT COUNT(DISTINCT autor) FROM ka_novinky))")" "1/$B2/0/1"
[ "$("${MYSQL[@]}" "$DB2" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'")" != "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'tasks_token'")" ] && echo "  ok     the new site keeps its own cron address" || { echo "  CHYBA  the cron address came from the old site"; ERRORS=$((ERRORS+1)); }
MOVED_MEDIA=$("${MYSQL[@]}" "$DB2" -N -e "SELECT obr_poloha FROM ka_media ORDER BY ido LIMIT 1")
[ -n "$MOVED_MEDIA" ] && [ -f "$WORK/web2/$MOVED_MEDIA" ] && echo "  ok     the media files are on the new site" || { echo "  CHYBA  media file $MOVED_MEDIA"; ERRORS=$((ERRORS+1)); }
[ -z "$(find "$WORK/web2/media" -name '*.php')" ] && echo "  ok     no PHP came into media/" || { echo "  CHYBA  PHP in media/"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o "$WORK/response" -w '%{http_code}' "$B2/"); [ "$code" = 200 ] && grep -q "Testovací firma" "$WORK/response" && echo "  ok     the moved site runs" || { echo "  CHYBA  moved site: $code"; ERRORS=$((ERRORS+1)); }
SLUG_ITEM=$("${MYSQL[@]}" "$DB2" -N -e "SELECT seo_link FROM ka_stranky WHERE zobrazit = 1 AND smazano IS NULL AND ids <> (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'home_page') ORDER BY ids LIMIT 1")
expect "a moved page looks the same" "$(curl -s "$B2/$SLUG_ITEM" | grep -o '<h1[^>]*>[^<]*' | head -1)" "$(curl -s "$B/$SLUG_ITEM" | grep -o '<h1[^>]*>[^<]*' | head -1)"
curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o "$WORK/response" "$B2/admin.php?module=transfer"
grep -q 'id="soubor-kaleta"' "$WORK/response" && { echo "  CHYBA  a site with content still offers the import form"; ERRORS=$((ERRORS+1)); }
expect "a site with content refuses another import" "$(curl -s -b "$JAR_MOVE" -c "$JAR_MOVE" -o /dev/null -w '%{redirect_url}' -X POST "$B2/admin.php?module=transfer&action=kaleta_select" -d "_csrf=$(csrf)" -d "soubor=$MOVE_FILE" | grep -c 'kaleta')|$(curl -s -b "$JAR_MOVE" -o - "$B2/admin.php?module=transfer&action=kaleta&soubor=$MOVE_FILE" | grep -c 'name="potvrzeni"')" "1|0"
[ -s "$WORK/web2/storage/log/chyby.log" ] && { echo "  CHYBA  errors on the new site:"; cat "$WORK/web2/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); }
kill "$SERVER2_PID" 2>/dev/null || true; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB2\`"

echo "== záloha a obnova databáze"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$TOKEN"
BACKUP=$(ls -t "$WORK"/web/storage/zalohy/ 2>/dev/null | grep -v predobnovou | head -1 || true)
[ -n "$BACKUP" ] && echo "  ok     záloha vytvořena" || { echo "  CHYBA  záloha nevznikla"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Po zaloze' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BACKUP"
expect "obnova vrátí stav ze zálohy" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota <> 'Po zaloze' FROM ka_nastaveni WHERE promenna = 'site_name'")" "1"
BROKEN_BACKUP="kaleta-poskozena.sql"; [[ "$BACKUP" == *.gz ]] && BROKEN_BACKUP="kaleta-poskozena.sql.gz"
if [[ "$BACKUP" == *.gz ]]; then { gzip -dc "$WORK/web/storage/zalohy/$BACKUP" | head -c 4000 || true; } | gzip > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; else head -c 4000 "$WORK/web/storage/zalohy/$BACKUP" > "$WORK/web/storage/zalohy/$BROKEN_BACKUP"; fi
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'Pred poskozenou' WHERE promenna = 'site_name'"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=restore_backup" -d "_csrf=$TOKEN" -d "soubor=$BROKEN_BACKUP"
expect "poškozená záloha databázi nezmění" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")" "Pred poskozenou"

echo "== off-site copies: the database and the media, incrementally (fake S3, 1.8)"
S3_PORT=$((PORT + 6)); mkdir -p "$WORK/s3"
cat > "$WORK/s3/router.php" <<'PHP'
<?php
$h = array_change_key_case(getallheaders());
file_put_contents(__DIR__ . '/puts.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . strlen(file_get_contents('php://input')) . ' ' . (str_starts_with($h['authorization'] ?? '', 'AWS4-HMAC-SHA256 ') ? 'signed' : 'unsigned') . "\n", FILE_APPEND);
http_response_code(200); return true;
PHP
(cd "$WORK/s3" && exec php -S "127.0.0.1:$S3_PORT" router.php > /dev/null 2>&1) & S3_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$S3_PORT/ping" && break; sleep 0.2; done; : > "$WORK/s3/puts.log"
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('remote_backup', 's3'), ('backup_host', 's3.example.com'), ('backup_user', 'AKIDTEST'), ('backup_password', 'tajne-s3'),
  ('backup_folder', 'kaleta-zalohy'), ('backup_region', 'eu-central-1'), ('backup_test_url', 'http://127.0.0.1:$S3_PORT'), ('backup_media', '1'), ('remote_media_status', '')"
rm -f "$WORK/web/storage/zalohy/media-kopie.json"
MEDIA_FILES=$(cd "$WORK/web" && find media -type f ! -name '.*' ! -name '*.php' | wc -l | tr -d ' ')
backup_now() { curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=backup" -d "_csrf=$(csrf)"; }
backup_now
expect "the backup and every media file uploaded, signed (sql/media/unsigned)" "$(grep -c '^PUT /kaleta-zalohy/kaleta-.*\.sql' "$WORK/s3/puts.log")/$(grep -c '^PUT /kaleta-zalohy/media/' "$WORK/s3/puts.log")/$(grep -c 'unsigned' "$WORK/s3/puts.log")" "1/$MEDIA_FILES/0"
expect "media status: complete" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT SUBSTRING_INDEX(hodnota, '|', -2) FROM ka_nastaveni WHERE promenna = 'remote_media_status'")" "ok|0"
check "Backups show the media copy" 200 "/admin.php?module=settings&tab=backups" "Média: kopie je kompletní"
: > "$WORK/s3/puts.log"; backup_now
expect "the next backup uploads no unchanged media" "$(grep -c '^PUT /kaleta-zalohy/media/' "$WORK/s3/puts.log")" "0"
mkdir -p "$WORK/web/media/2026/09" && echo "novy" > "$WORK/web/media/2026/09/novy-soubor.txt"; : > "$WORK/s3/puts.log"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'media_sync_check'"; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "cron copies only the new file" "$(cat "$WORK/s3/puts.log")" "PUT /kaleta-zalohy/media/2026/09/novy-soubor.txt 5 signed"
# a daily backup when something changed, otherwise it waits for the week
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('auto_backups', '1'), ('remote_backup', 'vypnuto'); INSERT INTO ka_protokol (cas, modul, akce) VALUES (NOW(), 'test', 'change')"
touch -t "$(date -v-2d +%Y%m%d%H%M 2>/dev/null || date -d '-2 days' +%Y%m%d%H%M)" "$WORK"/web/storage/zalohy/kaleta-*
BEFORE=$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-'); curl -s -b "$JAR" -o /dev/null "$B/admin.php"
expect "a change since the last backup (older than a day) makes a new automatic one" "$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-')" "$((BEFORE + 1))"
touch -t "$(date -v-2d +%Y%m%d%H%M 2>/dev/null || date -d '-2 days' +%Y%m%d%H%M)" "$WORK"/web/storage/zalohy/kaleta-*
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_protokol SET cas = NOW() - INTERVAL 3 DAY WHERE cas > NOW() - INTERVAL 3 DAY; UPDATE ka_poptavky SET datum = NOW() - INTERVAL 3 DAY WHERE datum > NOW() - INTERVAL 3 DAY"
curl -s -b "$JAR" -o /dev/null "$B/admin.php"
expect "without a change no new backup before the week is over" "$(ls "$WORK"/web/storage/zalohy/ | grep -c -- '-auto-')" "$((BEFORE + 1))"
kill "$S3_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('backup_host', 'backup_test_url', 'backup_password', 'remote_media_status'); UPDATE ka_nastaveni SET hodnota = 'vypnuto' WHERE promenna = 'remote_backup'"

echo "== role přes MCP, obnova hesla, zámek účtu"
SUB_TOKEN2="kaleta_$(printf 'b%.0s' $(seq 1 48))"
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$SUB_TOKEN2")', NOW() FROM ka_uzivatele WHERE user = 'obchodnik'"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $SUB_TOKEN2" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"vytvor_novinku","arguments":{"titulek":"Od obchodnika","kategorie":"aktuality"}}}' > "$WORK/response"
grep -q 'nemáš přístup' "$WORK/response" && expect "vlastní role bez Novinek nezaloží novinku ani přes MCP" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_novinky WHERE titulek = 'Od obchodnika'")" "0" || { echo "  CHYBA  MCP bez kontroly sekce Novinky"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FAKE_REFRESH="$(printf 'c%.0s' $(seq 1 64))"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET obnova_otisk = '$(php -r 'echo hash("sha256", $argv[1]);' "$FAKE_REFRESH")', obnova_cas = NOW() + INTERVAL 1 DAY WHERE user = 'obchodnik'"
JAR3="$WORK/jar3"
TOKEN3=$(curl -s -c "$JAR3" "$B/admin.php?action=password&token=$FAKE_REFRESH" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
curl -s -b "$JAR3" -c "$JAR3" -o /dev/null -X POST "$B/admin.php?action=password" -d "_csrf=$TOKEN3" -d "token=$FAKE_REFRESH" --data-urlencode "password=Nove-heslo-123" --data-urlencode "password2=Nove-heslo-123"
expect "obnova hesla zruší tokeny napojení" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny t JOIN ka_uzivatele u ON u.idu = t.idu WHERE u.user = 'obchodnik'")" "0"
JAR4="$WORK/jar4"
TOKEN4=$(curl -s -c "$JAR4" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
for i in $(seq 1 10); do curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik -d password=spatne-heslo-xyz; done
code=$(curl -s -b "$JAR4" -c "$JAR4" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$TOKEN4" -d user=obchodnik --data-urlencode "password=Nove-heslo-123")
expect "po 10 chybách je účet dočasně zamčený i pro správné heslo" "$code|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zamceno_do > NOW() FROM ka_uzivatele WHERE user = 'obchodnik'")" "401|1"

echo "== 2.8: security hygiene – unused accounts and Claude connections, automatic suspension"
# an administrator and an editor nobody has used for 100 days, an old personal token of the editor, an unused token of the admin created 70 days ago and a token used today
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_uzivatele (user, password, jmeno, admin, posledni_login, potvrzeno) VALUES ('stary-spravce', '\$2y\$12\$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu', 'Stary Spravce', 2, NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 100 DAY), ('stary-editor', '\$2y\$12\$6C4TPEcYRJ/rRw6iWsrlxu0aH1i91pzK/8KiwqEW3Pa6sTj89Q3Zu', '', 1, NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 100 DAY);
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, pouzit) SELECT idu, 'stary token', SHA2('hygiene-old', 256), NOW() - INTERVAL 100 DAY, NOW() - INTERVAL 100 DAY FROM ka_uzivatele WHERE user = 'stary-editor';
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, expirace) SELECT idu, 'nepouzity token', SHA2('hygiene-unused', 256), NOW() - INTERVAL 70 DAY, NOW() + INTERVAL 1 YEAR FROM ka_uzivatele WHERE user = 'admin';
  INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren, pouzit, expirace) SELECT idu, 'zivy token', SHA2('hygiene-live', 256), NOW() - INTERVAL 70 DAY, NOW(), NOW() + INTERVAL 1 YEAR FROM ka_uzivatele WHERE user = 'admin'"
check "System status lists the unused accounts and connections with links" 200 "/admin.php?module=settings&tab=health" "Nepoužívané účty"
grep -q 'Stary Spravce (poslední aktivita' "$WORK/response" && grep -q 'stary-editor (poslední aktivita' "$WORK/response" && grep -q 'stary token (stary-editor)' "$WORK/response" && grep -q 'nepouzity token (Tester)' "$WORK/response" && ! grep -q 'zivy token (Tester)' "$WORK/response" \
  && grep -q 'vypnuto – nepoužívané účty a napojení se jen hlásí' "$WORK/response" && echo "  ok     System status: two unused accounts, two unused connections, the live token is fine, suspension off" || { echo "  CHYBA  System status hygiene findings"; grep -o 'Účty a přístup.*' "$WORK/response" | head -c 1500; ERRORS=$((ERRORS+1)); }
check "the settings form offers the automatic suspension" 200 "/admin.php?module=settings&tab=general" 'name="auto_suspend\[\]"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' "$WORK/response" > "$WORK/text"
contains -qE '"handover": ?"unused_account"' "$WORK/text" && contains -qE '"handover": ?"unused_connection"' "$WORK/text" && contains -qE '"handover": ?"auto_suspend"' "$WORK/text" && echo "  ok     site audit: unused accounts and connections, suspension off, before handing over" || { echo "  CHYBA  hand-over audit hygiene"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# the daily run (the 2.8 scheduler calls SecurityHygiene::run once a day): here straight from the command line against the test site
run_hygiene() { php -r 'chdir($argv[1]); require "system/bootstrap.php"; $app = new Kaleta\Core\App(require "config.php"); $app->applyTimezone(); echo json_encode(Kaleta\Core\SecurityHygiene::run($app));' "$WORK/web"; }
expect "run() does nothing while the automatic suspension is off" "$(run_hygiene)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_uzivatele WHERE blokovat = 1 AND user LIKE 'stary-%'")" '{"blocked":[],"revoked":[]}|0'
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('auto_suspend', 'ucty,napojeni')"
expect "run() blocks the unused accounts and revokes the unused connections" "$(run_hygiene)" '{"blocked":["stary-editor","Stary Spravce"],"revoked":["stary token (stary-editor)","nepouzity token (Tester)"]}'
expect "the admin in use stays, the old accounts are blocked with the reason, the live token stays" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT CONCAT(blokovat, blokovano_automaticky IS NULL) FROM ka_uzivatele WHERE user = 'admin'), '|', (SELECT GROUP_CONCAT(CONCAT(user, ':', blokovat, ':', blokovano_automaticky IS NOT NULL) ORDER BY user) FROM ka_uzivatele WHERE user LIKE 'stary-%'), '|', (SELECT GROUP_CONCAT(nazev ORDER BY nazev) FROM ka_api_tokeny WHERE nazev LIKE '%token'))")" "01|stary-editor:1:1,stary-spravce:1:1|zivy token"
expect "every automatic action is in the change log" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_protokol WHERE modul = 'users' AND akce = 'auto_block'), '/', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'claude' AND akce = 'auto_revoke'))")" "2/2"
expect "every automatic action is an event without names (alerts, list_events)" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'security.account_suspended'), '/', (SELECT COUNT(*) FROM ka_events WHERE type = 'security.connection_revoked'))")" "2/2"
expect "a blocked account cannot use its token" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer hygiene-old" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" "401"
check "Users shows why the account is blocked" 200 "/admin.php?module=users" "Zablokován automaticky"
grep -q '(zablokován automaticky)' "$WORK/response" && grep -q 'action=reactivate' "$WORK/response" && echo "  ok     Users: the automatic block is labelled and can be reactivated" || { echo "  CHYBA  Users list without the automatic block"; ERRORS=$((ERRORS+1)); }
IDS_OLD=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'stary-editor'")
check "the user form explains the automatic block" 200 "/admin.php?module=users&action=edit&id=$IDS_OLD" "Odškrtněte políčko a uložte"
TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=reactivate" -d "_csrf=$TOKEN" -d "idu=$IDS_OLD" -d user=stary-editor
expect "reactivation unblocks the account and confirms it" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(blokovat, blokovano_automaticky IS NULL, potvrzeno > NOW() - INTERVAL 1 MINUTE) FROM ka_uzivatele WHERE user = 'stary-editor'")" "011"
expect "a reactivated account is not blocked again by the next run" "$(run_hygiene)" '{"blocked":[],"revoked":[]}'
IDS_ADMIN=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idu FROM ka_uzivatele WHERE user = 'admin'")
IDT_LIVE=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT idt FROM ka_api_tokeny WHERE nazev = 'zivy token'")
check "the administrator sees the connections of an account" 200 "/admin.php?module=users&action=edit&id=$IDS_ADMIN" 'id="napojeni"'
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=users&action=revoke_connection" -d "_csrf=$TOKEN" -d "idu=$IDS_ADMIN" -d "idt=$IDT_LIVE" -d user=admin
expect "the administrator revokes a connection from the user form" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE nazev = 'zivy token'")" "0"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'auto_suspend'"

echo "== OAuth pro konektor Claude"
curl -s -o "$WORK/response" -D "$WORK/hlavicky" -X POST "$B/mcp" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize"}'
grep -qi 'www-authenticate: Bearer resource_metadata="http://127.0.0.1:[0-9]*/.well-known/oauth-protected-resource"' "$WORK/hlavicky" && echo "  ok     MCP bez tokenu odkáže na metadata OAuth" || { echo "  CHYBA  WWW-Authenticate u MCP"; ERRORS=$((ERRORS+1)); }
check "metadata chráněného zdroje" 200 "/.well-known/oauth-protected-resource" '"authorization_servers"'
check "metadata autorizačního serveru" 200 "/.well-known/oauth-authorization-server" '"code_challenge_methods_supported":\["S256"\]'
REDIRECT_URI="https://claude.ai/api/mcp/auth_callback"
curl -s -o "$WORK/response" -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d "{\"client_name\":\"Claude\",\"redirect_uris\":[\"$REDIRECT_URI\"],\"token_endpoint_auth_method\":\"none\"}"
CLIENT=$(grep -o '"client_id":"[a-f0-9]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$CLIENT" ] && echo "  ok     dynamická registrace klienta" || { echo "  CHYBA  registrace klienta"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "registrace odmítne http adresu návratu" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/register" -H 'Content-Type: application/json' -d '{"redirect_uris":["http://zly.example/cb"]}')" 400
VERIFIER="$(printf 'v%.0s' $(seq 1 50))"; CHALLENGE=$(printf %s "$VERIFIER" | openssl dgst -binary -sha256 | openssl base64 | tr '+/' '-_' | tr -d '=')
expect "cizí adresa návratu se nepřesměruje" "$(curl -s -o /dev/null -w '%{http_code}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=https://zly.example/&code_challenge=$CHALLENGE&code_challenge_method=S256")" 400
code=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=xyz&scope=mcp")
case "$code" in "302 "*action=oauth) echo "  ok     přihlášení vede na souhlas v administraci";; *) echo "  CHYBA  authorize: $code"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -D "$WORK/hlavicky" "$B/admin.php?action=oauth"; grep -q 'Povolit přístup' "$WORK/response" && echo "  ok     stránka souhlasu" || { echo "  CHYBA  stránka souhlasu"; ERRORS=$((ERRORS+1)); }
grep -qi "form-action 'self' https://claude.ai;" "$WORK/hlavicky" && ! grep -qi "x-kaleta-form-action" "$WORK/hlavicky" && echo "  ok     CSP souhlasu povolí návrat do aplikace (form-action)" || { echo "  CHYBA  CSP form-action na stránce souhlasu"; grep -i "content-security" "$WORK/hlavicky"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
case "$REDIRECT" in "$REDIRECT_URI?code="*"state=xyz"*) echo "  ok     souhlas vrátí kód a state do aplikace";; *) echo "  CHYBA  návrat po souhlasu: $REDIRECT"; ERRORS=$((ERRORS+1));; esac
expect "špatný code_verifier (PKCE) neprojde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d code_verifier=spatny-overovac-spatny-overovac-spatny-overovac)" 400
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=abc" && curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d povolit=1)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
ACCESS_TOKEN=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true); REFRESH_TOKEN=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
[ -n "$ACCESS_TOKEN" ] && [ -n "$REFRESH_TOKEN" ] && echo "  ok     výměna kódu za tokeny (PKCE)" || { echo "  CHYBA  token endpoint"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "kód jde použít jen jednou" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER")" 400
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $ACCESS_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
grep -q 'builder_schema' "$WORK/response" && echo "  ok     MCP s přístupovým tokenem z OAuth" || { echo "  CHYBA  MCP s tokenem OAuth"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "obnovovací token nejde použít k MCP" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/mcp" -H "Authorization: Bearer $REFRESH_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}')" 401
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT"
grep -q '"access_token"' "$WORK/response" && echo "  ok     obnova tokenu" || { echo "  CHYBA  obnova tokenu"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "obnovovací token se po použití vymění" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_TOKEN" -d "client_id=$CLIENT")" 400

echo "== 2.2: connection access, change log, instructions, prompts, settings over MCP"
expect "an OAuth connection approved without a choice (a consent page from before 2.2) has full access" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(DISTINCT access) FROM ka_api_tokeny WHERE klient = '$CLIENT'")" "full"
REDIRECT=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=drafts" && curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php?action=oauth" -d "_csrf=$OAUTH_CSRF" -d povolit=1 -d access=drafts)
AUTH_CODE=$(printf %s "$REDIRECT" | grep -o 'code=[a-f0-9]*' | sed 's/code=//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=authorization_code -d "code=$AUTH_CODE" -d "redirect_uri=$REDIRECT_URI" -d "client_id=$CLIENT" -d "code_verifier=$VERIFIER"
REFRESH_DRAFTS=$(grep -o '"refresh_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
curl -s -o "$WORK/response" -X POST "$B/oauth/token" -d grant_type=refresh_token -d "refresh_token=$REFRESH_DRAFTS" -d "client_id=$CLIENT"
OAUTH_DRAFTS=$(grep -o '"access_token":"[a-z0-9_]*"' "$WORK/response" | sed 's/.*:"//;s/"//' || true)
expect "drafts only chosen on the consent screen stays after a token refresh" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT access FROM ka_api_tokeny WHERE otisk = SHA2('$OAUTH_DRAFTS', 256)")" "drafts"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $OAUTH_DRAFTS" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
contains -q '"name":"save_build"' "$WORK/response" && ! contains -q '"name":"publish_build"' "$WORK/response" && ! contains -q '"name":"update_settings"' "$WORK/response" && echo "  ok     a drafts-only connection lists only reads and draft tools" || { echo "  CHYBA  tools/list for drafts"; ERRORS=$((ERRORS+1)); }
# personal tokens with limited access (My account)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; ACCOUNT_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude read" -d access=read
READ_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -X POST "$B/admin.php?action=account" -d "_csrf=$ACCOUNT_CSRF" -d co=token_novy -d "nazev=Claude drafts" -d access=drafts
DRAFT_TOKEN=$(grep -o 'kaleta_[a-f0-9]\{48\}' "$WORK/response" | head -1 || true)
expect "tokens from My account keep the chosen access" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(access ORDER BY nazev) FROM ka_api_tokeny WHERE nazev IN ('Claude read', 'Claude drafts')")" "drafts,read"
mcp_text() { php -r 'echo json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "";' "$WORK/response" > "$WORK/text"; } # the tool result itself
mcp_as() { curl -s -X POST "$B/mcp" -H "Authorization: Bearer $1" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$2\",\"arguments\":$3}}"; }
mcp_as "$READ_TOKEN" create_page '{"title":"From a read-only connection"}' > "$WORK/response"
contains -q 'can only read the site' "$WORK/response" && expect "a read-only connection changes nothing" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE titulek = 'From a read-only connection'")" 0 || { echo "  CHYBA  read-only connection wrote"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$READ_TOKEN" get_page '{"id":1}' > "$WORK/response"; contains -q '"isError":true' "$WORK/response" && { echo "  CHYBA  a read-only connection cannot read"; ERRORS=$((ERRORS+1)); } || echo "  ok     a read-only connection reads"
mcp_as "$DRAFT_TOKEN" create_page '{"title":"Drafted by Claude","visible":true}' > "$WORK/response"
mcp_text; DRAFT_PAGE=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://' || true)
expect "a drafts-only connection creates a page, but hidden" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT zobrazit FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}'")" 0
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft\"}}]}]}}" > "$WORK/response"
contains -q 'Publishing needs' "$WORK/response" && expect "publishing over a drafts-only connection is refused before anything is saved" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COALESCE(stavba_koncept, 'none') FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}'")" "none" || { echo "  CHYBA  save_build publish over drafts"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"heading\",\"content\":{\"text\":\"Draft\"}}]}]}}" > "$WORK/response"
mcp_text; contains -q '"status":"draft' "$WORK/text" && echo "  ok     a drafts-only connection saves a draft build" || { echo "  CHYBA  drafts: save_build"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp_as "$DRAFT_TOKEN" update_settings '{"settings":{"site_name":"Hijacked"}}' > "$WORK/response"
contains -q 'can only save drafts' "$WORK/response" && echo "  ok     a drafts-only connection does not change settings" || { echo "  CHYBA  drafts: update_settings"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 2.5.1: an administrator's drafts-only connection must not reach the administrator's browser through a draft preview
mcp_as "$DRAFT_TOKEN" save_build "{\"id\":${DRAFT_PAGE:-0},\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"custom_html\",\"content\":{\"code\":\"<p>drafted-code</p>\"}}]}]}}" > /dev/null
expect "a drafts-only connection cannot insert Custom HTML" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_stranky WHERE ids = '${DRAFT_PAGE:-0}' AND stavba_koncept LIKE '%drafted-code%'")" 0
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_newsletters (subject, intro, status, scheduled_at, created) VALUES ('Scheduled 251', 'Original intro', 'scheduled', NOW() + INTERVAL 1 DAY, NOW())"
NL_SCHEDULED=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT id FROM ka_newsletters WHERE subject = 'Scheduled 251'")
mcp_as "$DRAFT_TOKEN" draft_newsletter "{\"id\":$NL_SCHEDULED,\"intro\":\"Changed by a drafts connection\"}" > /dev/null
expect "a drafts-only connection cannot change a scheduled newsletter" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT intro FROM ka_newsletters WHERE id = $NL_SCHEDULED")" "Original intro"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_newsletters WHERE id = $NL_SCHEDULED"
# wrong tokens from one address do not lock out a valid one (a proxy in front of Docker, Claude's servers)
for i in $(seq 1 21); do mcp_as "kaleta_$(printf '0%.0s' $(seq 1 48))" get_page '{"id":1}' > /dev/null; done
mcp get_page '{"id":1}' > "$WORK/response"; contains -q '"isError":true\|"error"' "$WORK/response" && { echo "  CHYBA  wrong tokens locked out a valid token"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); } || echo "  ok     wrong tokens do not lock out a valid token"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'mcp'"
expect "the change log names the Claude connection" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) > 0 FROM ka_protokol WHERE via = 'Claude drafts' AND modul = 'claude'")" 1
mcp list_changes '{"by":"claude","limit":5}' > "$WORK/response"
mcp_text; contains -q '"claude_connection":"Claude drafts"' "$WORK/text" && echo "  ok     list_changes tells Claude's changes and their connection" || { echo "  CHYBA  list_changes"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "the change log in the admin filters Claude's changes" 200 "/admin.php?module=changelog&by=claude" 'Claude: Claude drafts'
# the site owner's instructions, resources, prompts and the protocol version
mcp update_settings '{"settings":{"claude_instructions":"Always say renovation, never reconstruction."}}' > /dev/null
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}' > "$WORK/response"
contains -q '"protocolVersion":"2025-03-26"' "$WORK/response" && contains -q 'Always say renovation' "$WORK/response" && contains -q '"prompts"' "$WORK/response" && echo "  ok     initialize: the client's protocol version, the owner's instructions, resources and prompts" || { echo "  CHYBA  initialize"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $DRAFT_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"1999-01-01"}}' > "$WORK/response"
contains -q '"protocolVersion":"2025-06-18"' "$WORK/response" && contains -q 'CAN ONLY SAVE DRAFTS' "$WORK/response" && echo "  ok     initialize tells a drafts-only connection its limits" || { echo "  CHYBA  initialize drafts"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://instructions"}}' > "$WORK/response"
contains -q 'Always say renovation' "$WORK/response" && echo "  ok     the instructions as an MCP resource" || { echo "  CHYBA  resources/read"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"prompts/get","params":{"name":"build_page","arguments":{"topic":"kitchens"}}}' > "$WORK/response"
contains -q 'Build a new page about kitchens' "$WORK/response" && echo "  ok     prompts/get fills in a ready-made task" || { echo "  CHYBA  prompts/get"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"resources/read","params":{"uri":"kaleta://nope"}}' > "$WORK/response"
contains -q '"code":-32602' "$WORK/response" && echo "  ok     an unknown resource is a JSON-RPC error" || { echo "  CHYBA  resources/read unknown"; ERRORS=$((ERRORS+1)); }
# settings that were admin-only before 2.2
mcp update_settings '{"settings":{"extensions":["novinky","poptavky"]}}' > "$WORK/response"
contains -q 'cannot switch itself off' "$WORK/response" && echo "  ok     Claude cannot switch its own connection off" || { echo "  CHYBA  extensions without claude"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
EXT_BEFORE=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'extensions'")
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$EXT_BEFORE" | sed 's/,/","/g')\",\"asistent\"],\"additional_languages\":[\"xx\"],\"llms_txt\":\"0\",\"indexing\":\"1\"}}" > "$WORK/response"
mcp_text; contains -q 'Unknown language codes: xx' "$WORK/text" && contains -q '"asistent"' "$WORK/text" && expect "extensions, SEO switches and languages over MCP, checked" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'llms_txt'")" 0 || { echo "  CHYBA  settings parity"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings "{\"settings\":{\"extensions\":[\"$(printf %s "$EXT_BEFORE" | sed 's/,/","/g')\"],\"llms_txt\":\"1\"}}" > /dev/null
check "OAuth metadata for a site in a subfolder (openid-configuration)" 200 "/.well-known/openid-configuration" '"token_endpoint"'

echo "== 2.3: leads, statistics, forms, embeds, page head code, accessibility audit"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'"
curl -s -o /dev/null -A 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148' "$B/?utm_source=facebook&utm_medium=paid&utm_campaign=autumn"
expect "a visit from a campaign on a phone counts in the statistics" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COALESCE((SELECT SUM(navstevy) FROM ka_stat_kampane WHERE kampan = 'facebook / paid / autumn'), 0), '|', COALESCE((SELECT SUM(navstevy) FROM ka_stat_zarizeni WHERE zarizeni = 'phone'), 0) > 0)")" "1|1"
# a form with ticked options and a hidden value, an embed and code in the head of one page
mcp vytvor_stranku '{"titulek":"Leads 23","zobrazit":true}' > "$WORK/response"; mcp_text; PAGE23=$(grep -o '"id":[0-9]*' "$WORK/text" | head -1 | sed 's/"id"://')
mcp stavba_uloz "{\"id\":$PAGE23,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Poptavka 23\",\"pole\":[{\"popisek\":\"Sluzby\",\"typ\":\"zaskrtnuti\",\"povinne\":true,\"moznosti_zaskrtnuti\":\"Kuchyne\\nKoupelna\"},{\"popisek\":\"Produkt\",\"typ\":\"skryte\",\"hodnota\":\"Dubovy stul\"},{\"popisek\":\"Email\",\"typ\":\"email\",\"povinne\":true}]}},{\"typ\":\"vlozeni\",\"obsah\":{\"adresa\":\"https://calendly.com/acme/consultation\",\"titulek\":\"Book a consultation\"}},{\"typ\":\"vlozeni\",\"obsah\":{\"adresa\":\"https://evil.example/x\"}}]}]}}" > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET kod_hlavicky = '<meta name=\"kaleta-test\" content=\"23\">' WHERE ids = $PAGE23" # set in the administration, never through MCP (2.5.1)
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'type="checkbox" name="p0\[\]" value="Kuchyne"' "$WORK/formular.html" && ! grep -q 'Dubovy stul' "$WORK/formular.html" && echo "  ok     ticked options on the page, the hidden value not" || { echo "  CHYBA  checkboxes / hidden field"; ERRORS=$((ERRORS+1)); }
grep -q 'data-vlozit="https://calendly.com/acme/consultation?embed_type=Inline&amp;hide_gdpr_banner=1"' "$WORK/formular.html" && ! grep -q 'evil.example' "$WORK/formular.html" && echo "  ok     Embed: a known service after a click, anything else not at all" || { echo "  CHYBA  Embed"; ERRORS=$((ERRORS+1)); }
grep -q '<meta name="kaleta-test" content="23">' "$WORK/formular.html" && ! curl -s "$B/" | grep -q 'kaleta-test' && echo "  ok     code in the head of one page only" || { echo "  CHYBA  page head code"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$PAGE23,\"head_code\":\"<script>x()</script>\"}" > "$WORK/response"
contains -q 'only in the administration' "$WORK/response" && echo "  ok     MCP cannot set head code, not even with full access (2.5.1)" || { echo "  CHYBA  MCP: head code"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"head_code":"<script>x()</script>","marketing_code":"<script>y()</script>"}}' > "$WORK/response"
contains -q 'set only in the administration' "$WORK/response" && [ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_nastaveni WHERE promenna IN ('head_code','marketing_code') AND hodnota LIKE '%<script>%'")" = 0 ] \
  && echo "  ok     MCP cannot set code for the whole site (2.5.1)" || { echo "  CHYBA  MCP: site code"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
curl -s -o /dev/null -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Koupelna' --data-urlencode p2=petr@example.cz \
  -d ka_vstup=/sluzby --data-urlencode "ka_kampan=utm_source=google&utm_medium=cpc&utm_campaign=kuchyne" -d ka_odkud=google.com
expect "an enquiry carries the first page, the campaign and the referring site of the visit" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(vstup, '|', odkud, '|', kampan) FROM ka_poptavky WHERE email = 'petr@example.cz'")" "/sluzby|google.com|utm_source=google&utm_medium=cpc&utm_campaign=kuchyne"
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"campaign":"google / cpc / kuchyne"' "$WORK/text" && contains -q '"device":"phone"' "$WORK/text" && contains -q '"path":"/sluzby"' "$WORK/text" && echo "  ok     get_stats: campaigns, devices and the first pages of leads" || { echo "  CHYBA  get_stats"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
check "Statistics: pages, campaigns and first pages that bring leads" 200 "/admin.php?module=stats&dni=7" "google / cpc / kuchyne"
# 2.8: real-user speed – the beacon script only with the statistics on and only for visitors, one beacon per page view, the table in Statistics, get_stats and the audit
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/sluzby"
grep -q '<script src="/image/vitals.js?v=[^"]*" defer data-vitals="/vitals"></script>' "$WORK/response" && ! grep -q 'blocking="render" data-vitals' "$WORK/response" && echo "  ok     2.8: the speed beacon script loads deferred with the statistics on" || { echo "  CHYBA  vitals.js on the page"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/sluzby"; ! grep -q 'vitals.js' "$WORK/response" && echo "  ok     2.8: no speed beacon for signed-in users" || { echo "  CHYBA  vitals.js for a signed-in user"; ERRORS=$((ERRORS+1)); }
expect "2.8: a beacon answers 204" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=1800 -d cls=0.05 -d inp=120)" 204
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/neexistuje-vitals -d lcp=1800   # a page the statistics never saw
curl -s -o /dev/null -X POST "$B/vitals" -d path=/sluzby -d lcp=1800                                  # curl's own user agent = a bot
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=999999 -d cls=abc # out of range, not numeric
expect "2.8: the beacon lands in histogram buckets per metric; made-up pages, bots and nonsense do not" "$(sq "SELECT GROUP_CONCAT(CONCAT(path, ':', metric, ':', bucket, ':', samples) ORDER BY metric) FROM ka_web_vitals")" "/sluzby:cls:2:1,/sluzby:inp:2:1,/sluzby:lcp:3:1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=stats&dni=7"
grep -q 'href="/sluzby"' "$WORK/response" && grep -qE '2[.,]0 s <span class="stitek stitek-vydano">' "$WORK/response" && grep -qE '150 ms <span class="stitek stitek-vydano">' "$WORK/response" && echo "  ok     2.8: Statistics show p75 LCP, CLS and INP per page with the rating" || { echo "  CHYBA  Statistics: real-user speed"; ERRORS=$((ERRORS+1)); }
mcp get_stats '{"days":7}' > "$WORK/response"; mcp_text
contains -q '"web_vitals":\[{"path":"/sluzby","samples":1,"lcp_p75":2000' "$WORK/text" && contains -q '"lcp_rating":"good"' "$WORK/text" && contains -q '"cls_p75":0.05' "$WORK/text" && contains -q '"inp_p75":150' "$WORK/text" && echo "  ok     2.8: get_stats carries web_vitals" || { echo "  CHYBA  get_stats web_vitals"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('stats', '0')"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/sluzby"; ! grep -q 'vitals' "$WORK/response" && echo "  ok     2.8: statistics off – no beacon script on the page" || { echo "  CHYBA  vitals.js with the statistics off"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null -X POST "$B/vitals" -A 'Mozilla/5.0 test' -d path=/sluzby -d lcp=1800
expect "2.8: statistics off – a beacon is not counted" "$(sq "SELECT SUM(samples) FROM ka_web_vitals")" 3
"${MYSQL[@]}" "$DB_NAME" -e "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('stats', '1')"; rm -f "$WORK"/web/storage/cache/stranky/*.html
# the audit: a page whose p75 LCP went from 2.0 s (30 measurements 35 days ago) to 3.0 s (30 measurements today) is flagged, /sluzby with one measurement is not
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_web_vitals (day, path, metric, bucket, samples) VALUES (CURDATE() - INTERVAL 35 DAY, '/audit-pomalu', 'lcp', 3, 30), (CURDATE(), '/audit-pomalu', 'lcp', 5, 30)"
mcp site_audit '{"kind":"speed"}' > "$WORK/response"; mcp_text
contains -q '"path":"/audit-pomalu"' "$WORK/text" && contains -qE '3[.,]0 s' "$WORK/text" && ! contains -q '/sluzby' "$WORK/text" && echo "  ok     2.8: the site audit flags a page whose p75 LCP got worse by more than 25 %" || { echo "  CHYBA  speed audit"; head -c 600 "$WORK/text"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_web_vitals WHERE path = '/audit-pomalu'"
case "$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode p2=tick@example.cz)" in *vysledek=pole\&pole=0*) echo "  ok     a required group needs at least one ticked option";; *) echo "  CHYBA  required checkbox group"; ERRORS=$((ERRORS+1));; esac
curl -s -o /dev/null -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Kuchyne' -d 'p0[]=Podvrh' -d p1=Hacked --data-urlencode p2=tick@example.cz
expect "ticked options (only offered ones) and the form's own hidden value are saved" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT data FROM ka_poptavky WHERE email = 'tick@example.cz'")" '[["Sluzby","Kuchyne"],["Produkt","Dubovy stul"],["Email","tick@example.cz"]]'
# accessibility in the site audit
mcp vytvor_stranku '{"titulek":"Access 23","zobrazit":true,"text":"<p>Prices: <a href=\"/sluzby\">click here</a>.</p><table><tr><td>1</td></tr></table>"}' > /dev/null
mcp site_audit '{"kind":"accessibility"}' > "$WORK/response"; mcp_text
contains -q 'click here' "$WORK/text" && contains -q 'header cells' "$WORK/text" && contains -q 'accessibility statement' "$WORK/text" && echo "  ok     site audit: link texts, tables and the accessibility statement" || { echo "  CHYBA  accessibility audit"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
# 2.4 for agencies: a ready-made role, whom to ask for help, the check before handing the site over
check "2.4: ready-made Client role fills the form" 200 "/admin.php?module=roles&action=new&preset=client" 'name="nazev" value="Klient"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; mcp_text
contains -qE '"handover": ?"agency"' "$WORK/text" && contains -qE '"handover": ?"smtp"' "$WORK/text" && echo "  ok     hand-over check: agency contact and SMTP missing" || { echo "  CHYBA  hand-over audit"; head -c 500 "$WORK/text"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"agency_name":"Studio Test","agency_email":"help@studio.example","agency_phone":"+420 777 123 456"}}' > /dev/null
curl -s -o "$WORK/response" "$B/admin.php"
grep -q 'Studio Test' "$WORK/response" && grep -q 'mailto:help@studio.example' "$WORK/response" && grep -q 'tel:+420777123456' "$WORK/response" && echo "  ok     sign-in screen shows whom to ask for help" || { echo "  CHYBA  agency contact on the sign-in screen"; ERRORS=$((ERRORS+1)); }
check "2.4: admin footer shows the agency" 200 "/admin.php?module=pages" 'class="agentura"'
mcp site_audit '{"kind":"handover"}' > "$WORK/response"; mcp_text
contains -qE '"handover": ?"agency"' "$WORK/text" && { echo "  CHYBA  hand-over audit still misses the agency contact"; ERRORS=$((ERRORS+1)); } || echo "  ok     hand-over check: the agency contact is set"
# the cookie bar: remembering leads needs consent to marketing; Global Privacy Control counts as "only necessary"
mcp update_settings '{"settings":{"cookies_mode":"vestavena","lead_attribution":"1"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q 'data-kategorie="marketing"' "$WORK/response" && grep -q 'ka-puvod' "$WORK/response" && grep -q 'globalPrivacyControl' "$WORK/response" && grep -q 'name="ka_vstup"' "$WORK/response" && echo "  ok     cookie bar: marketing consent for lead origins, Global Privacy Control" || { echo "  CHYBA  cookie bar with lead attribution"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"lead_attribution":"0"}}' > /dev/null
# 2.6: an optional CAPTCHA on top of the built-in protection, checked with the provider on the server
mkdir -p "$WORK/captcha" && cat > "$WORK/captcha/router.php" <<'CAPTCHA'
<?php
$answer = $_POST['response'] ?? '';
header('Content-Type: application/json');
echo json_encode(($_POST['secret'] ?? '') !== 'test-secret' ? ['success' => false] : match ($answer) { 'pass' => ['success' => true, 'score' => 0.9], 'low' => ['success' => true, 'score' => 0.2], default => ['success' => false] });
CAPTCHA
(cd "$WORK/captcha" && exec php -S "127.0.0.1:$CAPTCHA_PORT" router.php > /dev/null 2>&1) & CAPTCHA_PID=$!
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_provider','turnstile'),('captcha_site_key','test-site'),('captcha_secret','test-secret') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota); DELETE FROM ka_kontrola_ip WHERE typ IN ('formular','odber')"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'class="ka-captcha cf-turnstile" data-sitekey="test-site"' "$WORK/formular.html" && [ "$(grep -o 'challenges.cloudflare.com/turnstile/v0/api.js' "$WORK/formular.html" | wc -l | tr -d ' ')" = 1 ] \
  && echo "  ok     CAPTCHA: the Turnstile widget in the form, its script once" || { echo "  CHYBA  CAPTCHA widget"; ERRORS=$((ERRORS+1)); }
FORM_SOURCE=$(field_value zdroj); FORM_ELEMENT=$(field_value prvek); FORM_TIME=$(field_value as_cas); FORM_SIGNATURE=$(field_value as_podpis); sleep 4
captcha_post() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d zpet=/leads-23 -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d 'p0[]=Koupelna' --data-urlencode "p2=$1" "${@:2}"; }
case "$(captcha_post fail@example.cz -d cf-turnstile-response=wrong)" in *vysledek=captcha*) echo "  ok     CAPTCHA: a failed check is refused";; *) echo "  CHYBA  CAPTCHA: failed check"; ERRORS=$((ERRORS+1));; esac
case "$(captcha_post none@example.cz)" in *vysledek=captcha*) echo "  ok     CAPTCHA: a form without the answer is refused";; *) echo "  CHYBA  CAPTCHA: missing answer"; ERRORS=$((ERRORS+1));; esac
captcha_post pass@example.cz -d cf-turnstile-response=pass > /dev/null
expect "CAPTCHA: a passed check saves the enquiry, the failed ones not" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(email ORDER BY email) FROM ka_poptavky WHERE email IN ('fail@example.cz','none@example.cz','pass@example.cz')")" "pass@example.cz"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = 'recaptcha' WHERE promenna = 'captcha_provider'"; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/formular.html" "$B/leads-23"
grep -q 'name="g-recaptcha-response" value="" data-recaptcha="test-site"' "$WORK/formular.html" && grep -q 'recaptcha/api.js?render=test-site' "$WORK/formular.html" || { echo "  CHYBA  reCAPTCHA v3 field and script"; ERRORS=$((ERRORS+1)); }
case "$(captcha_post low@example.cz -d g-recaptcha-response=low)" in *vysledek=captcha*) echo "  ok     reCAPTCHA v3: a low score is refused";; *) echo "  CHYBA  reCAPTCHA score"; ERRORS=$((ERRORS+1));; esac
kill "$CAPTCHA_PID" 2>/dev/null; wait "$CAPTCHA_PID" 2>/dev/null || true; CAPTCHA_PID=
captcha_post down@example.cz -d g-recaptcha-response=pass > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni (promenna, hodnota) VALUES ('captcha_fail_open', '0') ON DUPLICATE KEY UPDATE hodnota = '0'"
case "$(captcha_post closed@example.cz -d g-recaptcha-response=pass)" in *vysledek=captcha*) ;; *) echo "  CHYBA  CAPTCHA: fail closed"; ERRORS=$((ERRORS+1));; esac
expect "CAPTCHA: when the provider is down the owner's choice decides" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT GROUP_CONCAT(email) FROM ka_poptavky WHERE email IN ('down@example.cz','closed@example.cz')")" "down@example.cz"
mcp update_settings '{"settings":{"captcha_secret":"stolen","captcha_provider":"hcaptcha"}}' > "$WORK/response"
[ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'captcha_secret'")" = "test-secret" ] && mcp update_settings '{}' > "$WORK/response" && ! contains -q 'test-secret' "$WORK/response" \
  && echo "  ok     CAPTCHA: Claude can neither set nor read the secret key" || { echo "  CHYBA  CAPTCHA secret over MCP"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_nastaveni WHERE promenna LIKE 'captcha_%'"
# 2.6: Google Tag Manager with consent mode – with the built-in bar it starts only after consent, without a bar right away
mcp update_settings '{"settings":{"gtm_id":"GTM-TEST123","cookies_mode":"vestavena"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q '<script type="text/plain" data-gtm>(function(w,d,s,l,i)' "$WORK/response" && grep -q "gtag('consent','default',{ad_storage:'denied'" "$WORK/response" && grep -q "'dataLayer','GTM-TEST123'" "$WORK/response" \
  && grep -q 'data-kategorie="analytika"' "$WORK/response" && grep -q 'data-kategorie="marketing"' "$WORK/response" && echo "  ok     GTM: consent mode, the container waits for the cookie bar" || { echo "  CHYBA  GTM with the cookie bar"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"cookies_mode":"zadna"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
curl -s -o "$WORK/response" "$B/leads-23"
grep -q "<script>(function(w,d,s,l,i)" "$WORK/response" && ! grep -q "gtag('consent','default'" "$WORK/response" && echo "  ok     GTM: without a cookie bar the container loads right away" || { echo "  CHYBA  GTM without a bar"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"gtm_id":"GTM-<x>"}}' > "$WORK/response"
[ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'gtm_id'")" = "GTM-TEST123" ] || { echo "  CHYBA  GTM: an invalid container ID was saved"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"gtm_id":"","cookies_mode":"vestavena"}}' > /dev/null; rm -f "$WORK"/web/storage/cache/stranky/*.html
# 2.6: import from a website – a small "old site" with a sitemap, a header, a footer, an image and a blog post
OLD_PORT=$((PORT + 10)); OLD="http://127.0.0.1:$OLD_PORT"
mkdir -p "$WORK/oldsite/about-us" "$WORK/oldsite/blog/first-post" "$WORK/oldsite/img"
php -r '$i = imagecreatetruecolor(400, 300); imagefill($i, 0, 0, imagecolorallocate($i, 40, 120, 90)); imagepng($i, $argv[1]);' "$WORK/oldsite/img/team.png"
printf 'User-agent: *\nSitemap: %s/sitemap.xml\n' "$OLD" > "$WORK/oldsite/robots.txt"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/</loc></url><url><loc>%s/about-us/</loc></url><url><loc>%s/blog/first-post/</loc></url></urlset>' "$OLD" "$OLD" "$OLD" > "$WORK/oldsite/sitemap.xml"
oldpage() { printf '<!doctype html><html><head><title>%s | Old Oak</title><meta name="description" content="%s"></head><body><header><nav><a href="/">Old home</a> <a href="/about-us/">About</a></nav></header><main><h1>%s</h1>%s</main><footer>Old footer 1990</footer></body></html>' "$1" "$2" "$1" "$3"; }
oldpage "Welcome" "The old home page" "<p>Old Oak makes furniture by hand in our workshop near the river, since many years, for homes and offices alike.</p>" > "$WORK/oldsite/index.html"
oldpage "About us" "Who we are" '<p>We build oak furniture since 1990, for homes and offices across the region and beyond it, always by hand.</p><img src="/img/team.png" alt="Our team"><p><a href="/blog/first-post/">Read our story</a></p><div class="cookie-notice">We use cookies</div>' > "$WORK/oldsite/about-us/index.html"
printf '<!doctype html><html><head><title>Our first post | Old Oak</title><meta property="article:published_time" content="2024-05-06T09:00:00+02:00"></head><body><article><h1>Our first post</h1><p>Today we opened the new workshop for visitors, come and see how a table is made from a single oak.</p></article></body></html>' > "$WORK/oldsite/blog/first-post/index.html"
(cd "$WORK/oldsite" && exec php -S "127.0.0.1:$OLD_PORT" > /dev/null 2>&1) & OLDSITE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$OLD/" && break; sleep 0.3; done
import_field() { php -r '$r = json_decode(json_decode(file_get_contents($argv[1]), true)["result"]["content"][0]["text"] ?? "{}", true); echo is_array($r[$argv[2]] ?? null) ? json_encode($r[$argv[2]]) : ($r[$argv[2]] ?? "");' "$WORK/response" "$1"; }
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
[ "$(import_field phase)" = preview ] && [ "$(import_field found)" = 3 ] && contains -q '/about-us' "$WORK/response" && echo "  ok     website import: three pages found in the sitemap, shown before importing" || { echo "  CHYBA  website import: finding pages"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
[ "$(import_field phase)" = done ] || { echo "  CHYBA  website import did not finish"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "website import: pages hidden, the post as a hidden news item" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT((SELECT CONCAT(titulek, ':', zobrazit) FROM ka_stranky WHERE seo_link = 'about-us'), '|', (SELECT CONCAT(titulek, ':', visible, ':', DATE(datum)) FROM ka_novinky WHERE titulek = 'Our first post'))")" "About us:0|Our first post:0:2024-05-06"
ABOUT=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(text, ' ', IFNULL(stavba, '')) FROM ka_stranky WHERE seo_link = 'about-us'")
echo "$ABOUT" | grep -q 'oak furniture' && echo "$ABOUT" | grep -q 'media/' && ! echo "$ABOUT" | grep -qE 'Old footer|Old home|We use cookies|127\.0\.0\.1' && echo "$ABOUT" | grep -q '"typ":"nadpis"' \
  && echo "  ok     website import: the content in the builder, the image in Media, no header, footer or cookie bar" || { echo "  CHYBA  website import: page content"; echo "$ABOUT" | head -c 600; ERRORS=$((ERRORS+1)); }
[ "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_presmerovani WHERE z_adresy = 'blog/first-post' AND na_adresu LIKE 'novinky/%'")" = 1 ] && echo "  ok     website import: the old address of the post redirects" || { echo "  CHYBA  website import: redirect"; ERRORS=$((ERRORS+1)); }
mcp import_website "{\"url\":\"$OLD\"}" > "$WORK/response"; IMPORT_ID=$(import_field import_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = finding ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
mcp import_website "{\"import_id\":\"$IMPORT_ID\",\"confirm\":true}" > "$WORK/response"
for i in $(seq 1 20); do [ "$(import_field phase)" = importing ] || break; mcp import_website "{\"import_id\":\"$IMPORT_ID\"}" > "$WORK/response"; done
expect "website import: running it again skips what is already there" "$(import_field result)" '{"new_pages":0,"new_news":0,"images":0,"redirects":0,"skipped":3,"failed":0}'
# 2.7: the migration report – a fourth old page with a form that nothing on the new site answers
# (one warning throughout: the old home page had a search engine description, the new starter home page has none)
mkdir -p "$WORK/oldsite/contact"
oldpage "Contact" "Write to us" '<p>Write to us about a table, a chair or a whole kitchen and we answer within two working days, promised.</p><form action="/send"><input name="email"><textarea name="message"></textarea></form>' > "$WORK/oldsite/contact/index.html"
printf '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>%s/</loc></url><url><loc>%s/about-us/</loc></url><url><loc>%s/blog/first-post/</loc></url><url><loc>%s/contact/</loc></url></urlset>' "$OLD" "$OLD" "$OLD" "$OLD" > "$WORK/oldsite/sitemap.xml"
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "migration report: four old addresses – the imported ones not published yet, the contact page missing" "$(import_field summary)" '{"addresses":4,"checked":4,"ok":1,"redirected":0,"not_published":2,"missing":1,"errors":1,"warnings":3}'
contains -q '/contact' "$WORK/response" && contains -q 'form_missing\|missing' "$WORK/response" && contains -q 'site_checks' "$WORK/response" && echo "  ok     migration report: the missing page first, then the checks of the whole site" || { echo "  CHYBA  migration report rows"; head -c 900 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_redirect '{"from":"/contact","to":"/about-us"}' > /dev/null
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 1 WHERE seo_link = 'about-us'"
mcp migration_report "{\"url\":\"$OLD\"}" > "$WORK/response"; REPORT_ID=$(import_field report_id)
for i in $(seq 1 20); do [ "$(import_field phase)" = done ] && break; mcp migration_report "{\"report_id\":\"$REPORT_ID\"}" > "$WORK/response"; done
expect "migration report: after a redirect and publishing, the contact address redirects (but the form is gone)" "$(import_field summary)" '{"addresses":4,"checked":4,"ok":2,"redirected":1,"not_published":1,"missing":0,"errors":1,"warnings":2}'
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_stranky SET zobrazit = 0 WHERE seo_link = 'about-us'; DELETE FROM ka_presmerovani WHERE z_adresy = 'contact'"
kill "$OLDSITE_PID" 2>/dev/null; OLDSITE_PID=
# 2.7: old form entries (e.g. Breakdance submissions) come over into Enquiries, once
ENTRIES='[{"date":"2025-03-14 09:30","form":"Contact","page":"/contact","fields":{"Name":"Jana Old","E-mail":"jana.old@example.cz","Message":"A table please"}},{"date":"2025-03-15 10:00","form":"Contact","fields":[{"label":"Phone","value":"777 000 111"}]}]'
mcp import_enquiries "{\"source\":\"breakdance\",\"entries\":$ENTRIES}" > "$WORK/response"
expect "import_enquiries: two old entries imported" "$(import_field imported)|$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT CONCAT(COUNT(*), ':', MAX(email), ':', MIN(stav)) FROM ka_poptavky WHERE zdroj = 'import:breakdance'")" "2|2:jana.old@example.cz:1"
mcp import_enquiries "{\"source\":\"breakdance\",\"entries\":$ENTRIES}" > "$WORK/response"
expect "import_enquiries: a second run skips them" "$(import_field imported):$(import_field already_imported)" "0:2"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'" # limit přihlášení z IP vyčerpal test zámku účtu
JAR5="$WORK/jar5"
curl -s -c "$JAR5" -b "$JAR5" -o /dev/null "$B/oauth/authorize?response_type=code&client_id=$CLIENT&redirect_uri=$REDIRECT_URI&code_challenge=$CHALLENGE&code_challenge_method=S256&state=nove"
TOKEN5=$(curl -s -b "$JAR5" -c "$JAR5" "$B/admin.php?action=oauth" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
code=$(curl -s -b "$JAR5" -c "$JAR5" -o /dev/null -w '%{redirect_url}' -X POST "$B/admin.php" -d "_csrf=$TOKEN5" -d user=admin --data-urlencode "password=$PASSWORD")
case "$code" in *action=oauth) echo "  ok     nepřihlášený se po přihlášení vrátí na souhlas";; *) echo "  CHYBA  návrat na souhlas po přihlášení: $code"; ERRORS=$((ERRORS+1));; esac
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?action=account"; grep -q 'Připojené aplikace' "$WORK/response" && echo "  ok     připojená aplikace v Můj účet" || { echo "  CHYBA  připojené aplikace"; ERRORS=$((ERRORS+1)); }
OAUTH_CSRF=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?action=account" -d "_csrf=$OAUTH_CSRF" -d "odpojit_klient=$CLIENT"
expect "odpojení aplikace smaže její tokeny" "$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT COUNT(*) FROM ka_api_tokeny WHERE klient = '$CLIENT'")" "0"

echo "== dvoufázové přihlášení (TOTP a záložní kódy)"
"${MYSQL[@]}" "$DB_NAME" -e "DELETE FROM ka_kontrola_ip WHERE typ = 'login'; UPDATE ka_uzivatele SET totp_tajemstvi = 'JBSWY3DPEHPK3PXP', totp_zalozni = '[\"$(php -r 'echo hash("sha256", "abcde-12345");')\"]' WHERE user = 'autor'"
sign_in_2fa() { # prihlas2fa <jar> → vrátí kód odpovědi na zadání druhého kroku <kod>
  local jar="$1" t; t=$(curl -s -c "$jar" -b "$jar" "$B/admin.php" | grep -o 'name="_csrf" value="[a-f0-9]*"' | head -1 | sed 's/.*value="//;s/"//' || true)
  curl -s -b "$jar" -c "$jar" -o "$WORK/response" -X POST "$B/admin.php" -d "_csrf=$t" -d user=autor --data-urlencode "password=$PASSWORD"
  grep -q 'name="kod"' "$WORK/response" || echo "bez-druheho-kroku"
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$B/admin.php" -d "_csrf=$t" -d krok=kod -d "kod=$2"
}
expect "špatný kód z aplikace neprojde" "$(sign_in_2fa "$WORK/jar6" 000000)" "401"
TOTP_CODE=$(php -r 'require $argv[1] . "/system/src/Core/Totp.php"; echo Kaleta\Core\Totp::code("JBSWY3DPEHPK3PXP", intdiv(time(), 30));' "$ROOT")
expect "přihlášení s kódem z aplikace (TOTP)" "$(sign_in_2fa "$WORK/jar7" "$TOTP_CODE")" "302"
expect "záložní kód projde" "$(sign_in_2fa "$WORK/jar8" abcde-12345)" "302"
expect "záložní kód jde použít jen jednou" "$(sign_in_2fa "$WORK/jar9" abcde-12345)" "401"
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_uzivatele SET totp_tajemstvi = '', totp_zalozni = NULL WHERE user = 'autor'; DELETE FROM ka_kontrola_ip WHERE typ = 'login'"

echo "== vypnutá rozšíření Novinky a Formuláře a poptávky"
EXTENSIONS=$("${MYSQL[@]}" "$DB_NAME" -N -e "SELECT hodnota FROM ka_nastaveni WHERE promenna='extensions'")
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='statistika,presmerovani,claude' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html "$WORK"/web/storage/cache/*.txt 2>/dev/null || true
check "výpis novinek je pryč" 404 /novinky
check "novinka je pryč" 404 /novinky/vitejte-v-kalete
check "RSS je pryč" 404 /rss.xml
curl -s -o "$WORK/response" "$B/sitemap.xml"; ! grep -q "/novinky" "$WORK/response" && echo "  ok     mapa webu bez novinek" || { echo "  CHYBA  mapa webu s vypnutými novinkami"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'rss.xml' "$WORK/response" && echo "  ok     bez novinek ani odkaz na RSS" || { echo "  CHYBA  odkaz na RSS při vypnutých novinkách"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; ! grep -q 'href="[^"]*/novinky"' "$WORK/response" && echo "  ok     menu bez odkazu na novinky" || { echo "  CHYBA  menu odkazuje na vypnuté novinky"; ERRORS=$((ERRORS+1)); }
expect "odeslání formuláře nejde" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/formular" -d x=1)" 404
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php"; ! grep -q 'module=news"' "$WORK/response" && ! grep -q 'module=enquiries"' "$WORK/response" && echo "  ok     administrace bez novinek a poptávek" || { echo "  CHYBA  administrace ukazuje vypnutá rozšíření"; ERRORS=$((ERRORS+1)); }
mcp stavba_schema '{}' > "$WORK/response"; ! grep -q '\\"formular\\":' "$WORK/response" && ! grep -q 'seznam_novinek' <(curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}') \
  && echo "  ok     builder a MCP nenabízejí prvky ani nástroje vypnutých rozšíření" || { echo "  CHYBA  schéma nebo MCP s vypnutými rozšířeními"; ERRORS=$((ERRORS+1)); }
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota='$EXTENSIONS' WHERE promenna='extensions'"
rm -f "$WORK"/web/storage/cache/stranky/*.html

echo "== 2.0: one pop-up system – the old per-page Modal element becomes a site pop-up (migration 0034)"
# a page as 1.x saved it: a Modal opened after 5 s once a week, and a button that opened it by its anchor
mcp create_page '{"title":"Stará akce","slug":"stara-akce","visible":true}' > /dev/null; MODAL_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'stara-akce'")
mcp save_build "{\"id\":$MODAL_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"button\",\"content\":{\"text\":\"Nabídka\",\"link\":\"#nabidka\"}}]}]}}" > /dev/null
# the Modal as 1.x stored it, next to the button (the 2.0 validator no longer accepts it, so straight into the database)
php -r '$b = json_decode($argv[1], true); $b["deti"][0]["deti"][] = ["id" => "ok1", "typ" => "okno", "kotva" => "nabidka", "popis" => "Jarní akce", "obsah" => ["samo" => "5", "znovu" => "tyden"], "styl" => [], "tridy" => [],
  "deti" => [["id" => "na1", "typ" => "nadpis", "znacka" => "h2", "obsah" => ["text" => "Sleva 20 %"], "styl" => [], "tridy" => []]]]; echo json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);' "$(sq "SELECT stavba FROM ka_stranky WHERE ids = $MODAL_PAGE")" > "$WORK/modal.json"
php -r '$pdo = new PDO("mysql:host=" . $argv[1] . ";port=" . $argv[2] . ";dbname=" . $argv[3] . ";charset=utf8mb4", $argv[4], $argv[5]); $pdo->prepare("UPDATE ka_stranky SET stavba = ? WHERE ids = ?")->execute([file_get_contents($argv[6]), $argv[7]]);' \
  "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS" "$WORK/modal.json" "$MODAL_PAGE"
sq "UPDATE ka_nastaveni SET hodnota = '33' WHERE promenna = 'db_version'; UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'data_migrations'" > /dev/null # a site of 1.9
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/stara-akce"
expect "the migration made a site pop-up with the same trigger, frequency and content, only on that page" \
  "$(sq "SELECT CONCAT_WS('|', nazev, adresa, typ, spoustec, hodnota, cetnost, dni, aktivni, stavba LIKE '%Sleva 20 %%', JSON_EXTRACT(pravidla, '$.stranky[0]')) FROM ka_popupy WHERE nazev = 'Jarní akce'")" \
  "Jarní akce|nabidka|okno|cas|5|dni|7|1|1|$MODAL_PAGE"
expect "the page lost the element and its button opens the pop-up" "$(sq "SELECT CONCAT(stavba LIKE '%\"typ\":\"okno\"%', '|', stavba LIKE '%#popup-nabidka%') FROM ka_stranky WHERE ids = $MODAL_PAGE")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'db_version'")" "0|1|$LAST_MIGRATION"
grep -q 'href="#popup-nabidka"' "$WORK/response" && grep -q 'id="popup-nabidka"' "$WORK/response" && grep -q 'Sleva 20 %' "$WORK/response" \
  && echo "  ok     on the site: the button and the pop-up with the old content" || { echo "  CHYBA  converted pop-up on the site"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/o-nas"; grep -q 'id="popup-nabidka"' "$WORK/response" && { echo "  CHYBA  the converted pop-up shows on other pages"; ERRORS=$((ERRORS+1)); } || echo "  ok     the converted pop-up stays on its page"
check "2.0: old admin URLs of 1.3 lead to the start screen, not a redirect" 200 "/admin.php?modul=stranky&akce=novy" "Přehled"

echo "== 2.8: background jobs, events, alerts"
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q "mail: sent" "$WORK/tasks.txt" && grep -q "cleanup: ok" "$WORK/tasks.txt" && echo "  ok     /ulohy runs the jobs of the scheduler" || { echo "  CHYBA  /ulohy jobs"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "every job that ran is recorded with its result" "$(sq "SELECT CONCAT(COUNT(*) > 5, ':', SUM(failures)) FROM ka_jobs")" "1:0"
[ "$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'enquiry.received'")" = 1 ] && [ "$(sq "SELECT COUNT(*) > 0 FROM ka_events WHERE type = 'build.published'")" = 1 ] \
  && ! sq "SELECT data FROM ka_events WHERE type = 'enquiry.received'" | grep -q '@' && echo "  ok     events: enquiries and publishing recorded, without the sender" || { echo "  CHYBA  events"; ERRORS=$((ERRORS+1)); }
check "System status lists the background jobs" 200 "/admin.php?module=settings&tab=health" "alerts_email"
# an error event goes out as one alert e-mail
sq "UPDATE ka_nastaveni SET hodnota = (SELECT COALESCE(MAX(id), 0) FROM ka_events) WHERE promenna = 'alerts_cursor'; UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'alerts_last_sent'" > /dev/null
sq "INSERT INTO ka_nastaveni (promenna, hodnota) SELECT 'alerts_cursor', (SELECT COALESCE(MAX(id), 0) FROM ka_events) FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM ka_nastaveni WHERE promenna = 'alerts_cursor')" > /dev/null
sq "INSERT INTO ka_events (created_at, type, severity, message) VALUES (NOW(), 'backup.failed', 'error', 'Test: the automatic backup failed')" > /dev/null
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'alerts'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "alerts: one e-mail with the error, the next waits an hour" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%problem%' OR predmet LIKE '%problém%'")" "1"
sq "INSERT INTO ka_events (created_at, type, severity, message) VALUES (NOW(), 'mail.failed', 'error', 'Test: second')" > /dev/null; sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'alerts'" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "alerts: at most one an hour" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%problem%' OR predmet LIKE '%problém%'")" "1"
# the check after an update: only with the one-time code
code=$(curl -s -o /dev/null -w '%{http_code}' "$B/ulohy?probe=abc"); expect "update check without the code is refused" "$code" "403"
sq "INSERT INTO ka_nastaveni VALUES ('update_probe','probe123') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
expect "update check with the code answers the running version" "$(curl -s "$B/ulohy?probe=probe123")" "KALETA-PROBE $(php -r 'require $argv[1]; echo KALETA_VERSION;' "$ROOT/system/bootstrap.php" 2>/dev/null)"
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_probe'" > /dev/null
# Claude: get_health and list_events
mcp get_health '{}' > "$WORK/response"
contains -q 'kaleta_version' "$WORK/response" && contains -q 'alerts' "$WORK/response" && contains -q 'backup.failed' "$WORK/response" && echo "  ok     MCP get_health: status, jobs and the problems of the week" || { echo "  CHYBA  MCP get_health"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_events '{"types":["backup."],"min_severity":"error"}' > "$WORK/response"
contains -q 'Test: the automatic backup failed' "$WORK/response" && contains -q 'next_since_id' "$WORK/response" && ! contains -q 'type\\":\\"enquiry.received' "$WORK/response" && echo "  ok     MCP list_events: filtered by type and severity, with a cursor" || { echo "  CHYBA  MCP list_events"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }

# firewall (2.8): the test server runs with KALETA_FIREWALL_LOCAL=1, so 127.0.0.1 counts as a visitor's address
sq "INSERT INTO ka_nastaveni VALUES ('firewall_enabled','1'),('firewall_ips','127.0.0.1 # test'),('firewall_probes','1'),('firewall_rate','0') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
expect "firewall: a listed address is refused on the public site" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "403"
expect "firewall: the administration stays open" "$(curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=settings&tab=firewall")" "200"
grep -q 'name="firewall_ips"' "$WORK/response" && grep -q '127.0.0.1' "$WORK/response" && echo "  ok     firewall: the tab shows the settings and the refused request" || { echo "  CHYBA  firewall: záložka"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'firewall_ips'" > /dev/null
for i in 1 2 3 4; do curl -s -o /dev/null "$B/wp-login.php"; done
expect "firewall: probing for other systems is blocked at the fifth try" "$(curl -s -o /dev/null -w '%{http_code}' "$B/wp-login.php")" "403"
expect "firewall: the blocked address is refused everywhere for a while" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "403"
expect "firewall: the block is recorded as an event" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'firewall.blocked'")" "1"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=firewall"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=firewall_unblock" -d "_csrf=$TOKEN&ip=127.0.0.1"
expect "firewall: an address can be unblocked" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "200"
sq "UPDATE ka_nastaveni SET hodnota = '3' WHERE promenna = 'firewall_rate'" > /dev/null
codes=""; for i in 1 2 3 4 5 6 7 8; do codes="$codes $(curl -s -o /dev/null -w '%{http_code}' "$B/")"; done
case "$codes" in *429*) echo "  ok     firewall: too many requests a minute get 429";; *) echo "  CHYBA  firewall: limit požadavků ($codes)"; ERRORS=$((ERRORS+1));; esac
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna IN ('firewall_enabled', 'firewall_rate')" > /dev/null
expect "firewall: off again, the site answers" "$(curl -s -o /dev/null -w '%{http_code}' "$B/")" "200"

echo "== 2.9: fleet console (a second install is the console, this site pairs with it)"
PORT3=$((PORT + 13)); B3="http://127.0.0.1:$PORT3"; DB3="${DB_NAME}_konzole"; JAR_CON="$WORK/cookies-konzole.txt"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB3\`; CREATE DATABASE \`$DB3\` CHARACTER SET utf8mb4 COLLATE utf8mb4_czech_ci"
mkdir "$WORK/web3" && (cd "$ROOT" && git ls-files -z --cached --others --exclude-standard | while IFS= read -r -d '' s; do if [ -e "$s" ]; then printf '%s\0' "$s"; fi; done | tar --null -T - -cf - | tar -xf - -C "$WORK/web3")
mkdir -p "$WORK/web3/media" "$WORK/web3/storage/log" "$WORK/web3/storage/cache"
(cd "$WORK/web3" && exec php -S "127.0.0.1:$PORT3" system/dev-router.php > "$WORK/server3.log" 2>&1) & SERVER3_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "$B3/install.php" && break; sleep 0.2; done
curl -s -o "$WORK/response" -X POST "$B3/install.php" --data-urlencode "db_host=$DB_HOST" -d "db_port=$DB_PORT" -d "db_name=$DB3" -d "db_user=$DB_USER" --data-urlencode "db_password=$DB_PASS" -d db_prefix=ka_ \
  --data-urlencode "nazev_webu=Konzole agentury" -d web=firemni -d user=admin -d jmeno=Tester -d email= --data-urlencode "password=$PASSWORD" --data-urlencode "password2=$PASSWORD" -d 'rozsireni[]=fleet' -d 'rozsireni[]=claude'
sq3() { "${MYSQL[@]}" --default-character-set=utf8mb4 "$DB3" -N -e "$1"; }
# the console's own update channel is not reachable here: it "knows" a newer version 9.9.9 from its cache
sq3 "REPLACE INTO ka_nastaveni VALUES ('update_url', 'http://127.0.0.1:1/aktualizace.json'), ('update_cache', '{\"url\":\"http://127.0.0.1:1/aktualizace.json\",\"overeno\":$(date +%s),\"manifest\":{\"verze\":\"9.9.9\",\"zmeny\":[]},\"chyba\":null}')" > /dev/null
curl -s -c "$JAR_CON" -b "$JAR_CON" -o "$WORK/response" "$B3/admin.php"; curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php" -d "_csrf=$(csrf)" -d user=admin --data-urlencode "password=$PASSWORD"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=pairing_key" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
PAIRING_KEY=$(grep -o 'kaleta-console:[A-Za-z0-9_-]*' "$WORK/response" | head -1)
[ -n "$PAIRING_KEY" ] && echo "  ok     console: a one-time pairing key" || { echo "  CHYBA  konzole nedala párovací klíč"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
! grep -q 'kaleta-console:' "$WORK/response" && echo "  ok     console: the pairing key is shown only once" || { echo "  CHYBA  párovací klíč se ukázal znovu"; ERRORS=$((ERRORS+1)); }
expect "a site without the fleet extension has no console addresses" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B/fleet/heartbeat" -d '{}')" "404"
# this site pairs with the console from Settings → Fleet console
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; TOKEN=$(csrf)
grep -q 'name="pairing_key"' "$WORK/response" && echo "  ok     Settings → Fleet console offers pairing" || { echo "  CHYBA  záložka Konzole webů"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_pair" -d "_csrf=$TOKEN" --data-urlencode "pairing_key=$PAIRING_KEY" -d fleet_updates=1
expect "pairing: the site knows its console and its number there" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'), '|', (SELECT hodnota > 0 FROM ka_nastaveni WHERE promenna = 'fleet_site_id'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_updates'))")" "$B3|1|1"
expect "pairing: the console has the site with its first report" "$(sq3 "SELECT CONCAT(COUNT(*), '|', MAX(last_seen IS NOT NULL), '|', MAX(version <> ''), '|', MAX(manage_updates), '|', MAX(heartbeat LIKE '%enquiries_unanswered%')) FROM ka_fleet_sites")" "1|1|1|1|1"
expect "pairing: the code works only once" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_pairing WHERE used_at IS NOT NULL")" "1"
sq3 "SELECT heartbeat FROM ka_fleet_sites" | grep -q '@' && { echo "  CHYBA  the report carries an e-mail address"; ERRORS=$((ERRORS+1)); } || echo "  ok     the report carries no e-mail addresses"
FLEET_NAME=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&show=all"
grep -qF "$FLEET_NAME" "$WORK/response" && echo "  ok     console: the site is in the list" || { echo "  CHYBA  konzole: web není v seznamu"; ERRORS=$((ERRORS+1)); }
FLEET_ID=$(sq3 "SELECT id FROM ka_fleet_sites LIMIT 1")
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
grep -q 'name="ring"' "$WORK/response" && echo "  ok     console: the detail of a site with its update ring" || { echo "  CHYBA  konzole: detail webu"; ERRORS=$((ERRORS+1)); }
# forged and repeated reports are refused
expect "console: a report with a wrong signature is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -H 'X-Kaleta-Signature: AAAA' -d "{\"site_id\":$FLEET_ID,\"ts\":$(date +%s)}")" "403"
expect "console: a report of an unknown site is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/heartbeat" -d '{"site_id":99999}')" "404"
expect "console: pairing with an unknown code is refused" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "$B3/fleet/pair" -d '{"action":"pair","code":"00000000000000000000000000000000","public_key":"'"$(sq3 "SELECT public_key FROM ka_fleet_sites LIMIT 1")"'","url":"http://x.test","ts":0}')" "403"
# the heartbeat job reports on its own
LAST_TS=$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID"); sleep 1
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'heartbeat'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
[ "$(sq3 "SELECT last_ts FROM ka_fleet_sites WHERE id = $FLEET_ID")" -gt "$LAST_TS" ] && echo "  ok     the background job sends the report" || { echo "  CHYBA  úloha heartbeat nic neposlala"; ERRORS=$((ERRORS+1)); }
# staged updates: a normal site waits, a test site (canary) gets the new version at once
expect "staged updates: a normal site waits for the test sites" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'")" ""
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet&action=detail&id=$FLEET_ID"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=ring" -d "_csrf=$(csrf)" -d "id=$FLEET_ID" -d ring=canary
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"; sleep 1
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_send" -d "_csrf=$(csrf)"
expect "staged updates: a test site may install the new version" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'")" "9.9.9"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
grep -q '9.9.9' "$WORK/response" && echo "  ok     the site shows the allowed update" || { echo "  CHYBA  povolená aktualizace se neukazuje"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_updates" -d "_csrf=$(csrf)"
expect "the site takes the decision about updates back" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_updates'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_update_allowed'))")" "0|"
# uptime from the console, and a site that stops reporting
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
expect "uptime: the console sees the site up" "$(sq3 "SELECT up FROM ka_fleet_sites WHERE id = $FLEET_ID")" "1"
sq3 "UPDATE ka_fleet_sites SET url = 'http://127.0.0.1:1', last_seen = NOW() - INTERVAL 30 HOUR, silent_reported = 0 WHERE id = $FLEET_ID" > /dev/null
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o /dev/null -X POST "$B3/admin.php?module=fleet&action=check" -d "_csrf=$(csrf)"
expect "uptime: down twice in a row is an event, and so is a site that stopped reporting" "$(sq3 "SELECT CONCAT((SELECT up FROM ka_fleet_sites WHERE id = $FLEET_ID), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_down'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'fleet.site_silent'))")" "0|1|1"
curl -s -b "$JAR_CON" -c "$JAR_CON" -o "$WORK/response" "$B3/admin.php?module=fleet"
grep -q 'stitek-chyba' "$WORK/response" && echo "  ok     console: a down site is first in the list of what needs attention" || { echo "  CHYBA  konzole: nedostupný web se neukazuje"; ERRORS=$((ERRORS+1)); }
sq3 "UPDATE ka_fleet_sites SET url = '$B' WHERE id = $FLEET_ID" > /dev/null
# Claude on the console reads the fleet (read-only tools, only with the extension)
CON_TOKEN="kaleta_$(printf 'c%.0s' $(seq 1 48))"
sq3 "INSERT INTO ka_api_tokeny (idu, nazev, otisk, vytvoren) SELECT idu, 'test', '$(php -r 'echo hash("sha256", $argv[1]);' "$CON_TOKEN")', NOW() FROM ka_uzivatele WHERE user = 'admin'" > /dev/null
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_sites","arguments":{}}}' > "$WORK/response"
contains -q 'console_decides_updates' "$WORK/response" && contains -q 'newest_version' "$WORK/response" && echo "  ok     MCP list_sites on the console" || { echo "  CHYBA  MCP list_sites"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B3/mcp" -H "Authorization: Bearer $CON_TOKEN" -H 'Content-Type: application/json' --data-binary "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"get_site\",\"arguments\":{\"id\":$FLEET_ID}}}" > "$WORK/response"
contains -q 'jobs_failing' "$WORK/response" && echo "  ok     MCP get_site: the last report" || { echo "  CHYBA  MCP get_site"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_sites '{}' > "$WORK/response"; contains -q 'newest_version' "$WORK/response" && { echo "  CHYBA  list_sites works on a site that is not a console"; ERRORS=$((ERRORS+1)); } || echo "  ok     list_sites exists only on a console"
# disconnecting tells the console; the used key does not pair again
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_unpair" -d "_csrf=$(csrf)"
expect "disconnecting removes the site from the console and the console from the site" "$(sq3 "SELECT COUNT(*) FROM ka_fleet_sites")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'")" "0|"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=console"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=fleet_pair" -d "_csrf=$(csrf)" --data-urlencode "pairing_key=$PAIRING_KEY" -d fleet_updates=1
expect "a used pairing key does not pair again" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'fleet_console_url'")" ""
kill "$SERVER3_PID" 2>/dev/null || true; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB3\`"
echo "== 2.10: business facts"
mcp save_fact '{"key":"projects","label":"Projects","type":"number","value":"1500"}' > "$WORK/response"
contains -q 'fact.projects' "$WORK/response" && echo "  ok     facts: Claude creates a fact" || { echo "  CHYBA  save_fact"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"founded","label":"Founded","type":"year","value":"2004","schema_property":"foundingDate"}' > /dev/null
mcp save_fact '{"key":"founded","value":"long ago"}' > "$WORK/response"
contains -q 'does not fit the type' "$WORK/response" && echo "  ok     facts: a value that does not fit the type is refused" || { echo "  CHYBA  fakt nesprávného typu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Fakta test","slug":"fakta-test","visible":true,"text":"<p>Máme za sebou {{fact.projects}} zakázek od roku {{ fact.founded }}.</p><p>Loni jsme dokončili 1500 zakázek.</p><p>{{fact.neexistuje}}</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fakta-test"
grep -qE '1(.|..)500 zakázek od roku 2004' "$WORK/response" && ! grep -q '{{' "$WORK/response" && echo "  ok     facts: tokens filled in on the page (number with the thousands separator)" || { echo "  CHYBA  fakta se na stránce nedoplnila"; grep -o 'Máme za sebou[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
grep -q '"foundingDate":"2004"' "$WORK/response" && echo "  ok     facts: a fact with a schema property is in the structured data" || { echo "  CHYBA  foundingDate ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Fakta dokumentace","slug":"fakta-dokumentace","visible":true,"text":"<p>Napište <code>{{fact.projects}}</code> do textu.</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/fakta-dokumentace"
grep -q '<code>{{fact.projects}}</code>' "$WORK/response" && echo "  ok     facts: a token inside <code> stays as written (documentation)" || { echo "  CHYBA  značka v <code> se doplnila"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/llms.txt"
grep -qE '^- Projects: 1(.|..)500$' "$WORK/response" && echo "  ok     facts: llms.txt lists the facts" || { echo "  CHYBA  fakta v llms.txt"; grep -A3 -i 'fakt' "$WORK/response" | head -5; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"projects","value":"1600"}' > "$WORK/response"
contains -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     facts: a change lists the sentences that still state the old value" || { echo "  CHYBA  stará hodnota se nenašla"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/fakta-test"
grep -qE '1(.|..)600 zakázek' "$WORK/response" && echo "  ok     facts: the page says the new value at once (the cache is cleared)" || { echo "  CHYBA  nová hodnota faktu se neukázala"; ERRORS=$((ERRORS+1)); }
mcp find_claims '{}' > "$WORK/response"
contains -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     claims inventory: sentences with numbers written as plain text" || { echo "  CHYBA  find_claims"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'fact.neexistuje' "$WORK/response" && echo "  ok     site audit: a token of a fact that does not exist" || { echo "  CHYBA  audit neznámého faktu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_facts '{}' > "$WORK/response"
contains -q 'company_phone' "$WORK/response" && contains -q 'used_in' "$WORK/response" && echo "  ok     MCP list_facts: own and built-in facts with their use" || { echo "  CHYBA  list_facts"; ERRORS=$((ERRORS+1)); }
expect "facts: the admin list" "$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code}' "$B/admin.php?module=facts")" "200"
grep -q 'fact.projects' "$WORK/response" && echo "  ok     facts: the list shows the token" || { echo "  CHYBA  seznam faktů"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=edit&key=projects"
grep -q 'Fakta test' "$WORK/response" && echo "  ok     facts: the fact shows where it is used" || { echo "  CHYBA  kde se fakt používá"; ERRORS=$((ERRORS+1)); }
TOKEN=$(csrf); curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=facts&action=save" -d "_csrf=$TOKEN" -d key=projects -d label=Projects -d type=number -d value=1700
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=edit&key=projects"
grep -q 'starou hodnotu' "$WORK/response" && echo "  ok     facts: after a change in the admin it says whether the old value is still stated" || { echo "  CHYBA  admin: stará hodnota"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts&action=claims"
grep -q 'Loni jsme dokon' "$WORK/response" && echo "  ok     facts: the claims inventory in the admin" || { echo "  CHYBA  admin: věty s čísly"; ERRORS=$((ERRORS+1)); }
echo "== 2.10: computed facts and sourced proof numbers"
YEARS=$(php -r 'echo (int) date("Y") - 2004;'); TYM_COUNT=$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = 'tym' AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ''")
mcp create_page '{"title":"Pocitane test","slug":"pocitane-test","visible":true,"text":"<p>Roky: {{years_since:2004}} / {{ years_since:fact.founded }}. Tým: {{count:tym}}. Novinky: {{count:news}}. Vadné: {{count:neexistuje}}|{{years_since:brzy}}.</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/pocitane-test"
grep -q "Roky: $YEARS / $YEARS\. Tým: $TYM_COUNT\." "$WORK/response" && grep -qE 'Novinky: [0-9]+\.' "$WORK/response" && grep -q 'Vadné: |\.' "$WORK/response" && ! grep -q '{{' "$WORK/response" \
  && echo "  ok     computed facts: years since a year and a fact, the count of a collection and of news; a bad token is empty" || { echo "  CHYBA  počítané fakty na stránce"; grep -o 'Roky:[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'count:neexistuje' "$WORK/response" && contains -q 'years_since:brzy' "$WORK/response" && contains -q 'cannot be computed' "$WORK/response" && echo "  ok     site audit: computed tokens that cannot be computed" || { echo "  CHYBA  audit vadné počítané značky"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp find_claims '{}' > "$WORK/response"
! contains -q 'Roky:' "$WORK/response" && echo "  ok     claims inventory: a sentence with a computed token is not a claim" || { echo "  CHYBA  find_claims s počítanou značkou"; ERRORS=$((ERRORS+1)); }
POCIT=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'pocitane-test'")
mcp save_build "{\"id\":$POCIT,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"id\":\"cnt1\",\"type\":\"counter\",\"content\":{\"number\":\"1500\",\"suffix\":\"+\",\"caption\":\"zakázek\"}},{\"id\":\"cnt2\",\"type\":\"counter\",\"content\":{\"number\":\"{{fact.projects}}\",\"suffix\":\"\",\"caption\":\"zakázek z faktu\"}},{\"id\":\"cnt3\",\"type\":\"counter\",\"content\":{\"number\":\"{{years_since:fact.founded}}\",\"suffix\":\" let\",\"caption\":\"na trhu\"}}]}]}}" > "$WORK/response"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/pocitane-test"
grep -qE 'data-pocitadlo="1700">1(.|..)700<' "$WORK/response" && grep -q "data-pocitadlo=\"$YEARS\">$YEARS<" "$WORK/response" && grep -q 'data-pocitadlo="1500"' "$WORK/response" && ! grep -q '{{' "$WORK/response" \
  && echo "  ok     counter: a fact and a computed token as the number, filled in for visitors with the count-up" || { echo "  CHYBA  počítadlo s faktem"; grep -o 'data-pocitadlo[^<]*' "$WORK/response" | head -3; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"fact"}' > "$WORK/response"
contains -q 'The number 1500 is typed in' "$WORK/response" && contains -q 'cnt1' "$WORK/response" && ! contains -q 'cnt2' "$WORK/response" && ! contains -q 'cnt3' "$WORK/response" \
  && echo "  ok     site audit: a proof number typed in as digits is reported with its element, tokens are not" || { echo "  CHYBA  audit ručně napsaného čísla"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
FIRST_COL=$(sq "SELECT seo_link FROM ka_kolekce ORDER BY idk LIMIT 1"); FIRST_COUNT=$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky p JOIN ka_kolekce k ON k.idk = p.idk WHERE k.seo_link = '$FIRST_COL' AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ''")
mcp list_facts '{}' > "$WORK/response"
contains -q 'years_since:fact.founded' "$WORK/response" && contains -q "count:$FIRST_COL" "$WORK/response" && echo "  ok     MCP list_facts: the computed tokens with their values" || { echo "  CHYBA  list_facts computed"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=facts"
grep -q 'years_since:fact.founded' "$WORK/response" && grep -q "count:$FIRST_COL}}</code></td><td>$FIRST_COUNT<" "$WORK/response" && echo "  ok     facts: the admin list explains the computed tokens with their current values" || { echo "  CHYBA  počítané značky v seznamu faktů"; ERRORS=$((ERRORS+1)); }
mcp trash_page "{\"id\":$POCIT}" > /dev/null
mcp delete_fact '{"key":"founded"}' > "$WORK/response"
contains -q 'Fakta test' "$WORK/response" && echo "  ok     deleting a fact lists where it was still used" || { echo "  CHYBA  delete_fact"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }

echo "== 2.10: opening hours with exceptions"
HOURS_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_hours'"); TYPE_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'company_type'")
sq "REPLACE INTO ka_nastaveni VALUES ('company_hours', 'Po-Pá 8:00-17:00'), ('company_type', 'LocalBusiness')" > /dev/null
TOMORROW=$(php -r 'echo date("Y-m-d", strtotime("+1 day"));')
mcp save_hours_exception "{\"from\":\"$TOMORROW\",\"note\":\"Inventura\",\"notice_days\":7}" > "$WORK/response"
contains -q 'Inventura' "$WORK/response" && echo "  ok     hours: Claude adds an exception (closed tomorrow)" || { echo "  CHYBA  save_hours_exception"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_hours_exception '{"from":"2026-13-01"}' > "$WORK/response"
contains -q 'YYYY-MM-DD' "$WORK/response" && echo "  ok     hours: a wrong date is refused" || { echo "  CHYBA  špatné datum výjimky"; ERRORS=$((ERRORS+1)); }
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/response" "$B/"
grep -q 'class="ka-oznameni-hodiny"' "$WORK/response" && grep -q 'Inventura' "$WORK/response" && echo "  ok     hours: the notice bar on the site" || { echo "  CHYBA  oznamovací lišta"; ERRORS=$((ERRORS+1)); }
grep -q '"specialOpeningHoursSpecification"' "$WORK/response" && echo "  ok     hours: the exception in the structured data" || { echo "  CHYBA  výjimka ve strukturovaných datech"; ERRORS=$((ERRORS+1)); }
mcp create_page '{"title":"Hodiny test","slug":"hodiny-test","visible":true,"text":"<p>Dnes: {{hours.today}}. {{hours.status}}</p>"}' > /dev/null
curl -s -o "$WORK/response" "$B/hodiny-test"
! grep -q '{{hours' "$WORK/response" && grep -qE 'Dnes: ([0-9]|zavřeno)' "$WORK/response" && echo "  ok     hours: {{hours.today}} and {{hours.status}} filled in" || { echo "  CHYBA  značky hodin"; grep -o 'Dnes:[^<]*' "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp list_hours '{}' > "$WORK/response"
contains -q 'Monday' "$WORK/response" && contains -q 'Inventura' "$WORK/response" && echo "  ok     MCP list_hours: the week, the exceptions and now" || { echo "  CHYBA  list_hours"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=company"
grep -q 'Inventura' "$WORK/response" && grep -q 'name="exception_from"' "$WORK/response" && echo "  ok     hours: the exceptions in Settings → Company" || { echo "  CHYBA  výjimky v nastavení"; ERRORS=$((ERRORS+1)); }
EXC=$(sq "SELECT id FROM ka_hours_exceptions LIMIT 1")
grep -q "action=hours_sign&amp;exception=$EXC" "$WORK/response" && echo "  ok     hours: every exception has a Door sign link" || { echo "  CHYBA  odkaz na ceduli"; ERRORS=$((ERRORS+1)); }
check "hours: the door sign is a printable page with the note" 200 "/admin.php?module=settings&action=hours_sign&exception=$EXC" "Inventura"
grep -q '<svg class="qr"' "$WORK/response" && grep -q "127.0.0.1:$PORT" "$WORK/response" && grep -q '@page { size: A4' "$WORK/response" && grep -q 'data-tisk' "$WORK/response" && ! grep -q 'admin.css' "$WORK/response" \
  && echo "  ok     hours: the sign carries the QR code with the site address, A4 print CSS and the Print button, outside the admin layout" || { echo "  CHYBA  cedule na dveře"; ERRORS=$((ERRORS+1)); }
check "hours: the A5 sign" 200 "/admin.php?module=settings&action=hours_sign&exception=$EXC&format=a5" "@page { size: A5"
check "hours: a sign for an unknown exception is a 404" 404 "/admin.php?module=settings&action=hours_sign&exception=999999"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=company"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=hours_delete" -d "_csrf=$(csrf)" -d "exception=$EXC"
expect "hours: an exception is deleted in the admin" "$(sq "SELECT COUNT(*) FROM ka_hours_exceptions")" "0"
curl -s -o "$WORK/response" "$B/"
! grep -q 'ka-oznameni-hodiny' "$WORK/response" && echo "  ok     hours: without an exception there is no notice bar" || { echo "  CHYBA  lišta zůstala"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '$HOURS_BEFORE' WHERE promenna = 'company_hours'; UPDATE ka_nastaveni SET hodnota = '$TYPE_BEFORE' WHERE promenna = 'company_type'" > /dev/null

echo "== 2.10: links between collections, people"
mcp create_collection '{"name":"Pobočky test","slug":"pobocky-test","item_pages":true,"fields":[{"label":"Město","type":"text"}]}' > /dev/null
mcp save_collection_item '{"collection":"pobocky-test","name":"Praha centrum","slug":"praha-centrum","values":{"mesto":"Praha"},"visible":true}' > /dev/null
mcp create_collection '{"name":"Lidé test","slug":"lide-test","item_pages":true,"redirect_hidden_to":"/pobocky-test","fields":[{"label":"Pobočka","type":"item","collection":"pobocky-test"}]}' > "$WORK/response"
contains -q 'redirect_hidden_to' "$WORK/response" && echo "  ok     collections: Claude links a field to another collection" || { echo "  CHYBA  create_collection s vazbou"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "collections: the link remembers the collection" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].kolekce')) FROM ka_kolekce WHERE seo_link = 'lide-test'")" "pobocky-test"
mcp save_collection_item '{"collection":"lide-test","name":"Jana Nová","slug":"jana-nova","values":{"pobocka":"praha-centrum"},"visible":true}' > /dev/null
curl -s -o "$WORK/response" "$B/lide-test/jana-nova"
grep -q 'Praha centrum' "$WORK/response" && echo "  ok     collections: the item page shows the linked item by its name" || { echo "  CHYBA  propojená položka se neukázala"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"lide-test\",\"id\":$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-nova'"),\"visible\":false}" > /dev/null
expect "people: the page of a hidden person leads to the chosen page (301)" "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/lide-test/jana-nova")" "301 $B/pobocky-test"
expect "people: an address that never existed is still not found" "$(curl -s -o /dev/null -w '%{http_code}' "$B/lide-test/nikdo-takovy")" "404"
mcp create_collection '{"name":"Tým","preset":"people"}' > "$WORK/response"
contains -q 'redirect_hidden_to' "$WORK/response" && contains -q 'image' "$WORK/response" && echo "  ok     people: the ready-made team collection" || { echo "  CHYBA  preset people"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
# 2.10: e-mail signature of a person – field keys by the preset's order (system/presets/people.php: photo, role, languages, phone, email, on_leave, about)
TEAM=$(sq "SELECT seo_link FROM ka_kolekce WHERE schema_org LIKE '%Person%' ORDER BY idk DESC LIMIT 1")
TEAM_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$TEAM'")
team_key() { sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[$1].klic')) FROM ka_kolekce WHERE seo_link = '$TEAM'"; }
mcp save_collection_item "{\"collection\":\"$TEAM\",\"name\":\"Petr Podpis\",\"slug\":\"petr-podpis\",\"values\":{\"$(team_key 1)\":\"Obchodní ředitel\",\"$(team_key 3)\":\"+420 777 123 456\",\"$(team_key 4)\":\"petr@example.cz\",\"$(team_key 5)\":\"Dovolená do pátku\"},\"visible\":true}" > "$WORK/response"
PERSON=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $TEAM_IDK AND seo_link = 'petr-podpis'")
[ -n "$PERSON" ] && echo "  ok     people: a person with a role, a phone and an e-mail" || { echo "  CHYBA  save_collection_item (people)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_email_signature "{\"collection\":\"$TEAM\",\"id\":${PERSON:-0}}" > "$WORK/response"
contains -q 'Petr Podpis' "$WORK/response" && contains -q '777 123 456' "$WORK/response" && contains -q 'tel:+420777123456' "$WORK/response" && contains -q 'max-width:600px' "$WORK/response" && ! contains -q 'Dovolen' "$WORK/response" \
  && echo "  ok     people: get_email_signature has the name and the phone in an inline-styled table, never the absence" || { echo "  CHYBA  get_email_signature"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_email_signature "{\"collection\":\"$TEAM\",\"slug\":\"petr-podpis\"}" > "$WORK/response"
contains -q 'mailto:petr@example.cz' "$WORK/response" && contains -q 'people_collection\\":true' "$WORK/response" && echo "  ok     people: the signature by the person's address" || { echo "  CHYBA  get_email_signature slug"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "people: the item form offers the e-mail signature" 200 "/admin.php?module=collections&action=item&id=$TEAM_IDK&polozka=${PERSON:-0}" 'action=signature'
check "people: the admin signature page shows the preview with the copy button" 200 "/admin.php?module=collections&action=signature&id=$TEAM_IDK&polozka=${PERSON:-0}" 'data-kopirovat-podpis'
grep -q 'Petr Podpis' "$WORK/response" && grep -q 'Obchodní ředitel' "$WORK/response" && grep -q 'href="tel:+420777123456"' "$WORK/response" && ! grep -q 'Dovolen' "$WORK/response" && echo "  ok     people: the preview has the name, the role and the phone, never the absence" || { echo "  CHYBA  signature preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=edit&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'lide-test'")"
grep -q 'name="hidden_redirect"' "$WORK/response" && grep -q 'name="pole\[0\]\[kolekce\]"' "$WORK/response" && echo "  ok     collections: the form offers links and the redirect" || { echo "  CHYBA  formulář kolekce"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'lide-test'")&polozka=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'jana-nova'")"
grep -q '<option value="praha-centrum" selected>Praha centrum</option>' "$WORK/response" && echo "  ok     collections: the item form chooses the linked item" || { echo "  CHYBA  formulář položky s vazbou"; ERRORS=$((ERRORS+1)); }

echo "== 2.10: true until and review by"
YESTERDAY=$(php -r 'echo date("Y-m-d", strtotime("-1 day"));'); TODAY=$(php -r 'echo date("Y-m-d");')
# a visible page and a published news item that were true until yesterday and ask for a review today, a pop-up with a review due
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('validity', NOW() + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)" > /dev/null # not due until the test runs it itself (a day ahead: MySQL and PHP may be in different time zones)
mcp create_page "{\"title\":\"Expired offer\",\"text\":\"<p>Only until yesterday.</p>\",\"visible\":true,\"valid_until\":\"$YESTERDAY\",\"review_by\":\"$TODAY\"}" > "$WORK/response"
VALID_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE titulek = 'Expired offer'")
grep -qF "valid_until\\\":\\\"$YESTERDAY" "$WORK/response" && grep -qF "review_by\\\":\\\"$TODAY" "$WORK/response" && echo "  ok     MCP: create_page takes valid_until and review_by and returns them" || { echo "  CHYBA  create_page valid_until/review_by"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_news "{\"title\":\"Expired news\",\"category\":\"$CATEGORY\",\"publish\":true,\"valid_until\":\"$YESTERDAY\",\"review_by\":\"$TODAY\"}" > "$WORK/response"
VALID_NEWS=$(sq "SELECT idc FROM ka_novinky WHERE titulek = 'Expired news'")
grep -qF "valid_until\\\":\\\"$YESTERDAY" "$WORK/response" && echo "  ok     MCP: create_news takes valid_until and review_by" || { echo "  CHYBA  create_news valid_until"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_popup "{\"name\":\"Review popup\",\"template\":\"blank\",\"review_by\":\"$YESTERDAY\"}" > "$WORK/response"
grep -qF "review_by\\\":\\\"$YESTERDAY" "$WORK/response" && echo "  ok     MCP: save_popup takes review_by" || { echo "  CHYBA  save_popup review_by"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$VALID_PAGE,\"valid_until\":\"nonsense\"}" > "$WORK/response"
grep -q 'must be a date' "$WORK/response" && echo "  ok     MCP: a value that is not a date is refused" || { echo "  CHYBA  update_page valid_until validation"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "before the job both are still visible" "$(sq "SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = $VALID_PAGE), '|', (SELECT visible FROM ka_novinky WHERE idc = $VALID_NEWS))")" "1|1"
# the hourly job hides what expired, records the events and asks for the reviews – once
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "the job hid the expired page and news item" "$(sq "SELECT CONCAT((SELECT zobrazit FROM ka_stranky WHERE ids = $VALID_PAGE), '|', (SELECT visible FROM ka_novinky WHERE idc = $VALID_NEWS))")" "0|0"
expect "content.expired events with the kind and id, content.review once per content" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired' AND severity = 'warning' AND data LIKE '%\"kind\":\"page\",\"id\":$VALID_PAGE%'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'))")" "1|2|3"
expect "the change log records what hid itself" "$(sq "SELECT COUNT(*) FROM ka_protokol WHERE akce = 'expired' AND modul IN ('pages', 'news')")" "2"
grep -q 'validity: hidden 2, reviews 3' "$WORK/tasks.txt" && echo "  ok     the job reports what it did" || { echo "  CHYBA  validity job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "a second run asks for no review twice and hides nothing again" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_events WHERE type = 'content.review'), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'content.expired'))")" "3|2"
check "the hidden page is no longer on the site" 404 "/expired-offer"
# the site audit lists the content due for a review, with where to fix it
mcp site_audit '{"kind":"review"}' > "$WORK/response"
grep -q 'Expired offer' "$WORK/response" && grep -q 'Expired news' "$WORK/response" && grep -q 'Review popup' "$WORK/response" && grep -q '\\"page\\":' "$WORK/response" && grep -q '\\"news\\":' "$WORK/response" && grep -q '\\"popup\\":' "$WORK/response" \
  && echo "  ok     MCP: site_audit kind review lists the page, the news item and the pop-up with their targets" || { echo "  CHYBA  site_audit review"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "Administration → Site audit shows the review-by findings" 200 "/admin.php?module=audit" "Expired offer"
# the page form has the two fields; saving it with the dates changed is kept
check "the page form shows true until and review by" 200 "/admin.php?module=pages&action=edit&id=$VALID_PAGE" "name=\"valid_until\" value=\"$YESTERDAY\""
grep -q "name=\"review_by\" value=\"$TODAY\"" "$WORK/response" && echo "  ok     the page form shows the review-by date" || { echo "  CHYBA  page form review_by"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=pages&action=save" -d "_csrf=$(csrf)" -d "ids=$VALID_PAGE" -d "titulek=Expired offer" -d "seo_link=expired-offer" -d "text=<p>x</p>" -d "poradi=100" -d "valid_until=" -d "review_by=2030-01-01"
expect "saving the page form clears true until and keeps the new review-by date" "$(sq "SELECT CONCAT(IFNULL(valid_until, 'null'), '|', IFNULL(review_by, 'null')) FROM ka_stranky WHERE ids = $VALID_PAGE")" "null|2030-01-01"
check "the pages list shows the review-by badge" 200 "/admin.php?module=pages" "stitek stitek-koncept\" title=\"V tento den žádá o kontrolu.\""
check "the news form shows the two fields" 200 "/admin.php?module=news&action=edit&id=$VALID_NEWS" "name=\"review_by\" value=\"$TODAY\""
check "the pop-up form shows the two fields" 200 "/admin.php?module=popups&action=edit&id=$(sq "SELECT idpp FROM ka_popupy WHERE nazev = 'Review popup'")" "name=\"review_by\" value=\"$YESTERDAY\""
mcp update_page "{\"id\":$VALID_PAGE,\"review_by\":\"\"}" > "$WORK/response"
expect "MCP: an empty string clears review by" "$(sq "SELECT IFNULL(review_by, 'null') FROM ka_stranky WHERE ids = $VALID_PAGE")" "null"
echo "== 2.11: ready-made collections and the date-time, file and location fields"
mcp list_collection_presets '{}' > "$WORK/response"
contains -q 'preset\\":\\"people' "$WORK/response" && contains -q 'how_to_use' "$WORK/response" && contains -q 'existing_collection\\":\\"' "$WORK/response" && echo "  ok     MCP: list_collection_presets lists the presets with their fields and instructions" || { echo "  CHYBA  list_collection_presets"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp create_collection '{"name":"Náš tým","preset":"people"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "presets: the collection remembers its preset and gets English field keys" "$(sq "SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[3].klic')), '|', nazev) FROM ka_kolekce WHERE seo_link = 'nas-tym'")" "people|phone|Náš tým" \
  || { echo "  CHYBA  create_collection preset people (2.11)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "presets: a hidden page lists the new collection" "$(sq "SELECT CONCAT(zobrazit, '|', stavba LIKE '%\"kolekce\":\"nas-tym\"%', '|', stavba LIKE '%{{photo}}%') FROM ka_stranky WHERE seo_link = 'nas-tym'")" "0|1|1"
contains -q 'list_page' "$WORK/response" && echo "  ok     presets: Claude is told about the hidden list page" || { echo "  CHYBA  list_page"; ERRORS=$((ERRORS+1)); }
expect "presets: the team gets Person structured data mapped to its fields" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.telephone')) FROM ka_kolekce WHERE seo_link = 'nas-tym'")" "phone"
mcp create_collection '{"name":"Nesmysl","preset":"nothing-like-it"}' > "$WORK/response"
contains -q 'list_collection_presets' "$WORK/response" && echo "  ok     presets: an unknown preset names the known ones" || { echo "  CHYBA  unknown preset"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "presets: the collections list offers the ready-made collections" 200 "/admin.php?module=collections" 'name="preset" value="people"'
mcp create_collection '{"name":"Typy polí","slug":"typy-poli","item_pages":true,"fields":[{"label":"Začátek","type":"datetime"},{"label":"Ceník","type":"file"},{"label":"Místo","type":"location"}]}' > /dev/null
expect "fields: Claude's datetime, file and location types" "$(sq "SELECT GROUP_CONCAT(JSON_UNQUOTE(JSON_EXTRACT(pole, CONCAT('\$[', n.i, '].typ'))) ORDER BY n.i) FROM ka_kolekce, (SELECT 0 i UNION SELECT 1 UNION SELECT 2) n WHERE seo_link = 'typy-poli'")" "termin,soubor,poloha"
mcp save_collection_item '{"collection":"typy-poli","name":"Den otevřených dveří","slug":"den-otevrenych-dveri","values":{"zacatek":"2026-11-02T17:00","cenik":"/media/cenik-2026.pdf","misto":"49.1951;16.6068"},"visible":true}' > "$WORK/response"
expect "fields: the date and time, the file and the location are stored clean" "$(sq "SELECT data->>'\$.zacatek', data->>'\$.cenik', data->>'\$.misto' FROM ka_kolekce_polozky WHERE seo_link = 'den-otevrenych-dveri'" | tr '\t' '|')" "2026-11-02 17:00|/media/cenik-2026.pdf|49.1951, 16.6068"
curl -s -o "$WORK/response" "$B/typy-poli/den-otevrenych-dveri"
grep -q '2. 11. 2026 17:00' "$WORK/response" && grep -q 'href="[^"]*media/cenik-2026.pdf"' "$WORK/response" && grep -q 'cenik-2026.pdf)' "$WORK/response" \
  && echo "  ok     fields: the item page shows the day and time and a button to the file with its name" || { echo "  CHYBA  stránka položky s termínem a souborem"; ERRORS=$((ERRORS+1)); }
check "fields: the item form has a date-time input and a Media file picker" 200 "/admin.php?module=collections&action=item&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'typy-poli'")&polozka=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'den-otevrenych-dveri'")" 'type="datetime-local" id="pole-zacatek" name="data\[zacatek\]" value="2026-11-02T17:00"'
grep -q 'data-soubor' "$WORK/response" && echo "  ok     fields: the file field opens Media" || { echo "  CHYBA  data-soubor"; ERRORS=$((ERRORS+1)); }
# the period of a Collection list (2.11): upcoming, current and past by a start and an end field – the SQL condition run on real rows
mcp create_collection '{"name":"Období","slug":"obdobi-test","fields":[{"label":"Od","type":"datetime"},{"label":"Do","type":"datetime"}]}' > /dev/null
YESTERDAY_D=$(date -v-1d +%Y-%m-%d 2>/dev/null || date -d yesterday +%Y-%m-%d); TODAY_D=$(date +%Y-%m-%d); TOMORROW_D=$(date -v+1d +%Y-%m-%d 2>/dev/null || date -d tomorrow +%Y-%m-%d)
for row in "vcera|$YESTERDAY_D 10:00|" "dnes-cely-den|$TODAY_D|" "zitra|$TOMORROW_D 09:00|" "probiha|$YESTERDAY_D|$TOMORROW_D" "vyveseno|$YESTERDAY_D|" "bez-data||"; do
  IFS='|' read -r slug od do <<< "$row"
  mcp save_collection_item "{\"collection\":\"obdobi-test\",\"name\":\"$slug\",\"slug\":\"$slug\",\"values\":{\"od\":\"$od\",\"do\":\"$do\"},\"visible\":true}" > /dev/null
done
period() { php -r 'require $argv[1] . "/system/bootstrap.php"; [$sql, $p] = Kaleta\Builder\Collections::periodCondition($argv[2], "od", $argv[3], date("Y-m-d H:i")); echo str_replace("?", "'"'"'" . $p[0] . "'"'"'", $sql);' "$ROOT" "$1" "$2"; }
in_period() { sq "SELECT GROUP_CONCAT(seo_link ORDER BY seo_link) FROM ka_kolekce_polozky WHERE idk = (SELECT idk FROM ka_kolekce WHERE seo_link = 'obdobi-test') AND $(period "$1" "$2")"; }
expect "period: upcoming – not ended (today's whole day counts, an event with an end that has not passed too)" "$(in_period nadchazejici do)" "dnes-cely-den,probiha,zitra"
expect "period: past – ended yesterday" "$(in_period minule do)" "vcera,vyveseno"
expect "period: current – started and not ended; without an end it stays up" "$(in_period probihajici do)" "dnes-cely-den,probiha,vcera,vyveseno"
expect "period: current without an end field – only the start's day" "$(in_period probihajici '')" "dnes-cely-den"
echo "== 2.11: events calendar"
mcp create_collection '{"name":"Akce test","preset":"events"}' > "$WORK/response"
EVENTS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'akce-test' AND preset = 'events'")
[ -n "$EVENTS_IDK" ] && contains -q 'list_page' "$WORK/response" && echo "  ok     events: the preset creates the calendar and its list page" || { echo "  CHYBA  events preset"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "events: the repetition is a choice of known options and the item template has the registration form" "$(sq "SELECT CONCAT(JSON_LENGTH(JSON_EXTRACT(pole, '\$[12].moznosti')), '|', stavba LIKE '%\"typ\":\"formular\"%', '|', stavba LIKE '%{{ical}}%') FROM ka_kolekce WHERE idk = $EVENTS_IDK")" "5|1|1"
TOMORROW_D=$(date -v+1d +%Y-%m-%d 2>/dev/null || date -d tomorrow +%Y-%m-%d); EIGHT_AGO=$(date -v-8d +%Y-%m-%d 2>/dev/null || date -d '8 days ago' +%Y-%m-%d)
SIX_AHEAD=$(date -v+6d +%Y-%m-%d 2>/dev/null || date -d '6 days' +%Y-%m-%d); YESTERDAY_D=$(date -v-1d +%Y-%m-%d 2>/dev/null || date -d yesterday +%Y-%m-%d)
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Jóga, pro začátečníky\",\"slug\":\"joga\",\"values\":{\"start\":\"$TOMORROW_D 18:00\",\"end\":\"$TOMORROW_D 19:30\",\"venue\":\"Sál\",\"address\":\"Hlavní 1, Brno\",\"capacity\":\"1\",\"repeat\":\"weekly\",\"summary\":\"Přineste si podložku.\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Minulá přednáška\",\"slug\":\"minula\",\"values\":{\"start\":\"$YESTERDAY_D 10:00\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Seriál\",\"slug\":\"serial\",\"values\":{\"start\":\"$EIGHT_AGO 18:00\",\"end\":\"$EIGHT_AGO 19:30\",\"repeat\":\"weekly\"},\"visible\":true}" > /dev/null
mcp save_collection_item "{\"collection\":\"akce-test\",\"name\":\"Nesmysl\",\"slug\":\"nesmysl\",\"values\":{\"repeat\":\"každé úterý\"}}" > "$WORK/response"
contains -q 'invalid_fields.*repeat' "$WORK/response" && expect "events: a repetition outside the options is refused" "$(sq "SELECT data->>'\$.repeat' FROM ka_kolekce_polozky WHERE idk = $EVENTS_IDK AND seo_link = 'nesmysl'")" "" \
  || { echo "  CHYBA  volba mimo možnosti"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('events', NULL) ON DUPLICATE KEY UPDATE last_run = NULL" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
expect "events: the job moves an ended weekly event to its next date, keeping the time" "$(sq "SELECT CONCAT(data->>'\$.start', '|', data->>'\$.end') FROM ka_kolekce_polozky WHERE idk = $EVENTS_IDK AND seo_link = 'serial'")" "$SIX_AHEAD 18:00|$SIX_AHEAD 19:30"
grep -q 'events: moved 1' "$WORK/tasks.txt" && echo "  ok     events: the job reports what it moved" || { echo "  CHYBA  events job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'akce-test'"),\"visible\":true}" > /dev/null
curl -s -o "$WORK/response" "$B/akce-test"
grep -q 'Jóga, pro začátečníky' "$WORK/response" && grep -q 'Seriál' "$WORK/response" && ! grep -q 'Minulá přednáška' "$WORK/response" && grep -q 'Sál, Hlavní 1, Brno' "$WORK/response" \
  && echo "  ok     events: the list page shows upcoming events with their date and place, not the past one" || { echo "  CHYBA  seznam akcí"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/formular.html" "$B/akce-test/joga"
grep -q '"@type":"Event"' "$WORK/formular.html" && grep -q 'OfflineEventAttendanceMode' "$WORK/formular.html" && grep -q 'href="[^"]*akce-test/joga.ics"' "$WORK/formular.html" && grep -q 'class="ka-formular"' "$WORK/formular.html" \
  && echo "  ok     events: the event page has Event data, an Add to calendar link and the registration form" || { echo "  CHYBA  stránka akce"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/response" "$B/akce-test.ics"
grep -qi 'content-type: text/calendar' "$WORK/headers" && grep -q 'SUMMARY:Jóga\\, pro začátečníky' "$WORK/response" && grep -q 'RRULE:FREQ=WEEKLY' "$WORK/response" && grep -q 'LOCATION:Sál\\, Hlavní 1\\, Brno' "$WORK/response" \
  && echo "  ok     events: /<collection>.ics is a calendar to subscribe to" || { echo "  CHYBA  iCal kolekce"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o "$WORK/response" "$B/akce-test/joga.ics"
grep -qi 'content-disposition: attachment; filename="joga.ics"' "$WORK/headers" && [ "$(grep -c 'BEGIN:VEVENT' "$WORK/response")" = 1 ] && echo "  ok     events: one event as a file to add" || { echo "  CHYBA  iCal akce"; ERRORS=$((ERRORS+1)); }
expect "events: a collection that is not a calendar has no .ics" "$(curl -s -o /dev/null -w '%{http_code}' "$B/typy-poli.ics")" "404"
# registration: capacity 1 – the first registration fills it, the form closes and the server refuses another one
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
FORM_SOURCE=$(field_value zdroj || true); FORM_ELEMENT=$(field_value prvek || true); FORM_TIME=$(field_value as_cas || true); FORM_SIGNATURE=$(field_value as_podpis || true); sleep 4
register() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d "zpet=/akce-test/joga" -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" -d p0=Eva --data-urlencode "p1=$1" -d p4=1; }
case "$(register eva@example.cz)" in *vysledek=ok*) echo "  ok     events: a registration is accepted";; *) echo "  CHYBA  registrace na akci"; ERRORS=$((ERRORS+1));; esac
expect "events: the registration is an enquiry from the event's page" "$(sq "SELECT CONCAT(zdroj, '|', stranka) FROM ka_poptavky ORDER BY idp DESC LIMIT 1")" "kolekce:$EVENTS_IDK|/akce-test/joga"
case "$(register petr@example.cz)" in *vysledek=plno*) echo "  ok     events: a full event refuses another registration on the server";; *) echo "  CHYBA  plná akce přijala registraci"; ERRORS=$((ERRORS+1));; esac
curl -s -o "$WORK/response" "$B/akce-test/joga"
grep -q 'Akce je plně obsazená.' "$WORK/response" && ! grep -q 'class="ka-formular"' "$WORK/response" && echo "  ok     events: the page of a full event shows it is full instead of the form" || { echo "  CHYBA  plná akce stále ukazuje formulář"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items '{"collection":"akce-test"}' > "$WORK/response"
contains -q 'state\\":\\"full' "$WORK/response" && contains -q 'places_left\\":0' "$WORK/response" && echo "  ok     events: Claude sees the registrations and that it is full" || { echo "  CHYBA  registrace v list_collection_items"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/akce-test/minula"
grep -q 'Akce už skončila.' "$WORK/response" && ! grep -q 'class="ka-formular"' "$WORK/response" && echo "  ok     events: a past event says it has ended and takes no registrations" || { echo "  CHYBA  proběhlá akce"; ERRORS=$((ERRORS+1)); }
echo "== 2.11: product catalogue without a checkout"
mcp create_collection '{"name":"Produkty test","preset":"products"}' > /dev/null
PRODUCTS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'produkty-test' AND preset = 'products'")
mcp save_collection_item '{"collection":"produkty-test","name":"Lehátko Basic","slug":"lehatko-basic","values":{"code":"LB-1","category":"Lehátka","price":"12900","parameters":"Šířka: 60 cm\nNosnost: 150 kg","variants":"Modrá | LB-1-M | 12 900 Kč\nŠedá | LB-1-S"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-test","name":"Lehátko Pro","slug":"lehatko-pro","values":{"code":"LP-2","category":"Lehátka","parameters":"Šířka: 70 cm\nMotor: 2 kW"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"produkty-test","name":"Špatné","slug":"spatne","values":{"parameters":"jen text bez hodnoty"}}' > "$WORK/response"
contains -q 'invalid_fields.*parameters' "$WORK/response" && echo "  ok     products: parameters without a value are refused" || { echo "  CHYBA  parametry bez hodnoty"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/produkty-test/lehatko-basic"
grep -q '<th scope="row">Nosnost</th><td>150 kg</td>' "$WORK/response" && grep -q 'class="ka-do-poptavky"' "$WORK/response" && grep -q '<option>Šedá</option>' "$WORK/response" && grep -q '"@type":"Product"' "$WORK/response" \
  && echo "  ok     products: the product page has the parameters, Add to enquiry with variants and Product data" || { echo "  CHYBA  stránka produktu"; ERRORS=$((ERRORS+1)); }
grep -q 'image/web.js' "$WORK/response" && ! grep -q 'href="#"' "$WORK/response" && echo "  ok     products: the page keeps the basket script and has no button to a datasheet it does not have" || { echo "  CHYBA  web.js nebo prázdné tlačítko na stránce produktu"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/produkty-test/_porovnat?i=lehatko-basic,lehatko-pro,neni"
grep -q '<th scope="row">Šířka</th><td>60 cm</td><td>70 cm</td>' "$WORK/response" && grep -q '<th scope="row">Motor</th><td></td><td>2 kW</td>' "$WORK/response" && grep -q 'noindex' "$WORK/response" \
  && echo "  ok     products: the comparison puts the parameters side by side" || { echo "  CHYBA  porovnání produktů"; ERRORS=$((ERRORS+1)); }
expect "products: a comparison without known products is not found" "$(curl -s -o /dev/null -w '%{http_code}' "$B/produkty-test/_porovnat?i=neni")" "404"
expect "products: a collection that is not a catalogue has no comparison" "$(curl -s -o /dev/null -w '%{http_code}' "$B/typy-poli/_porovnat?i=den-otevrenych-dveri")" "404"
mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'produkty-test'"),\"visible\":true}" > /dev/null
# without the script: Add to enquiry opens the list page with the product, the basket field has it
curl -s -o "$WORK/formular.html" "$B/produkty-test?produkt=produkty-test/lehatko-basic&varianta=$(php -r 'echo rawurlencode("Šedá");')&mnozstvi=2"
grep -q 'data-kosik-pole' "$WORK/formular.html" && grep -q '2 × Lehátko Basic – Šedá (LB-1-S)' "$WORK/formular.html" && echo "  ok     products: the enquiry form takes the product from the address" || { echo "  CHYBA  košík bez skriptu"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'formular'" > /dev/null
FORM_SOURCE=$(field_value zdroj || true); FORM_ELEMENT=$(grep -o 'name="prvek" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//'); FORM_TIME=$(grep -o 'name="as_cas" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//')
FORM_SIGNATURE=$(grep -o 'name="as_podpis" value="[^"]*"' "$WORK/formular.html" | tail -1 | sed 's/.*value="//;s/"$//'); sleep 4
basket_send() { curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$FORM_SOURCE" -d "prvek=$FORM_ELEMENT" -d "zpet=/produkty-test" -d "as_cas=$FORM_TIME" -d "as_podpis=$FORM_SIGNATURE" --data-urlencode "p0=$1" -d p1=Eva -d p2=eva@example.cz -d p5=1; }
case "$(basket_send '[{"c":"produkty-test","i":"lehatko-pro","v":"Zlatá","q":1}]')" in *vysledek=pole*) echo "  ok     products: a variant the product does not have is refused";; *) echo "  CHYBA  neexistující varianta v košíku"; ERRORS=$((ERRORS+1));; esac
case "$(basket_send '[]')" in *vysledek=pole*) echo "  ok     products: an empty basket is refused";; *) echo "  CHYBA  prázdný košík"; ERRORS=$((ERRORS+1));; esac
case "$(basket_send '[{"c":"produkty-test","i":"lehatko-basic","v":"Modrá","q":3},{"c":"produkty-test","i":"lehatko-pro","v":"","q":1,"n":"<script>"}]')" in *vysledek=ok*) echo "  ok     products: the basket is sent";; *) echo "  CHYBA  odeslání košíku"; ERRORS=$((ERRORS+1));; esac
sq "SELECT data FROM ka_poptavky ORDER BY idp DESC LIMIT 1" > "$WORK/response"
grep -q '3 × Lehátko Basic – Modrá (LB-1-M)' "$WORK/response" && grep -q '1 × Lehátko Pro (LP-2)' "$WORK/response" && ! grep -q 'script' "$WORK/response" \
  && echo "  ok     products: the enquiry lists the products as the database has them, never the visitor's text" || { echo "  CHYBA  řádky košíku v poptávce"; cat "$WORK/response"; ERRORS=$((ERRORS+1)); }
echo "== 2.11: industry blueprints"
cat > "$WORK/blueprint.json" <<'JSON'
{"kaleta_blueprint":1,"key":"dental_test","name":{"en":"Dental clinic","cs":"Zubní ordinace"},"description":"For dentists","presets":["people","faq_unknown_is_refused"],
 "facts":[{"key":"insurers","label":"Insurers","type":"text"}],"questions":[{"question":"Which insurers do you have contracts with?","fact":"insurers"}],
 "audit":[{"check":"fact","fact":"insurers","message":"Say which insurers you work with."},{"check":"preset_items","preset":"people","min":1,"message":"Add the doctors."}],
 "claude":"Patients look for insurers first; never give medical advice."}
JSON
mcp apply_blueprint "{\"manifest\":$(cat "$WORK/blueprint.json")}" > "$WORK/response"
contains -q 'faq_unknown_is_refused' "$WORK/response" && expect "blueprints: a manifest with an unknown preset is refused whole" "$(sq "SELECT COUNT(*) FROM ka_blueprints")" "0" || { echo "  CHYBA  neplatný plán"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sed -i.bak 's/,"faq_unknown_is_refused"//' "$WORK/blueprint.json"
sq "UPDATE ka_kolekce SET preset = '' WHERE preset = 'people'" > /dev/null # the earlier tests made teams; this site has none from the preset
mcp apply_blueprint "{\"manifest\":$(cat "$WORK/blueprint.json")}" > "$WORK/response"
contains -q 'created_facts.*insurers' "$WORK/response" && expect "blueprints: applying creates the team collection, the fact without a value and keeps the manifest" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'people'), '|', (SELECT CONCAT(label, '=', value) FROM ka_facts WHERE fact_key = 'insurers'), '|', (SELECT bkey FROM ka_blueprints WHERE nazev IN ('Zubní ordinace', 'Dental clinic')))")" "1|Insurers=|dental_test" \
  || { echo "  CHYBA  apply_blueprint"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp get_blueprint '{}' > "$WORK/response"
contains -q 'Which insurers do you have contracts with' "$WORK/response" && contains -q 'Say which insurers you work with' "$WORK/response" && contains -q 'Add the doctors' "$WORK/response" \
  && echo "  ok     blueprints: Claude sees the open question and the failing checks" || { echo "  CHYBA  get_blueprint"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | grep -q 'never give medical advice' \
  && echo "  ok     blueprints: the instructions of every Claude connection include the blueprint's" || { echo "  CHYBA  pokyny plánu v MCP"; ERRORS=$((ERRORS+1)); }
mcp site_audit '{"kind":"blueprint"}' > "$WORK/response"
contains -q 'Say which insurers you work with' "$WORK/response" && echo "  ok     blueprints: the site audit runs its checks" || { echo "  CHYBA  audit plánu"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_fact '{"key":"insurers","value":"VZP, OZP"}' > /dev/null
mcp save_collection_item "{\"collection\":\"$(sq "SELECT seo_link FROM ka_kolekce WHERE preset = 'people' LIMIT 1")\",\"name\":\"MUDr. Test\",\"visible\":true}" > /dev/null
mcp site_audit '{"kind":"blueprint"}' > "$WORK/response"
! contains -q 'Say which insurers' "$WORK/response" && ! contains -q 'Add the doctors' "$WORK/response" && echo "  ok     blueprints: answered and filled in, the checks pass" || { echo "  CHYBA  kontroly plánu po doplnění"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "blueprints: the admin page shows the applied blueprint and its question with the answer" 200 "/admin.php?module=blueprints" 'value="VZP, OZP"'
mcp export_blueprint '{"key":"my_clinic","name":"My clinic"}' > "$WORK/response"
contains -q 'kaleta_blueprint' "$WORK/response" && contains -q 'insurers' "$WORK/response" && ! contains -q 'VZP' "$WORK/response" && contains -q 'people' "$WORK/response" \
  && echo "  ok     blueprints: the export has the presets and the facts, never their values" || { echo "  CHYBA  export_blueprint"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -D "$WORK/headers" -o "$WORK/response" "$B/admin.php?module=blueprints&action=export&key=my_clinic&name=Moje"
grep -qi 'filename="my_clinic.blueprint.json"' "$WORK/headers" && php -r 'exit(json_decode(file_get_contents($argv[1]), true)["kaleta_blueprint"] === 1 ? 0 : 1);' "$WORK/response" \
  && echo "  ok     blueprints: the admin downloads the site as a blueprint file" || { echo "  CHYBA  stažení plánu"; ERRORS=$((ERRORS+1)); }
mcp remove_blueprint '{"key":"dental_test"}' > /dev/null
expect "blueprints: removing keeps the collection and the fact" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE preset = 'people'), '|', (SELECT value FROM ka_facts WHERE fact_key = 'insurers'))")" "0|1|VZP, OZP"
echo "== 2.11: job openings that close themselves"
mcp create_collection '{"name":"Volná místa","preset":"jobs"}' > "$WORK/response"
JOBS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE preset = 'jobs'")
expect "jobs: the preset brings JobPosting data, the contact linked to the team, the redirect of hidden jobs to the jobs page and an item template with a form and a CV field" \
  "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.employmentType')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[8].kolekce')) = (SELECT seo_link FROM ka_kolekce WHERE preset = 'people' ORDER BY idk LIMIT 1), '|', hidden_redirect, '|', stavba LIKE '%{{nazev}}%' AND stavba LIKE '%\"typ\":\"formular\"%' AND stavba LIKE '%\"typ\":\"soubor\"%') FROM ka_kolekce WHERE idk = $JOBS_IDK")" "JobPosting|employment_type|1|/volna-mista|1"
contains -q 'valid_until' "$WORK/response" && echo "  ok     jobs: Claude is told to always set the closing date (valid_until)" || { echo "  CHYBA  jobs how_to_use"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_kolekce SET schema_org = JSON_SET(schema_org, '\$.mena', 'CZK') WHERE idk = $JOBS_IDK" > /dev/null # the collection currency: salaries are published only with it
JOB_TOMORROW=$(php -r 'echo date("Y-m-d", strtotime("+1 day"));'); JOB_YESTERDAY=$(php -r 'echo date("Y-m-d", strtotime("-1 day"));')
mcp save_collection_item "{\"collection\":\"volna-mista\",\"name\":\"Truhlář\",\"slug\":\"truhlar\",\"values\":{\"location\":\"Brno\",\"employment_type\":\"plný úvazek\",\"salary_min\":\"35000\",\"salary_max\":\"45000\",\"salary_unit\":\"za měsíc\",\"description\":\"<p>Výroba nábytku na míru.</p>\"},\"visible\":true,\"valid_until\":\"$JOB_TOMORROW\"}" > "$WORK/response"
curl -s -o "$WORK/job.html" "$B/volna-mista/truhlar"
grep -qF '"@type":"JobPosting"' "$WORK/job.html" && grep -qF "\"validThrough\":\"$JOB_TOMORROW\"" "$WORK/job.html" && grep -qF '"employmentType":"FULL_TIME"' "$WORK/job.html" && grep -qF '"addressLocality":"Brno","addressCountry":"CZ"' "$WORK/job.html" \
  && grep -qF '"baseSalary":{"@type":"MonetaryAmount","currency":"CZK","value":{"@type":"QuantitativeValue","minValue":35000,"maxValue":45000,"unitText":"MONTH"}}' "$WORK/job.html" && grep -qF '"hiringOrganization":{"@type":"Organization","name":"' "$WORK/job.html" \
  && echo "  ok     jobs: the item page carries a JobPosting with validThrough, the company, the place and the salary" || { echo "  CHYBA  JobPosting on the item page"; grep -o '"@type":"JobPosting".*' "$WORK/job.html" | head -c 700; ERRORS=$((ERRORS+1)); }
grep -q 'name="p3" type="file"' "$WORK/job.html" && grep -qF 'type="hidden" name="p6" value="Truhlář"' "$WORK/job.html" && grep -q 'enctype="multipart/form-data"' "$WORK/job.html" \
  && echo "  ok     jobs: the application form has a CV field and carries the job name in a hidden field" || { echo "  CHYBA  application form on the item page"; ERRORS=$((ERRORS+1)); }
job_field() { grep -o "name=\"$1\" value=\"[^\"]*\"" "$WORK/job.html" | head -1 | sed 's/.*value="//;s/"$//'; }
JOB_SOURCE=$(job_field zdroj); JOB_ELEMENT=$(job_field prvek); JOB_TIME=$(job_field as_cas); JOB_SIGNATURE=$(job_field as_podpis)
expect "jobs: the form is served from the collection's item template (the source of its enquiries)" "$JOB_SOURCE" "kolekce:$JOBS_IDK"
# a job whose closing date passed hides itself and its address leads to the jobs page; a job without a closing date is in the audit
mcp save_collection_item "{\"collection\":\"volna-mista\",\"name\":\"Svářeč\",\"slug\":\"svarec\",\"values\":{\"location\":\"Brno\"},\"visible\":true,\"valid_until\":\"$JOB_YESTERDAY\"}" > /dev/null
mcp save_collection_item '{"collection":"volna-mista","name":"Bez uzaverky","slug":"bez-uzaverky","values":{"location":"Praha"},"visible":true}' > /dev/null
check "jobs: before the validity job the job that closed yesterday still has its page" 200 "/volna-mista/svarec" "Svářeč"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'validity'" > /dev/null; curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "jobs: the validity job hid the job whose closing date passed, the open ones stay" "$(sq "SELECT GROUP_CONCAT(CONCAT(seo_link, '=', zobrazit) ORDER BY seo_link) FROM ka_kolekce_polozky WHERE idk = $JOBS_IDK")" "bez-uzaverky=1,svarec=0,truhlar=1"
case "$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/volna-mista/svarec")" in "301 $B/volna-mista") echo "  ok     jobs: the closed job's address leads to the jobs page";; *) echo "  CHYBA  closed job redirect: $(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' "$B/volna-mista/svarec")"; ERRORS=$((ERRORS+1));; esac
mcp site_audit '{"kind":"job"}' > "$WORK/response"
grep -q 'Bez uzaverky' "$WORK/response" && ! grep -q 'Truhl' "$WORK/response" && contains -q 'job\\":1' "$WORK/response" && contains -q 'collection\\":\\"volna-mista' "$WORK/response" \
  && echo "  ok     MCP: site_audit kind job lists only the visible job without a closing date, with its target" || { echo "  CHYBA  site_audit job"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "Administration → Site audit lists the job opening without a closing date" 200 "/admin.php?module=audit" "Bez uzaverky"
# the retention of applications next to the enquiries retention, with the usual practice of the company country as a hint
check "jobs: Enquiries offers the retention of job applications with the usual practice for the company country" 200 "/admin.php?module=enquiries" 'name="mesice_uchazeci" value="0"'
grep -q 'CZ: 6' "$WORK/response" && echo "  ok     jobs: the hint names the country and the months" || { echo "  CHYBA  retention hint"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=enquiries&action=settings" -d "_csrf=$(csrf)" -d mesice=24 -d mesice_uchazeci=3
expect "jobs: the retention of applications is saved next to the enquiries retention" "$(sq "SELECT CONCAT((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'job_applications_months'), '|', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months'))")" "3|24"
# an application with a CV: an enquiry from the job's page; the hidden job name comes back as plain text only
printf '%%PDF-1.4 test CV\n' > "$WORK/cv.pdf"
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -F "zdroj=$JOB_SOURCE" -F "prvek=$JOB_ELEMENT" -F zpet=/volna-mista/truhlar -F "as_cas=$JOB_TIME" -F "as_podpis=$JOB_SIGNATURE" \
  -F p0=Jan -F p1=jan@example.cz -F p2= -F "p3=@$WORK/cv.pdf" --form-string "p4=Hlásím se." -F p5=1 --form-string "p6=<b>Truhlář</b>") # --form-string: a value starting with < would be read as a file by -F
case "$location" in *"/volna-mista/truhlar?formular=$JOB_ELEMENT&vysledek=ok#"*) echo "  ok     jobs: an application with a CV was sent";; *) echo "  CHYBA  application: $location"; ERRORS=$((ERRORS+1));; esac
APP_IDP=$(sq "SELECT MAX(idp) FROM ka_poptavky WHERE zdroj = 'kolekce:$JOBS_IDK'")
expect "jobs: the application is an enquiry from the job's page with the job name as plain text and the CV outside the web root" \
  "$(sq "SELECT CONCAT(stranka, '|', email, '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[6][1]')), '|', JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) REGEXP '^[0-9]{4}/[0-9]{2}/[a-f0-9]{24}[.]pdf$') FROM ka_poptavky WHERE idp = $APP_IDP")" "/volna-mista/truhlar|jan@example.cz|Truhlář|1"
CV_PATH=$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '\$[3][2]')) FROM ka_poptavky WHERE idp = $APP_IDP")
[ -n "$CV_PATH" ] && [ -f "$WORK/web/storage/prilohy/$CV_PATH" ] && echo "  ok     jobs: the CV is stored in storage/prilohy" || { echo "  CHYBA  CV file missing: $CV_PATH"; ERRORS=$((ERRORS+1)); }
# the daily clean-up deletes applications past their retention (3 months) with the CV and records it; an ordinary enquiry of the same age stays (24 months)
ENQ_IDP=$(sq "SELECT MIN(idp) FROM ka_poptavky WHERE zdroj LIKE 'stranka:%'")
sq "UPDATE ka_poptavky SET datum = NOW() - INTERVAL 4 MONTH WHERE idp IN ($APP_IDP, $ENQ_IDP)" > /dev/null
curl -s -o /dev/null "$B/ulohy?token=testtoken123"
expect "jobs: the clean-up deleted the application after its retention and recorded it; the ordinary enquiry of the same age stays" \
  "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_poptavky WHERE idp = $APP_IDP), '|', (SELECT COUNT(*) FROM ka_poptavky WHERE idp = $ENQ_IDP), '|', (SELECT COUNT(*) FROM ka_events WHERE type = 'applications.purged' AND data LIKE '%\"count\":1,\"months\":3%'), '|', (SELECT COUNT(*) FROM ka_protokol WHERE modul = 'enquiries' AND akce = 'purge_applications'))")" "0|1|1|1"
[ ! -f "$WORK/web/storage/prilohy/$CV_PATH" ] && echo "  ok     jobs: the CV was deleted with the application" || { echo "  CHYBA  CV file still there after the clean-up"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '0' WHERE promenna = 'job_applications_months'" > /dev/null
echo "== 2.11: document library – versions, the stable latest address, download counts, gated downloads"
mcp create_collection '{"name":"Dokumenty","preset":"documents"}' > "$WORK/response"
DOCS=$(sq "SELECT seo_link FROM ka_kolekce WHERE preset = 'documents' ORDER BY idk DESC LIMIT 1"); DOCS_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = '$DOCS'")
expect "documents: the preset brings the file, category, version, summary and issued fields and item pages" "$(sq "SELECT CONCAT(detail, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].klic')), ':', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[0].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[4].klic'))) FROM ka_kolekce WHERE idk = $DOCS_IDK")" "1|file:soubor|issued"
expect "documents: the item template downloads through {{latest}} and lists {{versions}}; the list page sorts by name and filters by category" "$(sq "SELECT CONCAT(stavba LIKE '%{{latest}}%', stavba LIKE '%{{versions}}%', (SELECT CONCAT(stavba LIKE '%\"filtr_pole\":\"category\"%', stavba LIKE '%\"razeni\":\"nazev\"%') FROM ka_stranky WHERE seo_link = '$DOCS')) FROM ka_kolekce WHERE idk = $DOCS_IDK")" "1111"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"name\":\"Ceník\",\"slug\":\"cenik\",\"values\":{\"file\":\"/media/cenik-v1.pdf\",\"version\":\"1.0\",\"category\":\"Ceníky\",\"summary\":\"Platný ceník.\",\"issued\":\"2026-01-10\"},\"visible\":true}" > "$WORK/response"
DOC=$(sq "SELECT idp FROM ka_kolekce_polozky WHERE idk = $DOCS_IDK AND seo_link = 'cenik'")
expect "documents: save_collection_item returns the stable address of the file" "$(mcp_value latest_url)" "$B/$DOCS/cenik/latest"
expect "documents: a new document has no previous version" "$(sq "SELECT COUNT(*) FROM ka_document_versions WHERE idp = $DOC")" "0"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"values\":{\"file\":\"/media/cenik-v2.pdf\",\"version\":\"2.0\"}}" > /dev/null
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"values\":{\"summary\":\"Platný ceník, nové ceny.\"}}" > /dev/null
expect "documents: a changed file keeps the previous file and its version for good, a save without a file change keeps nothing" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(file), '|', MAX(version), '|', MAX(replaced_by) LIKE 'Tester%') FROM ka_document_versions WHERE idp = $DOC")" "1|/media/cenik-v1.pdf|1.0|1"
curl -s -o "$WORK/response" "$B/$DOCS/cenik"
grep -q "href=\"/$DOCS/cenik/latest\"" "$WORK/response" && grep -q 'cenik-v2.pdf)' "$WORK/response" && grep -q 'href="/media/cenik-v1.pdf">cenik-v1.pdf · Verze 1.0</a>' "$WORK/response" && grep -q 'Předchozí verze' "$WORK/response" \
  && echo "  ok     documents: the item page downloads through the stable address and lists the previous version with its number" || { echo "  CHYBA  stránka dokumentu"; grep -o 'ka-dokument-verze.*</ul>' "$WORK/response" | head -c 400; ERRORS=$((ERRORS+1)); }
expect "documents: /…/latest answers 302 to the current file" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code} %{redirect_url}' "$B/$DOCS/cenik/latest")" "302 $B/media/cenik-v2.pdf"
curl -s -A 'Mozilla/5.0 test' -o /dev/null -D "$WORK/headers" "$B/$DOCS/cenik/latest"
grep -qi '^Cache-Control: no-store' "$WORK/headers" && echo "  ok     documents: the redirect to the file is never cached" || { echo "  CHYBA  latest Cache-Control"; cat "$WORK/headers"; ERRORS=$((ERRORS+1)); }
curl -s -o /dev/null "$B/$DOCS/cenik/latest" # curl's own user agent counts as a bot
expect "documents: two downloads from one address within an hour count once, a bot never" "$(sq "SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = $DOC")" "1"
check "documents: the admin items list shows the downloads (30 days / total)" 200 "/admin.php?module=collections&action=items&id=$DOCS_IDK" '<td class="cislo stazeni">1 / 1</td>'
grep -q "href=\"/$DOCS/cenik/latest\"" "$WORK/response" && echo "  ok     documents: the admin items list links the stable address" || { echo "  CHYBA  admin latest link"; ERRORS=$((ERRORS+1)); }
mcp list_collection_items "{\"collection\":\"$DOCS\"}" > "$WORK/response"
expect "MCP: list_collection_items carries the downloads and the stable address of a document" "$(mcp_value items 0 downloads total)|$(mcp_value items 0 downloads last_30_days)|$(mcp_value items 0 latest_url)" "1|1|$B/$DOCS/cenik/latest"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":false}" > /dev/null
expect "documents: a hidden document has no download address" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$B/$DOCS/cenik/latest")" "404"
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":true,\"valid_until\":\"$YESTERDAY\"}" > /dev/null
expect "documents: an expired document has no download address even before the hourly job hides it" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$B/$DOCS/cenik/latest")" "404"
IN_TEN_DAYS=$(php -r 'echo date("Y-m-d", strtotime("+10 days"));')
mcp save_collection_item "{\"collection\":\"$DOCS\",\"id\":$DOC,\"visible\":true,\"valid_until\":\"$IN_TEN_DAYS\"}" > /dev/null
mcp site_audit '{"kind":"document"}' > "$WORK/response"
expect "documents: the site audit warns 30 days before a document expires, with the item to fix" "$(mcp_value total)|$(mcp_value findings 0 target item)" "1|$DOC"
check "Administration → Site audit shows the expiring document" 200 "/admin.php?module=audit" "Dokument platí do"
# gated downloads: a form that e-mails a file after sending – the enquiry records it, the e-mail carries a signed link (fake SMTP)
SMTP2_PORT=$((PORT + 7)); mkdir -p "$WORK/smtp2"
php "$ROOT/tools/fake-smtp.php" "$SMTP2_PORT" "$WORK/smtp2" > /dev/null 2>&1 & SMTP_PID=$!
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'smtp'), ('smtp_host', '127.0.0.1'), ('smtp_port', '$SMTP2_PORT'), ('smtp_encryption', 'zadne'), ('smtp_user', ''), ('mail_from', 'web@example.cz')" > /dev/null
mcp create_page '{"title":"Ceník e-mailem","slug":"cenik-emailem","visible":true}' > /dev/null
GATE_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'cenik-emailem'")
mcp stavba_uloz "{\"id\":$GATE_PAGE,\"publikovat\":true,\"stavba\":{\"v\":1,\"deti\":[{\"typ\":\"sekce\",\"deti\":[{\"id\":\"gate123\",\"typ\":\"formular\",\"obsah\":{\"nazev\":\"Ceník na e-mail\",\"poslat_soubor\":\"/media/cenik-v2.pdf\",\"pole\":[{\"popisek\":\"E-mail\",\"typ\":\"email\",\"povinne\":true}]}}]}]}}" > "$WORK/response"
expect "gated: the published form keeps the file to send" "$(sq "SELECT stavba LIKE '%\"poslat_soubor\":\"/media/cenik-v2.pdf\"%' FROM ka_stranky WHERE ids = $GATE_PAGE")" "1"
rm -f "$WORK"/web/storage/cache/stranky/*.html; curl -s -o "$WORK/formular.html" "$B/cenik-emailem"
GATE_SOURCE=$(field_value zdroj); GATE_ELEMENT=$(field_value prvek); GATE_TIME=$(field_value as_cas); GATE_SIGNATURE=$(field_value as_podpis)
sleep 4
location=$(curl -s -o /dev/null -w '%{redirect_url}' -X POST "$B/formular" -d "zdroj=$GATE_SOURCE" -d "prvek=$GATE_ELEMENT" -d zpet=/cenik-emailem -d "as_cas=$GATE_TIME" -d "as_podpis=$GATE_SIGNATURE" --data-urlencode p0=gate@example.cz)
case "$location" in *vysledek=ok*) echo "  ok     gated: the form was sent";; *) echo "  CHYBA  gated form: $location"; ERRORS=$((ERRORS+1));; esac
expect "gated: the enquiry records which file was sent" "$(sq "SELECT data LIKE '%Soubor poslan% e-mailem%cenik-v2.pdf%' FROM ka_poptavky WHERE email = 'gate@example.cz'")" "1"
# a plain-text e-mail: one base64 body (the eml helper above decodes the parts of a multipart newsletter)
gate_mail() { php -r '[$h, $b] = explode("\r\n\r\n", file_get_contents($argv[1]), 2); preg_match("/^Subject: (.*)$/m", $h, $s); echo "Subject-Decoded: ", mb_decode_mimeheader(trim($s[1] ?? "")), "\n", base64_decode($b);' "$1"; }
F=$(grep -l "^X-Rcpt-To: gate@example.cz" "$WORK"/smtp2/*.eml 2>/dev/null | tail -1 || true); if [ -n "$F" ]; then gate_mail "$F" > "$WORK/eml.txt"; else : > "$WORK/eml.txt"; fi
GATE_LINK=$(grep -o "http://127.0.0.1:$PORT/download/[A-Za-z0-9._-]*" "$WORK/eml.txt" | head -1 || true)
[ -n "$GATE_LINK" ] && grep -q '^Subject-Decoded: Váš soubor z webu' "$WORK/eml.txt" && grep -q 'cenik-v2.pdf' "$WORK/eml.txt" && echo "  ok     gated: the visitor got an e-mail with the file name and the download link" || { echo "  CHYBA  gated e-mail"; head -30 "$WORK/eml.txt"; ERRORS=$((ERRORS+1)); }
sq "DELETE FROM ka_kontrola_ip WHERE typ = 'stazeni'" > /dev/null # an hour has passed for the counter
expect "gated: the link redirects to the file and counts the download of the document" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code} %{redirect_url}' "$GATE_LINK")|$(sq "SELECT COALESCE(SUM(d.count), 0) FROM ka_document_downloads d WHERE d.idp = $DOC")" "302 $B/media/cenik-v2.pdf|2"
case "${GATE_LINK: -1}" in a) TAMPERED="${GATE_LINK%?}b";; *) TAMPERED="${GATE_LINK%?}a";; esac
expect "gated: a tampered token is not found" "$(curl -s -A 'Mozilla/5.0 test' -o /dev/null -w '%{http_code}' "$TAMPERED")|$(curl -s -o /dev/null -w '%{http_code}' "$B/download/nonsense.token.here")" "404|404"
kill "$SMTP_PID" 2>/dev/null || true
sq "REPLACE INTO ka_nastaveni (promenna, hodnota) VALUES ('mail_mode', 'mail'), ('smtp_host', '')" > /dev/null
echo "== 2.11: branches (LocalBusiness) and the store locator"
mcp create_collection '{"name":"Pobočky","preset":"branches"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "branches: the collection remembers its preset, has a location field and LocalBusiness data from it" \
  "$(sq "SELECT CONCAT(preset, '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].klic')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[1].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.geo')), '|', JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.openingHours'))) FROM ka_kolekce WHERE seo_link = 'pobocky'")" "branches|location|poloha|location|hours" \
  || { echo "  CHYBA  create_collection preset branches"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "branches: the item template brings the photo, the hours and a click-to-load map of the address" "$(sq "SELECT CONCAT(stavba LIKE '%{{photo}}%', stavba LIKE '%<p>{{hours}}</p>%', stavba LIKE '%\"adresa\":\"{{address}}\"%') FROM ka_kolekce WHERE seo_link = 'pobocky'")" "111"
mcp save_collection_item '{"collection":"pobocky","name":"Brno","slug":"brno","values":{"address":"Náměstí Svobody 1, 602 00 Brno","location":"49.1951, 16.6068","phone":"+420 123 456 789","email":"brno@example.com","hours":"Mo-Fr 9-17\nSa 9-12"},"visible":true}' > /dev/null
mcp save_collection_item '{"collection":"pobocky","name":"Praha","slug":"praha","values":{"address":"Václavské náměstí 1, 110 00 Praha","location":"50.0813, 14.4275","phone":"+420 987 654 321","hours":"by appointment"},"visible":true}' > /dev/null
mcp create_page '{"title":"Kde nás najdete","slug":"kde-nas-najdete","visible":true}' > /dev/null
LOCATOR_PAGE=$(sq "SELECT ids FROM ka_stranky WHERE seo_link = 'kde-nas-najdete'")
mcp save_build "{\"id\":$LOCATOR_PAGE,\"publish\":true,\"build\":{\"v\":1,\"children\":[{\"type\":\"section\",\"children\":[{\"type\":\"store_locator\"}]}]}}" > "$WORK/response"
contains -q 'published' "$WORK/response" && expect "store locator: Claude places the element by its English name, stored under its own type" "$(sq "SELECT stavba LIKE '%\"typ\":\"pobocky\"%' FROM ka_stranky WHERE ids = $LOCATOR_PAGE")" "1" \
  || { echo "  CHYBA  save_build store_locator"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp builder_schema '{"elements":["store_locator"]}' > "$WORK/response"
contains -q 'location_field' "$WORK/response" && contains -q 'show_map' "$WORK/response" && echo "  ok     store locator: builder_schema describes the element and its English options" || { echo "  CHYBA  builder_schema store_locator"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/kde-nas-najdete"
grep -q 'data-pobocky' "$WORK/response" && grep -q 'href="[^"]*/pobocky/brno"' "$WORK/response" && grep -q 'href="[^"]*/pobocky/praha"' "$WORK/response" \
  && echo "  ok     store locator: the page lists both branches with links to their pages (no JavaScript needed)" || { echo "  CHYBA  store locator list"; ERRORS=$((ERRORS+1)); }
grep -q 'href="tel:+420123456789"' "$WORK/response" && grep -q 'href="tel:+420987654321"' "$WORK/response" && grep -q 'href="mailto:brno@example.com"' "$WORK/response" \
  && echo "  ok     store locator: phones as tel: links, the e-mail as mailto:" || { echo "  CHYBA  store locator tel/mailto"; ERRORS=$((ERRORS+1)); }
grep -q 'maps/search/?api=1&amp;query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody' "$WORK/response" && grep -q '>Trasa<' "$WORK/response" && echo "  ok     store locator: a Directions link to a maps search of the address" || { echo "  CHYBA  store locator directions"; ERRORS=$((ERRORS+1)); }
grep -q 'data-lat="49.1951" data-lng="16.6068"' "$WORK/response" && grep -q 'data-lat="50.0813" data-lng="14.4275"' "$WORK/response" && grep -q 'data-text="brno n' "$WORK/response" \
  && echo "  ok     store locator: data-lat/data-lng and the search text for the script" || { echo "  CHYBA  store locator data attributes"; ERRORS=$((ERRORS+1)); }
grep -q 'data-hledat' "$WORK/response" && grep -q 'data-nejblizsi>Nejblíže ke mně<' "$WORK/response" && grep -q 'data-mapa aria-controls="pobocky-mapa-' "$WORK/response" && grep -q 'class="ka-pobocky-mapa" id="pobocky-mapa-' "$WORK/response" \
  && grep -q 'data-leaflet="[^"]*/image/vendor/leaflet/"' "$WORK/response" && grep -q 'openstreetmap.org/copyright' "$WORK/response" && grep -q 'image/web.js' "$WORK/response" \
  && echo "  ok     store locator: search, nearest and map controls in Czech, the Leaflet path and attribution, web.js kept on the page" || { echo "  CHYBA  store locator controls"; ERRORS=$((ERRORS+1)); }
check "store locator: Leaflet 1.9.4 is served from the site itself" 200 "/image/vendor/leaflet/leaflet.js" "Leaflet 1.9.4"
check "store locator: the Leaflet stylesheet and marker are there" 200 "/image/vendor/leaflet/leaflet.css" "leaflet-marker-icon"
check "store locator: the Leaflet licence is shipped" 200 "/image/vendor/leaflet/LICENSE" "BSD 2-Clause"
# the structured data of a branch page: the LocalBusiness node of the graph (the company node is there too, so the whole page is not enough)
branch_node() { php -r 'preg_match("#<script type=\"application/ld\+json\">(.*?)</script>#s", (string) file_get_contents($argv[1]), $m); foreach (json_decode($m[1] ?? "{}", true)["@graph"] ?? [] as $n) { if (($n["@type"] ?? "") === "LocalBusiness") { echo json_encode($n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); } }' "$WORK/response"; }
curl -s -o "$WORK/response" "$B/pobocky/brno"
BRNO_NODE=$(branch_node)
case "$BRNO_NODE" in *'"geo":{"@type":"GeoCoordinates","latitude":49.1951,"longitude":16.6068}'*'"openingHoursSpecification":[{"@type":"OpeningHoursSpecification","dayOfWeek":["Monday","Tuesday","Wednesday","Thursday","Friday"],"opens":"09:00","closes":"17:00"},{"@type":"OpeningHoursSpecification","dayOfWeek":["Saturday"],"opens":"09:00","closes":"12:00"}]'*'"parentOrganization":{"@id":'*)
  echo "  ok     branches: the branch page carries LocalBusiness data with the geo, the opening hours and the company as parent";;
  *) echo "  CHYBA  LocalBusiness JSON-LD (brno)"; echo "$BRNO_NODE" | head -c 600; ERRORS=$((ERRORS+1));; esac
case "$BRNO_NODE" in *'"telephone":"+420 123 456 789"'*'"email":"brno@example.com"'*) echo "  ok     branches: address, phone and e-mail in the structured data";; *) echo "  CHYBA  LocalBusiness contacts"; ERRORS=$((ERRORS+1));; esac
grep -q 'data-vlozit="https://maps.google.com/maps?q=N%C3%A1m%C4%9Bst%C3%AD%20Svobody' "$WORK/response" && grep -q '+420 123 456 789' "$WORK/response" \
  && echo "  ok     branches: the branch page shows the contacts and the map of its own address loading after a click" || { echo "  CHYBA  branch page map"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/pobocky/praha"
PRAHA_NODE=$(branch_node)
case "$PRAHA_NODE" in *openingHoursSpecification*) echo "  CHYBA  hours that do not parse must be left out"; ERRORS=$((ERRORS+1));; *'"latitude":50.0813'*) echo "  ok     branches: hours that do not parse are left out, the geo stays";; *) echo "  CHYBA  LocalBusiness JSON-LD (praha)"; echo "$PRAHA_NODE" | head -c 400; ERRORS=$((ERRORS+1));; esac
check "branches: the collection form offers the LocalBusiness type with its properties" 200 "/admin.php?module=collections&action=edit&id=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'pobocky'")" 'value="LocalBusiness"'
grep -q 'name="schema\[pole\]\[openingHours\]"' "$WORK/response" && grep -q 'name="schema\[pole\]\[geo\]"' "$WORK/response" && echo "  ok     branches: geo and opening hours can be mapped in the form" || { echo "  CHYBA  schema form LocalBusiness"; ERRORS=$((ERRORS+1)); }
# a team created after the branches links each person to a branch (system/presets/people.php: branch → preset branches)
mcp create_collection '{"name":"Tým poboček","preset":"people"}' > /dev/null
expect "branches: a team made afterwards gets the branch field linked to the branches" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'tym-pobocek' AND pole LIKE '%\"klic\":\"branch\"%\"typ\":\"polozka\",\"kolekce\":\"pobocky\"%'")" "1"
echo "== 2.11 F1: six more ready-made collections"
# preset => "preset|item pages|schema type|number of fields|hidden list page" (system/presets/<preset>.php); the services come first so that a reference can link to them
preset_row() { sq "SELECT CONCAT(k.preset, '|', k.detail, '|', IFNULL(JSON_UNQUOTE(JSON_EXTRACT(k.schema_org, '\$.typ')), '-'), '|', JSON_LENGTH(k.pole), '|', (SELECT COUNT(*) FROM ka_stranky s WHERE s.seo_link = k.seo_link AND s.zobrazit = 0)) FROM ka_kolekce k WHERE k.seo_link = '$1'"; }
mcp create_collection '{"name":"Preset služby","preset":"services"}' > "$WORK/response"
contains -q 'how_to_use' "$WORK/response" && expect "presets: services – item pages, Service schema, five fields, a hidden list page" "$(preset_row preset-sluzby)" "services|1|Service|5|1" || { echo "  CHYBA  create_collection preset services"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "presets: the Service schema maps the price to price_from" "$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.price')) FROM ka_kolekce WHERE seo_link = 'preset-sluzby'")" "price_from"
mcp create_collection '{"name":"Preset reference","preset":"references"}' > /dev/null
expect "presets: references – item pages, no schema, seven fields (the service link included), a hidden list page" "$(preset_row preset-reference)" "references|1|-|7|1"
expect "presets: the service field of a reference links to the services collection" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].klic')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].typ')), '|', JSON_UNQUOTE(JSON_EXTRACT(pole, '\$[5].kolekce'))) FROM ka_kolekce WHERE seo_link = 'preset-reference'")" "service|polozka|preset-sluzby"
mcp create_collection '{"name":"Preset ceník","preset":"price_list"}' > /dev/null
expect "presets: price list – no item pages, four fields, a hidden list page" "$(preset_row preset-cenik)" "price_list|0|-|4|1"
expect "presets: the price list page filters by category with buttons, sorted by order" "$(sq "SELECT CONCAT(stavba LIKE '%\"filtr_pole\":\"category\"%', '|', stavba LIKE '%\"filtry\":true%', '|', stavba LIKE '%<p>{{price}}</p>%') FROM ka_stranky WHERE seo_link = 'preset-cenik'")" "1|1|1"
mcp create_collection '{"name":"Preset FAQ","preset":"faq"}' > /dev/null
expect "presets: questions and answers – no item pages, FAQPage schema, two fields, a hidden list page" "$(preset_row preset-faq)" "faq|0|FAQPage|2|1"
mcp create_collection '{"name":"Preset stroje","preset":"machines"}' > /dev/null
expect "presets: machines – item pages, Product schema with the model as SKU, six fields" "$(preset_row preset-stroje)|$(sq "SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.pole.sku')) FROM ka_kolekce WHERE seo_link = 'preset-stroje'")" "machines|1|Product|6|1|model"
mcp create_collection '{"name":"Preset kurzy","preset":"courses"}' > "$WORK/response"
expect "presets: courses – item pages, Event schema, seven fields, a hidden list page" "$(preset_row preset-kurzy)" "courses|1|Event|7|1"
expect "presets: the courses page lists the upcoming ones by start and end, sorted by the start" "$(sq "SELECT CONCAT(stavba LIKE '%\"obdobi\":\"nadchazejici\"%', '|', stavba LIKE '%\"obdobi_od\":\"start\"%', '|', stavba LIKE '%\"razeni_pole\":\"start\"%', '|', stavba LIKE '%<p>{{start}}</p>%') FROM ka_stranky WHERE seo_link = 'preset-kurzy'")" "1|1|1|1"
expect "presets: the course item template comes from the preset (the dates, the place, the registration form)" "$(sq "SELECT CONCAT(stavba LIKE '%<strong>{{when}}</strong>%', '|', stavba LIKE '%{{capacity}}%', '|', stavba LIKE '%\"typ\":\"formular\"%') FROM ka_kolekce WHERE seo_link = 'preset-kurzy'")" "1|1|1"
FUTURE_DAY=$(php -r 'echo date("Y-m-d", strtotime("+30 days"));'); PAST_DAY=$(php -r 'echo date("Y-m-d", strtotime("-30 days"));')
mcp save_collection_item "{\"collection\":\"preset-kurzy\",\"name\":\"Kurz svařování\",\"slug\":\"kurz-svarovani\",\"values\":{\"start\":\"$FUTURE_DAY 09:00\",\"end\":\"$FUTURE_DAY 16:00\",\"place\":\"Brno\",\"price\":\"1900\"},\"visible\":true}" > "$WORK/response"
contains -q 'kurz-svarovani' "$WORK/response" && echo "  ok     presets: a course with a future start" || { echo "  CHYBA  save_collection_item (course)"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp save_collection_item "{\"collection\":\"preset-kurzy\",\"name\":\"Kurz loňský\",\"slug\":\"kurz-lonsky\",\"values\":{\"start\":\"$PAST_DAY 09:00\",\"place\":\"Praha\"},\"visible\":true}" > /dev/null
check "presets: the course page shows the place and the formatted start" 200 /preset-kurzy/kurz-svarovani "Brno"
grep -q '"Event"' "$WORK/response" && grep -q '"startDate"' "$WORK/response" && echo "  ok     presets: the course page carries the Event structured data" || { echo "  CHYBA  course Event schema"; ERRORS=$((ERRORS+1)); }
# the hidden list page in the administrator's preview shows only the course still to come
curl -s -b "$JAR" -o "$WORK/response" "$B/preset-kurzy?stavba=koncept"
grep -q 'Kurz svařování' "$WORK/response" && ! grep -q 'Kurz loňský' "$WORK/response" && echo "  ok     presets: the courses list shows the future course and not the past one" || { echo "  CHYBA  courses list by date"; grep -o 'Kurz [a-zě]*' "$WORK/response" | sort -u; ERRORS=$((ERRORS+1)); }

echo "== 2.11 F1: screen mode for a reception"
expect "screen: off → 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$(printf 'a%.0s' $(seq 1 32))")" "404"
mcp update_settings '{"settings":{"screen_collections":["neexistuje"]}}' > "$WORK/response"
contains -q 'Unknown collections: neexistuje' "$WORK/response" && echo "  ok     MCP: the screen shows only collections that exist" || { echo "  CHYBA  screen_collections validation"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp update_settings '{"settings":{"screen_mode":1,"screen_seconds":"7","screen_collections":["preset-kurzy"],"screen_hours":1,"screen_clock":1}}' > "$WORK/response"
SCREEN_SECRET=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_secret'")
expect "screen: switching the mode on creates the secret part of the address" "${#SCREEN_SECRET}" "32"
contains -q 'screen\\":{\\"on\\":true,\\"seconds\\":7,\\"collections\\":\[\\"preset-kurzy\\"\]' "$WORK/response" && ! contains -q 'screen_secret' "$WORK/response" && ! contains -q "$SCREEN_SECRET" "$WORK/response" \
  && echo "  ok     MCP: update_settings switches the screen on and reports it without the secret" || { echo "  CHYBA  update_settings screen"; head -c 600 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "screen: on with the right secret → 200 with noindex" 200 "/screen/$SCREEN_SECRET" '<meta name="robots" content="noindex, nofollow">'
grep -q 'obrazovka-slide obrazovka-news' "$WORK/response" && grep -q 'Kurz svařování' "$WORK/response" && ! grep -q 'Kurz loňský' "$WORK/response" && grep -q 'obrazovka-hours' "$WORK/response" && grep -q 'id="obrazovka-hodiny"' "$WORK/response" \
  && echo "  ok     screen: slides of the news, the upcoming course only, today's opening hours and the clock" || { echo "  CHYBA  screen slides"; grep -o 'obrazovka-[a-z]*' "$WORK/response" | sort | uniq -c; ERRORS=$((ERRORS+1)); }
grep -q '<noscript><meta http-equiv="refresh" content="7; url=/screen/'"$SCREEN_SECRET"'?s=1">' "$WORK/response" && grep -q 'setInterval(function(){i=(i+1)%n;show(i)},7000)' "$WORK/response" && grep -q -e '--ka-barva-primarni:' "$WORK/response" \
  && echo "  ok     screen: rotates every 7 seconds with the script and by a meta refresh without it, in the site's design tokens" || { echo "  CHYBA  screen rotation"; ERRORS=$((ERRORS+1)); }
curl -s -D "$WORK/headers" -o /dev/null "$B/screen/$SCREEN_SECRET?s=1"
grep -qi '^X-Robots-Tag: noindex' "$WORK/headers" && grep -qi '^Cache-Control: no-store' "$WORK/headers" && ! grep -qi '^Set-Cookie' "$WORK/headers" && echo "  ok     screen: noindex and no-store headers, no cookies" || { echo "  CHYBA  screen headers"; cat "$WORK/headers"; ERRORS=$((ERRORS+1)); }
expect "screen: a wrong secret → 404" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$(echo "$SCREEN_SECRET" | tr '0-9a-f' '1-9a-f0')")" "404"
check "screen: the admin shows the full address with a copy button" 200 "/admin.php?module=settings&tab=general" "screen/$SCREEN_SECRET"
grep -q 'data-kopirovat="#screen-url"' "$WORK/response" && grep -q 'name="screen_collections\[\]" value="preset-kurzy" checked' "$WORK/response" && echo "  ok     screen: the copy button and the chosen collection" || { echo "  CHYBA  screen admin form"; ERRORS=$((ERRORS+1)); }
# the "new address" button: the form is posted as a browser does, the old address stops working
curl -s -b "$JAR" -c "$JAR" -o "$WORK/general.html" "$B/admin.php?module=settings&tab=general"
php -r '$d = new DOMDocument(); @$d->loadHTML(file_get_contents($argv[1])); $x = new DOMXPath($d); $f = $x->query("//form[.//input[@name=\"tab\"]]")->item(0); $q = [];
  foreach ($x->query(".//input|.//select|.//textarea", $f) as $e) { $n = $e->getAttribute("name"); $t = $e->getAttribute("type"); if ($n === "" || $t === "submit" || (in_array($t, ["checkbox", "radio"], true) && !$e->hasAttribute("checked"))) continue;
    $v = $e->nodeName === "select" ? (($o = $x->query(".//option[@selected]", $e)->item(0) ?? $x->query(".//option", $e)->item(0)) ? $o->getAttribute("value") : "") : ($e->nodeName === "textarea" ? $e->textContent : $e->getAttribute("value")); $q[] = rawurlencode($n) . "=" . rawurlencode($v); }
  echo implode("&", $q);' "$WORK/general.html" > "$WORK/general.post"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" --data-binary @"$WORK/general.post" -d novy_token_obrazovka=1
SCREEN_SECRET_2=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_secret'")
[ "${#SCREEN_SECRET_2}" = 32 ] && [ "$SCREEN_SECRET_2" != "$SCREEN_SECRET" ] && echo "  ok     screen: the button creates a new address" || { echo "  CHYBA  new screen address"; ERRORS=$((ERRORS+1)); }
expect "screen: the old address stops working, the new one works, the seconds stay" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET")|$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET_2")|$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'screen_seconds'")" "404|200|7"
mcp update_settings '{"settings":{"screen_mode":0}}' > /dev/null
expect "screen: switched off → 404 even with the right secret" "$(curl -s -o /dev/null -w '%{http_code}' "$B/screen/$SCREEN_SECRET_2")" "404"

echo "== 2.11: official notice board – posting and takedown dates, permanent archive, audit trail"
sq "INSERT INTO ka_jobs (name, last_run) VALUES ('notices', NOW() + INTERVAL 1 DAY) ON DUPLICATE KEY UPDATE last_run = VALUES(last_run)" > /dev/null # the job runs only when the test asks (a day ahead: MySQL and PHP may be in different time zones)
N_YESTERDAY=$(php -r 'echo date("Y-m-d", strtotime("-1 day"));'); N_TOMORROW=$(php -r 'echo date("Y-m-d", strtotime("+1 day"));'); N_TEN_AGO=$(php -r 'echo date("Y-m-d", strtotime("-10 day"));')
N_YESTERDAY_CZ=$(php -r 'echo date("j. n. Y", strtotime("-1 day"));'); N_TOMORROW_CZ=$(php -r 'echo date("j. n. Y", strtotime("+1 day"));')
mcp create_collection '{"name":"Úřední deska","preset":"notices"}' > "$WORK/response"
expect "notices: the collection with its board and its archive page, both hidden, each listing its period" "$(sq "SELECT CONCAT((SELECT preset FROM ka_kolekce WHERE seo_link = 'uredni-deska'), '|', (SELECT COUNT(*) FROM ka_stranky WHERE seo_link IN ('uredni-deska', 'uredni-deska-archive') AND zobrazit = 0), '|', (SELECT stavba LIKE '%\"obdobi\":\"probihajici\"%' FROM ka_stranky WHERE seo_link = 'uredni-deska'), '|', (SELECT stavba LIKE '%\"obdobi\":\"minule\"%' AND stavba LIKE '%\"kolekce\":\"uredni-deska\"%' FROM ka_stranky WHERE seo_link = 'uredni-deska-archive'), '|', (SELECT titulek FROM ka_stranky WHERE seo_link = 'uredni-deska-archive'))")" "notices|2|1|1|Úřední deska – archive" # over MCP the texts are English (as the field labels of every preset); from the admin the name is translated
contains -q 'more_pages' "$WORK/response" && contains -q 'uredni-deska-archive' "$WORK/response" && echo "  ok     notices: Claude is told about the archive page" || { echo "  CHYBA  more_pages"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
expect "notices: the item template comes from the preset with the status line" "$(sq "SELECT stavba LIKE '%{{notice_status}}%' FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" "1"
BOARD_IDK=$(sq "SELECT idk FROM ka_kolekce WHERE seo_link = 'uredni-deska'")
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Záměr pronájmu\",\"slug\":\"zamer-pronajmu\",\"values\":{\"posted\":\"$N_YESTERDAY\",\"taken_down\":\"$N_TOMORROW\",\"reference\":\"MU/2026/41\",\"issuer\":\"Městský úřad\",\"category\":\"Majetek\",\"summary\":\"Záměr pronajmout pozemek.\"},\"visible\":true}" > "$WORK/response"; NOTICE_A=$(mcp_value id)
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Rozpočet 2026\",\"slug\":\"rozpocet-2026\",\"values\":{\"posted\":\"$N_TEN_AGO\",\"taken_down\":\"$N_YESTERDAY\",\"reference\":\"MU/2026/12\",\"category\":\"Rozpočet\"},\"visible\":true}" > "$WORK/response"; NOTICE_B=$(mcp_value id)
mcp save_collection_item '{"collection":"uredni-deska","name":"Budoucí vyhláška","slug":"budouci","values":{"posted":"2099-01-01"}}' > "$WORK/response"
expect "notices: a notice still to be posted may stay hidden" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE seo_link = 'budouci'")" "0"
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"name\":\"Skrytá minulá\",\"values\":{\"posted\":\"$N_YESTERDAY\"}}" > "$WORK/response"
contains -q 'cannot be hidden' "$WORK/response" && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce_polozky WHERE nazev = 'Skrytá minulá'")" = 0 ] && echo "  ok     MCP: a notice whose posting day has come cannot be created hidden" || { echo "  CHYBA  hidden notice created"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
for slug in uredni-deska uredni-deska-archive; do mcp update_page "{\"id\":$(sq "SELECT ids FROM ka_stranky WHERE seo_link = '$slug'"),\"visible\":true}" > /dev/null; done
curl -s -o "$WORK/response" "$B/uredni-deska"
grep -q 'Záměr pronájmu' "$WORK/response" && grep -q 'MU/2026/41' "$WORK/response" && ! grep -q 'Rozpočet 2026' "$WORK/response" && grep -q 'Majetek' "$WORK/response" \
  && echo "  ok     notices: the board shows the current notice with its reference and the category filter, not the archived one" || { echo "  CHYBA  board page"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/uredni-deska-archive"
grep -q 'Rozpočet 2026' "$WORK/response" && ! grep -q 'Záměr pronájmu' "$WORK/response" && echo "  ok     notices: the archive shows the notice taken down yesterday, not the current one" || { echo "  CHYBA  archive page"; ERRORS=$((ERRORS+1)); }
check "notices: the item page says from when to when the notice is posted" 200 /uredni-deska/zamer-pronajmu "Vyvěšeno od $N_YESTERDAY_CZ do $N_TOMORROW_CZ"
grep -q 'Městský úřad' "$WORK/response" && echo "  ok     notices: the item page has the issuer" || { echo "  CHYBA  item page"; ERRORS=$((ERRORS+1)); }
curl -s -o "$WORK/response" "$B/uredni-deska/rozpocet-2026" # as a visitor: the page must not land in the page cache
grep -q "Sejmuto $N_YESTERDAY_CZ – archiv" "$WORK/response" && ! grep -qs 'Sejmuto' "$WORK"/web/storage/cache/stranky/*.html && echo "  ok     notices: an archived notice says when it was taken down, and the page is not cached" || { echo "  CHYBA  archived notice page"; ERRORS=$((ERRORS+1)); }
# the permanent archive: no trash, no hiding once posted, the collection stays while it has notices
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_A,\"visible\":false}" > "$WORK/response"
contains -q 'cannot be hidden' "$WORK/response" && [ "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $NOTICE_A")" = 1 ] && echo "  ok     MCP: a posted notice cannot be hidden" || { echo "  CHYBA  MCP hid a notice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
mcp delete_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B}" > "$WORK/response"
contains -q 'stay in the archive' "$WORK/response" && [ "$(sq "SELECT smazano IS NULL FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" = 1 ] && echo "  ok     MCP: delete_collection_item refuses a notice with a clear message" || { echo "  CHYBA  MCP deleted a notice"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$BOARD_IDK"; TOKEN=$(csrf)
! grep -q 'action=delete_item"' "$WORK/response" && grep -q 'archiv' "$WORK/response" && echo "  ok     admin: the notices list has no Delete button" || { echo "  CHYBA  delete button on a board"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B"
expect "admin: the delete action refuses a notice" "$(sq "SELECT smazano IS NULL FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" "1"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=items&id=$BOARD_IDK"
grep -q 'změňte místo toho datum sejmutí' "$WORK/response" && echo "  ok     admin: the refusal is explained" || { echo "  CHYBA  admin refusal message"; ERRORS=$((ERRORS+1)); }
mcp delete_collection '{"collection":"uredni-deska"}' > "$WORK/response"
contains -q 'cannot be deleted' "$WORK/response" && [ "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" = 1 ] && echo "  ok     MCP: the board cannot be deleted while it has notices" || { echo "  CHYBA  delete_collection deleted a board"; head -c 300 "$WORK/response"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=delete" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK"
expect "admin: the collection delete refuses a board with notices" "$(sq "SELECT COUNT(*) FROM ka_kolekce WHERE seo_link = 'uredni-deska'")" "1"
# the audit trail: created and changed rows, by whom, what changed; a save without a change writes nothing
expect "notices: a created row per notice, written by Claude, with the values" "$(sq "SELECT CONCAT(COUNT(*), '|', GROUP_CONCAT(DISTINCT \`by\`), '|', (SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '$.reference[1]')) FROM ka_notice_log WHERE idp = $NOTICE_A AND action = 'created')) FROM ka_notice_log WHERE action = 'created'")" "3|Claude|MU/2026/41"
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B,\"values\":{\"summary\":\"Schválený rozpočet.\"}}" > /dev/null
mcp save_collection_item "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B,\"values\":{\"summary\":\"Schválený rozpočet.\"}}" > /dev/null
expect "notices: a change is logged once with the field, the old and the new value" "$(sq "SELECT CONCAT(COUNT(*), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '$.summary[0]'))), '|', MAX(JSON_UNQUOTE(JSON_EXTRACT(fields, '$.summary[1]'))), '|', MAX(JSON_CONTAINS_PATH(fields, 'one', '$.reference'))) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'changed'")" "1||Schválený rozpočet.|0"
curl -s -b "$JAR" -o "$WORK/response" "$B/admin.php?module=collections&action=item&id=$BOARD_IDK&polozka=$NOTICE_B"; TOKEN=$(csrf)
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B" --data-urlencode "nazev=Rozpočet 2026" -d "seo_link=rozpocet-2026" -d "poradi=100" -d "zobrazit=1" \
  -d "data[posted]=$N_TEN_AGO" -d "data[taken_down]=$N_YESTERDAY" -d "data[reference]=MU/2026/12" --data-urlencode "data[issuer]=Rada města" --data-urlencode "data[category]=Rozpočet" -d "data[document]=" --data-urlencode "data[summary]=Schválený rozpočet."
expect "admin: saving the form logs the change under the user's name" "$(sq "SELECT CONCAT(\`by\`, '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '$.issuer[1]'))) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'changed' ORDER BY id DESC LIMIT 1")" "Tester|Rada města"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=collections&action=save_item" -d "_csrf=$TOKEN" -d "idk=$BOARD_IDK" -d "idp=$NOTICE_B" --data-urlencode "nazev=Rozpočet 2026" -d "seo_link=rozpocet-2026" -d "poradi=100" \
  -d "data[posted]=$N_TEN_AGO" -d "data[taken_down]=$N_YESTERDAY" -d "data[reference]=MU/2026/12" --data-urlencode "data[issuer]=Rada města"
expect "admin: the form cannot hide a posted notice either" "$(sq "SELECT zobrazit FROM ka_kolekce_polozky WHERE idp = $NOTICE_B")" "1"
# the hourly job records posted and taken down once each and reports it
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'notices: posted 2, taken down 1' "$WORK/tasks.txt" && echo "  ok     notices: the job reports what it recorded" || { echo "  CHYBA  notices job output"; cat "$WORK/tasks.txt"; ERRORS=$((ERRORS+1)); }
expect "notices: posted for both visible notices, taken_down for the archived one, by system" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_notice_log WHERE idp = $NOTICE_A AND action = 'posted'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE idp = $NOTICE_B AND action = 'taken_down' AND JSON_UNQUOTE(JSON_EXTRACT(fields, '$.taken_down')) = '$N_YESTERDAY'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down') AND \`by\` = 'system'), '|', (SELECT COUNT(*) FROM ka_notice_log WHERE idp = (SELECT idp FROM ka_kolekce_polozky WHERE seo_link = 'budouci') AND action <> 'created'))")" "1|1|3|0"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'notices'" > /dev/null; curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
grep -q 'notices: posted 0, taken down 0' "$WORK/tasks.txt" && [ "$(sq "SELECT COUNT(*) FROM ka_notice_log WHERE action IN ('posted', 'taken_down')")" = 3 ] && echo "  ok     notices: a second run records nothing twice" || { echo "  CHYBA  notices job ran twice"; ERRORS=$((ERRORS+1)); }
# the log under the item form and the CSV for an administrator, not for a guest
check "notices: the item form shows the log with the CSV link" 200 "/admin.php?module=collections&action=item&id=$BOARD_IDK&polozka=$NOTICE_B" "action=notice_log"
grep -q 'Rada města' "$WORK/response" && grep -q "taken_down: $N_YESTERDAY" "$WORK/response" && echo "  ok     notices: the log shows the changes and the takedown" || { echo "  CHYBA  log under the form"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -b "$JAR" -o "$WORK/response" -w '%{http_code} %{content_type}' "$B/admin.php?module=collections&action=notice_log&id=$BOARD_IDK")
expect "notices: the administrator downloads the log as CSV" "$code" "200 text/csv; charset=utf-8"
[ "$(grep -c ';taken_down;' "$WORK/response")|$(grep -c ';posted;' "$WORK/response")|$(grep -c ';created;' "$WORK/response")" = "1|2|3" ] && grep -q 'Rada města' "$WORK/response" && echo "  ok     notices: the CSV has every row of the board" || { echo "  CHYBA  notice log CSV"; head -c 400 "$WORK/response"; ERRORS=$((ERRORS+1)); }
code=$(curl -s -o "$WORK/response" -w '%{content_type}' "$B/admin.php?module=collections&action=notice_log&id=$BOARD_IDK")
[[ "$code" == text/html* ]] && ! grep -q 'taken_down' "$WORK/response" && grep -q 'Heslo' "$WORK/response" && echo "  ok     notices: a guest gets the sign-in form instead of the CSV" || { echo "  CHYBA  notice log for a guest ($code)"; ERRORS=$((ERRORS+1)); }
mcp list_notice_log '{"collection":"uredni-deska"}' > "$WORK/response"
expect "MCP: list_notice_log lists the whole trail with who and what" "$(mcp_value count)|$(mcp_value entries 0 action)|$(mcp_value entries 0 by)|$(mcp_value entries 0 fields reference 1)" "8|created|Claude|MU/2026/41"
mcp list_notice_log "{\"collection\":\"uredni-deska\",\"id\":$NOTICE_B}" > "$WORK/response"
expect "MCP: list_notice_log of one notice" "$(mcp_value count)|$(mcp_value entries 4 action)|$(mcp_value entries 4 fields taken_down)" "5|taken_down|$N_YESTERDAY"
curl -s -X POST "$B/mcp" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' > "$WORK/response"
php -r '$t = array_column(json_decode(file_get_contents($argv[1]), true)["result"]["tools"], "annotations", "name"); exit($t["list_notice_log"]["readOnlyHint"] === true && !isset($t["edit_notice_log"]) && !isset($t["delete_notice_log"]) ? 0 : 1);' "$WORK/response" \
  && echo "  ok     MCP: the notice log is read-only – no tool edits or deletes it" || { echo "  CHYBA  notice log tools"; ERRORS=$((ERRORS+1)); }
echo "== 2.11: the six shipped industry blueprints"
mcp get_blueprint '{}' > "$WORK/response"
BLUEPRINTS_LISTED=0; for key in clinic manufacturer craftsman driving_school farm municipality; do contains -q "key\\\\\":\\\\\"$key" "$WORK/response" && BLUEPRINTS_LISTED=$((BLUEPRINTS_LISTED+1)); done
expect "shipped blueprints: get_blueprint lists the six as available" "$BLUEPRINTS_LISTED" "6"
BLUEPRINT_IDK0=$(sq "SELECT IFNULL(MAX(idk), 0) FROM ka_kolekce")
BLUEPRINT_MISSING=$(sq "SELECT 5 - COUNT(DISTINCT preset) FROM ka_kolekce WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')") # the earlier blocks made some of them – apply creates only what the site lacks
mcp apply_blueprint '{"key":"municipality"}' > "$WORK/response"
contains -q 'applied\\":\\"municipality' "$WORK/response" && expect "shipped blueprints: the municipality brings the collections the site lacks ($BLUEPRINT_MISSING of its five) and its facts without values" \
  "$(sq "SELECT CONCAT((SELECT COUNT(DISTINCT preset) FROM ka_kolekce WHERE preset IN ('notices', 'documents', 'events', 'people', 'faq')), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0), '|', (SELECT COUNT(*) FROM ka_facts WHERE fact_key IN ('mayor_name', 'population', 'filing_office_email', 'council_meetings') AND value = ''), '|', (SELECT type FROM ka_facts WHERE fact_key = 'population'), '|', (SELECT bkey FROM ka_blueprints))")" "5|$BLUEPRINT_MISSING|4|number|municipality" \
  || { echo "  CHYBA  apply_blueprint municipality"; head -c 500 "$WORK/response"; ERRORS=$((ERRORS+1)); }
check "shipped blueprints: the admin page asks the municipality's questions" 200 "/admin.php?module=blueprints" 'name="answer\[mayor_name\]"'
grep -q 'Kdo je starostou nebo starostkou obce?' "$WORK/response" && grep -q 'Uveďte starostu nebo starostku' "$WORK/response" && echo "  ok     shipped blueprints: the question and the failing check about the mayor are in the admin language" || { echo "  CHYBA  otázky plánu obce v administraci"; ERRORS=$((ERRORS+1)); }
# the site as before: the blueprint off, its empty collections and their hidden pages gone (the facts stay – removing never deletes them); later blocks make their own
mcp remove_blueprint '{"key":"municipality"}' > /dev/null
for slug in $(sq "SELECT seo_link FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0"); do mcp delete_collection "{\"collection\":\"$slug\"}" > /dev/null; sq "DELETE FROM ka_stranky WHERE seo_link IN ('$slug', '$slug-archive') AND zobrazit = 0" > /dev/null; done
expect "shipped blueprints: removed again, the site has no blueprint and no collection of the municipality" "$(sq "SELECT CONCAT((SELECT COUNT(*) FROM ka_blueprints), '|', (SELECT COUNT(*) FROM ka_kolekce WHERE idk > $BLUEPRINT_IDK0))")" "0|0"
echo "== 2.9: monthly report by e-mail"
REPORT_MAILS() { sq "SELECT COUNT(*) FROM ka_posta WHERE predmet LIKE '%Zpráva o webu%' OR predmet LIKE '%Website report%'"; }
LAST_MONTH=$(php -r 'echo (new DateTimeImmutable("first day of last month"))->format("Y-m");')
sq "INSERT INTO ka_nastaveni VALUES ('report_monthly','1'),('report_recipients','owner@example.cz'),('report_last_month','') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=mail"; TOKEN=$(csrf)
grep -q 'name="report_recipients"' "$WORK/response" && grep -q 'action=report_preview' "$WORK/response" && grep -q 'action=report_send' "$WORK/response" && echo "  ok     Settings → Mail has the monthly report with its preview and send buttons" || { echo "  CHYBA  Mail tab: monthly report"; ERRORS=$((ERRORS+1)); }
check "the preview is the e-mail of the last month with the site name" 200 "/admin.php?module=settings&action=report_preview" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_name'")"
grep -q 'max-width:600px' "$WORK/response" && grep -q 'Studio Test' "$WORK/response" && ! grep -q 'spravce@example.cz' "$WORK/response" && echo "  ok     the preview is an inline-styled e-mail with the agency, without the site e-mail" || { echo "  CHYBA  report preview"; ERRORS=$((ERRORS+1)); }
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=report_send" -d "_csrf=$TOKEN"
expect "send now: the report is queued for the recipient" "$(sq "SELECT COUNT(*) FROM ka_posta WHERE komu = 'owner@example.cz' AND (predmet LIKE '%Zpráva o webu%' OR predmet LIKE '%Website report%')")" "1"
expect "send now remembers the month, so the job does not send it again" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_last_month'")" "$LAST_MONTH"
# the job: with the month forgotten it sends once, the second run finds it sent
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'report_last_month'; UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'" > /dev/null
BEFORE=$(REPORT_MAILS)
curl -s -o "$WORK/tasks.txt" "$B/ulohy?token=testtoken123"
sq "UPDATE ka_jobs SET last_run = NULL WHERE name = 'monthly_report'" > /dev/null
curl -s -o "$WORK/tasks2.txt" "$B/ulohy?token=testtoken123"
expect "the job sends the previous month once: two runs, one more report" "$(( $(REPORT_MAILS) - BEFORE ))" "1"
grep -q "monthly_report: sent to 1" "$WORK/tasks.txt" && grep -q "monthly_report: sent already" "$WORK/tasks2.txt" && echo "  ok     the job reports what it did" || { echo "  CHYBA  monthly_report job"; cat "$WORK/tasks.txt" "$WORK/tasks2.txt"; ERRORS=$((ERRORS+1)); }
expect "report.sent events carry the month and the count, never an address" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'report.sent' AND data LIKE '%\"month\":\"$LAST_MONTH\"%' AND data NOT LIKE '%@%'")" "2"
# recipients are validated on save: one bad address rejects the field, good ones are kept one per line
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=mail -d mail_mode=mail -d report_monthly=1 --data-urlencode "report_recipients=owner@example.cz, nonsense"
expect "recipients: an invalid address is not saved" "$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_recipients'")" "owner@example.cz"
curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=save" -d "_csrf=$TOKEN" -d tab=mail -d mail_mode=mail --data-urlencode "report_recipients=owner@example.cz, agentura@example.cz"
expect "recipients: valid addresses are saved one per line, the switch off when unchecked" "$(sq "SELECT CONCAT(REPLACE((SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_recipients'), '\n', '|'), ':', (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'report_monthly'))")" "owner@example.cz|agentura@example.cz:0"

echo "== instalace aktualizace (testovací klíč a kanál)"
cat > "$WORK/vydani-test.php" <<'PHP'
<?php
[$site, $port] = [$argv[1], $argv[2]];
require $site . '/system/src/Core/Signature.php';
$pair = sodium_crypto_sign_keypair();
$sk = sodium_crypto_sign_secretkey($pair);
file_put_contents($site . '/system/aktualizace.pub', base64_encode(sodium_crypto_sign_publickey($pair)) . " test\n");
// seznam souborů „nainstalované verze“: podle otisku .htaccess aktualizace pozná, že ho správce upravil
file_put_contents($site . '/system/soubory.json', json_encode(['verze' => '1.0.0-dev', 'soubory' => ['.htaccess' => hash_file('sha256', $site . '/.htaccess')]]));
@mkdir(dirname($site) . '/kanal');
$zip = new ZipArchive();
$zip->open(dirname($site) . '/kanal/k.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('image/test-aktualizace.txt', "nova verze\n");
$zip->addFromString('.htaccess', "# htaccess nove verze\n");
// the core of the package says it is 9.9.9 – the check after the update (2.8) asks the site which version runs
$bootstrap = (string) preg_replace("/const KALETA_VERSION = '[^']*';/", "const KALETA_VERSION = '9.9.9';", (string) file_get_contents($site . '/system/bootstrap.php'));
$zip->addFromString('system/bootstrap.php', $bootstrap); // balíček musí nést jádro
$zip->addFromString('index.php', (string) file_get_contents($site . '/index.php'));
$zip->close();
$sha = hash_file('sha256', dirname($site) . '/kanal/k.zip');
$m = ['verze' => '9.9.9', 'url' => "http://127.0.0.1:$port/k.zip", 'sha256' => $sha, 'min_php' => '8.4', 'zmeny' => ['test'],
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage('9.9.9', $sha, false), $sk))];
file_put_contents(dirname($site) . '/kanal/ok.json', json_encode($m));
// 2.8: a package that installs fine but breaks the home page – the update must undo itself
$broken = new ZipArchive();
$broken->open(dirname($site) . '/kanal/b.zip', ZipArchive::CREATE);
$broken->addFromString('image/test-rozbita.txt', "rozbita verze\n");
$broken->addFromString('system/bootstrap.php', $bootstrap);
$broken->addFromString('index.php', "<?php\nif (str_starts_with((string) parse_url((string) (\$_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/ulohy')) { require __DIR__ . '/system/bootstrap.php'; \$app = Kaleta\\Core\\App::boot(); (new Kaleta\\Front\\Kernel(\$app))->handle()->send(); exit; }\nhttp_response_code(500);\necho 'broken';\n");
$broken->close();
$shaB = hash_file('sha256', dirname($site) . '/kanal/b.zip');
file_put_contents(dirname($site) . '/kanal/rozbity.json', json_encode(['url' => "http://127.0.0.1:$port/b.zip", 'sha256' => $shaB,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage('9.9.9', $shaB, false), $sk))] + $m));
file_put_contents(dirname($site) . '/kanal/zly.json', json_encode(['podpis' => base64_encode(random_bytes(64))] + $m));
PHP
# the channel on its own server: the built-in PHP server handles only one request at a time, it could not download from itself
CHANNEL_PORT=$((PORT + 1))
php "$WORK/vydani-test.php" "$WORK/web" "$CHANNEL_PORT"
(cd "$WORK/kanal" && exec php -S "127.0.0.1:$CHANNEL_PORT" > /dev/null 2>&1) & CHANNEL_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$CHANNEL_PORT/ok.json" && break; sleep 0.2; done
echo "# vlastni uprava spravce" >> "$WORK/web/.htaccess"
curl -s -b "$JAR" -c "$JAR" -o "$WORK/response" "$B/admin.php?module=settings&tab=backups"; TOKEN=$(csrf)
update_from() { "${MYSQL[@]}" "$DB_NAME" -e "INSERT INTO ka_nastaveni VALUES ('update_url','http://127.0.0.1:$CHANNEL_PORT/$1') ON DUPLICATE KEY UPDATE hodnota=VALUES(hodnota); UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_cache'"
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -X POST "$B/admin.php?module=settings&action=update" -d "_csrf=$TOKEN"; }
update_from zly.json
[ ! -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     balíček s cizím podpisem se nenainstaluje" || { echo "  CHYBA  nainstalován balíček s neplatným podpisem"; ERRORS=$((ERRORS+1)); }
# 2.8: the check after an update asks the site itself – a second server on the same files, since this one is busy installing
PROBE_PORT=$((PORT + 11)); (cd "$WORK/web" && exec php -S "127.0.0.1:$PROBE_PORT" system/dev-router.php > /dev/null 2>&1) & PROBE_PID=$!
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:$PROBE_PORT/" && break; sleep 0.2; done
SITE_URL_BEFORE=$(sq "SELECT hodnota FROM ka_nastaveni WHERE promenna = 'site_url'")
sq "INSERT INTO ka_nastaveni VALUES ('site_url','http://127.0.0.1:$PROBE_PORT') ON DUPLICATE KEY UPDATE hodnota = VALUES(hodnota)" > /dev/null
update_from rozbity.json
[ ! -f "$WORK/web/image/test-rozbita.txt" ] && ! grep -q "echo 'broken'" "$WORK/web/index.php" && [ "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.rolled_back'")" = 1 ] \
  && sq "SELECT message FROM ka_events WHERE type = 'update.rolled_back'" | grep -q '500' && echo "  ok     2.8: an update that breaks the site undoes itself (event update.rolled_back)" || { echo "  CHYBA  rozbitá aktualizace se nevrátila"; sq "SELECT message, data FROM ka_events WHERE type LIKE 'update.%'"; ERRORS=$((ERRORS+1)); }
sq "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'update_attempt'" > /dev/null
update_from ok.json
[ -f "$WORK/web/image/test-aktualizace.txt" ] && echo "  ok     podepsaná aktualizace se nainstaluje" || { echo "  CHYBA  aktualizace se nenainstalovala"; sq "SELECT message, data FROM ka_events WHERE type LIKE 'update.%'"; ERRORS=$((ERRORS+1)); }
grep -q "vlastni uprava spravce" "$WORK/web/.htaccess" && [ -f "$WORK/web/.htaccess.kaleta-nova" ] && echo "  ok     vlastní .htaccess zůstal, nová verze leží vedle" || { echo "  CHYBA  aktualizace přepsala vlastní .htaccess"; ERRORS=$((ERRORS+1)); }
expect "2.10.2: after an update the site knows it runs the newest version (no new check, no error)" "$(sq "SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(hodnota, '\$.manifest.verze')), '|', JSON_TYPE(JSON_EXTRACT(hodnota, '\$.chyba'))) FROM ka_nastaveni WHERE promenna = 'update_cache'")" "9.9.9|NULL"
expect "2.8: a working update is checked and recorded (update.applied)" "$(sq "SELECT COUNT(*) FROM ka_events WHERE type = 'update.applied'")" "1"
sq "UPDATE ka_nastaveni SET hodnota = '$SITE_URL_BEFORE' WHERE promenna = 'site_url'" > /dev/null; kill "$PROBE_PID" 2>/dev/null || true
"${MYSQL[@]}" "$DB_NAME" -e "UPDATE ka_nastaveni SET hodnota = '' WHERE promenna IN ('update_url', 'update_cache')"
kill "$CHANNEL_PID" 2>/dev/null || true

if [ -s "$WORK/web/storage/log/chyby.log" ]; then echo "== záznam chyb aplikace:"; cat "$WORK/web/storage/log/chyby.log"; ERRORS=$((ERRORS+1)); fi
echo; [ "$ERRORS" -eq 0 ] && echo "VŠE V POŘÁDKU" || { echo "NALEZENO CHYB: $ERRORS"; exit 1; }
