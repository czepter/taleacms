<?php
/**
 * Unit tests of the Kaleta core - no framework and no database: php tools/unit-tests.php
 *
 * They guard what the smoke test (tools/test.sh) cannot detect: cryptography, parsing and text conversions.
 * A new test = another call of over('popis', $skutecne, $ocekavane).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Assistant;
use Kaleta\Core\Search;
use Kaleta\Core\Migration;
use Kaleta\Core\Files;
use Kaleta\Core\Totp;
use Kaleta\Front\Seo;
use Kaleta\Front\NewsText;

$errors = 0;
$total = 0;
function check(string $label, mixed $actual, mixed $expected): void
{
    global $errors, $total;
    $total++;
    if ($actual === $expected) {
        return;
    }
    $errors++;
    echo "  CHYBA  {$label}\n         čekal jsem: " . var_export($expected, true) . "\n         dostal jsem: " . var_export($actual, true) . "\n";
}

/* ---------- text conversions ---------- */
check('slugify: diakritika a mezery', slugify('Příliš žluťoučký kůň!'), 'prilis-zlutoucky-kun');
check('slugify: prázdný vstup', slugify('***'), 'n-a');
check('slugify: délka', strlen(slugify(str_repeat('abc ', 100), 20)) <= 20, true);
check('bez_diakritiky', remove_diacritics('Ďábelské ÓDY – Straße'), 'Dabelske ODY – Strasse');
check('e(): uvozovky a značky', e('<a href="x">\'</a>'), '&lt;a href=&quot;x&quot;&gt;&#039;&lt;/a&gt;');
check('datum', format_date('2026-09-05 07:03:00', true), '5. 9. 2026 07:03');

/* ---------- search ---------- */
check('Hledani::normalizuj', Search::normalize('<p>Nábřeží&nbsp;<b>Vltavy</b></p><h2>Proměna!</h2>'), 'nabrezi vltavy promena');
check('Hledani::dotaz: krátká slova vypadnou', Search::query('co je na Nábřeží'), '+nabrezi*');
check('Hledani::dotaz: operátory fulltextu se neprosadí', Search::query('+tajne -verejne "fraze" (x) ~y*'), '+tajne* +verejne* +fraze*');
check('Hledani::dotaz: nejvýš 8 slov', substr_count(Search::query('aaa bbb ccc ddd eee fff ggg hhh iii jjj'), '+'), 8);

/* ---------- TOTP (RFC 6238, secret "12345678901234567890") ---------- */
$totpSeed = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check('TOTP: vektor T=59', Totp::code($totpSeed, intdiv(59, 30)), '287082');
check('TOTP: vektor T=1111111109', Totp::code($totpSeed, intdiv(1111111109, 30)), '081804');
check('TOTP: vektor T=2000000000', Totp::code($totpSeed, intdiv(2000000000, 30)), '279037');
check('TOTP: platný kód projde', Totp::verify($totpSeed, '287082', 59), true);
check('TOTP: sousední okno projde', Totp::verify($totpSeed, '287082', 59 + 30), true);
check('TOTP: starý kód neprojde', Totp::verify($totpSeed, '287082', 59 + 300), false);
check('TOTP: nesmysl neprojde', Totp::verify($totpSeed, 'abcdef', 59), false);
check('TOTP: nové tajemství má 160 bitů', strlen(Totp::newSecret()), 32);

/* ---------- migrations: splitting SQL into statements ---------- */
$sql = "-- komentář\nALTER TABLE ka_novinky ADD COLUMN x INT;   -- poznámka za příkazem\nCREATE TABLE ka_nova (\n  a VARCHAR(10) DEFAULT ';'\n);\nALTER TABLE ka_a ADD CONSTRAINT fk_a FOREIGN KEY (b) REFERENCES ka_b (id);\n";
$statements = Migration::statements($sql, 'web_');
check('Migrace::prikazy: počet', count($statements), 3);
check('Migrace::prikazy: předpona tabulek', str_contains($statements[1], 'CREATE TABLE web_nova'), true);
check('Migrace::prikazy: středník v hodnotě příkaz nerozdělí', str_contains($statements[1], "DEFAULT ';'"), true);
check('Migrace::prikazy: předpona omezení', str_contains($statements[2], 'CONSTRAINT web_fk_a') && str_contains($statements[2], 'REFERENCES web_b'), true);
check('Migrace: KALETA_VERZE_DB odpovídá souborům', KALETA_DB_VERSION, Migration::latest());

/* ---------- attachments ---------- */
check('Soubory: PDF je příloha', Files::isAttachment('Zpráva.PDF'), true);
check('Soubory: PHP není příloha', Files::isAttachment('shell.php'), false);
check('Soubory: dvojitá přípona', Files::isAttachment('shell.pdf.php'), false);
check('Soubory: SVG a HTML ne', Files::isAttachment('x.svg') || Files::isAttachment('x.html'), false);
check('Soubory: velikost', Files::size(1536), '2 kB');
check('Soubory: velikost v MB', Files::size(5 * 1048576), '5,0 MB');

/* ---------- player and embedded URLs ---------- */
check('prehravac: YouTube bez cookies', str_contains(NewsText::player('https://www.youtube.com/watch?v=dQw4w9WgXcQ', '', 'T'), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
check('prehravac: youtu.be', str_contains(NewsText::player('https://youtu.be/dQw4w9WgXcQ', '', 'T'), 'embed/dQw4w9WgXcQ'), true);
check('prehravac: MP3 je <audio>', str_contains(NewsText::player('media/2026/09/epizoda.mp3', '/magazin', 'T'), '<audio controls preload="none" src="/magazin/media/2026/09/epizoda.mp3">'), true);
check('prehravac: neznámá adresa v režimu jenZname', NewsText::player('https://example.com/video', '', 'T', true), '');
check('prehravac: titulek se escapuje', str_contains(NewsText::player('https://vimeo.com/123', '', '"><script>'), '<script>'), false);
$types = (new ReflectionClass(NewsText::class))->newInstanceWithoutConstructor();
$html = $types->embedVideoUrls('<p>Úvod</p><p>https://youtu.be/dQw4w9WgXcQ</p><p>Viz https://youtu.be/dQw4w9WgXcQ v textu.</p>');
check('vlozeneAdresy: jen samostatný řádek', [substr_count($html, 'data-vlozit'), substr_count($html, 'Viz https://youtu.be')], [1, 1]);

/* ---------- themeless (1.6) ---------- */
check('Themeless: the page frame is the system’s own, no layout folder and no layout setting', [is_file(KALETA_ROOT . '/system/views/front/base.php'), is_dir(KALETA_ROOT . '/layout'), isset(\Kaleta\Core\Settings::DEFAULTS['layout'])], [true, false, false]);

/* ---------- the English dictionary covers site and admin texts ---------- */
$missingTranslation = static function (string $dictionary, array $patterns): array {
    // a source text is Czech (then the English dictionary has it) or English since 1.4.1 (then the Czech one has it)
    $translations = (require dirname(__DIR__) . '/system/jazyky/' . $dictionary) + (require dirname(__DIR__) . '/system/jazyky/' . str_replace('en.php', 'cs.php', $dictionary));
    $missing = [];
    foreach ($patterns as $pattern) {
        foreach (glob(dirname(__DIR__) . '/' . $pattern) ?: [] as $file) {
            preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)+)'/u", (string) file_get_contents($file), $m);
            foreach ($m[1] as $k) {
                $k = stripslashes($k);
                if (!isset($translations[$k]) && preg_match('/[áčďéěíňóřšťúůýž]/iu', $k)) {
                    $missing[] = basename($file) . ': ' . $k;
                }
            }
        }
    }

    return array_values(array_unique($missing));
};
check('Slovník en.php: texty webu mají anglický překlad', $missingTranslation('en.php', ['system/views/front/*.php', 'system/src/Front/*.php', 'system/src/Builder/*.php', 'system/src/Builder/Elements/*.php']), []);
check('Slovník admin-en.php: E-mail webu', isset((require dirname(__DIR__) . '/system/jazyky/admin-en.php')['E-mail webu']), true);

check('Stavba::kod: vnořený skript se nesloží znovu', [str_contains(Kaleta\Builder\Build::code('<scr<script>x</script>ipt>alert(1)</scr<script>y</script>ipt>'), '<script'), Kaleta\Builder\Build::code('<iframe src="https://mapy.cz/x"></iframe>')], [false, '<iframe src="https://mapy.cz/x"></iframe>']);
check('Stavba::kod: obsluhy událostí a javascript: zmizí', Kaleta\Builder\Build::code('<a href="javascript:alert(1)" onclick="x()">A</a><iframe srcdoc="data:text/html,x"></iframe>'), '<a>A</a><iframe></iframe>');
// 2.5.1: a DOM filter instead of regular expressions – the bypasses of the old filter stay closed
$codeBypasses = ['<img/onerror=alert(1) src=x>', '<svg/onload=alert(1)>', '<a href=javascript:alert(1)>x</a>', '<a href="&#106;avascript:alert(1)">x</a>',
    '<a href="java&#x09;script:alert(1)">x</a>', '<a href="javascript:alert(\'1\')">x</a>', '<iframe srcdoc="&lt;svg/onload=alert(1)&gt;"></iframe>',
    '<object data="javascript:alert(1)"></object>', '<form action=javascript:alert(1)><button>x</button></form>', '<svg><animate attributeName=href values=javascript:alert(1) /></svg>',
    '<base href="https://evil.example/">', '<meta http-equiv=refresh content="0;url=https://evil.example">', '<div style="background:url(javascript:alert(1))">x</div>',
    '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>', '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'];
check('Stavba::kod: obejití starého filtru neprojde', array_filter($codeBypasses, fn (string $h): bool => (bool) preg_match('/\son[a-z]+=|javascript:|srcdoc|<base|<meta|<object|<animate|evil\.example/i', Kaleta\Builder\Build::code($h))), []);
check('Stavba::kod: mapa, formulář služby a zástupné hodnoty zůstanou', Kaleta\Builder\Build::code('<iframe src="https://www.google.com/maps/embed?pb=1" width="600" loading="lazy" allowfullscreen></iframe><form action="https://example.com/subscribe" method="post"><input type="email" name="EMAIL"></form><a href="{{odkaz}}">{{nazev}}</a>'),
    '<iframe src="https://www.google.com/maps/embed?pb=1" width="600" loading="lazy" allowfullscreen=""></iframe><form action="https://example.com/subscribe" method="post"><input type="email" name="EMAIL"></form><a href="{{odkaz}}">{{nazev}}</a>');
/* ---------- admin and site scripts without system dialogs (they cannot be styled or translated, and browsers suppress them) ---------- */
$nativeDialogs = [];
foreach (glob(dirname(__DIR__) . '/image/*.js') ?: [] as $file) {
    $code = preg_replace(['#/\*.*?\*/#s', '#(^|[^:])//.*$#m'], ['', '$1'], (string) file_get_contents($file)); // without comments
    if (preg_match_all('/\b(alert|prompt|confirm)\s*\(/', (string) $code, $m)) {
        $nativeDialogs[] = basename($file) . ': ' . implode(', ', array_unique($m[1]));
    }
}
check('Skripty bez window.alert/prompt/confirm', $nativeDialogs, []);

/* ---------- QR code (two-factor sign-in): own encoder without a library ---------- */
// Reed–Solomon: the known vector „HELLO WORLD“ version 1-M from the standard's tutorial (thonky.com, QR Code Tutorial)
check('Qr: opravné kódy Reed–Solomon (známý vektor)', Kaleta\Core\Qr::correctionCodes([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10), [196, 35, 39, 119, 235, 215, 231, 226, 93, 23]);
$qrRows = fn (array $m): array => array_map(fn (array $r): string => implode('', array_map(fn (bool $b): string => $b ? '#' : '.', $r)), $m);
$qr = $qrRows(Kaleta\Core\Qr::matrix('Kaleta', 2));
// format bits read from the matrix (column 8 and row 8 at the top left corner) = the standard's table for level M, mask 2: 101111001111100
$qrFormat = '';
foreach ([[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]] as [$y, $x]) {
    $qrFormat = ($qr[$y][$x] === '#' ? '1' : '0') . $qrFormat;
}
check('Qr: formátové bity M/maska 2 podle tabulky normy', $qrFormat, '101111001111100');
check('Qr: verze 1 = 21 × 21 s hledacím vzorem', [count($qr), $qr[0], $qr[6]], [21, '#######..##.#.#######', '#######.#.#.#.#######']);
// matrices verified by an independent reader (Chrome BarcodeDetector) – guards that the encoder does not break
$qrHash = fn (string $text): string => sha1(implode("\n", array_map(fn (array $r): string => implode('', array_map('intval', $r)), Kaleta\Core\Qr::matrix($text))));
check('Qr: adresa otpauth (verze 6) odpovídá ověřené matici', $qrHash('otpauth://totp/Acme%3Aadmin?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Acme&digits=6&period=30'), '21b4e92a0dd7dfcfe3d26161ea6baf9459312669');
check('Qr: verze 12 (verzní bity, víc bloků) odpovídá ověřené matici', $qrHash(str_repeat('Kaleta QR 0123456789 ', 12)), '12f9c4c80e2de66f316614d576e6dde5464482c8');
$qrSvg = Kaleta\Core\Qr::svg('otpauth://totp/x?secret=AB', 'QR <kód>');
check('Qr: SVG s popisem, bez skriptu', str_contains($qrSvg, 'role="img" aria-label="QR &lt;kód&gt;"') && !str_contains($qrSvg, '<script'), true);

/* ---------- image sizes: tall screenshots are measured by width, srcset carries the real widths ---------- */
check('Obrazky::pomer: fotka na šířku i na výšku podle delší strany', [Kaleta\Core\Images::ratio(4000, 3000, 2000), Kaleta\Core\Images::ratio(3000, 4000, 2000)], [0.5, 0.5]);
check('Obrazky::pomer: celostránkový snímek podle šířky, výška nejvýš trojnásobek', [Kaleta\Core\Images::ratio(1440, 5000, 2000), round(Kaleta\Core\Images::ratio(1440, 5000, 1200), 3), Kaleta\Core\Images::ratio(1000, 9000, 2000)], [1.0, 0.72, 6000 / 9000]);
$imageFolder = dirname(__DIR__) . '/media/' . date('Y/m');
@mkdir($imageFolder, 0775, true);
$imageTmp = tempnam(sys_get_temp_dir(), 'obr');
$imagePng = imagecreatetruecolor(1440, 5000);
imagepng($imagePng, $imageTmp);
$imageSaved = Kaleta\Core\Images::saveFile($imageTmp, 'celostrankovy-snimek.png');
$imageSrcset = Kaleta\Core\Images::srcset($imageSaved['obr_poloha'], '');
check('Obrazky: vysoký snímek si nechá šířku, srcset má skutečné šířky variant', [$imageSaved['obr_width'], $imageSaved['obr_height'], (bool) preg_match('/-nahled\.png 553w, .*-1200\.png 1037w, .*\.png 1440w$/', $imageSrcset)], [1440, 5000, true]);
Kaleta\Core\Images::delete($imageSaved['obr_poloha'], $imageSaved['nahl_poloha']);
@unlink($imageTmp);

check('Styl::zCss: barva rámečku (i pro stav hover)', Kaleta\Builder\Style::fromCss('border-color', '#F6F4EE'), ['barva_ramecku' => '#F6F4EE']);
check('Styl::zCss: zkratka background jen s barvou', Kaleta\Builder\Style::fromCss('background', '#EFECE5'), Kaleta\Builder\Style::fromCss('background-color', '#EFECE5'));
check('Styl::zCss: background s obrázkem zůstane mimo', Kaleta\Builder\Style::fromCss('background', 'url(a.png) no-repeat'), null);
$buildCheck = Kaleta\Builder\Check::builds(['v' => 1, 'deti' => [['id' => 's', 'typ' => 'sekce', 'deti' => [
    ['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Služby']],
    ['id' => 'b', 'typ' => 'nadpis', 'znacka' => 'h4', 'obsah' => ['text' => 'Detail']],
    ['id' => 'c', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Poptat', 'odkaz' => '#']],
    ['id' => 'd', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/a.jpg', 'alt' => '']],
    ['id' => 'e', 'typ' => 'obrazek', 'obsah' => ['src' => '{{foto}}', 'alt' => '']],
    ['id' => 'f', 'typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => '01']],
]]]], true);
check('Kontrola stavby: tlačítko bez odkazu, obrázek bez popisu, chybějící h1 a přeskočená úroveň', array_column($buildCheck, 'id'), ['c', 'd', 'a', 'b']);
check('Kontrola stavby: části webu osnovu nadpisů nehlídají', Kaleta\Builder\Check::builds(['v' => 1, 'deti' => [['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => 'Kontakt']]]], false), []);
check('Poptávka: kampaň z utm_* adresy stránky s formulářem', Kaleta\Front\Forms::campaign('https://example.com/akce?utm_source=google&utm_medium=cpc&utm_campaign=jaro&gclid=x&utm_term[]=a', 'https://example.com'), 'utm_source=google&utm_medium=cpc&utm_campaign=jaro');
check('Poptávka: kampaň jen z vlastního webu', Kaleta\Front\Forms::campaign('https://jiny.cz/?utm_source=x', 'https://example.com'), '');
check('Poptávka: kampaň pro člověka', Kaleta\Front\Forms::campaignText('utm_source=google&utm_medium=cpc&utm_campaign=jaro'), 'google / cpc / jaro');
// Parity guard: every admin action is a read, has an MCP tool, or is admin only on purpose (with the reason). A new action
// that is none of these fails the test – decide whether Claude can do it too (MCP is the main way to work with a site).
$readOnly = 'read';
$builderParity = [
    'build_ai_section' => 'admin: AI helper of the builder – Claude writes the content itself', 'build_ai_text' => 'admin: AI helper of the builder – Claude writes the content itself',
    'build_class' => 'save_classes', 'build_delete_section' => 'delete_section', 'build_discard' => 'discard_draft', 'build_publish' => 'publish_build',
    'build_restore' => 'restore_build_version', 'build_save' => 'save_build', 'build_save_section' => 'save_section', 'build_section' => 'insert_section',
    'build_share' => 'preview_link', 'build_versions' => $readOnly, 'builder' => $readOnly,
    'build_package' => $readOnly, 'build_paste' => 'admin: paste from the system clipboard of another Kaleta site – Claude inserts elements with save_build or edit_build and brings classes with save_classes',
];
$settingsParity = ['list' => $readOnly, 'save' => 'update_settings', 'download_backup' => $readOnly, 'backup' => 'admin: backups', 'restore_backup' => 'admin: backups',
    'delete_backup' => 'admin: backups', 'media_backup' => 'admin: backups', 'delete_log' => 'admin: error log', 'check' => 'admin: updates', 'update' => 'admin: updates',
    'test_mail' => 'admin: mail server settings', 'domain_check' => 'admin: the domain and mail watch runs on its own once a day', 'test_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
    'retry_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)', 'new_webhook_secret' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
    'firewall_unblock' => 'admin: the firewall is a security setting (2.8) – not over MCP',
    'hours_add' => 'save_hours_exception', 'hours_delete' => 'delete_hours_exception', 'hours_sign' => $readOnly,
    'fleet_pair' => 'admin: which console a site reports to is a security decision (2.9)', 'fleet_send' => 'admin: the site reports every hour on its own',
    'fleet_updates' => 'admin: who decides about updates is a security decision (2.9)', 'fleet_unpair' => 'admin: which console a site reports to is a security decision (2.9)',
    'report_preview' => $readOnly, 'report_send' => 'admin: the monthly report goes to e-mail addresses that stay out of MCP (2.9) – Claude reads the same numbers with get_stats and get_health'];
$parity = [
    'pages' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'export' => $readOnly, 'save' => 'update_page', 'save_text' => 'update_page',
        'delete' => 'trash_page', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
        'duplicate' => 'admin: a copy of a page – Claude creates the page and saves the build', 'import' => 'admin: upload of a page export file',
        'build_text' => 'admin: back from the builder to a text page', 'restore_version' => 'admin: text revisions of a text page'],
    'news' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'compare' => $readOnly, 'versions' => $readOnly, 'search_json' => $readOnly,
        'save' => 'update_news', 'save_text' => 'update_news', 'delete' => 'trash_news', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
        'draft' => 'admin: autosave of the editor', 'duplicate' => 'admin: a copy of a news item – Claude creates a new one', 'links' => 'admin: link check runs on its own',
        'assistant' => 'admin: AI helper – Claude writes the text itself', 'translate' => 'admin: AI translation – Claude translates and uses create_news'],
    'collections' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'preset' => 'create_collection', 'edit' => $readOnly, 'items' => $readOnly, 'item' => $readOnly,
        'save' => 'update_collection', 'delete' => 'delete_collection', 'save_item' => 'save_collection_item', 'delete_item' => 'delete_collection_item',
        'restore_item' => 'restore_from_trash', 'delete_item_permanently' => 'admin: the trash empties itself after 30 days', 'duplicate_item' => 'admin: a copy of an item – Claude saves a new one',
        'restore_item_version' => 'restore_item_version', 'signature' => 'get_email_signature'],
    'enquiries' => ['list' => $readOnly, 'detail' => $readOnly, 'csv' => $readOnly, 'attachment' => $readOnly, 'note' => 'update_enquiry', 'status' => 'update_enquiry',
        'bulk' => 'update_enquiry', 'delete' => 'delete_enquiry', 'settings' => 'admin: how long enquiries are kept'],
    'subscribers' => ['list' => $readOnly, 'csv' => $readOnly, 'delete' => 'admin: subscribers’ addresses stay out of MCP', 'sync' => 'admin: mailing service keys', 'retry' => 'admin: mailing service keys'],
    'newsletters' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'preview' => $readOnly, 'save' => 'draft_newsletter', 'test' => 'send_test_newsletter',
        'send' => 'send_newsletter', 'unschedule' => 'send_newsletter', 'delete' => 'delete_newsletter'],
    'categories' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'update_category', 'delete' => 'delete_category'],
    'tags' => ['list' => $readOnly, 'save' => 'admin: tags are set with news items (update_news)', 'delete' => 'admin: tags are set with news items (update_news)'],
    'media' => ['list' => $readOnly, 'listing' => $readOnly, 'upload' => 'upload_file', 'save' => 'update_media', 'save_caption' => 'update_media', 'bulk' => 'delete_media',
        'replace' => 'admin: a new file behind the same address', 'folder' => 'admin: media folders', 'folder_delete' => 'admin: media folders'],
    'appearance' => ['list' => $readOnly, 'preview' => $readOnly, 'tokens' => $readOnly, 'save' => 'update_design_system', 'tokens_import' => 'admin: upload of a design tokens file',
        'publish_look' => 'publish_look', 'discard_look' => 'discard_look', 'restore_look' => 'restore_look_version', 'preview_site' => 'preview_link'],
    'parts' => $builderParity + ['list' => $readOnly, 'variant' => $readOnly, 'save_variant' => 'save_part_variant', 'template' => 'save_part_variant',
        'templates' => $readOnly, 'apply_template' => 'apply_part_template'],
    'menu' => ['list' => $readOnly, 'save' => 'save_menu', 'automatic' => 'save_menu'],
    'components' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'save_component', 'delete' => 'delete_component', 'from_element' => 'save_component'],
    'popups' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'create' => 'save_popup', 'save' => 'save_popup', 'toggle' => 'save_popup',
        'delete' => 'delete_popup', 'reset' => 'admin: resetting the counters'],
    'users' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions', 'password_link' => 'admin: accounts and permissions',
        'reactivate' => 'admin: accounts and permissions', 'revoke_connection' => 'admin: accounts and permissions'],
    'roles' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions'],
    'stats' => ['list' => 'get_stats'], 'changelog' => ['list' => 'list_changes'], 'audit' => ['list' => 'site_audit'],
    'redirects' => ['list' => $readOnly, 'save' => 'save_redirect', 'delete' => 'save_redirect', 'clear' => 'admin: clearing the list of 404 addresses', 'ignore' => 'ignore_not_found', 'ignore_all' => 'ignore_not_found'],
    'transfer' => ['list' => $readOnly, 'preview' => $readOnly, 'download' => $readOnly, 'export' => $readOnly, 'upload' => 'admin: WordPress import', 'select' => 'admin: WordPress import',
        'run' => 'admin: WordPress import', 'progress' => 'admin: WordPress import', 'images' => 'admin: WordPress import', 'delete_file' => 'admin: WordPress import', 'delete_export' => 'admin: site export',
        'kaleta' => 'admin: moving a whole site into a new installation', 'kaleta_select' => 'admin: moving a whole site into a new installation',
        'kaleta_run' => 'admin: moving a whole site into a new installation', 'kaleta_delete' => 'admin: moving a whole site into a new installation',
        'web_start' => 'import_website', 'web_progress' => 'import_website', 'web_run' => 'import_website', 'web_delete' => 'admin: removing the record of an import',
        'report_start' => 'migration_report', 'report' => 'migration_report', 'report_delete' => 'admin: removing a saved report'],
    'settings' => $settingsParity, 'extensions' => $settingsParity,
    'facts' => ['list' => 'list_facts', 'edit' => $readOnly, 'save' => 'save_fact', 'delete' => 'delete_fact', 'claims' => 'find_claims'],
    'fleet' => ['list' => 'list_sites', 'detail' => 'get_site', 'pairing_key' => 'admin: pairing a site is a security decision (2.9)', 'ring' => 'admin: the update ring decides when sites install versions',
        'allow' => 'admin: allowing a version on the sites', 'check' => 'admin: the console checks the sites every 5 minutes on its own', 'remove' => 'admin: removing a site from the console'],
];
$missingParity = [];
foreach (Kaleta\Admin\Kernel::MODULES as $moduleClass) {
    foreach ((new ReflectionClass($moduleClass))->getMethods() as $method) {
        if (preg_match('/^action[A-Z]/', $method->name)) {
            $action = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method->name, 6)));
            $covered = $parity[$moduleClass::IDENT][$action] ?? null;
            if ($covered === null || ($covered !== $readOnly && !str_starts_with($covered, 'admin: ') && !in_array($covered, Kaleta\Mcp\Translator::names(), true))) {
                $missingParity[] = $moduleClass::IDENT . '.' . $action . ($covered !== null ? ' → unknown tool ' . $covered : '');
            }
        }
    }
}
check('Parity: every admin action is a read, an MCP tool or admin only on purpose', $missingParity, []);
// The builder vocabulary in English at the MCP boundary: every map one to one, nothing missing, every library section there and back
$vocabulary = Kaleta\Mcp\Vocabulary::class;
$notOneToOne = [];
foreach (['NODE', 'CONDITIONS', 'TYPES', 'CONTENT', 'ITEMS', 'STATES', 'STYLE', 'COLORS', 'TEXT_STYLES', 'FIELD_TYPES', 'STYLE_TYPES'] as $map) {
    if (count(array_unique(constant($vocabulary . '::' . $map))) !== count(constant($vocabulary . '::' . $map))) {
        $notOneToOne[] = $map;
    }
}
foreach ($vocabulary::VALUES + ['ITEM typ' => $vocabulary::ITEM_VALUES['typ']] as $key => $values) {
    if (count(array_unique($values)) !== count($values)) {
        $notOneToOne[] = 'VALUES ' . $key;
    }
}
$contentBack = $vocabulary::reverse('CONTENT');
foreach ($vocabulary::CONTENT_BY_TYPE as $keys) {
    foreach ($keys as $en) {
        if (isset($vocabulary::CONTENT[$contentBack[$en]]) && $vocabulary::CONTENT[$contentBack[$en]] === $en && !in_array($en, $keys, true)) {
            $notOneToOne[] = 'CONTENT_BY_TYPE ' . $en;
        }
        if (in_array($en, $vocabulary::CONTENT, true)) {
            $notOneToOne[] = 'CONTENT_BY_TYPE ' . $en . ' is also a general key';
        }
    }
}
check('Vocabulary: every map is one to one', $notOneToOne, []);
$vocabularyMissing = $vocabulary::missingTypes();
foreach (Kaleta\Builder\Build::ELEMENTS as $className) {
    foreach ($className::properties() as $key => $definition) {
        if (!isset($vocabulary::CONTENT[$key]) && !isset($vocabulary::CONTENT_BY_TYPE[$className::TYPE][$key])) {
            $vocabularyMissing[] = $className::TYPE . '.' . $key;
        }
        foreach (array_keys((array) ($definition['pole'] ?? [])) as $itemKey) {
            if (!isset($vocabulary::ITEMS[$itemKey])) {
                $vocabularyMissing[] = $className::TYPE . '.' . $key . '[' . $itemKey . ']';
            }
        }
        if (!isset($vocabulary::FIELD_TYPES[$definition['typ']])) {
            $vocabularyMissing[] = 'field type ' . $definition['typ'];
        }
    }
}
$vocabularyMissing = [...$vocabularyMissing, ...array_diff(array_keys(Kaleta\Builder\Style::PROPERTIES), array_keys($vocabulary::STYLE)),
    ...array_diff(array_keys(Kaleta\Builder\Style::STATUSES), array_keys($vocabulary::STATES)), ...array_diff(array_keys(Kaleta\Builder\DesignSystem::COLOR_TOKENS), array_keys($vocabulary::COLORS)),
    ...array_diff(array_keys(Kaleta\Builder\DesignSystem::TYPOGRAPHY), array_keys($vocabulary::TEXT_STYLES))];
check('Vocabulary: every element type, field, style property, state and token has an English name', array_values(array_unique($vocabularyMissing)), []);
$roundTrip = [];
foreach (Kaleta\Builder\Library::listAll() as $librarySection) {
    $element = Kaleta\Builder\Library::section($librarySection['klic'])['prvek'];
    $english = $vocabulary::elementToEnglish($element);
    if ($vocabulary::elementToCzech($english) !== $element || preg_match('/"(deti|obsah|styl|typ|zaklad|mobil)":/', (string) json_encode($english))) {
        $roundTrip[] = $librarySection['klic'];
    }
}
check('Vocabulary: every library section goes to English (no Czech keys left) and back unchanged', $roundTrip, []);
check('Vocabulary: the Czech form is still accepted on input', $vocabulary::elementToCzech(['typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['mobil' => ['barva' => 'primarni']]]),
    ['typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['mobil' => ['barva' => 'primarni']]]);
check('Vocabulary: English in, Czech stored', $vocabulary::elementToCzech(['type' => 'button', 'content' => ['text' => 'Go', 'variant' => 'outline', 'icon' => 'arrow'], 'style' => ['hover' => ['background' => 'primary-soft', 'radius' => 'full']]]),
    ['typ' => 'tlacitko', 'obsah' => ['text' => 'Go', 'varianta' => 'obrys', 'ikona' => 'sipka'], 'styl' => ['hover' => ['pozadi' => 'primarni-jemna', 'zaobleni' => 'plne']]]);
// Ready-made templates of site parts: each builds without errors, wrappers keep exactly one page content element, headers carry the logo
$templateProblems = [];
foreach (Kaleta\Builder\PartTemplates::LIST as $partType => $partTemplates) {
    foreach (array_keys($partTemplates) as $templateKey) {
        $templateBuild = Kaleta\Builder\PartTemplates::build($partType, $templateKey, 'en', ['newsletter']);
        [, $templateErrors] = Kaleta\Builder\Build::sanitize($templateBuild);
        $json = (string) json_encode($templateBuild);
        $contentCount = preg_match_all('/"typ":"obsah"/', $json);
        if ($templateErrors !== [] || (in_array($partType, ['novinka', 'vypis', 'nenalezeno'], true) ? $contentCount !== 1 : $contentCount !== 0)
            || ($partType === 'hlavicka' && !str_contains($json, '"typ":"logo"'))) {
            $templateProblems[] = $partType . ':' . $templateKey;
        }
    }
}
check('Part templates: valid builds, one page content in wrappers, a logo in headers', $templateProblems, []);
check('Part templates: the newsletter sign-up only with the Newsletter extension', [str_contains((string) json_encode(Kaleta\Builder\PartTemplates::build('paticka', 'sloupce', 'en', [])), '"typ":"newsletter"'),
    array_column(Kaleta\Builder\PartTemplates::forType('vypis', []), 'klic')], [false, ['jednoduchy']]);
// 2.1: every MCP tool once in Mcp\Catalog – its English definition, its parameter types and its method agree with it
$catalogTools = array_keys(Kaleta\Mcp\Catalog::TOOLS);
$toolMethods = array_values(array_filter(array_map(fn (ReflectionMethod $m): string => $m->name, (new ReflectionClass(Kaleta\Mcp\Tools::class))->getMethods()), fn (string $m): bool => preg_match('/^tool[A-Z]/', $m) === 1));
check('2.1: MCP catalog, English definitions, parameter types and methods agree', [
    array_values(array_diff($catalogTools, Kaleta\Mcp\Translator::names())), array_values(array_diff(Kaleta\Mcp\Translator::names(), $catalogTools)),
    array_values(array_filter(array_column(Kaleta\Mcp\Tools::definitions(), 'name'), fn (string $n): bool => Kaleta\Mcp\Catalog::english($n) === null)),
    array_values(array_diff(array_map([Kaleta\Mcp\Catalog::class, 'method'], $catalogTools), $toolMethods)), array_values(array_diff($toolMethods, array_map([Kaleta\Mcp\Catalog::class, 'method'], $catalogTools))),
    count(Kaleta\Mcp\Tools::definitions()), Kaleta\Mcp\Tools::annotations('smaz_stranku'), Kaleta\Mcp\Tools::isWriteTool('site_audit'), Kaleta\Mcp\Catalog::extension('seznam_novinek'),
], [[], [], [], [], [], count($catalogTools), ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false], false, 'novinky']);
// 2.2: what a connection may do – full everything, drafts only reads and drafts, read only reads; never an unknown level
check('2.2: connection access', [Kaleta\Mcp\Catalog::allows('full', 'publish_look'), Kaleta\Mcp\Catalog::allows('drafts', 'save_build'), Kaleta\Mcp\Catalog::allows('drafts', 'create_page'),
    Kaleta\Mcp\Catalog::allows('drafts', 'publish_build'), Kaleta\Mcp\Catalog::allows('drafts', 'update_settings'), Kaleta\Mcp\Catalog::allows('drafts', 'trash_page'),
    Kaleta\Mcp\Catalog::allows('read', 'get_build'), Kaleta\Mcp\Catalog::allows('read', 'save_build'), Kaleta\Mcp\Catalog::allows('read', 'stavba_uloz'), Kaleta\Mcp\Catalog::allows('whatever', 'save_build'),
    Kaleta\Front\OAuth::access('drafts'), Kaleta\Front\OAuth::access('admin'), Kaleta\Mcp\Tools::annotations('save_build')],
    [true, true, true, false, false, false, true, false, false, false, 'drafts', 'read', ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false]]);
check('2.2: every drafts-only tool is a write that does not remove anything', array_values(array_filter(array_keys(Kaleta\Mcp\Catalog::TOOLS),
    fn (string $t): bool => Kaleta\Mcp\Catalog::access($t) === 'draft' && (Kaleta\Mcp\Tools::annotations($t)['readOnlyHint'] || Kaleta\Mcp\Tools::annotations($t)['destructiveHint']))), []);
check('2.2: MCP protocol version negotiated', [Kaleta\Mcp\Server::protocol('2025-03-26'), Kaleta\Mcp\Server::protocol('2099-01-01'), Kaleta\Mcp\Server::protocol(null)],
    ['2025-03-26', '2025-06-18', '2025-06-18']);
$promptText = Kaleta\Mcp\Prompts::get('build_page', ['topic' => 'kitchens', 'audience' => 'families'])['messages'][0]['content']['text'];
$promptError = '';
try {
    Kaleta\Mcp\Prompts::get('translate_page', ['page_id' => '3']);
} catch (InvalidArgumentException $e) {
    $promptError = $e->getMessage();
}
check('2.2: MCP prompts and resources', [str_starts_with($promptText, 'Build a new page about kitchens for families.'), str_contains(Kaleta\Mcp\Prompts::get('build_page', ['topic' => 'x'])['messages'][0]['content']['text'], 'about x. First'),
    $promptError, array_column(Kaleta\Mcp\Prompts::listAll(), 'name'), array_column(Kaleta\Mcp\Prompts::resources(), 'uri')],
    [true, true, 'The prompt translate_page needs the argument language.', ['build_page', 'audit_and_fix', 'translate_page', 'write_news', 'migrate_site', 'weekly_review'], ['kaleta://instructions', 'kaleta://overview']]);
// 2.8: when a job is due, and when an update counts as broken (only with a clear sign – never just because the site cannot reach itself)
check('2.8: Scheduler::isDue', [Kaleta\Core\Scheduler::isDue(null, 300, 1000), Kaleta\Core\Scheduler::isDue(900, 0, 1000), Kaleta\Core\Scheduler::isDue(800, 300, 1000),
    Kaleta\Core\Scheduler::isDue(700, 300, 1000), Kaleta\Core\Scheduler::isDue(1000 - 86400 + 60, 86400, 1000)], [true, true, false, true, false]);
// 2.9: the monthly report – the previous month across the year boundary, the agency or the site in the header, no address from the
// data gets through, the month name in the site's language
use Kaleta\Core\MonthlyReport;
check('2.9: MonthlyReport::previousMonth – January goes to December of the previous year', [MonthlyReport::previousMonth(new DateTimeImmutable('2027-01-15 10:00:00'))->format('Y-m-d H:i'),
    MonthlyReport::previousMonth(new DateTimeImmutable('2026-03-31 23:59:59'))->format('Y-m-d')], ['2026-12-01 00:00', '2026-02-01']);
$reportSettings = static function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values + ['site_name' => 'Testovací firma', 'site_language' => 'en']);

    return $s;
};
$reportData = ['month' => '2026-09',
    'stats' => ['visits' => 120, 'views' => 300, 'previous_visits' => 100, 'previous_views' => 0, 'pages' => [['path' => '/sluzby', 'n' => 80]], 'sources' => [['site' => 'google.com', 'n' => 40]], 'campaigns' => []],
    'enquiries' => ['total' => 3, 'forms' => [['form' => 'Kontakt jan.novak@visitor.example', 'n' => 3]], 'pages' => [['path' => '/kontakt', 'n' => 3]], 'unanswered' => 2], 'signups' => 1,
    'updates' => [['type' => 'update.applied', 'date' => '2026-09-10 10:00:00', 'message' => 'Version 2.8.0 was installed (from 2.7.0).']],
    'backups' => ['created' => 20, 'failed' => 0, 'last' => '2026-09-30 03:00:00'], 'changes' => ['people' => 12, 'claude' => 7],
    'problems' => [['group' => 'Operation', 'name' => 'Cron', 'state' => 'varovani', 'info' => 'not set up – ask admin@visitor.example']], 'decisions' => ['enquiries' => 2, 'errors' => 0]];
$withAgency = MonthlyReport::render($reportData, $reportSettings(['agency_name' => 'Studio Kaleta', 'agency_email' => 'help@studio.example', 'agency_logo' => 'media/logo.svg']), 'https://example.com/');
$withoutAgency = MonthlyReport::render($reportData, $reportSettings([]), 'https://example.com');
check('2.9: the report carries the agency when it is set, otherwise the site', [str_contains($withAgency['html'], 'Studio Kaleta'), str_contains($withAgency['html'], 'https://example.com/media/logo.svg'), str_contains($withAgency['text'], 'help@studio.example'),
    str_contains($withoutAgency['html'], 'Studio Kaleta'), str_contains($withoutAgency['html'], 'Testovací firma'), str_contains($withoutAgency['html'], '120 (+20 %)')], [true, true, true, false, true, true]);
check('2.9: no e-mail address from the data gets into the report', [str_contains($withAgency['html'] . $withAgency['text'] . $withAgency['subject'], 'visitor.example'), str_contains($withoutAgency['html'], '/sluzby'), str_contains($withoutAgency['text'], 'Kontakt ')], [false, true, true]);
check('2.9: the subject names the month in the site language', [$withoutAgency['subject'], MonthlyReport::render($reportData, $reportSettings(['site_language' => 'cs']), 'https://example.com')['subject'],
    MonthlyReport::render(['month' => '2027-01'], $reportSettings(['site_language' => 'de']), 'https://example.com')['subject']],
    ['Website report – September 2026 – Testovací firma', 'Zpráva o webu – září 2026 – Testovací firma', 'Website-Bericht – Januar 2027 – Testovací firma']);
$ok = [200, 'KALETA-PROBE 9.9.9'];
check('2.8: Updater::probeVerdict', [
    Kaleta\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [0, ''], 'home' => [0, ''], 'admin' => [0, '']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null,
    Kaleta\Core\Updater::probeVerdict(['probe' => [200, 'KALETA-PROBE 2.7.0'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [500, ''], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9') !== null,
    Kaleta\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [301, ''], 'admin' => [302, '']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [403, 'Access denied.'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [200, 'KALETA-PROBE 2.7.0'], 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null],
    [null, null, true, null, true, null, null, true]);
// 2.8: the firewall – networks, the address behind Cloudflare only from Cloudflare, countries, the manual list
use Kaleta\Core\Firewall;
check('2.8: Firewall::inList', [Firewall::inList('198.51.100.77', ['198.51.100.0/24']), Firewall::inList('198.51.101.1', ['198.51.100.0/24']), Firewall::inList('203.0.113.7', ['203.0.113.7']),
    Firewall::inList('10.1.2.3', ['10.0.0.0/9']), Firewall::inList('10.200.0.1', ['10.0.0.0/9']), Firewall::inList('2001:db8::1', ['2001:db8::/32']), Firewall::inList('2001:db9::1', ['2001:db8::/32']),
    Firewall::inList('not-an-ip', ['0.0.0.0/8']), Firewall::inList('198.51.100.7', ['2001:db8::/32'])], [true, false, true, true, false, true, false, false, false]);
check('2.8: Firewall::parseList and isValidEntry', [Firewall::parseList("203.0.113.7 # bot\n\n198.51.100.0/24\nnonsense\n10.0.0.0/4\n2001:db8::/32"), Firewall::isValidEntry('300.1.1.1')],
    [[['203.0.113.7', '198.51.100.0/24', '2001:db8::/32'], ['nonsense', '10.0.0.0/4']], false]);
check('2.8: Firewall::visitorIp – Cloudflare only from its addresses', [
    Firewall::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], 'cloudflare'),
    Firewall::visitorIp(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'], 'cloudflare'),
    Firewall::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], ''),
    Firewall::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'junk'], 'cloudflare')],
    ['203.0.113.9', '203.0.113.50', '172.70.1.2', '172.70.1.2']);
check('2.8: Firewall::country and countries', [Firewall::country(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_IPCOUNTRY' => 'ru'], 'cloudflare'), Firewall::country(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_IPCOUNTRY' => 'RU'], 'cloudflare'),
    Firewall::country(['GEOIP_COUNTRY_CODE' => 'CN'], ''), Firewall::country(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_IPCOUNTRY' => 'XX'], 'cloudflare'), Firewall::countries("ru, CN;\nde x"),
    Firewall::countryBlocked('RU', 'RU, CN'), Firewall::countryBlocked('', 'RU')], ['RU', '', 'CN', '', ['RU', 'CN', 'DE'], true, false]);
check('2.8: Firewall::PROBE_PATHS – probes count, missing images and old WordPress uploads never', array_map(fn (string $p): bool => preg_match(Firewall::PROBE_PATHS, $p) === 1,
    ['wp-login.php', 'xmlrpc.php', '.env', 'app/.git/config', 'phpmyadmin/index.php', 'backup.sql', 'wp-content/uploads/2020/05/foto.jpg', 'image/logo.png', 'robots.txt', 'sitemap.xml', 'o-nas', '.well-known/security.txt']),
    [true, true, true, true, true, true, false, false, false, false, false, false]);
check('2.8: Firewall::isLocal', array_map(Firewall::isLocal(...), ['127.0.0.1', '10.0.0.5', '192.168.1.1', '::1', '203.0.113.7', '2a00:1450::1']), [true, true, true, true, false, false]);
// 2.7: a reveal and a motion while scrolling run together; a hover effect gets its own rule, its motion only without reduced motion
$motion = Kaleta\Builder\Style::css('#a', ['zaklad' => ['animace' => 'ka-zleva', 'pohyb' => 'ka-paralaxa', 'najeti' => 'zvednout']]);
check('2.7: scroll motion and hover effect in the CSS', [str_contains($motion, 'animation: ka-zleva linear both, ka-paralaxa linear both; animation-timeline: view(), view(); animation-range: entry 0% cover 28%, cover 0% cover 100%'),
    str_contains($motion, '@media (prefers-reduced-motion: no-preference) { #a { transition:'), str_contains($motion, '#a:is(:hover, :focus-visible) { translate: 0 -4px;'),
    Kaleta\Builder\Style::css('#b', ['zaklad' => ['animace' => 'none', 'pohyb' => 'ka-nesmysl']])],
    [true, true, true, '']);
// 2.7: the migration report reads what the old page had, counts forms and images of a build, and old form entries are checked
$oldPage = '<html><head><title>Services | Acme</title><meta name="description" content="What we do"></head><body><header><form role="search"><input type="search" name="s"></form></header>'
    . '<main><h1>Services</h1><p>' . str_repeat('We build kitchens and bathrooms. ', 5) . '</p><img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg"><form action="/contact"><input name="email"><textarea name="m"></textarea></form></main></body></html>';
$searchOnly = '<html><body><main><p>' . str_repeat('Text of the page. ', 8) . '</p></main><form class="search-form"><input name="s"></form></body></html>';
check('2.7: MigrationReport::analyse', [Kaleta\Core\MigrationReport::analyse($oldPage, 'https://old.example/services/'), Kaleta\Core\MigrationReport::analyse($searchOnly, 'https://old.example/')['formular']],
    [['titulek' => 'Services | Acme', 'popis' => 'What we do', 'formular' => true, 'obrazky' => 3], false]);
check('2.7: MigrationReport::countElements', Kaleta\Core\MigrationReport::countElements([
    ['typ' => 'sekce', 'deti' => [['typ' => 'obrazek'], ['typ' => 'galerie', 'obsah' => ['fotky' => [['src' => 'a'], ['src' => 'b']]]], ['typ' => 'kontejner', 'deti' => [['typ' => 'formular']]]]],
    ['typ' => 'text', 'obsah' => ['html' => '<p><img src="x"></p>']]]), [1, 4]);
check('2.7: import_enquiries checks each entry', [
    Kaleta\Mcp\Tools::enquiryEntry(['date' => '2025-03-14 09:30', 'form' => 'Contact', 'page' => '/contact', 'fields' => ['Name' => 'Jana', 'E-mail' => 'jana@example.cz', 'Message' => '<b>Hi</b>', 'Empty' => '']]),
    Kaleta\Mcp\Tools::enquiryEntry(['date' => 'yesterday-ish?', 'fields' => ['a' => 'b']]), Kaleta\Mcp\Tools::enquiryEntry(['date' => '2025-01-01', 'fields' => []]),
    Kaleta\Mcp\Tools::enquiryEntry(['date' => '2025-01-01 10:00', 'email' => 'not-an-email', 'fields' => [['label' => 'Phone', 'value' => '777 123 456']]])['email']],
    [['datum' => '2025-03-14 09:30:00', 'formular' => 'Contact', 'stranka' => '/contact', 'email' => 'jana@example.cz', 'data' => [['Name', 'Jana'], ['E-mail', 'jana@example.cz'], ['Message', 'Hi']]],
    'date must be a date and time, e.g. 2025-03-14 09:30', 'fields are empty', '']);
// 2.2: data migrations run by name – the list in Core\Migration is the PHP files
check('2.2: Migration::DATA lists every PHP data migration', Kaleta\Core\Migration::DATA, array_values(array_map(fn (string $f): string => basename($f, '.php'),
    array_filter(Kaleta\Core\Migration::files(), fn (string $f): bool => str_ends_with($f, '.php')))));
// 2.3: the Embed element takes only known services and builds their frame address; image/web.js allows the same
$embed = fn (string $u): ?string => Kaleta\Builder\Elements\Embed::resolve($u)[1] ?? null;
check('2.3: Embed – known services only', [$embed('https://calendly.com/acme/consultation'), $embed('https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?usp=sf_link'),
    $embed('https://tally.so/r/w7ZyYq'), $embed('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC?si=x'), $embed('https://soundcloud.com/artist/track-name'),
    $embed('https://evil.example/calendly.com/x'), $embed('javascript:alert(1)'), $embed('https://calendly.com/a/b"onload=x')],
    ['https://calendly.com/acme/consultation?embed_type=Inline&hide_gdpr_banner=1', 'https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?embedded=true',
    'https://tally.so/embed/w7ZyYq?alignLeft=1&transparentBackground=1', 'https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC',
    'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fartist%2Ftrack-name', null, null, null]);
preg_match('/if \(!(\/\^https:.*?\/)\.test\(address\)\)/', (string) file_get_contents(KALETA_ROOT . '/image/web.js'), $webJsAllow);
$jsPattern = '#' . str_replace('\\/', '/', substr($webJsAllow[1] ?? '//', 1, -1)) . '#';
check('2.3: every Embed frame address is allowed in image/web.js', array_keys(array_filter(Kaleta\Builder\Elements\Embed::SERVICES,
    fn (array $s): bool => preg_match($jsPattern, sprintf($s[3], 'x')) !== 1)), []);
// 2.3.1: in a language version, a path that already names its language keeps it (a menu link /cs/funkce, not /cs/cs/funkce)
$urlApp = new Kaleta\Core\App([]);
$urlApp->languagePrefix = 'cs';
check('2.3.1: App::url does not double the language prefix', [$urlApp->url('cs/funkce'), $urlApp->url('/cs/funkce'), $urlApp->url('funkce'), $urlApp->url('cs'), $urlApp->url('image/x.svg'), $urlApp->url('css-tricks')],
    array_map(fn (string $p): string => $urlApp->request->basePath() . $p, ['/cs/funkce', '/cs/funkce', '/cs/funkce', '/cs', '/image/x.svg', '/cs/css-tricks']));
// 2.3.1: every field a settings tab posts is read by the save – a setting, the "remove" box of a secret setting, or one of the
// few the save reads on purpose (a field under an old name was silently dropped: saving General removed the language versions)
$settingsFields = [];
foreach ((new ReflectionClass(Kaleta\Admin\Modules\Settings::class))->getReflectionConstant('FIELDS')->getValue() as $tabFields) {
    foreach ($tabFields as $key => $type) {
        $settingsFields[$key] = true;
        if (str_starts_with($type, 'tajne')) {
            $settingsFields[$key . '_smazat'] = true;
        }
    }
}
$unknownFields = [];
foreach (glob(KALETA_SYSTEM . '/views/admin/settings/*.php') as $view) {
    preg_match_all('/name="([a-z_]+)(?:\[\])?"/', (string) file_get_contents($view), $viewNames);
    foreach (array_unique($viewNames[1]) as $name) {
        if (!isset($settingsFields[$name]) && !in_array($name, ['rozsireni', 'ai_poskytovatel_puvodni', 'novy_token_ulohy', 'novy_token', 'soubor', 'tab', 'id', 'ip', 'pairing_key', 'fleet_updates',
            'exception', 'exception_from', 'exception_to', 'exception_closed', 'exception_hours', 'exception_note', 'exception_notice', 'viewport', 'robots'], true)) { // viewport, robots: <meta> of the door sign
            $unknownFields[] = basename($view) . ': ' . $name;
        }
    }
}
check('2.3.1: settings forms post only fields the save reads', $unknownFields, []);
// 2.1: public contracts – MCP tools and parameters, design tokens and builder elements are never removed or changed
// outside the deprecation policy; an addition is recorded with php tools/contracts.php --update
require_once __DIR__ . '/contracts.php';
check('2.1: public contracts kept (tools/contracts)', kaleta_contract_diff(), ['broken' => [], 'added' => []]);
// MCP in English: every tool has an English name, every fixed message a translation, parameters and results are converted
$mcpSource = (string) file_get_contents(KALETA_ROOT . '/system/src/Mcp/Tools.php');
preg_match_all("/^\s+\['([a-z_]+)', '/m", substr($mcpSource, 0, (int) strpos($mcpSource, 'public function call(')), $mcpTools);
check('MCP: každý nástroj má anglický název', array_values(array_diff($mcpTools[1], Kaleta\Mcp\Translator::czechTools())), []);
preg_match_all("/Exception\('((?:[^'\\\\]|\\\\.)*)'\)/", $mcpSource . file_get_contents(KALETA_ROOT . '/system/src/Builder/Edits.php'), $mcpMessages);
check('MCP: pevná hlášení mají anglický překlad', array_values(array_filter(array_map('stripslashes', $mcpMessages[1]), fn (string $z): bool => Kaleta\Mcp\Translator::message($z) === $z && preg_match('/[ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚ]/u', $z) === 1)), []); // tools added in English (newsletters) need none
check('MCP anglicky: parametry, hodnoty a položky menu', Kaleta\Mcp\Translator::arguments('save_menu', ['location' => 'footer', 'items' => [['type' => 'page', 'page_id' => 2, 'children' => [['type' => 'link', 'url' => '/x', 'new_window' => true]]]]]),
    ['umisteni' => 'paticka', 'polozky' => [['typ' => 'stranka', 'ids' => 2, 'deti' => [['typ' => 'odkaz', 'url' => '/x', 'nove_okno' => true]]]]]);
check('MCP anglicky: typy polí kolekce a nastavení', [Kaleta\Mcp\Translator::arguments('create_collection', ['fields' => [['label' => 'Foto', 'type' => 'image']]]), Kaleta\Mcp\Translator::arguments('update_settings', ['settings' => ['site_name_de' => 'X', 'company_email' => 'a@b.c', 'nazev_webu' => 'Y']])],
    [['pole' => [['popisek' => 'Foto', 'typ' => 'obrazek']]], ['nastaveni' => ['site_name_de' => 'X', 'company_email' => 'a@b.c', 'nazev_webu' => 'Y']]]);
check('MCP anglicky: výsledek s anglickými klíči, stavba v anglickém slovníku', Kaleta\Mcp\Translator::result('save_build', ['id' => 3, 'stav' => 'publikováno', 'stavba' => ['v' => 1, 'deti' => [['typ' => 'nadpis', 'stav' => 'x']]], 'kontrola' => [['id' => 'a', 'zprava' => 'z']]]),
    ['id' => 3, 'status' => 'published', 'build' => ['v' => 1, 'children' => [['type' => 'heading', 'stav' => 'x']]], 'check' => [['id' => 'a', 'message' => 'z']]]);
$mcpList = [['name' => 'save_collection_item', 'inputSchema' => ['properties' => ['data' => ['type' => 'object'], 'name' => ['type' => 'string'], 'fields' => ['type' => 'array']]]]];
check('MCP: objekt a pole poslané jako text JSON se rozbalí podle schématu, text zůstane textem', Kaleta\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{"a":"b"}', 'name' => '{"x":1}', 'fields' => '[1,2]']),
    ['data' => ['a' => 'b'], 'name' => '{"x":1}', 'fields' => [1, 2]]);
check('MCP: neplatný JSON nebo pole místo objektu se nerozbalí', Kaleta\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{nic', 'fields' => '{"a":1}']), ['data' => '{nic', 'fields' => '{"a":1}']);
check('MCP: logická hodnota poslaná jako text („false“ nezveřejní skrytou stránku)', Kaleta\Mcp\Server::extractJson([['name' => 'create_page', 'inputSchema' => ['properties' => ['visible' => ['type' => 'boolean'], 'title' => ['type' => 'string']]]]], 'create_page', ['visible' => 'false', 'title' => 'false']), ['visible' => false, 'title' => 'false']);
check('MCP: logická hodnota „true“ a „1“ jako text', array_values(array_map(fn (string $h): mixed => Kaleta\Mcp\Server::extractJson([['name' => 't', 'inputSchema' => ['properties' => ['v' => ['type' => 'boolean']]]]], 't', ['v' => $h])['v'], ['true', '1', '0', 'ano'])), [true, true, false, 'ano']);
check('MCP: text JSON u parametru typu [array, null] se rozbalí', Kaleta\Mcp\Server::extractJson([['name' => 'save_menu', 'inputSchema' => ['properties' => ['items' => ['type' => ['array', 'null']]]]]], 'save_menu', ['items' => '[{"type":"page"}]']), ['items' => [['type' => 'page']]]);
check('MCP: neznámé parametry se vyjmenují', Kaleta\Mcp\Server::unknownParams($mcpList, 'save_collection_item', ['data' => '{}', 'classes' => [], 'name' => 'x']), ['classes']);
// popups: server rules (places, language, period) and English MCP parameters
$popupWhere = fn (array $x): array => $x + ['ids' => null, 'kolekce' => null, 'novinky' => false, 'jazyk' => 'cs', 'dnes' => '2026-09-25'];
$popupSelected = Kaleta\Builder\Popups::sanitizeRules(['kde' => 'vybrane', 'stranky' => ['4', 'x', 4], 'kolekce' => ['tym', 'Ne platna'], 'novinky' => 1, 'od' => '2026-02-30', 'utm' => 'jaro<b>']);
check('Pop-up: vyčištěná pravidla', [$popupSelected['stranky'], $popupSelected['kolekce'], $popupSelected['novinky'], $popupSelected['od'], $popupSelected['utm'], $popupSelected['zarizeni']], [[4], ['tym'], true, '', 'jarob', 'vse']);
check('Pop-up: vybraná místa – stránka, kolekce, novinky, jinde ne', [
    Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['ids' => 4])), Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['kolekce' => 'tym'])),
    Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['novinky' => true])), Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['ids' => 5])),
], [true, true, true, false]);
$popupPeriod = Kaleta\Builder\Popups::sanitizeRules(['od' => '2026-10-01', 'do' => '2026-10-31', 'jazyk' => 'en']);
check('Pop-up: období a jazyk platí i pro celý web', [
    Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'en'])), Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'en', 'dnes' => '2026-10-15'])),
    Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'cs', 'dnes' => '2026-10-15'])), Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'en', 'dnes' => '2026-11-01'])),
], [false, true, false, false]);
$navCss = Kaleta\Builder\Elements\Navigation::baseCss();
check('Kolekce: hodnota v Vlastním HTML je escapovaná, formátovaný text vyčištěný', [
    Kaleta\Builder\Collections::fill('<div title="{{nazev}}">{{nazev}}</div>', 'kod', ['nazev' => ['<img src=x onerror=alert(1)>"', 'text']]),
    Kaleta\Builder\Collections::fill('<div>{{telo}}</div>', 'kod', ['telo' => ['<p>Ahoj</p><img src=x onerror=alert(1)>', 'html']]),
], ['<div title="&lt;img src=x onerror=alert(1)&gt;&quot;">&lt;img src=x onerror=alert(1)&gt;&quot;</div>', '<div><p>Ahoj</p><img src="x"></div>']);
check('Navigace: menu na telefonu se dá posouvat (dlouhé menu se skupinami)', (bool) preg_match('/@media \\(max-width: 767px\\).*?\\.ka-nav-menu\\[popover\\] \\{[^}]*max-height:[^}]*overflow-y: auto/s', $navCss), true);
check('MCP anglicky: pop-up okno – hodnoty a pravidla', Kaleta\Mcp\Translator::arguments('save_popup', ['type' => 'slide_in', 'trigger' => 'exit', 'frequency' => 'until_closed', 'template' => 'lead_magnet',
    'rules' => ['where' => 'selected', 'pages' => [2], 'device' => 'phone', 'campaign' => 'jaro']]),
    ['typ' => 'panel', 'spoustec' => 'odchod', 'cetnost' => 'zavreni', 'vzor' => 'magnet', 'pravidla' => ['kde' => 'vybrane', 'stranky' => [2], 'zarizeni' => 'telefon', 'utm' => 'jaro']]);
check('MCP anglicky: výsledek pop-up okna', Kaleta\Mcp\Translator::result('save_popup', ['id' => 3, 'nazev' => 'X', 'adresa' => 'x', 'typ' => 'lista-dole', 'spoustec' => 'stranky', 'cetnost' => 'dni',
    'pravidla' => ['kde' => 'vse', 'zarizeni' => 'pocitac', 'od' => ''], 'aktivni' => true, 'zobrazeni' => 5]),
    ['id' => 3, 'name' => 'X', 'slug' => 'x', 'type' => 'bottom_bar', 'trigger' => 'pages', 'frequency' => 'days', 'rules' => ['where' => 'all', 'device' => 'desktop', 'from' => ''], 'active' => true, 'views' => 5]);
check('Pop-up: každý vzor z knihovny se sestaví', array_map(fn (string $k): bool => count(Kaleta\Builder\Popups::libraryBuild($k, 'en')['deti']) === 1, array_keys(Kaleta\Builder\Popups::LIBRARY)), array_fill(0, count(Kaleta\Builder\Popups::LIBRARY), true));
check('MCP anglicky: hlášení s proměnnou částí', Kaleta\Mcp\Translator::message('Kategorie „Akce“ neexistuje. Použij nástroj seznam_kategorii.'), 'The category “Akce” does not exist. Use list_categories.');
use Kaleta\Core\Routes;
check('Cesty: systémové adresy v jazyce verze', [Routes::publicPath('novinky/kategorie/akce', 'en', null), Routes::publicPath('novinky/stitek/x', 'de', null), Routes::publicPath('hledani?q=a', 'fr', null),
    Routes::publicPath('novinky/kategorie/akce', 'cs', null), Routes::publicPath('novinky-akce', 'en', null), Routes::publicPath('novinky', 'en', null)],
    ['news/category/akce', 'news/tag/x', 'search?q=a', 'novinky/kategorie/akce', 'novinky-akce', 'news']);
check('Cesty: požadavek na vnitřní cestu a kanonickou podobu', [Routes::internalPath('/news/tag/x', 'en', null), Routes::internalPath('/novinky/x', 'en', null), Routes::internalPath('/news', 'cs', null), Routes::internalPath('/o-nas', 'en', null)],
    [['/novinky/stitek/x', '/news/tag/x'], ['/novinky/x', '/news/x'], ['/novinky', '/novinky'], ['/o-nas', '/o-nas']]);
// dictionaries of other site languages: only keys of the English dictionary (and English day and month names for dates in words), the same %s and tags
$enDictionary = require KALETA_ROOT . '/system/jazyky/en.php';
// English source texts (1.4.1+) are keys too: their "English translation" is the text itself
$enDictionary += array_combine(array_keys(require KALETA_ROOT . '/system/jazyky/cs.php'), array_keys(require KALETA_ROOT . '/system/jazyky/cs.php'));
$dataNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$brokenDictionaries = [];
foreach (glob(KALETA_ROOT . '/system/jazyky/[a-z][a-z].php') ?: [] as $file) {
    $code = basename($file, '.php');
    if ($code === 'en') {
        continue;
    }
    $dictionary = require $file;
    if (!isset(Kaleta\Core\Language::AVAILABLE[$code])) {
        $brokenDictionaries[] = $code . ': jazyk není v Jazyk::DOSTUPNE';
    }
    foreach ($dictionary as $key => $translation) {
        if (!isset($enDictionary[$key]) && !in_array($key, $dataNames, true) && $key !== 'datum_format') {
            $brokenDictionaries[] = $code . ': navíc „' . $key . '“';
        } elseif (isset($enDictionary[$key]) && (preg_match_all('/%(?:\d+\$)?[sd]/', $enDictionary[$key]) !== preg_match_all('/%(?:\d+\$)?[sd]/', $translation) || substr_count($enDictionary[$key], '<') !== substr_count($translation, '<'))) {
            $brokenDictionaries[] = $code . ': zástupné znaky nebo značky v „' . $key . '“';
        }
    }
}
check('Slovníky jazyků webu: klíče z en.php, stejné %s a HTML', $brokenDictionaries, []);

/* ---------- version comparison ---------- */
$r = Kaleta\Core\Diff::html('<p>Radnice schválila plán.</p><p>Druhý odstavec.</p>', '<p>Radnice včera schválila nový plán.</p><p>Druhý odstavec.</p><p>Třetí.</p>');
check('Rozdil: slova ve změněném odstavci', str_contains($r['html'], '<ins>včera </ins>') && str_contains($r['html'], '<ins>nový </ins>'), true);
check('Rozdil: nezměněný odstavec bez značek', str_contains($r['html'], '<p>Druhý odstavec.</p>'), true);
check('Rozdil: nový odstavec', str_contains($r['html'], '<p><ins>Třetí.</ins></p>'), true);
check('Rozdil: HTML ve vstupu se escapuje', str_contains(Kaleta\Core\Diff::html('', '<p>a &lt;script&gt; b</p>')['html'], '<script>'), false);
check('Rozdil: shodné texty', Kaleta\Core\Diff::html('<p>Stejné</p>', '<p>Stejné</p>')['pridano'], 0);

/* ---------- FAQ ---------- */
check('Seo::faq', Seo::faq("Kdy to začne?\nV pondělí.\n\nKolik to stojí?\nNic."), [['Kdy to začne?', 'V pondělí.'], ['Kolik to stojí?', 'Nic.']]);
check('Seo::faq: prázdný vstup', Seo::faq(null), []);

/* ---------- backups to S3: AWS Signature V4 signing (the value verified by an independent computation) ---------- */
check('404: sondy robotů se nezapisují, skutečné adresy ano', array_map(Kaleta\Core\NotFound::isBot(...), ['wp/v2/users', 'sellers.json', 'api/session/properties', '_next', 'api/novinky/x', 'about-us', 'en', 'cenik-2019']),
    [true, true, true, true, true, false, false, false]);
// 1.9: structured data of collection item pages – only mapped fields, an offer needs a price and a currency
$sdFields = [['klic' => 'cena', 'popisek' => 'Cena', 'typ' => 'text'], ['klic' => 'druh', 'popisek' => 'Druh', 'typ' => 'text'], ['klic' => 'zacatek', 'popisek' => 'Začátek', 'typ' => 'datum']];
check('Strukturovaná data kolekce: neznámé pole a typ se zahodí', [Kaleta\Builder\CollectionSchema::sanitize(['typ' => 'Service', 'pole' => ['price' => 'cena', 'serviceType' => 'neni', 'hack' => 'druh'], 'mena' => 'eur'], $sdFields),
    Kaleta\Builder\CollectionSchema::sanitize(['typ' => 'Recipe'], $sdFields)], [['typ' => 'Service', 'pole' => ['price' => 'cena'], 'mena' => 'EUR'], null]);
$sdCollection = ['pole' => $sdFields, 'schema_org' => '{"typ":"Service","pole":{"price":"cena","serviceType":"druh"},"mena":"EUR"}'];
check('Strukturovaná data kolekce: služba s nabídkou', Kaleta\Builder\CollectionSchema::forItem($sdCollection, ['nazev' => 'Revize', 'data' => ['cena' => '1 200,50', 'druh' => '<b>Elektro</b>']], 'https://x.test/sluzby/revize', 'Popis', '', 'https://x.test/#firma'),
    ['@type' => 'Service', 'name' => 'Revize', 'url' => 'https://x.test/sluzby/revize', 'description' => 'Popis', 'serviceType' => 'Elektro', 'provider' => ['@id' => 'https://x.test/#firma'],
        'offers' => ['@type' => 'Offer', 'price' => '1200.50', 'priceCurrency' => 'EUR', 'url' => 'https://x.test/sluzby/revize']]);
check('Strukturovaná data kolekce: událost bez začátku ne, bez typu nic', [Kaleta\Builder\CollectionSchema::forItem(['pole' => $sdFields, 'schema_org' => '{"typ":"Event","pole":{"startDate":"zacatek"}}'], ['nazev' => 'A', 'data' => []], 'u', '', '', 'i'),
    Kaleta\Builder\CollectionSchema::forItem(['pole' => $sdFields], ['nazev' => 'A', 'data' => []], 'u', '', '', 'i')], [null, null]);
/* ---------- 2.10: e-mail signatures from people records ---------- */
$signatureClass = Kaleta\Builder\EmailSignature::class;
$peopleFields = [['klic' => 'fotka', 'popisek' => 'Fotka', 'typ' => 'obrazek'], ['klic' => 'role', 'popisek' => 'Role', 'typ' => 'text'], ['klic' => 'jazyky', 'popisek' => 'Jazyky', 'typ' => 'text'],
    ['klic' => 'telefon', 'popisek' => 'Telefon', 'typ' => 'text'], ['klic' => 'e_mail', 'popisek' => 'E-mail', 'typ' => 'text'], ['klic' => 'nepritomnost', 'popisek' => 'Nepřítomnost', 'typ' => 'text'], ['klic' => 'o_mne', 'popisek' => 'O mně', 'typ' => 'html']];
$peopleCollection = ['idk' => 5, 'nazev' => 'Tým', 'seo_link' => 'tym', 'detail' => 1, 'pole' => $peopleFields, 'schema_org' => '{"typ":"Person","pole":{},"mena":""}'];
check('2.10: EmailSignature::fields – the Czech preset by the keys and labels', $signatureClass::fields($peopleCollection), ['photo' => 'fotka', 'role' => 'role', 'phone' => 'telefon', 'email' => 'e_mail']);
$germanCollection = ['pole' => [['klic' => 'portrait', 'popisek' => 'Porträt', 'typ' => 'obrazek'], ['klic' => 'funktion', 'popisek' => 'Funktion', 'typ' => 'text'], ['klic' => 'handy', 'popisek' => 'Handy', 'typ' => 'text'],
    ['klic' => 'e_mail_adresse', 'popisek' => 'E-Mail-Adresse', 'typ' => 'text'], ['klic' => 'abwesend', 'popisek' => 'Abwesend', 'typ' => 'text']]];
check('2.10: EmailSignature::fields – a user-made collection in another language, a label with diacritics', [$signatureClass::fields($germanCollection),
    $signatureClass::fields(['pole' => [['klic' => 'pole_1', 'popisek' => 'Telefonní číslo', 'typ' => 'text'], ['klic' => 'pole_2', 'popisek' => 'Pozice ve firmě', 'typ' => 'text'], ['klic' => 'pole_3', 'popisek' => 'Mobil', 'typ' => 'cislo']]])],
    [['photo' => 'portrait', 'role' => 'funktion', 'phone' => 'handy', 'email' => 'e_mail_adresse'], ['photo' => null, 'role' => 'pole_2', 'phone' => 'pole_1', 'email' => null]]);
check('2.10: EmailSignature::fields – the schema.org Person mapping wins over the names', $signatureClass::fields(['pole' => [['klic' => 'kontakt', 'popisek' => 'Kontakt', 'typ' => 'text'], ['klic' => 'cim_jsem', 'popisek' => 'Čím jsem', 'typ' => 'text'],
    ['klic' => 'telefon', 'popisek' => 'Telefon', 'typ' => 'text'], ['klic' => 'role', 'popisek' => 'Role v týmu', 'typ' => 'text']], 'schema_org' => '{"typ":"Person","pole":{"jobTitle":"cim_jsem","email":"kontakt"}}']),
    ['photo' => null, 'role' => 'cim_jsem', 'phone' => 'telefon', 'email' => 'kontakt']);
check('2.10: EmailSignature::isPeople – Person, a photo with a contact; references and a bare phone are not people', [$signatureClass::isPeople($peopleCollection), $signatureClass::isPeople($germanCollection),
    $signatureClass::isPeople(['pole' => [['klic' => 'logo', 'popisek' => 'Logo', 'typ' => 'obrazek'], ['klic' => 'citat', 'popisek' => 'Citát', 'typ' => 'radky']]]),
    $signatureClass::isPeople(['pole' => [['klic' => 'telefon', 'popisek' => 'Telefon', 'typ' => 'text']]])], [true, true, false, false]);
$signatureSite = ['name' => 'Firma & spol.', 'url' => 'https://example.com/', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => 'Dlouhá 1, 110 00 Praha', 'color' => '#0f766e',
    'text_font' => 'Georgia, serif', 'heading_font' => '"Helvetica Neue", Arial, sans-serif', 'logo' => 'https://example.com/media/logo.png'];
$signaturePerson = ['nazev' => 'Jana <Nová>', 'data' => ['fotka' => 'media/2026/10/jana.jpg', 'role' => 'Obchodní ředitelka', 'jazyky' => 'CZ, EN', 'telefon' => '+420 777 123 456', 'e_mail' => 'jana@example.com',
    'nepritomnost' => 'Dovolená do pátku', 'o_mne' => '<p>Deset let v oboru.</p>']];
$signature = $signatureClass::render($peopleCollection, $signaturePerson, $signatureSite);
check('2.10: the signature is one table with inline styles only, at most 600 px wide', [preg_match('/<style|<script|class=|\son[a-z]+=/i', $signature['html']), str_starts_with($signature['html'], '<table role="presentation"'), str_contains($signature['html'], 'max-width:600px')], [0, true, true]);
check('2.10: the signature has the escaped name, the role, the phone with a tel: link, the brand colour and font, absolute photo and logo', [str_contains($signature['html'], 'Jana &lt;Nová&gt;'), str_contains($signature['html'], 'Obchodní ředitelka'),
    str_contains($signature['html'], 'href="tel:+420777123456"'), str_contains($signature['html'], 'href="mailto:jana@example.com"'), str_contains($signature['html'], 'src="https://example.com/media/2026/10/jana.jpg" width="72" height="72"'),
    str_contains($signature['html'], 'src="https://example.com/media/logo.png"'), str_contains($signature['html'], 'border-left:3px solid #0f766e'), str_contains($signature['html'], 'Georgia, serif'), str_contains($signature['html'], 'Firma &amp; spol.')],
    [true, true, true, true, true, true, true, true, true]);
check('2.10: the signature never carries the absence, the about text or the languages', [str_contains($signature['html'] . $signature['text'], 'Dovolen'), str_contains($signature['html'] . $signature['text'], 'Deset let'), str_contains($signature['html'] . $signature['text'], 'CZ, EN')], [false, false, false]);
check('2.10: the plain-text version', $signature['text'], "Jana <Nová>\nObchodní ředitelka\n+420 777 123 456 · jana@example.com\nFirma & spol. · example.com\nDlouhá 1, 110 00 Praha");
$bareSignature = $signatureClass::render(['pole' => $peopleFields], ['nazev' => 'Petr', 'data' => ['e_mail' => 'not an address', 'fotka' => '']],
    ['name' => 'Web', 'url' => '', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => '', 'color' => 'red', 'text_font' => '', 'heading_font' => '', 'logo' => '']);
check('2.10: a person without a photo and with an invalid e-mail: no image, the company phone, a safe colour and font', [str_contains($bareSignature['html'], '<img'), str_contains($bareSignature['html'], 'not an address'),
    str_contains($bareSignature['html'], 'border-left:3px solid #121212'), str_contains($bareSignature['html'], 'system-ui'), $bareSignature['text']], [false, false, true, true, "Petr\n+420 222 000 111\nWeb"]);
check('Položky jako stránky: SEO pole a plán zveřejnění', [Kaleta\Builder\Collections::pageFields(['obrazek' => 'javascript:alert(1)', 'noindex' => '1', 'zverejnit_od' => '2099-01-01T08:00', 'popis' => str_repeat('a', 400)], false),
    Kaleta\Builder\Collections::pageFields(['zverejnit_od' => '2001-01-01 08:00'], false)['zobrazit']],
    [['seo_titulek' => '', 'popis' => str_repeat('a', 300), 'obrazek' => '', 'noindex' => 1, 'zverejnit_od' => '2099-01-01 08:00:00', 'zobrazit' => 0], 1]);
$privacy = Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Builder\Library::privacyPolicyText());
check('Zásady ochrany údajů: šablona s upozorněním, bez nastavení jen poptávky', [str_contains($privacy, 'not legal advice'), str_contains($privacy, 'enquiry form'), str_contains($privacy, 'newsletter'),
    str_contains(Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Builder\Library::privacyPolicyText()), 'nikoli právní rada')], [true, true, false, true]);
// import of a Kaleta export (1.8): which files from the archive may go into media/, which names are exports
check('Import Kalety: soubory do media/', array_map(Kaleta\Core\SiteImport::mediaTarget(...), ['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', 'media/x.php', 'media/../config.php',
    'media/.htaccess', 'obsah.json', 'media/2026/09/dokument.pdf', 'media/a/b.phtml', 'media/2026/09/logo.svg']),
    ['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', null, null, null, null, 'media/2026/09/dokument.pdf', null, 'media/2026/09/logo.svg']);
check('Import Kalety: názvy souborů', array_map(Kaleta\Core\SiteImport::isValidName(...), ['export-20260928-101010.zip', 'web.json', 'stav-0123456789abcdef.json',
    'kaleta-stav-0123456789abcdef.json', '../web.zip', 'web.xml', '.web.zip']), [true, true, false, false, false, false, false]);
// webhook signature (1.8): HMAC-SHA256 of "timestamp.body" – the receiver recomputes it with the shared secret
check('Webhook: podpis HMAC časové značky a těla', Kaleta\Core\Webhook::signature('whsec_test', '{"udalost":"test"}', 1789900000),
    ['1789900000', 'sha256=' . hash_hmac('sha256', '1789900000.{"udalost":"test"}', 'whsec_test')]);
check('Webhook: jiné tělo = jiný podpis', Kaleta\Core\Webhook::signature('whsec_test', '{"udalost":"test2"}', 1789900000)[1] !== Kaleta\Core\Webhook::signature('whsec_test', '{"udalost":"test"}', 1789900000)[1], true);
$h = Kaleta\Core\RemoteBackup::signS3('PUT', 's3.eu-central-1.amazonaws.com', '/muj-bucket/kaleta-zaloha.sql.gz', hash('sha256', 'obsah'), 'eu-central-1', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 1789900000);
check('S3: rozsah a podepsané hlavičky', str_contains($h['Authorization'], 'Credential=AKIDEXAMPLE/20260920/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='), true);
check('S3: podpis má 64 šestnáctkových znaků', (bool) preg_match('/Signature=[0-9a-f]{64}$/', $h['Authorization']), true);

/* ---------- link check: only public URLs (protection against probing the internal network) ---------- */
check('Odkazy: výběr odkazů z HTML', Kaleta\Core\Links::links('<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="#kotva">k</a> <a class="x" href="/clanek/muj">c</a> <a href="https://example.com/a?x=1&amp;y=2">znovu</a></p>'), ['https://example.com/a?x=1&y=2', '/clanek/muj']);
foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.5/admin', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'ftp://example.com/', 'https://example.com:8443/', 'file:///etc/passwd', 'gopher://x/'] as $internal) {
    check('Odkazy: nekontroluje se ' . $internal, Kaleta\Core\Links::isPublic($internal), false);
}
check('Odkazy: veřejná adresa se kontroluje', Kaleta\Core\Links::isPublic('https://93.184.216.34/stranka'), true);

/* ---------- assistant: article translation (HTML skeleton from the original, texts from the model) ---------- */
$articleHtml = '<h2>Nadpis oddílu</h2><p>První <strong>tučný</strong> a <a href="/x?a=1&amp;b=2">odkaz</a>.</p><figure><img src="a.jpg" alt="x"><figcaption>Popisek fotky</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>';
$r = Kaleta\Core\Assistant::decompose($articleHtml);
check('Asistent::rozloz: úseky k překladu', $r['useky'], ['Nadpis oddílu', 'První [[0]]tučný[[1]] a [[2]]odkaz[[3]].', 'Popisek fotky']);
check('Asistent::sloz: beze změny textu vrátí původní HTML', Kaleta\Core\Assistant::compose($r['kostra'], $r['useky']), $articleHtml);
check('Asistent::sloz: HTML od modelu se vypíše jako text', str_contains(Kaleta\Core\Assistant::compose($r['kostra'], ['<script>alert(1)</script>', 'x', '<img src=x onerror=alert(1)>']), '<script>alert(1)') || str_contains(Kaleta\Core\Assistant::compose($r['kostra'], ['a', 'b', '<img src=x onerror=alert(1)>']), '<img src=x'), false);
check('Asistent::sloz: chybějící symbol = úsek bez formátování', Kaleta\Core\Assistant::compose($r['kostra'], ['N', 'First [[0]]bold[[1]] and link.', 'P']), '<h2>N</h2><p>First bold and link.</p><figure><img src="a.jpg" alt="x"><figcaption>P</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>');
check('Asistent::sloz: špatně vnořené symboly = úsek bez formátování', str_contains(Kaleta\Core\Assistant::compose($r['kostra'], ['N', '[[1]]bold[[0]] [[2]]link[[3]]', 'P']), '<strong>'), false);
check('Asistent::sloz: přeházené pořadí slov formátování zachová', str_contains(Kaleta\Core\Assistant::compose($r['kostra'], ['N', 'A [[2]]link[[3]] and [[0]]bold[[1]] first.', 'P']), '<p>A <a href="/x?a=1&amp;b=2">link</a> and <strong>bold</strong> first.</p>'), true);

$settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($settings, ['site_name' => 'Test', 'ai_key' => 'x']);
$fake = new class($settings) extends Kaleta\Core\Assistant {
    public int $calls = 0;

    protected function call(array $body): array
    {
        $this->calls++;
        preg_match('#<useky>\n(.*)\n</useky>#s', $body['messages'][0]['content'], $m);

        return ['content' => [['type' => 'text', 'text' => json_encode(['preklady' => array_map(mb_strtoupper(...), json_decode($m[1], true))], JSON_UNESCAPED_UNICODE)]]];
    }
};
$translated = $fake->translate(['titulek' => 'Tom & Jerry „znovu“ ve městě, tentokrát úplně jinak než kdy dřív', 'text' => '<p>Krátký <em>text</em> článku, který má aspoň pár desítek znaků.</p>', 'seo_popis' => ''], 'en', ['titulek', 'seo_popis']);
check('Asistent::preloz: prostý text se neescapuje dvakrát', $translated['titulek'], 'TOM & JERRY „ZNOVU“ VE MĚSTĚ, TENTOKRÁT ÚPLNĚ JINAK NEŽ KDY DŘÍV');
check('Asistent::preloz: HTML pole drží kostru', $translated['text'], '<p>KRÁTKÝ <em>TEXT</em> ČLÁNKU, KTERÝ MÁ ASPOŇ PÁR DESÍTEK ZNAKŮ.</p>');
check('Asistent::preloz: prázdné pole zůstane prázdné', $translated['seo_popis'], '');
$fake->calls = 0;
$long = $fake->translate(['text' => str_repeat('<p>' . str_repeat('Věta o něčem. ', 100) . '</p>', 9)], 'en');
check('Asistent::preloz: dlouhý článek jde po dávkách', [$fake->calls > 1, substr_count($long['text'], '<p>')], [true, 9]);
try {
    $fake->translate(['text' => '<p>nic</p>'], 'xx');
    check('Asistent::preloz: neznámý jazyk odmítne', 'prošlo', 'výjimka');
} catch (RuntimeException) {
    check('Asistent::preloz: neznámý jazyk odmítne', 'výjimka', 'výjimka');
}

/* ---------- temporary language switch (e-mails in the recipient's language) ---------- */
Kaleta\Core\Language::set('cs');
check('Jazyk::docasne: uvnitř platí cizí jazyk', Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Language::code() . '|' . t('Číst článek →')), 'en|Read article →');
check('Jazyk::docasne: potom se jazyk vrátí', Kaleta\Core\Language::code() . '|' . t('Číst článek →'), 'cs|Číst článek →');
try {
    Kaleta\Core\Language::runWith('en', function (): never { throw new RuntimeException('x'); });
} catch (RuntimeException) {
}
check('Jazyk::docasne: jazyk se vrátí i po výjimce', Kaleta\Core\Language::code(), 'cs');

/* ---------- dominant image color ---------- */
if (function_exists('imagecreatetruecolor')) {
    $temporary = tempnam(sys_get_temp_dir(), 'rs') . '.png';
    $canvas = imagecreatetruecolor(40, 20);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 30, 60));
    imagepng($canvas, $temporary);
    check('Obrazky::barva: jednobarevný obrázek', Kaleta\Core\Images::color($temporary), '#c81e3c');
    unlink($temporary);
    check('Obrazky::barva: chybějící soubor', Kaleta\Core\Images::color($temporary), null);
}

/* ---------- scripts: must not look for an element (data attribute) that is never created – that is how the Media dialog broke ---------- */
$whereCreated = [
    'image/editor.js' => ['system/views/admin'], 'image/admin.js' => ['system/views/admin', 'system/src/Admin'], 'image/pomocnik.js' => ['system/views/admin'],
    'image/web.js' => ['system/views/front', 'system/src/Front', 'system/src/Builder/Elements'],
    'image/vitals.js' => ['system/src/Front'],
    'image/tisk.js' => ['system/views/admin/settings'],
];
foreach ($whereCreated as $script => $folders) {
    $source = (string) file_get_contents(KALETA_ROOT . '/' . $script);
    preg_match_all('/querySelector(?:All)?\(\'\[(data-[a-z0-9-]+)\]\'\)/', $source, $links);
    $withoutSearch = (string) preg_replace('/(querySelector(All)?|closest|matches)\([^)]*\)/', '', $source);
    $missing = [];
    foreach (array_unique($links[1]) as $attribute) {
        $inTemplates = false;
        foreach ($folders as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_ROOT . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
                $inTemplates = $inTemplates || str_contains((string) file_get_contents($file->getPathname()), $attribute);
            }
        }
        if (!$inTemplates && !preg_match('/[\s"\']' . preg_quote($attribute, '/') . '[\s>="\']/', $withoutSearch) && !str_contains($withoutSearch, "setAttribute('" . $attribute . "'")) {
            $missing[] = $attribute;
        }
    }
    check($script . ': každý hledaný data-atribut někde vzniká', $missing, []);
}

/* ---------- the admin has a Content-Security-Policy without 'unsafe-inline': no inline scripts or event handlers ---------- */
$inline = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_ROOT . '/system/views/admin', FilesystemIterator::SKIP_DOTS)) as $file) {
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit|load|error|key\w+|mouse\w+)="#i', $source)) {
        $inline[] = substr($file->getPathname(), strlen(KALETA_ROOT) + 1);
    }
}
check('šablony administrace neobsahují inline skripty (CSP)', $inline, []);

/* ---------- publisher signatures: several keys, key rotation and revocation ---------- */
if (function_exists('sodium_crypto_sign_keypair')) {
    $pair = fn (): array => (fn (string $p): array => [sodium_crypto_sign_secretkey($p), sodium_crypto_sign_publickey($p)])(sodium_crypto_sign_keypair());
    [[$skPrimary, $pkPrimary], [$skBackup, $pkBackup], [$skNew, $pkNew], [$skForeign]] = [$pair(), $pair(), $pair(), $pair()];
    $sign = fn (string $message, string $sk): string => base64_encode(sodium_crypto_sign_detached($message, $sk));
    $pub = tempnam(sys_get_temp_dir(), 'rs');
    file_put_contents($pub, "# poznámka\n" . base64_encode($pkPrimary) . " provozni\n\nnesmysl-ktery-neni-klic\n" . base64_encode($pkBackup) . " zalozni 2026-09-20\n");
    $message = Kaleta\Core\Signature::packageMessage('3.0.1', str_repeat('A', 64), false);
    check('Podpis::klice: dva platné klíče, poznámky a nesmysly se přeskočí', count(Kaleta\Core\Signature::keys($pub)), 2);
    check('Podpis: provozní klíč platí', Kaleta\Core\Signature::isValid($message, $sign($message, $skPrimary), $pub), true);
    check('Podpis: záložní klíč platí také', Kaleta\Core\Signature::isValid($message, $sign($message, $skBackup), $pub), true);
    check('Podpis: cizí klíč neplatí', Kaleta\Core\Signature::isValid($message, $sign($message, $skForeign), $pub), false);
    check('Podpis: poškozený podpis neplatí', Kaleta\Core\Signature::isValid($message, 'AAAA', $pub), false);
    check('Podpis: běžné vydání nejde prohlásit za bezpečnostní', Kaleta\Core\Signature::isValid(Kaleta\Core\Signature::packageMessage('3.0.1', str_repeat('A', 64), true), $sign($message, $skPrimary), $pub), false);
    check('Podpis: otisk balíčku se porovnává bez ohledu na velikost písmen', Kaleta\Core\Signature::packageMessage('3.0.1', 'ABC', false), '3.0.1|abc|bezne');
    // a leak of the primary key: a release signed with the backup key brings a file without it and with a new primary key
    file_put_contents($pub, base64_encode($pkNew) . " provozni\n" . base64_encode($pkBackup) . " zalozni\n");
    check('výměna klíče: odvolaný klíč už neplatí', Kaleta\Core\Signature::isValid($message, $sign($message, $skPrimary), $pub), false);
    check('výměna klíče: nový provozní klíč platí', Kaleta\Core\Signature::isValid($message, $sign($message, $skNew), $pub), true);
    file_put_contents($pub, '');
    check('Podpis: bez klíčů neplatí nic', Kaleta\Core\Signature::isValid($message, $sign($message, $skNew), $pub), false);
    unlink($pub);
}
// Kaleta's publisher keys are created only before the first release (docs/RELEASING.md); until then the file may have no key, but it must be readable.
check('system/aktualizace.pub jde přečíst', is_array(Kaleta\Core\Signature::keys(KALETA_ROOT . '/system/aktualizace.pub')), true);

/* ---------- installer: every text has a translation in all languages ---------- */
$keys = [];
foreach (['system/views/install/form.php', 'system/views/install/done.php', 'system/src/Install/Installer.php'] as $file) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(KALETA_ROOT . '/' . $file), $found);
    foreach ($found[1] as $text) {
        $keys[stripslashes($text)] = true;
    }
}
// the installer prints the extension and sample site cards via t() from constants
foreach ([...array_values(Kaleta\Core\Extensions::CATALOG), ...array_values(Kaleta\Builder\Library::SITES)] as $card) {
    $keys[$card['nazev'] ?? $card[0]] = true;
    $keys[$card['popis'] ?? $card[1]] = true;
}
foreach (['en', 'de'] as $code) {
    // English: Czech keys to English on top of the English source texts; German (2.5): every source text translated
    $dictionary = (require KALETA_ROOT . '/system/jazyky/install-' . $code . '.php') + ($code === 'en' ? require KALETA_ROOT . '/system/jazyky/install-cs.php' : []);
    // international words are not translated (the dictionary tool does not write identical entries)
    $missing = array_values(array_diff(array_keys($keys), array_keys($dictionary), ['Server', 'Port', 'E-mail', 'Newsletter']));
    check('instalátor: úplný slovník ' . $code, $missing, []);
}

/* ---------- 2.6: import from a website ---------- */
check('2.6 WebImport::sitemap: pages and nested sitemaps', [
    Kaleta\Core\WebImport::sitemap('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.cz/</loc></url><url><loc> https://a.cz/o-nas </loc></url></urlset>'),
    Kaleta\Core\WebImport::sitemap('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://a.cz/page-sitemap.xml</loc></sitemap></sitemapindex>'),
    Kaleta\Core\WebImport::sitemap('<html>not a sitemap'), Kaleta\Core\WebImport::sitemap('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>'),
], [[['https://a.cz/', 'https://a.cz/o-nas'], []], [[], ['https://a.cz/page-sitemap.xml']], [[], []], [[], []]]); // an external entity is never loaded
check('2.6 WebImport: addresses – normalized, absolute, links without mail and scripts', [
    Kaleta\Core\WebImport::normalize('HTTPS://A.cz/o-nas/index.html?utm_source=x&id=5#top'), Kaleta\Core\WebImport::normalize('ftp://a.cz/x'),
    Kaleta\Core\WebImport::absolute('b/c', 'https://a.cz/dir/page'), Kaleta\Core\WebImport::absolute('//cdn.a.cz/x.jpg', 'https://a.cz/'),
    Kaleta\Core\WebImport::links('<a href="/x">1</a><a href="mailto:a@a.cz">2</a><a href="javascript:alert(1)">3</a><a href="#top">4</a>', 'https://a.cz/'),
], ['https://a.cz/o-nas/?id=5', '', 'https://a.cz/dir/b/c', 'https://cdn.a.cz/x.jpg', ['https://a.cz/x']]);
$webPage = Kaleta\Core\WebImport::extract('<html><head><title>About us | Acme</title><meta name="description" content="Who we are"></head><body>'
    . '<header><nav><a href="/">Home</a></nav></header><main><h1>About us</h1><p>We build oak furniture since 1990, for homes and offices across the region. ' . str_repeat('More text. ', 10) . '</p>'
    . '<img data-src="/img/team.jpg" src="data:image/gif;base64,x" alt="Our team"><p><a href="https://www.a.cz/contact/">Contact</a> <a href="https://other.cz/">Partner</a></p>'
    . '<div class="cookie-banner">We use cookies</div><script>alert(1)</script><form><input name="q"></form></main><footer>© Acme</footer></body></html>', 'https://www.a.cz/about-us/');
check('2.6 WebImport::extract: the main content without the header, footer, cookie bar, script and form', [
    $webPage['titulek'], $webPage['popis'], str_contains($webPage['obsah'], 'oak furniture'), str_contains($webPage['obsah'], 'Home'), str_contains($webPage['obsah'], '©'),
    str_contains($webPage['obsah'], 'cookies'), str_contains($webPage['obsah'], 'alert'), str_contains($webPage['obsah'], '<form'), str_contains($webPage['obsah'], '<h1'),
    str_contains($webPage['obsah'], 'src="https://www.a.cz/img/team.jpg"'), str_contains($webPage['obsah'], 'href="/contact"'), str_contains($webPage['obsah'], 'href="https://other.cz/"'), $webPage['clanek'],
], ['About us', 'Who we are', true, false, false, false, false, false, false, true, true, true, false]);
$webArticle = Kaleta\Core\WebImport::extract('<html><head><title>New workshop</title><meta property="article:published_time" content="2025-03-04T10:00:00+01:00"></head><body><article><p>' . str_repeat('We opened a new workshop. ', 6) . '</p></article></body></html>', 'https://a.cz/2025/03/new-workshop');
check('2.6 WebImport::extract: an article with its date, the title from <title> without the site name', [$webArticle['titulek'], substr($webArticle['datum'], 0, 10), $webArticle['clanek']], ['New workshop', '2025-03-04', true]);
check('2.6 ImageDownloader: images from any public host only when the import allows it', [(new Kaleta\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://cdn.wix.example/x.jpg'),
    (new Kaleta\Core\ImageDownloader('https://a.cz'))->isAllowedUrl('https://cdn.wix.example/x.jpg'), (new Kaleta\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://user:pw@cdn.example/x.jpg')], [true, false, false]);

/* ---------- numbers by language ---------- */
check('pocet: česky mezera jako oddělovač tisíců', Kaleta\Core\Language::runWith('cs', fn () => format_count(1234567)), "1\u{00A0}234\u{00A0}567");
check('pocet: anglicky čárka a desetinná tečka', Kaleta\Core\Language::runWith('en', fn () => format_count(12345.678, 2)), '12,345.68');
check('Soubory::velikost: anglicky desetinná tečka', Kaleta\Core\Language::runWith('en', fn () => Kaleta\Core\Files::size(3 * 1048576 + 524288)), '3.5 MB');

/* ---------- marketing codes and consent ---------- */
check('Seo::cekaNaSouhlas: bez lišty beze změny', Kaleta\Front\Seo::deferUntilConsent('<script src="x.js"></script>', 'zadna'), '<script src="x.js"></script>');
check('Seo::cekaNaSouhlas: vestavěná lišta balí do <template>', Kaleta\Front\Seo::deferUntilConsent('<ins></ins><script>a()</script>', 'vestavena'), '<template data-souhlas="marketing"><ins></ins><script>a()</script></template>');
check('Seo::cekaNaSouhlas: externí služba dostane značené skripty', Kaleta\Front\Seo::deferUntilConsent('<ins></ins><SCRIPT async src="x.js"></script><script type="application/json">{}</script>', 'externi'),
    '<ins></ins><script type="text/plain" data-cookieconsent="marketing" async src="x.js"></script><script type="application/json">{}</script>');

/* ---------- update: cleaning up files the new release no longer contains ---------- */
$cleanup = sys_get_temp_dir() . '/kaleta-uklid-' . bin2hex(random_bytes(4));
mkdir($cleanup . '/system/stare', 0775, true);
mkdir($cleanup . '/media', 0775, true);
foreach (['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', 'vlastni.php'] as $f) {
    file_put_contents($cleanup . '/' . $f, 'x');
}
$deleted = Kaleta\Core\Updater::cleanUpObsolete($cleanup, ['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', '../mimo.php'], ['index.php', 'system/zustava.php']);
check('Aktualizace: smaže jen soubor zrušený novým vydáním', $deleted, 1);
check('Aktualizace: zrušený soubor i jeho prázdná složka jsou pryč', is_dir($cleanup . '/system/stare'), false);
check('Aktualizace: chráněné cesty a vlastní soubory zůstávají', [is_file($cleanup . '/media/foto.jpg'), is_file($cleanup . '/config.php'), is_file($cleanup . '/vlastni.php'), is_file($cleanup . '/system/zustava.php')], [true, true, true, true]);
// files that ride along only for the update (old classes, the alias file of 1.4–2.0) go after it – unless edited; nothing else
mkdir($cleanup . '/system/src/Old', 0775, true);
file_put_contents($cleanup . '/system/class-aliases.php', "<?php\nreturn [];\n");
file_put_contents($cleanup . '/system/src/Old/Gone.php', 'edited');
file_put_contents($cleanup . '/system/soubory.json', json_encode(['legacy' => ['system/class-aliases.php' => hash('sha256', "<?php\nreturn [];\n"),
    'system/src/Old/Gone.php' => hash('sha256', 'original'), 'system/zustava.php' => hash('sha256', 'x')]]));
check('Update: legacy files go after the update, edited and other files stay', [Kaleta\Core\Updater::cleanUpRemoved($cleanup), is_file($cleanup . '/system/class-aliases.php'),
    is_file($cleanup . '/system/src/Old/Gone.php'), is_file($cleanup . '/system/zustava.php')], [1, false, true, true]);
exec('rm -rf ' . escapeshellarg($cleanup));

/* ---------- passkeys (WebAuthn): a software authenticator against the verification core ---------- */
$pkRp = 'redakce.example'; $pkOrigin = 'https://redakce.example';
$pkKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$pkDescription = openssl_pkey_get_details($pkKey);
$pkDer = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkDescription['key']));
$pkX = str_pad($pkDescription['ec']['x'], 32, "\0", STR_PAD_LEFT); $pkY = str_pad($pkDescription['ec']['y'], 32, "\0", STR_PAD_LEFT);
$pkCose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $pkX . "\x22\x58\x20" . $pkY;
$pkId = random_bytes(20);
$pkClient = static fn (string $type, string $challenge, string $origin): string => Kaleta\Core\Passkey::b64((string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES));
$pkRegData = static fn (string $rp, int $flags = 0x45): string => hash('sha256', $rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($pkId)) . $pkId . $pkCose;
$pkChallenge = Kaleta\Core\Passkey::challenge();
$pkReg = ['clientDataJSON' => $pkClient('webauthn.create', $pkChallenge, $pkOrigin), 'authenticatorData' => Kaleta\Core\Passkey::b64($pkRegData($pkRp)), 'publicKey' => Kaleta\Core\Passkey::b64($pkDer), 'publicKeyAlgorithm' => -7];
$pkSaved = Kaleta\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, $pkOrigin, $pkRp);
check('Passkey: registrace vrátí id klíče', $pkSaved['id'], Kaleta\Core\Passkey::b64($pkId));
check('Passkey: registrace vrátí veřejný klíč v PEM', str_contains($pkSaved['klic'], 'BEGIN PUBLIC KEY'), true);
$pkRejects = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
check('Passkey: registrace s cizí výzvou neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifyRegistration($pkReg, Kaleta\Core\Passkey::challenge(), $pkOrigin, $pkRp)), true);
check('Passkey: registrace z jiného původu neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, 'https://podvrh.example', $pkRp)), true);
check('Passkey: registrace pro jinou doménu neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, $pkOrigin, 'jina.example')), true);
$pkForeign = openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
check('Passkey: podstrčený veřejný klíč neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifyRegistration(['publicKey' => Kaleta\Core\Passkey::b64(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkForeign['key'])))] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), true);
check('Passkey: odpověď z přihlášení nejde použít k registraci', $pkRejects(fn () => Kaleta\Core\Passkey::verifyRegistration(['clientDataJSON' => $pkClient('webauthn.get', $pkChallenge, $pkOrigin)] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), true);
$pkSignIn = static function (string $challenge, int $counter, string $rp = 'redakce.example', string $origin = 'https://redakce.example', int $flags = 0x05) use ($pkKey, $pkClient): array {
    $data = hash('sha256', $rp, true) . chr($flags) . pack('N', $counter);
    $client = $pkClient('webauthn.get', $challenge, $origin);
    openssl_sign($data . hash('sha256', Kaleta\Core\Passkey::fromB64($client), true), $signature, $pkKey, OPENSSL_ALGO_SHA256);

    return ['clientDataJSON' => $client, 'authenticatorData' => Kaleta\Core\Passkey::b64($data), 'signature' => Kaleta\Core\Passkey::b64($signature)];
};
$pkV2 = Kaleta\Core\Passkey::challenge();
check('Passkey: platné přihlášení vrátí nové počitadlo', Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 4), 5);
check('Passkey: synchronizovaný klíč s nulovým počitadlem projde', Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 0), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 0), 0);
check('Passkey: přehraná odpověď (jiná výzva) neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6), Kaleta\Core\Passkey::challenge(), $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: počitadlo, které neroste, neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: podpis jiným klíčem neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6), $pkV2, $pkOrigin, $pkRp, $pkForeign['key'], 5)), true);
check('Passkey: odpověď z podvržené domény neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example.podvrh.cz'), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: klíč jiné domény neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'jina.example'), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: bez potvrzení přítomnosti uživatele neprojde', $pkRejects(fn () => Kaleta\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example', 0x00), $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
$pkChanged = $pkSignIn($pkV2, 6); $pkChanged['authenticatorData'] = Kaleta\Core\Passkey::b64(Kaleta\Core\Passkey::fromB64($pkChanged['authenticatorData']) . 'x');
check('Passkey: pozměněná data zařízení neprojdou', $pkRejects(fn () => Kaleta\Core\Passkey::verifySignIn($pkChanged, $pkV2, $pkOrigin, $pkRp, $pkSaved['klic'], 5)), true);
check('Passkey: původ a doména z adresy webu', [Kaleta\Core\Passkey::origin('https://WWW.Web.cz/'), Kaleta\Core\Passkey::origin('http://localhost:8080'), Kaleta\Core\Passkey::rpId('https://www.web.cz:8443/x')], ['https://www.web.cz', 'http://localhost:8080', 'www.web.cz']);

/* ---------- .htaccess: rewrite targets are URLs, not relative paths ---------- */
// A relative target (RewriteRule ^ index.php) ends in a loop and error 500 on hosts that map subdomains into a folder outside the web root.
$htaccess = (string) file_get_contents(KALETA_ROOT . '/.htaccess');
preg_match_all('/^\s*RewriteRule\s+\S+\s+(\S+)/m', $htaccess, $targets);
check('.htaccess: žádný přepis nemá relativní cíl', array_values(array_filter($targets[1], static fn (string $c): bool => $c !== '-' && !str_starts_with($c, '%{ENV:BASE}/'))), []);
check('.htaccess: složka webu se počítá z adresy požadavku', str_contains($htaccess, 'E=BASE:%1'), true);

/* ---------- menu paths as links (messages, "Stav systému" (System status), help) ---------- */
Kaleta\Core\Language::set('cs', 'admin-');
$routesHtml = Kaleta\Admin\MenuPaths::links('/admin.php', 'Je k dispozici nová verze 3.0.1 – nainstalujete ji v Nastavení → Zálohy a aktualizace. <b>', ['settings']);
check('Cesty: známá cesta je odkaz', str_contains($routesHtml, '<a href="/admin.php?module=settings&amp;tab=backups">Nastavení → Zálohy a aktualizace</a>'), true);
check('Cesty: zbytek textu zůstává escapovaný', str_contains($routesHtml, '&lt;b&gt;'), true);
check('Cesty: delší cesta má přednost a odkaz se nevnořuje', substr_count($routesHtml, '<a '), 1);
check('Cesty: bez práva k modulu žádný odkaz', str_contains(Kaleta\Admin\MenuPaths::links('/admin.php', 'Nastavení → Pošta', []), '<a '), false);
Kaleta\Core\Language::set('en', 'admin-');
check('Cesty: v angličtině se odkazuje přeložená cesta', str_contains(Kaleta\Admin\MenuPaths::links('/admin.php', t('Je k dispozici nová verze %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', '3.0.1'), ['settings']), '>Settings → Backups and updates</a>'), true);
Kaleta\Core\Language::set('cs', 'admin-');

/* ---------- spam protection: IP hash ---------- */
check('Antispam::otisk: není to IP adresa', str_contains(Kaleta\Core\Antispam::hash('203.0.113.7'), '203'), false);
check('Antispam::otisk: stejná adresa = stejný otisk', Kaleta\Core\Antispam::hash('203.0.113.7'), Kaleta\Core\Antispam::hash('203.0.113.7'));

/* ---------- import from WordPress: reading the export (tools/fixtures/wordpress-sample.xml), preview, safe XML ---------- */
$wpPath = KALETA_ROOT . '/tools/fixtures/wordpress-sample.xml';
$wpRejects = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
$wp = new Kaleta\Core\WpFile($wpPath);
check('WpSoubor: ukázkový export projde ověřením', $wpRejects(fn () => $wp->verify()), false);
$wpHeader = $wp->header();
check('WpSoubor: starý web z <channel><link>', [$wpHeader['nazev'], $wpHeader['adresa']], ['Podhorský zpravodaj', 'https://www.podhorsky-zpravodaj.example']);
check('WpSoubor: autoři jako přihlašovací jméno => zobrazované jméno', $wpHeader['autori'], ['redakce' => 'Redakce Zpravodaje', 'bhorakova' => 'Běla Horáková']);
check('WpSoubor: rubriky s hierarchií', $wpHeader['rubriky'], ['zpravy' => ['nazev' => 'Zprávy', 'predek' => ''], 'z-radnice' => ['nazev' => 'Z radnice', 'predek' => 'zpravy']]);
check('WpSoubor: tři štítky', array_keys($wpHeader['stitky']), ['most', 'doprava', 'slavnosti']);
$wpItems = iterator_to_array($wp->items());
check('WpSoubor: devět položek, typy v pořadí souboru', array_column($wpItems, 'typ'), ['post', 'post', 'post', 'post', 'page', 'nav_menu_item', 'attachment', 'attachment', 'attachment']);
check('WpSoubor: přeskočení už zpracovaných položek drží pořadí', array_keys(iterator_to_array($wp->items(7))), [7, 8]);
check('WpSoubor: první příspěvek', [$wpItems[0]['id'], $wpItems[0]['stav'], $wpItems[0]['pripnuty'], $wpItems[0]['nahled'], $wpItems[0]['rubriky'], array_keys($wpItems[0]['stitky'])], [101, 'publish', true, 201, ['z-radnice' => 'Z radnice'], ['most', 'doprava']]);
check('WpSoubor: komentáře se nečtou', array_key_exists('komentare', $wpItems[0]), false);
check('WpSoubor: e-mail ani IP se z exportu nikam nedostanou', (bool) preg_match('/posta\.example|198\.51\.100|203\.0\.113/', (string) json_encode($wpItems)), false);
$wpState = Kaleta\Core\WpImport::newState('wordpress-sample.xml');
Kaleta\Core\WpImport::analyze($wpState, 30, $wpPath);
check('WpImport náhled: fáze a počet položek', [$wpState['faze'], $wpState['celkem'], $wpState['pozice']], ['nahled', 9, 0]);
check('WpImport náhled: příspěvky podle stavu a stránky', [$wpState['prehled']['clanky'], $wpState['prehled']['stranky']], [['publish' => 3, 'draft' => 1], ['publish' => 1]]);
check('WpImport náhled: kategorie, štítky, autoři, přílohy', [$wpState['prehled']['rubriky'], $wpState['prehled']['stitky'], $wpState['prehled']['autori'], $wpState['prehled']['prilohy']], [2, 3, 2, 3]);
check('WpImport náhled: upozorní na cizí typ obsahu a zkratku doplňku', [$wpState['prehled']['jine'], $wpState['prehled']['zkratky']], [['nav_menu_item' => 1], ['kontaktni-formular' => 1]]);
check('WpImport náhled: adresy příloh pro galerie a hlavní obrázky', $wpState['prilohy'][202] ?? '', 'https://www.podhorsky-zpravodaj.example/wp-content/uploads/2026/05/pohled.jpg');

/* ---------- import from WordPress: SEO plugin data (SmartCrawl, Yoast SEO, Rank Math) ---------- */
check('WpSoubor: čte jen meta klíče SEO pluginů', [array_keys($wpItems[0]['meta']), $wpItems[3]['meta']], [['_wds_title', '_wds_metadesc', '_wds_meta-robots-noindex'], []]);
check('WpImport náhled: SEO data po pluginech (výchozí vzor Yoastu se nepočítá)', $wpState['prehled']['seo'], [
    'SmartCrawl' => ['title' => 2, 'description' => 2, 'noindex' => 0, 'canonical' => 1],
    'Yoast SEO' => ['title' => 0, 'description' => 1, 'noindex' => 1, 'canonical' => 0],
    'Rank Math' => ['title' => 1, 'description' => 1, 'noindex' => 1, 'canonical' => 1],
]);
$wpSeoContext = ['title' => 'Lávka přes Bystřinu', 'sitename' => 'Podhorský zpravodaj', 'sitedesc' => 'Zprávy z údolí', 'excerpt' => 'Po roce oprav.', 'category' => 'Z radnice'];
check('WpSeo::raw: první plugin s vyplněnou hodnotou; Yoast 2 = indexovat', Kaleta\Core\WpSeo::raw(['_yoast_wpseo_meta-robots-noindex' => '2', '_yoast_wpseo_title' => ' T ']), ['plugin' => 'Yoast SEO', 'title' => 'T', 'description' => '', 'noindex' => false, 'canonical' => '']);
check('WpSeo::raw: bez SEO meta', Kaleta\Core\WpSeo::raw(['_thumbnail_id' => '5'])['plugin'], '');
check('WpSeo::robotsNoindex: serializované pole Rank Math jen jako text', array_map(Kaleta\Core\WpSeo::robotsNoindex(...), ['a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}', 'a:1:{i:0;s:5:"index";}', 'a:1:{i:0;s:12:"noimageindex";}', 'noindex,nofollow', 'O:8:"stdClass":0:{}', '']), [true, false, false, true, false, false]);
check('WpSeo::isDefaultPattern: jen proměnné a oddělovače = výchozí vzor pluginu', array_map(Kaleta\Core\WpSeo::isDefaultPattern(...), ['%%title%% %%sep%% %%sitename%%', '%%title%% %%page%% %%sep%% %%sitename%%', '%title% %sep% %sitename%', '%%title%% | %%sitename%%', '%%title%%', '', 'Blog – %%sitename%%', 'Nabídka %%title%%']), [true, true, true, true, true, true, false, false]);
check('WpSeo::title: proměnné Yoastu a SmartCrawlu se doplní, oddělovač je pomlčka', Kaleta\Core\WpSeo::title('Lávka znovu otevřena %%sep%% %%sitename%%', $wpSeoContext), 'Lávka znovu otevřena – Podhorský zpravodaj');
check('WpSeo::title: proměnné Rank Math', Kaleta\Core\WpSeo::title('%title% – fotografie %sep% %sitename%', $wpSeoContext), 'Lávka přes Bystřinu – fotografie – Podhorský zpravodaj');
check('WpSeo::title: výchozí vzor se neimportuje', [Kaleta\Core\WpSeo::title('%%title%% %%sep%% %%sitename%%', $wpSeoContext), Kaleta\Core\WpSeo::title('%title% %page% %sep% %sitename%', $wpSeoContext)], ['', '']);
check('WpSeo::title: stránkování a datum zmizí i s oddělovačem navíc', Kaleta\Core\WpSeo::title('%%title%% %%page%% – %%currentyear%% – Blog', $wpSeoContext), 'Lávka přes Bystřinu – ' . date('Y') . ' – Blog');
check('WpSeo::title: odstraněná proměnná nenechá dvojitý oddělovač', Kaleta\Core\WpSeo::title('%%title%% %%sep%% %%page%% %%sep%% Blog', $wpSeoContext), 'Lávka přes Bystřinu – Blog');
check('WpSeo::title: neznámá proměnná = titulek se zahodí, ne rozbitý', [Kaleta\Core\WpSeo::title('%%title%% %%sep%% %%neznama_promenna%%', $wpSeoContext), Kaleta\Core\WpSeo::resolve('%%title%% %%neznama%%', $wpSeoContext)], ['', null]);
check('WpSeo::title: vlastní pole (cf_) a termy (ct_) se jen odstraní', Kaleta\Core\WpSeo::title('Nabídka %%title%% %%cf_moje_pole%% %%ct_oblast%%', $wpSeoContext), 'Nabídka Lávka přes Bystřinu');
check('WpSeo::title: shodný s titulkem příspěvku se neukládá', Kaleta\Core\WpSeo::title('%%title%%%%cf_x%%', $wpSeoContext), '');
check('WpSeo::title: délka podle sloupce', mb_strlen(Kaleta\Core\WpSeo::title(str_repeat('ž', 300), $wpSeoContext, 200)), 200);
check('WpSeo::description: výtah a hlavní kategorie', Kaleta\Core\WpSeo::description('%%excerpt%% Více v rubrice %%primary_category%%.', $wpSeoContext), 'Po roce oprav. Více v rubrice Z radnice.');
check('WpSeo::description: jen %%excerpt%% je výchozí vzor – web si popis sestaví sám', Kaleta\Core\WpSeo::description('%%excerpt%%', $wpSeoContext), '');
check('WpSeo::description: značky a entity pryč', Kaleta\Core\WpSeo::description('Sýr &amp; <b>víno</b> v %sitename%', $wpSeoContext), 'Sýr & víno v Podhorský zpravodaj');
check('WpSeo::keys: jeden seznam klíčů ze všech pluginů', [count(Kaleta\Core\WpSeo::keys()), in_array('rank_math_robots', Kaleta\Core\WpSeo::keys(), true)], [12, true]);

/* ---------- import from WordPress: custom post types and fields as collections (2.7, tools/fixtures/wordpress-cpt.xml) ---------- */
check('WpTypes::isCustomType: own types yes, WordPress and plugin internals no', array_map(Kaleta\Core\WpTypes::isCustomType(...), ['reference', 'team_member', 'product', 'page', 'nav_menu_item', 'wp_block', 'acf-field', 'breakdance_template', 'shop_order', 'wpcf7_contact_form', 'Bad Type!']),
    [true, true, true, false, false, false, false, false, false, false, false]);
check('WpTypes::fields: ACF fields always, plugin and underscore meta never', Kaleta\Core\WpTypes::fields(['klient' => 'A', '_klient' => 'field_1', 'rank_math_title' => 'x', '_edit_lock' => '1', 'ekit_views' => '3', 'cena' => '100', 'site-sidebar-layout' => 'x']),
    ['klient' => 'A', 'cena' => '100']);
check('WpTypes::guessType', array_map(fn (array $c): ?string => Kaleta\Core\WpTypes::guessType($c[0], $c[1], [301 => 'https://x/a.jpg']), [
    ['fotka', '301'], ['logo_firmy', '77'], ['pocet', '77'], ['x', 'https://old.example/a/b.png?v=2'], ['datum', '20240315'], ['datum', '20241345'], ['web', 'https://novakovi.example'],
    ['cena', '1 200'], ['cena', '1200,50'], ['popis', '<p>Hi</p>'], ['adresa', "Ulice 1\nMěsto"], ['jmeno', 'Jana'], ['galerie', 'a:2:{i:0;s:3:"301";}'], ['x', '']]),
    ['obrazek', 'obrazek', 'cislo', 'obrazek', 'datum', 'cislo', 'odkaz', 'text', 'cislo', 'html', 'radky', 'text', null, 'text']);
check('WpTypes::fieldType, date, label, prefix', [Kaleta\Core\WpTypes::fieldType(['text' => 3, 'radky' => 1]), Kaleta\Core\WpTypes::fieldType(['obrazek' => 1, 'text' => 0]), Kaleta\Core\WpTypes::fieldType([]),
    Kaleta\Core\WpTypes::date('20240315'), Kaleta\Core\WpTypes::date('2024-02-30'), Kaleta\Core\WpTypes::label('team_member-role'), Kaleta\Core\WpTypes::prefix('https://a.cz/reference/kuchyne/'), Kaleta\Core\WpTypes::prefix('https://a.cz/?p=4')],
    ['radky', 'obrazek', 'text', '2024-03-15', '', 'Team member role', 'reference', '']);
$cptPath = KALETA_ROOT . '/tools/fixtures/wordpress-cpt.xml';
$cptItems = iterator_to_array((new Kaleta\Core\WpFile($cptPath))->items());
check('WpSoubor: fields of a custom post type are read, of other types not', [array_keys($cptItems[1]['pole']), $cptItems[0]['pole']],
    [['klient', '_klient', 'rok_dokonceni', '_rok_dokonceni', 'datum_predani', '_datum_predani', 'web_klienta', '_web_klienta', 'fotka', '_fotka', 'galerie', '_galerie', 'rank_math_seo_score', 'ekit_post_views_count'], []]);
$cptState = Kaleta\Core\WpImport::newState('wordpress-cpt.xml');
Kaleta\Core\WpImport::analyze($cptState, 30, $cptPath);
check('WpImport náhled: a custom post type with its fields, address and what is left out', [$cptState['prehled']['typy'], $cptState['prehled']['jine']], [['reference' => [
    'pocet' => 2, 'predpony' => ['reference' => 2], 'pole' => ['klient' => ['text' => 2], 'rok_dokonceni' => ['cislo' => 2], 'datum_predani' => ['datum' => 2], 'web_klienta' => ['odkaz' => 1], 'fotka' => ['obrazek' => 1, 'text' => 0]],
    'vynechano' => ['galerie' => true], 'obsah' => true, 'perex' => false]], []]);

$wpTmp = sys_get_temp_dir() . '/kaleta-wp-' . bin2hex(random_bytes(4));
mkdir($wpTmp);
$wpHead = '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title><link>https://stary.example</link>';
file_put_contents($wpTmp . '/tajne.txt', 'TAJNY-OBSAH-SERVERU');
$wpMalicious = [
    'vnější entita (XXE)' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://' . $wpTmp . '/tajne.txt">]>' . $wpHead . '<item><title>&xxe;</title><content:encoded>&xxe;</content:encoded></item></channel></rss>',
    'miliarda smíchů' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "haha"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]>' . $wpHead . '<item><title>&c;</title></item></channel></rss>',
    'vnější DTD' => '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://127.0.0.1:1/zly.dtd">' . $wpHead . '</channel></rss>',
    'nedefinovaná entita' => '<?xml version="1.0"?>' . $wpHead . '<item><title>&neexistuje;</title></item></channel></rss>',
    'jiné XML než export WordPressu' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Obyčejné RSS</title><item><title>x</title></item></channel></rss>',
    'poškozené XML' => '<?xml version="1.0"?>' . $wpHead . '<item><title>neuzavřeno</item>',
    'HTML místo XML' => '<html><body>xmlns:wp="http://wordpress.org/export/1.2/"</body></html>',
];
foreach ($wpMalicious as $label => $xml) {
    file_put_contents($wpTmp . '/zly.xml', $xml);
    $read = '';
    $rejected = $wpRejects(function () use ($wpTmp, &$read): void {
        $bad = new Kaleta\Core\WpFile($wpTmp . '/zly.xml');
        $bad->verify();
        $read = (string) json_encode([$bad->header(), iterator_to_array($bad->items())]);
    });
    check('WpSoubor odmítne: ' . $label, [$rejected, str_contains($read, 'TAJNY-OBSAH') || str_contains($read, 'hahahaha')], [true, false]);
}
file_put_contents($wpTmp . '/dobry.xml', '<?xml version="1.0"?>' . $wpHead . '<item><title>A &amp; B</title></item></channel></rss>');
check('WpSoubor: běžné entity (&amp;) jsou v pořádku', iterator_to_array((new Kaleta\Core\WpFile($wpTmp . '/dobry.xml'))->items())[0]['titulek'], 'A & B');
exec('rm -rf ' . escapeshellarg($wpTmp));
foreach (['export.xml' => true, 'Můj web.WordPress.2026-09-21.XML' => true, '../config.xml' => false, 'slozka/export.xml' => false, '.skryty.xml' => false, 'export.php' => false, 'export.xml.php' => false, "export\0.xml" => false, '' => false] as $name => $expectedResult) {
    check('WpSoubor::platnyNazev ' . json_encode((string) $name), Kaleta\Core\WpFile::isValidName((string) $name), $expectedResult);
}
check('WpSoubor: název nahraného souboru bez diakritiky a vždy .xml', Kaleta\Core\WpFile::uploadName('Můj web.WordPress.2026-09-21.xml'), 'muj-web-wordpress-2026-09-21.xml');

/* ---------- import from WordPress: content cleanup ---------- */
$wpClean = Kaleta\Core\WpContent::sanitize(...);
check('WpObsah: klasický editor – odstavce z prázdných řádků, <br> z konců řádků', $wpClean("První řádek\ndruhý řádek\n\nDruhý odstavec"), "<p>První řádek<br>\ndruhý řádek</p>\n<p>Druhý odstavec</p>");
check('WpObsah: blokové značky se do <p> nebalí', $wpClean("Úvod\n\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>"), "<p>Úvod</p>\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>");
check('WpObsah: komentáře Gutenbergu mizí, odstavce zůstávají', $wpClean("<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Nadpis</h1>\n<!-- /wp:heading -->"), "<p>Text</p>\n<h2>Nadpis</h2>");
check('WpObsah: [caption] → figure s popiskem, odkaz na velký obrázek mizí', $wpClean('[caption id="attachment_5" align="alignnone" width="300"]<a href="https://stary.example/wp-content/uploads/most.jpg"><img class="size-medium" src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" /></a> Most přes řeku[/caption]'), '<figure><img src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" loading="lazy"><figcaption>Most přes řeku</figcaption></figure>');
check('WpObsah: [gallery ids] → naše galerie jen ze známých obrázků', $wpClean('[gallery ids="5,6,7,99" columns="2"]', [5 => 'https://stary.example/a.jpg', 6 => 'https://stary.example/b.png', 7 => 'https://stary.example/dokument.pdf']), '<figure class="galerie"><img src="https://stary.example/a.jpg" alt="" loading="lazy"><img src="https://stary.example/b.png" alt="" loading="lazy"></figure>');
check('WpObsah: blok galerie Gutenbergu → naše galerie', $wpClean('<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://stary.example/a.jpg" alt="A" class="wp-image-5"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'), '<figure class="galerie"><img src="https://stary.example/a.jpg" alt="A" loading="lazy"></figure>');
check('WpObsah: adresa YouTube na samostatném řádku je vlastní odstavec', $wpClean("Text před\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nText po"), "<p>Text před</p>\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>\n<p>Text po</p>");
check('WpObsah: takový odstavec web promění v přehrávač', str_contains((new ReflectionClass(NewsText::class))->newInstanceWithoutConstructor()->embedVideoUrls($wpClean("https://www.youtube.com/watch?v=dQw4w9WgXcQ")), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
check('WpObsah: blok embed → adresa v odstavci', $wpClean('<!-- wp:embed {"url":"https://vimeo.com/76979871","type":"video"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/76979871</div></figure><!-- /wp:embed -->'), '<p>https://vimeo.com/76979871</p>');
check('WpObsah: iframe YouTube → adresa, cizí iframe pryč', $wpClean('<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560"></iframe><iframe src="https://zly.example/"></iframe>'), '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>');
check('WpObsah: zkratky doplňků mizí, jejich text a [sic] zůstávají', $wpClean('[vc_row][vc_column width="1/2"]Text uvnitř[/vc_column][/vc_row] [contact-form-7 id="1"] citace [sic] a [[ukázka]]'), '<p>Text uvnitř  citace [sic] a [[ukázka]]</p>');
check('WpObsah: v ukázce kódu se závorky nemění', $wpClean("<pre>pole[muj_klic] = 1;\n\nkonec</pre>"), "<pre>pole[muj_klic] = 1;\n\nkonec</pre>");
$wpUnsafe = $wpClean('<p onclick="x()" style="color:red">Klik <a href="java&#9;script:alert(1)" onmouseover="x()">odkaz</a> <a href="https://dobry.example/" target="_blank">ven</a></p><script>alert(1)</script><style>p{}</style><img src="data:image/svg+xml;base64,AAAA"><img src="https://stary.example/a.jpg" onerror="alert(1)" srcset="x 2x"><svg onload="alert(1)"><circle/></svg><form action="/x"><input name="a"></form><object data="x"></object><div class="obal"><span>Text v divu</span></div>');
check('WpObsah: skripty, styly, obsluhy událostí, javascript: a data: adresy neprojdou', $wpUnsafe, "<p>Klik odkaz <a href=\"https://dobry.example/\" target=\"_blank\" rel=\"noopener\">ven</a></p>\n<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"></figure>\n<p>Text v divu</p>");
check('WpObsah: po čištění nezbyde nic nebezpečného', (bool) preg_match('/<script|<style|<svg|<form|<iframe|<object|\son[a-z]+=|javascript:|data:|style=|srcset=/i', $wpUnsafe), false);
check('WpObsah::bezpecnaAdresa', array_map(Kaleta\Core\WpContent::isSafeUrl(...), ['https://a.cz/', '/clanek/x', '#kotva', 'mailto:a@b.cz', "java\nscript:alert(1)", ' JAVASCRIPT:alert(1)', 'data:text/html,x', 'vbscript:x', '']), [true, true, true, true, false, false, false, false, false]);
check('WpObsah: perex z výtahu WordPressu, text celý', Kaleta\Core\WpContent::introAndText('Ruční <b>výtah</b> &amp; spol.', "Odstavec jedna\n\nOdstavec dva"), ['<p>Ruční výtah &amp; spol.</p>', "<p>Odstavec jedna</p>\n<p>Odstavec dva</p>"]);
check('WpObsah: bez výtahu je perexem první odstavec a v textu se neopakuje', Kaleta\Core\WpContent::introAndText('', "[caption]<img src=\"https://stary.example/a.jpg\" alt=\"\"> Popisek[/caption]\n\nOdstavec jedna\n\nOdstavec dva"), ['<p>Odstavec jedna</p>', "<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"><figcaption>Popisek</figcaption></figure>\n<p>Odstavec dva</p>"]);
check('WpObsah: značka „Číst dál“ dělí perex a text', Kaleta\Core\WpContent::introAndText('', "Před značkou\n<!--more-->\nZa značkou"), ['<p>Před značkou</p>', '<p>Za značkou</p>']);
check('WpObsah: cizí zkratky pro varování v náhledu', Kaleta\Core\WpContent::unknownShortcodes('[gallery ids="1"] [caption]x[/caption] [et_pb_section]a[/et_pb_section] [sic] <code>[muj_klic]</code>'), ['et_pb_section']);
[$wpIntro, $wpText] = Kaleta\Core\WpContent::introAndText($wpItems[0]['perex'], $wpItems[0]['obsah'], $wpState['prilohy']);
check('WpObsah: ukázkový příspěvek – perex, obrázek s popiskem, video, galerie, bez skriptu a zkratky', [str_starts_with($wpIntro, '<p>Po dvanácti měsících'), substr_count($wpText, '<figcaption>'), str_contains($wpText, '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>'), substr_count($wpText, 'class="galerie"'), (bool) preg_match('/script|onclick|kontaktni-formular|javascript/i', $wpText)], [true, 1, true, 1, false]);

/* ---------- import from WordPress: status, date, URLs ---------- */
$wpStatuses = [];
foreach (['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'nesmysl'] as $wpS) {
    $wpM = Kaleta\Core\WpImport::articleStatus($wpS);
    $wpStatuses[$wpS] = $wpM === null ? 'vynechat' : ($wpM['visible'] ? 'vydany' : 'koncept');
}
check('WpImport::stavClanku', $wpStatuses, ['publish' => 'vydany', 'future' => 'vydany', 'draft' => 'koncept', 'pending' => 'koncept', 'private' => 'vynechat', 'trash' => 'vynechat', 'auto-draft' => 'vynechat', 'inherit' => 'vynechat', 'nesmysl' => 'vynechat']);
check('WpImport::stavClanku: příspěvek chráněný heslem se nezveřejní', Kaleta\Core\WpImport::articleStatus('publish', true), ['visible' => 0]);
check('WpImport::datum: místní čas starého webu', Kaleta\Core\WpImport::date(['datum' => '2026-05-12 09:30:00', 'datum_gmt' => '2026-05-12 07:30:00']), '2026-05-12 09:30:00');
check('WpImport::datum: koncept s nulovým datem dostane dnešek', Kaleta\Core\WpImport::date(['datum' => '0000-00-00 00:00:00', 'datum_gmt' => '0000-00-00 00:00:00', 'vydano' => ''], 1789000000), date('Y-m-d H:i:s', 1789000000));
$wpTaken = ['lavka', 'lavka-2'];
check('WpImport::volnaAdresa: obsazená adresa dostane číslo', Kaleta\Core\WpImport::availableSlug('lavka', fn (string $a): bool => in_array($a, $wpTaken, true)), 'lavka-3');
check('WpImport::volnaAdresa: volná zůstává', Kaleta\Core\WpImport::availableSlug('most', fn (string $a): bool => in_array($a, $wpTaken, true)), 'most');
check('WpImport::staraCesta', array_map(Kaleta\Core\WpImport::oldPath(...), ['https://stary.example/2026/05/lavka/', 'https://stary.example/?p=104', 'https://stary.example/blog/p%C5%99%C3%ADklad/', 'https://stary.example/' . str_repeat('x', 300)]), ['2026/05/lavka', '', 'blog/příklad', '']);
check('WpImport::bezRozmeru', array_map(Kaleta\Core\WpImport::withoutSize(...), ['https://s.example/u/foto-300x200.jpg', 'https://s.example/u/foto-1024x683.JPG?ver=2', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']), ['https://s.example/u/foto.jpg', 'https://s.example/u/foto.JPG', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']);
check('WpImport::zdroj: doména starého webu, nejvýš 40 znaků', [Kaleta\Core\WpImport::source('https://WWW.Stary.example/blog'), Kaleta\Core\WpImport::source(''), strlen(Kaleta\Core\WpImport::source('https://' . str_repeat('a', 60) . '.example'))], ['wp:stary.example', 'wp', 40]);
check('ExportWebu::cesta: jen názvy exportů, nic mimo složku', [Kaleta\Core\SiteExport::path('../config.php'), Kaleta\Core\SiteExport::path('export-20260921-101500.zip/../../config.php'), Kaleta\Core\SiteExport::path('kaleta-20260918-130917-rucni-7d777965.sql.gz')], [null, null, null]);

/* ---------- import from WordPress: downloading images only from the old site and only from public URLs (SSRF protection) ---------- */
$wpDownload = new Kaleta\Core\ImageDownloader('https://www.stary-web.example/blog/');
check('StahovaniObrazku: doména starého webu bez www', $wpDownload->domain(), 'stary-web.example');
foreach ([
    'https://www.stary-web.example/wp-content/uploads/a.jpg' => true,
    'http://stary-web.example/a.png' => true,
    'https://STARY-WEB.example./a.png' => true,
    'https://stary-web.example:443/a.png' => true,
    'https://stary-web.example:8443/a.png' => false,                 // another port
    'http://stary-web.example:22/a.png' => false,
    'https://cdn.stary-web.example/a.png' => false,                  // another (sub)domain
    'https://stary-web.example.utocnik.example/a.png' => false,
    'https://utocnik.example/stary-web.example/a.png' => false,
    'https://stary-web.example@utocnik.example/a.png' => false,      // a domain hidden behind a user name
    'https://uzivatel:heslo@stary-web.example/a.png' => false,       // credentials in the URL
    'ftp://stary-web.example/a.png' => false,
    'file:///etc/passwd' => false,
    'gopher://stary-web.example/' => false,
    '//stary-web.example/a.png' => false,
    'http://127.0.0.1/a.png' => false,
    'http://169.254.169.254/latest/meta-data/' => false,
    "https://stary-web.example/a.png\r\nHost: jinam" => false,       // injected headers
    'https://stary-web.example\\@utocnik.example/a.png' => false,
    '' => false,
] as $wpUrl => $expectedResult) {
    check('StahovaniObrazku::povolenaAdresa ' . json_encode((string) $wpUrl), $wpDownload->isAllowedUrl((string) $wpUrl), $expectedResult);
}
check('StahovaniObrazku: bez adresy starého webu se nestahuje nic', (new Kaleta\Core\ImageDownloader(''))->isAllowedUrl('https://cokoli.example/a.png'), false);
foreach ([
    '93.184.216.34' => true, '8.8.8.8' => true, '172.32.0.1' => true, '100.128.0.1' => true, '2606:4700:4700::1111' => true, '::ffff:93.184.216.34' => true,
    '10.0.0.5' => false, '172.16.0.1' => false, '172.31.255.255' => false, '192.168.1.1' => false, '127.0.0.1' => false, '127.255.255.254' => false,
    '169.254.169.254' => false, '100.64.0.1' => false, '100.127.255.255' => false, '0.0.0.0' => false, '0.1.2.3' => false, '224.0.0.1' => false, '255.255.255.255' => false,
    '192.0.2.10' => false, '198.18.0.1' => false, '::1' => false, '::' => false, 'fc00::1' => false, 'fd12:3456::1' => false, 'fe80::1' => false, 'ff02::1' => false,
    '::ffff:10.0.0.1' => false, '::ffff:127.0.0.1' => false, '64:ff9b::a00:1' => false, '::10.0.0.1' => false, '2002:a00:1::1' => false, '2001:0:4136:e378:8000:63bf:3fff:fdd2' => false,
    '2001:4860:4860::8888' => true, '[::1]' => false, 'neni-ip' => false, '' => false,
] as $wpIp => $expectedResult) {
    check('StahovaniObrazku::verejnaIp ' . $wpIp, Kaleta\Core\ImageDownloader::isPublicIp((string) $wpIp), $expectedResult);
}
check('StahovaniObrazku: IP adresa místo domény se posuzuje stejně', [$wpDownload->verifiedIp('127.0.0.1'), $wpDownload->verifiedIp('[::1]'), $wpDownload->verifiedIp('93.184.216.34')], [null, null, '93.184.216.34']);
check('StahovaniObrazku: přesměrování na jinou doménu neprojde dalším kolem kontroly', $wpDownload->isAllowedUrl(Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'https://utocnik.example/a.png')), false);
check('StahovaniObrazku: přesměrování //jinam a do vnitřní sítě neprojde', [$wpDownload->isAllowedUrl(Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', '//utocnik.example/a.png')), $wpDownload->isAllowedUrl(Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'http://169.254.169.254/'))], [false, false]);
check('StahovaniObrazku: relativní přesměrování zůstává na starém webu', [Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', '/jinde/b.png'), Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', 'b.png')], ['https://stary-web.example/jinde/b.png', 'https://stary-web.example/u/b.png']);
$wpPng = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$wpSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
check('StahovaniObrazku::typObrazku: PNG podle hlavičky i obsahu', Kaleta\Core\ImageDownloader::imageType('image/png; charset=binary', $wpPng), 'image/png');
check('StahovaniObrazku::typObrazku: hlavička tvrdí obrázek, obsah je HTML', Kaleta\Core\ImageDownloader::imageType('image/jpeg', '<html><body>přihlášení</body></html>'), null);
check('StahovaniObrazku::typObrazku: obsah je obrázek, hlavička ne', Kaleta\Core\ImageDownloader::imageType('text/html', $wpPng), null);
check('StahovaniObrazku::typObrazku: SVG se odmítá vždy', [Kaleta\Core\ImageDownloader::imageType('image/svg+xml', $wpSvg), Kaleta\Core\ImageDownloader::imageType('image/png', $wpSvg)], [null, null]);
check('StahovaniObrazku::typObrazku: prázdná odpověď', Kaleta\Core\ImageDownloader::imageType('image/png', ''), null);
check('StahovaniObrazku: limity podle zadání (15 MB, 3 přesměrování, 5 s spojení, 20 s celkem)', [Kaleta\Core\ImageDownloader::MAX_BYTES, Kaleta\Core\ImageDownloader::MAX_REDIRECTS, Kaleta\Core\ImageDownloader::CONNECT_TIMEOUT, Kaleta\Core\ImageDownloader::TOTAL_TIMEOUT], [15 * 1024 * 1024, 3, 5, 20]);
$wpSourceHtml = (string) file_get_contents(KALETA_ROOT . '/system/src/Core/ImageDownloader.php');
check('StahovaniObrazku: přesměrování se nikdy nenásledují automaticky a nic se neposílá navíc', [substr_count($wpSourceHtml, 'CURLOPT_FOLLOWLOCATION => false'), str_contains($wpSourceHtml, "'follow_location' => 0"), (bool) preg_match('/CURLOPT_(COOKIE\w*|USERPWD|HTTPHEADER|HTTPAUTH)\b/', $wpSourceHtml), str_contains($wpSourceHtml, "'Kaleta-import'")], [1, true, false, true]);

/* ---------- builder: validator, style, design system, library ---------- */
[$buildS, $buildErrors] = Kaleta\Builder\Build::sanitize(['deti' => [
    ['typ' => 'nadpis', 'id' => 'abc', 'obsah' => ['text' => '<script>x</script>Ahoj <b>světe</b>']],
    ['typ' => 'neznamy'],
    ['typ' => 'html', 'obsah' => ['kod' => '<p>a</p>']],
    ['typ' => 'tlacitko', 'obsah' => ['odkaz' => 'javascript:alert(1)']],
    ['typ' => 'nadpis', 'id' => 'abc', 'deti' => [['typ' => 'text']]],
]], false);
check('Stavba::vycisti: skript z nadpisu pryč, tučné zůstane', $buildS['deti'][0]['obsah']['text'], 'Ahoj <b>světe</b>');
check('Stavba::vycisti: neznámý typ, cizí HTML a vnořené děti nadpisu vypadnou', array_map(fn (array $p): string => $p['typ'], $buildS['deti']), ['nadpis', 'tlacitko', 'nadpis']);
check('Stavba::vycisti: javascript: odkaz se zahodí a nahlásí', [$buildS['deti'][1]['obsah']['odkaz'], isset($buildErrors['deti[3].obsah.odkaz'])], ['', true]);
check('Stavba::vycisti: duplicitní id dostane nové', $buildS['deti'][2]['id'] !== 'abc', true);
check('Stavba::vycisti: chyby mají cestu', array_keys($buildErrors), ['deti[1]', 'deti[2]', 'deti[3].obsah.odkaz', 'deti[4].deti']);
$buildHtml = ['deti' => [['typ' => 'html', 'id' => 'h1x', 'obsah' => ['kod' => '<p onclick="x()">a</p><script>1</script><a href="javascript:x">b</a>']]]];
[$buildAdmin] = Kaleta\Builder\Build::sanitize($buildHtml, true);
check('Stavba::vycisti: vlastní HTML správce bez skriptů a obsluh', $buildAdmin['deti'][0]['obsah']['kod'], '<p>a</p><a>b</a>');
[$buildEditor] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'html', 'id' => 'h1x', 'obsah' => ['kod' => '<p>podvrh</p>']]]], false, $buildAdmin);
check('Stavba::vycisti: editor nezmění vlastní HTML správce, jen ho ponechá', $buildEditor['deti'][0]['obsah']['kod'], '<p>a</p><a>b</a>');
$buildDeep = ['typ' => 'text'];
for ($i = 0; $i < 20; $i++) {
    $buildDeep = ['typ' => 'kontejner', 'deti' => [$buildDeep]];
}
[, $buildErrors] = Kaleta\Builder\Build::sanitize(['deti' => [$buildDeep]]);
check('Stavba::vycisti: hloubka je omezená', count($buildErrors), 1);
[$buildMany, $buildErrors] = Kaleta\Builder\Build::sanitize(['deti' => array_fill(0, 900, ['typ' => 'oddelovac'])]);
check('Stavba::vycisti: počet prvků je omezený', [count($buildMany['deti']), count($buildErrors)], [Kaleta\Builder\Build::MAX_ELEMENTS, 1]);
check('Stavba::vycisti: obrázek jen z Médií nebo https', Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'obrazek', 'obsah' => ['src' => 'http://x.cz/a.jpg']], ['typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/a.jpg']]]])[0]['deti'][1]['obsah']['src'], 'media/2026/a.jpg');

/* ---------- 2.7: display conditions – language versions and a URL parameter ---------- */
$conditionErrors = [];
check('2.7: conditions – languages and a URL parameter are kept, unknown and unsafe values dropped with a message', [
    Kaleta\Builder\Build::sanitizeConditions(['prihlaseni' => 'ne', 'jazyky' => ['', 'de', 'de', 'xx', 7], 'parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']], 'p', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => 'varianta'], 'p', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => ['nazev' => 'a b', 'hodnota' => 'x']], 'p2', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => ['nazev' => 'v', 'hodnota' => 'x"y']], 'p3', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['parametr' => ['nazev' => '', 'hodnota' => '']], 'p4', $conditionErrors),
    Kaleta\Builder\Build::sanitizeConditions(['jazyky' => 'de'], 'p5', $conditionErrors),
    array_keys($conditionErrors),
], [
    ['prihlaseni' => 'ne', 'jazyky' => ['', 'de'], 'parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']],
    ['parametr' => ['nazev' => 'varianta']], [], [], [], [],
    ['p', 'p2', 'p3'],
]);
check('2.7: old builds without the new conditions sanitize as before', Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'nadpis', 'id' => 'c1', 'podminky' => ['prihlaseni' => 'ano', 'od' => '2026-01-01']]]])[0]['deti'][0]['podminky'], ['prihlaseni' => 'ano', 'od' => '2026-01-01']);
$conditionApp = new Kaleta\Core\App([], new Kaleta\Core\Request(['utm_campaign' => 'jaro', 'varianta' => ''], [], []));
$conditionContext = new Kaleta\Builder\Context($conditionApp);
$conditionApp->languagePrefix = 'de';
check('2.7: meetsConditions – language version of the visit', [
    Kaleta\Builder\Build::meetsConditions(['jazyky' => ['de']], $conditionContext), Kaleta\Builder\Build::meetsConditions(['jazyky' => ['', 'en']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['jazyky' => ['']], (function () use ($conditionApp) { $conditionApp->languagePrefix = ''; return new Kaleta\Builder\Context($conditionApp); })()),
], [true, false, true]);
check('2.7: meetsConditions – URL parameter present, with and without an exact value', [
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'leto']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'varianta']], $conditionContext), // ?varianta without a value counts as present
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'chybi']], $conditionContext),
    Kaleta\Builder\Build::meetsConditions(['parametr' => ['nazev' => 'utm_campaign'], 'jazyky' => ['de'], 'od' => '2000-01-01'], $conditionContext), // all must hold
], [true, true, false, true, false, false]);
check('2.7: only a language condition keeps the page in the cache (language versions have their own addresses)', [
    Kaleta\Builder\Build::conditionsBypassCache(['jazyky' => ['de']]), Kaleta\Builder\Build::conditionsBypassCache(['parametr' => ['nazev' => 'utm_campaign']]),
    Kaleta\Builder\Build::conditionsBypassCache(['jazyky' => [''], 'od' => '2026-01-01']), Kaleta\Builder\Build::conditionsBypassCache(['prihlaseni' => 'ne']),
], [false, true, true, true]);
$conditionsCs = ['prihlaseni' => 'ne', 'od' => '2026-03-01', 'jazyky' => ['', 'de'], 'parametr' => ['nazev' => 'utm_campaign', 'hodnota' => 'jaro']];
$conditionsEn = Kaleta\Mcp\Vocabulary::elementToEnglish(['typ' => 'sekce', 'podminky' => $conditionsCs])['conditions'];
check('2.7: Vocabulary – conditions in English and back', [$conditionsEn, Kaleta\Mcp\Vocabulary::elementToCzech(['type' => 'section', 'conditions' => $conditionsEn])['podminky'],
    Kaleta\Mcp\Vocabulary::elementToCzech(['type' => 'section', 'conditions' => ['url_parameter' => 'varianta']])['podminky']],
    [['signed_in' => 'no', 'from' => '2026-03-01', 'languages' => ['', 'de'], 'url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']], $conditionsCs, ['parametr' => 'varianta']]);

/* ---------- 2.7: copy and paste between Kaleta sites (Builder\ElementClipboard) ---------- */
$clipboardElements = [['id' => 'abc1234', 'typ' => 'sekce', 'kotva' => 'cenik', 'tridy' => ['karta'], 'deti' => [
    ['id' => 'def5678', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/foto.jpg', 'alt' => 'x']],
    ['id' => 'ghi9012', 'typ' => 'text', 'obsah' => ['html' => '<p><img src="/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>'], 'styl' => ['zaklad' => ['obrazek_pozadi' => 'media/bg.webp']]],
    ['id' => 'jkl3456', 'typ' => 'komponenta', 'obsah' => ['komponenta' => '7', 'hodnoty' => []]],
]]];
$clipboardEnvelope = ['kaleta' => 'elements', 'v' => 1, 'site' => 'https://Zdroj.example/', 'elements' => $clipboardElements, 'classes' => [['nazev' => 'karta', 'styl' => [], 'css' => '']], 'components' => [['id' => 7, 'nazev' => 'K', 'vlastnosti' => [], 'stavba' => ['v' => 1, 'deti' => []]]]];
$clipboardParsed = Kaleta\Builder\ElementClipboard::parse($clipboardEnvelope);
check('2.7: clipboard envelope – checked and normalised', [$clipboardParsed['site'], count($clipboardParsed['prvky']), count($clipboardParsed['tridy']), count($clipboardParsed['komponenty'])], ['https://zdroj.example', 1, 1, 1]);
check('2.7: clipboard envelope – anything else is refused', [
    Kaleta\Builder\ElementClipboard::parse('text'), Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'page', 'v' => 1, 'elements' => $clipboardElements]),
    Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 2, 'elements' => $clipboardElements]), Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 1, 'elements' => []]),
    Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 1, 'elements' => ['x', 1]]), Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 1, 'elements' => $clipboardElements, 'site' => 'javascript:x'])['site'],
], [null, null, null, null, null, '']);
$clipboardFresh = Kaleta\Builder\ElementClipboard::fresh($clipboardElements);
check('2.7: pasted elements lose their ids and anchors (new ids come from sanitize)', [isset($clipboardFresh[0]['id']), isset($clipboardFresh[0]['kotva']), isset($clipboardFresh[0]['deti'][1]['id']), $clipboardFresh[0]['deti'][1]['typ']], [false, false, false, 'text']);
$clipboardImages = 0;
$clipboardHttps = Kaleta\Builder\ElementClipboard::relinkMedia($clipboardElements, 'https://zdroj.example', $clipboardImages);
check('2.7: media of an https site are pointed at it and counted', [$clipboardImages, $clipboardHttps[0]['deti'][0]['obsah']['src'], $clipboardHttps[0]['deti'][1]['obsah']['html'], $clipboardHttps[0]['deti'][1]['styl']['zaklad']['obrazek_pozadi']],
    [3, 'https://zdroj.example/media/2026/foto.jpg', '<p><img src="https://zdroj.example/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', 'https://zdroj.example/media/bg.webp']);
$clipboardImages = 0;
$clipboardHttp = Kaleta\Builder\ElementClipboard::relinkMedia($clipboardElements, 'http://zdroj.example', $clipboardImages);
check('2.7: media of an http site are left out and counted', [$clipboardImages, $clipboardHttp[0]['deti'][0]['obsah']['src'], $clipboardHttp[0]['deti'][1]['obsah']['html'], $clipboardHttp[0]['deti'][1]['styl']['zaklad']['obrazek_pozadi']],
    [3, '', '<p><img src="" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', '']);
check('2.7: relinked media pass the build validator', Kaleta\Builder\Build::sanitize(['deti' => $clipboardHttps])[0]['deti'][0]['deti'][0]['obsah']['src'], 'https://zdroj.example/media/2026/foto.jpg');
check('Stavba::zTextu: nadpis h1 a text', array_map(fn (array $p): string => $p['znacka'], Kaleta\Builder\Build::fromText('O nás', '<p>x</p>')['deti'][0]['deti']), ['h1', 'div']);
$buildStyleErrors = [];
check('Styl::vycisti: vloženo CSS, neznámá vlastnost a stav vypadnou', Kaleta\Builder\Style::sanitize(['zaklad' => ['barva' => 'red;}body{x:y', 'neznama' => '1', 'sirka' => '50%'], 'tisk' => []], 's', $buildStyleErrors), ['zaklad' => ['sirka' => '50%']]);
check('Styl::vycisti: chyby', array_keys($buildStyleErrors), ['s.zaklad.barva', 's.zaklad.neznama', 's.tisk']);
check('Styl::css: tokeny, sloupce, hover a breakpoint', Kaleta\Builder\Style::css('#s-a', ['zaklad' => ['odsazeni_y' => 'xl', 'barva' => 'primarni', 'sloupce' => '3'], 'mobil' => ['sloupce' => '1'], 'hover' => ['barva' => '#ff0000']]),
    "#s-a { padding-block: var(--ka-mezera-xl); color: var(--ka-barva-primarni); grid-template-columns: repeat(3, minmax(0, 1fr)); }\n#s-a:is(:hover, :focus-visible) { color: #ff0000; }\n@media (max-width: 767px) { #s-a { grid-template-columns: repeat(1, minmax(0, 1fr)); } }\n");
check('Html::bezpecne: bez skriptů, obsluh událostí a javascript:, se strukturou a třídami', Kaleta\Core\Html::safe('<p class="x" onclick="a()">A <a href="javascript:alert(1)">b</a><img src="x" onerror="alert(1)"><script>alert(1)</script></p><iframe src="https://x"></iframe><a href="/k" target="_blank" data-vlozit="javascript:x">k</a>'),
    '<p class="x">A <a>b</a><img src="x"></p><a href="/k" target="_blank" rel="noopener">k</a>');
check('Stavba: háčky skriptů webu nejdou vložit jako vlastní atribut', [preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-vlozit'), preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-samo'), preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-sledovat')], [0, 0, 1]);
check('Styl::css: najetí a stisk zvlášť pro tablet a mobil', Kaleta\Builder\Style::css('#x', ['mobil' => ['mezera' => 's'], 'hover_mobil' => ['barva' => 'primarni'], 'aktivni_tablet' => ['meritko' => '0.95']]),
    "@media (max-width: 1023px) { #x:active { scale: 0.95; } }\n@media (max-width: 767px) { #x { gap: var(--ka-mezera-s); } #x:is(:hover, :focus-visible) { color: var(--ka-barva-primarni); } }\n");
check('Styl::css: typografický styl první, jednotlivé vlastnosti ho doladí', Kaleta\Builder\Style::css('#x', ['zaklad' => ['velikost_pisma' => '3', 'typ_styl' => 'nadtitulek']]),
    "#x { font: var(--ka-typ-nadtitulek); text-transform: uppercase; letter-spacing: 0.08em; font-size: var(--ka-krok-3); }\n");
check('Styl: vlastní stín a rámeček s tokeny barev', [Kaleta\Builder\Style::value('stin', '0 8px 24px 0 primarni'), Kaleta\Builder\Style::value('stin', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)'), Kaleta\Builder\Style::value('ramecek', '2px dashed primarni')],
    ['0 8px 24px 0 var(--ka-barva-primarni)', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)', '2px dashed var(--ka-barva-primarni)']);
check('Styl: stín a rámeček nepustí nic nebezpečného', [Kaleta\Builder\Style::value('stin', '0 0 1px url(x)'), Kaleta\Builder\Style::value('stin', '0 0 red; color: red'), Kaleta\Builder\Style::value('ramecek', '1px solid red}')], [null, null, null]);
check('Styl: mřížka – řádky, oblasti a oblast prvku', [Kaleta\Builder\Style::value('radky', '3'), Kaleta\Builder\Style::value('oblasti', 'hlava hlava / bok obsah'), Kaleta\Builder\Style::value('oblasti', 'a b / c'), Kaleta\Builder\Style::value('oblast', 'bok'), Kaleta\Builder\Style::value('oblast', 'x"y')],
    ['repeat(3, auto)', '"hlava hlava" "bok obsah"', null, 'bok', null]);
check('DesignSystem: typografické styly jako tokeny, úprava ve Vzhledu', [str_contains(Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::sanitize([])), '--ka-typ-perex: 400 var(--ka-krok-1)/1.55 var(--ka-pismo-text);'),
    str_contains(Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::sanitize(['typografie' => ['perex' => ['krok' => '2', 'tloustka' => '500'], 'titulek' => ['krok' => '99']]])), '--ka-typ-perex: 500 var(--ka-krok-2)/1.55'),
    Kaleta\Builder\DesignSystem::sanitize(['typografie' => ['titulek' => ['krok' => '99']]])['typografie']], [true, true, []]);
check('Styl::css: obrázek pozadí z Médií od kořene instalace', str_contains(Kaleta\Builder\Style::css('#s', ['zaklad' => ['obrazek_pozadi' => 'media/2026/09/a.jpg']], '', '/web'), 'url("/web/media/2026/09/a.jpg")'), true);
$takenSlugs = ['o-nas' => 1, 'o-nas-2' => 1, str_repeat('a', 10) => 1];
check('Volná adresa: číslo za obsazenou, s číslem se vejde do sloupce', [
    Kaleta\Core\Slug::makeUnique('o-nas', fn (string $a): bool => isset($takenSlugs[$a])),
    Kaleta\Core\Slug::makeUnique('sluzby', fn (string $a): bool => isset($takenSlugs[$a])),
    Kaleta\Core\Slug::makeUnique(str_repeat('a', 12), fn (string $a): bool => isset($takenSlugs[$a]), 10),
], ['o-nas-3', 'sluzby', 'aaaaaaaa-2']);
check('Kontejner jako odkaz: odkazy uvnitř se změní na span', Kaleta\Builder\Elements\Container::render(['znacka' => 'div', 'obsah' => ['odkaz' => '/k']], '', '<p>x</p><a class="ka-tlacitko" href="/y" target="_blank">B</a><abbr>z</abbr>', new Kaleta\Builder\Context((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor())),
    '<a class="ka-karta-odkaz" href="/k"><p>x</p><span class="ka-tlacitko">B</span><abbr>z</abbr></a>');
check('Menu::vycisti: neznámý typ, nebezpečná adresa a třetí úroveň vypadnou', Kaleta\Core\Menu::sanitize([
    ['typ' => 'skript'], ['typ' => 'odkaz', 'text' => 'X', 'url' => 'javascript:alert(1)'],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'ids' => 3, 'deti' => [['typ' => 'novinky']]], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => 1]]],
]), [['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'text' => '', 'ids' => 3], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => true]]]]);
check('Menu::html: podmenu, aktivní položka a větev, úvod jen přesnou shodou', Kaleta\Core\Menu::html([
    ['text' => 'Úvod', 'url' => '/', 'nove_okno' => false, 'deti' => []],
    ['text' => 'Služby', 'url' => '', 'nove_okno' => false, 'deti' => [['text' => 'Kuchyně', 'url' => '/kuchyne', 'nove_okno' => false, 'deti' => []]]],
], '/kuchyne/detail', '/'), '<li><a href="/">Úvod</a></li><li class="podmenu aktivni"><button type="button" class="menu-skupina">Služby</button><ul><li><a href="/kuchyne" aria-current="page">Kuchyně</a></li></ul></li>');
// 2.7: icons and descriptions of menu items, a group inside a submenu with its own items (a column of the mega menu)
check('Menu::sanitize: ikona jen ze sady, popis bez značek do 120 znaků, skupina v podmenu smí mít položky, stránka v podmenu ne', Kaleta\Core\Menu::sanitize([
    ['typ' => 'odkaz', 'text' => 'Kontakt', 'url' => '/kontakt', 'ikona' => 'telefon', 'popis' => ' <b>Zavolejte</b> nám ' . str_repeat('x', 130)],
    ['typ' => 'novinky', 'ikona' => 'neexistuje', 'popis' => ['pole']],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [
        ['typ' => 'skupina', 'text' => 'Kuchyně', 'ikona' => 'dum', 'deti' => [['typ' => 'stranka', 'ids' => 3, 'deti' => [['typ' => 'novinky']]]]],
        ['typ' => 'stranka', 'ids' => 4, 'deti' => [['typ' => 'novinky']]],
    ]],
]), [
    ['typ' => 'odkaz', 'text' => 'Kontakt', 'ikona' => 'telefon', 'popis' => 'Zavolejte nám ' . str_repeat('x', 106), 'url' => '/kontakt', 'nove_okno' => false],
    ['typ' => 'novinky', 'text' => ''],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [
        ['typ' => 'skupina', 'text' => 'Kuchyně', 'ikona' => 'dum', 'deti' => [['typ' => 'stranka', 'text' => '', 'ids' => 3]]],
        ['typ' => 'stranka', 'text' => '', 'ids' => 4],
    ]],
]);
$menuWithColumns = [
    ['text' => 'Kontakt', 'url' => '/kontakt', 'nove_okno' => false, 'deti' => [], 'ikona' => 'telefon', 'popis' => 'Nahoře se popis neukáže'],
    ['text' => 'Služby', 'url' => '', 'nove_okno' => false, 'deti' => [
        ['text' => 'Kuchyně', 'url' => '', 'nove_okno' => false, 'deti' => [['text' => 'Na míru', 'url' => '/na-miru', 'nove_okno' => false, 'deti' => [], 'popis' => 'Podle vašich <rozměrů>']], 'ikona' => 'dum'],
        ['text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => false, 'deti' => [], 'popis' => 'Orientační ceny'],
    ]],
];
$menuSvg = fn (string $key): string => Kaleta\Builder\Icons::svg($key, 'menu-ikona');
check('Menu::html: ikona před textem, skupina v podmenu jako sloupec s nadpisem, popis jen v mega menu pod položkami podmenu', Kaleta\Core\Menu::html($menuWithColumns, '/na-miru', '/', true),
    '<li><a href="/kontakt">' . $menuSvg('telefon') . 'Kontakt</a></li><li class="podmenu aktivni"><button type="button" class="menu-skupina">Služby</button><ul>'
    . '<li class="menu-sloupec"><span class="menu-nadpis">' . $menuSvg('dum') . 'Kuchyně</span><ul><li><a href="/na-miru" aria-current="page">Na míru<small class="menu-popis">Podle vašich &lt;rozměrů&gt;</small></a></li></ul></li>'
    . '<li><a href="/cenik">Ceník<small class="menu-popis">Orientační ceny</small></a></li></ul></li>');
check('Menu::html: bez mega menu zůstane sloupec, popisy se nevypisují', [str_contains(Kaleta\Core\Menu::html($menuWithColumns, '/', '/'), 'menu-popis'), str_contains(Kaleta\Core\Menu::html($menuWithColumns, '/', '/'), '<li class="menu-sloupec"><span class="menu-nadpis">')], [false, true]);
check('Menu::flatten: všechny úrovně v pořadí', array_column(Kaleta\Core\Menu::flatten($menuWithColumns), 'text'), ['Kontakt', 'Služby', 'Kuchyně', 'Na míru', 'Ceník']);
check('MCP anglicky: ikona a popis položky menu tam i zpět', [
    Kaleta\Mcp\Translator::arguments('save_menu', ['location' => 'main', 'items' => [['type' => 'group', 'text' => 'S', 'icon' => 'phone', 'description' => 'D', 'children' => [['type' => 'link', 'url' => '/x', 'icon' => 'dum']]]]])['polozky'],
    Kaleta\Mcp\Translator::result('get_menu', ['umisteni' => 'hlavni', 'polozky' => [['typ' => 'odkaz', 'text' => 'K', 'url' => '/k', 'ikona' => 'telefon', 'popis' => 'D']], 'na_webu' => []])['items'],
], [
    [['typ' => 'skupina', 'text' => 'S', 'ikona' => 'telefon', 'popis' => 'D', 'deti' => [['typ' => 'odkaz', 'url' => '/x', 'ikona' => 'dum']]]],
    [['type' => 'link', 'text' => 'K', 'url' => '/k', 'icon' => 'phone', 'description' => 'D']],
]);
$navMegaCss = Kaleta\Builder\Elements\Navigation::baseCss();
check('Navigace: styl ikony, sloupce a popisu menu; popis se na telefonu skryje', [str_contains($navMegaCss, '.ka-nav .menu-ikona {'), str_contains($navMegaCss, '.ka-nav .menu-sloupec > ul {'), str_contains($navMegaCss, '.ka-nav .menu-nadpis {'),
    (bool) preg_match('/@media \(max-width: 767px\).*?\.ka-nav-menu\[popover\] \.menu-popis \{ display: none; \}/s', $navMegaCss)], [true, true, true, true]);
// 2.7: the header that is transparent at the top (and/or smaller after scrolling) – only in the header site part, CSS only when used
$headerApp = new Kaleta\Core\App([]);
$headerBuild = ['v' => 1, 'deti' => [['id' => 'hl1', 'typ' => 'sekce', 'znacka' => 'header', 'obsah' => ['pri_rolovani' => 'pruhledna-zmensit', 'text_nahore' => 'svetly'], 'styl' => ['zaklad' => ['pozice' => 'sticky', 'pozadi' => 'pozadi']], 'deti' => []]]];
[$headerBuild] = Kaleta\Builder\Build::sanitize($headerBuild);
$headerContext = new Kaleta\Builder\Context($headerApp);
$headerContext->source = 'cast:hlavicka:';
$headerHtml = Kaleta\Builder\Build::html($headerBuild, $headerContext);
$headerCss = Kaleta\Builder\Build::css((new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(), $headerContext);
check('Sekce při rolování: třídy záhlaví, fixní pozice až za stylem (sticky), animace podle posuvu, světlý text nahoře', [
    (bool) preg_match('/<header id="s-hl1" class="ka-hlavicka-rolovani ka-hlavicka-rolovani--pruhledna">/', $headerHtml),
    (bool) preg_match('/#s-hl1 \{ [^}]*position: sticky;[^}]*background-color: var\(--ka-barva-pozadi\); position: fixed; top: 0; inset-inline: 0; animation: ka-hlavicka-svetla linear both, ka-hlavicka-mensi linear both; animation-timeline: scroll\(root\); animation-range: 0 120px; \}/', $headerContext->css),
    str_contains($headerCss, '@keyframes ka-hlavicka-svetla'), str_contains($headerCss, '@keyframes ka-hlavicka-mensi { to { padding-block:'), str_contains($headerCss, 'prefers-reduced-motion: reduce) { .ka-hlavicka-rolovani { animation: none !important; } }'),
], [true, true, true, true, true]);
$pageContext = new Kaleta\Builder\Context($headerApp);
$pageContext->source = 'stranka:5';
check('Sekce při rolování: mimo záhlaví se neprojeví a CSS se nevypíše', [str_contains(Kaleta\Builder\Build::html($headerBuild, $pageContext), 'ka-hlavicka'), str_contains($pageContext->css, 'animation'),
    str_contains(Kaleta\Builder\Build::css((new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(), $pageContext), 'ka-hlavicka')], [false, false, false]);
$shrinkOnly = ['v' => 1, 'deti' => [['id' => 'hl2', 'typ' => 'sekce', 'obsah' => ['pri_rolovani' => 'zmensit'], 'deti' => []]]];
$shrinkContext = new Kaleta\Builder\Context($headerApp);
$shrinkContext->source = 'cast:hlavicka:kampan';
check('Sekce při rolování: jen zmenšení nechá záhlaví v toku (žádné position: fixed), prvek bez stylu přesto dostane id a pravidlo', [
    str_contains(Kaleta\Builder\Build::html(Kaleta\Builder\Build::sanitize($shrinkOnly)[0], $shrinkContext), '<section id="s-hl2" class="ka-hlavicka-rolovani">'),
    str_contains($shrinkContext->css, 'position: fixed'), str_contains($shrinkContext->css, '#s-hl2 { animation: ka-hlavicka-mensi linear both;'),
], [true, false, true]);
check('Vocabulary: záhlaví při rolování anglicky', Kaleta\Mcp\Vocabulary::contentToEnglish('sekce', ['pri_rolovani' => 'pruhledna-zmensit', 'text_nahore' => 'svetly']), ['on_scroll' => 'transparent_shrink', 'text_at_top' => 'light']);
check('Hledani::najdi: shoda v názvu má přednost', array_column(Kaleta\Core\Search::find('search', [
    ['titulek' => 'Menus', 'adresa' => 'menus', 'text' => 'Link to site search from the menu.'],
    ['titulek' => 'Site search', 'adresa' => 'site-search', 'text' => 'How search works.'],
    ['titulek' => 'SEO', 'adresa' => 'seo', 'text' => 'Search engines and search results; search console.'],
]), 'adresa'), ['site-search', 'seo', 'menus']);
check('Hledani::najdi: bez diakritiky, všechna slova, úryvek', Kaleta\Core\Search::find('zkusenosti kuchyne', [
    ['titulek' => 'O nás', 'adresa' => 'o-nas', 'text' => '<p>Máme dvacet let zkušeností s nábytkem.</p>'],
    ['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'text' => '<p>Kuchyně na míru – bohaté zkušenosti.</p>'],
]), [['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'uryvek' => 'Kuchyně na míru – bohaté zkušenosti.']]);
check('Styl::css: bílé pozadí si nese tmavý text i v tmavém režimu', str_contains(Kaleta\Builder\Style::css('#s', ['zaklad' => ['pozadi' => 'bila']]), '--ka-barva-text: var(--ka-barva-text-svetle); color: var(--ka-barva-text-svetle)'), true);
check('Styl::css: vlastní barva textu na bílém pozadí se nepřepíše', str_contains(Kaleta\Builder\Style::css('#s', ['zaklad' => ['pozadi' => 'bila', 'barva' => 'primarni']]), 'text-svetle'), false);
$buildDiscarded = [];
check('Styl::vlastniCss: jen bezpečné deklarace', Kaleta\Builder\Style::customCss('color:red; background:url(javascript:x); --ka-x: 1; @import url(x); width: expression(1); a{b:c}', $buildDiscarded), 'color: red; --ka-x: 1;');
check('Styl::vlastniCss: zahozené se hlásí', count($buildDiscarded), 4);
check('DesignSystem::kontrast: černá na bílé', round(Kaleta\Builder\DesignSystem::contrast('#ffffff', '#000000'), 1), 21.0);
// 2.1: every stored token has an English name that reads it again on styled elements (follows a token overridden in a class)
$dsCss = Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::DEFAULTS);
preg_match_all('/^\t(--ka-[a-z0-9-]+):/m', substr($dsCss, 0, (int) strpos($dsCss, '@media')), $dsStored);
check('2.1: English token names cover every stored token', [array_values(array_diff($dsStored[1], Kaleta\Builder\DesignSystem::englishTokens(), ['--ka-akcent'])),
    str_contains($dsCss, ":where(:root, [class], [id], [style]) {\n\t--ka-color-primary: var(--ka-barva-primarni);"), str_contains($dsCss, '--ka-type-lead: var(--ka-typ-perex);'),
    count(array_unique(array_keys(Kaleta\Builder\DesignSystem::englishTokens()))) === count(array_unique(Kaleta\Builder\DesignSystem::englishTokens()))], [[], true, true, true]);
// 2.1: a page without its own description gets the start of its first longer paragraph
check('2.1: description from the first longer paragraph', [Kaleta\Front\Kernel::descriptionFrom('<h1>Hi</h1><p>Short.</p><p class="x">We build <strong>kitchens</strong> &amp; bathrooms in Zlín   and around it since 1998.</p><p>Later text that is long enough to be picked but comes second.</p>'),
    Kaleta\Front\Kernel::descriptionFrom('<p>tiny</p>'), mb_strlen(Kaleta\Front\Kernel::descriptionFrom('<p>' . str_repeat('word ', 80) . '</p>'))],
    ['We build kitchens & bathrooms in Zlín and around it since 1998.', '', 160]);
// 2.1: security.txt (RFC 9116) from the Security contact setting
check('2.1: security.txt', [Kaleta\Front\Seo::securityTxt('security@example.com', 'https://example.com/', ['en', 'de'], 1790000000),
    Kaleta\Front\Seo::securityTxt('https://example.com/security', 'https://example.com/web', [], 1790000000)],
    ["Contact: mailto:security@example.com\nExpires: 2027-03-23T00:00:00Z\nPreferred-Languages: en, de\nCanonical: https://example.com/.well-known/security.txt\n",
    "Contact: https://example.com/security\nExpires: 2027-03-23T00:00:00Z\nCanonical: https://example.com/web/.well-known/security.txt\n"]);
check('DesignSystem::css: pořadí vrstev na začátku', str_starts_with(Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::DEFAULTS), Kaleta\Builder\DesignSystem::LAYERS), true);
check('DesignSystem::vycisti: nesmysl nahradí výchozí', Kaleta\Builder\DesignSystem::sanitize(['barvy' => ['primarni' => 'red;}']])['barvy']['primarni'], Kaleta\Builder\DesignSystem::DEFAULTS['barvy']['primarni']);
$buildLibraryErrors = [];
// raw builds (section() already cleans them, so an invalid value would disappear silently)
foreach ((new ReflectionMethod(Kaleta\Builder\Library::class, 'sections'))->invoke(null) as $buildKey => $buildSection) {
    $buildSection['klic'] = $buildKey;
    [, $buildErrors] = Kaleta\Builder\Build::sanitize(['deti' => [($buildSection['stavba'])()]]);
    $buildLibraryErrors += array_map(fn (string $c): string => $buildSection['klic'] . ': ' . $c, $buildErrors);
}
check('Knihovna: všechny hotové sekce projdou validátorem', $buildLibraryErrors, []);
check('Stavba::schema: bez vlastního HTML pro ne-správce', in_array('html', array_column(Kaleta\Builder\Build::schema(false)['prvky'], 'typ'), true), false);

$fromHtml = Kaleta\Builder\HtmlConverter::convert('<style>.hero { padding: 2rem; background: url(x) } .hero h1 { color: red } @media (max-width: 9px) { .hero { padding: 0 } }</style>'
    . '<header class="hero container-x"><div class="wrap"><h1>A <em>b</em></h1><p>Jedna.</p><p>Dvě.</p><a class="btn btn-outline" href="/k">K</a></div></header>'
    . '<p>Volný text</p><details><summary>Otázka?</summary><p>Odpověď.</p></details><form></form><svg></svg><script>x</script>');
$fromHtmlTypes = fn (array $children): array => array_map(fn (array $p): string => $p['typ'] . '<' . $p['znacka'] . '>', $children);
check('ZHtml: sekce z <header>, vnitřní obal bez stylu odpadne', $fromHtmlTypes($fromHtml['stavba']['deti'][0]['deti']), ['nadpis<h1>', 'text<div>', 'tlacitko<a>']);
check('ZHtml: souvislé odstavce v jednom prvku Text', $fromHtml['stavba']['deti'][0]['deti'][1]['obsah']['html'], '<p>Jedna.</p><p>Dvě.</p>');
check('ZHtml: tlačítko s variantou podle třídy', [$fromHtml['stavba']['deti'][0]['deti'][2]['obsah']['varianta'], $fromHtml['stavba']['deti'][0]['deti'][2]['tridy']], ['obrys', ['btn', 'btn-outline']]);
check('ZHtml: volné prvky na konci se zabalí do sekce, details → FAQ, form → Formulář', $fromHtmlTypes($fromHtml['stavba']['deti'][1]['deti']), ['text<div>', 'faq<div>', 'formular<form>']);
check('ZHtml: třída z <style> jen s bezpečnými deklaracemi', $fromHtml['tridy'], ['hero' => 'padding: 2rem;']);
check('ZHtml: hlášení o @media, složitém selektoru, url(), formuláři, SVG a skriptu', count($fromHtml['hlaseni']), 6);
$fromHtml2 = Kaleta\Builder\HtmlConverter::convert('<style>.mriz { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .karta:hover { box-shadow: var(--ka-stin-m); transform: translateY(-4px) }'
    . ' @media (max-width: 1023px) { .mriz { grid-template-columns: repeat(2, 1fr) } } @media (max-width: 767px) { .mriz { grid-template-columns: 1fr; gap: var(--ka-mezera-m) } .karta { padding: var(--ka-mezera-m) var(--ka-mezera-s) } }'
    . ' @media (min-width: 768px) { .mriz { gap: 0 } }</style><section><div class="mriz"><div class="karta"><h3>A</h3></div><div>B</div></div></section>');
check('ZHtml: @media (max-width) a :hover jako stavy třídy', $fromHtml2['tridy_styl'], ['mriz' => ['tablet' => ['sloupce' => '2'], 'mobil' => ['sloupce' => '1', 'mezera' => 'm']],
    'karta' => ['mobil' => ['odsazeni_y' => 'm', 'odsazeni_x' => 's'], 'hover' => ['stin' => 'm', 'posun' => '0 -4px']]]);
check('ZHtml: prvek se stylovanou třídou nemá výchozí styl (přebil by třídu), bez třídy ho má', [$fromHtml2['stavba']['deti'][0]['deti'][0]['styl'], $fromHtml2['stavba']['deti'][0]['deti'][0]['deti'][1]['styl']['zaklad']['zobrazeni'] ?? null], [[], 'flex']);
check('ZHtml: mobile-first @media (min-width) se nahlásí', count(array_filter($fromHtml2['hlaseni'], fn (string $h): bool => str_contains($h, 'min-width'))), 1);
check('Styl::zCss: tokeny, zkratky a mřížka', [Kaleta\Builder\Style::fromCss('padding', 'var(--ka-mezera-l) 2rem'), Kaleta\Builder\Style::fromCss('margin', '0 auto'), Kaleta\Builder\Style::fromCss('grid-template-columns', 'repeat(auto-fit, minmax(16rem, 1fr))'),
    Kaleta\Builder\Style::fromCss('color', 'var(--ka-barva-tlumeny)'), Kaleta\Builder\Style::fromCss('font-size', 'var(--ka-krok--1)'), Kaleta\Builder\Style::fromCss('color', 'expression(1)'), Kaleta\Builder\Style::fromCss('filter', 'blur(2px)')],
    [['odsazeni_y' => 'l', 'odsazeni_x' => '2rem'], ['okraj_nahore' => '0', 'okraj_dole' => '0', 'na_stred' => 'auto'], ['sloupce' => 'auto:16rem'], ['barva' => 'tlumeny'], ['velikost_pisma' => '-1'], null, null]);

$editBuild = ['v' => 1, 'deti' => [['id' => 'sek1', 'typ' => 'sekce', 'deti' => [['id' => 'nad1', 'typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['zaklad' => ['barva' => 'primarni']]], ['id' => 'tl1', 'typ' => 'tlacitko', 'obsah' => ['text' => 'B', 'odkaz' => '/docs']]]], ['id' => 'sek2', 'typ' => 'sekce']]];
$editErrors = [];
$edit = Kaleta\Builder\Edits::apply($editBuild, [
    ['op' => 'uprav', 'id' => 'tl1', 'obsah' => ['odkaz' => '/guide']],
    ['op' => 'uprav', 'id' => 'nad1', 'styl' => ['zaklad' => ['barva' => null], 'mobil' => ['zarovnani_textu' => 'center']], 'tridy' => ['nadpis-sekce']],
    ['op' => 'vloz', 'do' => 'sek2', 'prvky' => [['id' => 'txt1', 'typ' => 'text']]],
    ['op' => 'presun', 'id' => 'tl1', 'za' => 'txt1'],
    ['op' => 'vloz', 'pozice' => 0, 'prvek' => ['id' => 'sek0', 'typ' => 'sekce']],
    ['op' => 'smaz', 'id' => 'neni'],
    ['op' => 'presun', 'id' => 'sek2', 'do' => 'txt1'],
    ['op' => 'kouzlo'],
], $editErrors);
check('Upravy: úprava obsahu a stylu (null odebere), vložení, přesun, vložení na začátek', [array_column($edit['deti'], 'id'), $edit['deti'][1]['deti'][0]['styl'], $edit['deti'][1]['deti'][0]['tridy'], array_column($edit['deti'][2]['deti'], 'id'), $edit['deti'][2]['deti'][1]['obsah']['odkaz']],
    [['sek0', 'sek1', 'sek2'], ['mobil' => ['zarovnani_textu' => 'center']], ['nadpis-sekce'], ['txt1', 'tl1'], '/guide']);
check('Upravy: chybné operace se nahlásí a přeskočí (i přesun do potomka)', array_keys($editErrors), ['op[5]', 'op[6]', 'op[7]']);
$editErrors2 = [];
check('Upravy: přesun prvku do jeho potomka nejde', Kaleta\Builder\Edits::apply($editBuild, [['op' => 'presun', 'id' => 'sek1', 'do' => 'nad1']], $editErrors2) === $editBuild && isset($editErrors2['op[0]']), true);

[$compactBuild] = Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['typ' => 'sekce', 'id' => 'abc', 'deti' => [['typ' => 'tlacitko', 'id' => 'def', 'obsah' => ['text' => 'Jdi']], ['typ' => 'kontejner', 'id' => 'ghi', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
$compact = Kaleta\Builder\Build::compact($compactBuild);
check('Stavba::kompaktni: bez výchozích hodnot, styl zůstane', $compact, ['v' => 1, 'deti' => [['id' => 'abc', 'typ' => 'sekce', 'deti' => [['id' => 'def', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Jdi']], ['id' => 'ghi', 'typ' => 'kontejner', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
check('Stavba: zvýraznění <mark> v nadpisu zůstane, třídy a styly ne', Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['typ' => 'nadpis', 'id' => 'mk1', 'obsah' => ['text' => 'Publish<mark class="x" style="color:red">.</mark>']]]])[0]['deti'][0]['obsah']['text'], 'Publish<mark>.</mark>');
$buttonContext = null;
check('Styl::zCss: aliasy margin-top a flex-start', [Kaleta\Builder\Style::fromCss('margin-bottom', '24px'), Kaleta\Builder\Style::fromCss('align-items', 'flex-start')], [['okraj_dole' => '24px'], ['zarovnani' => 'start']]);
check('Styl::zCss: text-align left/right', [Kaleta\Builder\Style::fromCss('text-align', 'left'), Kaleta\Builder\Style::fromCss('text-align', 'right')], [['zarovnani_textu' => 'start'], ['zarovnani_textu' => 'end']]);
check('Ikony: GitHub v sadě', str_contains(Kaleta\Builder\Icons::svg('github'), 'M9 19c-4'), true);
check('Tlačítko: ikona za textem a vlevo od textu', [
    (bool) preg_match('#>Start<svg#', Kaleta\Builder\Elements\Button::render(['obsah' => ['text' => 'Start', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false, 'ikona' => 'sipka', 'ikona_vlevo' => false]], '', '', (new ReflectionClass(Kaleta\Builder\Context::class))->newInstanceWithoutConstructor())),
    (bool) preg_match('#</svg>GitHub</a>#', Kaleta\Builder\Elements\Button::render(['obsah' => ['text' => 'GitHub', 'odkaz' => '/x', 'varianta' => 'obrys', 'nove_okno' => false, 'ikona' => 'github', 'ikona_vlevo' => true]], '', '', (new ReflectionClass(Kaleta\Builder\Context::class))->newInstanceWithoutConstructor())),
    Kaleta\Builder\Elements\Button::render(['obsah' => ['text' => 'Bez', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false]], '', '', (new ReflectionClass(Kaleta\Builder\Context::class))->newInstanceWithoutConstructor()) === '<a class="ka-tlacitko ka-tlacitko--primarni" href="/x">Bez</a>',
], [true, true, true]);
check('Stavba::kompaktni: po vyčištění stejná stavba', Kaleta\Builder\Build::sanitize($compact)[0], $compactBuild);
$overview = Kaleta\Builder\Build::overview(Kaleta\Builder\Build::schema());
check('Stavba::prehled: prvek na řádek, výchozí možnost s hvězdičkou, schéma výrazně menší', [str_contains($overview['prvky']['tlacitko'], 'varianta:vyber(primarni*|'), strlen((string) json_encode($overview)) < strlen((string) json_encode(Kaleta\Builder\Build::schema())) / 2],
    [true, true]);
check('ZHtml: výsledek projde validátorem bez chyb', Kaleta\Builder\Build::sanitize($fromHtml['stavba'])[1], []);
check('Stavba::jakoText: sémantický obsah bez rozložení', Kaleta\Builder\Build::asText($fromHtml['stavba']), "<h1>A <em>b</em></h1>\n<p>Jedna.</p><p>Dvě.</p>\n<p><a href=\"/k\">K</a></p>\n<p>Volný text</p>\n<h3>Otázka?</h3><p>Odpověď.</p>");

// disabled extensions: the builder offers neither their elements nor sections using them
$schemaTypes = array_column(Kaleta\Builder\Build::schema(true, 'cs', false, ['statistika'])['prvky'], 'typ');
check('Rozšíření: bez novinek a poptávek schéma nemá jejich prvky', [in_array('novinky', $schemaTypes, true), in_array('formular', $schemaTypes, true), in_array('nadpis', $schemaTypes, true)], [false, false, true]);
$libraryKey = array_column(Kaleta\Builder\Library::listAll(['statistika']), 'klic');
check('Rozšíření: knihovna bez sekcí s formulářem a novinkami', [in_array('kontakt-formular', $libraryKey, true), in_array('novinky', $libraryKey, true), in_array('uvod', $libraryKey, true)], [false, false, true]);
check('Rozšíření: bez omezení je knihovna celá', count(Kaleta\Builder\Library::listAll()) > count($libraryKey), true);
$libraryEn = Kaleta\Builder\Library::section('uvod', 'en')['prvek'];
check('Knihovna: sekce v angličtině včetně odkazů na stránky', [$libraryEn['deti'][0]['obsah']['text'], $libraryEn['deti'][2]['deti'][0]['obsah']['odkaz'], $libraryEn['deti'][2]['deti'][1]['obsah']['odkaz']], ['We help businesses grow – quickly and hassle-free', '/contact', '/services']);
check('Knihovna: česky se odkazuje na české adresy', Kaleta\Builder\Library::section('uvod')['prvek']['deti'][2]['deti'][0]['obsah']['odkaz'], '/kontakt');
check('Knihovna: jazyk se po sestavení sekce vrátí', Kaleta\Core\Language::code(), 'cs');
$librarySchema = array_column(Kaleta\Builder\Build::schema(true, 'en')['prvky'], 'vlastnosti', 'typ');
check('Stavba::schema: výchozí obsah prvků v jazyce stránky', [$librarySchema['nadpis']['text']['vychozi'], $librarySchema['tlacitko']['text']['vychozi']], ['Heading', 'Contact us']);
$libraryEnDictionary = (require KALETA_ROOT . '/system/jazyky/en.php') + (require KALETA_ROOT . '/system/jazyky/cs.php');
preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)*)'\)/", file_get_contents(KALETA_ROOT . '/system/src/Builder/Library.php') . implode('', array_map('file_get_contents', glob(KALETA_ROOT . '/system/src/Builder/Elements/*.php'))), $libraryTexts);
check('Knihovna a prvky: všechny ukázkové texty mají anglický překlad', array_values(array_diff(array_unique(array_map('stripslashes', $libraryTexts[1])), array_keys($libraryEnDictionary), ['Menu', 'Standard', 'Video'])), []);

check('Firma::hodiny: rozsah dnů, víc úseků, zavřeno', Kaleta\Front\Company::parseOpeningHours("Po–Pá 8:00–17:00\nÚt 8-12, 13-17\nNe zavřeno"), [
    ['dny' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'od' => '08:00', 'do' => '17:00'],
    ['dny' => ['Tuesday'], 'od' => '08:00', 'do' => '12:00'], ['dny' => ['Tuesday'], 'od' => '13:00', 'do' => '17:00'],
]);
check('Firma::hodiny: nesrozumitelný řádek se odmítne', [Kaleta\Front\Company::parseOpeningHours('každý den 8-17'), Kaleta\Front\Company::parseOpeningHours('Po 8-25')], [null, null]);
check('Firma: typy v Nastavení odpovídají Firma::TYPY', (new ReflectionClassConstant(Kaleta\Admin\Modules\Settings::class, 'COMPANY_TYPES'))->getValue(), implode('|', array_keys(Kaleta\Front\Company::TYPES)));

/* ---------- collections ---------- */
$collectionFields = Kaleta\Builder\Collections::sanitizeFields([['popisek' => 'Citát zákazníka', 'typ' => 'radky'], ['popisek' => 'Název', 'typ' => 'text'], ['popisek' => 'Logo', 'typ' => 'nesmysl'], ['popisek' => '']]);
check('Kolekce::vycistiPole: klíč z popisku, vestavěný název se nepřepíše, neznámý typ = text', array_map(fn (array $p): string => $p['klic'] . ':' . $p['typ'], $collectionFields), ['citat_zakaznika:radky', 'nazev_2:text', 'logo:text']);
$collectionErrors = [];
$collectionData = Kaleta\Builder\Collections::sanitizeData([['klic' => 'web', 'popisek' => 'Web', 'typ' => 'odkaz'], ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek'], ['klic' => 'cena', 'popisek' => 'Cena', 'typ' => 'cislo'], ['klic' => 'bio', 'popisek' => 'Bio', 'typ' => 'html']],
    ['web' => 'javascript:alert(1)', 'foto' => 'media/2026/a.jpg', 'cena' => '1 200', 'bio' => '<p onclick="x">Ahoj</p><script>1</script>'], $collectionErrors);
check('Kolekce::vycistiData: nebezpečný odkaz pryč, obrázek z médií, číslo bez mezer, HTML vyčištěné', [$collectionData['web'], $collectionData['foto'], $collectionData['cena'], $collectionData['bio'], array_keys($collectionErrors)], ['', 'media/2026/a.jpg', '1200', '<p>Ahoj</p>', ['web']]);
$collectionValues = ['nazev' => ['Jan <b>Novák</b>', 'text'], 'bio' => ['<p>Truhlář</p>', 'html'], 'poznamka' => ["řádek 1\nřádek 2", 'radky'], 'url' => ['/tym/jan', 'odkaz'], 'zly' => ['javascript:x', 'odkaz']];
check('Kolekce::dosad: značky v dosazené hodnotě se znovu nedosazují', Kaleta\Builder\Collections::fill('<p>{{text}}</p><p>{{nazev}}</p>', 'html', ['text' => ['<p>Napište {{nazev}} nebo {{url}}.</p>', 'html'], 'nazev' => ['Návod', 'text'], 'url' => ['/navod', 'text']]), '<p>Napište {{nazev}} nebo {{url}}.</p><p>Návod</p>');
check('Kolekce::dosad: jeden průchod i v řádkovém textu', Kaleta\Builder\Collections::fill('{{popis}} – {{nazev}}', 'inline', ['popis' => ['Viz {{nazev}}', 'radky'], 'nazev' => ['X', 'text']]), 'Viz {{nazev}} – X');
check('Kolekce::dosad: text se escapuje až prvkem, inline a html hned, html pole zůstane HTML', [
    Kaleta\Builder\Collections::fill('{{nazev}}', 'text', $collectionValues), Kaleta\Builder\Collections::fill('Tým: {{nazev}}', 'inline', $collectionValues),
    Kaleta\Builder\Collections::fill('{{bio}}', 'html', $collectionValues), Kaleta\Builder\Collections::fill('<p>{{poznamka}}</p>', 'html', $collectionValues),
    Kaleta\Builder\Collections::fill('{{url}}', 'odkaz', $collectionValues), Kaleta\Builder\Collections::fill('{{zly}}', 'odkaz', $collectionValues), Kaleta\Builder\Collections::fill('{{neni}}', 'inline', $collectionValues),
], ['Jan <b>Novák</b>', 'Tým: Jan &lt;b&gt;Novák&lt;/b&gt;', '<p>Truhlář</p>', '<p>řádek 1<br>' . "\n" . 'řádek 2</p>', '/tym/jan', '', '']);
[$collectionBuild, $collectionErrors] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'kolekce', 'obsah' => ['kolekce' => 'tym'], 'deti' => [['typ' => 'obrazek', 'obsah' => ['src' => '{{foto}}']], ['typ' => 'tlacitko', 'obsah' => ['odkaz' => '{{url}}']]]]]]);
check('Stavba::vycisti: značky {{pole}} v obrázku a odkazu projdou', [$collectionBuild['deti'][0]['deti'][0]['obsah']['src'], $collectionBuild['deti'][0]['deti'][1]['obsah']['odkaz'], $collectionErrors], ['{{foto}}', '{{url}}', []]);

/* ---------- builder English: editor texts (JS) and schema labels (PHP) ---------- */
preg_match_all("/\bT\('((?:[^'\\\\]|\\\\.)*)'\)/", (string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), $enJs);
preg_match('/window\.KALETA_PREKLAD = (\{.*\});/s', (string) file_get_contents(KALETA_ROOT . '/image/jazyky/admin-en.js'), $enJsDictionary);
$enJsKeys = array_keys((array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $enJsDictionary[1] ?? '{}'), true));
preg_match('/window\.KALETA_PREKLAD = (\{.*\});/s', (string) file_get_contents(KALETA_ROOT . '/image/jazyky/admin-cs.js'), $csJsDictionary);
$enJsKeys = [...$enJsKeys, ...array_keys((array) json_decode($csJsDictionary[1] ?? '{}', true))];
check('Builder: všechny texty editoru mají anglický překlad', array_values(array_diff(array_unique(array_map('stripslashes', $enJs[1])), $enJsKeys, ['Tablet', 'Menu'])), []);
$enAdmin = (require KALETA_ROOT . '/system/jazyky/admin-en.php') + (require KALETA_ROOT . '/system/jazyky/admin-cs.php');
$enSchema = Kaleta\Builder\Build::schema(true, 'cs', true);
$enTexts = array_merge(array_column($enSchema['prvky'], 'nazev'), array_column($enSchema['prvky'], 'popis'), array_column($enSchema['prvky'], 'skupina'), array_values($enSchema['skupiny_stylu']));
$enFields = function (array $properties) use (&$enFields, &$enTexts): void {
    foreach ($properties as $d) {
        $enTexts[] = (string) ($d['popisek'] ?? '');
        array_push($enTexts, ...array_values($d['moznosti'] ?? []));
        if (isset($d['pole'])) {
            $enFields($d['pole']);
        }
    }
};
foreach ($enSchema['prvky'] as $p) {
    $enFields($p['vlastnosti']);
}
$enFields($enSchema['styl']);
check('Builder: všechny popisky schématu mají anglický překlad', array_values(array_filter(array_unique($enTexts), fn (string $x): bool => $x !== '' && preg_match('/\p{L}/u', $x) === 1 && !isset($enAdmin[$x]) && !in_array($x, ['Video', 'Logo', 'HTML', 'Text', 'text'], true))), []);

$siteErrors = [];
$siteSections = array_column(Kaleta\Builder\Library::listAll(), 'klic');
foreach (Kaleta\Builder\Library::SITES as $siteKey => $networks) {
    if (!isset(Kaleta\Builder\DesignSystem::PRESETS[$networks['predvolba']])) {
        $siteErrors[] = $siteKey . ': předvolba ' . $networks['predvolba'];
    }
    foreach ($networks['stranky'] as $pageSections) {
        foreach (array_diff($pageSections, $siteSections) as $missing) {
            $siteErrors[] = $siteKey . ': sekce ' . $missing;
        }
    }
}
check('Knihovna::WEBY: předvolby a sekce ukázkových webů existují', $siteErrors, []);

// pages of the sample sites pass the builder's pre-publish check (image/stavitel.js, check()): buttons with a link,
// no empty image, a single h1 and an outline without a skipped level – in Czech and English, with all extensions and without them
$pageCheck = function (array $build): array {
    $findings = [];
    $headings = [];
    $walk = function (array $children) use (&$walk, &$findings, &$headings): void {
        foreach ($children as $p) {
            $o = $p['obsah'] ?? [];
            if ($p['typ'] === 'tlacitko' && in_array($o['odkaz'] ?? '', ['', '#'], true)) {
                $findings[] = 'tlačítko bez odkazu: ' . ($o['text'] ?? '');
            }
            if ($p['typ'] === 'obrazek' && (($o['src'] ?? '') === '' || ($o['alt'] ?? '') === '')) {
                $findings[] = 'obrázek bez souboru nebo popisu';
            }
            if ($p['typ'] === 'nadpis' && preg_match('/^h([1-6])$/', $p['znacka'] ?? 'h2', $m)) {
                $headings[] = (int) $m[1];
            }
            $walk($p['deti'] ?? []);
        }
    };
    $walk($build['deti']);
    if (count(array_keys($headings, 1)) !== 1) {
        $findings[] = count(array_keys($headings, 1)) . '× h1';
    }
    foreach ($headings as $i => $u) {
        if ($i > 0 && $u > $headings[$i - 1] + 1) {
            $findings[] = 'h' . $headings[$i - 1] . ' → h' . $u;
        }
    }

    return $findings;
};
$sitesCheck = [];
foreach (Kaleta\Builder\Library::SITES as $siteKey => $networks) {
    foreach (['cs', 'en'] as $language) {
        foreach (['všechna rozšíření' => array_keys(Kaleta\Core\Extensions::CATALOG), 'bez rozšíření' => []] as $variant => $enabled) {
            foreach ($networks['stranky'] as $i => $pageSections) {
                if ($pageSections === []) {
                    continue; // text page: the layout provides the h1 heading
                }
                [$build] = Kaleta\Builder\Library::assemble($pageSections, 'Stránka', $language, Kaleta\Builder\Build::disabledTypes($enabled), true);
                foreach ($pageCheck($build) as $finding) {
                    $sitesCheck[] = "$siteKey/$language/$variant/stránka $i: $finding";
                }
            }
        }
    }
}
check('Knihovna::WEBY: stránky ukázkových webů projdou kontrolou před publikováním', array_values(array_unique($sitesCheck)), []);
$contactWithoutForm = Kaleta\Builder\Library::assemble(Kaleta\Builder\Library::SITES['remeslo']['stranky'][3], 'Kontakt', 'cs', Kaleta\Builder\Build::disabledTypes([]), true)[0];
check('Knihovna: kontakt bez rozšíření Formuláře má údaje firmy', str_contains((string) json_encode($contactWithoutForm), '"udaj":"adresa"'), true);

/* ---------- AI assistant: providers and builder ---------- */
$aiBody = ['model' => 'm1', 'max_tokens' => 50, 'system' => 'S', 'messages' => [['role' => 'user', 'content' => [['type' => 'image', 'source' => ['media_type' => 'image/png', 'data' => 'QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]]];
check('Asistent::naOpenAi: systém, obrázek jako data URL, limit tokenů podle poskytovatele', [Assistant::toOpenAi($aiBody, 'openai'), array_keys(Assistant::toOpenAi($aiBody, 'mistral'))], [
    ['model' => 'm1', 'messages' => [['role' => 'system', 'content' => 'S'], ['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]], 'max_completion_tokens' => 50],
    ['model', 'messages', 'max_tokens'],
]);
check('Asistent::zOpenAi: odpověď do tvaru Claude API', Assistant::fromOpenAi(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'length']]]), ['content' => [['type' => 'text', 'text' => 'Text']], 'stop_reason' => 'max_tokens']);
$aiSettings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($aiSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-5']);
$aiFake = new class($aiSettings) extends Assistant {
    public string $answer = '';
    public array $last = [];

    protected function call(array $body): array
    {
        $this->last = $body;

        return ['content' => [['type' => 'text', 'text' => $this->answer]]];
    }
};
$aiFake->answer = "Tady je sekce:\n```html\n<section class=\"sluzby-ai\"><h2>Služby</h2><p>Text <script>x</script></p><a class=\"btn\" href=\"javascript:alert(1)\">Klik</a></section><style>.sluzby-ai { padding: var(--ka-mezera-l); }</style>\n```";
$aiHtml = $aiFake->suggestSection('Tři karty se službami a odkazem na kontakt.', 'cs', 'Služby');
$aiConversion = Kaleta\Builder\HtmlConverter::convert($aiHtml);
[$aiBuild] = Kaleta\Builder\Build::sanitize($aiConversion['stavba'], false);
check('Asistent::navrhniSekci: HTML z bloku ```html, zadání uvnitř <zadani>, výsledek bez skriptu a javascript: odkazu', [
    str_starts_with($aiHtml, '<section'), str_contains((string) $aiFake->last['messages'][0]['content'], '<zadani>'), str_contains(json_encode($aiBuild), 'script'), str_contains(json_encode($aiBuild), 'javascript'), $aiConversion['tridy'],
], [true, true, false, false, ['sluzby-ai' => 'padding: var(--ka-mezera-l);']]);
$aiFake->answer = '<p>Kratší <strong>text</strong> <img src=x onerror=alert(1)></p>';
check('Asistent::prepis: HTML odpověď vyčištěná, prostý text bez značek', [$aiFake->rewrite('<p>Dlouhý text k přepsání.</p>', 'kratsi', true), $aiFake->rewrite('Nadpis', 'formalne', false)], ['<p>Kratší <strong>text</strong> </p>', 'Kratší text']);
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($aiSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'openai', 'ai_model' => 'claude-sonnet-5']);
try {
    $aiFake->rewrite('Text', 'kratsi', false);
    $aiError = '';
} catch (RuntimeException $e) {
    $aiError = $e->getMessage();
}
check('Asistent: u jiného poskytovatele než Claude je potřeba zadat jeho model', str_contains($aiError, 'Enter the model name'), true);

/* ---------- class renames (tools/rename.php) ---------- */
// 2.0: the per-page Modal element becomes a site pop-up (Builder\ModalConversion)
[$withoutModal, $modals] = Kaleta\Builder\ModalConversion::extract(['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'tlacitko', 'obsah' => ['odkaz' => '#nabidka']],
    ['id' => 'o1', 'typ' => 'okno', 'kotva' => 'nabidka', 'deti' => [['typ' => 'nadpis']]]]]]]);
check('Modal → pop-up: vyjmutí z hloubky stavby, kotva, přepsání odkazů', [count($withoutModal['deti'][0]['deti']), count($modals), Kaleta\Builder\ModalConversion::anchor($modals[0]),
    Kaleta\Builder\ModalConversion::anchor(['id' => 'x1']), Kaleta\Builder\ModalConversion::anchor(['id' => 'x1', 'atributy' => ['id' => 'vlastni']]),
    Kaleta\Builder\ModalConversion::rewriteLinks('{"odkaz":"#nabidka","html":"<a href=\\"#nabidka\\">x</a>","jiny":"#nabidka-2"}', ['nabidka' => 'nabidka'])],
    [1, 1, 'nabidka', 'okno-x1', 'vlastni', '{"odkaz":"#popup-nabidka","html":"<a href=\\"#popup-nabidka\\">x</a>","jiny":"#nabidka-2"}']);
check('2.0: staré klíče nastavení jen na hranici (MCP, import, aktualizace)', [Kaleta\Core\OldSettingsKeys::current('nazev_webu'), Kaleta\Core\OldSettingsKeys::current('nazev_webu_de'),
    Kaleta\Core\OldSettingsKeys::current('site_name'), Kaleta\Core\OldSettingsKeys::current('verze_db')], ['site_name', 'site_name_de', 'site_name', 'db_version']);
// 2.0: the old (Czech) class names and helpers of 1.3 are gone; 2.0.1 dropped the empty alias file (it rides along in packages only)
check('2.0: old class names and helpers no longer exist', [is_file(KALETA_SYSTEM . '/class-aliases.php'), class_exists('Kaleta\\Jadro\\Nastaveni'), function_exists('datum_slovy'),
    class_exists('Kaleta\\Admin\\LegacyUrls'), class_exists('Kaleta\\Front\\Api'), class_exists('Kaleta\\Builder\\Elements\\Modal')], [false, false, false, false, false, false]);
exec('php ' . escapeshellarg(__DIR__ . '/rename.php') . ' --self-test', $renameOutput, $renameCode);
check('tools/rename.php self-test', $renameCode, 0);

/* ---------- 2.4: guide links in the administration ---------- */
// the articles of the guide on kaletacms.com; a new admin module or settings tab needs its article here and in Admin\Guide
$guideArticles = ['install', 'first-steps', 'extensions', 'builder-basics', 'styling-responsive', 'elements', 'page-settings', 'site-appearance', 'classes', 'components',
    'site-parts', 'popups', 'menus', 'collections', 'collection-lists', 'site-search', 'news', 'forms', 'newsletter', 'company-details', 'seo', 'languages',
    'claude-connect', 'claude-capabilities', 'ai-assistant', 'users-roles', 'wordpress-import', 'backups-updates', 'media', 'statistics', 'privacy-cookies', 'email', 'site-health', 'fleet-console'];
$guideTargets = [...Kaleta\Admin\Guide::MODULES, ...Kaleta\Admin\Guide::SETTINGS, ...Kaleta\Admin\Guide::BUILDER];
check('2.4: every admin module and settings tab links to an existing guide article', [
    array_values(array_diff(array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES), array_keys(Kaleta\Admin\Guide::MODULES), ['settings'])),
    array_values(array_diff(array_keys(Kaleta\Admin\Modules\Settings::TABS), array_keys(Kaleta\Admin\Guide::SETTINGS))),
    array_values(array_filter($guideTargets, fn (string $a): bool => !in_array(strtok($a, '#'), $guideArticles, true))),
], [[], [], []]);
check('2.4: guide link follows the admin language', [
    Kaleta\Admin\Guide::forScreen('settings', '', 'backups', 'cs'), Kaleta\Admin\Guide::forScreen('pages', 'builder', '', 'de'),
    Kaleta\Admin\Guide::forScreen('popups', 'builder', '', 'en'), Kaleta\Admin\Guide::forScreen('stats', '', '', 'pl'), Kaleta\Admin\Guide::forScreen('nothing', '', '', 'en'),
], ['https://kaletacms.com/cs/guide/backups-updates', 'https://kaletacms.com/de/guide/builder-basics', 'https://kaletacms.com/guide/popups', 'https://kaletacms.com/guide/statistics', null]);

/* ---------- 2.4: German administration ---------- */
// every admin text translated into Czech has a German translation too, with the same placeholders and tags
$placeholders = function (string $text): array { preg_match_all('/%(?:\d+\$)?[sd]|<\/?[a-z]+/i', $text, $m); sort($m[0]); return $m[0]; };
$jsDictionary = function (string $file): array { preg_match_all('/^\t("(?:[^"\\\\]|\\\\.)*"):\s*("(?:[^"\\\\]|\\\\.)*"),?$/m', (string) file_get_contents($file), $m, PREG_SET_ORDER);
    return array_combine(array_map(fn (array $x): string => json_decode($x[1]), $m), array_map(fn (array $x): string => json_decode($x[2]), $m)); };
$deGaps = [];
foreach ([[require KALETA_SYSTEM . '/jazyky/admin-cs.php', require KALETA_SYSTEM . '/jazyky/admin-de.php'],
    [$jsDictionary(KALETA_ROOT . '/image/jazyky/admin-cs.js'), $jsDictionary(KALETA_ROOT . '/image/jazyky/admin-de.js')]] as [$csTexts, $deTexts]) {
    foreach ($csTexts as $key => $_) {
        $key = (string) $key;
        if (!isset($deTexts[$key]) && !in_array($key, ['Name'], true)) { $deGaps[] = 'missing: ' . $key; }
        elseif (isset($deTexts[$key]) && $placeholders($key) !== $placeholders((string) $deTexts[$key])) { $deGaps[] = 'placeholders: ' . $key; }
    }
}
check('2.4: German admin covers every Czech admin text', array_slice($deGaps, 0, 5), []);
check('2.4: German is an admin language', [isset(Kaleta\Core\Language::ADMIN_LANGUAGES['de']), count($jsDictionary(KALETA_ROOT . '/image/jazyky/admin-de.js')) > 2500], [true, true]);

check('2.10: alert e-mails – errors and the warnings that need the owner, not every warning', array_column(Kaleta\Core\Alerts::worth([
    ['id' => 1, 'created_at' => '', 'type' => 'backup.failed', 'severity' => 'error', 'message' => '', 'data' => []],
    ['id' => 2, 'created_at' => '', 'type' => 'firewall.blocked', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 3, 'created_at' => '', 'type' => 'content.review', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 4, 'created_at' => '', 'type' => 'notfound.spike', 'severity' => 'warning', 'message' => '', 'data' => []]]), 'id'), [1, 3, 4]);
/* ---------- 2.10: business facts – values by type, how they are shown, the token ---------- */
check('2.10: Facts::clean – a value must fit its type', [Kaleta\Core\Facts::clean('number', '1 500'), Kaleta\Core\Facts::clean('number', 'many'), Kaleta\Core\Facts::clean('year', '2004'),
    Kaleta\Core\Facts::clean('year', '04'), Kaleta\Core\Facts::clean('money', '1500 CZK'), Kaleta\Core\Facts::clean('money', '1500,- Kč'), Kaleta\Core\Facts::clean('date', '2026-10-02'),
    Kaleta\Core\Facts::clean('email', 'info@example.cz'), Kaleta\Core\Facts::clean('url', 'javascript:alert(1)'), Kaleta\Core\Facts::clean('text', '<b>20</b> let'), Kaleta\Core\Facts::clean('text', 'see {{fact.other}}')],
    ['1500', null, '2004', null, '1500 CZK', null, '2026-10-02', 'info@example.cz', null, '20 let', null]);
check('2.10: Facts::display – numbers with the thousands separator of the language, amounts with the currency', Kaleta\Core\Language::runWith('cs', fn (): array => [
    Kaleta\Core\Facts::display('number', '12500'), Kaleta\Core\Facts::display('money', '1500 CZK'), Kaleta\Core\Facts::display('year', '2004'), Kaleta\Core\Facts::display('text', 'od 2004')]),
    ["12\u{00A0}500", "1\u{00A0}500\u{00A0}CZK", '2004', 'od 2004']);
preg_match_all(Kaleta\Core\Facts::TOKEN_PATTERN, 'Since {{fact.founded}} – {{ fact.projects }} projects, {{fact.Bad}}, {{fact.x}}, {{nazev}}', $factTokens);
check('2.10: the fact token – spaces inside are fine, collection fields and bad keys are not facts', $factTokens[1], ['founded', 'projects']);
/* ---------- 2.10: computed facts – years since, counts, the proof rule ---------- */
$yearsSince = fn (string $value, string $now): ?int => Kaleta\Core\Facts::yearsSince($value, new DateTimeImmutable($now));
check('2.10: years_since – a year, a date on, before and after its anniversary, this year, a bad argument, a date ahead', [
    $yearsSince('2004', '2026-10-02 12:00'), $yearsSince('2004-10-02', '2026-10-02 00:00'), $yearsSince('2004-10-03', '2026-10-02 12:00'), $yearsSince('2004-10-01', '2026-10-02 12:00'),
    $yearsSince('2026', '2026-01-01'), $yearsSince('soon', '2026-10-02'), $yearsSince('2004-13-01', '2026-10-02'), $yearsSince('2030', '2026-10-02'), $yearsSince('2026-12-24', '2026-10-02')],
    [22, 22, 21, 22, 0, null, null, null, null]);
$factApp = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
check('2.10: computed() – years since a year as the site shows it, a bad argument is nothing (the audit reports it)', [Kaleta\Core\Facts::computed($factApp, 'years_since', '2004', new DateTimeImmutable('2026-06-01')), Kaleta\Core\Facts::computed($factApp, 'years_since', 'soon')], ['22', null]);
preg_match_all(Kaleta\Core\Facts::COMPUTED_PATTERN, '{{years_since:2004}} {{ years_since:fact.founded }} {{count:reference-2}} {{count:news}} {{count:Velka}} {{pocet:x}} {{nazev}}', $computedTokens, PREG_SET_ORDER);
check('2.10: the computed token – both forms with their arguments, nothing else', array_map(fn (array $c): string => $c[1] . ':' . $c[2], $computedTokens), ['years_since:2004', 'years_since:fact.founded', 'count:reference-2', 'count:news']);
check('2.10: claims – a sentence with a computed or fact token is not a claim any more', [Kaleta\Core\Facts::isClaim('Na trhu jsme 22 let.'), Kaleta\Core\Facts::isClaim('Na trhu jsme {{years_since:2004}} let.'),
    Kaleta\Core\Facts::isClaim('Máme {{fact.projects}} zakázek.'), Kaleta\Core\Facts::isClaim('Otevřeno od 8 hodin.')], [true, false, false, false]);
$proofBuild = ['v' => 1, 'deti' => [['id' => 's1', 'typ' => 'sekce', 'deti' => [['id' => 'c1', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => 1500]], ['id' => 'c2', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => '{{fact.projects}}']],
    ['id' => 'c3', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => ' 22 ']], ['id' => 'c4', 'typ' => 'pocitadlo', 'obsah' => ['cislo' => '{{count:reference}}']], ['id' => 'n1', 'typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => '1500']]]]]];
check('2.10: proof numbers typed in – counters with digits, not with tokens and not headings', Kaleta\Core\Facts::typedNumbers($proofBuild), [['id' => 'c1', 'number' => '1500'], ['id' => 'c3', 'number' => '22']]);
[$counterBuild] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'pocitadlo', 'obsah' => ['cislo' => 1500]], ['typ' => 'pocitadlo', 'obsah' => ['cislo' => '{{years_since:fact.founded}}']]]], false);
check('2.10: the counter keeps a number and a token alike', array_column(array_column($counterBuild['deti'], 'obsah'), 'cislo'), ['1500', '{{years_since:fact.founded}}']);
check('2.10: the counter is content – its number or token and label are in the text of the build (usage, the audit, the old value)', Kaleta\Builder\Build::asText($counterBuild),
    "<p>1500+ " . t('spokojených zákazníků') . "</p>\n<p>{{years_since:fact.founded}}+ " . t('spokojených zákazníků') . '</p>');
$counterContext = new Kaleta\Builder\Context($factApp, true);
$counterHtml = fn (string $number): string => Kaleta\Builder\Elements\Counter::render(['znacka' => 'div', 'obsah' => ['cislo' => $number, 'pred' => '', 'za' => '+', 'popisek' => 'zakázek']], '', '', $counterContext);
check('2.10: the counter – digits count up, the editor shows a token as it is (without the count-up)', Kaleta\Core\Language::runWith('cs', fn (): array => [str_contains($counterHtml('1500'), "data-pocitadlo=\"1500\">1\u{00A0}500<"),
    str_contains($counterHtml('{{fact.projects}}'), '>{{fact.projects}}<'), str_contains($counterHtml('{{fact.projects}}'), 'data-pocitadlo')]), [true, true, false]);
check('2.10.1: tokens inside <code> and <pre> are examples – never filled, not reported', [
    Kaleta\Core\Facts::withoutCode('<p>Write <code>{{fact.key}}</code> here, <pre>{{years_since:2004}}</pre> and {{fact.real}}.</p>')],
    ['<p>Write   here,   and {{fact.real}}.</p>']);
/* ---------- 2.10: opening hours with exceptions ---------- */
$hWeek = array_fill_keys(Kaleta\Core\Hours::DAYS, []);
foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $hDay) { $hWeek[$hDay] = [['08:00', '12:00'], ['13:00', '17:00']]; }
$hXmas = ['id' => 1, 'from' => '2026-12-24', 'to' => '2026-12-26', 'closed' => true, 'hours' => '', 'note' => 'Christmas', 'notice_days' => 7];
$hShort = ['id' => 2, 'from' => '2026-12-31', 'to' => '2026-12-31', 'closed' => false, 'hours' => '9-12', 'note' => '', 'notice_days' => 0];
$hAt = fn (string $when): DateTimeImmutable => new DateTimeImmutable($when);
$hStatus = fn (string $when, array $ex = []): array => (fn (array $st): array => [$st['open'], $st['until'], $st['next']?->format('Y-m-d H:i')])(Kaleta\Core\Hours::status($hWeek, $ex, $hAt($when)));
check('2.10: Hours::parseRanges – 9-12, 9:00–12:00 and more ranges; nonsense is refused', [Kaleta\Core\Hours::parseRanges('9-12, 13:30–17'), Kaleta\Core\Hours::parseRanges('morning')],
    [[['09:00', '12:00'], ['13:30', '17:00']], null]);
check('2.10: Hours::status – open until, lunch break, closed for the weekend, a holiday, shorter hours', [
    $hStatus('2026-10-05 10:00'), $hStatus('2026-10-05 12:30'), $hStatus('2026-10-03 11:00'), $hStatus('2026-12-23 18:00', [$hXmas]), $hStatus('2026-12-31 10:00', [$hShort]), $hStatus('2026-12-31 12:30', [$hShort])],
    [[true, '12:00', null], [false, null, '2026-10-05 13:00'], [false, null, '2026-10-05 08:00'], [false, null, '2026-12-28 08:00'], [true, '12:00', null], [false, null, '2027-01-01 08:00']]);
check('2.10: Hours – the notice bar a week ahead until the end, not with notice_days 0; exceptions in schema.org', [
    count(Kaleta\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-16 10:00'))), count(Kaleta\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-17 10:00'))),
    count(Kaleta\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-27 10:00'))), count(Kaleta\Core\Hours::noticed([$hShort], $hAt('2026-12-31 10:00'))),
    Kaleta\Core\Hours::schema([$hXmas, $hShort])],
    [0, 1, 0, 0, [['@type' => 'OpeningHoursSpecification', 'opens' => '00:00', 'closes' => '00:00', 'validFrom' => '2026-12-24', 'validThrough' => '2026-12-26'],
        ['@type' => 'OpeningHoursSpecification', 'opens' => '09:00', 'closes' => '12:00', 'validFrom' => '2026-12-31', 'validThrough' => '2026-12-31']]]);
/* ---------- 2.10: links between collections, redirect of hidden items ---------- */
$linkFields = Kaleta\Builder\Collections::sanitizeFields([['popisek' => 'Pobočka', 'typ' => 'polozka', 'kolekce' => 'pobocky'], ['popisek' => 'Bez kolekce', 'typ' => 'polozka'], ['popisek' => 'Role', 'typ' => 'text', 'kolekce' => 'x']]);
check('2.10: an item link remembers its collection; without one it is a short text', $linkFields,
    [['klic' => 'pobocka', 'popisek' => 'Pobočka', 'typ' => 'polozka', 'kolekce' => 'pobocky'], ['klic' => 'bez_kolekce', 'popisek' => 'Bez kolekce', 'typ' => 'text'], ['klic' => 'role', 'popisek' => 'Role', 'typ' => 'text']]);
$linkErrors = [];
check('2.10: an item link stores the address of the item', [Kaleta\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'praha-centrum'], $linkErrors), Kaleta\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'Praha <b>'], $linkErrors), $linkErrors],
    [['pobocka' => 'praha-centrum', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => '', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => 'Pobočka']]);
$linkValues = Kaleta\Builder\Collections::values(['seo_link' => 'lide', 'detail' => 1, 'pole' => $linkFields], ['nazev' => 'Jana', 'seo_link' => 'jana', 'datum' => '2026-10-02 10:00:00', 'data' => ['pobocka' => 'praha-centrum']], fn (string $p): string => '/' . $p);
check('2.10: {{field}}, {{field_url}} and {{field_seo}} of an item link (without a database only the address)', [$linkValues['pobocka'], $linkValues['pobocka_url'], $linkValues['pobocka_seo']],
    [['', 'text'], ['', 'odkaz'], ['praha-centrum', 'text']]);
check('2.10: where hidden items redirect – a path on the site or https', array_map(Kaleta\Builder\Collections::cleanRedirect(...), ['', '/tym', 'https://example.com/team', 'javascript:alert(1)', 'tym', '/a b']),
    ['', '/tym', 'https://example.com/team', null, null, null]);
check('2.10: Hours::rangesText – hours for people from ranges or their text, nothing from nonsense (the door sign)', [Kaleta\Core\Hours::rangesText([['08:00', '12:00'], ['13:30', '17:00']]), Kaleta\Core\Hours::rangesText('9-12'), Kaleta\Core\Hours::rangesText('morning')],
    ['8:00–12:00, 13:30–17:00', '9:00–12:00', '']);
check('2.10: HoursSign – formats, a standalone view with print CSS, a Print button only on screen and no outside resource', [Kaleta\Core\HoursSign::FORMATS,
    (fn (string $v): array => [str_contains($v, '@page'), str_contains($v, '@media print'), str_contains($v, 'data-tisk'), preg_match('/(href|src)="https?:/', $v) === 0, str_contains($v, '<!DOCTYPE html>')])((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/hours_sign.php'))],
    [['a4', 'a5'], [true, true, true, true, true]]);
/* ---------- 2.9: fleet console – keys, pairing key, staged updates, attention ---------- */
$fleetPair = sodium_crypto_sign_keypair();
$fleetPub = base64_encode(sodium_crypto_sign_publickey($fleetPair));
$fleetSig = base64_encode(sodium_crypto_sign_detached('{"a":1}', sodium_crypto_sign_secretkey($fleetPair)));
check('2.9: Fleet\Keys – a signature fits only its message and key', [Kaleta\Fleet\Keys::verify('{"a":1}', $fleetSig, $fleetPub), Kaleta\Fleet\Keys::verify('{"a":2}', $fleetSig, $fleetPub),
    Kaleta\Fleet\Keys::verify('{"a":1}', $fleetSig, base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))), Kaleta\Fleet\Keys::verify('{"a":1}', 'junk', $fleetPub),
    Kaleta\Fleet\Keys::isPublicKey($fleetPub), Kaleta\Fleet\Keys::isPublicKey('abc'), strlen(Kaleta\Fleet\Keys::fingerprint($fleetPub))], [true, false, false, false, true, false, 8]);
check('2.9: Fleet\Http – https only, plain http just for local test addresses', array_map(Kaleta\Fleet\Http::allowedUrl(...),
    ['https://console.example.com', 'http://console.example.com', 'http://127.0.0.1:8312', 'http://web.test', 'ftp://example.com', 'https://user:pw@example.com', 'javascript:alert(1)']),
    [true, false, true, true, false, false, false]);
$fleetKey = Kaleta\Fleet\Link::makeKey('https://console.example.com/', str_repeat('ab', 16), $fleetPub, 'Agency console');
check('2.9: Fleet\Link – the pairing key carries the console address, the one-time code and the console key', [
    Kaleta\Fleet\Link::parseKey(" \n" . chunk_split($fleetKey, 40, "\n")), Kaleta\Fleet\Link::parseKey('kaleta-console:junk'), Kaleta\Fleet\Link::parseKey(str_repeat('ab', 16)),
    Kaleta\Fleet\Link::parseKey(Kaleta\Fleet\Link::makeKey('http://console.example.com', str_repeat('ab', 16), $fleetPub, 'x'))],
    [['url' => 'https://console.example.com', 'code' => str_repeat('ab', 16), 'key' => $fleetPub, 'name' => 'Agency console'], null, null, null]);
$fleetNow = 1_800_000_000;
$fleetSite = fn (int $id, string $version, string $ring = 'normal', array $more = []): array => $more + ['id' => $id, 'version' => $version, 'ring' => $ring, 'manage_updates' => true,
    'update_allowed' => '', 'status' => 'ok', 'up' => true, 'version_since' => $fleetNow - 50 * 3600, 'update_problem' => false];
$allowed = fn (array $site, array $sites, bool $security = false, int $firstSeen = 0): string => Kaleta\Fleet\Console::allowedVersion($site, $sites, '2.9.0', $security, $firstSeen, $fleetNow);
$canaryNew = $fleetSite(1, '2.9.0', 'canary');
check('2.9: staged updates – canaries first, the rest after 48 hours without problems, security releases at once', [
    $allowed($fleetSite(2, '2.8.0', 'normal', ['manage_updates' => false]), [$canaryNew]),
    $allowed($fleetSite(2, '2.9.0'), [$canaryNew]),
    $allowed($fleetSite(1, '2.8.0', 'canary'), []),
    $allowed($fleetSite(2, '2.8.0'), [$canaryNew]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['version_since' => $fleetNow - 10 * 3600])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['status' => 'error'])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['up' => false])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.9.0', 'canary', ['update_problem' => true])]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.8.0', 'canary')]),
    $allowed($fleetSite(2, '2.8.0'), [$fleetSite(1, '2.8.0', 'canary')], true),
    $allowed($fleetSite(2, '2.8.0', 'normal', ['update_allowed' => '2.9.0']), [$fleetSite(1, '2.8.0', 'canary')]),
    $allowed($fleetSite(2, '2.8.0'), [], false, $fleetNow - 10 * 3600),
    $allowed($fleetSite(2, '2.8.0'), [], false, $fleetNow - 49 * 3600),
], ['', '', '2.9.0', '2.9.0', '', '', '', '', '', '2.9.0', '2.9.0', '', '2.9.0']);
$fleetRow = fn (array $more, array $beat = []): array => $more + ['up' => 1, 'last_seen' => date('Y-m-d H:i:s', $fleetNow - 600), 'status' => 'ok', 'heartbeat' => (string) json_encode($beat + ['last_backup' => $fleetNow - 3600, 'cron_last_run' => $fleetNow - 300])];
check('2.9: attention – what is wrong with a site, weighted', [
    Kaleta\Fleet\Console::attention($fleetRow([]), $fleetNow),
    Kaleta\Fleet\Console::attention($fleetRow(['up' => 0, 'status' => 'error']), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow(['last_seen' => date('Y-m-d H:i:s', $fleetNow - 30 * 3600)]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow(['last_seen' => null, 'heartbeat' => null]), $fleetNow)['reasons'],
    Kaleta\Fleet\Console::attention($fleetRow([], ['last_backup' => $fleetNow - 9 * 86400, 'enquiries_unanswered' => 2, 'jobs_failing' => ['mail'], 'update_problem' => 'Version 2.9.0 did not work']), $fleetNow)['reasons'],
], [['score' => 0, 'reasons' => []], ['down', 'errors'], ['not_reporting'], ['no_heartbeat'], ['update_failed', 'jobs_failing', 'no_backup', 'enquiries']]);
$fleetClean = Kaleta\Fleet\Console::clean(['version' => '2.9.0', 'name' => str_repeat('x', 400), 'secret' => 'drop me', 'problems' => [['check' => 'Mail', 'status' => 'warning', 'detail' => ['deep' => ['deeper' => ['deepest' => 1]]]]], 'visits_7_days' => 12.7]);
check('2.9: a heartbeat keeps only the known keys with sane values', [isset($fleetClean['secret']), mb_strlen($fleetClean['name']), $fleetClean['version'], $fleetClean['visits_7_days'], $fleetClean['problems'][0]['check']],
    [false, 255, '2.9.0', 12, 'Mail']);
/* ---------- 2.8: domain and mail watch (Core\DomainWatch) – no network, the lookups are fixtures ---------- */
$watchClass = Kaleta\Core\DomainWatch::class;
check('DomainWatch: registrable domain – www., subdomains and two-level suffixes', array_map($watchClass::registrableDomain(...), ['www.example.cz', 'shop.firma.example.co.uk', 'Example.COM', 'www.example.com.au', 'example.cz.']), ['example.cz', 'example.co.uk', 'example.com', 'example.com.au', 'example.cz']);
check('DomainWatch: a public host is not an IP, localhost or a development suffix', array_map($watchClass::isPublicHost(...), ['www.example.cz', '127.0.0.1', 'localhost', 'web.test', 'kaleta.localhost', '::1', '']), [true, false, false, false, false, false, false]);
check('DomainWatch: SPF covers the SMTP server – include of a known provider, a/mx in the own domain, redirect', [
    $watchClass::spfCovers('v=spf1 include:_spf.google.com ~all', 'example.cz', 'smtp.gmail.com'),
    $watchClass::spfCovers('v=spf1 include:_spf.google.com ~all', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('v=spf1 a mx -all', 'example.cz', 'mail.example.cz'),
    $watchClass::spfCovers('v=spf1 a mx -all', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('v=spf1 redirect=_spf.seznam.cz', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('v=spf1 ip4:93.184.216.34 -all', 'example.cz', 'smtp.seznam.cz'),
    $watchClass::spfCovers('google-site-verification=abc', 'example.cz', 'smtp.seznam.cz'),
], [true, false, true, false, true, null, null]);
check('DomainWatch: suggested SPF – provider include, own server, mail() from the web server', [
    $watchClass::suggestedSpf('example.cz', 'smtp.gmail.com', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', 'mail.example.cz', 'www.example.cz'),
    $watchClass::suggestedSpf('example.cz', 'smtp.relay-xyz.io', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', '', 'www.example.cz'), $watchClass::suggestedSpf('example.cz', '', 'web12.hosting.net'),
], ['v=spf1 mx include:_spf.google.com ~all', 'v=spf1 a mx ~all', 'v=spf1 mx include:relay-xyz.io ~all', 'v=spf1 a mx ~all', 'v=spf1 mx a:hosting.net ~all']);
check('DomainWatch: suggested DMARC and parsing', [$watchClass::suggestedDmarc('info@example.cz'), $watchClass::suggestedDmarc(''), $watchClass::parseDmarc('v=DMARC1; p=quarantine; rua=mailto:dmarc@example.cz; pct=100')],
    ['v=DMARC1; p=none; rua=mailto:info@example.cz', 'v=DMARC1; p=none', ['p' => 'quarantine', 'rua' => 'mailto:dmarc@example.cz']]);
$rdap = '{"objectClassName":"domain","ldhName":"example.cz","events":[{"eventAction":"registration","eventDate":"2010-05-01T10:00:00Z"},{"eventAction":"expiration","eventDate":"2027-03-14T10:00:00Z"}]}';
check('DomainWatch: RDAP expiry – the expiration event, none, invalid JSON', [$watchClass::rdapExpiry($rdap), $watchClass::rdapExpiry('{"events":[{"eventAction":"registration","eventDate":"2010-05-01T10:00:00Z"}]}'), $watchClass::rdapExpiry('nonsense')],
    [gmmktime(10, 0, 0, 3, 14, 2027), null, null]);
check('DomainWatch: thresholds – 21 days warning, 7 days error, unknown ok', array_map($watchClass::level(...), [60, 21, 20, 7, 6, 0, -3, null]), ['ok', 'ok', 'varovani', 'varovani', 'chyba', 'chyba', 'chyba', 'ok']);
$watchNow = 1_800_000_000;
$watchDns = fn (string $name, int $type): array => match ($name) {
    'example.cz' => [['type' => 'TXT', 'txt' => 'google-site-verification=abc'], ['type' => 'TXT', 'txt' => 'v=spf1 include:_spf.google.com ~all']],
    '_dmarc.example.cz' => [['type' => 'TXT', 'txt' => 'v=DMARC1; p=none; rua=mailto:dmarc@example.cz']],
    'google._domainkey.example.cz' => [['type' => 'TXT', 'txt' => 'v=DKIM1; k=rsa; p=MIGfMA0G']],
    default => [],
};
$watchHttp = fn (string $url): array => $url === 'https://rdap.org/domain/example.cz' ? [200, json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => gmdate('c', $watchNow + 100 * 86400)]]])] : [404, ''];
$watchSite = ['site_host' => 'www.example.cz', 'https' => true, 'mail_domain' => 'example.cz', 'smtp_host' => 'smtp.gmail.com', 'report_email' => 'info@example.cz'];
$watchResult = (new $watchClass($watchDns, $watchHttp, fn (string $host): int => $watchNow + 15 * 86400))->collect($watchSite, $watchNow);
check('DomainWatch: SPF, DMARC and DKIM found, SPF includes the SMTP server, days left', [$watchResult['mail']['spf'], $watchResult['mail']['spf_covers_smtp'], $watchResult['mail']['dmarc'] !== null, $watchResult['mail']['dkim'], $watchResult['tls']['days'], $watchResult['domain']['days'], $watchResult['domain']['name']],
    ['v=spf1 include:_spf.google.com ~all', true, true, 'google', 15, 100, 'example.cz']);
check('DomainWatch: rows – a certificate under 21 days is a warning, the rest ok', array_column($watchClass::rows($watchResult, false, $watchNow), 'stav', 'nazev'),
    [t('SPF record') => 'ok', t('DMARC record') => 'ok', t('DKIM signature') => 'ok', t('Certificate') => 'varovani', t('Domain registration') => 'ok']);
check('DomainWatch: handover lists only the certificate here', array_column($watchClass::handoverFindings($watchResult), 'key'), ['certificate']);
$watchBare = (new $watchClass(fn (): array => [], fn (): array => [404, ''], fn (): int => throw new RuntimeException('refused')))->collect(['site_host' => 'www.example.cz', 'https' => true, 'mail_domain' => 'example.cz', 'smtp_host' => ''], $watchNow);
check('DomainWatch: nothing in DNS – SPF and DMARC missing, DKIM not found, registry without RDAP is unknown, certificate error', [$watchBare['mail']['spf'], $watchBare['mail']['dmarc'], $watchBare['mail']['dkim'], $watchBare['domain']['expires'], $watchBare['domain']['error'], $watchBare['tls']['error']], [null, null, null, null, null, 'refused']);
check('DomainWatch: rows suggest the records to add; DKIM not found is a note, not a warning', [
    str_contains($watchClass::rows($watchBare, false, $watchNow)[0]['info'], 'v=spf1 a mx ~all'), str_contains($watchClass::rows($watchBare, false, $watchNow)[1]['info'], '_dmarc.example.cz'),
    array_column($watchClass::rows($watchBare, false, $watchNow), 'stav'),
], [true, true, ['varovani', 'varovani', 'ok', 'varovani', 'ok']]);
check('DomainWatch: handover lists the certain problems only (not DKIM, not an unknown registry)', array_column($watchClass::handoverFindings($watchBare), 'key'), ['spf', 'dmarc']);
$watchExpired = (new $watchClass($watchDns, fn (string $url): array => [200, json_encode(['events' => [['eventAction' => 'expiration', 'eventDate' => gmdate('c', $watchNow - 86400)]]])], fn (string $host): int => $watchNow - 3 * 86400))->collect($watchSite, $watchNow);
check('DomainWatch: an expired certificate and domain are errors and handover findings', [array_column($watchClass::rows($watchExpired, false, $watchNow), 'stav', 'nazev')[t('Certificate')], array_column($watchClass::rows($watchExpired, false, $watchNow), 'stav', 'nazev')[t('Domain registration')], array_column($watchClass::handoverFindings($watchExpired), 'key')],
    ['chyba', 'chyba', ['certificate', 'domain']]);
$watchFailed = (new $watchClass(fn (): false => false, $watchHttp, fn (string $host): int => $watchNow + 90 * 86400))->collect($watchSite, $watchNow);
check('DomainWatch: a failed DNS lookup is reported, never judged', [$watchFailed['mail']['error'], array_column($watchClass::rows($watchFailed, false, $watchNow), 'stav')[0], $watchClass::handoverFindings($watchFailed)], ['dns', 'varovani', []]);
$watchLocal = (new $watchClass(fn (): array => throw new RuntimeException('no network'), fn (): array => throw new RuntimeException('no network'), fn (): int => throw new RuntimeException('no network')))->collect(['site_host' => '127.0.0.1', 'https' => false, 'mail_domain' => 'example.cz', 'smtp_host' => ''], $watchNow);
check('DomainWatch: a site on a local address makes no request and is one ok row', [$watchLocal['local'] ?? false, $watchLocal['mail'], array_column($watchClass::rows($watchLocal, false, $watchNow), 'stav'), $watchClass::handoverFindings($watchLocal)], [true, null, ['ok'], []]);
check('DomainWatch: no result yet and the public demo are one ok row each', [array_column($watchClass::rows(null, false, $watchNow), 'stav'), array_column($watchClass::rows(null, true, $watchNow), 'stav'), $watchClass::handoverFindings(null)], [['ok'], ['ok'], []]);
check('DomainWatch: the cache lives in a setting, not editable, not exported', [isset(Kaleta\Core\Settings::DEFAULTS[$watchClass::SETTING]), Kaleta\Admin\Modules\Settings::verifyValue($watchClass::SETTING, 'x'), in_array($watchClass::SETTING, Kaleta\Core\SiteExport::SETTINGS, true)], [true, null, false]);
/* ---------- 2.8: real-user speed (Core\WebVitals) – histogram buckets, p75, Google's ratings, the audit rule ---------- */
use Kaleta\Core\WebVitals;
check('2.8: WebVitals::bucket – an edge value belongs to its bucket, the next value to the next one, above the last edge to the open bucket',
    [WebVitals::bucket('lcp', 2500), WebVitals::bucket('lcp', 2500.1), WebVitals::bucket('lcp', 0), WebVitals::bucket('lcp', 9000), WebVitals::bucket('cls', 0.1), WebVitals::bucket('cls', 0.11), WebVitals::bucket('inp', 200), WebVitals::bucket('inp', 201)],
    [4, 5, 0, 11, 4, 5, 3, 4]);
check('2.8: WebVitals::THRESHOLDS are bucket edges, so a rating from a bucket edge is exact',
    array_map(fn (string $m): bool => in_array(WebVitals::THRESHOLDS[$m][0], WebVitals::BUCKETS[$m], true) && in_array(WebVitals::THRESHOLDS[$m][1], WebVitals::BUCKETS[$m], true), array_keys(WebVitals::BUCKETS)), [true, true, true]);
// 100 samples: 70 in (1500, 2000], 10 in (2000, 2500], 20 in (4000, 5000] – the 75th sample is in the (2000, 2500] bucket
check('2.8: WebVitals::percentile – p75 is the upper edge of the bucket with the 75th sample', WebVitals::percentile('lcp', [3 => 70, 4 => 10, 8 => 20]), 2500.0);
check('2.8: WebVitals::percentile – p50 of the same samples', WebVitals::percentile('lcp', [3 => 70, 4 => 10, 8 => 20], 0.5), 2000.0);
check('2.8: WebVitals::percentile – a single sample is its own p75', WebVitals::percentile('inp', [2 => 1]), 150.0);
check('2.8: WebVitals::percentile – the open bucket reports its lower edge (at least that), no samples give null', [WebVitals::percentile('lcp', [11 => 4]), WebVitals::percentile('cls', [])], [8000.0, null]);
check('2.8: WebVitals::rating – Google\'s thresholds', [WebVitals::rating('lcp', 2500), WebVitals::rating('lcp', 2501), WebVitals::rating('lcp', 4000), WebVitals::rating('lcp', 4001),
    WebVitals::rating('cls', 0.1), WebVitals::rating('cls', 0.25), WebVitals::rating('cls', 0.3), WebVitals::rating('inp', 200), WebVitals::rating('inp', 500), WebVitals::rating('inp', 501)],
    ['good', 'needs_improvement', 'needs_improvement', 'poor', 'good', 'needs_improvement', 'poor', 'good', 'needs_improvement', 'poor']);
check('2.8: WebVitals::isRegression – more than 25 % worse with at least 30 measurements in both periods', [WebVitals::isRegression(3000.0, 2000.0, 30, 30), WebVitals::isRegression(2500.0, 2000.0, 100, 100),
    WebVitals::isRegression(2501.0, 2000.0, 100, 100), WebVitals::isRegression(3000.0, 2000.0, 29, 30), WebVitals::isRegression(3000.0, 2000.0, 30, 29), WebVitals::isRegression(3000.0, null, 30, 30), WebVitals::isRegression(null, 2000.0, 30, 30)],
    [true, false, true, false, false, false, false]);
check('2.8: the beacon script looks for nothing but its own endpoint and sends with sendBeacon', [str_contains((string) file_get_contents(KALETA_ROOT . '/image/vitals.js'), "getAttribute('data-vitals')"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/vitals.js'), 'navigator.sendBeacon('), preg_match('/document\.cookie|localStorage|sessionStorage/', (string) file_get_contents(KALETA_ROOT . '/image/vitals.js'))], [true, true, 0]);
check('2.8: /vitals is a reserved address', in_array('vitals', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true), true);

/* ---------- 2.8: font preloading – only the site's own WOFF2 files that render text above the fold ---------- */
$fontsDs = ['vlastni_pisma' => [['nazev' => 'Firma Sans', 'soubor' => 'media/pisma/firma-sans.woff2', 'tucny' => 'media/pisma/firma-sans-bold.woff2'], ['nazev' => 'Firma Serif', 'soubor' => 'media/pisma/firma-serif.woff2', 'tucny' => ''],
    ['nazev' => 'Old', 'soubor' => 'media/pisma/old.woff', 'tucny' => '']]];
check('2.8: DesignSystem::fontPreloads – body = the regular file, headings = the bold file of the heading font', Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'vlastni-1', 'pismo_titulky' => 'vlastni-1'] + $fontsDs, '/web'),
    '<link rel="preload" href="/web/media/pisma/firma-sans.woff2" as="font" type="font/woff2" crossorigin>' . "\n" . '<link rel="preload" href="/web/media/pisma/firma-sans-bold.woff2" as="font" type="font/woff2" crossorigin>');
check('2.8: DesignSystem::fontPreloads – a heading font without a bold file preloads its only file; a system body font preloads nothing', Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'moderni', 'pismo_titulky' => 'vlastni-2'] + $fontsDs),
    '<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>');
check('2.8: DesignSystem::fontPreloads – the same file once, system fonts and WOFF (not WOFF2) never', [Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'vlastni-2', 'pismo_titulky' => 'vlastni-2'] + $fontsDs),
    Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'moderni', 'pismo_titulky' => 'klasicke'] + $fontsDs), Kaleta\Builder\DesignSystem::fontPreloads(['pismo_text' => 'vlastni-3', 'pismo_titulky' => 'vlastni-3'] + $fontsDs)],
    ['<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>', '', '']);
$fontsCss = Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::sanitize(['pismo_text' => 'vlastni-1', 'pismo_titulky' => 'vlastni-2'] + $fontsDs), '/web');
check('2.8: every @font-face of the site\'s own fonts swaps in the fallback font while the file loads, and the preloaded files are the ones @font-face uses',
    [substr_count($fontsCss, '@font-face'), substr_count($fontsCss, 'font-display: swap'), str_contains($fontsCss, 'url("/web/media/pisma/firma-sans-bold.woff2")'), str_contains($fontsCss, 'url("/web/media/pisma/firma-serif.woff2")')], [4, 4, true, true]);
/* ---------- security hygiene (2.8): which accounts and connections count as unused, whom the automatic suspension never blocks ---------- */
$hygieneNow = new DateTimeImmutable('2026-10-02 12:00:00');
$account = fn (int $idu, int $admin, ?string $signIn, ?string $confirmed = null, ?string $claude = null, int $blocked = 0): array =>
    ['idu' => $idu, 'user' => 'u' . $idu, 'jmeno' => '', 'admin' => $admin, 'blokovat' => $blocked, 'posledni_login' => $signIn, 'potvrzeno' => $confirmed, 'pouzit' => $claude];
$hygieneAccounts = [
    $account(1, 2, '2026-10-01 10:00:00'),                                 // the active administrator
    $account(2, 2, '2026-06-01 10:00:00'),                                 // an administrator unused for 123 days
    $account(3, 1, '2026-07-04 11:59:59'),                                 // one second over 90 days
    $account(4, 1, '2026-07-04 12:00:00'),                                 // exactly 90 days – still fine
    $account(5, 0, null, '2026-05-01 00:00:00'),                           // never signed in, created long ago
    $account(6, 0, null, '2026-09-20 00:00:00'),                           // invited recently, not signed in yet
    $account(7, 1, '2026-01-01 00:00:00', '2026-02-01 00:00:00', '2026-09-30 08:00:00'), // old sign-in, but Claude used the account
    $account(8, 1, '2026-01-01 00:00:00', null, null, 1),                  // already blocked
    $account(9, 1, null),                                                   // nothing is known – never treated as unused
];
$hygieneUnused = Kaleta\Core\SecurityHygiene::unusedAccounts($hygieneAccounts, $hygieneNow);
check('Hygiene: unused accounts by sign-in, confirmation and Claude use, over 90 days only', array_column($hygieneUnused, 'idu'), [2, 3, 5]);
check('Hygiene: the last activity is the latest of the three moments', Kaleta\Core\SecurityHygiene::lastActivity($hygieneAccounts[6]), '2026-09-30 08:00:00');
check('Hygiene: an account with no record is unknown, not unused', Kaleta\Core\SecurityHygiene::lastActivity($hygieneAccounts[8]), null);
check('Hygiene: the signed-in user is never blocked', array_column(Kaleta\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 3), 'idu'), [2, 5]);
check('Hygiene: an unused administrator is blocked while another administrator stays active', array_column(Kaleta\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 0), 'idu'), [2, 3, 5]);
$hygieneAllOld = $hygieneAccounts;
$hygieneAllOld[0] = $account(1, 2, '2026-06-15 10:00:00'); // now every administrator is unused: the most recently active one stays
$hygieneUnusedAll = Kaleta\Core\SecurityHygiene::unusedAccounts($hygieneAllOld, $hygieneNow);
check('Hygiene: every administrator unused – the last active one is kept', [array_column($hygieneUnusedAll, 'idu'), array_column(Kaleta\Core\SecurityHygiene::blockable($hygieneUnusedAll, $hygieneAllOld, 0), 'idu')], [[1, 2, 3, 5], [2, 3, 5]]);
$hygieneOnlyAdmin = [$account(1, 2, '2026-01-01 00:00:00'), $account(2, 0, '2026-01-01 00:00:00')];
check('Hygiene: the only administrator is never blocked', array_column(Kaleta\Core\SecurityHygiene::blockable(Kaleta\Core\SecurityHygiene::unusedAccounts($hygieneOnlyAdmin, $hygieneNow), $hygieneOnlyAdmin, 0), 'idu'), [2]);
$hygieneConnections = [
    ['kind' => 'token', 'id' => 1, 'idu' => 1, 'user' => 'a', 'name' => 'laptop', 'last' => '2026-09-30 00:00:00', 'expiry' => null],
    ['kind' => 'token', 'id' => 2, 'idu' => 1, 'user' => 'a', 'name' => 'old', 'last' => '2026-08-03 11:59:59', 'expiry' => null],
    ['kind' => 'app', 'id' => 'abc', 'idu' => 2, 'user' => 'b', 'name' => 'Claude', 'last' => '2026-08-01 00:00:00', 'expiry' => '2026-10-20 00:00:00'],
    ['kind' => 'app', 'id' => 'def', 'idu' => 2, 'user' => 'b', 'name' => 'Claude', 'last' => '2026-08-03 12:00:00', 'expiry' => null],
];
check('Hygiene: connections unused for over 60 days', array_map(fn (array $c): string => $c['kind'] . ':' . $c['id'], Kaleta\Core\SecurityHygiene::unusedConnections($hygieneConnections, $hygieneNow)), ['token:2', 'app:abc']);
check('Hygiene: days ago for messages', Kaleta\Core\SecurityHygiene::daysAgo('2026-06-01 10:00:00', $hygieneNow), 123);
check('Hygiene: thresholds are constants', [Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS, Kaleta\Core\SecurityHygiene::CONNECTION_DAYS], [90, 60]);

/* ---------- 2.10: true until and review by (Core\Validity) ---------- */
$validityRows = [
    ['id' => 1, 'title' => 'Spring offer', 'visible' => 1, 'valid_until' => '2026-09-30', 'review_by' => null],   // expired yesterday, still visible
    ['id' => 2, 'title' => 'Today', 'visible' => 1, 'valid_until' => '2026-10-01', 'review_by' => '2026-10-01'],  // true until today: still true, review due today
    ['id' => 3, 'title' => 'Hidden', 'visible' => 0, 'valid_until' => '2026-01-01', 'review_by' => '2026-01-01'], // already hidden: not hidden again, review still due
    ['id' => 4, 'title' => 'Future', 'visible' => 1, 'valid_until' => '2026-12-31', 'review_by' => '2026-10-02'],
    ['id' => 5, 'title' => 'Asked', 'visible' => 1, 'valid_until' => null, 'review_by' => '2026-09-20'],           // review already recorded for this date
    ['id' => 6, 'title' => 'Re-asked', 'visible' => 1, 'valid_until' => null, 'review_by' => '2026-09-25'],        // recorded for an older date – a changed date asks again
];
$validityAsked = ['5|2026-09-20' => true, '6|2026-09-01' => true];
check('2.10 Validity::expired – visible rows whose day has passed, today still counts as true', array_column(Kaleta\Core\Validity::expired($validityRows, '2026-10-01'), 'id'), [1]);
check('2.10 Validity::expired – the day after, today\'s row expires too', array_column(Kaleta\Core\Validity::expired($validityRows, '2026-10-02'), 'id'), [1, 2]);
check('2.10 Validity::dueForReview – today or earlier, once per content and date', array_column(Kaleta\Core\Validity::dueForReview($validityRows, '2026-10-01', $validityAsked), 'id'), [2, 3, 6]);
check('2.10 Validity::dueForReview – nothing asked yet', array_column(Kaleta\Core\Validity::dueForReview($validityRows, '2026-10-02', []), 'id'), [2, 3, 4, 5, 6]);
check('2.10 Validity::date – a form or Claude date, a datetime cut to its day, nonsense and empty = none', [Kaleta\Core\Validity::date('2026-10-01'), Kaleta\Core\Validity::date(' 2026-10-01T12:00 '),
    Kaleta\Core\Validity::date('2026-02-30'), Kaleta\Core\Validity::date(''), Kaleta\Core\Validity::date(null), Kaleta\Core\Validity::date('tomorrow')], ['2026-10-01', '2026-10-01', null, null, null, null]);
check('2.10 Validity::isDate', [Kaleta\Core\Validity::isDate('2026-10-01'), Kaleta\Core\Validity::isDate('2026-13-01'), Kaleta\Core\Validity::isDate('1.10.2026'), Kaleta\Core\Validity::isDate(20261001)], [true, false, false, false]);
check('2.10: the validity job, the audit kind and the events are known', [Kaleta\Core\Scheduler::JOBS['validity'][0], Kaleta\Core\Scheduler::JOBS['validity'][1], isset(Kaleta\Core\Scheduler::jobs()['validity']),
    Kaleta\Core\Audit::KINDS['review'], isset(Kaleta\Core\Events::TYPES['content.expired'], Kaleta\Core\Events::TYPES['content.review'])], [3600, 'any', true, 'Review by', true]);

/* ---------- 2.11: ready-made collections (Builder\Presets) and the date-time, file and location fields ---------- */
$presets = Kaleta\Builder\Presets::all();
$presetProblems = [];
foreach ($presets as $presetKey => $p) {
    $keys = array_column($p['fields'], 0);
    $sanitized = array_column(Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]] + (isset($f[3]['preset']) ? ['kolekce' => 'x'] : []), $p['fields'])), 'klic');
    if ($keys !== $sanitized || count($keys) > 30) {
        $presetProblems[] = $presetKey . ': field keys change when sanitized';
    }
    foreach ($p['fields'] as $f) {
        if (!isset(Kaleta\Builder\Collections::FIELD_TYPES[$f[2]]) || ($f[2] === 'polozka' && !isset($presets[$f[3]['preset'] ?? '']) && ($f[3]['preset'] ?? '') !== 'branches')) {
            $presetProblems[] = $presetKey . ': ' . $f[0] . ' has an unknown type or linked preset';
        }
    }
    if (is_array($p['schema']) && Kaleta\Builder\CollectionSchema::sanitize($p['schema'], Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]], $p['fields'])))['pole'] != $p['schema']['pole']) {
        $presetProblems[] = $presetKey . ': the schema maps a field that does not exist';
    }
    if (trim((string) $p['claude']) === '' || trim((string) $p['description']) === '') {
        $presetProblems[] = $presetKey . ': no description or instructions for Claude';
    }
}
check('2.11 Presets: every preset is valid (keys survive sanitizing, known types, schema maps existing fields, described)', [isset($presets['people']), $presetProblems], [true, []]);
check('2.11 Presets::field – only in a collection made from the preset, and only while the field is there with its type', [
    Kaleta\Builder\Presets::field(['preset' => 'people', 'pole' => [['klic' => 'phone', 'typ' => 'text']]], 'people', 'phone', ['text']),
    Kaleta\Builder\Presets::field(['preset' => 'people', 'pole' => [['klic' => 'phone', 'typ' => 'cislo']]], 'people', 'phone', ['text']),
    Kaleta\Builder\Presets::field(['preset' => '', 'pole' => [['klic' => 'phone', 'typ' => 'text']]], 'people', 'phone', ['text'])], ['phone', null, null]);
check('2.11 cleanDateTime: date and time, a whole day, the T of an input, midnight = the whole day, nonsense', array_map(Kaleta\Builder\Collections::cleanDateTime(...),
    ['2026-10-24 18:30', '2026-10-24', '2026-10-24T09:05', '2026-10-24T00:00', '2026-10-24 9:05:00', '', '2026-02-30 10:00', '2026-10-24 25:00', 'tomorrow']),
    ['2026-10-24 18:30', '2026-10-24', '2026-10-24 09:05', '2026-10-24', '2026-10-24 09:05', '', null, null, null]);
check('2.11 cleanLocation: latitude, longitude – rounded, also with a semicolon; out of range and text are not valid', array_map(Kaleta\Builder\Collections::cleanLocation(...),
    ['50.0875, 14.4214', '50.08754321;14.42139876', '-33.9,18.42', '', '91, 10', '50, 181', 'Praha']), ['50.0875, 14.4214', '50.087543, 14.421399', '-33.9, 18.42', '', null, null, null]);
$fileFields = [['klic' => 'start', 'popisek' => 'Start', 'typ' => 'termin'], ['klic' => 'sheet', 'popisek' => 'Datasheet', 'typ' => 'soubor'], ['klic' => 'place', 'popisek' => 'Place', 'typ' => 'poloha']];
$fileErrors = [];
check('2.11 sanitizeData: a file from Media or https, never a path out of it', [Kaleta\Builder\Collections::sanitizeData($fileFields, ['start' => '2026-11-02T17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19,16.61'], $fileErrors),
    Kaleta\Builder\Collections::sanitizeData($fileFields, ['sheet' => '/media/../config.php'], $fileErrors), Kaleta\Builder\Collections::sanitizeData($fileFields, ['sheet' => 'javascript:alert(1)'], $fileErrors)['sheet']],
    [['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19, 16.61'], ['start' => '', 'sheet' => '', 'place' => ''], '']);
$fileValues = Kaleta\Builder\Collections::values(['seo_link' => 'akce', 'detail' => 1, 'pole' => $fileFields], ['nazev' => 'Den otevřených dveří', 'seo_link' => 'den', 'datum' => '2026-10-02 10:00:00',
    'data' => ['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/Cen%C3%ADk%202026.pdf', 'place' => '49.19, 16.61']], fn (string $p): string => '/' . $p);
check('2.11 values: {{start}} for visitors and {{start_iso}}, {{sheet}} a link and {{sheet_name}}', [$fileValues['start'][0], $fileValues['start_iso'][0], $fileValues['sheet'], $fileValues['sheet_name'][0], $fileValues['place'][0]],
    [format_date('2026-11-02 17:00', true), '2026-11-02 17:00', ['/media/docs/Cen%C3%ADk%202026.pdf', 'odkaz'], 'Ceník 2026.pdf', '49.19, 16.61']);

/* ---------- 2.11: branches (LocalBusiness) and the Store locator element ---------- */
check('2.11 Hours::specification – opening hours as text to OpeningHoursSpecification; nothing from a line that does not parse or from an empty text', [
    Kaleta\Core\Hours::specification("Mo-Fr 9-17\nSa 9:00-12:00"), Kaleta\Core\Hours::specification("Mo-Fr 9-17\nby appointment"), Kaleta\Core\Hours::specification('')], [
    [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '17:00'],
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '09:00', 'closes' => '12:00']], [], []]);
$branchFields = [['klic' => 'address', 'popisek' => 'Address', 'typ' => 'text'], ['klic' => 'location', 'popisek' => 'Location', 'typ' => 'poloha'], ['klic' => 'phone', 'popisek' => 'Phone', 'typ' => 'text'],
    ['klic' => 'email', 'popisek' => 'E-mail', 'typ' => 'text'], ['klic' => 'hours', 'popisek' => 'Opening hours', 'typ' => 'radky']];
$branchCollection = ['seo_link' => 'pobocky', 'detail' => 1, 'pole' => $branchFields,
    'schema_org' => json_encode(['typ' => 'LocalBusiness', 'pole' => ['address' => 'address', 'telephone' => 'phone', 'email' => 'email', 'geo' => 'location', 'openingHours' => 'hours']])];
$branch = fn (array $data): ?array => Kaleta\Builder\CollectionSchema::forItem($branchCollection, ['nazev' => 'Brno', 'data' => $data], 'https://example.com/pobocky/brno', 'Our Brno store', '', 'https://example.com#firma');
$brnoNode = $branch(['address' => 'Náměstí Svobody 1, Brno', 'location' => '49.1951, 16.6068', 'phone' => '+420 123 456 789', 'email' => 'brno@example.com', 'hours' => 'Mo-Fr 9-17']);
check('2.11 CollectionSchema: a branch is a LocalBusiness of the company with its address, contacts, geo and opening hours', [
    $brnoNode['@type'], $brnoNode['address'], $brnoNode['telephone'], $brnoNode['geo'], count($brnoNode['openingHoursSpecification']), $brnoNode['parentOrganization'], isset($brnoNode['provider'])],
    ['LocalBusiness', 'Náměstí Svobody 1, Brno', '+420 123 456 789', ['@type' => 'GeoCoordinates', 'latitude' => 49.1951, 'longitude' => 16.6068], 1, ['@id' => 'https://example.com#firma'], false]);
$sparseNode = $branch(['address' => 'Somewhere 1', 'location' => 'in the centre', 'hours' => 'always open']);
check('2.11 CollectionSchema: no geo from text that is not a location and no hours that do not parse – rather left out than guessed', [isset($sparseNode['geo']), isset($sparseNode['openingHoursSpecification']), $sparseNode['address']], [false, false, 'Somewhere 1']);
check('2.11 CollectionSchema::geo', [Kaleta\Builder\CollectionSchema::geo('50.0875;14.4214'), Kaleta\Builder\CollectionSchema::geo(''), Kaleta\Builder\CollectionSchema::geo('Praha')],
    [['@type' => 'GeoCoordinates', 'latitude' => 50.0875, 'longitude' => 14.4214], null, null]);
check('2.11 Presets: branches – LocalBusiness mapped to the location and hours fields, created before the team', [
    $presets['branches']['schema']['typ'], $presets['branches']['schema']['pole']['geo'], $presets['branches']['schema']['pole']['openingHours'], $presets['branches']['order'] < 100,
    Kaleta\Builder\Presets::field(['preset' => 'branches', 'pole' => [['klic' => 'location', 'typ' => 'poloha']]], 'branches', 'location', ['poloha'])], ['LocalBusiness', 'location', 'hours', true, 'location']);
$branchTemplate = Kaleta\Builder\Presets::itemTemplate($presets['branches'], Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]], $presets['branches']['fields'])));
$branchTemplateJson = Kaleta\Builder\Build::toJson($branchTemplate);
check('2.11 Presets: the branch item template shows the photo, contacts, hours and a click-to-load map of the address', [
    str_contains($branchTemplateJson, '{{photo}}'), str_contains($branchTemplateJson, '<p>{{hours}}</p>'), str_contains($branchTemplateJson, '"typ":"mapa"'), str_contains($branchTemplateJson, '"adresa":"{{address}}"')], [true, true, true, true]);
check('2.11 StoreLocator::telHref – digits and one leading plus, nothing from text', array_map(Kaleta\Builder\Elements\StoreLocator::telHref(...), ['+420 123 456 789', '(0049) 30 / 123-45', 'call us', '']),
    ['tel:+420123456789', 'tel:00493012345', '', '']);
check('2.11 StoreLocator::coordinates and directionsUrl – the address first, then the coordinates, otherwise nothing', [
    Kaleta\Builder\Elements\StoreLocator::coordinates('49.1951, 16.6068'), Kaleta\Builder\Elements\StoreLocator::coordinates('nowhere'),
    Kaleta\Builder\Elements\StoreLocator::directionsUrl('Náměstí Svobody 1, Brno', ['49.1951', '16.6068']), Kaleta\Builder\Elements\StoreLocator::directionsUrl('', ['49.1951', '16.6068']), Kaleta\Builder\Elements\StoreLocator::directionsUrl('', null)],
    [['49.1951', '16.6068'], null, 'https://www.google.com/maps/search/?api=1&query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody%201%2C%20Brno', 'https://www.google.com/maps/search/?api=1&query=49.1951%2C16.6068', '']);
check('2.11 Store locator: a Dynamic element with an English name, its texts for the script in the site dictionaries, Leaflet vendored', [
    Kaleta\Builder\Elements\StoreLocator::GROUP, Kaleta\Mcp\Vocabulary::TYPES['pobocky'], Kaleta\Mcp\Vocabulary::CONTENT['pole_poloha'],
    isset((require KALETA_SYSTEM . '/jazyky/cs.php')['Nearest to me']), isset((require KALETA_SYSTEM . '/jazyky/de.php')['Location access was refused – the list stays in its usual order.']),
    is_file(KALETA_ROOT . '/image/vendor/leaflet/leaflet.js') && is_file(KALETA_ROOT . '/image/vendor/leaflet/leaflet.css') && is_file(KALETA_ROOT . '/image/vendor/leaflet/images/marker-icon.png') && is_file(KALETA_ROOT . '/image/vendor/leaflet/LICENSE'),
    preg_match('/Leaflet 1\.9\.4/', (string) file_get_contents(KALETA_ROOT . '/image/vendor/leaflet/leaflet.js')) === 1],
    ['Dynamic', 'store_locator', 'location_field', true, true, true, true]);

echo $errors === 0 ? "  ok     jednotkové testy ({$total})\n" : "  NALEZENO CHYB: {$errors} z {$total}\n";
exit($errors === 0 ? 0 : 1);
