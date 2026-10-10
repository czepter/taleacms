<?php
/**
 * Unit tests of the Talea core - no framework and no database: php tools/unit-tests.php
 *
 * They guard what the site tests (tests/Site) cannot detect: cryptography, parsing and text conversions.
 * A new test = another call of over('description', $skutecne, $ocekavane).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Talea\Core\Assistant;
use Talea\Core\Search;
use Talea\Core\Migrator;
use Talea\Core\SqlScript;
use Talea\Core\Files;
use Talea\Core\Totp;
use Talea\Front\Seo;
use Talea\Front\NewsText;

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
    echo "  FAIL   {$label}\n         expected: " . var_export($expected, true) . "\n         actual:   " . var_export($actual, true) . "\n";
}

/* ---------- text conversions ---------- */
check('slugify: diacritics and spaces', slugify('Příliš žluťoučký kůň!'), 'prilis-zlutoucky-kun'); // check-english: allow
check('slugify: empty input', slugify('***'), 'n-a');
check('slugify: length', strlen(slugify(str_repeat('abc ', 100), 20)) <= 20, true);
check('remove_diacritics', remove_diacritics('Ďábelské ÓDY – Straße'), 'Dabelske ODY – Strasse'); // check-english: allow
check('e(): quotes and tags', e('<a href="x">\'</a>'), '&lt;a href=&quot;x&quot;&gt;&#039;&lt;/a&gt;');
check('date: Czech format', Talea\Core\Language::runWith('cs', fn () => format_date('2026-09-05 07:03:00', true)), '5. 9. 2026 07:03');

/* ---------- search ---------- */
check('Search::normalize', Search::normalize('<p>Nábřeží&nbsp;<b>Vltavy</b></p><h2>Proměna!</h2>'), 'nabrezi vltavy promena'); // check-english: allow
check('Search::words: short words drop out', Search::words('co je na Nábřeží'), ['nabrezi']); // check-english: allow
check('Search::words: fulltext operators do not take effect', Search::words('+tajne -verejne "fraze" (x) ~y*'), ['tajne', 'verejne', 'fraze']);
check('Search::words: at most 8 words', count(Search::words('aaa bbb ccc ddd eee fff ggg hhh iii jjj')), 8);

/* ---------- TOTP (RFC 6238, secret "12345678901234567890") ---------- */
$totpSeed = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
check('TOTP: vektor T=59', Totp::code($totpSeed, intdiv(59, 30)), '287082');
check('TOTP: vektor T=1111111109', Totp::code($totpSeed, intdiv(1111111109, 30)), '081804');
check('TOTP: vektor T=2000000000', Totp::code($totpSeed, intdiv(2000000000, 30)), '279037');
check('TOTP: a valid code passes', Totp::verify($totpSeed, '287082', 59), true);
check('TOTP: the neighbouring window passes', Totp::verify($totpSeed, '287082', 59 + 30), true);
check('TOTP: an old code fails', Totp::verify($totpSeed, '287082', 59 + 300), false);
check('TOTP: nesmysl neprojde', Totp::verify($totpSeed, 'abcdef', 59), false);
check('TOTP: a new secret has 160 bits', strlen(Totp::newSecret()), 32);

/* ---------- migrations: splitting SQL into statements ---------- */
$sql = "-- komentář\nALTER TABLE tl_news ADD COLUMN x INT;   -- poznámka za příkazem\nCREATE TABLE tl_nova (\n  a VARCHAR(10) DEFAULT ';'\n);\nALTER TABLE tl_a ADD CONSTRAINT fk_a FOREIGN KEY (b) REFERENCES tl_b (id);\n"; // check-english: allow
$statements = SqlScript::statements($sql, 'web_');
check('Migrations::statements: count', count($statements), 3);
check('Migrations::statements: table prefix', str_contains($statements[1], 'CREATE TABLE web_nova'), true);
check('Migrations::statements: a semicolon inside a value does not split the statement', str_contains($statements[1], "DEFAULT ';'"), true);
check('Migrations::statements: constraint prefix', str_contains($statements[2], 'CONSTRAINT web_fk_a') && str_contains($statements[2], 'REFERENCES web_b'), true);
// Phinx migrations: timestamped files, one class each, no charset or collation anywhere (it is set once on the database)
$migrationFiles = glob(TALEA_SYSTEM . '/database/migrations/*.php') ?: [];
$migrationSource = implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), $migrationFiles));
check('Migrations: files are YYYYMMDDHHMMSS_name.php in ascending order, versions unique', [
    count($migrationFiles) > 0, array_keys(Migrator::files()) === array_values(array_unique(array_keys(Migrator::files()))),
    count(array_filter($migrationFiles, fn (string $f): bool => preg_match('/^\d{14}_[a-z0-9_]+\.php$/', basename($f)) !== 1)),
    preg_match_all('/^final class (\w+) extends AbstractMigration/m', $migrationSource, $migrationClasses) === count($migrationFiles) && count(array_unique($migrationClasses[1])) === count($migrationFiles),
], [true, true, 0, true]);
check('Migrations: no CHARSET, COLLATE or raw SQL in a migration', [preg_match('/\b(CHARSET|COLLATE|collation|charset)\b/i', $migrationSource), preg_match('/->execute\(/', $migrationSource)], [0, 0]);
check('Migrations: Phinx configuration uses the one charset and collation', [Migrator::phinxConfig(['name' => 'x', 'username' => 'u', 'password' => '', 'prefix' => 'web_'])['environments']['default']['collation'],
    Migrator::phinxConfig(['name' => 'x', 'username' => 'u', 'password' => '', 'prefix' => 'web_'])['environments']['default_migration_table']], ['utf8mb4_0900_ai_ci', 'web_migrations']);

/* ---------- attachments ---------- */
check('Files: a PDF is an attachment', Files::isAttachment('Zpráva.PDF'), true); // check-english: allow
check('Files: PHP is not an attachment', Files::isAttachment('shell.php'), false);
check('Files: a double extension', Files::isAttachment('shell.pdf.php'), false);
check('Soubory: SVG a HTML ne', Files::isAttachment('x.svg') || Files::isAttachment('x.html'), false);
check('Soubory: velikost', Files::size(1536), '2 kB');
check('Files::size: MB, Czech decimal comma', Talea\Core\Language::runWith('cs', fn () => Files::size(5 * 1048576)), '5,0 MB');

/* ---------- player and embedded URLs ---------- */
check('prehravac: YouTube bez cookies', str_contains(NewsText::player('https://www.youtube.com/watch?v=dQw4w9WgXcQ', '', 'T'), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
check('prehravac: youtu.be', str_contains(NewsText::player('https://youtu.be/dQw4w9WgXcQ', '', 'T'), 'embed/dQw4w9WgXcQ'), true);
check('prehravac: MP3 je <audio>', str_contains(NewsText::player('media/2026/09/epizoda.mp3', '/magazin', 'T'), '<audio controls preload="none" src="/magazin/media/2026/09/epizoda.mp3">'), true);
check('player: unknown address in knownOnly mode', NewsText::player('https://example.com/video', '', 'T', true), '');
check('player: the title is escaped', str_contains(NewsText::player('https://vimeo.com/123', '', '"><script>'), '<script>'), false);
$types = (new ReflectionClass(NewsText::class))->newInstanceWithoutConstructor();
$html = $types->embedVideoUrls('<p>Úvod</p><p>https://youtu.be/dQw4w9WgXcQ</p><p>Viz https://youtu.be/dQw4w9WgXcQ v textu.</p>'); // check-english: allow
check('embeddedUrls: only a standalone line', [substr_count($html, 'data-insert'), substr_count($html, 'Viz https://youtu.be')], [1, 1]);

/* ---------- themeless (1.6) ---------- */
check('Themeless: the page frame is the system’s own, no layout folder and no layout setting', [is_file(TALEA_ROOT . '/system/views/front/base.php'), is_dir(TALEA_ROOT . '/layout'), isset(\Talea\Core\Settings::DEFAULTS['layout'])], [true, false, false]);

/* ---------- the English dictionary covers site and admin texts ---------- */
$missingTranslation = static function (string $dictionary, array $patterns): array {
    // the source texts are English: a t() text with Czech letters is a mistake unless the Czech dictionary lists it as a key
    $translations = require dirname(__DIR__) . '/system/languages/' . str_replace('en.php', 'cs.php', $dictionary);
    $missing = [];
    foreach ($patterns as $pattern) {
        foreach (glob(dirname(__DIR__) . '/' . $pattern) ?: [] as $file) {
            preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)+)'/u", (string) file_get_contents($file), $m);
            foreach ($m[1] as $k) {
                $k = stripslashes($k);
                if (!isset($translations[$k]) && preg_match('/[áčďéěíňóřšťúůýž]/iu', $k)) { // check-english: allow
                    $missing[] = basename($file) . ': ' . $k;
                }
            }
        }
    }

    return array_values(array_unique($missing));
};
check('Front-end source texts are English (t() without Czech diacritics)', $missingTranslation('en.php', ['system/views/front/*.php', 'system/src/Front/*.php', 'system/src/Builder/*.php', 'system/src/Builder/Elements/*.php']), []);
check('English dictionaries carry only the data-driven keys (English is the source language)', array_map(fn (string $f): array => array_values(array_diff(array_keys(require dirname(__DIR__) . '/system/languages/' . $f), ['datum_format', 'date_in_words'])), ['en.php', 'admin-en.php', 'install-en.php']), [[], [], []]);

check('Build::code: a nested script does not reassemble', [str_contains(Talea\Builder\Build::code('<scr<script>x</script>ipt>alert(1)</scr<script>y</script>ipt>'), '<script'), Talea\Builder\Build::code('<iframe src="https://mapy.cz/x"></iframe>')], [false, '<iframe src="https://mapy.cz/x"></iframe>']);
check('Build::code: event handlers and javascript: disappear', Talea\Builder\Build::code('<a href="javascript:alert(1)" onclick="x()">A</a><iframe srcdoc="data:text/html,x"></iframe>'), '<a>A</a><iframe></iframe>');
// 2.5.1: a DOM filter instead of regular expressions – the bypasses of the old filter stay closed
$codeBypasses = ['<img/onerror=alert(1) src=x>', '<svg/onload=alert(1)>', '<a href=javascript:alert(1)>x</a>', '<a href="&#106;avascript:alert(1)">x</a>',
    '<a href="java&#x09;script:alert(1)">x</a>', '<a href="javascript:alert(\'1\')">x</a>', '<iframe srcdoc="&lt;svg/onload=alert(1)&gt;"></iframe>',
    '<object data="javascript:alert(1)"></object>', '<form action=javascript:alert(1)><button>x</button></form>', '<svg><animate attributeName=href values=javascript:alert(1) /></svg>',
    '<base href="https://evil.example/">', '<meta http-equiv=refresh content="0;url=https://evil.example">', '<div style="background:url(javascript:alert(1))">x</div>',
    '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>', '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'];
check('Build::code: bypassing the old filter fails', array_filter($codeBypasses, fn (string $h): bool => (bool) preg_match('/\son[a-z]+=|javascript:|srcdoc|<base|<meta|<object|<animate|evil\.example/i', Talea\Builder\Build::code($h))), []);
check('Build::code: map, service form and placeholder values stay', Talea\Builder\Build::code('<iframe src="https://www.google.com/maps/embed?pb=1" width="600" loading="lazy" allowfullscreen></iframe><form action="https://example.com/subscribe" method="post"><input type="email" name="EMAIL"></form><a href="{{odkaz}}">{{name}}</a>'),
    '<iframe src="https://www.google.com/maps/embed?pb=1" width="600" loading="lazy" allowfullscreen=""></iframe><form action="https://example.com/subscribe" method="post"><input type="email" name="EMAIL"></form><a href="{{odkaz}}">{{name}}</a>');
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
check('Qr: Reed–Solomon correction codes (known vector)', Talea\Core\Qr::correctionCodes([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10), [196, 35, 39, 119, 235, 215, 231, 226, 93, 23]);
$qrRows = fn (array $m): array => array_map(fn (array $r): string => implode('', array_map(fn (bool $b): string => $b ? '#' : '.', $r)), $m);
$qr = $qrRows(Talea\Core\Qr::matrix('Talea', 2));
// format bits read from the matrix (column 8 and row 8 at the top left corner) = the standard's table for level M, mask 2: 101111001111100
$qrFormat = '';
foreach ([[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]] as [$y, $x]) {
    $qrFormat = ($qr[$y][$x] === '#' ? '1' : '0') . $qrFormat;
}
check('Qr: format bits M/mask 2 per the standard\'s table', $qrFormat, '101111001111100');
check('Qr: version 1 = 21 × 21 with the finder pattern', [count($qr), $qr[0], $qr[6]], [21, '#######..#.#..#######', '#######.#.#.#.#######']);
// matrices verified by an independent reader (Chrome BarcodeDetector) – guards that the encoder does not break
$qrHash = fn (string $text): string => sha1(implode("\n", array_map(fn (array $r): string => implode('', array_map('intval', $r)), Talea\Core\Qr::matrix($text))));
check('Qr: otpauth address (version 6) matches the verified matrix', $qrHash('otpauth://totp/Acme%3Aadmin?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Acme&digits=6&period=30'), '21b4e92a0dd7dfcfe3d26161ea6baf9459312669');
check('Qr: version 12 (version bits, more blocks) matches the verified matrix', $qrHash(str_repeat('Talea QR 0123456789 ', 12)), 'f56c7dba89a16b5ef644e40e3b771ae4a0818ffd');
$qrSvg = Talea\Core\Qr::svg('otpauth://totp/x?secret=AB', 'QR <kód>'); // check-english: allow
check('Qr: SVG with a caption, without a script', str_contains($qrSvg, 'role="img" aria-label="QR &lt;kód&gt;"') && !str_contains($qrSvg, '<script'), true); // check-english: allow

/* ---------- image sizes: tall screenshots are measured by width, srcset carries the real widths ---------- */
check('Images::ratio: a landscape and a portrait photo by the longer side', [Talea\Core\Images::ratio(4000, 3000, 2000), Talea\Core\Images::ratio(3000, 4000, 2000)], [0.5, 0.5]);
check('Images::ratio: a full-page screenshot by width, height at most three times', [Talea\Core\Images::ratio(1440, 5000, 2000), round(Talea\Core\Images::ratio(1440, 5000, 1200), 3), Talea\Core\Images::ratio(1000, 9000, 2000)], [1.0, 0.72, 6000 / 9000]);
$imageFolder = dirname(__DIR__) . '/media/' . date('Y/m');
@mkdir($imageFolder, 0775, true);
$imageTmp = tempnam(sys_get_temp_dir(), 'obr');
$imagePng = imagecreatetruecolor(1440, 5000);
imagepng($imagePng, $imageTmp);
$imageSaved = Talea\Core\Images::saveFile($imageTmp, 'celostrankovy-snimek.png');
$imageSrcset = Talea\Core\Images::srcset($imageSaved['image_path'], '');
check('Images: a tall image keeps its width, srcset has the real variant widths', [$imageSaved['image_width'], $imageSaved['image_height'], (bool) preg_match('/-nahled\.png 553w, .*-1200\.png 1037w, .*\.png 1440w$/', $imageSrcset)], [1440, 5000, true]);
Talea\Core\Images::delete($imageSaved['image_path'], $imageSaved['thumb_path']);
@unlink($imageTmp);

check('Style::fromCss: border colour (also for the hover state)', Talea\Builder\Style::fromCss('border-color', '#F6F4EE'), ['border_color' => '#F6F4EE']);
check('Styl::zCss: zkratka background jen s barvou', Talea\Builder\Style::fromCss('background', '#EFECE5'), Talea\Builder\Style::fromCss('background-color', '#EFECE5'));
check('Style::fromCss: a background with an image stays out', Talea\Builder\Style::fromCss('background', 'url(a.png) no-repeat'), null);
$buildCheck = Talea\Builder\Check::builds(['v' => 1, 'children' => [['id' => 's', 'type' => 'section', 'children' => [
    ['id' => 'a', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Služby']], // check-english: allow
    ['id' => 'b', 'type' => 'heading', 'tag' => 'h4', 'content' => ['text' => 'Detail']],
    ['id' => 'c', 'type' => 'button', 'content' => ['text' => 'Poptat', 'link' => '#']],
    ['id' => 'd', 'type' => 'image', 'content' => ['src' => 'media/a.jpg', 'alt' => '']],
    ['id' => 'e', 'type' => 'image', 'content' => ['src' => '{{photo}}', 'alt' => '']],
    ['id' => 'f', 'type' => 'heading', 'tag' => 'p', 'content' => ['text' => '01']],
]]]], true);
check('Build check: button without a link, image without alt, missing h1 and a skipped level', array_column($buildCheck, 'id'), ['c', 'd', 'a', 'b']);
check('Build check: site parts do not check the heading outline', Talea\Builder\Check::builds(['v' => 1, 'children' => [['id' => 'a', 'type' => 'heading', 'tag' => 'h3', 'content' => ['text' => 'Kontakt']]]], false), []); // check-english: allow
check('Enquiry: campaign from the utm_* address of the page with the form', Talea\Front\Forms::campaign('https://example.com/akce?utm_source=google&utm_medium=cpc&utm_campaign=jaro&gclid=x&utm_term[]=a', 'https://example.com'), 'utm_source=google&utm_medium=cpc&utm_campaign=jaro');
check('Enquiry: campaign only from the own site', Talea\Front\Forms::campaign('https://jiny.cz/?utm_source=x', 'https://example.com'), '');
check('Enquiry: campaign for a human', Talea\Front\Forms::campaignText('utm_source=google&utm_medium=cpc&utm_campaign=jaro'), 'google / cpc / jaro');
// Parity guard: every admin action is a read, has an MCP tool, or is admin only on purpose (with the reason). A new action
// that is none of these fails the test – decide whether Claude can do it too (MCP is the main way to work with a site).
$readOnly = 'read';
$builderParity = [
    'build_ai_section' => 'admin: AI helper of the builder – Claude writes the content itself', 'build_ai_text' => 'admin: AI helper of the builder – Claude writes the content itself',
    'build_class' => 'save_classes', 'build_delete_section' => 'delete_section', 'build_discard' => 'discard_draft', 'build_publish' => 'publish_build',
    'build_restore' => 'restore_build_version', 'build_save' => 'save_build', 'build_save_section' => 'save_section', 'build_section' => 'insert_section',
    'build_share' => 'preview_link', 'build_versions' => $readOnly, 'builder' => $readOnly, 'build_comment_resolve' => 'resolve_draft_comment',
    'build_package' => $readOnly, 'build_paste' => 'admin: paste from the system clipboard of another Talea site – Claude inserts elements with save_build or edit_build and brings classes with save_classes',
];
$settingsParity = ['list' => $readOnly, 'save' => 'update_settings', 'download_backup' => $readOnly, 'backup' => 'admin: backups', 'restore_backup' => 'admin: backups',
    'delete_backup' => 'admin: backups', 'media_backup' => 'admin: backups', 'delete_log' => 'admin: error log', 'check' => 'admin: updates', 'update' => 'admin: updates',
    'test_mail' => 'admin: mail server settings', 'test_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
    'retry_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)', 'new_webhook_secret' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
    'hours_add' => 'save_hours_exception', 'hours_delete' => 'delete_hours_exception', 'hours_sign' => $readOnly,
    'hours_apply' => 'save_hours_exception', 'hours_discard' => 'delete_hours_exception', // a proposal from a drafts-only connection (3.2)
    'fleet_pair' => 'admin: which console a site reports to is a security decision (2.9)', 'fleet_send' => 'admin: the site reports every hour on its own',
    'fleet_updates' => 'admin: who decides about updates is a security decision (2.9)', 'fleet_unpair' => 'admin: which console a site reports to is a security decision (2.9)',
    'fleet_kit' => 'admin: receiving the console\'s design kit is an opt-in of the site (2.16); site_info reports the version that arrived',
    'report_preview' => $readOnly, 'report_send' => 'admin: the monthly report goes to e-mail addresses that stay out of MCP (2.9) – Claude reads the same numbers with get_stats and get_health',
    'cookie_scan' => 'admin: the cookie scan runs daily on its own (2.14); Claude reads the table in processing_record', 'processing_record' => 'processing_record',
    'accessibility_statement' => 'admin: the draft page is the administrator’s step (2.14); Claude reads the text with accessibility_statement'];
$parity = [
    'pages' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'export' => $readOnly, 'save' => 'update_page', 'save_text' => 'update_page',
        'delete' => 'trash_page', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
        'duplicate' => 'admin: a copy of a page – Claude creates the page and saves the build', 'import' => 'admin: upload of a page export file',
        'build_text' => 'admin: back from the builder to a text page', 'restore_version' => 'admin: text revisions of a text page',
        'translations' => 'translation_status', 'bulk' => 'update_page'],
    'news' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'compare' => $readOnly, 'versions' => $readOnly, 'search_json' => $readOnly,
        'save' => 'update_news', 'save_text' => 'update_news', 'delete' => 'trash_news', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
        'draft' => 'admin: autosave of the editor', 'duplicate' => 'admin: a copy of a news item – Claude creates a new one', 'links' => 'admin: link check runs on its own',
        'assistant' => 'admin: AI helper – Claude writes the text itself', 'translate' => 'admin: AI translation – Claude translates and uses create_news', 'bulk' => 'update_news',
        'social_save' => 'update_social_draft', 'social_posted' => 'admin: the person who posted it marks it', 'social_suggest' => 'admin: AI helper – Claude polishes the drafts itself with update_social_draft'],
    'collections' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'preset' => 'create_collection', 'edit' => $readOnly, 'items' => $readOnly, 'item' => $readOnly,
        'save' => 'update_collection', 'delete' => 'delete_collection', 'save_item' => 'save_collection_item', 'delete_item' => 'delete_collection_item',
        'restore_item' => 'restore_from_trash', 'delete_item_permanently' => 'admin: the trash empties itself after 30 days', 'duplicate_item' => 'admin: a copy of an item – Claude saves a new one',
        'restore_item_version' => 'restore_item_version', 'signature' => 'get_email_signature', 'notice_log' => 'list_notice_log', 'bulk_items' => 'save_collection_item'],
    // 2.15: requests to Claude – staff write them in the admin (not over MCP: a request is what a person asks Claude), Claude reads and answers them
    'requests' => ['list' => 'list_requests', 'new' => $readOnly, 'save' => 'admin: a request is written by a person for Claude – Claude reads it with list_requests', 'detail' => 'list_requests',
        'reply' => 'admin: the requester answers Claude in the request; Claude answers with update_request', 'status' => 'update_request'],
    'enquiries' => ['list' => $readOnly, 'detail' => $readOnly, 'csv' => $readOnly, 'attachment' => $readOnly, 'note' => 'update_enquiry', 'status' => 'update_enquiry', 'triage' => 'update_enquiry', 'testimonial' => 'request_testimonial', 'personal' => 'erase_personal_data',
        'bulk' => 'update_enquiry', 'delete' => 'delete_enquiry', 'settings' => 'admin: how long enquiries are kept', 'anonymise' => 'admin: blanking a person from an enquiry is the owner’s decision about personal data (2.14)'],
    // 3.0: online booking – bookings are personal data (the Bookings section), the set-up is the administrator's
    'bookings' => ['list' => $readOnly, 'detail' => $readOnly, 'new' => $readOnly, 'status' => 'cancel_booking', 'confirm' => 'confirm_booking', 'decline' => 'decline_booking', 'propose' => 'propose_booking_times', 'anonymise' => 'admin: blanking a person from a booking is the owner’s decision about personal data',
        'create' => 'admin: a booking taken by phone is entered by the person who took the call – customers’ personal data never come from Claude', 'services' => $readOnly, 'service_save' => 'save_booking_service',
        'service_delete' => 'admin: removing a service is the administrator’s decision; save_booking_service switches it off', 'staff' => $readOnly, 'staff_edit' => $readOnly, 'staff_save' => 'save_booking_staff',
        'staff_delete' => 'admin: removing a person is the administrator’s decision; save_booking_staff switches them off', 'off_save' => 'save_booking_staff', 'off_delete' => 'save_booking_staff', 'settings' => 'update_settings'],
    'subscribers' => ['list' => $readOnly, 'csv' => $readOnly, 'delete' => 'admin: subscribers’ addresses stay out of MCP', 'sync' => 'admin: mailing service keys', 'retry' => 'admin: mailing service keys'],
    'newsletters' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'preview' => $readOnly, 'save' => 'draft_newsletter', 'test' => 'send_test_newsletter',
        'send' => 'send_newsletter', 'unschedule' => 'send_newsletter', 'delete' => 'delete_newsletter'],
    'categories' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'update_category', 'delete' => 'delete_category'],
    'tags' => ['list' => $readOnly, 'save' => 'admin: tags are set with news items (update_news)', 'delete' => 'admin: tags are set with news items (update_news)'],
    'media' => ['list' => $readOnly, 'listing' => $readOnly, 'upload' => 'upload_file', 'save' => 'update_media', 'save_caption' => 'update_media', 'bulk' => 'delete_media',
        'replace' => 'admin: a new file behind the same address', 'folder' => 'admin: media folders', 'folder_delete' => 'admin: media folders',
        'cleanup' => 'list_media_without_alt', 'save_alts' => 'update_media', 'shrink' => 'admin: re-encoding a stored image in place (2.14) – Claude uploads a smaller file instead'],
    'appearance' => ['list' => $readOnly, 'preview' => $readOnly, 'tokens' => $readOnly, 'save' => 'update_design_system', 'tokens_import' => 'admin: upload of a design tokens file',
        'looks' => 'list_looks', 'apply_look' => 'apply_look', 'publish_look' => 'publish_look', 'discard_look' => 'discard_look', 'restore_look' => 'restore_look_version', 'preview_site' => 'preview_link'],
    'parts' => $builderParity + ['list' => $readOnly, 'variant' => $readOnly, 'save_variant' => 'save_part_variant', 'template' => 'save_part_variant',
        'templates' => $readOnly, 'apply_template' => 'apply_part_template'],
    'menu' => ['list' => $readOnly, 'save' => 'save_menu', 'automatic' => 'save_menu'],
    'components' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'save_component', 'delete' => 'delete_component', 'from_element' => 'save_component'],
    'popups' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'create' => 'save_popup', 'save' => 'save_popup', 'toggle' => 'save_popup',
        'delete' => 'delete_popup', 'reset' => 'admin: resetting the counters'],
    'users' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions', 'password_link' => 'admin: accounts and permissions',
        'reactivate' => 'admin: accounts and permissions', 'revoke_connection' => 'admin: accounts and permissions'],
    'roles' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions'],
    'stats' => ['list' => 'get_stats'], 'changelog' => ['list' => 'list_changes', 'sessions' => 'list_agent_sessions', 'undo' => 'undo_agent_session'], 'audit' => ['list' => 'site_audit'],
    'addons' => ['list' => $readOnly, 'toggle' => 'admin: running code from another developer is the administrator’s decision (3.0)', 'uninstall' => 'admin: removing an add-on and its data is the administrator’s decision (API 2)', 'page' => 'admin: pages add-ons add to the administration'],
    'redirects' => ['list' => $readOnly, 'save' => 'save_redirect', 'delete' => 'save_redirect', 'clear' => 'admin: clearing the list of 404 addresses', 'ignore' => 'ignore_not_found', 'ignore_all' => 'ignore_not_found',
        'settings' => 'update_settings'],
    'transfer' => ['list' => $readOnly, 'preview' => $readOnly, 'download' => $readOnly, 'export' => $readOnly, 'upload' => 'admin: WordPress import', 'select' => 'admin: WordPress import',
        'run' => 'admin: WordPress import', 'progress' => 'admin: WordPress import', 'images' => 'admin: WordPress import', 'delete_file' => 'admin: WordPress import', 'delete_export' => 'admin: site export',
        'talea' => 'admin: moving a whole site into a new installation', 'talea_select' => 'admin: moving a whole site into a new installation',
        'talea_run' => 'admin: moving a whole site into a new installation', 'talea_delete' => 'admin: moving a whole site into a new installation',
        'web_start' => 'import_website', 'web_progress' => 'import_website', 'web_run' => 'import_website', 'web_delete' => 'admin: removing the record of an import',
        'report_start' => 'migration_report', 'report' => 'migration_report', 'report_delete' => 'admin: removing a saved report',
        'source_upload' => 'admin: structured import (3.0) – the export file comes through the admin, not over MCP', 'source_select' => 'admin: structured import (3.0)',
        'source_preview' => $readOnly, 'source_run' => 'admin: structured import (3.0)', 'source_progress' => 'admin: structured import (3.0)',
        'source_images' => 'admin: structured import (3.0)', 'source_delete' => 'admin: structured import (3.0)',
        'source_fetch' => 'admin: structured import (3.0) – the API token is typed in the admin, kept in the session only, never over MCP'],
    'settings' => $settingsParity, 'extensions' => $settingsParity, 'business' => $settingsParity, 'status' => $settingsParity, 'claude_settings' => $settingsParity,
    'facts' => ['list' => 'list_facts', 'edit' => $readOnly, 'save' => 'save_fact', 'delete' => 'delete_fact', 'claims' => 'find_claims'],
    'notebook' => ['list' => 'read_notebook', 'edit' => $readOnly, 'save' => 'write_notebook', 'pin' => 'write_notebook', 'delete' => 'delete_notebook_entry'],
    // 2.17: scheduled runs – what a routine in Claude does on the site and when is the administrator's decision; Claude only gets the due runs and reports them
    'schedules' => ['list' => $readOnly, 'edit' => $readOnly, 'history' => $readOnly, 'save' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17) – Claude gets the due runs with get_due_agent_runs and reports them with report_agent_run',
        'delete' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17)', 'toggle' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17)'],
    'connectors' => ['list' => 'list_connectors', 'save' => 'admin: credentials of outside services never go through Claude', 'connect' => 'admin: an OAuth sign-in needs the administrator in the browser',
        'callback' => 'admin: an OAuth sign-in needs the administrator in the browser', 'disconnect' => 'admin: credentials of outside services never go through Claude',
        'properties' => 'admin: picking the Search Console property belongs to the connection, next to its credentials', 'property' => 'admin: picking the Search Console property belongs to the connection, next to its credentials',
        'gbp_locations' => 'admin: which Business Profile location the site syncs is the administrator’s choice (2.13)', 'gbp_sync' => 'admin: the daily job does it by itself; the button is for the administrator checking the connection',
        'sheet' => 'admin: the sheet of enquiries is created with the administrator\'s Google sign-in (2.13); Claude sees the status in list_connectors'],
    'blueprints' => ['list' => 'get_blueprint', 'apply' => 'apply_blueprint', 'remove' => 'remove_blueprint', 'answers' => 'save_fact', 'export' => 'export_blueprint'],
    'fleet' => ['list' => 'list_sites', 'detail' => 'get_site', 'pairing_key' => 'admin: pairing a site is a security decision (2.9)', 'ring' => 'admin: the update ring decides when sites install versions',
        'allow' => 'admin: allowing a version on the sites', 'check' => 'admin: the console checks the sites every 5 minutes on its own', 'remove' => 'admin: removing a site from the console',
        'kit' => $readOnly, 'kit_publish' => 'admin: publishing a design kit to a whole fleet is a person\'s decision (2.16); list_sites shows the versions'],
];
$missingParity = [];
foreach (Talea\Admin\Kernel::MODULES as $moduleClass) {
    foreach ((new ReflectionClass($moduleClass))->getMethods() as $method) {
        if (preg_match('/^action[A-Z]/', $method->name)) {
            $action = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method->name, 6)));
            $covered = $parity[$moduleClass::IDENT][$action] ?? null;
            if ($covered === null || ($covered !== $readOnly && !str_starts_with($covered, 'admin: ') && !in_array($covered, array_keys(Talea\Mcp\Catalog::TOOLS), true))) {
                $missingParity[] = $moduleClass::IDENT . '.' . $action . ($covered !== null ? ' → unknown tool ' . $covered : '');
            }
        }
    }
}
check('Parity: every admin action is a read, an MCP tool or admin only on purpose', $missingParity, []);
// every library section is a valid build
$libraryProblems = [];
foreach (Talea\Builder\Library::listAll() as $librarySection) {
    $element = Talea\Builder\Library::section($librarySection['key'])['element'];
    [, $sectionErrors] = Talea\Builder\Build::sanitize(['v' => 1, 'children' => [$element]]);
    if ($sectionErrors !== []) {
        $libraryProblems[] = $librarySection['key'];
    }
}
check('Builder model: every library section is a valid build', $libraryProblems, []);
// Ready-made templates of site parts: each builds without errors, wrappers keep exactly one page content element, headers carry the logo
$templateProblems = [];
foreach (Talea\Builder\PartTemplates::LIST as $partType => $partTemplates) {
    foreach (array_keys($partTemplates) as $templateKey) {
        $templateBuild = Talea\Builder\PartTemplates::build($partType, $templateKey, 'en', ['newsletter_signup']);
        [, $templateErrors] = Talea\Builder\Build::sanitize($templateBuild);
        $json = (string) json_encode($templateBuild);
        $contentCount = preg_match_all('/"type":"page_content"/', $json);
        if ($templateErrors !== [] || (in_array($partType, ['news_item', 'list', 'not_found'], true) ? $contentCount !== 1 : $contentCount !== 0)
            || ($partType === 'header' && !str_contains($json, '"type":"logo"'))) {
            $templateProblems[] = $partType . ':' . $templateKey;
        }
    }
}
check('Part templates: valid builds, one page content in wrappers, a logo in headers', $templateProblems, []);
check('Part templates: the newsletter sign-up only with the Newsletter extension', [str_contains((string) json_encode(Talea\Builder\PartTemplates::build('footer', 'columns', 'en', [])), '"type":"newsletter_signup"'),
    array_column(Talea\Builder\PartTemplates::forType('list', []), 'key')], [false, ['plain']]);
// 2.1: every MCP tool once in Mcp\Catalog – its definition, its parameter types and its method agree with it
$catalogTools = array_keys(Talea\Mcp\Catalog::TOOLS);
$definedTools = array_column(Talea\Mcp\Tools::definitions(), 'name');
$toolMethods = array_values(array_filter(array_map(fn (ReflectionMethod $m): string => $m->name, (new ReflectionClass(Talea\Mcp\Tools::class))->getMethods()), fn (string $m): bool => preg_match('/^tool[A-Z]/', $m) === 1));
check('2.1: MCP catalog, definitions, parameter types and methods agree', [
    array_values(array_diff($catalogTools, $definedTools)), array_values(array_diff($definedTools, $catalogTools)),
    array_values(array_diff(array_map([Talea\Mcp\Catalog::class, 'method'], $catalogTools), $toolMethods)), array_values(array_diff($toolMethods, array_map([Talea\Mcp\Catalog::class, 'method'], $catalogTools))),
    count(Talea\Mcp\Tools::definitions()), Talea\Mcp\Tools::annotations('trash_page'), Talea\Mcp\Tools::isWriteTool('site_audit'), Talea\Mcp\Catalog::extension('list_news'),
], [[], [], [], [], count($catalogTools), ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false], false, 'news']);
// 2.2: what a connection may do – full everything, drafts only reads and drafts, read only reads; never an unknown level
check('2.2: connection access', [Talea\Mcp\Catalog::allows('full', 'publish_look'), Talea\Mcp\Catalog::allows('drafts', 'save_build'), Talea\Mcp\Catalog::allows('drafts', 'create_page'),
    Talea\Mcp\Catalog::allows('drafts', 'publish_build'), Talea\Mcp\Catalog::allows('drafts', 'update_settings'), Talea\Mcp\Catalog::allows('drafts', 'trash_page'),
    Talea\Mcp\Catalog::allows('read', 'get_build'), Talea\Mcp\Catalog::allows('read', 'save_build'), Talea\Mcp\Catalog::allows('read', 'update_page'), Talea\Mcp\Catalog::allows('whatever', 'save_build'),
    Talea\Front\OAuth::access('drafts'), Talea\Front\OAuth::access('admin'), Talea\Mcp\Tools::annotations('save_build')],
    [true, true, true, false, false, false, true, false, false, false, 'drafts', 'read', ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false]]);
check('2.2: every drafts-only tool is a write that does not remove anything', array_values(array_filter(array_keys(Talea\Mcp\Catalog::TOOLS),
    fn (string $t): bool => Talea\Mcp\Catalog::access($t) === 'draft' && (Talea\Mcp\Tools::annotations($t)['readOnlyHint'] || Talea\Mcp\Tools::annotations($t)['destructiveHint']))), []);
check('2.2: MCP protocol version negotiated', [Talea\Mcp\Server::protocol('2025-03-26'), Talea\Mcp\Server::protocol('2099-01-01'), Talea\Mcp\Server::protocol(null)],
    ['2025-03-26', '2025-06-18', '2025-06-18']);
$promptText = Talea\Mcp\Prompts::get('build_page', ['topic' => 'kitchens', 'audience' => 'families'])['messages'][0]['content']['text'];
$promptError = '';
try {
    Talea\Mcp\Prompts::get('translate_page', ['page_id' => '3']);
} catch (InvalidArgumentException $e) {
    $promptError = $e->getMessage();
}
check('2.2: MCP prompts and resources', [str_starts_with($promptText, 'Build a new page about kitchens for families.'), str_contains(Talea\Mcp\Prompts::get('build_page', ['topic' => 'x'])['messages'][0]['content']['text'], 'about x. First'),
    $promptError, array_column(Talea\Mcp\Prompts::listAll(), 'name'), array_column(Talea\Mcp\Prompts::resources(), 'uri')],
    [true, true, 'The prompt translate_page needs the argument language.', ['build_page', 'audit_and_fix', 'translate_page', 'write_news', 'migrate_site', 'weekly_review', 'work_requests', 'scheduled_run', 'review_pending', 'draft_blueprint'], ['talea://instructions', 'talea://overview']]);
// 2.8: when a job is due, and when an update counts as broken (only with a clear sign – never just because the site cannot reach itself)
check('2.8: Scheduler::isDue', [Talea\Core\Scheduler::isDue(null, 300, 1000), Talea\Core\Scheduler::isDue(900, 0, 1000), Talea\Core\Scheduler::isDue(800, 300, 1000),
    Talea\Core\Scheduler::isDue(700, 300, 1000), Talea\Core\Scheduler::isDue(1000 - 86400 + 60, 86400, 1000)], [true, true, false, true, false]);
// 2.9: the monthly report – the previous month across the year boundary, the agency or the site in the header, no address from the
// data gets through, the month name in the site's language
use Talea\Core\MonthlyReport;
check('2.9: MonthlyReport::previousMonth – January goes to December of the previous year', [MonthlyReport::previousMonth(new DateTimeImmutable('2027-01-15 10:00:00'))->format('Y-m-d H:i'),
    MonthlyReport::previousMonth(new DateTimeImmutable('2026-03-31 23:59:59'))->format('Y-m-d')], ['2026-12-01 00:00', '2026-02-01']);
$reportSettings = static function (array $values): Talea\Core\Settings {
    $s = (new ReflectionClass(Talea\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Talea\Core\Settings::class, 'values'))->setValue($s, $values + ['site_name' => 'Testovací firma', 'site_language' => 'en']); // check-english: allow

    return $s;
};
$reportData = ['month' => '2026-09',
    'stats' => ['visits' => 120, 'views' => 300, 'previous_visits' => 100, 'previous_views' => 0, 'pages' => [['path' => '/sluzby', 'n' => 80]], 'sources' => [['site' => 'google.com', 'n' => 40]], 'campaigns' => []],
    'enquiries' => ['total' => 3, 'forms' => [['form' => 'Kontakt jan.novak@visitor.example', 'n' => 3]], 'pages' => [['path' => '/kontakt', 'n' => 3]], 'unanswered' => 2], 'signups' => 1, // check-english: allow
    'updates' => [['type' => 'update.applied', 'date' => '2026-09-10 10:00:00', 'message' => 'Version 2.8.0 was installed (from 2.7.0).']],
    'backups' => ['created' => 20, 'failed' => 0, 'last' => '2026-09-30 03:00:00'], 'changes' => ['people' => 12, 'claude' => 7],
    'problems' => [['group' => 'Operation', 'name' => 'Cron', 'state' => 'warning', 'info' => 'not set up – ask admin@visitor.example']], 'decisions' => ['enquiries' => 2, 'errors' => 0]];
$withAgency = MonthlyReport::render($reportData, $reportSettings(['agency_name' => 'Studio Talea', 'agency_email' => 'help@studio.example', 'agency_logo' => 'media/logo.svg']), 'https://example.com/');
$withoutAgency = MonthlyReport::render($reportData, $reportSettings([]), 'https://example.com');
check('2.9: the report carries the agency when it is set, otherwise the site', [str_contains($withAgency['html'], 'Studio Talea'), str_contains($withAgency['html'], 'https://example.com/media/logo.svg'), str_contains($withAgency['text'], 'help@studio.example'),
    str_contains($withoutAgency['html'], 'Studio Talea'), str_contains($withoutAgency['html'], 'Testovací firma'), str_contains($withoutAgency['html'], '120 (+20 %)')], [true, true, true, false, true, true]); // check-english: allow
check('2.9: no e-mail address from the data gets into the report', [str_contains($withAgency['html'] . $withAgency['text'] . $withAgency['subject'], 'visitor.example'), str_contains($withoutAgency['html'], '/sluzby'), str_contains($withoutAgency['text'], 'Kontakt ')], [false, true, true]); // check-english: allow
check('2.9: the subject names the month in the site language', [$withoutAgency['subject'], MonthlyReport::render($reportData, $reportSettings(['site_language' => 'cs']), 'https://example.com')['subject'],
    MonthlyReport::render(['month' => '2027-01'], $reportSettings(['site_language' => 'de']), 'https://example.com')['subject']],
    ['Website report – September 2026 – Testovací firma', 'Zpráva o webu – září 2026 – Testovací firma', 'Website-Bericht – Januar 2027 – Testovací firma']); // check-english: allow
$ok = [200, 'TALEA-PROBE 9.9.9'];
check('2.8: Updater::probeVerdict', [
    Talea\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Talea\Core\Updater::probeVerdict(['probe' => [0, ''], 'home' => [0, ''], 'admin' => [0, '']], '9.9.9'),
    Talea\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null,
    Talea\Core\Updater::probeVerdict(['probe' => [200, 'TALEA-PROBE 2.7.0'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Talea\Core\Updater::probeVerdict(['probe' => [500, ''], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9') !== null,
    Talea\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [301, ''], 'admin' => [302, '']], '9.9.9'),
    Talea\Core\Updater::probeVerdict(['probe' => [403, 'Access denied.'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Talea\Core\Updater::probeVerdict(['probe' => [200, 'TALEA-PROBE 2.7.0'], 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null],
    [null, null, true, null, true, null, null, true]);
// the client address helper (issue #29, was Core\Firewall): networks, the address behind Cloudflare only from Cloudflare
use Talea\Core\Antispam;
check('Antispam::inList', [Antispam::inList('198.51.100.77', ['198.51.100.0/24']), Antispam::inList('198.51.101.1', ['198.51.100.0/24']), Antispam::inList('203.0.113.7', ['203.0.113.7']),
    Antispam::inList('10.1.2.3', ['10.0.0.0/9']), Antispam::inList('10.200.0.1', ['10.0.0.0/9']), Antispam::inList('2001:db8::1', ['2001:db8::/32']), Antispam::inList('2001:db9::1', ['2001:db8::/32']),
    Antispam::inList('not-an-ip', ['0.0.0.0/8']), Antispam::inList('198.51.100.7', ['2001:db8::/32'])], [true, false, true, true, false, true, false, false, false]);
check('Antispam::visitorIp – Cloudflare only from its addresses', [
    Antispam::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], 'cloudflare'),
    Antispam::visitorIp(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'], 'cloudflare'),
    Antispam::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], ''),
    Antispam::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'junk'], 'cloudflare')],
    ['203.0.113.9', '203.0.113.50', '172.70.1.2', '172.70.1.2']);
// 2.7: a reveal and a motion while scrolling run together; a hover effect gets its own rule, its motion only without reduced motion
$motion = Talea\Builder\Style::css('#a', ['base' => ['animation' => 'tl-from-left', 'scroll_motion' => 'tl-parallax', 'hover_effect' => 'lift']]);
check('2.7: scroll motion and hover effect in the CSS', [str_contains($motion, 'animation: tl-from-left linear both, tl-parallax linear both; animation-timeline: view(), view(); animation-range: entry 0% cover 28%, cover 0% cover 100%'),
    str_contains($motion, '@media (prefers-reduced-motion: no-preference) { #a { transition:'), str_contains($motion, '#a:is(:hover, :focus-visible) { translate: 0 -4px;'),
    Talea\Builder\Style::css('#b', ['base' => ['animation' => 'none', 'scroll_motion' => 'tl-nonsense']])],
    [true, true, true, '']);
// 2.7: the migration report reads what the old page had, counts forms and images of a build, and old form entries are checked
$oldPage = '<html><head><title>Services | Acme</title><meta name="description" content="What we do"></head><body><header><form role="search"><input type="search" name="s"></form></header>'
    . '<main><h1>Services</h1><p>' . str_repeat('We build kitchens and bathrooms. ', 5) . '</p><img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg"><form action="/contact"><input name="email"><textarea name="m"></textarea></form></main></body></html>';
$searchOnly = '<html><body><main><p>' . str_repeat('Text of the page. ', 8) . '</p></main><form class="search-form"><input name="s"></form></body></html>';
check('2.7: MigrationReport::analyse', [Talea\Core\MigrationReport::analyse($oldPage, 'https://old.example/services/'), Talea\Core\MigrationReport::analyse($searchOnly, 'https://old.example/')['form']],
    [['title' => 'Services | Acme', 'description' => 'What we do', 'form' => true, 'images' => 3], false]);
check('2.7: MigrationReport::countElements', Talea\Core\MigrationReport::countElements([
    ['type' => 'section', 'children' => [['type' => 'image'], ['type' => 'gallery', 'content' => ['photos' => [['src' => 'a'], ['src' => 'b']]]], ['type' => 'container', 'children' => [['type' => 'form']]]]],
    ['type' => 'text', 'content' => ['html' => '<p><img src="x"></p>']]]), [1, 4]);
check('2.7: import_enquiries checks each entry', [
    Talea\Mcp\Tools::enquiryEntry(['date' => '2025-03-14 09:30', 'form' => 'Contact', 'page' => '/contact', 'fields' => ['Name' => 'Jana', 'E-mail' => 'jana@example.cz', 'Message' => '<b>Hi</b>', 'Empty' => '']]),
    Talea\Mcp\Tools::enquiryEntry(['date' => 'yesterday-ish?', 'fields' => ['a' => 'b']]), Talea\Mcp\Tools::enquiryEntry(['date' => '2025-01-01', 'fields' => []]),
    Talea\Mcp\Tools::enquiryEntry(['date' => '2025-01-01 10:00', 'email' => 'not-an-email', 'fields' => [['label' => 'Phone', 'value' => '777 123 456']]])['email']],
    [['date' => '2025-03-14 09:30:00', 'form' => 'Contact', 'page' => '/contact', 'email' => 'jana@example.cz', 'data' => [['Name', 'Jana'], ['E-mail', 'jana@example.cz'], ['Message', 'Hi']]],
    'date must be a date and time, e.g. 2025-03-14 09:30', 'fields are empty', '']);
// 2.3: the Embed element takes only known services and builds their frame address; image/web.js allows the same
$embed = fn (string $u): ?string => Talea\Builder\Elements\Embed::resolve($u)[1] ?? null;
check('2.3: Embed – known services only', [$embed('https://calendly.com/acme/consultation'), $embed('https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?usp=sf_link'),
    $embed('https://tally.so/r/w7ZyYq'), $embed('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC?si=x'), $embed('https://soundcloud.com/artist/track-name'),
    $embed('https://evil.example/calendly.com/x'), $embed('javascript:alert(1)'), $embed('https://calendly.com/a/b"onload=x')],
    ['https://calendly.com/acme/consultation?embed_type=Inline&hide_gdpr_banner=1', 'https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?embedded=true',
    'https://tally.so/embed/w7ZyYq?alignLeft=1&transparentBackground=1', 'https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC',
    'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fartist%2Ftrack-name', null, null, null]);
preg_match('/if \(!(\/\^https:.*?\/)\.test\(address\)\)/', (string) file_get_contents(TALEA_ROOT . '/image/web.js'), $webJsAllow);
$jsPattern = '#' . str_replace('\\/', '/', substr($webJsAllow[1] ?? '//', 1, -1)) . '#';
check('2.3: every Embed frame address is allowed in image/web.js', array_keys(array_filter(Talea\Builder\Elements\Embed::SERVICES,
    fn (array $s): bool => preg_match($jsPattern, sprintf($s[3], 'x')) !== 1)), []);
// 2.3.1: in a language version, a path that already names its language keeps it (a menu link /cs/funkce, not /cs/cs/funkce)
$urlApp = new Talea\Core\App([]);
$urlApp->languagePrefix = 'cs';
check('2.3.1: App::url does not double the language prefix', [$urlApp->url('cs/funkce'), $urlApp->url('/cs/funkce'), $urlApp->url('funkce'), $urlApp->url('cs'), $urlApp->url('image/x.svg'), $urlApp->url('css-tricks')],
    array_map(fn (string $p): string => $urlApp->request->basePath() . $p, ['/cs/funkce', '/cs/funkce', '/cs/funkce', '/cs', '/image/x.svg', '/cs/css-tricks']));
// 2.3.1: every field a settings tab posts is read by the save – a setting, the "remove" box of a secret setting, or one of the
// few the save reads on purpose (a field under an old name was silently dropped: saving General removed the language versions)
$settingsFields = [];
foreach ((new ReflectionClass(Talea\Admin\Modules\Settings::class))->getReflectionConstant('FIELDS')->getValue() as $tabFields) {
    foreach ($tabFields as $key => $type) {
        $settingsFields[$key] = true;
        if (str_starts_with($type, 'secret')) {
            $settingsFields[$key . '_delete'] = true;
        }
    }
}
$unknownFields = [];
foreach (glob(TALEA_SYSTEM . '/views/admin/settings/*.php') as $view) {
    preg_match_all('/name="([a-z_]+)(?:\[\])?"/', (string) file_get_contents($view), $viewNames);
    foreach (array_unique($viewNames[1]) as $name) {
        if (!isset($settingsFields[$name]) && !in_array($name, ['extensions', 'ai_provider_previous', 'new_tasks_token', 'new_token', 'file', 'tab', 'id', 'ip', 'pairing_key', 'fleet_updates',
            'exception', 'exception_from', 'exception_to', 'exception_closed', 'exception_hours', 'exception_note', 'exception_notice', 'viewport', 'robots', // viewport, robots: <meta> of the door sign
            'fleet_kit', // 2.16: the console tab's own button (Settings::actionFleetKit)
            'version', // 3.3.2: the version on the update button – Settings::actionUpdate installs only that one
            'screen_collections', 'new_screen_token'], true)) { // 2.11 screen mode: the collections list is added by fields() from the site's collections, the button makes a new address
            $unknownFields[] = basename($view) . ': ' . $name;
        }
    }
}
check('2.3.1: settings forms post only fields the save reads', $unknownFields, []);
// 2.1: public contracts – MCP tools and parameters, design tokens and builder elements are never removed or changed
// outside the deprecation policy; an addition is recorded with php tools/contracts.php --update
require_once __DIR__ . '/contracts.php';
check('2.1: public contracts kept (tools/contracts)', talea_contract_diff(), ['broken' => [], 'added' => []]);
// MCP in English (since the hard fork the implementation is English: there is no translation layer): every tool of the public contract is
// implemented, and no tool name, parameter name or description carries a Czech word or a Czech diacritic
$mcpContract = json_decode((string) file_get_contents(__DIR__ . '/contracts/mcp-tools.json'), true);
$mcpDefined = array_column(Talea\Mcp\Tools::definitions(), null, 'name');
$mcpCzech = [];
foreach (Talea\Mcp\Tools::definitions() as $mcpTool) {
    $mcpText = $mcpTool['name'] . ' ' . $mcpTool['description'];
    foreach ((array) $mcpTool['inputSchema']['properties'] as $mcpParam => $mcpDefinition) {
        $mcpText .= ' ' . $mcpParam . ' ' . ($mcpDefinition['description'] ?? '');
    }
    if (preg_match('/[ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚ]/u', $mcpText) === 1 || preg_match('/\b(nazev|adresa|stranka|stranky|polozka|polozky|kolekce|poradi|publikovat|hledat|stitky|kategorie|datum|vydat|operace|sekce|uplne|prvky|zobrazit|smazat|nahradit|komentare|potvrdit|presmerovani|novinky|vzor|predvolba)\b/u', $mcpText) === 1) { // check-english: allow
        $mcpCzech[] = $mcpTool['name'];
    }
}
check('MCP: every tool of the contract is implemented under its English name; no Czech in the names, parameters and descriptions',
    [array_values(array_diff(array_keys($mcpContract), array_keys($mcpDefined))), array_values(array_diff(array_keys($mcpDefined), array_keys($mcpContract), array_keys(Talea\Mcp\Catalog::TOOLS))), $mcpCzech, class_exists('Talea\\Mcp\\Translator', false)], [[], [], [], false]);
$mcpCzechMessages = [];
foreach ([...glob(TALEA_ROOT . '/system/src/Mcp/*.php'), ...glob(TALEA_ROOT . '/system/src/Mcp/Handlers/*.php')] as $mcpFile) {
    if (preg_match("/Exception\\('[^']*[ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚ]/u", (string) file_get_contents($mcpFile)) === 1) { // check-english: allow
        $mcpCzechMessages[] = basename($mcpFile);
    }
}
check('MCP: the fixed messages of the tools are English (no Czech diacritics in the exceptions of Mcp\\)', $mcpCzechMessages, []);
$mcpList = [['name' => 'save_collection_item', 'inputSchema' => ['properties' => ['data' => ['type' => 'object'], 'name' => ['type' => 'string'], 'fields' => ['type' => 'array']]]]];
check('MCP: an object and an array sent as JSON text are unpacked by the schema, text stays text', Talea\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{"a":"b"}', 'name' => '{"x":1}', 'fields' => '[1,2]']),
    ['data' => ['a' => 'b'], 'name' => '{"x":1}', 'fields' => [1, 2]]);
check('MCP: invalid JSON or an array instead of an object is not unpacked', Talea\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{nic', 'fields' => '{"a":1}']), ['data' => '{nic', 'fields' => '{"a":1}']);
check('MCP: a boolean sent as text ("false" does not publish a hidden page)', Talea\Mcp\Server::extractJson([['name' => 'create_page', 'inputSchema' => ['properties' => ['visible' => ['type' => 'boolean'], 'title' => ['type' => 'string']]]]], 'create_page', ['visible' => 'false', 'title' => 'false']), ['visible' => false, 'title' => 'false']);
check('MCP: boolean "true" and "1" as text', array_values(array_map(fn (string $h): mixed => Talea\Mcp\Server::extractJson([['name' => 't', 'inputSchema' => ['properties' => ['v' => ['type' => 'boolean']]]]], 't', ['v' => $h])['v'], ['true', '1', '0', 'ano'])), [true, true, false, 'ano']);
check('MCP: JSON text for a parameter of type [array, null] is unpacked', Talea\Mcp\Server::extractJson([['name' => 'save_menu', 'inputSchema' => ['properties' => ['items' => ['type' => ['array', 'null']]]]]], 'save_menu', ['items' => '[{"type":"page"}]']), ['items' => [['type' => 'page']]]);
check('MCP: unknown parameters are listed', Talea\Mcp\Server::unknownParams($mcpList, 'save_collection_item', ['data' => '{}', 'classes' => [], 'name' => 'x']), ['classes']);
// popups: server rules (places, language, period) and English MCP parameters
$popupWhere = fn (array $x): array => $x + ['page_id' => null, 'collection' => null, 'news' => false, 'language' => 'cs', 'today' => '2026-09-25'];
$popupSelected = Talea\Builder\Popups::sanitizeRules(['where' => 'selected', 'pages' => ['4', 'x', 4], 'collections' => ['tym', 'Ne platna'], 'news' => 1, 'from' => '2026-02-30', 'campaign' => 'jaro<b>']);
check('Pop-up: sanitized rules', [$popupSelected['pages'], $popupSelected['collections'], $popupSelected['news'], $popupSelected['from'], $popupSelected['campaign'], $popupSelected['device']], [[4], ['tym'], true, '', 'jarob', 'all']);
check('Pop-up: selected places – page, collection, news, nowhere else', [
    Talea\Builder\Popups::matches($popupSelected, $popupWhere(['page_id' => 4])), Talea\Builder\Popups::matches($popupSelected, $popupWhere(['collection' => 'tym'])),
    Talea\Builder\Popups::matches($popupSelected, $popupWhere(['news' => true])), Talea\Builder\Popups::matches($popupSelected, $popupWhere(['page_id' => 5])),
], [true, true, true, false]);
$popupPeriod = Talea\Builder\Popups::sanitizeRules(['from' => '2026-10-01', 'to' => '2026-10-31', 'language' => 'en']);
check('Pop-up: period and language apply to the whole site too', [
    Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'en'])), Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'en', 'today' => '2026-10-15'])),
    Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'cs', 'today' => '2026-10-15'])), Talea\Builder\Popups::matches($popupPeriod, $popupWhere(['language' => 'en', 'today' => '2026-11-01'])),
], [false, true, false, false]);
$navCss = Talea\Builder\Elements\Navigation::baseCss();
check('Collection: a value in Custom HTML is escaped, formatted text is sanitized', [
    Talea\Builder\Collections::fill('<div title="{{name}}">{{name}}</div>', 'code', ['name' => ['<img src=x onerror=alert(1)>"', 'text']]),
    Talea\Builder\Collections::fill('<div>{{body}}</div>', 'code', ['body' => ['<p>Ahoj</p><img src=x onerror=alert(1)>', 'html']]),
], ['<div title="&lt;img src=x onerror=alert(1)&gt;&quot;">&lt;img src=x onerror=alert(1)&gt;&quot;</div>', '<div><p>Ahoj</p><img src="x"></div>']);
check('Navigation: the phone menu can scroll (a long menu with groups)', (bool) preg_match('/@media \\(max-width: 767px\\).*?\\.tl-nav-menu\\[popover\\] \\{[^}]*max-height:[^}]*overflow-y: auto/s', $navCss), true);
check('Pop-up: every library pattern builds', array_map(fn (string $k): bool => count(Talea\Builder\Popups::libraryBuild($k, 'en')['children']) === 1, array_keys(Talea\Builder\Popups::LIBRARY)), array_fill(0, count(Talea\Builder\Popups::LIBRARY), true));
use Talea\Core\Routes;
check('Routes: system addresses are the same in every language', [Routes::publicPath('news/category/akce', null), Routes::publicPath('news/tag/x', null), Routes::publicPath('search?q=a', null),
    Routes::publicPath('news/category/akce', null), Routes::publicPath('news-akce', null), Routes::publicPath('news', null)],
    ['news/category/akce', 'news/tag/x', 'search?q=a', 'news/category/akce', 'news-akce', 'news']);
check('Routes: request path to the internal and canonical form', [Routes::internalPath('/news/tag/x', null), Routes::internalPath('/news/x', null), Routes::internalPath('/news', null), Routes::internalPath('/about-us', null)],
    [['/news/tag/x', '/news/tag/x'], ['/news/x', '/news/x'], ['/news', '/news'], ['/about-us', '/about-us']]);
check('Routes: address shape by the url_slash setting', [Routes::slashRedirect('/o-nas', '/o-nas/?a=1', 'none'), Routes::slashRedirect('/o-nas', '/o-nas', 'none'), Routes::slashRedirect('/o-nas', '/o-nas?a=1', 'slash'), Routes::slashRedirect('/o-nas', '/en/o-nas/', 'slash'),
    Routes::slashRedirect('/o-nas', '/o-nas', 'html'), Routes::slashRedirect('/o-nas', '/o-nas/', 'html'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'html'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'none'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'slash'),
    Routes::slashRedirect('/', '/', 'slash'), Routes::slashRedirect('/rss.xml', '/rss.xml', 'slash'), Routes::slashRedirect('/api/x', '/api/x', 'slash'), Routes::slashRedirect('/mcp', '/mcp', 'slash'), Routes::slashRedirect('/form', '/form', 'slash')],
    ['/o-nas?a=1', null, '/o-nas/?a=1', null, '/o-nas.html', '/o-nas.html', null, '/o-nas', '/o-nas/', null, null, null, null, null]);
Routes::setNewsSlug('blog');
check('Routes: the custom news address applies in every language', [Routes::publicPath('news', null), Routes::publicPath('news/category/akce', null), Routes::publicPath('news/category/akce', null),
    Routes::publicPath('news/x.md', null), Routes::publicPath('search', null), Routes::publicPath('about-us', null)],
    ['blog', 'blog/category/akce', 'blog/category/akce', 'blog/x.md', 'search', 'about-us']);
check('Routes: the custom news address – request and redirect from the plain form', [Routes::internalPath('/blog/x', null), Routes::internalPath('/blog/category/x', null), Routes::internalPath('/news/x', null), Routes::internalPath('/news', null), Routes::internalPath('/blogger', null)],
    [['/news/x', '/blog/x'], ['/news/category/x', '/blog/category/x'], ['/news/x', '/blog/x'], ['/news', '/blog'], ['/blogger', '/blogger']]);
check('Routes: a custom news address collides with a page', [Routes::isNewsSlug('blog', null), Routes::isNewsSlug('o-nas', null), Routes::isNewsSlug('', null)], [true, false, false]);
check('Routes: the news address must not be a system path or have a bad shape', [Routes::systemSlugError('mcp'), Routes::systemSlugError('en'), Routes::systemSlugError('Blog'), Routes::systemSlugError('a/b'), Routes::systemSlugError('blog'), Routes::systemSlugError('news'), Routes::systemSlugError('')],
    ['This URL is used by the system, choose another one.', 'This URL is used by the system, choose another one.', 'This URL is used by the system, choose another one.', 'This URL is used by the system, choose another one.', null, null, null]);
Routes::setNewsSlug(null);
// dictionaries of the site languages: same keys as the Czech one (English source texts), the same %s and tags in the values
$sourceDictionary = require TALEA_ROOT . '/system/languages/cs.php';
$dataNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$brokenDictionaries = [];
foreach (glob(TALEA_ROOT . '/system/languages/[a-z][a-z].php') ?: [] as $file) {
    $code = basename($file, '.php');
    if ($code === 'en' || $code === 'cs') {
        continue;
    }
    $dictionary = require $file;
    if (!isset(Talea\Core\Language::AVAILABLE[$code])) {
        $brokenDictionaries[] = $code . ': language is not in Language::AVAILABLE';
    }
    foreach ($dictionary as $key => $translation) {
        if (!isset($sourceDictionary[$key]) && !in_array($key, $dataNames, true) && $key !== 'date_in_words' && $key !== 'datum_format') {
            $brokenDictionaries[] = $code . ': extra "' . $key . '"';
        } elseif (isset($sourceDictionary[$key]) && (preg_match_all('/%(?:\d+\$)?[sd]/', $key) !== preg_match_all('/%(?:\d+\$)?[sd]/', $translation) || substr_count($key, '<') !== substr_count($translation, '<'))) {
            $brokenDictionaries[] = $code . ': placeholders or tags in "' . $key . '"';
        }
    }
}
check('Site language dictionaries: keys from cs.php, same %s and HTML', $brokenDictionaries, []);

/* ---------- version comparison ---------- */
$r = Talea\Core\Diff::html('<p>Radnice schválila plán.</p><p>Druhý odstavec.</p>', '<p>Radnice včera schválila nový plán.</p><p>Druhý odstavec.</p><p>Třetí.</p>'); // check-english: allow
check('Diff: words in a changed paragraph', str_contains($r['html'], '<ins>včera </ins>') && str_contains($r['html'], '<ins>nový </ins>'), true); // check-english: allow
check('Diff: an unchanged paragraph has no marks', str_contains($r['html'], '<p>Druhý odstavec.</p>'), true); // check-english: allow
check('Diff: a new paragraph', str_contains($r['html'], '<p><ins>Třetí.</ins></p>'), true); // check-english: allow
check('Rozdil: HTML ve vstupu se escapuje', str_contains(Talea\Core\Diff::html('', '<p>a &lt;script&gt; b</p>')['html'], '<script>'), false);
check('Diff: identical texts', Talea\Core\Diff::html('<p>Stejné</p>', '<p>Stejné</p>')['added'], 0); // check-english: allow

/* ---------- FAQ ---------- */
check('Seo::faq: question and answer pairs', Seo::faq("Kdy to začne?\nV pondělí.\n\nKolik to stojí?\nNic."), [['Kdy to začne?', 'V pondělí.'], ['Kolik to stojí?', 'Nic.']]); // check-english: allow
check('Seo::faq: empty input', Seo::faq(null), []);

/* ---------- backups to S3: AWS Signature V4 signing (the value verified by an independent computation) ---------- */
check('404: robot probes are not logged, real addresses are', array_map(Talea\Core\NotFound::isBot(...), ['wp/v2/users', 'sellers.json', 'api/session/properties', '_next', 'api/news/x', 'about-us', 'en', 'cenik-2019']),
    [true, true, true, true, true, false, false, false]);
// 1.9: structured data of collection item pages – only mapped fields, an offer needs a price and a currency
$sdFields = [['key' => 'cena', 'label' => 'Cena', 'type' => 'text'], ['key' => 'kind', 'label' => 'Druh', 'type' => 'text'], ['key' => 'zacatek', 'label' => 'Začátek', 'type' => 'date']]; // check-english: allow
check('Collection structured data: an unknown field and type are dropped', [Talea\Builder\CollectionSchema::sanitize(['type' => 'Service', 'fields' => ['price' => 'cena', 'serviceType' => 'neni', 'hack' => 'kind'], 'currency' => 'eur'], $sdFields),
    Talea\Builder\CollectionSchema::sanitize(['type' => 'Recipe'], $sdFields)], [['type' => 'Service', 'fields' => ['price' => 'cena'], 'currency' => 'EUR'], null]);
$sdCollection = ['fields' => $sdFields, 'schema_org' => '{"type":"Service","fields":{"price":"cena","serviceType":"kind"},"currency":"EUR"}'];
check('Collection structured data: a service with an offer', Talea\Builder\CollectionSchema::forItem($sdCollection, ['name' => 'Revize', 'data' => ['cena' => '1 200,50', 'kind' => '<b>Elektro</b>']], 'https://x.test/sluzby/revize', 'Popis', '', 'https://x.test/#firma'), // check-english: allow
    ['@type' => 'Service', 'name' => 'Revize', 'url' => 'https://x.test/sluzby/revize', 'description' => 'Popis', 'serviceType' => 'Elektro', 'provider' => ['@id' => 'https://x.test/#firma'], // check-english: allow
        'offers' => ['@type' => 'Offer', 'price' => '1200.50', 'priceCurrency' => 'EUR', 'url' => 'https://x.test/sluzby/revize']]);
check('Collection structured data: an event without a start does not pass, without a type nothing', [Talea\Builder\CollectionSchema::forItem(['fields' => $sdFields, 'schema_org' => '{"type":"Event","fields":{"startDate":"zacatek"}}'], ['name' => 'A', 'data' => []], 'u', '', '', 'i'),
    Talea\Builder\CollectionSchema::forItem(['fields' => $sdFields], ['name' => 'A', 'data' => []], 'u', '', '', 'i')], [null, null]);
/* ---------- 2.10: e-mail signatures from people records ---------- */
$signatureClass = Talea\Builder\EmailSignature::class;
$peopleFields = [['key' => 'fotka', 'label' => 'Fotka', 'type' => 'image'], ['key' => 'role', 'label' => 'Role', 'type' => 'text'], ['key' => 'jazyky', 'label' => 'Jazyky', 'type' => 'text'],
    ['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text'], ['key' => 'e_mail', 'label' => 'E-mail', 'type' => 'text'], ['key' => 'nepritomnost', 'label' => 'Nepřítomnost', 'type' => 'text'], ['key' => 'o_mne', 'label' => 'O mně', 'type' => 'html']]; // check-english: allow
$peopleCollection = ['collection_id' => 5, 'name' => 'Tým', 'slug' => 'tym', 'detail' => 1, 'fields' => $peopleFields, 'schema_org' => '{"type":"Person","fields":{},"currency":""}']; // check-english: allow
check('2.10: EmailSignature::fields – the Czech preset by the keys and labels', $signatureClass::fields($peopleCollection), ['photo' => 'fotka', 'role' => 'role', 'phone' => 'telefon', 'email' => 'e_mail']);
$germanCollection = ['fields' => [['key' => 'portrait', 'label' => 'Porträt', 'type' => 'image'], ['key' => 'funktion', 'label' => 'Funktion', 'type' => 'text'], ['key' => 'handy', 'label' => 'Handy', 'type' => 'text'],
    ['key' => 'e_mail_adresse', 'label' => 'E-Mail-Adresse', 'type' => 'text'], ['key' => 'abwesend', 'label' => 'Abwesend', 'type' => 'text']]];
check('2.10: EmailSignature::fields – a user-made collection in another language, a label with diacritics', [$signatureClass::fields($germanCollection),
    $signatureClass::fields(['fields' => [['key' => 'field_1', 'label' => 'Telefonní číslo', 'type' => 'text'], ['key' => 'field_2', 'label' => 'Pozice ve firmě', 'type' => 'text'], ['key' => 'pole_3', 'label' => 'Mobil', 'type' => 'number']]])], // check-english: allow
    [['photo' => 'portrait', 'role' => 'funktion', 'phone' => 'handy', 'email' => 'e_mail_adresse'], ['photo' => null, 'role' => 'field_2', 'phone' => 'field_1', 'email' => null]]);
check('2.10: EmailSignature::fields – the schema.org Person mapping wins over the names', $signatureClass::fields(['fields' => [['key' => 'kontakt', 'label' => 'Kontakt', 'type' => 'text'], ['key' => 'cim_jsem', 'label' => 'Čím jsem', 'type' => 'text'], // check-english: allow
    ['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text'], ['key' => 'role', 'label' => 'Role v týmu', 'type' => 'text']], 'schema_org' => '{"type":"Person","fields":{"jobTitle":"cim_jsem","email":"kontakt"}}']), // check-english: allow
    ['photo' => null, 'role' => 'cim_jsem', 'phone' => 'telefon', 'email' => 'kontakt']); // check-english: allow
check('2.10: EmailSignature::isPeople – Person, a photo with a contact; references and a bare phone are not people', [$signatureClass::isPeople($peopleCollection), $signatureClass::isPeople($germanCollection),
    $signatureClass::isPeople(['fields' => [['key' => 'logo', 'label' => 'Logo', 'type' => 'image'], ['key' => 'citat', 'label' => 'Citát', 'type' => 'lines']]]), // check-english: allow
    $signatureClass::isPeople(['fields' => [['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text']]])], [true, true, false, false]);
$signatureSite = ['name' => 'Firma & spol.', 'url' => 'https://example.com/', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => 'Dlouhá 1, 110 00 Praha', 'color' => '#0f766e', // check-english: allow
    'text_font' => 'Georgia, serif', 'heading_font' => '"Helvetica Neue", Arial, sans-serif', 'logo' => 'https://example.com/media/logo.png'];
$signaturePerson = ['name' => 'Jana <Nová>', 'data' => ['fotka' => 'media/2026/10/jana.jpg', 'role' => 'Obchodní ředitelka', 'jazyky' => 'CZ, EN', 'telefon' => '+420 777 123 456', 'e_mail' => 'jana@example.com', // check-english: allow
    'nepritomnost' => 'Dovolená do pátku', 'o_mne' => '<p>Deset let v oboru.</p>']]; // check-english: allow
$signature = $signatureClass::render($peopleCollection, $signaturePerson, $signatureSite);
check('2.10: the signature is one table with inline styles only, at most 600 px wide', [preg_match('/<style|<script|class=|\son[a-z]+=/i', $signature['html']), str_starts_with($signature['html'], '<table role="presentation"'), str_contains($signature['html'], 'max-width:600px')], [0, true, true]);
check('2.10: the signature has the escaped name, the role, the phone with a tel: link, the brand colour and font, absolute photo and logo', [str_contains($signature['html'], 'Jana &lt;Nová&gt;'), str_contains($signature['html'], 'Obchodní ředitelka'), // check-english: allow
    str_contains($signature['html'], 'href="tel:+420777123456"'), str_contains($signature['html'], 'href="mailto:jana@example.com"'), str_contains($signature['html'], 'src="https://example.com/media/2026/10/jana.jpg" width="72" height="72"'),
    str_contains($signature['html'], 'src="https://example.com/media/logo.png"'), str_contains($signature['html'], 'border-left:3px solid #0f766e'), str_contains($signature['html'], 'Georgia, serif'), str_contains($signature['html'], 'Firma &amp; spol.')], // check-english: allow
    [true, true, true, true, true, true, true, true, true]);
check('2.10: the signature never carries the absence, the about text or the languages', [str_contains($signature['html'] . $signature['text'], 'Dovolen'), str_contains($signature['html'] . $signature['text'], 'Deset let'), str_contains($signature['html'] . $signature['text'], 'CZ, EN')], [false, false, false]);
check('2.10: the plain-text version', $signature['text'], "Jana <Nová>\nObchodní ředitelka\n+420 777 123 456 · jana@example.com\nFirma & spol. · example.com\nDlouhá 1, 110 00 Praha"); // check-english: allow
$bareSignature = $signatureClass::render(['fields' => $peopleFields], ['name' => 'Petr', 'data' => ['e_mail' => 'not an address', 'fotka' => '']],
    ['name' => 'Web', 'url' => '', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => '', 'color' => 'red', 'text_font' => '', 'heading_font' => '', 'logo' => '']);
check('2.10: a person without a photo and with an invalid e-mail: no image, the company phone, a safe colour and font', [str_contains($bareSignature['html'], '<img'), str_contains($bareSignature['html'], 'not an address'),
    str_contains($bareSignature['html'], 'border-left:3px solid #121212'), str_contains($bareSignature['html'], 'system-ui'), $bareSignature['text']], [false, false, true, true, "Petr\n+420 222 000 111\nWeb"]);
check('Items as pages: SEO fields and publishing schedule', [Talea\Builder\Collections::pageFields(['image' => 'javascript:alert(1)', 'noindex' => '1', 'publish_at' => '2099-01-01T08:00', 'description' => str_repeat('a', 400)], false),
    Talea\Builder\Collections::pageFields(['publish_at' => '2001-01-01 08:00'], false)['visible']],
    [['seo_title' => '', 'description' => str_repeat('a', 300), 'image' => '', 'noindex' => 1, 'publish_at' => '2099-01-01 08:00:00', 'visible' => 0], 1]);
$privacy = Talea\Core\Language::runWith('en', fn (): string => Talea\Builder\Library::privacyPolicyText());
check('Privacy policy: a template with a notice, without settings only enquiries', [str_contains($privacy, 'not legal advice'), str_contains($privacy, 'enquiry form'), str_contains($privacy, 'newsletter'),
    str_contains(Talea\Core\Language::runWith('cs', fn (): string => Talea\Builder\Library::privacyPolicyText()), 'nikoli právní rada')], [true, true, false, true]); // check-english: allow
// import of a Talea export (1.8): which files from the archive may go into media/, which names are exports
check('Import Talea: soubory do media/', array_map(Talea\Core\SiteImport::mediaTarget(...), ['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', 'media/x.php', 'media/../config.php', // check-english: allow
    'media/.htaccess', 'content.json', 'media/2026/09/dokument.pdf', 'media/a/b.phtml', 'media/2026/09/logo.svg']),
    ['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', null, null, null, null, 'media/2026/09/dokument.pdf', null, 'media/2026/09/logo.svg']);
check('Talea import: file names', array_map(Talea\Core\SiteImport::isValidName(...), ['export-20260928-101010.zip', 'web.json', 'state-0123456789abcdef.json',
    'talea-state-0123456789abcdef.json', '../web.zip', 'web.xml', '.web.zip']), [true, true, false, false, false, false, false]);
// webhook signature (1.8): HMAC-SHA256 of "timestamp.body" – the receiver recomputes it with the shared secret
check('Webhook: HMAC signature of the timestamp and body', Talea\Core\Webhook::signature('whsec_test', '{"event":"test"}', 1789900000),
    ['1789900000', 'sha256=' . hash_hmac('sha256', '1789900000.{"event":"test"}', 'whsec_test')]);
check('Webhook: another body = another signature', Talea\Core\Webhook::signature('whsec_test', '{"event":"test2"}', 1789900000)[1] !== Talea\Core\Webhook::signature('whsec_test', '{"event":"test"}', 1789900000)[1], true);
$h = Talea\Core\RemoteBackup::signS3('PUT', 's3.eu-central-1.amazonaws.com', '/muj-bucket/talea-zaloha.sql.gz', hash('sha256', 'content'), 'eu-central-1', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 1789900000);
check('S3: scope and signed headers', str_contains($h['Authorization'], 'Credential=AKIDEXAMPLE/20260920/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='), true);
check('S3: the signature has 64 hexadecimal characters', (bool) preg_match('/Signature=[0-9a-f]{64}$/', $h['Authorization']), true);

/* ---------- link check: only public URLs (protection against probing the internal network) ---------- */
check('Links: picking links from HTML', Talea\Core\Links::links('<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="#kotva">k</a> <a class="x" href="/clanek/muj">c</a> <a href="https://example.com/a?x=1&amp;y=2">znovu</a></p>'), ['https://example.com/a?x=1&y=2', '/clanek/muj']);
foreach (['http://127.0.0.1/', 'http://localhost/', 'http://10.0.0.5/admin', 'http://192.168.1.1/', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'ftp://example.com/', 'https://example.com:8443/', 'file:///etc/passwd', 'gopher://x/'] as $internal) {
    check('Odkazy: nekontroluje se ' . $internal, Talea\Core\Links::isPublic($internal), false);
}
check('Links: a public address is checked', Talea\Core\Links::isPublic('https://93.184.216.34/stranka'), true);

/* ---------- assistant: article translation (HTML skeleton from the original, texts from the model) ---------- */
$articleHtml = '<h2>Nadpis oddílu</h2><p>První <strong>tučný</strong> a <a href="/x?a=1&amp;b=2">odkaz</a>.</p><figure><img src="a.jpg" alt="x"><figcaption>Popisek fotky</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>'; // check-english: allow
$r = Talea\Core\Assistant::decompose($articleHtml);
check('Assistant::decompose: segments to translate', $r['segments'], ['Nadpis oddílu', 'První [[0]]tučný[[1]] a [[2]]odkaz[[3]].', 'Popisek fotky']); // check-english: allow
check('Assistant::compose: unchanged text returns the original HTML', Talea\Core\Assistant::compose($r['skeleton'], $r['segments']), $articleHtml);
check('Assistant::compose: HTML from the model is printed as text', str_contains(Talea\Core\Assistant::compose($r['skeleton'], ['<script>alert(1)</script>', 'x', '<img src=x onerror=alert(1)>']), '<script>alert(1)') || str_contains(Talea\Core\Assistant::compose($r['skeleton'], ['a', 'b', '<img src=x onerror=alert(1)>']), '<img src=x'), false);
check('Assistant::compose: a missing symbol = a segment without formatting', Talea\Core\Assistant::compose($r['skeleton'], ['N', 'First [[0]]bold[[1]] and link.', 'P']), '<h2>N</h2><p>First bold and link.</p><figure><img src="a.jpg" alt="x"><figcaption>P</figcaption></figure><script>alert("nepřekládat")</script><p>2024</p>'); // check-english: allow
check('Assistant::compose: wrongly nested symbols = a segment without formatting', str_contains(Talea\Core\Assistant::compose($r['skeleton'], ['N', '[[1]]bold[[0]] [[2]]link[[3]]', 'P']), '<strong>'), false);
check('Assistant::compose: a shuffled word order keeps the formatting', str_contains(Talea\Core\Assistant::compose($r['skeleton'], ['N', 'A [[2]]link[[3]] and [[0]]bold[[1]] first.', 'P']), '<p>A <a href="/x?a=1&amp;b=2">link</a> and <strong>bold</strong> first.</p>'), true);

$settings = (new ReflectionClass(Talea\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Talea\Core\Settings::class, 'values'))->setValue($settings, ['site_name' => 'Test', 'ai_key' => 'x']);
$fake = new class($settings) extends Talea\Core\Assistant {
    public int $calls = 0;

    protected function call(array $body): array
    {
        $this->calls++;
        preg_match('#<segments>\n(.*)\n</segments>#s', $body['messages'][0]['content'], $m);

        return ['content' => [['type' => 'text', 'text' => json_encode(['translations' => array_map(mb_strtoupper(...), json_decode($m[1], true))], JSON_UNESCAPED_UNICODE)]]];
    }
};
$translated = $fake->translate(['title' => 'Tom & Jerry „znovu“ ve městě, tentokrát úplně jinak než kdy dřív', 'text' => '<p>Krátký <em>text</em> článku, který má aspoň pár desítek znaků.</p>', 'seo_description' => ''], 'en', ['title', 'seo_description']); // check-english: allow
check('Assistant::translate: plain text is not escaped twice', $translated['title'], 'TOM & JERRY „ZNOVU“ VE MĚSTĚ, TENTOKRÁT ÚPLNĚ JINAK NEŽ KDY DŘÍV'); // check-english: allow
check('Assistant::translate: an HTML field keeps the skeleton', $translated['text'], '<p>KRÁTKÝ <em>TEXT</em> ČLÁNKU, KTERÝ MÁ ASPOŇ PÁR DESÍTEK ZNAKŮ.</p>'); // check-english: allow
check('Assistant::translate: an empty field stays empty', $translated['seo_description'], '');
$fake->calls = 0;
$long = $fake->translate(['text' => str_repeat('<p>' . str_repeat('Věta o něčem. ', 100) . '</p>', 9)], 'en'); // check-english: allow
check('Assistant::translate: a long article goes in batches', [$fake->calls > 1, substr_count($long['text'], '<p>')], [true, 9]);
try {
    $fake->translate(['text' => '<p>nic</p>'], 'xx');
    check('Assistant::translate: an unknown language is rejected', 'prošlo', 'výjimka'); // check-english: allow
} catch (RuntimeException) {
    check('Assistant::translate: an unknown language is rejected', 'výjimka', 'výjimka'); // check-english: allow
}

/* ---------- temporary language switch (e-mails in the recipient's language) ---------- */
Talea\Core\Language::set('cs');
check('Language::runWith: the other language applies inside', Talea\Core\Language::runWith('en', fn (): string => Talea\Core\Language::code() . '|' . t('Read article →')), 'en|Read article →');
check('Language::runWith: the language is restored afterwards', Talea\Core\Language::code() . '|' . t('Read article →'), 'cs|Číst článek →'); // check-english: allow
try {
    Talea\Core\Language::runWith('en', function (): never { throw new RuntimeException('x'); });
} catch (RuntimeException) {
}
check('Language::runWith: the language is restored even after an exception', Talea\Core\Language::code(), 'cs');

/* ---------- dominant image color ---------- */
if (function_exists('imagecreatetruecolor')) {
    $temporary = tempnam(sys_get_temp_dir(), 'rs') . '.png';
    $canvas = imagecreatetruecolor(40, 20);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 200, 30, 60));
    imagepng($canvas, $temporary);
    check('Images::color: a single-colour image', Talea\Core\Images::color($temporary), '#c81e3c');
    unlink($temporary);
    check('Images::color: a missing file', Talea\Core\Images::color($temporary), null);
}

/* ---------- scripts: must not look for an element (data attribute) that is never created – that is how the Media dialog broke ---------- */
$whereCreated = [
    'image/editor.js' => ['system/views/admin'], 'image/admin.js' => ['system/views/admin', 'system/src/Admin'], 'image/helper.js' => ['system/views/admin'],
    'image/web.js' => ['system/views/front', 'system/src/Front', 'system/src/Builder/Elements'],
    'image/vitals.js' => ['system/src/Front'],
    'image/print.js' => ['system/views/admin/settings'],
];
foreach ($whereCreated as $script => $folders) {
    $source = (string) file_get_contents(TALEA_ROOT . '/' . $script);
    preg_match_all('/querySelector(?:All)?\(\'\[(data-[a-z0-9-]+)\]\'\)/', $source, $links);
    $withoutSearch = (string) preg_replace('/(querySelector(All)?|closest|matches)\([^)]*\)/', '', $source);
    $missing = [];
    foreach (array_unique($links[1]) as $attribute) {
        $inTemplates = false;
        foreach ($folders as $folder) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TALEA_ROOT . '/' . $folder, FilesystemIterator::SKIP_DOTS)) as $file) {
                $inTemplates = $inTemplates || str_contains((string) file_get_contents($file->getPathname()), $attribute);
            }
        }
        if (!$inTemplates && !preg_match('/[\s"\']' . preg_quote($attribute, '/') . '[\s>="\']/', $withoutSearch) && !str_contains($withoutSearch, "setAttribute('" . $attribute . "'")) {
            $missing[] = $attribute;
        }
    }
    check($script . ': every searched data-attribute is rendered somewhere', $missing, []);
}

/* ---------- the admin has a Content-Security-Policy without 'unsafe-inline': no inline scripts or event handlers ---------- */
$inline = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(TALEA_ROOT . '/system/views/admin', FilesystemIterator::SKIP_DOTS)) as $file) {
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match('#<script(?![^>]*\bsrc=)(?![^>]*type="application/json")[^>]*>|\son(?:click|change|input|submit|load|error|key\w+|mouse\w+)="#i', $source)) {
        $inline[] = substr($file->getPathname(), strlen(TALEA_ROOT) + 1);
    }
}
check('admin templates contain no inline scripts (CSP)', $inline, []);

/* ---------- publisher signatures: several keys, key rotation and revocation ---------- */
if (function_exists('sodium_crypto_sign_keypair')) {
    $pair = fn (): array => (fn (string $p): array => [sodium_crypto_sign_secretkey($p), sodium_crypto_sign_publickey($p)])(sodium_crypto_sign_keypair());
    [[$skPrimary, $pkPrimary], [$skBackup, $pkBackup], [$skNew, $pkNew], [$skForeign]] = [$pair(), $pair(), $pair(), $pair()];
    $sign = fn (string $message, string $sk): string => base64_encode(sodium_crypto_sign_detached($message, $sk));
    $pub = tempnam(sys_get_temp_dir(), 'rs');
    file_put_contents($pub, "# note\n" . base64_encode($pkPrimary) . " operating\n\nnonsense-that-is-not-a-key\n" . base64_encode($pkBackup) . " backup 2026-09-20\n");
    $message = Talea\Core\Signature::packageMessage('3.0.1', str_repeat('A', 64), false);
    check('Signature::keys: two valid keys, notes and nonsense are skipped', count(Talea\Core\Signature::keys($pub)), 2);
    check('Signature: the operating key is valid', Talea\Core\Signature::isValid($message, $sign($message, $skPrimary), $pub), true);
    check('Signature: the backup key is valid too', Talea\Core\Signature::isValid($message, $sign($message, $skBackup), $pub), true);
    check('Signature: a foreign key is invalid', Talea\Core\Signature::isValid($message, $sign($message, $skForeign), $pub), false);
    check('Signature: a damaged signature is invalid', Talea\Core\Signature::isValid($message, 'AAAA', $pub), false);
    check('Signature: a regular release cannot be passed off as a security one', Talea\Core\Signature::isValid(Talea\Core\Signature::packageMessage('3.0.1', str_repeat('A', 64), true), $sign($message, $skPrimary), $pub), false);
    check('Signature: the package hash is compared case-insensitively', Talea\Core\Signature::packageMessage('3.0.1', 'ABC', false), '3.0.1|abc|regular');
    // a leak of the primary key: a release signed with the backup key brings a file without it and with a new primary key
    file_put_contents($pub, base64_encode($pkNew) . " operating\n" . base64_encode($pkBackup) . " backup\n");
    check('key rotation: a revoked key is no longer valid', Talea\Core\Signature::isValid($message, $sign($message, $skPrimary), $pub), false);
    check('key rotation: the new operating key is valid', Talea\Core\Signature::isValid($message, $sign($message, $skNew), $pub), true);
    file_put_contents($pub, '');
    check('Signature: with no keys nothing is valid', Talea\Core\Signature::isValid($message, $sign($message, $skNew), $pub), false);
    unlink($pub);
}
// Talea's publisher keys are created only before the first release (docs/RELEASING.md); until then the file may have no key, but it must be readable.
check('system/update.pub is readable', is_array(Talea\Core\Signature::keys(TALEA_ROOT . '/system/update.pub')), true);

/* ---------- installer: every text has a translation in all languages ---------- */
$keys = [];
foreach (['system/views/install/form.php', 'system/views/install/done.php', 'system/src/Install/Installer.php'] as $file) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents(TALEA_ROOT . '/' . $file), $found);
    foreach ($found[1] as $text) {
        $keys[stripslashes($text)] = true;
    }
}
// the installer prints the extension and sample site cards via t() from constants
foreach ([...array_values(Talea\Core\Extensions::CATALOG), ...array_values(Talea\Builder\Library::SITES)] as $card) {
    $keys[$card['name'] ?? $card[0]] = true;
    $keys[$card['description'] ?? $card[1]] = true;
}
foreach (['en', 'de'] as $code) {
    // English: Czech keys to English on top of the English source texts; German (2.5): every source text translated
    $dictionary = (require TALEA_ROOT . '/system/languages/install-' . $code . '.php') + ($code === 'en' ? require TALEA_ROOT . '/system/languages/install-cs.php' : []);
    // international words are not translated (the dictionary tool does not write identical entries)
    $missing = array_values(array_diff(array_keys($keys), array_keys($dictionary), ['Server', 'Port', 'E-mail', 'Newsletter']));
    check('installer: complete dictionary ' . $code, $missing, []);
}

/* ---------- 2.6: import from a website ---------- */
check('2.6 WebImport::sitemap: pages and nested sitemaps', [
    Talea\Core\WebImport::sitemap('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.cz/</loc></url><url><loc> https://a.cz/o-nas </loc></url></urlset>'),
    Talea\Core\WebImport::sitemap('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://a.cz/page-sitemap.xml</loc></sitemap></sitemapindex>'),
    Talea\Core\WebImport::sitemap('<html>not a sitemap'), Talea\Core\WebImport::sitemap('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>'),
], [[['https://a.cz/', 'https://a.cz/o-nas'], []], [[], ['https://a.cz/page-sitemap.xml']], [[], []], [[], []]]); // an external entity is never loaded
check('2.6 WebImport: addresses – normalized, absolute, links without mail and scripts', [
    Talea\Core\WebImport::normalize('HTTPS://A.cz/o-nas/index.html?utm_source=x&id=5#top'), Talea\Core\WebImport::normalize('ftp://a.cz/x'),
    Talea\Core\WebImport::absolute('b/c', 'https://a.cz/dir/page'), Talea\Core\WebImport::absolute('//cdn.a.cz/x.jpg', 'https://a.cz/'),
    Talea\Core\WebImport::links('<a href="/x">1</a><a href="mailto:a@a.cz">2</a><a href="javascript:alert(1)">3</a><a href="#top">4</a>', 'https://a.cz/'),
], ['https://a.cz/o-nas/?id=5', '', 'https://a.cz/dir/b/c', 'https://cdn.a.cz/x.jpg', ['https://a.cz/x']]);
$webPage = Talea\Core\WebImport::extract('<html><head><title>About us | Acme</title><meta name="description" content="Who we are"></head><body>'
    . '<header><nav><a href="/">Home</a></nav></header><main><h1>About us</h1><p>We build oak furniture since 1990, for homes and offices across the region. ' . str_repeat('More text. ', 10) . '</p>'
    . '<img data-src="/img/team.jpg" src="data:image/gif;base64,x" alt="Our team"><p><a href="https://www.a.cz/contact/">Contact</a> <a href="https://other.cz/">Partner</a></p>'
    . '<div class="cookie-banner">We use cookies</div><script>alert(1)</script><form><input name="q"></form></main><footer>© Acme</footer></body></html>', 'https://www.a.cz/about-us/');
check('2.6 WebImport::extract: the main content without the header, footer, cookie bar, script and form', [
    $webPage['title'], $webPage['description'], str_contains($webPage['content'], 'oak furniture'), str_contains($webPage['content'], 'Home'), str_contains($webPage['content'], '©'),
    str_contains($webPage['content'], 'cookies'), str_contains($webPage['content'], 'alert'), str_contains($webPage['content'], '<form'), str_contains($webPage['content'], '<h1'),
    str_contains($webPage['content'], 'src="https://www.a.cz/img/team.jpg"'), str_contains($webPage['content'], 'href="/contact"'), str_contains($webPage['content'], 'href="https://other.cz/"'), $webPage['article'],
], ['About us', 'Who we are', true, false, false, false, false, false, false, true, true, true, false]);
$webArticle = Talea\Core\WebImport::extract('<html><head><title>New workshop</title><meta property="article:published_time" content="2025-03-04T10:00:00+01:00"></head><body><article><p>' . str_repeat('We opened a new workshop. ', 6) . '</p></article></body></html>', 'https://a.cz/2025/03/new-workshop');
check('2.6 WebImport::extract: an article with its date, the title from <title> without the site name', [$webArticle['title'], substr($webArticle['date'], 0, 10), $webArticle['article']], ['New workshop', '2025-03-04', true]);
check('2.6 ImageDownloader: images from any public host only when the import allows it', [(new Talea\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://cdn.wix.example/x.jpg'),
    (new Talea\Core\ImageDownloader('https://a.cz'))->isAllowedUrl('https://cdn.wix.example/x.jpg'), (new Talea\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://user:pw@cdn.example/x.jpg')], [true, false, false]);

/* ---------- German in two registers: formal (Sie) and informal (du), issue #20 ---------- */
// A form of address is a capitalised Sie/Ihr… inside a sentence; at the start of a sentence (or after a quotation mark) it is the application or a term ("Sie handelt…").
$addressForms = static function (string $text): int {
    $count = 0;
    preg_match_all('/\b(Sie|Ihr|Ihre|Ihren|Ihrem|Ihrer|Ihres|Ihnen)\b/u', $text, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [, $offset]) {
        $before = rtrim(substr($text, 0, $offset));
        if ($before !== '' && preg_match('/[.!?:\n„“"»(–]$/u', $before) !== 1) {
            $count++;
        }
    }

    return $count;
};
$scriptDictionary = static function (string $file): array {
    return json_decode(rtrim(preg_replace('/^[^{]*/', '', (string) file_get_contents($file), 1), " \n;)"), true) ?? [];
};
foreach (['admin-de', 'de', 'install-de'] as $set) {
    $base = require TALEA_ROOT . '/system/languages/' . $set . '.php';
    $overlay = require TALEA_ROOT . '/system/languages/' . $set . '-du.php';
    $formalLeft = array_keys(array_filter($overlay, fn ($v, $k): bool => $addressForms((string) $v) > 0, ARRAY_FILTER_USE_BOTH));
    $withoutOverlay = array_keys(array_filter($base, fn ($v, $k): bool => is_string($v) && $addressForms($v) > 0 && !isset($overlay[$k]), ARRAY_FILTER_USE_BOTH));
    check('Deutsch du: ' . $set . '-du.php – only keys of the base dictionary, no Sie/Ihr in the overlay, every base string with a form of address has its counterpart',
        [array_keys(array_diff_key($overlay, $base)), $formalLeft, $withoutOverlay], [[], [], []]);
}
$baseScripts = $scriptDictionary(TALEA_ROOT . '/image/languages/admin-de.js');
$overlayScripts = $scriptDictionary(TALEA_ROOT . '/image/languages/admin-de-du.js');
check('Deutsch du: admin-de-du.js – only keys of admin-de.js, no Sie/Ihr in the overlay, every script text with a form of address has its counterpart', [
    array_keys(array_diff_key($overlayScripts, $baseScripts)),
    array_keys(array_filter($overlayScripts, fn ($v): bool => $addressForms((string) $v) > 0)),
    array_keys(array_filter($baseScripts, fn ($v, $k): bool => $addressForms((string) $v) > 0 && !isset($overlayScripts[$k]), ARRAY_FILTER_USE_BOTH)),
], [[], [], []]);
// every Claude panel suggestion (AskClaude::EXAMPLES) follows the register of the administration
$suggestionsLeft = [];
foreach (['formal', 'informal'] as $register) {
    foreach (Talea\Core\AskClaude::EXAMPLES as $key => [, $suggestion]) {
        $text = Talea\Core\Language::runWith('de', fn (): string => t($suggestion), 'admin-', $register);
        if (($addressForms($text) > 0) !== false && $register === 'informal') {
            $suggestionsLeft[] = $register . ':' . $key;
        }
    }
}
check('Deutsch du: the Claude panel suggestions have no Sie in the informal administration', $suggestionsLeft, []);
check('Deutsch du: the register picks the dictionary (site, admin), is restored after runWith, and English ignores it', [
    Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'formal'),
    Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'informal'),
    Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), 'admin-', 'informal'),
    Talea\Core\Language::runWith('en', fn (): string => t('Enter at least 3 characters.'), '', 'informal'),
    Talea\Core\Language::runWith('de', fn (): string => Talea\Core\Language::runWith('de', fn (): string => 'x', '', 'informal') . Talea\Core\Language::register(), '', 'formal'),
    Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), '', 'nonsense'),
], ['Geben Sie mindestens 3 Zeichen ein.', 'Gib mindestens 3 Zeichen ein.', 'Gib mindestens 3 Zeichen ein.', 'Enter at least 3 characters.', 'xformal', 'Geben Sie mindestens 3 Zeichen ein.']);
Talea\Core\Language::setSiteRegister('informal');
Talea\Core\Language::setAdminRegister('formal');
check('Deutsch du: without an explicit register the site follows german_register and the administration the user’s choice', [
    Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.')),
    Talea\Core\Language::runWith('de', fn (): string => t('Enter at least 3 characters.'), 'admin-'),
], ['Gib mindestens 3 Zeichen ein.', 'Geben Sie mindestens 3 Zeichen ein.']);
Talea\Core\Language::setSiteRegister('formal');
Talea\Core\Language::set('cs', 'admin-');
// the form of address of the visitors reaches Claude: the connection instructions, site_info and the text copied from the dashboard
check('Deutsch du: Language::visitorAddress – only a site with a German version has one', [
    Talea\Core\Language::visitorAddress($reportSettings(['site_language' => 'de', 'german_register' => 'informal'])),
    Talea\Core\Language::visitorAddress($reportSettings(['site_language' => 'de'])),
    Talea\Core\Language::visitorAddress($reportSettings(['site_language' => 'cs', 'additional_languages' => 'de', 'german_register' => 'informal', 'extensions' => 'languages'])),
    Talea\Core\Language::visitorAddress($reportSettings(['site_language' => 'en', 'german_register' => 'informal'])),
    Talea\Core\Language::normalizeRegister('x'),
], ['informal', 'formal', 'informal', null, 'formal']);
$promptIn = fn (string $language, ?string $address, string $register = 'formal'): string => Talea\Core\Language::runWith($language, fn (): string => Talea\Core\AskClaude::prompt('https://example.com', $address), 'admin-', $register);
check('Deutsch du: the text copied for Claude names the form of address of the visitors – in the language and register of the administration', [
    str_contains($promptIn('en', 'informal'), 'informal “du”'), str_contains($promptIn('en', 'formal'), 'formal “Sie”'), str_contains($promptIn('en', null), 'German'),
    str_contains($promptIn('de', 'formal'), 'Schreiben Sie deutsche Texte'), str_contains($promptIn('de', 'formal', 'informal'), 'Schreibe deutsche Texte'),
], [true, true, false, true, true]);

/* ---------- numbers by language ---------- */
check('count: Czech uses a space as the thousands separator', Talea\Core\Language::runWith('cs', fn () => format_count(1234567)), "1\u{00A0}234\u{00A0}567");
check('count: English uses a comma and a decimal point', Talea\Core\Language::runWith('en', fn () => format_count(12345.678, 2)), '12,345.68');
check('Files::size: English decimal point', Talea\Core\Language::runWith('en', fn () => Talea\Core\Files::size(3 * 1048576 + 524288)), '3.5 MB');

/* ---------- marketing codes and consent ---------- */
check('Seo::deferUntilConsent: without a bar unchanged', Talea\Front\Seo::deferUntilConsent('<script src="x.js"></script>', 'none'), '<script src="x.js"></script>');
check('Seo::deferUntilConsent: the built-in bar wraps in <template>', Talea\Front\Seo::deferUntilConsent('<ins></ins><script>a()</script>', 'builtin'), '<template data-consent="marketing"><ins></ins><script>a()</script></template>');
check('Seo::deferUntilConsent: an external service gets marked scripts', Talea\Front\Seo::deferUntilConsent('<ins></ins><SCRIPT async src="x.js"></script><script type="application/json">{}</script>', 'external'),
    '<ins></ins><script type="text/plain" data-cookieconsent="marketing" async src="x.js"></script><script type="application/json">{}</script>');

/* ---------- update: cleaning up files the new release no longer contains ---------- */
$cleanup = sys_get_temp_dir() . '/talea-uklid-' . bin2hex(random_bytes(4));
mkdir($cleanup . '/system/stare', 0775, true);
mkdir($cleanup . '/media', 0775, true);
foreach (['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', 'vlastni.php'] as $f) {
    file_put_contents($cleanup . '/' . $f, 'x');
}
$deleted = Talea\Core\Updater::cleanUpObsolete($cleanup, ['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', '../mimo.php'], ['index.php', 'system/zustava.php']);
check('Update: deletes only the file removed by the new release', $deleted, 1);
check('Update: the removed file and its empty folder are gone', is_dir($cleanup . '/system/stare'), false);
check('Update: protected paths and own files stay', [is_file($cleanup . '/media/foto.jpg'), is_file($cleanup . '/config.php'), is_file($cleanup . '/vlastni.php'), is_file($cleanup . '/system/zustava.php')], [true, true, true, true]);
// files that ride along only for the update (old classes, the alias file of 1.4–2.0) go after it – unless edited; nothing else
mkdir($cleanup . '/system/src/Old', 0775, true);
file_put_contents($cleanup . '/system/class-aliases.php', "<?php\nreturn [];\n");
file_put_contents($cleanup . '/system/src/Old/Gone.php', 'edited');
file_put_contents($cleanup . '/system/files.json', json_encode(['legacy' => ['system/class-aliases.php' => hash('sha256', "<?php\nreturn [];\n"),
    'system/src/Old/Gone.php' => hash('sha256', 'original'), 'system/zustava.php' => hash('sha256', 'x')]]));
check('Update: legacy files go after the update, edited and other files stay', [Talea\Core\Updater::cleanUpRemoved($cleanup), is_file($cleanup . '/system/class-aliases.php'),
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
$pkClient = static fn (string $type, string $challenge, string $origin): string => Talea\Core\Passkey::b64((string) json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false], JSON_UNESCAPED_SLASHES));
$pkRegData = static fn (string $rp, int $flags = 0x45): string => hash('sha256', $rp, true) . chr($flags) . pack('N', 0) . str_repeat("\0", 16) . pack('n', strlen($pkId)) . $pkId . $pkCose;
$pkChallenge = Talea\Core\Passkey::challenge();
$pkReg = ['clientDataJSON' => $pkClient('webauthn.create', $pkChallenge, $pkOrigin), 'authenticatorData' => Talea\Core\Passkey::b64($pkRegData($pkRp)), 'publicKey' => Talea\Core\Passkey::b64($pkDer), 'publicKeyAlgorithm' => -7];
$pkSaved = Talea\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, $pkOrigin, $pkRp);
check('Passkey: registration returns the key id', $pkSaved['id'], Talea\Core\Passkey::b64($pkId));
check('Passkey: registration returns the public key in PEM', str_contains($pkSaved['key'], 'BEGIN PUBLIC KEY'), true);
$pkRejects = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
check('Passkey: registration with a foreign challenge fails', $pkRejects(fn () => Talea\Core\Passkey::verifyRegistration($pkReg, Talea\Core\Passkey::challenge(), $pkOrigin, $pkRp)), true);
check('Passkey: registration from another origin fails', $pkRejects(fn () => Talea\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, 'https://podvrh.example', $pkRp)), true);
check('Passkey: registration for another domain fails', $pkRejects(fn () => Talea\Core\Passkey::verifyRegistration($pkReg, $pkChallenge, $pkOrigin, 'jina.example')), true);
$pkForeign = openssl_pkey_get_details(openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']));
check('Passkey: a planted public key fails', $pkRejects(fn () => Talea\Core\Passkey::verifyRegistration(['publicKey' => Talea\Core\Passkey::b64(base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pkForeign['key'])))] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), true);
check('Passkey: a sign-in response cannot be used for registration', $pkRejects(fn () => Talea\Core\Passkey::verifyRegistration(['clientDataJSON' => $pkClient('webauthn.get', $pkChallenge, $pkOrigin)] + $pkReg, $pkChallenge, $pkOrigin, $pkRp)), true);
$pkSignIn = static function (string $challenge, int $counter, string $rp = 'redakce.example', string $origin = 'https://redakce.example', int $flags = 0x05) use ($pkKey, $pkClient): array {
    $data = hash('sha256', $rp, true) . chr($flags) . pack('N', $counter);
    $client = $pkClient('webauthn.get', $challenge, $origin);
    openssl_sign($data . hash('sha256', Talea\Core\Passkey::fromB64($client), true), $signature, $pkKey, OPENSSL_ALGO_SHA256);

    return ['clientDataJSON' => $client, 'authenticatorData' => Talea\Core\Passkey::b64($data), 'signature' => Talea\Core\Passkey::b64($signature)];
};
$pkV2 = Talea\Core\Passkey::challenge();
check('Passkey: a valid sign-in returns the new counter', Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 4), 5);
check('Passkey: a synced key with a zero counter passes', Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 0), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 0), 0);
check('Passkey: a replayed response (another challenge) fails', $pkRejects(fn () => Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6), Talea\Core\Passkey::challenge(), $pkOrigin, $pkRp, $pkSaved['key'], 5)), true);
check('Passkey: a counter that does not grow fails', $pkRejects(fn () => Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 5), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), true);
check('Passkey: a signature by another key fails', $pkRejects(fn () => Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6), $pkV2, $pkOrigin, $pkRp, $pkForeign['key'], 5)), true);
check('Passkey: a response from a forged domain fails', $pkRejects(fn () => Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example.podvrh.cz'), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), true);
check('Passkey: a key of another domain fails', $pkRejects(fn () => Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'jina.example'), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), true);
check('Passkey: without user presence it fails', $pkRejects(fn () => Talea\Core\Passkey::verifySignIn($pkSignIn($pkV2, 6, 'redakce.example', 'https://redakce.example', 0x00), $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), true);
$pkChanged = $pkSignIn($pkV2, 6); $pkChanged['authenticatorData'] = Talea\Core\Passkey::b64(Talea\Core\Passkey::fromB64($pkChanged['authenticatorData']) . 'x');
check('Passkey: altered authenticator data fail', $pkRejects(fn () => Talea\Core\Passkey::verifySignIn($pkChanged, $pkV2, $pkOrigin, $pkRp, $pkSaved['key'], 5)), true);
check('Passkey: origin and domain from the site address', [Talea\Core\Passkey::origin('https://WWW.Web.cz/'), Talea\Core\Passkey::origin('http://localhost:8080'), Talea\Core\Passkey::rpId('https://www.web.cz:8443/x')], ['https://www.web.cz', 'http://localhost:8080', 'www.web.cz']);

/* ---------- .htaccess: rewrite targets are URLs, not relative paths ---------- */
// A relative target (RewriteRule ^ index.php) ends in a loop and error 500 on hosts that map subdomains into a folder outside the web root.
$htaccess = (string) file_get_contents(TALEA_ROOT . '/.htaccess');
preg_match_all('/^\s*RewriteRule\s+\S+\s+(\S+)/m', $htaccess, $targets);
check('.htaccess: no rewrite has a relative target', array_values(array_filter($targets[1], static fn (string $c): bool => $c !== '-' && !str_starts_with($c, '%{ENV:BASE}/'))), []);
check('.htaccess: the site folder is computed from the request address', str_contains($htaccess, 'E=BASE:%1'), true);

/* ---------- menu paths as links (messages, System status, help) ---------- */
Talea\Core\Language::set('cs', 'admin-');
$routesHtml = Talea\Admin\MenuPaths::links('/admin.php', 'Je k dispozici nová verze 3.0.1 – nainstalujete ji v Nastavení → Zálohy a aktualizace. <b>', ['settings']); // check-english: allow
check('Paths: a known path is a link', str_contains($routesHtml, '<a href="/admin.php?module=settings&amp;tab=backups">Nastavení → Zálohy a aktualizace</a>'), true); // check-english: allow
check('Paths: the rest of the text stays escaped', str_contains($routesHtml, '&lt;b&gt;'), true);
check('Paths: a longer path wins and the link does not nest', substr_count($routesHtml, '<a '), 1);
check('Paths: without the right to the module no link', str_contains(Talea\Admin\MenuPaths::links('/admin.php', 'Nastavení → Pošta', []), '<a '), false); // check-english: allow
Talea\Core\Language::set('en', 'admin-');
check('Menu paths: in English the translated path is linked', str_contains(Talea\Admin\MenuPaths::links('/admin.php', t('A new version %s is available – install it in Settings → Backups and updates.', '3.0.1'), ['settings']), '>Settings → Backups and updates</a>'), true);
Talea\Core\Language::set('cs', 'admin-');

/* ---------- date in words in the administration (3.2.1: the English and German admin showed Czech months) ---------- */
check('Date in words: Czech admin', format_date_long('2026-10-07'), 'středa 7. října 2026'); // check-english: allow
Talea\Core\Language::set('en', 'admin-');
check('Date in words: English admin', format_date_long('2026-10-07'), 'Wednesday 7 October 2026');
Talea\Core\Language::set('de', 'admin-');
check('Date in words: German admin', format_date_long('2026-10-07'), 'Mittwoch, 7. Oktober 2026');
Talea\Core\Language::set('cs', 'admin-');

/* ---------- spam protection: IP hash ---------- */
check('Antispam::hash: it is not the IP address', str_contains(Talea\Core\Antispam::hash('203.0.113.7'), '203'), false);
check('Antispam::hash: the same address = the same hash', Talea\Core\Antispam::hash('203.0.113.7'), Talea\Core\Antispam::hash('203.0.113.7'));

/* ---------- import from WordPress: reading the export (tools/fixtures/wordpress-sample.xml), preview, safe XML ---------- */
$wpPath = TALEA_ROOT . '/tools/fixtures/wordpress-sample.xml';
$wpRejects = static function (callable $f): bool { try { $f(); return false; } catch (RuntimeException) { return true; } };
$wp = new Talea\Core\WpFile($wpPath);
check('WpFile: the sample export passes validation', $wpRejects(fn () => $wp->verify()), false);
$wpHeader = $wp->header();
check('WpFile: the old site from <channel><link>', [$wpHeader['name'], $wpHeader['url']], ['Podhorský zpravodaj', 'https://www.podhorsky-zpravodaj.example']); // check-english: allow
check('WpFile: authors as login => display name', $wpHeader['authors'], ['redakce' => 'Redakce Zpravodaje', 'bhorakova' => 'Běla Horáková']); // check-english: allow
check('WpFile: categories with a hierarchy', $wpHeader['categories'], ['zpravy' => ['name' => 'Zprávy', 'parent' => ''], 'z-radnice' => ['name' => 'Z radnice', 'parent' => 'zpravy']]); // check-english: allow
check('WpFile: three tags', array_keys($wpHeader['tags']), ['most', 'doprava', 'slavnosti']);
$wpItems = iterator_to_array($wp->items());
check('WpFile: nine items, types in file order', array_column($wpItems, 'type'), ['post', 'post', 'post', 'post', 'page', 'nav_menu_item', 'attachment', 'attachment', 'attachment']);
check('WpFile: skipping already processed items keeps the order', array_keys(iterator_to_array($wp->items(7))), [7, 8]);
check('WpFile: the first post', [$wpItems[0]['id'], $wpItems[0]['status'], $wpItems[0]['sticky'], $wpItems[0]['preview'], $wpItems[0]['categories'], array_keys($wpItems[0]['tags'])], [101, 'publish', true, 201, ['z-radnice' => 'Z radnice'], ['most', 'doprava']]);
check('WpFile: comments are not read', array_key_exists('komentare', $wpItems[0]), false);
check('WpSoubor: e-mail ani IP se z exportu nikam nedostanou', (bool) preg_match('/posta\.example|198\.51\.100|203\.0\.113/', (string) json_encode($wpItems)), false);
$wpState = Talea\Core\WpImport::newState('wordpress-sample.xml');
Talea\Core\WpImport::analyze($wpState, 30, $wpPath);
check('WpImport preview: phase and item count', [$wpState['phase'], $wpState['total'], $wpState['position']], ['preview', 9, 0]);
check('WpImport preview: posts by status and pages', [$wpState['overview']['articles'], $wpState['overview']['pages']], [['publish' => 3, 'draft' => 1], ['publish' => 1]]);
check('WpImport preview: categories, tags, authors, attachments', [$wpState['overview']['categories'], $wpState['overview']['tags'], $wpState['overview']['authors'], $wpState['overview']['attachments']], [2, 3, 2, 3]);
check('WpImport preview: warns about a foreign content type and a plugin shortcode', [$wpState['overview']['other'], $wpState['overview']['shortcodes']], [['nav_menu_item' => 1], ['kontaktni-formular' => 1]]);
check('WpImport preview: attachment addresses for galleries and featured images', $wpState['attachments'][202] ?? '', 'https://www.podhorsky-zpravodaj.example/wp-content/uploads/2026/05/pohled.jpg');

/* ---------- import from WordPress: SEO plugin data (SmartCrawl, Yoast SEO, Rank Math) ---------- */
check('WpFile: reads only the SEO plugins\' meta keys', [array_keys($wpItems[0]['meta']), $wpItems[3]['meta']], [['_wds_title', '_wds_metadesc', '_wds_meta-robots-noindex'], []]);
check('WpImport preview: SEO data by plugin (the default Yoast pattern is not counted)', $wpState['overview']['seo'], [
    'SmartCrawl' => ['title' => 2, 'description' => 2, 'noindex' => 0, 'canonical' => 1],
    'Yoast SEO' => ['title' => 0, 'description' => 1, 'noindex' => 1, 'canonical' => 0],
    'Rank Math' => ['title' => 1, 'description' => 1, 'noindex' => 1, 'canonical' => 1],
]);
$wpSeoContext = ['title' => 'Lávka přes Bystřinu', 'sitename' => 'Podhorský zpravodaj', 'sitedesc' => 'Zprávy z údolí', 'excerpt' => 'Po roce oprav.', 'category' => 'Z radnice']; // check-english: allow
check('WpSeo::raw: the first plugin with a filled value; Yoast 2 = index', Talea\Core\WpSeo::raw(['_yoast_wpseo_meta-robots-noindex' => '2', '_yoast_wpseo_title' => ' T ']), ['plugin' => 'Yoast SEO', 'title' => 'T', 'description' => '', 'noindex' => false, 'canonical' => '']);
check('WpSeo::raw: bez SEO meta', Talea\Core\WpSeo::raw(['_thumbnail_id' => '5'])['plugin'], '');
check('WpSeo::robotsNoindex: a serialized Rank Math array only as text', array_map(Talea\Core\WpSeo::robotsNoindex(...), ['a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}', 'a:1:{i:0;s:5:"index";}', 'a:1:{i:0;s:12:"noimageindex";}', 'noindex,nofollow', 'O:8:"stdClass":0:{}', '']), [true, false, false, true, false, false]);
check('WpSeo::isDefaultPattern: only variables and separators = the plugin\'s default pattern', array_map(Talea\Core\WpSeo::isDefaultPattern(...), ['%%title%% %%sep%% %%sitename%%', '%%title%% %%page%% %%sep%% %%sitename%%', '%title% %sep% %sitename%', '%%title%% | %%sitename%%', '%%title%%', '', 'Blog – %%sitename%%', 'Nabídka %%title%%']), [true, true, true, true, true, true, false, false]); // check-english: allow
check('WpSeo::title: Yoast and SmartCrawl variables are filled in, the separator is a dash', Talea\Core\WpSeo::title('Lávka znovu otevřena %%sep%% %%sitename%%', $wpSeoContext), 'Lávka znovu otevřena – Podhorský zpravodaj'); // check-english: allow
check('WpSeo::title: Rank Math variables', Talea\Core\WpSeo::title('%title% – fotografie %sep% %sitename%', $wpSeoContext), 'Lávka přes Bystřinu – fotografie – Podhorský zpravodaj'); // check-english: allow
check('WpSeo::title: the default pattern is not imported', [Talea\Core\WpSeo::title('%%title%% %%sep%% %%sitename%%', $wpSeoContext), Talea\Core\WpSeo::title('%title% %page% %sep% %sitename%', $wpSeoContext)], ['', '']);
check('WpSeo::title: pagination and date vanish along with an extra separator', Talea\Core\WpSeo::title('%%title%% %%page%% – %%currentyear%% – Blog', $wpSeoContext), 'Lávka přes Bystřinu – ' . date('Y') . ' – Blog'); // check-english: allow
check('WpSeo::title: a removed variable leaves no double separator', Talea\Core\WpSeo::title('%%title%% %%sep%% %%page%% %%sep%% Blog', $wpSeoContext), 'Lávka přes Bystřinu – Blog'); // check-english: allow
check('WpSeo::title: an unknown variable = the title is dropped, not broken', [Talea\Core\WpSeo::title('%%title%% %%sep%% %%neznama_promenna%%', $wpSeoContext), Talea\Core\WpSeo::resolve('%%title%% %%neznama%%', $wpSeoContext)], ['', null]);
check('WpSeo::title: custom fields (cf_) and terms (ct_) are only removed', Talea\Core\WpSeo::title('Nabídka %%title%% %%cf_moje_pole%% %%ct_oblast%%', $wpSeoContext), 'Nabídka Lávka přes Bystřinu'); // check-english: allow
check('WpSeo::title: identical to the post title is not stored', Talea\Core\WpSeo::title('%%title%%%%cf_x%%', $wpSeoContext), '');
check('WpSeo::title: length by column', mb_strlen(Talea\Core\WpSeo::title(str_repeat('ž', 300), $wpSeoContext, 200)), 200); // check-english: allow
check('WpSeo::description: excerpt and main category', Talea\Core\WpSeo::description('%%excerpt%% Více v rubrice %%primary_category%%.', $wpSeoContext), 'Po roce oprav. Více v rubrice Z radnice.'); // check-english: allow
check('WpSeo::description: only %%excerpt%% is the default pattern – the site builds the description itself', Talea\Core\WpSeo::description('%%excerpt%%', $wpSeoContext), '');
check('WpSeo::description: tags and entities gone', Talea\Core\WpSeo::description('Sýr &amp; <b>víno</b> v %sitename%', $wpSeoContext), 'Sýr & víno v Podhorský zpravodaj'); // check-english: allow
check('WpSeo::keys: one key list from all plugins', [count(Talea\Core\WpSeo::keys()), in_array('rank_math_robots', Talea\Core\WpSeo::keys(), true)], [12, true]);

/* ---------- import from WordPress: custom post types and fields as collections (2.7, tools/fixtures/wordpress-cpt.xml) ---------- */
check('WpTypes::isCustomType: own types yes, WordPress and plugin internals no', array_map(Talea\Core\WpTypes::isCustomType(...), ['reference', 'team_member', 'product', 'page', 'nav_menu_item', 'wp_block', 'acf-field', 'breakdance_template', 'shop_order', 'wpcf7_contact_form', 'Bad Type!']),
    [true, true, true, false, false, false, false, false, false, false, false]);
check('WpTypes::fields: ACF fields always, plugin and underscore meta never', Talea\Core\WpTypes::fields(['client_id' => 'A', '_klient' => 'field_1', 'rank_math_title' => 'x', '_edit_lock' => '1', 'ekit_views' => '3', 'cena' => '100', 'site-sidebar-layout' => 'x']),
    ['client_id' => 'A', 'cena' => '100']);
check('WpTypes::guessType', array_map(fn (array $c): ?string => Talea\Core\WpTypes::guessType($c[0], $c[1], [301 => 'https://x/a.jpg']), [
    ['fotka', '301'], ['logo_firmy', '77'], ['count', '77'], ['x', 'https://old.example/a/b.png?v=2'], ['date', '20240315'], ['date', '20241345'], ['web', 'https://novakovi.example'],
    ['cena', '1 200'], ['cena', '1200,50'], ['description', '<p>Hi</p>'], ['url', "Ulice 1\nMěsto"], ['name', 'Jana'], ['galerie', 'a:2:{i:0;s:3:"301";}'], ['x', '']]), // check-english: allow
    ['image', 'image', 'number', 'image', 'date', 'number', 'link', 'text', 'number', 'html', 'lines', 'text', null, 'text']);
check('WpTypes::fieldType, date, label, prefix', [Talea\Core\WpTypes::fieldType(['text' => 3, 'lines' => 1]), Talea\Core\WpTypes::fieldType(['image' => 1, 'text' => 0]), Talea\Core\WpTypes::fieldType([]),
    Talea\Core\WpTypes::date('20240315'), Talea\Core\WpTypes::date('2024-02-30'), Talea\Core\WpTypes::label('team_member-role'), Talea\Core\WpTypes::prefix('https://a.cz/reference/kuchyne/'), Talea\Core\WpTypes::prefix('https://a.cz/?p=4')],
    ['lines', 'image', 'text', '2024-03-15', '', 'Team member role', 'reference', '']);
$cptPath = TALEA_ROOT . '/tools/fixtures/wordpress-cpt.xml';
$cptItems = iterator_to_array((new Talea\Core\WpFile($cptPath))->items());
check('WpSoubor: fields of a custom post type are read, of other types not', [array_keys($cptItems[1]['fields']), $cptItems[0]['fields']],
    [['klient', '_klient', 'rok_dokonceni', '_rok_dokonceni', 'datum_predani', '_datum_predani', 'web_klienta', '_web_klienta', 'fotka', '_fotka', 'galerie', '_galerie', 'rank_math_seo_score', 'ekit_post_views_count'], []]); // check-english: allow
$cptState = Talea\Core\WpImport::newState('wordpress-cpt.xml');
Talea\Core\WpImport::analyze($cptState, 30, $cptPath);
check('WpImport preview: a custom post type with its fields, address and what is left out', [$cptState['overview']['types'], $cptState['overview']['other']], [['reference' => [
    'count' => 2, 'prefixes' => ['reference' => 2], 'fields' => ['klient' => ['text' => 2], 'rok_dokonceni' => ['number' => 2], 'datum_predani' => ['date' => 2], 'web_klienta' => ['link' => 1], 'fotka' => ['image' => 1, 'text' => 0]],
    'left_out' => ['galerie' => true], 'content' => true, 'excerpt' => false]], []]); // check-english: allow

$wpTmp = sys_get_temp_dir() . '/talea-wp-' . bin2hex(random_bytes(4));
mkdir($wpTmp);
$wpHead = '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title><link>https://stary.example</link>';
file_put_contents($wpTmp . '/tajne.txt', 'TAJNY-OBSAH-SERVERU');
$wpMalicious = [
    'vnější entita (XXE)' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://' . $wpTmp . '/tajne.txt">]>' . $wpHead . '<item><title>&xxe;</title><content:encoded>&xxe;</content:encoded></item></channel></rss>', // check-english: allow
    'miliarda smíchů' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "haha"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]>' . $wpHead . '<item><title>&c;</title></item></channel></rss>', // check-english: allow
    'vnější DTD' => '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://127.0.0.1:1/zly.dtd">' . $wpHead . '</channel></rss>', // check-english: allow
    'nedefinovaná entita' => '<?xml version="1.0"?>' . $wpHead . '<item><title>&neexistuje;</title></item></channel></rss>', // check-english: allow
    'jiné XML než export WordPressu' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Obyčejné RSS</title><item><title>x</title></item></channel></rss>', // check-english: allow
    'poškozené XML' => '<?xml version="1.0"?>' . $wpHead . '<item><title>neuzavřeno</item>', // check-english: allow
    'HTML místo XML' => '<html><body>xmlns:wp="http://wordpress.org/export/1.2/"</body></html>', // check-english: allow
];
foreach ($wpMalicious as $label => $xml) {
    file_put_contents($wpTmp . '/zly.xml', $xml);
    $read = '';
    $rejected = $wpRejects(function () use ($wpTmp, &$read): void {
        $bad = new Talea\Core\WpFile($wpTmp . '/zly.xml');
        $bad->verify();
        $read = (string) json_encode([$bad->header(), iterator_to_array($bad->items())]);
    });
    check('WpFile rejects: ' . $label, [$rejected, str_contains($read, 'TAJNY-OBSAH') || str_contains($read, 'hahahaha')], [true, false]);
}
file_put_contents($wpTmp . '/dobry.xml', '<?xml version="1.0"?>' . $wpHead . '<item><title>A &amp; B</title></item></channel></rss>');
check('WpFile: common entities (&amp;) are fine', iterator_to_array((new Talea\Core\WpFile($wpTmp . '/dobry.xml'))->items())[0]['title'], 'A & B');
exec('rm -rf ' . escapeshellarg($wpTmp));
foreach (['export.xml' => true, 'Můj web.WordPress.2026-09-21.XML' => true, '../config.xml' => false, 'slozka/export.xml' => false, '.skryty.xml' => false, 'export.php' => false, 'export.xml.php' => false, "export\0.xml" => false, '' => false] as $name => $expectedResult) { // check-english: allow
    check('WpSoubor::platnyNazev ' . json_encode((string) $name), Talea\Core\WpFile::isValidName((string) $name), $expectedResult);
}
check('WpFile: the uploaded file name without diacritics and always .xml', Talea\Core\WpFile::uploadName('Můj web.WordPress.2026-09-21.xml'), 'muj-web-wordpress-2026-09-21.xml'); // check-english: allow

/* ---------- import from WordPress: content cleanup ---------- */
$wpClean = Talea\Core\WpContent::sanitize(...);
check('WpContent: classic editor – paragraphs from empty lines, <br> from line ends', $wpClean("První řádek\ndruhý řádek\n\nDruhý odstavec"), "<p>První řádek<br>\ndruhý řádek</p>\n<p>Druhý odstavec</p>"); // check-english: allow
check('WpContent: block tags are not wrapped in <p>', $wpClean("Úvod\n\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>"), "<p>Úvod</p>\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>"); // check-english: allow
check('WpContent: Gutenberg comments vanish, paragraphs stay', $wpClean("<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Nadpis</h1>\n<!-- /wp:heading -->"), "<p>Text</p>\n<h2>Nadpis</h2>");
check('WpContent: [caption] → figure with a caption, the link to the large image vanishes', $wpClean('[caption id="attachment_5" align="alignnone" width="300"]<a href="https://stary.example/wp-content/uploads/most.jpg"><img class="size-medium" src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" /></a> Most přes řeku[/caption]'), '<figure><img src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" loading="lazy"><figcaption>Most přes řeku</figcaption></figure>'); // check-english: allow
check('WpContent: [gallery ids] → our gallery only from known images', $wpClean('[gallery ids="5,6,7,99" columns="2"]', [5 => 'https://stary.example/a.jpg', 6 => 'https://stary.example/b.png', 7 => 'https://stary.example/dokument.pdf']), '<figure class="gallery"><img src="https://stary.example/a.jpg" alt="" loading="lazy"><img src="https://stary.example/b.png" alt="" loading="lazy"></figure>');
check('WpContent: Gutenberg gallery block → our gallery', $wpClean('<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://stary.example/a.jpg" alt="A" class="wp-image-5"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'), '<figure class="gallery"><img src="https://stary.example/a.jpg" alt="A" loading="lazy"></figure>');
check('WpContent: a YouTube address on its own line is its own paragraph', $wpClean("Text před\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nText po"), "<p>Text před</p>\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>\n<p>Text po</p>"); // check-english: allow
check('WpContent: the site turns such a paragraph into a player', str_contains((new ReflectionClass(NewsText::class))->newInstanceWithoutConstructor()->embedVideoUrls($wpClean("https://www.youtube.com/watch?v=dQw4w9WgXcQ")), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), true);
check('WpContent: embed block → address in a paragraph', $wpClean('<!-- wp:embed {"url":"https://vimeo.com/76979871","type":"video"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/76979871</div></figure><!-- /wp:embed -->'), '<p>https://vimeo.com/76979871</p>');
check('WpContent: YouTube iframe → address, a foreign iframe gone', $wpClean('<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560"></iframe><iframe src="https://zly.example/"></iframe>'), '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>');
check('WpContent: plugin shortcodes vanish, their text and [sic] stay', $wpClean('[vc_row][vc_column width="1/2"]Text uvnitř[/vc_column][/vc_row] [contact-form-7 id="1"] citace [sic] a [[ukázka]]'), '<p>Text uvnitř  citace [sic] a [[ukázka]]</p>'); // check-english: allow
check('WpContent: brackets in a code sample do not change', $wpClean("<pre>pole[muj_klic] = 1;\n\nkonec</pre>"), "<pre>pole[muj_klic] = 1;\n\nkonec</pre>");
$wpUnsafe = $wpClean('<p onclick="x()" style="color:red">Klik <a href="java&#9;script:alert(1)" onmouseover="x()">odkaz</a> <a href="https://dobry.example/" target="_blank">ven</a></p><script>alert(1)</script><style>p{}</style><img src="data:image/svg+xml;base64,AAAA"><img src="https://stary.example/a.jpg" onerror="alert(1)" srcset="x 2x"><svg onload="alert(1)"><circle/></svg><form action="/x"><input name="a"></form><object data="x"></object><div class="wrap"><span>Text v divu</span></div>');
check('WpContent: scripts, styles, event handlers, javascript: and data: addresses do not pass', $wpUnsafe, "<p>Klik odkaz <a href=\"https://dobry.example/\" target=\"_blank\" rel=\"noopener\">ven</a></p>\n<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"></figure>\n<p>Text v divu</p>"); // check-english: allow
check('WpContent: nothing dangerous is left after cleaning', (bool) preg_match('/<script|<style|<svg|<form|<iframe|<object|\son[a-z]+=|javascript:|data:|style=|srcset=/i', $wpUnsafe), false);
check('WpObsah::bezpecnaAdresa', array_map(Talea\Core\WpContent::isSafeUrl(...), ['https://a.cz/', '/clanek/x', '#kotva', 'mailto:a@b.cz', "java\nscript:alert(1)", ' JAVASCRIPT:alert(1)', 'data:text/html,x', 'vbscript:x', '']), [true, true, true, true, false, false, false, false, false]);
check('WpContent: the perex from the WordPress excerpt, the full text', Talea\Core\WpContent::introAndText('Ruční <b>výtah</b> &amp; spol.', "Odstavec jedna\n\nOdstavec dva"), ['<p>Ruční výtah &amp; spol.</p>', "<p>Odstavec jedna</p>\n<p>Odstavec dva</p>"]); // check-english: allow
check('WpContent: without an excerpt the first paragraph is the perex and is not repeated in the text', Talea\Core\WpContent::introAndText('', "[caption]<img src=\"https://stary.example/a.jpg\" alt=\"\"> Popisek[/caption]\n\nOdstavec jedna\n\nOdstavec dva"), ['<p>Odstavec jedna</p>', "<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"><figcaption>Popisek</figcaption></figure>\n<p>Odstavec dva</p>"]); // check-english: allow
check('WpContent: the "Read more" marker splits perex and text', Talea\Core\WpContent::introAndText('', "Před značkou\n<!--more-->\nZa značkou"), ['<p>Před značkou</p>', '<p>Za značkou</p>']); // check-english: allow
check('WpContent: foreign shortcodes warned about in the preview', Talea\Core\WpContent::unknownShortcodes('[gallery ids="1"] [caption]x[/caption] [et_pb_section]a[/et_pb_section] [sic] <code>[muj_klic]</code>'), ['et_pb_section']);
[$wpIntro, $wpText] = Talea\Core\WpContent::introAndText($wpItems[0]['excerpt'], $wpItems[0]['content'], $wpState['attachments']);
check('WpContent: the sample post – perex, image with a caption, video, gallery, no script and shortcode', [str_starts_with($wpIntro, '<p>Po dvanácti měsících'), substr_count($wpText, '<figcaption>'), str_contains($wpText, '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>'), substr_count($wpText, 'class="gallery"'), (bool) preg_match('/script|onclick|kontaktni-formular|javascript/i', $wpText)], [true, 1, true, 1, false]); // check-english: allow

/* ---------- 3.3.2: attribute text never becomes markup – sanitized HTML is changed on the DOM only (N23, N30, N6) ---------- */
// what a browser would run: a script-capable element or an on… attribute anywhere in the parsed HTML
$liveMarkup = static function (string $html): bool {
    $doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
    foreach ($doc->querySelectorAll('*') as $el) {
        if (in_array(strtolower($el->localName), ['svg', 'script', 'iframe', 'object', 'embed', 'math'], true)) {
            return true;
        }
        foreach ($el->attributes as $a) {
            if (str_starts_with(strtolower($a->name), 'on')) {
                return true;
            }
        }
    }

    return false;
};
// the two payloads of the 2026-10-06 audit: an image alt with markup, and text that fooled the website import's class/id strip
$n23Img = '<p>Photo</p><img src="https://old.example/a.jpg" alt="q><svg onload=alert(2)>">';
$n23Ids = '<p>' . str_repeat('Our workshop makes oak tables. ', 4) . ' id="</p><p title="><svg onload=alert(1)>">b</p>';
$n23Clean = Talea\Core\WpContent::sanitize($n23Img);
check('3.3.2 N23: the sanitizers escape < and > inside attribute values', [$n23Clean, Talea\Core\Html::safe('<p title="a<b>c">x</p>'), Talea\Core\Html::safe('<xmp><img src=x onerror=alert(1)></xmp>')],
    ["<p>Photo</p>\n<figure><img src=\"https://old.example/a.jpg\" alt=\"q&gt;&lt;svg onload=alert(2)&gt;\" loading=\"lazy\"></figure>", '<p title="a&lt;b&gt;c">x</p>', '']);
check('3.3.2 N23: even the old regular expressions over sanitized HTML make no markup any more', [
    $liveMarkup((string) preg_replace_callback('#<img\b[^>]*>#i', fn (): string => '<img src="/media/2026/01/a.jpg" alt="">', $n23Clean)),
    $liveMarkup((string) preg_replace('/\s(?:class|id|srcset|sizes)="[^"]*"/i', '', Talea\Core\Html::safe($n23Ids))),
], [false, false]);
$n23Rewritten = Talea\Core\WpImport::rewriteImages($n23Clean, fn (string $src, string $alt): array => Talea\Core\WpImport::mediaImage('', ['image_path' => 'media/2026/01/a.jpg', 'image_width' => 800, 'image_height' => 600, 'media_id' => 7], $alt));
check('3.3.2 N23: importers point images at Media on the DOM, the alt stays text', [$liveMarkup($n23Rewritten), $n23Rewritten, Talea\Core\WpImport::rewriteImages($n23Clean, fn (): ?array => null)],
    [false, "<p>Photo</p>\n<figure><img src=\"/media/2026/01/a.jpg\" alt=\"q&gt;&lt;svg onload=alert(2)&gt;\" width=\"800\" height=\"600\" data-id=\"7\" loading=\"lazy\"></figure>", $n23Clean]);
$n23Page = Talea\Core\WebImport::extract('<html><body><main><h1>Workshop</h1>' . $n23Ids . $n23Img . '</main></body></html>', 'https://old.example/workshop');
check('3.3.2 N23: website import – no markup from the class/id strip, nor when an image download fails', [
    $liveMarkup($n23Page['content']), $liveMarkup(Talea\Core\Html::rewriteImages($n23Page['content'], fn (): bool => false)), str_contains($n23Page['content'], 'Our workshop'),
    Talea\Core\WebImport::safeContent('<picture><source srcset="a.webp" type="image/webp"><img src="https://x.example/a.jpg" srcset="a.jpg 2x" alt="A"></picture><p class="x" id="y">t</p>'),
], [false, false, true, '<img src="https://x.example/a.jpg" alt="A"><p>t</p>']);
check('3.3.2 N23: WordPress images – the old site\'s data-id goes, the Media number of a rewrite stays', [Talea\Core\WpContent::sanitize('<img src="https://old.example/a.jpg" alt="" data-id="99">'), Talea\Core\WpContent::safeHtml('<img src="/media/a.jpg" alt="" data-id="7">')],
    ['<figure><img src="https://old.example/a.jpg" alt="" loading="lazy"></figure>', '<figure><img src="/media/a.jpg" alt="" data-id="7" loading="lazy"></figure>']);
// text stored before 3.3.2 can have a raw > inside an attribute: what the site and the assistant do with it must stay inert
$n23Legacy = '<p><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" title="a>b</a></p><img src=x onerror=alert(1)>">video</a></p><p>Rest</p>';
$n23Parts = Talea\Core\Assistant::decompose('<p title="x>y">Ahoj</p><p title=" onmouseover=alert(1) z">Svete</p>');
check('3.3.2 N23: video embedding and the translation skeleton work on the DOM, older text stays inert', [
    $liveMarkup($types->embedVideoUrls($n23Legacy)), substr_count($types->embedVideoUrls($n23Legacy), 'data-insert'),
    $liveMarkup(Talea\Core\Assistant::compose($n23Parts['skeleton'], $n23Parts['segments'])),
], [false, 1, false]);
check('3.3.2 N30: custom HTML keeps no <select> family, no < in raw text and no raw < or > in attributes', [
    Talea\Builder\Build::code('<select><option>a</option><img src=x onerror=alert(1)></select><datalist><option value="x"></datalist><selectedcontent></selectedcontent>'),
    Talea\Builder\Build::code('<style>p::after{content:"<b>"}</style>'), Talea\Builder\Build::code('<iframe src="https://mapy.cz/x"><img src=x onerror=alert(1)></iframe>'),
    Talea\Builder\Build::code('<p title="</p><img src=x onerror=alert(1)>">x</p>'), Talea\Builder\Build::code('<svg><style><a onclick="x()">y</a></style></svg>'),
], ['', '<style>p::after{content:"\3c b>"}</style>', '<iframe src="https://mapy.cz/x"></iframe>', '<p title="&lt;/p&gt;&lt;img src=x onerror=alert(1)&gt;">x</p>', '<svg><style><a>y</a></style></svg>']);
check('3.3.2 N30: imported pages are converted without administrator rights – no Custom HTML', array_column(Talea\Builder\HtmlConverter::convert('<p>Text</p><video src="https://old.example/v.mp4" controls></video><svg><circle r="1"></circle></svg>', false)['build']['children'][0]['children'] ?? [], 'type'), ['text']);
$n6Html = (new ReflectionMethod(Talea\Core\SiteImport::class, 'html'))->invoke(null, '<p class="lead" onclick="x()">Hi <a href="javascript:alert(1)">x</a></p><script>alert(1)</script><figure class="gallery"><img src="media/2026/01/a.jpg" alt="A" width="10" height="10" loading="lazy" data-id="5"></figure><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>', 100000);
check('3.3.2 N6: Talea export – page and news HTML sanitized like a save without code rights, the content kept', trim($n6Html),
    "<p class=\"lead\">Hi <a>x</a></p><figure class=\"gallery\"><img src=\"media/2026/01/a.jpg\" alt=\"A\" width=\"10\" height=\"10\" loading=\"lazy\" data-id=\"5\"></figure>\n\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>");

/* ---------- import from WordPress: status, date, URLs ---------- */
$wpStatuses = [];
foreach (['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'nesmysl'] as $wpS) {
    $wpM = Talea\Core\WpImport::articleStatus($wpS);
    $wpStatuses[$wpS] = $wpM === null ? 'vynechat' : ($wpM['visible'] ? 'vydany' : 'koncept'); // check-english: allow
}
check('WpImport::articleStatus', $wpStatuses, ['publish' => 'vydany', 'future' => 'vydany', 'draft' => 'koncept', 'pending' => 'koncept', 'private' => 'vynechat', 'trash' => 'vynechat', 'auto-draft' => 'vynechat', 'inherit' => 'vynechat', 'nesmysl' => 'vynechat']); // check-english: allow
check('WpImport::articleStatus: a password-protected post is not published', Talea\Core\WpImport::articleStatus('publish', true), ['visible' => 0]);
check('WpImport::date: local time of the old site', Talea\Core\WpImport::date(['date' => '2026-05-12 09:30:00', 'date_gmt' => '2026-05-12 07:30:00']), '2026-05-12 09:30:00');
check('WpImport::date: a draft with a zero date gets today', Talea\Core\WpImport::date(['date' => '0000-00-00 00:00:00', 'date_gmt' => '0000-00-00 00:00:00', 'pub_date' => ''], 1789000000), date('Y-m-d H:i:s', 1789000000));
$wpTaken = ['lavka', 'lavka-2'];
check('WpImport::freeAddress: a taken address gets a number', Talea\Core\WpImport::availableSlug('lavka', fn (string $a): bool => in_array($a, $wpTaken, true)), 'lavka-3');
check('WpImport::freeAddress: a free one stays', Talea\Core\WpImport::availableSlug('most', fn (string $a): bool => in_array($a, $wpTaken, true)), 'most');
check('WpImport::oldPath', array_map(Talea\Core\WpImport::oldPath(...), ['https://stary.example/2026/05/lavka/', 'https://stary.example/?p=104', 'https://stary.example/blog/p%C5%99%C3%ADklad/', 'https://stary.example/' . str_repeat('x', 300)]), ['2026/05/lavka', '', 'blog/příklad', '']); // check-english: allow
check('WpImport::bezRozmeru', array_map(Talea\Core\WpImport::withoutSize(...), ['https://s.example/u/foto-300x200.jpg', 'https://s.example/u/foto-1024x683.JPG?ver=2', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']), ['https://s.example/u/foto.jpg', 'https://s.example/u/foto.JPG', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']);
check('WpImport::source: the old site\'s domain, at most 40 characters', [Talea\Core\WpImport::source('https://WWW.Stary.example/blog'), Talea\Core\WpImport::source(''), strlen(Talea\Core\WpImport::source('https://' . str_repeat('a', 60) . '.example'))], ['wp:stary.example', 'wp', 40]);
check('SiteExport::path: only export names, nothing outside the folder', [Talea\Core\SiteExport::path('../config.php'), Talea\Core\SiteExport::path('export-20260921-101500.zip/../../config.php'), Talea\Core\SiteExport::path('talea-20260918-130917-rucni-7d777965.sql.gz')], [null, null, null]);

/* ---------- import from WordPress: downloading images only from the old site and only from public URLs (SSRF protection) ---------- */
$wpDownload = new Talea\Core\ImageDownloader('https://www.stary-web.example/blog/');
check('ImageDownloader: the old site\'s domain without www', $wpDownload->domain(), 'stary-web.example');
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
check('ImageDownloader: without the old site\'s address nothing is downloaded', (new Talea\Core\ImageDownloader(''))->isAllowedUrl('https://cokoli.example/a.png'), false);
foreach ([
    '93.184.216.34' => true, '8.8.8.8' => true, '172.32.0.1' => true, '100.128.0.1' => true, '2606:4700:4700::1111' => true, '::ffff:93.184.216.34' => true,
    '10.0.0.5' => false, '172.16.0.1' => false, '172.31.255.255' => false, '192.168.1.1' => false, '127.0.0.1' => false, '127.255.255.254' => false,
    '169.254.169.254' => false, '100.64.0.1' => false, '100.127.255.255' => false, '0.0.0.0' => false, '0.1.2.3' => false, '224.0.0.1' => false, '255.255.255.255' => false,
    '192.0.2.10' => false, '198.18.0.1' => false, '::1' => false, '::' => false, 'fc00::1' => false, 'fd12:3456::1' => false, 'fe80::1' => false, 'ff02::1' => false,
    '::ffff:10.0.0.1' => false, '::ffff:127.0.0.1' => false, '64:ff9b::a00:1' => false, '::10.0.0.1' => false, '2002:a00:1::1' => false, '2001:0:4136:e378:8000:63bf:3fff:fdd2' => false,
    '2001:4860:4860::8888' => true, '[::1]' => false, 'neni-ip' => false, '' => false,
] as $wpIp => $expectedResult) {
    check('StahovaniObrazku::verejnaIp ' . $wpIp, Talea\Core\ImageDownloader::isPublicIp((string) $wpIp), $expectedResult);
}
check('ImageDownloader: an IP address instead of a domain is judged the same', [$wpDownload->verifiedIp('127.0.0.1'), $wpDownload->verifiedIp('[::1]'), $wpDownload->verifiedIp('93.184.216.34')], [null, null, '93.184.216.34']);
check('ImageDownloader: a redirect to another domain does not pass the next check round', $wpDownload->isAllowedUrl(Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'https://utocnik.example/a.png')), false);
check('ImageDownloader: a redirect to //elsewhere and to the internal network does not pass', [$wpDownload->isAllowedUrl(Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', '//utocnik.example/a.png')), $wpDownload->isAllowedUrl(Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'http://169.254.169.254/'))], [false, false]);
check('ImageDownloader: a relative redirect stays on the old site', [Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', '/jinde/b.png'), Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', 'b.png')], ['https://stary-web.example/jinde/b.png', 'https://stary-web.example/u/b.png']);
$wpPng = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$wpSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
check('ImageDownloader::imageType: PNG by header and content', Talea\Core\ImageDownloader::imageType('image/png; charset=binary', $wpPng), 'image/png');
check('ImageDownloader::imageType: the header claims an image, the content is HTML', Talea\Core\ImageDownloader::imageType('image/jpeg', '<html><body>přihlášení</body></html>'), null); // check-english: allow
check('ImageDownloader::imageType: the content is an image, the header is not', Talea\Core\ImageDownloader::imageType('text/html', $wpPng), null);
check('ImageDownloader::imageType: SVG is always rejected', [Talea\Core\ImageDownloader::imageType('image/svg+xml', $wpSvg), Talea\Core\ImageDownloader::imageType('image/png', $wpSvg)], [null, null]);
check('ImageDownloader::imageType: an empty response', Talea\Core\ImageDownloader::imageType('image/png', ''), null);
check('ImageDownloader: limits as specified (15 MB, 3 redirects, 5 s connection, 20 s total)', [Talea\Core\ImageDownloader::MAX_BYTES, Talea\Core\ImageDownloader::MAX_REDIRECTS, Talea\Core\ImageDownloader::CONNECT_TIMEOUT, Talea\Core\ImageDownloader::TOTAL_TIMEOUT], [15 * 1024 * 1024, 3, 5, 20]);
$wpSourceHtml = (string) file_get_contents(TALEA_ROOT . '/system/src/Core/ImageDownloader.php');
check('ImageDownloader: redirects are never followed automatically and nothing extra is sent', [substr_count($wpSourceHtml, 'CURLOPT_FOLLOWLOCATION => false'), str_contains($wpSourceHtml, "'follow_location' => 0"), (bool) preg_match('/CURLOPT_(COOKIE\w*|USERPWD|HTTPHEADER|HTTPAUTH)\b/', $wpSourceHtml), str_contains($wpSourceHtml, "'Talea-import'")], [1, true, false, true]);

/* ---------- builder: validator, style, design system, library ---------- */
[$buildS, $buildErrors] = Talea\Builder\Build::sanitize(['children' => [
    ['type' => 'heading', 'id' => 'abc', 'content' => ['text' => '<script>x</script>Ahoj <b>světe</b>']], // check-english: allow
    ['type' => 'neznamy'],
    ['type' => 'custom_html', 'content' => ['code' => '<p>a</p>']],
    ['type' => 'button', 'content' => ['link' => 'javascript:alert(1)']],
    ['type' => 'heading', 'id' => 'abc', 'children' => [['type' => 'text']]],
]], false);
check('Build::sanitize: a script in a heading gone, bold stays', $buildS['children'][0]['content']['text'], 'Ahoj <b>světe</b>'); // check-english: allow
check('Build::sanitize: an unknown type, foreign HTML and nested heading children drop out', array_map(fn (array $p): string => $p['type'], $buildS['children']), ['heading', 'button', 'heading']);
check('Build::sanitize: a javascript: link is dropped and reported', [$buildS['children'][1]['content']['link'], isset($buildErrors['children[3].content.link'])], ['', true]);
check('Build::sanitize: a duplicate id gets a new one', $buildS['children'][2]['id'] !== 'abc', true);
check('Build::sanitize: errors have a path', array_keys($buildErrors), ['children[1]', 'children[2]', 'children[3].content.link', 'children[4].children']);
$buildHtml = ['children' => [['type' => 'custom_html', 'id' => 'h1x', 'content' => ['code' => '<p onclick="x()">a</p><script>1</script><a href="javascript:x">b</a>']]]];
[$buildAdmin] = Talea\Builder\Build::sanitize($buildHtml, true);
check('Build::sanitize: the administrator\'s custom HTML without scripts and handlers', $buildAdmin['children'][0]['content']['code'], '<p>a</p><a>b</a>');
[$buildEditor] = Talea\Builder\Build::sanitize(['children' => [['type' => 'custom_html', 'id' => 'h1x', 'content' => ['code' => '<p>podvrh</p>']]]], false, $buildAdmin);
check('Build::sanitize: the editor does not change the administrator\'s custom HTML, only keeps it', $buildEditor['children'][0]['content']['code'], '<p>a</p><a>b</a>');
$buildDeep = ['type' => 'text'];
for ($i = 0; $i < 20; $i++) {
    $buildDeep = ['type' => 'container', 'children' => [$buildDeep]];
}
[, $buildErrors] = Talea\Builder\Build::sanitize(['children' => [$buildDeep]]);
check('Build::sanitize: depth is limited', count($buildErrors), 1);
[$buildMany, $buildErrors] = Talea\Builder\Build::sanitize(['children' => array_fill(0, 900, ['type' => 'divider'])]);
check('Build::sanitize: the number of elements is limited', [count($buildMany['children']), count($buildErrors)], [Talea\Builder\Build::MAX_ELEMENTS, 1]);
check('Build::sanitize: an image only from Media or https', Talea\Builder\Build::sanitize(['children' => [['type' => 'image', 'content' => ['src' => 'http://x.cz/a.jpg']], ['type' => 'image', 'content' => ['src' => 'media/2026/a.jpg']]]])[0]['children'][1]['content']['src'], 'media/2026/a.jpg');

/* ---------- 2.7: display conditions – language versions and a URL parameter ---------- */
$conditionErrors = [];
check('2.7: conditions – languages and a URL parameter are kept, unknown and unsafe values dropped with a message', [
    Talea\Builder\Build::sanitizeConditions(['signed_in' => 'no', 'languages' => ['', 'de', 'de', 'xx', 7], 'url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']], 'p', $conditionErrors),
    Talea\Builder\Build::sanitizeConditions(['url_parameter' => 'variant'], 'p', $conditionErrors),
    Talea\Builder\Build::sanitizeConditions(['url_parameter' => ['name' => 'a b', 'value' => 'x']], 'p2', $conditionErrors),
    Talea\Builder\Build::sanitizeConditions(['url_parameter' => ['name' => 'v', 'value' => 'x"y']], 'p3', $conditionErrors),
    Talea\Builder\Build::sanitizeConditions(['url_parameter' => ['name' => '', 'value' => '']], 'p4', $conditionErrors),
    Talea\Builder\Build::sanitizeConditions(['jazyky' => 'de'], 'p5', $conditionErrors),
    array_keys($conditionErrors),
], [
    ['signed_in' => 'no', 'languages' => ['', 'de'], 'url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']],
    ['url_parameter' => ['name' => 'variant']], [], [], [], [],
    ['p', 'p2', 'p3'],
]);
check('2.7: old builds without the new conditions sanitize as before', Talea\Builder\Build::sanitize(['children' => [['type' => 'heading', 'id' => 'c1', 'conditions' => ['signed_in' => 'yes', 'from' => '2026-01-01']]]])[0]['children'][0]['conditions'], ['signed_in' => 'yes', 'from' => '2026-01-01']);
$conditionApp = new Talea\Core\App([], new Talea\Core\Request(['utm_campaign' => 'jaro', 'variant' => ''], [], []));
$conditionContext = new Talea\Builder\Context($conditionApp);
$conditionApp->languagePrefix = 'de';
check('2.7: meetsConditions – language version of the visit', [
    Talea\Builder\Build::meetsConditions(['languages' => ['de']], $conditionContext), Talea\Builder\Build::meetsConditions(['languages' => ['', 'en']], $conditionContext),
    Talea\Builder\Build::meetsConditions(['languages' => ['']], (function () use ($conditionApp) { $conditionApp->languagePrefix = ''; return new Talea\Builder\Context($conditionApp); })()),
], [true, false, true]);
check('2.7: meetsConditions – URL parameter present, with and without an exact value', [
    Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign']], $conditionContext),
    Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign', 'value' => 'jaro']], $conditionContext),
    Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign', 'value' => 'leto']], $conditionContext),
    Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'variant']], $conditionContext), // ?variant without a value counts as present
    Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'missing']], $conditionContext),
    Talea\Builder\Build::meetsConditions(['url_parameter' => ['name' => 'utm_campaign'], 'languages' => ['de'], 'from' => '2000-01-01'], $conditionContext), // all must hold
], [true, true, false, true, false, false]);
check('2.7: only a language condition keeps the page in the cache (language versions have their own addresses)', [
    Talea\Builder\Build::conditionsBypassCache(['languages' => ['de']]), Talea\Builder\Build::conditionsBypassCache(['url_parameter' => ['name' => 'utm_campaign']]),
    Talea\Builder\Build::conditionsBypassCache(['languages' => [''], 'from' => '2026-01-01']), Talea\Builder\Build::conditionsBypassCache(['signed_in' => 'no']),
], [false, true, true, true]);

/* ---------- 2.7: copy and paste between Talea sites (Builder\ElementClipboard) ---------- */
$clipboardElements = [['id' => 'abc1234', 'type' => 'section', 'anchor' => 'cenik', 'classes' => ['karta'], 'children' => [
    ['id' => 'def5678', 'type' => 'image', 'content' => ['src' => 'media/2026/foto.jpg', 'alt' => 'x']],
    ['id' => 'ghi9012', 'type' => 'text', 'content' => ['html' => '<p><img src="/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>'], 'style' => ['base' => ['background_image' => 'media/bg.webp']]],
    ['id' => 'jkl3456', 'type' => 'component', 'content' => ['component' => '7', 'values' => []]],
]]];
$clipboardEnvelope = ['talea' => 'elements', 'v' => 1, 'site' => 'https://Zdroj.example/', 'elements' => $clipboardElements, 'classes' => [['name' => 'karta', 'style' => [], 'css' => '']], 'components' => [['id' => 7, 'name' => 'K', 'properties' => [], 'build' => ['v' => 1, 'children' => []]]]];
$clipboardParsed = Talea\Builder\ElementClipboard::parse($clipboardEnvelope);
check('2.7: clipboard envelope – checked and normalised', [$clipboardParsed['site'], count($clipboardParsed['elements']), count($clipboardParsed['classes']), count($clipboardParsed['components'])], ['https://zdroj.example', 1, 1, 1]);
check('2.7: clipboard envelope – anything else is refused', [
    Talea\Builder\ElementClipboard::parse('text'), Talea\Builder\ElementClipboard::parse(['talea' => 'page', 'v' => 1, 'elements' => $clipboardElements]),
    Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 2, 'elements' => $clipboardElements]), Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 1, 'elements' => []]),
    Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 1, 'elements' => ['x', 1]]), Talea\Builder\ElementClipboard::parse(['talea' => 'elements', 'v' => 1, 'elements' => $clipboardElements, 'site' => 'javascript:x'])['site'],
], [null, null, null, null, null, '']);
$clipboardFresh = Talea\Builder\ElementClipboard::fresh($clipboardElements);
check('2.7: pasted elements lose their ids and anchors (new ids come from sanitize)', [isset($clipboardFresh[0]['id']), isset($clipboardFresh[0]['anchor']), isset($clipboardFresh[0]['children'][1]['id']), $clipboardFresh[0]['children'][1]['type']], [false, false, false, 'text']);
$clipboardImages = 0;
$clipboardHttps = Talea\Builder\ElementClipboard::relinkMedia($clipboardElements, 'https://zdroj.example', $clipboardImages);
check('2.7: media of an https site are pointed at it and counted', [$clipboardImages, $clipboardHttps[0]['children'][0]['content']['src'], $clipboardHttps[0]['children'][1]['content']['html'], $clipboardHttps[0]['children'][1]['style']['base']['background_image']],
    [3, 'https://zdroj.example/media/2026/foto.jpg', '<p><img src="https://zdroj.example/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', 'https://zdroj.example/media/bg.webp']);
$clipboardImages = 0;
$clipboardHttp = Talea\Builder\ElementClipboard::relinkMedia($clipboardElements, 'http://zdroj.example', $clipboardImages);
check('2.7: media of an http site are left out and counted', [$clipboardImages, $clipboardHttp[0]['children'][0]['content']['src'], $clipboardHttp[0]['children'][1]['content']['html'], $clipboardHttp[0]['children'][1]['style']['base']['background_image']],
    [3, '', '<p><img src="" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', '']);
check('2.7: relinked media pass the build validator', Talea\Builder\Build::sanitize(['children' => $clipboardHttps])[0]['children'][0]['children'][0]['content']['src'], 'https://zdroj.example/media/2026/foto.jpg');
check('Build::fromText: h1 heading and text', array_map(fn (array $p): string => $p['tag'], Talea\Builder\Build::fromText('O nás', '<p>x</p>')['children'][0]['children']), ['h1', 'div']); // check-english: allow
$buildStyleErrors = [];
check('Style::sanitize: pasted CSS, an unknown property and state drop out', Talea\Builder\Style::sanitize(['base' => ['color' => 'red;}body{x:y', 'neznama' => '1', 'width' => '50%'], 'tisk' => []], 's', $buildStyleErrors), ['base' => ['width' => '50%']]);
check('Styl::vycisti: chyby', array_keys($buildStyleErrors), ['s.base.color', 's.base.neznama', 's.tisk']);
check('Styl::css: tokeny, sloupce, hover a breakpoint', Talea\Builder\Style::css('#s-a', ['base' => ['padding_y' => 'xl', 'color' => 'primary', 'columns' => '3'], 'mobile' => ['columns' => '1'], 'hover' => ['color' => '#ff0000']]),
    "#s-a { padding-block: var(--tl-space-xl); color: var(--tl-color-primary); grid-template-columns: repeat(3, minmax(0, 1fr)); }\n#s-a:is(:hover, :focus-visible) { color: #ff0000; }\n@media (max-width: 767px) { #s-a { grid-template-columns: repeat(1, minmax(0, 1fr)); } }\n");
check('Html::safe: without scripts, event handlers and javascript:, with structure and classes', Talea\Core\Html::safe('<p class="x" onclick="a()">A <a href="javascript:alert(1)">b</a><img src="x" onerror="alert(1)"><script>alert(1)</script></p><iframe src="https://x"></iframe><a href="/k" target="_blank" data-insert="javascript:x">k</a>'),
    '<p class="x">A <a>b</a><img src="x"></p><a href="/k" target="_blank" rel="noopener">k</a>');
check('Build: site script hooks cannot be inserted as a custom attribute', [preg_match(Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-insert'), preg_match(Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-self'), preg_match(Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-track')], [0, 0, 1]);
check('Style::css: hover and press separately for tablet and mobile', Talea\Builder\Style::css('#x', ['mobile' => ['gap' => 's'], 'hover_mobile' => ['color' => 'primary'], 'active_tablet' => ['scale' => '0.95']]),
    "@media (max-width: 1023px) { #x:active { scale: 0.95; } }\n@media (max-width: 767px) { #x { gap: var(--tl-space-s); } #x:is(:hover, :focus-visible) { color: var(--tl-color-primary); } }\n");
check('Style::css: the typography style first, single properties refine it', Talea\Builder\Style::css('#x', ['base' => ['font_size' => '3', 'text_style' => 'eyebrow']]),
    "#x { font: var(--tl-type-eyebrow); text-transform: uppercase; letter-spacing: 0.08em; font-size: var(--tl-step-3); }\n");
check('Style: a custom shadow and border with colour tokens', [Talea\Builder\Style::value('shadow', '0 8px 24px 0 primary'), Talea\Builder\Style::value('shadow', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)'), Talea\Builder\Style::value('border', '2px dashed primary')],
    ['0 8px 24px 0 var(--tl-color-primary)', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)', '2px dashed var(--tl-color-primary)']);
check('Style: shadow and border let nothing dangerous through', [Talea\Builder\Style::value('shadow', '0 0 1px url(x)'), Talea\Builder\Style::value('shadow', '0 0 red; color: red'), Talea\Builder\Style::value('border', '1px solid red}')], [null, null, null]);
check('Style: grid – rows, areas and the element\'s area', [Talea\Builder\Style::value('rows', '3'), Talea\Builder\Style::value('areas', 'hlava hlava / bok obsah'), Talea\Builder\Style::value('areas', 'a b / c'), Talea\Builder\Style::value('area', 'bok'), Talea\Builder\Style::value('area', 'x"y')], // check-english: allow
    ['repeat(3, auto)', '"hlava hlava" "bok obsah"', null, 'bok', null]); // check-english: allow
check('DesignSystem: typography styles as tokens, edited in Appearance', [str_contains(Talea\Builder\DesignSystem::css(Talea\Builder\DesignSystem::sanitize([])), '--tl-type-lead: 400 var(--tl-step-1)/1.55 var(--tl-font-body);'),
    str_contains(Talea\Builder\DesignSystem::css(Talea\Builder\DesignSystem::sanitize(['typography' => ['lead' => ['step' => '2', 'weight' => '500'], 'title' => ['step' => '99']]])), '--tl-type-lead: 500 var(--tl-step-2)/1.55'),
    Talea\Builder\DesignSystem::sanitize(['typography' => ['title' => ['step' => '99']]])['typography']], [true, true, []]);
check('Style::css: a background image from Media from the installation root', str_contains(Talea\Builder\Style::css('#s', ['base' => ['background_image' => 'media/2026/09/a.jpg']], '', '/web'), 'url("/web/media/2026/09/a.jpg")'), true);
$takenSlugs = ['o-nas' => 1, 'o-nas-2' => 1, str_repeat('a', 10) => 1];
check('Free address: the number after a taken one, with a number it fits the column', [
    Talea\Core\Slug::makeUnique('o-nas', fn (string $a): bool => isset($takenSlugs[$a])),
    Talea\Core\Slug::makeUnique('sluzby', fn (string $a): bool => isset($takenSlugs[$a])),
    Talea\Core\Slug::makeUnique(str_repeat('a', 12), fn (string $a): bool => isset($takenSlugs[$a]), 10),
], ['o-nas-3', 'sluzby', 'aaaaaaaa-2']); // check-english: allow
check('Container as a link: links inside become a span', Talea\Builder\Elements\Container::render(['tag' => 'div', 'content' => ['link' => '/k']], '', '<p>x</p><a class="tl-button" href="/y" target="_blank">B</a><abbr>z</abbr>', new Talea\Builder\Context((new ReflectionClass(Talea\Core\App::class))->newInstanceWithoutConstructor())),
    '<a class="tl-card-link" href="/k"><p>x</p><span class="tl-button">B</span><abbr>z</abbr></a>');
check('Menu::sanitize: an unknown type, a dangerous address and the third level drop out', Talea\Core\Menu::sanitize([
    ['type' => 'skript'], ['type' => 'link', 'text' => 'X', 'url' => 'javascript:alert(1)'],
    ['type' => 'group', 'text' => 'Služby', 'children' => [['type' => 'page', 'page_id' => 3, 'children' => [['type' => 'news_list']]], ['type' => 'link', 'text' => 'Ceník', 'url' => '/cenik', 'new_window' => 1]]], // check-english: allow
]), [['type' => 'group', 'text' => 'Služby', 'children' => [['type' => 'page', 'text' => '', 'page_id' => 3], ['type' => 'link', 'text' => 'Ceník', 'url' => '/cenik', 'new_window' => true]]]]); // check-english: allow
check('Menu::html: submenu, active item and branch, home only by exact match', Talea\Core\Menu::html([
    ['text' => 'Úvod', 'url' => '/', 'new_window' => false, 'children' => []], // check-english: allow
    ['text' => 'Služby', 'url' => '', 'new_window' => false, 'children' => [['text' => 'Kuchyně', 'url' => '/kuchyne', 'new_window' => false, 'children' => []]]], // check-english: allow
], '/kuchyne/detail', '/'), '<li><a href="/">Úvod</a></li><li class="submenu active"><button type="button" class="menu-group">Služby</button><ul><li><a href="/kuchyne" aria-current="page">Kuchyně</a></li></ul></li>'); // check-english: allow
// 2.7: icons and descriptions of menu items, a group inside a submenu with its own items (a column of the mega menu)
check('Menu::sanitize: icon only from the set, description without tags up to 120 characters, a group in a submenu may have items, a page in a submenu may not', Talea\Core\Menu::sanitize([
    ['type' => 'link', 'text' => 'Kontakt', 'url' => '/kontakt', 'icon' => 'phone', 'description' => ' <b>Zavolejte</b> nám ' . str_repeat('x', 130)], // check-english: allow
    ['type' => 'news', 'icon' => 'neexistuje', 'description' => ['pole']],
    ['type' => 'group', 'text' => 'Služby', 'children' => [ // check-english: allow
        ['type' => 'group', 'text' => 'Kuchyně', 'icon' => 'home', 'children' => [['type' => 'page', 'page_id' => 3, 'children' => [['type' => 'news_list']]]]], // check-english: allow
        ['type' => 'page', 'page_id' => 4, 'children' => [['type' => 'news_list']]],
    ]],
]), [
    ['type' => 'link', 'text' => 'Kontakt', 'icon' => 'phone', 'description' => 'Zavolejte nám ' . str_repeat('x', 106), 'url' => '/kontakt', 'new_window' => false], // check-english: allow
    ['type' => 'news', 'text' => ''],
    ['type' => 'group', 'text' => 'Služby', 'children' => [ // check-english: allow
        ['type' => 'group', 'text' => 'Kuchyně', 'icon' => 'home', 'children' => [['type' => 'page', 'text' => '', 'page_id' => 3]]], // check-english: allow
        ['type' => 'page', 'text' => '', 'page_id' => 4],
    ]],
]);
$menuWithColumns = [
    ['text' => 'Kontakt', 'url' => '/kontakt', 'new_window' => false, 'children' => [], 'icon' => 'phone', 'description' => 'Nahoře se popis neukáže'], // check-english: allow
    ['text' => 'Služby', 'url' => '', 'new_window' => false, 'children' => [ // check-english: allow
        ['text' => 'Kuchyně', 'url' => '', 'new_window' => false, 'children' => [['text' => 'Na míru', 'url' => '/na-miru', 'new_window' => false, 'children' => [], 'description' => 'Podle vašich <rozměrů>']], 'icon' => 'home'], // check-english: allow
        ['text' => 'Ceník', 'url' => '/cenik', 'new_window' => false, 'children' => [], 'description' => 'Orientační ceny'], // check-english: allow
    ]],
];
$menuSvg = fn (string $key): string => Talea\Builder\Icons::svg($key, 'menu-icon');
check('Menu::html: icon before the text, a group in a submenu as a column with a heading, description only in the mega menu under submenu items', Talea\Core\Menu::html($menuWithColumns, '/na-miru', '/', true),
    '<li><a href="/kontakt">' . $menuSvg('phone') . 'Kontakt</a></li><li class="submenu active"><button type="button" class="menu-group">Služby</button><ul>' // check-english: allow
    . '<li class="menu-column"><span class="menu-heading">' . $menuSvg('home') . 'Kuchyně</span><ul><li><a href="/na-miru" aria-current="page">Na míru<small class="menu-description">Podle vašich &lt;rozměrů&gt;</small></a></li></ul></li>' // check-english: allow
    . '<li><a href="/cenik">Ceník<small class="menu-description">Orientační ceny</small></a></li></ul></li>'); // check-english: allow
check('Menu::html: without a mega menu the column stays, descriptions are not printed', [str_contains(Talea\Core\Menu::html($menuWithColumns, '/', '/'), 'menu-description'), str_contains(Talea\Core\Menu::html($menuWithColumns, '/', '/'), '<li class="menu-column"><span class="menu-heading">')], [false, true]);
check('Menu::flatten: all levels in order', array_column(Talea\Core\Menu::flatten($menuWithColumns), 'text'), ['Kontakt', 'Služby', 'Kuchyně', 'Na míru', 'Ceník']); // check-english: allow
$navMegaCss = Talea\Builder\Elements\Navigation::baseCss();
check('Navigation: icon, column and description style of the menu; the description hides on a phone', [str_contains($navMegaCss, '.tl-nav .menu-icon {'), str_contains($navMegaCss, '.tl-nav .menu-column > ul {'), str_contains($navMegaCss, '.tl-nav .menu-heading {'),
    (bool) preg_match('/@media \(max-width: 767px\).*?\.tl-nav-menu\[popover\] \.menu-description \{ display: none; \}/s', $navMegaCss)], [true, true, true, true]);
// 2.7: the header that is transparent at the top (and/or smaller after scrolling) – only in the header site part, CSS only when used
$headerApp = new Talea\Core\App([]);
$headerBuild = ['v' => 1, 'children' => [['id' => 'hl1', 'type' => 'section', 'tag' => 'header', 'content' => ['on_scroll' => 'transparent_shrink', 'text_at_top' => 'light'], 'style' => ['base' => ['position' => 'sticky', 'background' => 'background']], 'children' => []]]];
[$headerBuild] = Talea\Builder\Build::sanitize($headerBuild);
$headerContext = new Talea\Builder\Context($headerApp);
$headerContext->source = 'part:header:';
$headerHtml = Talea\Builder\Build::html($headerBuild, $headerContext);
$headerCss = Talea\Builder\Build::css((new ReflectionClass(Talea\Core\Db::class))->newInstanceWithoutConstructor(), $headerContext);
check('Sections on scroll: header classes, fixed position after the style (sticky), scroll animation, light text on top', [
    (bool) preg_match('/<header id="s-hl1" class="tl-header-scroll tl-header-scroll--transparent">/', $headerHtml),
    (bool) preg_match('/#s-hl1 \{ [^}]*position: sticky;[^}]*background-color: var\(--tl-color-background\); position: fixed; top: 0; inset-inline: 0; animation: tl-header-light linear both, tl-header-smaller linear both; animation-timeline: scroll\(root\); animation-range: 0 120px; \}/', $headerContext->css),
    str_contains($headerCss, '@keyframes tl-header-light'), str_contains($headerCss, '@keyframes tl-header-smaller { to { padding-block:'), str_contains($headerCss, 'prefers-reduced-motion: reduce) { .tl-header-scroll { animation: none !important; } }'),
], [true, true, true, true, true]);
$pageContext = new Talea\Builder\Context($headerApp);
$pageContext->source = 'page:5';
check('Sections on scroll: outside the header nothing shows and no CSS is printed', [str_contains(Talea\Builder\Build::html($headerBuild, $pageContext), 'tl-header'), str_contains($pageContext->css, 'animation'),
    str_contains(Talea\Builder\Build::css((new ReflectionClass(Talea\Core\Db::class))->newInstanceWithoutConstructor(), $pageContext), 'tl-header')], [false, false, false]);
$shrinkOnly = ['v' => 1, 'children' => [['id' => 'hl2', 'type' => 'section', 'content' => ['on_scroll' => 'shrink'], 'children' => []]]];
$shrinkContext = new Talea\Builder\Context($headerApp);
$shrinkContext->source = 'part:header:kampan';
check('Sections on scroll: only shrinking keeps the header in the flow (no position: fixed), an element without style still gets an id and a rule', [
    str_contains(Talea\Builder\Build::html(Talea\Builder\Build::sanitize($shrinkOnly)[0], $shrinkContext), '<section id="s-hl2" class="tl-header-scroll">'),
    str_contains($shrinkContext->css, 'position: fixed'), str_contains($shrinkContext->css, '#s-hl2 { animation: tl-header-smaller linear both;'),
], [true, false, true]);
check('Search::find: a match in the title comes first', array_column(Talea\Core\Search::find('search', [
    ['title' => 'Menus', 'url' => 'menus', 'text' => 'Link to site search from the menu.'],
    ['title' => 'Site search', 'url' => 'site-search', 'text' => 'How search works.'],
    ['title' => 'SEO', 'url' => 'seo', 'text' => 'Search engines and search results; search console.'],
]), 'url'), ['site-search', 'seo', 'menus']);
check('Search::find: without diacritics, all words, excerpt', Talea\Core\Search::find('zkusenosti kuchyne', [
    ['title' => 'O nás', 'url' => 'o-nas', 'text' => '<p>Máme dvacet let zkušeností s nábytkem.</p>'], // check-english: allow
    ['title' => 'Kuchyně', 'url' => 'kuchyne', 'text' => '<p>Kuchyně na míru – bohaté zkušenosti.</p>'], // check-english: allow
]), [['title' => 'Kuchyně', 'url' => 'kuchyne', 'snippet' => 'Kuchyně na míru – bohaté zkušenosti.']]); // check-english: allow
check('Style::css: a white background carries dark text in dark mode too', str_contains(Talea\Builder\Style::css('#s', ['base' => ['background' => 'white']]), '--tl-color-text: var(--tl-color-text-light); color: var(--tl-color-text-light)'), true);
check('Style::css: a custom text colour on white is not overridden', str_contains(Talea\Builder\Style::css('#s', ['base' => ['background' => 'white', 'color' => 'primary']]), 'text-svetle'), false);
$buildDiscarded = [];
check('Style::customCss: only safe declarations', Talea\Builder\Style::customCss('color:red; background:url(javascript:x); --tl-x: 1; @import url(x); width: expression(1); a{b:c}', $buildDiscarded), 'color: red; --tl-x: 1;');
check('Style::customCss: dropped ones are reported', count($buildDiscarded), 4);
check('DesignSystem::contrast: black on white', round(Talea\Builder\DesignSystem::contrast('#ffffff', '#000000'), 1), 21.0);
// 2.1: the design system tokens are English custom properties – color, font, width, text-width, radius, step, space, shadow, type
$dsCss = Talea\Builder\DesignSystem::css(Talea\Builder\DesignSystem::DEFAULTS);
preg_match_all('/^\t(--tl-[a-z0-9-]+):/m', substr($dsCss, 0, (int) strpos($dsCss, '@media')), $dsStored);
check('2.1: the design system tokens are English', [array_values(array_filter($dsStored[1], fn (string $name): bool => !preg_match('/^--tl-(color|font|radius|step|space|shadow|type)-|^--tl-(width|text-width|radius|accent)$/', $name))),
    str_contains($dsCss, '--tl-color-primary: #2b5be3;'), str_contains($dsCss, '@layer tokens {'), str_contains($dsCss, '--tl-type-lead: 400 var(--tl-step-1)/1.55 var(--tl-font-body);')], [[], true, true, true]);
// 2.1: a page without its own description gets the start of its first longer paragraph
check('2.1: description from the first longer paragraph', [Talea\Front\Kernel::descriptionFrom('<h1>Hi</h1><p>Short.</p><p class="x">We build <strong>kitchens</strong> &amp; bathrooms in Zlín   and around it since 1998.</p><p>Later text that is long enough to be picked but comes second.</p>'), // check-english: allow
    Talea\Front\Kernel::descriptionFrom('<p>tiny</p>'), mb_strlen(Talea\Front\Kernel::descriptionFrom('<p>' . str_repeat('word ', 80) . '</p>'))],
    ['We build kitchens & bathrooms in Zlín and around it since 1998.', '', 160]); // check-english: allow
// 2.1: security.txt (RFC 9116) from the Security contact setting
check('2.1: security.txt', [Talea\Front\Seo::securityTxt('security@example.com', 'https://example.com/', ['en', 'de'], 1790000000),
    Talea\Front\Seo::securityTxt('https://example.com/security', 'https://example.com/web', [], 1790000000)],
    ["Contact: mailto:security@example.com\nExpires: 2027-03-23T00:00:00Z\nPreferred-Languages: en, de\nCanonical: https://example.com/.well-known/security.txt\n",
    "Contact: https://example.com/security\nExpires: 2027-03-23T00:00:00Z\nCanonical: https://example.com/web/.well-known/security.txt\n"]);
check('DesignSystem::css: layer order at the start', str_starts_with(Talea\Builder\DesignSystem::css(Talea\Builder\DesignSystem::DEFAULTS), Talea\Builder\DesignSystem::LAYERS), true);
check('DesignSystem::sanitize: nonsense is replaced by the default', Talea\Builder\DesignSystem::sanitize(['colors' => ['primary' => 'red;}']])['colors']['primary'], Talea\Builder\DesignSystem::DEFAULTS['colors']['primary']);
$buildLibraryErrors = [];
// raw builds (section() already cleans them, so an invalid value would disappear silently)
foreach ((new ReflectionMethod(Talea\Builder\Library::class, 'sections'))->invoke(null) as $buildKey => $buildSection) {
    $buildSection['key'] = $buildKey;
    [, $buildErrors] = Talea\Builder\Build::sanitize(['children' => [($buildSection['build'])()]]);
    $buildLibraryErrors += array_map(fn (string $c): string => $buildSection['key'] . ': ' . $c, $buildErrors);
}
check('Library: all ready-made sections pass the validator', $buildLibraryErrors, []);
check('Build::schema: without custom HTML for non-administrators', in_array('html', array_column(Talea\Builder\Build::schema(false)['elements'], 'type'), true), false);

$fromHtml = Talea\Builder\HtmlConverter::convert('<style>.hero { padding: 2rem; background: url(x) } .hero h1 { color: red } @media (max-width: 9px) { .hero { padding: 0 } }</style>'
    . '<header class="hero container-x"><div class="wrap"><h1>A <em>b</em></h1><p>Jedna.</p><p>Dvě.</p><a class="btn btn-outline" href="/k">K</a></div></header>' // check-english: allow
    . '<p>Volný text</p><details><summary>Otázka?</summary><p>Odpověď.</p></details><form></form><svg></svg><script>x</script>'); // check-english: allow
$fromHtmlTypes = fn (array $children): array => array_map(fn (array $p): string => $p['type'] . '<' . $p['tag'] . '>', $children);
check('HtmlConverter: a section from <header>, an inner wrapper without style drops', $fromHtmlTypes($fromHtml['build']['children'][0]['children']), ['heading<h1>', 'text<div>', 'button<a>']);
check('HtmlConverter: consecutive paragraphs in one Text element', $fromHtml['build']['children'][0]['children'][1]['content']['html'], '<p>Jedna.</p><p>Dvě.</p>'); // check-english: allow
check('HtmlConverter: a button with a variant by class', [$fromHtml['build']['children'][0]['children'][2]['content']['variant'], $fromHtml['build']['children'][0]['children'][2]['classes']], ['outline', ['btn', 'btn-outline']]);
check('HtmlConverter: loose elements at the end are wrapped in a section, details → FAQ, form → Form', $fromHtmlTypes($fromHtml['build']['children'][1]['children']), ['text<div>', 'faq<div>', 'form<form>']);
check('HtmlConverter: a class from <style> only with safe declarations', $fromHtml['classes'], ['hero' => 'padding: 2rem;']);
check('HtmlConverter: reports about @media, a complex selector, url(), a form, SVG and a script', count($fromHtml['notes']), 6);
$fromHtml2 = Talea\Builder\HtmlConverter::convert('<style>.mriz { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--tl-space-l) } .karta:hover { box-shadow: var(--tl-shadow-m); transform: translateY(-4px) }'
    . ' @media (max-width: 1023px) { .mriz { grid-template-columns: repeat(2, 1fr) } } @media (max-width: 767px) { .mriz { grid-template-columns: 1fr; gap: var(--tl-space-m) } .karta { padding: var(--tl-space-m) var(--tl-space-s) } }'
    . ' @media (min-width: 768px) { .mriz { gap: 0 } }</style><section><div class="mriz"><div class="card"><h3>A</h3></div><div>B</div></div></section>');
check('HtmlConverter: @media (max-width) and :hover as class states', $fromHtml2['class_styles'], ['mriz' => ['tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1', 'gap' => 'm']],
    'karta' => ['mobile' => ['padding_y' => 'm', 'padding_x' => 's'], 'hover' => ['shadow' => 'm', 'translate' => '0 -4px']]]);
check('HtmlConverter: an element with a styled class has no default style (it would override the class), without a class it has', [$fromHtml2['build']['children'][0]['children'][0]['style'], $fromHtml2['build']['children'][0]['children'][0]['children'][1]['style']['base']['display'] ?? null], [[], 'flex']);
check('HtmlConverter: mobile-first @media (min-width) is reported', count(array_filter($fromHtml2['notes'], fn (string $h): bool => str_contains($h, 'min-width'))), 1);
check('Style::fromCss: tokens, shorthands and grid', [Talea\Builder\Style::fromCss('padding', 'var(--tl-space-l) 2rem'), Talea\Builder\Style::fromCss('margin', '0 auto'), Talea\Builder\Style::fromCss('grid-template-columns', 'repeat(auto-fit, minmax(16rem, 1fr))'),
    Talea\Builder\Style::fromCss('color', 'var(--tl-color-muted)'), Talea\Builder\Style::fromCss('font-size', 'var(--tl-step--1)'), Talea\Builder\Style::fromCss('color', 'expression(1)'), Talea\Builder\Style::fromCss('filter', 'blur(2px)')],
    [['padding_y' => 'l', 'padding_x' => '2rem'], ['margin_top' => '0', 'margin_bottom' => '0', 'center' => 'auto'], ['columns' => 'auto:16rem'], ['color' => 'muted'], ['font_size' => '-1'], null, null]);

$editBuild = ['v' => 1, 'children' => [['id' => 'sek1', 'type' => 'section', 'children' => [['id' => 'nad1', 'type' => 'heading', 'content' => ['text' => 'A'], 'style' => ['base' => ['color' => 'primary']]], ['id' => 'tl1', 'type' => 'button', 'content' => ['text' => 'B', 'link' => '/docs']]]], ['id' => 'sek2', 'type' => 'section']]];
$editErrors = [];
$edit = Talea\Builder\Edits::apply($editBuild, [
    ['op' => 'update', 'id' => 'tl1', 'content' => ['link' => '/guide']],
    ['op' => 'update', 'id' => 'nad1', 'style' => ['base' => ['color' => null], 'mobile' => ['text_align' => 'center']], 'classes' => ['nadpis-sekce']],
    ['op' => 'insert', 'into' => 'sek2', 'elements' => [['id' => 'txt1', 'type' => 'text']]],
    ['op' => 'move', 'id' => 'tl1', 'after' => 'txt1'],
    ['op' => 'insert', 'position' => 0, 'element' => ['id' => 'sek0', 'type' => 'section']],
    ['op' => 'delete', 'id' => 'neni'],
    ['op' => 'move', 'id' => 'sek2', 'into' => 'txt1'],
    ['op' => 'kouzlo'],
], $editErrors);
check('Edits: edit content and style (null removes), insert, move, insert at the start', [array_column($edit['children'], 'id'), $edit['children'][1]['children'][0]['style'], $edit['children'][1]['children'][0]['classes'], array_column($edit['children'][2]['children'], 'id'), $edit['children'][2]['children'][1]['content']['link']],
    [['sek0', 'sek1', 'sek2'], ['mobile' => ['text_align' => 'center']], ['nadpis-sekce'], ['txt1', 'tl1'], '/guide']);
check('Edits: wrong operations are reported and skipped (also a move into a descendant)', array_keys($editErrors), ['op[5]', 'op[6]', 'op[7]']);
$editErrors2 = [];
check('Edits: moving an element into its descendant is not possible', Talea\Builder\Edits::apply($editBuild, [['op' => 'move', 'id' => 'sek1', 'into' => 'nad1']], $editErrors2) === $editBuild && isset($editErrors2['op[0]']), true);

[$compactBuild] = Talea\Builder\Build::sanitize(['v' => 1, 'children' => [['type' => 'section', 'id' => 'abc', 'children' => [['type' => 'button', 'id' => 'def', 'content' => ['text' => 'Jdi']], ['type' => 'container', 'id' => 'ghi', 'style' => ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm']]]]]]]);
$compact = Talea\Builder\Build::compact($compactBuild);
check('Build::compact: without default values, the style stays', $compact, ['v' => 1, 'children' => [['id' => 'abc', 'type' => 'section', 'children' => [['id' => 'def', 'type' => 'button', 'content' => ['text' => 'Jdi']], ['id' => 'ghi', 'type' => 'container', 'style' => ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm']]]]]]]);
check('Build: the <mark> highlight in a heading stays, classes and styles do not', Talea\Builder\Build::sanitize(['v' => 1, 'children' => [['type' => 'heading', 'id' => 'mk1', 'content' => ['text' => 'Publish<mark class="x" style="color:red">.</mark>']]]])[0]['children'][0]['content']['text'], 'Publish<mark>.</mark>');
$buttonContext = null;
check('Styl::zCss: aliasy margin-top a flex-start', [Talea\Builder\Style::fromCss('margin-bottom', '24px'), Talea\Builder\Style::fromCss('align-items', 'flex-start')], [['margin_bottom' => '24px'], ['align_items' => 'start']]);
check('Styl::zCss: text-align left/right', [Talea\Builder\Style::fromCss('text-align', 'left'), Talea\Builder\Style::fromCss('text-align', 'right')], [['text_align' => 'start'], ['text_align' => 'end']]);
check('Icons: GitHub is in the set', str_contains(Talea\Builder\Icons::svg('github'), 'M9 19c-4'), true);
check('Button: icon after the text and to the left of the text', [
    (bool) preg_match('#>Start<svg#', Talea\Builder\Elements\Button::render(['content' => ['text' => 'Start', 'link' => '/x', 'variant' => 'primary', 'new_window' => false, 'icon' => 'arrow', 'icon_left' => false]], '', '', (new ReflectionClass(Talea\Builder\Context::class))->newInstanceWithoutConstructor())),
    (bool) preg_match('#</svg>GitHub</a>#', Talea\Builder\Elements\Button::render(['content' => ['text' => 'GitHub', 'link' => '/x', 'variant' => 'outline', 'new_window' => false, 'icon' => 'github', 'icon_left' => true]], '', '', (new ReflectionClass(Talea\Builder\Context::class))->newInstanceWithoutConstructor())),
    Talea\Builder\Elements\Button::render(['content' => ['text' => 'Bez', 'link' => '/x', 'variant' => 'primary', 'new_window' => false]], '', '', (new ReflectionClass(Talea\Builder\Context::class))->newInstanceWithoutConstructor()) === '<a class="tl-button tl-button--primary" href="/x">Bez</a>',
], [true, true, true]);
check('Build::compact: the same build after sanitizing', Talea\Builder\Build::sanitize($compact)[0], $compactBuild);
$overview = Talea\Builder\Build::overview(Talea\Builder\Build::schema());
check('Build::overview: one element per line, the default option with an asterisk, a much smaller schema', [str_contains($overview['elements']['button'], 'variant:choice(primary*|'), strlen((string) json_encode($overview)) < strlen((string) json_encode(Talea\Builder\Build::schema())) / 2],
    [true, true]);
check('HtmlConverter: the result passes the validator without errors', Talea\Builder\Build::sanitize($fromHtml['build'])[1], []);
check('Build::asText: semantic content without layout', Talea\Builder\Build::asText($fromHtml['build']), "<h1>A <em>b</em></h1>\n<p>Jedna.</p><p>Dvě.</p>\n<p><a href=\"/k\">K</a></p>\n<p>Volný text</p>\n<h3>Otázka?</h3><p>Odpověď.</p>"); // check-english: allow

// disabled extensions: the builder offers neither their elements nor sections using them
$schemaTypes = array_column(Talea\Builder\Build::schema(true, 'cs', false, ['stats'])['elements'], 'type');
check('Extensions: without news and enquiries the schema has no such elements', [in_array('news_list', $schemaTypes, true), in_array('form', $schemaTypes, true), in_array('heading', $schemaTypes, true)], [false, false, true]);
$libraryKey = array_column(Talea\Builder\Library::listAll(['stats']), 'key');
check('Extensions: the library without sections with a form and news', [in_array('contact-form', $libraryKey, true), in_array('news', $libraryKey, true), in_array('hero', $libraryKey, true)], [false, false, true]);
check('Extensions: without a restriction the library is whole', count(Talea\Builder\Library::listAll()) > count($libraryKey), true);
$libraryEn = Talea\Builder\Library::section('hero', 'en')['element'];
check('Library: sections in English including page links', [$libraryEn['children'][0]['content']['text'], $libraryEn['children'][2]['children'][0]['content']['link'], $libraryEn['children'][2]['children'][1]['content']['link']], ['We help businesses grow – quickly and hassle-free', '/contact', '/services']);
check('Library: in Czech it links to Czech addresses', Talea\Core\Language::runWith('cs', fn () => Talea\Builder\Library::section('hero', 'cs')['element']['children'][2]['children'][0]['content']['link']), '/kontakt'); // check-english: allow
check('Library: the language is restored after a section is built', Talea\Core\Language::code(), 'cs');
$librarySchema = array_column(Talea\Builder\Build::schema(true, 'en')['elements'], 'properties', 'type');
check('Build::schema: default element content in the page language', [$librarySchema['heading']['text']['default'], $librarySchema['button']['text']['default']], ['Heading', 'Contact us']);
$libraryEnDictionary = (require TALEA_ROOT . '/system/languages/en.php') + (require TALEA_ROOT . '/system/languages/cs.php');
preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)*)'\)/", file_get_contents(TALEA_ROOT . '/system/src/Builder/Library.php') . implode('', array_map('file_get_contents', glob(TALEA_ROOT . '/system/src/Builder/Elements/*.php'))), $libraryTexts);
check('Library and elements: all sample texts have an English translation', array_values(array_diff(array_unique(array_map('stripslashes', $libraryTexts[1])), array_keys($libraryEnDictionary), ['Menu', 'Standard', 'Video'])), []);

check('Company::hours: day range, several periods, closed', Talea\Front\Company::parseOpeningHours("Mo–Fr 8:00–17:00\nTu 8-12, 13-17\nSu closed"), [
    ['days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'from' => '08:00', 'to' => '17:00'],
    ['days' => ['Tuesday'], 'from' => '08:00', 'to' => '12:00'], ['days' => ['Tuesday'], 'from' => '13:00', 'to' => '17:00'],
]);
check('Company::hours: an unintelligible line is rejected', [Talea\Front\Company::parseOpeningHours('every day 8-17'), Talea\Front\Company::parseOpeningHours('Mo 8-25')], [null, null]);
check('Company: the types in Settings match Company::TYPES', (new ReflectionClassConstant(Talea\Admin\Modules\Settings::class, 'COMPANY_TYPES'))->getValue(), implode('|', array_keys(Talea\Front\Company::TYPES)));

/* ---------- collections ---------- */
$collectionFields = Talea\Builder\Collections::sanitizeFields([['label' => 'Citát zákazníka', 'type' => 'lines'], ['label' => 'Name', 'type' => 'text'], ['label' => 'Logo', 'type' => 'nesmysl'], ['label' => '']]); // check-english: allow
check('Collections::sanitizeFields: key from the label, a built-in name is not overwritten, an unknown type = text', array_map(fn (array $p): string => $p['key'] . ':' . $p['type'], $collectionFields), ['citat_zakaznika:lines', 'name_2:text', 'logo:text']);
$collectionErrors = [];
$collectionData = Talea\Builder\Collections::sanitizeData([['key' => 'web', 'label' => 'Web', 'type' => 'link'], ['key' => 'photo', 'label' => 'Foto', 'type' => 'image'], ['key' => 'cena', 'label' => 'Cena', 'type' => 'number'], ['key' => 'bio', 'label' => 'Bio', 'type' => 'html']],
    ['web' => 'javascript:alert(1)', 'photo' => 'media/2026/a.jpg', 'cena' => '1 200', 'bio' => '<p onclick="x">Ahoj</p><script>1</script>'], $collectionErrors);
check('Collections::sanitizeData: a dangerous link gone, an image from media, a number without spaces, HTML sanitized', [$collectionData['web'], $collectionData['photo'], $collectionData['cena'], $collectionData['bio'], array_keys($collectionErrors)], ['', 'media/2026/a.jpg', '1200', '<p>Ahoj</p>', ['web']]);
$collectionValues = ['name' => ['Jan <b>Novák</b>', 'text'], 'bio' => ['<p>Truhlář</p>', 'html'], 'note' => ["řádek 1\nřádek 2", 'lines'], 'url' => ['/tym/jan', 'link'], 'zly' => ['javascript:x', 'link']]; // check-english: allow
check('Collections::fill: tags in a filled-in value are not filled in again', Talea\Builder\Collections::fill('<p>{{text}}</p><p>{{name}}</p>', 'html', ['text' => ['<p>Napište {{name}} nebo {{url}}.</p>', 'html'], 'name' => ['Návod', 'text'], 'url' => ['/navod', 'text']]), '<p>Napište {{name}} nebo {{url}}.</p><p>Návod</p>'); // check-english: allow
check('Collections::fill: a single pass also in line text', Talea\Builder\Collections::fill('{{description}} – {{name}}', 'inline_text', ['description' => ['Viz {{name}}', 'lines'], 'name' => ['X', 'text']]), 'Viz {{name}} – X');
check('Collections::fill: text is escaped only by the element, inline and html at once, an html field stays HTML', [
    Talea\Builder\Collections::fill('{{name}}', 'text', $collectionValues), Talea\Builder\Collections::fill('Tým: {{name}}', 'inline_text', $collectionValues), // check-english: allow
    Talea\Builder\Collections::fill('{{bio}}', 'html', $collectionValues), Talea\Builder\Collections::fill('<p>{{note}}</p>', 'html', $collectionValues),
    Talea\Builder\Collections::fill('{{url}}', 'link', $collectionValues), Talea\Builder\Collections::fill('{{zly}}', 'link', $collectionValues), Talea\Builder\Collections::fill('{{neni}}', 'inline_text', $collectionValues),
], ['Jan <b>Novák</b>', 'Tým: Jan &lt;b&gt;Novák&lt;/b&gt;', '<p>Truhlář</p>', '<p>řádek 1<br>' . "\n" . 'řádek 2</p>', '/tym/jan', '', '']); // check-english: allow
[$collectionBuild, $collectionErrors] = Talea\Builder\Build::sanitize(['children' => [['type' => 'collection_list', 'content' => ['collection' => 'tym'], 'children' => [['type' => 'image', 'content' => ['src' => '{{photo}}']], ['type' => 'button', 'content' => ['link' => '{{url}}']]]]]]);
check('Build::sanitize: {{fields}} tags in an image and a link pass', [$collectionBuild['children'][0]['children'][0]['content']['src'], $collectionBuild['children'][0]['children'][1]['content']['link'], $collectionErrors], ['{{photo}}', '{{url}}', []]);

/* ---------- builder English: editor texts (JS) and schema labels (PHP) ---------- */
$builderScripts = '';
foreach (['builder', 'builder-overlay', 'builder-handles', 'builder-keys'] as $builderScript) { // the editor and the compose scripts (direct manipulation on the canvas)
    $builderScripts .= (string) file_get_contents(TALEA_ROOT . '/image/' . $builderScript . '.js');
}
preg_match_all("/\bT\('((?:[^'\\\\]|\\\\.)*)'\)/", $builderScripts, $enJs);
preg_match('/window\.TALEA_TRANSLATIONS = (\{.*\});/s', (string) file_get_contents(TALEA_ROOT . '/image/languages/admin-en.js'), $enJsDictionary);
$enJsKeys = array_keys((array) json_decode((string) preg_replace(['#^\s*//.*$#m', '/,\s*\}$/'], ['', '}'], $enJsDictionary[1] ?? '{}'), true));
preg_match('/window\.TALEA_TRANSLATIONS = (\{.*\});/s', (string) file_get_contents(TALEA_ROOT . '/image/languages/admin-cs.js'), $csJsDictionary);
$enJsKeys = [...$enJsKeys, ...array_keys((array) json_decode($csJsDictionary[1] ?? '{}', true))];
check('Builder: all editor texts have an English translation', array_values(array_diff(array_unique(array_map('stripslashes', $enJs[1])), $enJsKeys, ['Tablet', 'Menu'])), []);
$enAdmin = (require TALEA_ROOT . '/system/languages/admin-en.php') + (require TALEA_ROOT . '/system/languages/admin-cs.php');
$enSchema = Talea\Builder\Build::schema(true, 'cs', true);
$enTexts = array_merge(array_column($enSchema['elements'], 'name'), array_column($enSchema['elements'], 'description'), array_column($enSchema['elements'], 'group'), array_values($enSchema['style_groups']));
$enFields = function (array $properties) use (&$enFields, &$enTexts): void {
    foreach ($properties as $d) {
        $enTexts[] = (string) ($d['label'] ?? '');
        array_push($enTexts, ...array_values($d['options'] ?? []));
        if (isset($d['fields'])) {
            $enFields($d['fields']);
        }
    }
};
foreach ($enSchema['elements'] as $p) {
    $enFields($p['properties']);
}
$enFields($enSchema['style']);
check('Builder: all schema labels have an English translation', array_values(array_filter(array_unique($enTexts), fn (string $x): bool => $x !== '' && preg_match('/\p{L}/u', $x) === 1 && !isset($enAdmin[$x]) && !in_array($x, ['Video', 'Logo', 'HTML', 'Text', 'text'], true))), []);

$siteErrors = [];
$siteSections = array_column(Talea\Builder\Library::listAll(), 'key');
foreach (Talea\Builder\Library::SITES as $siteKey => $networks) {
    if (!isset(Talea\Builder\DesignSystem::PRESETS[$networks['preset']])) {
        $siteErrors[] = $siteKey . ': preset ' . $networks['preset'];
    }
    foreach ($networks['pages'] as $pageSections) {
        foreach (array_diff($pageSections, $siteSections) as $missing) {
            $siteErrors[] = $siteKey . ': section ' . $missing;
        }
    }
}
check('Library::SITES: the presets and sections of the sample sites exist', $siteErrors, []);

// pages of the sample sites pass the builder's pre-publish check (image/builder.js, check()): buttons with a link,
// no empty image, a single h1 and an outline without a skipped level – in Czech and English, with all extensions and without them
$pageCheck = function (array $build): array {
    $findings = [];
    $headings = [];
    $walk = function (array $children) use (&$walk, &$findings, &$headings): void {
        foreach ($children as $p) {
            $o = $p['content'] ?? [];
            if ($p['type'] === 'button' && in_array($o['link'] ?? '', ['', '#'], true)) {
                $findings[] = 'button without a link: ' . ($o['text'] ?? '');
            }
            if ($p['type'] === 'image' && (($o['src'] ?? '') === '' || ($o['alt'] ?? '') === '')) {
                $findings[] = 'image without a file or description';
            }
            if ($p['type'] === 'heading' && preg_match('/^h([1-6])$/', $p['tag'] ?? 'h2', $m)) {
                $headings[] = (int) $m[1];
            }
            $walk($p['children'] ?? []);
        }
    };
    $walk($build['children']);
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
foreach (Talea\Builder\Library::SITES as $siteKey => $networks) {
    foreach (['cs', 'en'] as $language) {
        foreach (['all extensions' => array_keys(Talea\Core\Extensions::CATALOG), 'no extensions' => []] as $variant => $enabled) {
            foreach ($networks['pages'] as $i => $pageSections) {
                if ($pageSections === []) {
                    continue; // text page: the layout provides the h1 heading
                }
                [$build] = Talea\Builder\Library::assemble($pageSections, 'Stránka', $language, Talea\Builder\Build::disabledTypes($enabled), true); // check-english: allow
                foreach ($pageCheck($build) as $finding) {
                    $sitesCheck[] = "$siteKey/$language/$variant/page $i: $finding";
                }
            }
        }
    }
}
check('Library::SITES: the pages of the sample sites pass the pre-publish check', array_values(array_unique($sitesCheck)), []);
$contactWithoutForm = Talea\Builder\Library::assemble(Talea\Builder\Library::SITES['crafts']['pages'][3], 'Kontakt', 'cs', Talea\Builder\Build::disabledTypes([]), true)[0]; // check-english: allow
check('Library: a contact without the Forms extension has the company details', str_contains((string) json_encode($contactWithoutForm), '"detail":"address"'), true);

/* ---------- AI assistant: providers and builder ---------- */
$aiBody = ['model' => 'm1', 'max_tokens' => 50, 'system' => 'S', 'messages' => [['role' => 'username', 'content' => [['type' => 'image', 'source' => ['media_type' => 'image/png', 'data' => 'QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]]];
check('Assistant::toOpenAi: system, image as a data URL, token limit by provider', [Assistant::toOpenAi($aiBody, 'openai'), array_keys(Assistant::toOpenAi($aiBody, 'mistral'))], [
    ['model' => 'm1', 'messages' => [['role' => 'system', 'content' => 'S'], ['role' => 'username', 'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QQ==']], ['type' => 'text', 'text' => 'Ahoj']]]], 'max_completion_tokens' => 50],
    ['model', 'messages', 'max_tokens'],
]);
check('Assistant::fromOpenAi: the response in the Claude API shape', Assistant::fromOpenAi(['choices' => [['message' => ['content' => 'Text'], 'finish_reason' => 'length']]]), ['content' => [['type' => 'text', 'text' => 'Text']], 'stop_reason' => 'max_tokens']);
$aiSettings = (new ReflectionClass(Talea\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Talea\Core\Settings::class, 'values'))->setValue($aiSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'anthropic', 'ai_model' => 'claude-sonnet-5']);
$aiFake = new class($aiSettings) extends Assistant {
    public string $answer = '';
    public array $last = [];

    protected function call(array $body): array
    {
        $this->last = $body;

        return ['content' => [['type' => 'text', 'text' => $this->answer]]];
    }
};
$aiFake->answer = "Tady je sekce:\n```html\n<section class=\"services-ai\"><h2>Služby</h2><p>Text <script>x</script></p><a class=\"btn\" href=\"javascript:alert(1)\">Klik</a></section><style>.sluzby-ai { padding: var(--tl-space-l); }</style>\n```"; // check-english: allow
$aiHtml = $aiFake->suggestSection('Tři karty se službami a odkazem na kontakt.', 'cs', 'Služby'); // check-english: allow
$aiConversion = Talea\Builder\HtmlConverter::convert($aiHtml);
[$aiBuild] = Talea\Builder\Build::sanitize($aiConversion['build'], false);
check('Assistant::suggestSection: HTML from a ```html block, the brief inside <brief>, the result without a script and a javascript: link', [
    str_starts_with($aiHtml, '<section'), str_contains((string) $aiFake->last['messages'][0]['content'], '<brief>'), str_contains(json_encode($aiBuild), 'script'), str_contains(json_encode($aiBuild), 'javascript'), $aiConversion['classes'],
], [true, true, false, false, ['sluzby-ai' => 'padding: var(--tl-space-l);']]);
$aiFake->answer = '<p>Kratší <strong>text</strong> <img src=x onerror=alert(1)></p>'; // check-english: allow
check('Assistant::rewrite: an HTML answer sanitized, plain text without tags', [$aiFake->rewrite('<p>Dlouhý text k přepsání.</p>', 'shorter', true), $aiFake->rewrite('Nadpis', 'formal', false)], ['<p>Kratší <strong>text</strong> </p>', 'Kratší text']); // check-english: allow
(new ReflectionProperty(Talea\Core\Settings::class, 'values'))->setValue($aiSettings, ['site_name' => 'Test', 'ai_key' => 'x', 'ai_provider' => 'openai', 'ai_model' => 'claude-sonnet-5']);
try {
    $aiFake->rewrite('Text', 'shorter', false);
    $aiError = '';
} catch (RuntimeException $e) {
    $aiError = $e->getMessage();
}
check('Assistant: a provider other than Claude needs a model', str_contains($aiError, 'Enter the model name'), true);

/* ---------- old class names ---------- */
// 2.0: the old (Czech) class names and helpers of 1.3 are gone; 2.0.1 dropped the empty alias file (it rides along in packages only)
check('2.0: old class names and helpers no longer exist', [is_file(TALEA_SYSTEM . '/class-aliases.php'), class_exists('Talea\\Jadro\\Nastaveni'), function_exists('date_in_words'),
    class_exists('Talea\\Admin\\LegacyUrls'), class_exists('Talea\\Front\\Api'), class_exists('Talea\\Builder\\Elements\\Modal')], [false, false, false, false, false, false]);

/* ---------- 2.4: guide links in the administration ---------- */
// the articles of the guide on taleacms.com; a new admin module or settings tab needs its article here and in Admin\Guide
$guideArticles = ['install', 'first-steps', 'extensions', 'builder-basics', 'styling-responsive', 'elements', 'page-settings', 'site-appearance', 'classes', 'components',
    'site-parts', 'popups', 'menus', 'collections', 'collection-lists', 'site-search', 'news', 'forms', 'newsletter', 'company-details', 'seo', 'languages',
    'claude-connect', 'claude-capabilities', 'ai-assistant', 'users-roles', 'wordpress-import', 'backups-updates', 'media', 'statistics', 'privacy-cookies', 'email', 'site-health', 'fleet-console', 'industry-blueprints', 'connections', 'addons', 'bookings', 'claude-operator'];
$guideTargets = [...Talea\Admin\Guide::MODULES, ...Talea\Admin\Guide::SETTINGS, ...Talea\Admin\Guide::BUILDER];
check('2.4: every admin module and settings tab links to an existing guide article', [
    array_values(array_diff(array_map(fn (string $c): string => $c::IDENT, Talea\Admin\Kernel::MODULES), array_keys(Talea\Admin\Guide::MODULES), ['settings'])),
    array_values(array_diff(array_keys(Talea\Admin\Modules\Settings::TABS), array_keys(Talea\Admin\Guide::SETTINGS))),
    array_values(array_filter($guideTargets, fn (string $a): bool => !in_array(strtok($a, '#'), $guideArticles, true))),
], [[], [], []]);
check('2.4: guide link follows the admin language', [
    Talea\Admin\Guide::forScreen('settings', '', 'backups', 'cs'), Talea\Admin\Guide::forScreen('pages', 'builder', '', 'de'),
    Talea\Admin\Guide::forScreen('popups', 'builder', '', 'en'), Talea\Admin\Guide::forScreen('stats', '', '', 'pl'), Talea\Admin\Guide::forScreen('nothing', '', '', 'en'),
], ['https://taleacms.com/cs/guide/backups-updates', 'https://taleacms.com/de/guide/builder-basics', 'https://taleacms.com/guide/popups', 'https://taleacms.com/guide/statistics', null]);

/* ---------- 2.4: German administration ---------- */
// every admin text translated into Czech has a German translation too, with the same placeholders and tags
$placeholders = function (string $text): array { preg_match_all('/%(?:\d+\$)?[sd]|<\/?[a-z]+/i', $text, $m); sort($m[0]); return $m[0]; };
$jsDictionary = function (string $file): array { preg_match_all('/^\t("(?:[^"\\\\]|\\\\.)*"):\s*("(?:[^"\\\\]|\\\\.)*"),?$/m', (string) file_get_contents($file), $m, PREG_SET_ORDER);
    return array_combine(array_map(fn (array $x): string => json_decode($x[1]), $m), array_map(fn (array $x): string => json_decode($x[2]), $m)); };
$deGaps = [];
foreach ([[require TALEA_SYSTEM . '/languages/admin-cs.php', require TALEA_SYSTEM . '/languages/admin-de.php'],
    [$jsDictionary(TALEA_ROOT . '/image/languages/admin-cs.js'), $jsDictionary(TALEA_ROOT . '/image/languages/admin-de.js')]] as [$csTexts, $deTexts]) {
    foreach ($csTexts as $key => $_) {
        $key = (string) $key;
        if (!isset($deTexts[$key]) && !in_array($key, ['Name'], true)) { $deGaps[] = 'missing: ' . $key; }
        elseif (isset($deTexts[$key]) && $placeholders($key) !== $placeholders((string) $deTexts[$key])) { $deGaps[] = 'placeholders: ' . $key; }
    }
}
check('2.4: German admin covers every Czech admin text', array_slice($deGaps, 0, 5), []);
check('2.4: German is an admin language', [isset(Talea\Core\Language::ADMIN_LANGUAGES['de']), count($jsDictionary(TALEA_ROOT . '/image/languages/admin-de.js')) > 2500], [true, true]);

check('2.10: alert e-mails – errors and the warnings that need the owner, not every warning', array_column(Talea\Core\Alerts::worth([
    ['id' => 1, 'created_at' => '', 'type' => 'backup.failed', 'severity' => 'error', 'message' => '', 'data' => []],
    ['id' => 2, 'created_at' => '', 'type' => 'fleet.site_updated', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 3, 'created_at' => '', 'type' => 'content.review', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 4, 'created_at' => '', 'type' => 'notfound.spike', 'severity' => 'warning', 'message' => '', 'data' => []]]), 'id'), [1, 3, 4]);
/* ---------- 2.10: business facts – values by type, how they are shown, the token ---------- */
check('2.10: Facts::clean – a value must fit its type', [Talea\Core\Facts::clean('number', '1 500'), Talea\Core\Facts::clean('number', 'many'), Talea\Core\Facts::clean('year', '2004'),
    Talea\Core\Facts::clean('year', '04'), Talea\Core\Facts::clean('money', '1500 CZK'), Talea\Core\Facts::clean('money', '1500,- Kč'), Talea\Core\Facts::clean('date', '2026-10-02'), // check-english: allow
    Talea\Core\Facts::clean('email', 'info@example.cz'), Talea\Core\Facts::clean('url', 'javascript:alert(1)'), Talea\Core\Facts::clean('text', '<b>20</b> let'), Talea\Core\Facts::clean('text', 'see {{fact.other}}')],
    ['1500', null, '2004', null, '1500 CZK', null, '2026-10-02', 'info@example.cz', null, '20 let', null]);
check('2.10: Facts::display – numbers with the thousands separator of the language, amounts with the currency', Talea\Core\Language::runWith('cs', fn (): array => [
    Talea\Core\Facts::display('number', '12500'), Talea\Core\Facts::display('money', '1500 CZK'), Talea\Core\Facts::display('year', '2004'), Talea\Core\Facts::display('text', 'od 2004')]),
    ["12\u{00A0}500", "1\u{00A0}500\u{00A0}CZK", '2004', 'od 2004']);
preg_match_all(Talea\Core\Facts::TOKEN_PATTERN, 'Since {{fact.founded}} – {{ fact.projects }} projects, {{fact.Bad}}, {{fact.x}}, {{name}}', $factTokens);
check('2.10: the fact token – spaces inside are fine, collection fields and bad keys are not facts', $factTokens[1], ['founded', 'projects']);
/* ---------- 2.10: computed facts – years since, counts, the proof rule ---------- */
$yearsSince = fn (string $value, string $now): ?int => Talea\Core\Facts::yearsSince($value, new DateTimeImmutable($now));
check('2.10: years_since – a year, a date on, before and after its anniversary, this year, a bad argument, a date ahead', [
    $yearsSince('2004', '2026-10-02 12:00'), $yearsSince('2004-10-02', '2026-10-02 00:00'), $yearsSince('2004-10-03', '2026-10-02 12:00'), $yearsSince('2004-10-01', '2026-10-02 12:00'),
    $yearsSince('2026', '2026-01-01'), $yearsSince('soon', '2026-10-02'), $yearsSince('2004-13-01', '2026-10-02'), $yearsSince('2030', '2026-10-02'), $yearsSince('2026-12-24', '2026-10-02')],
    [22, 22, 21, 22, 0, null, null, null, null]);
$factApp = (new ReflectionClass(Talea\Core\App::class))->newInstanceWithoutConstructor();
check('2.10: computed() – years since a year as the site shows it, a bad argument is nothing (the audit reports it)', [Talea\Core\Facts::computed($factApp, 'years_since', '2004', new DateTimeImmutable('2026-06-01')), Talea\Core\Facts::computed($factApp, 'years_since', 'soon')], ['22', null]);
preg_match_all(Talea\Core\Facts::COMPUTED_PATTERN, '{{years_since:2004}} {{ years_since:fact.founded }} {{count:reference-2}} {{count:news}} {{count:Velka}} {{unknown:x}} {{name}}', $computedTokens, PREG_SET_ORDER);
check('2.10: the computed token – both forms with their arguments, nothing else', array_map(fn (array $c): string => $c[1] . ':' . $c[2], $computedTokens), ['years_since:2004', 'years_since:fact.founded', 'count:reference-2', 'count:news']);
check('2.10: claims – a sentence with a computed or fact token is not a claim any more', [Talea\Core\Facts::isClaim('Na trhu jsme 22 let.'), Talea\Core\Facts::isClaim('Na trhu jsme {{years_since:2004}} let.'), // check-english: allow
    Talea\Core\Facts::isClaim('Máme {{fact.projects}} zakázek.'), Talea\Core\Facts::isClaim('Otevřeno od 8 hodin.')], [true, false, false, false]); // check-english: allow
$proofBuild = ['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'children' => [['id' => 'c1', 'type' => 'counter', 'content' => ['number' => 1500]], ['id' => 'c2', 'type' => 'counter', 'content' => ['number' => '{{fact.projects}}']],
    ['id' => 'c3', 'type' => 'counter', 'content' => ['number' => ' 22 ']], ['id' => 'c4', 'type' => 'counter', 'content' => ['number' => '{{count:reference}}']], ['id' => 'n1', 'type' => 'heading', 'tag' => 'p', 'content' => ['text' => '1500']]]]]];
check('2.10: proof numbers typed in – counters with digits, not with tokens and not headings', Talea\Core\Facts::typedNumbers($proofBuild), [['id' => 'c1', 'number' => '1500'], ['id' => 'c3', 'number' => '22']]);
[$counterBuild] = Talea\Builder\Build::sanitize(['children' => [['type' => 'counter', 'content' => ['number' => 1500]], ['type' => 'counter', 'content' => ['number' => '{{years_since:fact.founded}}']]]], false);
check('2.10: the counter keeps a number and a token alike', array_column(array_column($counterBuild['children'], 'content'), 'number'), ['1500', '{{years_since:fact.founded}}']);
check('2.10: the counter is content – its number or token and label are in the text of the build (usage, the audit, the old value)', Talea\Builder\Build::asText($counterBuild),
    "<p>1500+ " . t('spokojených zákazníků') . "</p>\n<p>{{years_since:fact.founded}}+ " . t('spokojených zákazníků') . '</p>'); // check-english: allow
$counterContext = new Talea\Builder\Context($factApp, true);
$counterHtml = fn (string $number): string => Talea\Builder\Elements\Counter::render(['tag' => 'div', 'content' => ['number' => $number, 'prefix' => '', 'suffix' => '+', 'caption' => 'zakázek']], '', '', $counterContext); // check-english: allow
check('2.10: the counter – digits count up, the editor shows a token as it is (without the count-up)', Talea\Core\Language::runWith('cs', fn (): array => [str_contains($counterHtml('1500'), "data-counter=\"1500\">1\u{00A0}500<"),
    str_contains($counterHtml('{{fact.projects}}'), '>{{fact.projects}}<'), str_contains($counterHtml('{{fact.projects}}'), 'data-counter')]), [true, true, false]);
check('2.10.1: tokens inside <code> and <pre> are examples – never filled, not reported', [
    Talea\Core\Facts::withoutCode('<p>Write <code>{{fact.key}}</code> here, <pre>{{years_since:2004}}</pre> and {{fact.real}}.</p>')],
    ['<p>Write   here,   and {{fact.real}}.</p>']);
/* ---------- 2.10: opening hours with exceptions ---------- */
$hWeek = array_fill_keys(Talea\Core\Hours::DAYS, []);
foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $hDay) { $hWeek[$hDay] = [['08:00', '12:00'], ['13:00', '17:00']]; }
$hXmas = ['id' => 1, 'from' => '2026-12-24', 'to' => '2026-12-26', 'closed' => true, 'hours' => '', 'note' => 'Christmas', 'notice_days' => 7];
$hShort = ['id' => 2, 'from' => '2026-12-31', 'to' => '2026-12-31', 'closed' => false, 'hours' => '9-12', 'note' => '', 'notice_days' => 0];
$hAt = fn (string $when): DateTimeImmutable => new DateTimeImmutable($when);
$hStatus = fn (string $when, array $ex = []): array => (fn (array $st): array => [$st['open'], $st['until'], $st['next']?->format('Y-m-d H:i')])(Talea\Core\Hours::status($hWeek, $ex, $hAt($when)));
check('2.10: Hours::parseRanges – 9-12, 9:00–12:00 and more ranges; nonsense is refused', [Talea\Core\Hours::parseRanges('9-12, 13:30–17'), Talea\Core\Hours::parseRanges('morning')],
    [[['09:00', '12:00'], ['13:30', '17:00']], null]);
check('2.10: Hours::status – open until, lunch break, closed for the weekend, a holiday, shorter hours', [
    $hStatus('2026-10-05 10:00'), $hStatus('2026-10-05 12:30'), $hStatus('2026-10-03 11:00'), $hStatus('2026-12-23 18:00', [$hXmas]), $hStatus('2026-12-31 10:00', [$hShort]), $hStatus('2026-12-31 12:30', [$hShort])],
    [[true, '12:00', null], [false, null, '2026-10-05 13:00'], [false, null, '2026-10-05 08:00'], [false, null, '2026-12-28 08:00'], [true, '12:00', null], [false, null, '2027-01-01 08:00']]);
check('2.10: Hours – the notice bar a week ahead until the end, not with notice_days 0; exceptions in schema.org', [
    count(Talea\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-16 10:00'))), count(Talea\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-17 10:00'))),
    count(Talea\Core\Hours::noticed([$hXmas, $hShort], $hAt('2026-12-27 10:00'))), count(Talea\Core\Hours::noticed([$hShort], $hAt('2026-12-31 10:00'))),
    Talea\Core\Hours::schema([$hXmas, $hShort])],
    [0, 1, 0, 0, [['@type' => 'OpeningHoursSpecification', 'opens' => '00:00', 'closes' => '00:00', 'validFrom' => '2026-12-24', 'validThrough' => '2026-12-26'],
        ['@type' => 'OpeningHoursSpecification', 'opens' => '09:00', 'closes' => '12:00', 'validFrom' => '2026-12-31', 'validThrough' => '2026-12-31']]]);
/* ---------- 2.10: links between collections, redirect of hidden items ---------- */
$linkFields = Talea\Builder\Collections::sanitizeFields([['label' => 'Pobočka', 'type' => 'item', 'collection' => 'pobocky'], ['label' => 'Bez kolekce', 'type' => 'item'], ['label' => 'Role', 'type' => 'text', 'collection' => 'x']]); // check-english: allow
check('2.10: an item link remembers its collection; without one it is a short text', $linkFields,
    [['key' => 'pobocka', 'label' => 'Pobočka', 'type' => 'item', 'collection' => 'pobocky'], ['key' => 'bez_kolekce', 'label' => 'Bez kolekce', 'type' => 'text'], ['key' => 'role', 'label' => 'Role', 'type' => 'text']]); // check-english: allow
$linkErrors = [];
check('2.10: an item link stores the address of the item', [Talea\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'praha-centrum'], $linkErrors), Talea\Builder\Collections::sanitizeData($linkFields, ['pobocka' => 'Praha <b>'], $linkErrors), $linkErrors],
    [['pobocka' => 'praha-centrum', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => '', 'bez_kolekce' => '', 'role' => ''], ['pobocka' => 'Pobočka']]); // check-english: allow
$linkValues = Talea\Builder\Collections::values(['slug' => 'lide', 'detail' => 1, 'fields' => $linkFields], ['name' => 'Jana', 'slug' => 'jana', 'created_at' => '2026-10-02 10:00:00', 'data' => ['pobocka' => 'praha-centrum']], fn (string $p): string => '/' . $p);
check('2.10: {{field}}, {{field_url}} and {{field_seo}} of an item link (without a database only the address)', [$linkValues['pobocka'], $linkValues['pobocka_url'], $linkValues['pobocka_seo']],
    [['', 'text'], ['', 'link'], ['praha-centrum', 'text']]);
check('2.10: where hidden items redirect – a path on the site or https', array_map(Talea\Builder\Collections::cleanRedirect(...), ['', '/tym', 'https://example.com/team', 'javascript:alert(1)', 'tym', '/a b']),
    ['', '/tym', 'https://example.com/team', null, null, null]);
check('2.10: Hours::rangesText – hours for people from ranges or their text, nothing from nonsense (the door sign)', [Talea\Core\Hours::rangesText([['08:00', '12:00'], ['13:30', '17:00']]), Talea\Core\Hours::rangesText('9-12'), Talea\Core\Hours::rangesText('morning')],
    ['8:00–12:00, 13:30–17:00', '9:00–12:00', '']);
check('2.10: HoursSign – formats, a standalone view with print CSS, a Print button only on screen and no outside resource', [Talea\Core\HoursSign::FORMATS,
    (fn (string $v): array => [str_contains($v, '@page'), str_contains($v, '@media print'), str_contains($v, 'data-print'), preg_match('/(href|src)="https?:/', $v) === 0, str_contains($v, '<!DOCTYPE html>')])((string) file_get_contents(TALEA_SYSTEM . '/views/admin/settings/hours_sign.php'))],
    [['a4', 'a5'], [true, true, true, true, true]]);
/* ---------- 2.9: fleet console – keys, pairing key, staged updates, attention ---------- */
$fleetPair = sodium_crypto_sign_keypair();
$fleetPub = base64_encode(sodium_crypto_sign_publickey($fleetPair));
$fleetSig = base64_encode(sodium_crypto_sign_detached('{"a":1}', sodium_crypto_sign_secretkey($fleetPair)));
check('2.9: Fleet\Keys – a signature fits only its message and key', [Talea\Fleet\Keys::verify('{"a":1}', $fleetSig, $fleetPub), Talea\Fleet\Keys::verify('{"a":2}', $fleetSig, $fleetPub),
    Talea\Fleet\Keys::verify('{"a":1}', $fleetSig, base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))), Talea\Fleet\Keys::verify('{"a":1}', 'junk', $fleetPub),
    Talea\Fleet\Keys::isPublicKey($fleetPub), Talea\Fleet\Keys::isPublicKey('abc'), strlen(Talea\Fleet\Keys::fingerprint($fleetPub))], [true, false, false, false, true, false, 8]);
check('2.9 / 3.3.2: Fleet\Http – https only; plain http (also to local addresses) just in the automated tests (TALEA_FLEET_LOCAL)', array_map(Talea\Fleet\Http::allowedUrl(...),
    ['https://console.example.com', 'http://console.example.com', 'http://127.0.0.1:8312', 'http://web.test', 'ftp://example.com', 'https://user:pw@example.com', 'javascript:alert(1)']),
    [true, false, false, false, false, false, false]);
check('3.3.2: Fleet\Http pins public addresses only – loopback, private, link-local and NAT64 are refused; the uptime check of a refused address is 0 without a request', [
    array_map(fn (string $url): ?array => Talea\Fleet\Http::pin($url), ['https://127.0.0.1:8443/', 'https://10.0.0.5/admin', 'https://[::1]/', 'https://169.254.169.254/', 'https://[64:ff9b::a00:1]/', 'https://93.184.216.34/x']),
    Talea\Fleet\Http::statuses(['http://10.0.0.5:8080/admin', 'gopher://example.com/']), Talea\Fleet\Http::allowedUrl('http://example.com/', true)],
    [[null, null, null, null, null, ['93.184.216.34', 443, '93.184.216.34', 'https://93.184.216.34/x']], [0, 0], true]);
$fleetKey = Talea\Fleet\Link::makeKey('https://console.example.com/', str_repeat('ab', 16), $fleetPub, 'Agency console');
check('2.9: Fleet\Link – the pairing key carries the console address, the one-time code and the console key', [
    Talea\Fleet\Link::parseKey(" \n" . chunk_split($fleetKey, 40, "\n")), Talea\Fleet\Link::parseKey('talea-console:junk'), Talea\Fleet\Link::parseKey(str_repeat('ab', 16)),
    Talea\Fleet\Link::parseKey(Talea\Fleet\Link::makeKey('http://console.example.com', str_repeat('ab', 16), $fleetPub, 'x'))],
    [['url' => 'https://console.example.com', 'code' => str_repeat('ab', 16), 'key' => $fleetPub, 'name' => 'Agency console'], null, null, null]);
$fleetNow = 1_800_000_000;
$fleetSite = fn (int $id, string $version, string $ring = 'normal', array $more = []): array => $more + ['id' => $id, 'version' => $version, 'ring' => $ring, 'manage_updates' => true,
    'update_allowed' => '', 'status' => 'ok', 'up' => true, 'version_since' => $fleetNow - 50 * 3600, 'update_problem' => false];
$allowed = fn (array $site, array $sites, bool $security = false, int $firstSeen = 0): string => Talea\Fleet\Console::allowedVersion($site, $sites, '2.9.0', $security, $firstSeen, $fleetNow);
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
    Talea\Fleet\Console::attention($fleetRow([]), $fleetNow),
    Talea\Fleet\Console::attention($fleetRow(['up' => 0, 'status' => 'error']), $fleetNow)['reasons'],
    Talea\Fleet\Console::attention($fleetRow(['last_seen' => date('Y-m-d H:i:s', $fleetNow - 30 * 3600)]), $fleetNow)['reasons'],
    Talea\Fleet\Console::attention($fleetRow(['last_seen' => null, 'heartbeat' => null]), $fleetNow)['reasons'],
    Talea\Fleet\Console::attention($fleetRow([], ['last_backup' => $fleetNow - 9 * 86400, 'enquiries_unanswered' => 2, 'jobs_failing' => ['mail'], 'update_problem' => 'Version 2.9.0 did not work']), $fleetNow)['reasons'],
], [['score' => 0, 'reasons' => []], ['down', 'errors'], ['not_reporting'], ['no_heartbeat'], ['update_failed', 'jobs_failing', 'no_backup', 'enquiries']]);
$fleetClean = Talea\Fleet\Console::clean(['version' => '2.9.0', 'name' => str_repeat('x', 400), 'secret' => 'drop me', 'problems' => [['check' => 'Mail', 'status' => 'warning', 'detail' => ['deep' => ['deeper' => ['deepest' => 1]]]]], 'visits_7_days' => 12.7]);
check('2.9: a heartbeat keeps only the known keys with sane values', [isset($fleetClean['secret']), mb_strlen($fleetClean['name']), $fleetClean['version'], $fleetClean['visits_7_days'], $fleetClean['problems'][0]['check']],
    [false, 255, '2.9.0', 12, 'Mail']);
/* ---------- the domain watch add-on (extensions/domain_watch, issue #28): its own tests ---------- */
require dirname(__DIR__) . '/extensions/domain_watch/tests/unit.php';
/* ---------- the firewall add-on (extensions/firewall, issue #29): its own tests ---------- */
require dirname(__DIR__) . '/extensions/firewall/tests/unit.php';
/* ---------- 2.8: real-user speed (Core\WebVitals) – histogram buckets, p75, Google's ratings, the audit rule ---------- */
use Talea\Core\WebVitals;
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
check('2.8: the beacon script looks for nothing but its own endpoint and sends with sendBeacon', [str_contains((string) file_get_contents(TALEA_ROOT . '/image/vitals.js'), "getAttribute('data-vitals')"),
    str_contains((string) file_get_contents(TALEA_ROOT . '/image/vitals.js'), 'navigator.sendBeacon('), preg_match('/document\.cookie|localStorage|sessionStorage/', (string) file_get_contents(TALEA_ROOT . '/image/vitals.js'))], [true, true, 0]);
check('2.8: /vitals is a reserved address', in_array('vitals', Talea\Admin\Modules\Pages::RESERVED_SLUGS, true), true);

/* ---------- 2.8: font preloading – only the site's own WOFF2 files that render text above the fold ---------- */
$fontsDs = ['custom_fonts' => [['name' => 'Firma Sans', 'file' => 'media/pisma/firma-sans.woff2', 'bold' => 'media/pisma/firma-sans-bold.woff2'], ['name' => 'Firma Serif', 'file' => 'media/pisma/firma-serif.woff2', 'bold' => ''], // check-english: allow
    ['name' => 'Old', 'file' => 'media/pisma/old.woff', 'bold' => '']]];
check('2.8: DesignSystem::fontPreloads – body = the regular file, headings = the bold file of the heading font', Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'custom-1', 'font_heading' => 'custom-1'] + $fontsDs, '/web'),
    '<link rel="preload" href="/web/media/pisma/firma-sans.woff2" as="font" type="font/woff2" crossorigin>' . "\n" . '<link rel="preload" href="/web/media/pisma/firma-sans-bold.woff2" as="font" type="font/woff2" crossorigin>');
check('2.8: DesignSystem::fontPreloads – a heading font without a bold file preloads its only file; a system body font preloads nothing', Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'modern', 'font_heading' => 'custom-2'] + $fontsDs),
    '<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>');
check('2.8: DesignSystem::fontPreloads – the same file once, system fonts and WOFF (not WOFF2) never', [Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'custom-2', 'font_heading' => 'custom-2'] + $fontsDs),
    Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'modern', 'font_heading' => 'classic'] + $fontsDs), Talea\Builder\DesignSystem::fontPreloads(['font_body' => 'custom-3', 'font_heading' => 'custom-3'] + $fontsDs)],
    ['<link rel="preload" href="/media/pisma/firma-serif.woff2" as="font" type="font/woff2" crossorigin>', '', '']);
$fontsCss = Talea\Builder\DesignSystem::css(Talea\Builder\DesignSystem::sanitize(['font_body' => 'custom-1', 'font_heading' => 'custom-2'] + $fontsDs), '/web');
check('2.8: every @font-face of the site\'s own fonts swaps in the fallback font while the file loads, and the preloaded files are the ones @font-face uses',
    [substr_count($fontsCss, '@font-face'), substr_count($fontsCss, 'font-display: swap'), str_contains($fontsCss, 'url("/web/media/pisma/firma-sans-bold.woff2")'), str_contains($fontsCss, 'url("/web/media/pisma/firma-serif.woff2")')], [4, 4, true, true]);
/* ---------- security hygiene (2.8): which accounts and connections count as unused, whom the automatic suspension never blocks ---------- */
$hygieneNow = new DateTimeImmutable('2026-10-02 12:00:00');
$account = fn (int $idu, int $admin, ?string $signIn, ?string $confirmed = null, ?string $claude = null, int $blocked = 0): array =>
    ['user_id' => $idu, 'username' => 'u' . $idu, 'name' => '', 'admin' => $admin, 'blocked' => $blocked, 'last_login_at' => $signIn, 'confirmed_at' => $confirmed, 'used_at' => $claude];
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
$hygieneUnused = Talea\Core\SecurityHygiene::unusedAccounts($hygieneAccounts, $hygieneNow);
check('Hygiene: unused accounts by sign-in, confirmation and Claude use, over 90 days only', array_column($hygieneUnused, 'user_id'), [2, 3, 5]);
check('Hygiene: the last activity is the latest of the three moments', Talea\Core\SecurityHygiene::lastActivity($hygieneAccounts[6]), '2026-09-30 08:00:00');
check('Hygiene: an account with no record is unknown, not unused', Talea\Core\SecurityHygiene::lastActivity($hygieneAccounts[8]), null);
check('Hygiene: the signed-in user is never blocked', array_column(Talea\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 3), 'user_id'), [2, 5]);
check('Hygiene: an unused administrator is blocked while another administrator stays active', array_column(Talea\Core\SecurityHygiene::blockable($hygieneUnused, $hygieneAccounts, 0), 'user_id'), [2, 3, 5]);
$hygieneAllOld = $hygieneAccounts;
$hygieneAllOld[0] = $account(1, 2, '2026-06-15 10:00:00'); // now every administrator is unused: the most recently active one stays
$hygieneUnusedAll = Talea\Core\SecurityHygiene::unusedAccounts($hygieneAllOld, $hygieneNow);
check('Hygiene: every administrator unused – the last active one is kept', [array_column($hygieneUnusedAll, 'user_id'), array_column(Talea\Core\SecurityHygiene::blockable($hygieneUnusedAll, $hygieneAllOld, 0), 'user_id')], [[1, 2, 3, 5], [2, 3, 5]]);
$hygieneOnlyAdmin = [$account(1, 2, '2026-01-01 00:00:00'), $account(2, 0, '2026-01-01 00:00:00')];
check('Hygiene: the only administrator is never blocked', array_column(Talea\Core\SecurityHygiene::blockable(Talea\Core\SecurityHygiene::unusedAccounts($hygieneOnlyAdmin, $hygieneNow), $hygieneOnlyAdmin, 0), 'user_id'), [2]);
$hygieneConnections = [
    ['kind' => 'token', 'id' => 1, 'user_id' => 1, 'username' => 'a', 'name' => 'laptop', 'last' => '2026-09-30 00:00:00', 'expiry' => null],
    ['kind' => 'token', 'id' => 2, 'user_id' => 1, 'username' => 'a', 'name' => 'old', 'last' => '2026-08-03 11:59:59', 'expiry' => null],
    ['kind' => 'app', 'id' => 'abc', 'user_id' => 2, 'username' => 'b', 'name' => 'Claude', 'last' => '2026-08-01 00:00:00', 'expiry' => '2026-10-20 00:00:00'],
    ['kind' => 'app', 'id' => 'def', 'user_id' => 2, 'username' => 'b', 'name' => 'Claude', 'last' => '2026-08-03 12:00:00', 'expiry' => null],
];
check('Hygiene: connections unused for over 60 days', array_map(fn (array $c): string => $c['kind'] . ':' . $c['id'], Talea\Core\SecurityHygiene::unusedConnections($hygieneConnections, $hygieneNow)), ['token:2', 'app:abc']);
check('Hygiene: days ago for messages', Talea\Core\SecurityHygiene::daysAgo('2026-06-01 10:00:00', $hygieneNow), 123);
check('Hygiene: thresholds are constants', [Talea\Core\SecurityHygiene::ACCOUNT_DAYS, Talea\Core\SecurityHygiene::CONNECTION_DAYS], [90, 60]);

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
check('2.10 Validity::expired – visible rows whose day has passed, today still counts as true', array_column(Talea\Core\Validity::expired($validityRows, '2026-10-01'), 'id'), [1]);
check('2.10 Validity::expired – the day after, today\'s row expires too', array_column(Talea\Core\Validity::expired($validityRows, '2026-10-02'), 'id'), [1, 2]);
check('2.10 Validity::dueForReview – today or earlier, once per content and date', array_column(Talea\Core\Validity::dueForReview($validityRows, '2026-10-01', $validityAsked), 'id'), [2, 3, 6]);
check('2.10 Validity::dueForReview – nothing asked yet', array_column(Talea\Core\Validity::dueForReview($validityRows, '2026-10-02', []), 'id'), [2, 3, 4, 5, 6]);
check('2.10 Validity::date – a form or Claude date, a datetime cut to its day, nonsense and empty = none', [Talea\Core\Validity::date('2026-10-01'), Talea\Core\Validity::date(' 2026-10-01T12:00 '),
    Talea\Core\Validity::date('2026-02-30'), Talea\Core\Validity::date(''), Talea\Core\Validity::date(null), Talea\Core\Validity::date('tomorrow')], ['2026-10-01', '2026-10-01', null, null, null, null]);
check('2.10 Validity::isDate', [Talea\Core\Validity::isDate('2026-10-01'), Talea\Core\Validity::isDate('2026-13-01'), Talea\Core\Validity::isDate('1.10.2026'), Talea\Core\Validity::isDate(20261001)], [true, false, false, false]);
check('2.10: the validity job, the audit kind and the events are known', [Talea\Core\Scheduler::JOBS['validity'][0], Talea\Core\Scheduler::JOBS['validity'][1], isset(Talea\Core\Scheduler::jobs()['validity']),
    Talea\Core\Audit::KINDS['review'], isset(Talea\Core\Events::TYPES['content.expired'], Talea\Core\Events::TYPES['content.review'])], [3600, 'any', true, 'Review by', true]);

/* ---------- 2.11: ready-made collections (Builder\Presets) and the date-time, file and location fields ---------- */
$presets = Talea\Builder\Presets::all();
$presetProblems = [];
foreach ($presets as $presetKey => $p) {
    $keys = array_column($p['fields'], 0);
    $sanitized = array_column(Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]] + (isset($f[3]['preset']) ? ['collection' => 'x'] : []), $p['fields'])), 'key');
    if ($keys !== $sanitized || count($keys) > 30) {
        $presetProblems[] = $presetKey . ': field keys change when sanitized';
    }
    foreach ($p['fields'] as $f) {
        if (!isset(Talea\Builder\Collections::FIELD_TYPES[$f[2]]) || ($f[2] === 'item' && !isset($presets[$f[3]['preset'] ?? '']))) {
            $presetProblems[] = $presetKey . ': ' . $f[0] . ' has an unknown type or linked preset';
        }
    }
    if (is_array($p['schema']) && Talea\Builder\CollectionSchema::sanitize($p['schema'], Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $p['fields'])))['fields'] != $p['schema']['fields']) {
        $presetProblems[] = $presetKey . ': the schema maps a field that does not exist';
    }
    if (trim((string) $p['claude']) === '' || trim((string) $p['description']) === '') {
        $presetProblems[] = $presetKey . ': no description or instructions for Claude';
    }
}
check('2.11 Presets: every preset is valid (keys survive sanitizing, known types, schema maps existing fields, described)', [isset($presets['people']), $presetProblems], [true, []]);
check('2.11 Presets::field – only in a collection made from the preset, and only while the field is there with its type', [
    Talea\Builder\Presets::field(['preset' => 'people', 'fields' => [['key' => 'phone', 'type' => 'text']]], 'people', 'phone', ['text']),
    Talea\Builder\Presets::field(['preset' => 'people', 'fields' => [['key' => 'phone', 'type' => 'number']]], 'people', 'phone', ['text']),
    Talea\Builder\Presets::field(['preset' => '', 'fields' => [['key' => 'phone', 'type' => 'text']]], 'people', 'phone', ['text'])], ['phone', null, null]);
check('2.11 cleanDateTime: date and time, a whole day, the T of an input, midnight = the whole day, nonsense', array_map(Talea\Builder\Collections::cleanDateTime(...),
    ['2026-10-24 18:30', '2026-10-24', '2026-10-24T09:05', '2026-10-24T00:00', '2026-10-24 9:05:00', '', '2026-02-30 10:00', '2026-10-24 25:00', 'tomorrow']),
    ['2026-10-24 18:30', '2026-10-24', '2026-10-24 09:05', '2026-10-24', '2026-10-24 09:05', '', null, null, null]);
check('2.11 cleanLocation: latitude, longitude – rounded, also with a semicolon; out of range and text are not valid', array_map(Talea\Builder\Collections::cleanLocation(...),
    ['50.0875, 14.4214', '50.08754321;14.42139876', '-33.9,18.42', '', '91, 10', '50, 181', 'Praha']), ['50.0875, 14.4214', '50.087543, 14.421399', '-33.9, 18.42', '', null, null, null]);
$fileFields = [['key' => 'start', 'label' => 'Start', 'type' => 'datetime'], ['key' => 'sheet', 'label' => 'Datasheet', 'type' => 'file'], ['key' => 'place', 'label' => 'Place', 'type' => 'location']];
$fileErrors = [];
check('2.11 sanitizeData: a file from Media or https, never a path out of it', [Talea\Builder\Collections::sanitizeData($fileFields, ['start' => '2026-11-02T17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19,16.61'], $fileErrors),
    Talea\Builder\Collections::sanitizeData($fileFields, ['sheet' => '/media/../config.php'], $fileErrors), Talea\Builder\Collections::sanitizeData($fileFields, ['sheet' => 'javascript:alert(1)'], $fileErrors)['sheet']],
    [['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/list.pdf', 'place' => '49.19, 16.61'], ['start' => '', 'sheet' => '', 'place' => ''], '']);
$fileValues = Talea\Builder\Collections::values(['slug' => 'action', 'detail' => 1, 'fields' => $fileFields], ['name' => 'Den otevřených dveří', 'slug' => 'day', 'created_at' => '2026-10-02 10:00:00', // check-english: allow
    'data' => ['start' => '2026-11-02 17:00', 'sheet' => '/media/docs/Cen%C3%ADk%202026.pdf', 'place' => '49.19, 16.61']], fn (string $p): string => '/' . $p);
check('2.11 values: {{start}} for visitors and {{start_iso}}, {{sheet}} a link and {{sheet_name}}', [$fileValues['start'][0], $fileValues['start_iso'][0], $fileValues['sheet'], $fileValues['sheet_name'][0], $fileValues['place'][0]],
    [format_date('2026-11-02 17:00', true), '2026-11-02 17:00', ['/media/docs/Cen%C3%ADk%202026.pdf', 'link'], 'Ceník 2026.pdf', '49.19, 16.61']); // check-english: allow

/* ---------- 2.11: events calendar (Core\Calendar) ---------- */
use Talea\Core\Calendar;

check('2.11 Calendar::nextOccurrence – a weekly class that ended moves by whole weeks to the next one not ended yet', [
    Calendar::nextOccurrence('2026-09-29 18:00', '2026-09-29 19:30', 'weekly', '', '2026-10-02 12:00'),
    Calendar::nextOccurrence('2026-09-01 18:00', '2026-09-01 19:30', 'weekly', '', '2026-10-02 12:00'),
    Calendar::nextOccurrence('2026-10-06 18:00', '', 'weekly', '', '2026-10-02 12:00'),                  // not ended yet
    Calendar::nextOccurrence('2026-09-29', '', 'every 2 weeks', '', '2026-10-02 12:00'),                  // a whole day stays a day
    Calendar::nextOccurrence('2026-09-29 18:00', '', 'weekly', '2026-10-05', '2026-10-02 12:00'),         // the next one is after the last day
    Calendar::nextOccurrence('2026-09-29 18:00', '', 'never', '', '2026-10-02 12:00'),
], [['2026-10-06 18:00', '2026-10-06 19:30'], ['2026-10-06 18:00', '2026-10-06 19:30'], null, ['2026-10-13', ''], null, null]);
check('2.11 Calendar::nextOccurrence – monthly on the 31st ends on the last day of a shorter month, yearly keeps the day', [
    Calendar::nextOccurrence('2026-01-31 10:00', '', 'monthly', '', '2026-02-10 00:00'), Calendar::nextOccurrence('2026-01-31 10:00', '', 'monthly', '', '2026-03-10 00:00'),
    Calendar::nextOccurrence('2025-10-02', '', 'yearly', '', '2026-10-01 00:00')], [['2026-02-28 10:00', ''], ['2026-03-31 10:00', ''], ['2026-10-02', '']]);
check('2.11 Calendar::endsAt and registrationState: closed after the event or the deadline day, full at capacity, unlimited without', [
    Calendar::endsAt('2026-10-02', ''), Calendar::endsAt('2026-10-02 18:00', '2026-10-02 20:00'),
    Calendar::registrationState(20, 5, '', '2026-10-02 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 20, '', '2026-10-02 20:00', '2026-10-02 12:00'),
    Calendar::registrationState(0, 500, '', '2026-10-02 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 5, '', '2026-10-01 20:00', '2026-10-02 12:00'),
    Calendar::registrationState(20, 5, '2026-10-02', '2026-10-09 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 5, '2026-10-01', '2026-10-09 20:00', '2026-10-02 12:00'),
    Calendar::registrationState(20, 5, '2026-10-02 10:00', '2026-10-09 20:00', '2026-10-02 12:00')],
    ['2026-10-02 23:59', '2026-10-02 20:00', 'open', 'full', 'open', 'closed', 'open', 'closed', 'closed']);
check('2.11 Calendar::when – one day with times, several days, the start alone', [Calendar::when('2026-11-02 17:00', '2026-11-02 19:00'), Calendar::when('2026-11-02', '2026-11-04'), Calendar::when('2026-11-02 17:00', ''), Calendar::when('', '')],
    [format_date('2026-11-02 17:00', true) . '–19:00', format_date('2026-11-02') . ' – ' . format_date('2026-11-04'), format_date('2026-11-02 17:00', true), '']);
check('2.11 Calendar::escape and fold – RFC 5545 text, lines of at most 75 octets, never inside a character', [Calendar::escape("a;b,c\\d\nnext"),
    array_map('strlen', explode("\r\n", Calendar::fold('DESCRIPTION:' . str_repeat('č', 60)))), Calendar::fold('SUMMARY:short')], // check-english: allow
    ['a\\;b\\,c\\\\d\\nnext', [74, 59], 'SUMMARY:short']);
$icsCollection = ['collection_id' => 1, 'slug' => 'action', 'preset' => 'events', 'detail' => 1, 'fields' => Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2], 'options' => $f[3]['options'] ?? []],
    (array) (Talea\Builder\Presets::get('events')['fields'] ?? [])))];
$ics = Calendar::ics($icsCollection, [[['item_id' => 7, 'public_id' => '0b1c2d3e-0000-4000-8000-000000000007', 'name' => 'Jóga, pro začátečníky', 'updated_at' => '2026-10-01 10:00:00', 'data' => ['start' => '2026-10-06 18:00', 'end' => '2026-10-06 19:30', 'venue' => 'Sál', 'address' => 'Hlavní 1, Brno', 'repeat' => 'weekly', 'repeat_until' => '2026-12-15', 'summary' => 'Přineste podložku.']], 'https://example.cz/akce/joga'], // check-english: allow
    [['item_id' => 8, 'name' => 'Den otevřených dveří', 'data' => ['start' => '2026-11-02']], ''], [['item_id' => 9, 'name' => 'Bez data', 'data' => []], '']], 'Web – Akce', 'example.cz'); // check-english: allow
$utc = fn (string $local): string => (new DateTimeImmutable($local))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
check('2.11 Calendar::ics – a timed series with RRULE in UTC, a whole day as DATE, an event without a date left out', [str_starts_with($ics, "BEGIN:VCALENDAR\r\n"), str_contains($ics, "UID:talea-0b1c2d3e-0000-4000-8000-000000000007@example.cz\r\n"),
    str_contains($ics, 'DTSTART:' . $utc('2026-10-06 18:00') . "\r\n"), str_contains($ics, 'RRULE:FREQ=WEEKLY;UNTIL=' . $utc('2026-12-15 23:59')), str_contains($ics, "SUMMARY:Jóga\\, pro začátečníky\r\n"), // check-english: allow
    str_contains($ics, 'LOCATION:Sál\\, Hlavní 1\\, Brno'), str_contains($ics, "DTSTART;VALUE=DATE:20261102\r\nDTEND;VALUE=DATE:20261103\r\n"), substr_count($ics, 'BEGIN:VEVENT'), str_ends_with($ics, "END:VCALENDAR\r\n")], // check-english: allow
    [true, true, true, true, true, true, true, 2, true]);
check('2.11 Calendar::fields – only a collection made from the events preset with its start field', [Calendar::fields($icsCollection)['repeat'] ?? null, Calendar::fields(['preset' => 'events', 'fields' => []]), Calendar::fields(['preset' => 'people', 'fields' => $icsCollection['fields']])],
    ['repeat', null, null]);

/* ---------- 2.11: product catalogue (Builder\Products) ---------- */
use Talea\Builder\Products;

check('2.11 Products::cleanParameters – "Name: value" lines, tags gone; a line without a value is not valid', [Products::cleanParameters("Weight:12 kg\n\n <b>Width</b>: 60 cm "), Products::cleanParameters('Weight'), Products::cleanParameters('')],
    ["Weight: 12 kg\nWidth: 60 cm", null, '']);
check('2.11 Products::cleanVariants – name | code | price, the empty end trimmed; too many parts or no name are not valid', [Products::cleanVariants("S | A-1 | 1 200 Kč\nM\nL | | from 900"), Products::cleanVariants('| X'), Products::cleanVariants('a | b | c | d')], // check-english: allow
    ["S | A-1 | 1 200 Kč\nM\nL |  | from 900", null, null]); // check-english: allow
check('2.11 Products tables – escaped, a column only when some variant has it', [Products::parametersTable('Weight: 12 & "13" kg'), Products::variantsTable("S\nM"), Products::parametersTable('')],
    ['<table class="tl-parameters"><tbody><tr><th scope="row">Weight</th><td>12 &amp; &quot;13&quot; kg</td></tr></tbody></table>', '<table class="tl-variants"><thead><tr><th scope="col">' . t('Variant') . '</th></tr></thead><tbody><tr><td>S</td></tr><tr><td>M</td></tr></tbody></table>', '']);
check('2.11 Products::comparison – every parameter name once in the order it first appears, values side by side', Products::comparison('p', [['data' => ['p' => "Weight: 12 kg\nWidth: 60 cm"]], ['data' => ['p' => "Width: 80 cm\nMotor: 2 kW"]]]),
    [['Weight', ['12 kg', '']], ['Width', ['60 cm', '80 cm']], ['Motor', ['', '2 kW']]]);
check('2.11 Products::fields – only a collection made from the products preset', [Products::fields(['preset' => 'people', 'fields' => []]), Products::fields(['preset' => 'products', 'fields' => [['key' => 'variants', 'type' => 'variants']]])['variants'] ?? null],
    [null, 'variants']);

/* ---------- 2.11: industry blueprints (Core\Blueprint) ---------- */
use Talea\Core\Blueprint;

$blueprintInput = ['talea_blueprint' => 1, 'key' => 'dental_clinic', 'name' => ['en' => 'Dental clinic'], 'description' => 'For dentists', 'presets' => ['people', 'events', 'people'],
    'facts' => [['key' => 'insurers', 'label' => 'Insurers', 'type' => 'text'], ['key' => 'founded', 'label' => 'Founded', 'type' => 'year', 'schema' => 'foundingDate', 'value' => 'never copied']],
    'questions' => [['question' => 'Which insurers do you have contracts with?', 'fact' => 'insurers', 'help' => 'Comma separated']],
    'audit' => [['check' => 'fact', 'fact' => 'insurers', 'message' => 'Say which insurers you work with.'], ['check' => 'preset_items', 'preset' => 'people', 'min' => 2, 'message' => 'Add the doctors.'],
        ['check' => 'setting', 'setting' => 'company_hours', 'message' => 'Fill in the opening hours.'], ['check' => 'page', 'slugs' => ['cenik', 'price-list'], 'message' => 'Publish the price list.'],
        ['check' => 'stale_items', 'preset' => 'events', 'days' => 180, 'message' => 'No events for half a year.']],
    'claude' => 'Patients look for insurers and hours first. <b>Never</b> give medical advice.', 'unknown' => 'dropped'];
[$blueprint, $blueprintErrors] = Blueprint::sanitize($blueprintInput);
check('2.11 Blueprint::sanitize – a valid manifest keeps its parts, drops duplicates, unknown keys, fact values and tags', [$blueprintErrors, $blueprint['presets'] ?? null, array_column($blueprint['facts'] ?? [], 'key'),
    isset($blueprint['facts'][1]['value']), $blueprint['facts'][1]['schema'] ?? null, count($blueprint['audit'] ?? []), $blueprint['audit'][1]['min'] ?? null, $blueprint['claude'] ?? null, isset($blueprint['unknown'])],
    [[], ['people', 'events'], ['insurers', 'founded'], false, 'foundingDate', 5, 2, 'Patients look for insurers and hours first. Never give medical advice.', false]);
$blueprintBad = static fn (array $change): array => Blueprint::sanitize(array_replace($blueprintInput, $change))[1];
check('2.11 Blueprint::sanitize – refuses what it cannot trust', [Blueprint::sanitize(['key' => 'x'])[0], $blueprintBad(['key' => 'Bad Key']) !== [], $blueprintBad(['presets' => ['shop']]) !== [],
    $blueprintBad(['questions' => [['question' => 'Q?', 'fact' => 'not_declared']]]) !== [], $blueprintBad(['audit' => [['check' => 'php', 'message' => 'x']]]) !== [],
    $blueprintBad(['audit' => [['check' => 'setting', 'setting' => 'smtp_password', 'message' => 'x']]]) !== [], $blueprintBad(['audit' => [['check' => 'stale_items', 'preset' => 'events', 'days' => 1, 'message' => 'x']]]) !== [],
    $blueprintBad(['facts' => [['key' => 'company_phone', 'label' => 'Phone']]]) !== []], [null, true, true, true, true, true, true, true]);
check('2.11 Blueprint::text – the admin language, else English, else the first', [Blueprint::text('plain'), Blueprint::text(['en' => 'Clinic', 'de' => 'Praxis']), Blueprint::text(['fr' => 'Cabinet'])],
    ['plain', Talea\Core\Language::code() === 'de' ? 'Praxis' : 'Clinic', 'Cabinet']);
$shipped = glob(TALEA_ROOT . '/system/blueprints/*.json') ?: [];
check('2.11 Blueprint: every shipped blueprint is valid and named by its key', array_values(array_filter(array_map(function (string $file): string {
    [$m, $errs] = Blueprint::sanitize(json_decode((string) file_get_contents($file), true));

    return $m === null ? basename($file) . ': ' . implode(' ', $errs) : ($m['key'] !== basename($file, '.json') ? basename($file) . ': key differs' : '');
}, $shipped))), []);
// the six industry blueprints shipped with 2.11: each asks the owner something, checks the site and guides Claude, in English
$shippedBlueprints = Blueprint::available();
$blueprintTexts = function (array $m): array { // every text of a manifest that visitors of the administration may read
    $texts = [$m['name'], $m['description']];
    foreach ($m['facts'] as $f) {
        $texts[] = $f['label'];
    }
    foreach ($m['questions'] as $q) {
        $texts[] = $q['question'];
        $texts[] = $q['help'] ?? 'x'; // help is optional
    }
    foreach ($m['audit'] as $r) {
        $texts[] = $r['message'];
    }

    return $texts;
};
$shippedKeys = array_keys($shippedBlueprints);
sort($shippedKeys);
check('2.11/3.3 Blueprint: the twenty industry blueprints are shipped', $shippedKeys, ['accommodation', 'agency', 'auto_service', 'beauty_wellness', 'clinic', 'craftsman', 'driving_school', 'farm', 'fitness_studio', 'it_services', 'manufacturer', 'municipality', 'nonprofit', 'photographer', 'professional_services', 'real_estate', 'restaurant', 'retail_shop', 'school_courses', 'software_saas']);
check('2.11 Blueprint: every shipped blueprint has presets, 4–8 facts, a question for each, 3–6 checks and instructions for Claude', array_values(array_filter(array_map(fn (array $m): string => $m['presets'] === [] || count($m['facts']) < 4 || count($m['facts']) > 8
    || count($m['questions']) < 1 || count(array_unique(array_column($m['questions'], 'fact'))) !== count($m['facts']) || count($m['audit']) < 3 || count($m['audit']) > 6 || mb_strlen($m['claude']) < 200 ? $m['key'] : '', $shippedBlueprints))), []);
check('2.11 Blueprint: every text of a shipped blueprint is a non-empty English string', array_values(array_filter(array_map(fn (array $m): string => array_filter($blueprintTexts($m),
    fn (string|array $t): bool => !is_string($t) || trim($t) === '') === [] ? '' : $m['key'], $shippedBlueprints))), []);
check('2.11 Blueprint: shipped blueprints define no built-in fact and give no fact a value', array_values(array_filter(array_map(fn (array $m): string => array_filter($m['facts'],
    fn (array $f): bool => isset(Talea\Core\Facts::BUILT_IN[$f['key']]) || isset($f['value'])) === [] ? '' : $m['key'], $shippedBlueprints))), []);
check('3.3 Blueprint: every shipped blueprint sits in a group of the Blueprints screen (not "other")', array_values(array_filter(array_map(fn (array $m): string => in_array($m['group'], ['', 'other'], true) || !isset(Blueprint::GROUPS[$m['group']]) ? $m['key'] : '', $shippedBlueprints))), []);
check('3.3 Blueprint::sanitize – the group is kept when known, "other" otherwise (a manifest from before 3.3 has none)', [
    Blueprint::sanitize(['group' => 'tech'] + $blueprintInput)[0]['group'] ?? null, Blueprint::sanitize(['group' => 'spaceships'] + $blueprintInput)[0]['group'] ?? null, Blueprint::sanitize($blueprintInput)[0]['group'] ?? null], ['tech', 'other', 'other']);
check('3.3 Prompt draft_blueprint: works without an argument, names the business when given, applies only after the owner agrees', [
    str_contains(Talea\Mcp\Prompts::get('draft_blueprint', [])['messages'][0]['content']['text'], 'kind of business. (1)'),
    str_contains(Talea\Mcp\Prompts::get('draft_blueprint', ['business' => 'a bike shop'])['messages'][0]['content']['text'], 'kind of business: a bike shop.'),
    str_contains(Talea\Mcp\Prompts::get('draft_blueprint', [])['messages'][0]['content']['text'], 'only after I agree')], [true, true, true]);
$plansPreset = (array) Talea\Builder\Presets::get('plans');
$plansFields = Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $plansPreset['fields']));
check('3.3.1 Presets: each card of the pricing plans has a button to the plan\'s link', str_contains((string) json_encode(Talea\Builder\Presets::listPage($plansPreset, 'Plans', 'plans', $plansFields)), '{{link}}'), true);
check('3.3 Presets: pricing plans, a food and drink menu, rooms and property listings are offered', array_values(array_diff(['plans', 'menu', 'rooms', 'properties'], array_keys(Talea\Builder\Presets::all()))), []);
/* ---------- 2.11: job openings – JobPosting (Builder\CollectionSchema), the application form of the preset, retention of applications (Core\Jobs) ---------- */
$jobsPreset = Talea\Builder\Presets::get('jobs');
$jobFields = Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $jobsPreset['fields']));
$jobCollection = fn (string $currency): array => ['slug' => 'jobs', 'detail' => 1, 'fields' => $jobFields, 'schema_org' => json_encode(['currency' => $currency] + $jobsPreset['schema'])];
$jobIssuer = ['@type' => 'LocalBusiness', '@id' => 'https://example.cz/#firma', 'name' => 'Web', 'legalName' => 'Truhlárna s.r.o.', 'url' => 'https://example.cz/', 'logo' => 'https://example.cz/media/logo.svg', // check-english: allow
    'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Brno', 'addressCountry' => 'CZ']];
$jobItem = ['name' => 'Truhlář', 'slug' => 'truhlar', 'created_at' => '2026-10-02 10:00:00', 'valid_until' => '2026-11-30', // check-english: allow
    'data' => ['location' => 'Brno', 'employment_type' => 'plný úvazek', 'salary_min' => '35 000', 'salary_max' => '45000', 'salary_unit' => 'per month', 'description' => '<p>Výroba <b>nábytku</b>.</p>']]; // check-english: allow
$posting = Talea\Builder\CollectionSchema::forItem($jobCollection('CZK'), $jobItem, 'https://example.cz/jobs/truhlar', 'meta description', '', $jobIssuer['@id'], $jobIssuer);
check('2.11 JobPosting: title, dates, the hiring organization from the company, the place with the company country, a salary range with the collection currency, recognised type and unit', $posting, [
    '@type' => 'JobPosting', 'title' => 'Truhlář', 'url' => 'https://example.cz/jobs/truhlar', 'description' => 'Výroba nábytku.', 'datePosted' => date('c', strtotime('2026-10-02 10:00:00')), 'validThrough' => '2026-11-30', // check-english: allow
    'employmentType' => 'FULL_TIME', 'hiringOrganization' => ['@type' => 'Organization', 'name' => 'Truhlárna s.r.o.', 'sameAs' => 'https://example.cz/', 'logo' => 'https://example.cz/media/logo.svg'], // check-english: allow
    'jobLocation' => ['@type' => 'Place', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Brno', 'addressCountry' => 'CZ']],
    'baseSalary' => ['@type' => 'MonetaryAmount', 'currency' => 'CZK', 'value' => ['@type' => 'QuantitativeValue', 'minValue' => 35000.0, 'maxValue' => 45000.0, 'unitText' => 'MONTH']]]);
$bareItem = ['name' => 'Svářeč', 'slug' => 'svarec', 'created_at' => '2026-10-02 10:00:00', 'valid_until' => null, 'data' => ['employment_type' => 'podle dohody', 'salary_min' => '', 'salary_max' => '']]; // check-english: allow
$bare = Talea\Builder\CollectionSchema::forItem($jobCollection('CZK'), $bareItem, 'https://example.cz/jobs/svarec', 'meta description', '', $jobIssuer['@id'], []);
check('2.11 JobPosting: what is missing is left out, never guessed – no validThrough, salary, place or organization; an unknown employment type stays the text; the meta description', $bare,
    ['@type' => 'JobPosting', 'title' => 'Svářeč', 'url' => 'https://example.cz/jobs/svarec', 'description' => 'meta description', 'datePosted' => date('c', strtotime('2026-10-02 10:00:00')), 'employmentType' => 'podle dohody']); // check-english: allow
$oneFigure = ['data' => ['salary_min' => '250', 'salary_unit' => 'za hodinu', 'employment_type' => 'Teilzeit']] + $jobItem;
check('2.11 JobPosting: a single salary figure is a value; without the collection currency no salary at all', [
    Talea\Builder\CollectionSchema::forItem($jobCollection('EUR'), $oneFigure, 'u', '', '', 'i', [])['baseSalary'], Talea\Builder\CollectionSchema::forItem($jobCollection('EUR'), $oneFigure, 'u', '', '', 'i', [])['employmentType'],
    isset(Talea\Builder\CollectionSchema::forItem($jobCollection(''), $jobItem, 'u', '', '', 'i', [])['baseSalary'])],
    [['@type' => 'MonetaryAmount', 'currency' => 'EUR', 'value' => ['@type' => 'QuantitativeValue', 'value' => 250.0, 'unitText' => 'HOUR']], 'PART_TIME', false]);
check('2.11 CollectionSchema::employmentType and salaryUnit in several languages; unknown = the text, resp. nothing', [
    array_map(Talea\Builder\CollectionSchema::employmentType(...), ['full-time', 'HPP', 'Vollzeit', 'pełny etat', 'part-time', 'zkrácený úvazek', 'niepełny etat', 'na IČO', 'freelance contract', 'brigáda', 'stáž', 'Internship', '', 'flexible']), // check-english: allow
    array_map(Talea\Builder\CollectionSchema::salaryUnit(...), ['per month', 'měsíčně', 'pro Monat', 'miesięcznie', 'per hour', 'za hodinu', 'pro Stunde', 'za rok', 'p. a.', 'týdně', 'per day', '', 'brutto'])], // check-english: allow
    [['FULL_TIME', 'FULL_TIME', 'FULL_TIME', 'FULL_TIME', 'PART_TIME', 'PART_TIME', 'PART_TIME', 'CONTRACTOR', 'CONTRACTOR', 'TEMPORARY', 'INTERN', 'INTERN', '', 'flexible'],
        ['MONTH', 'MONTH', 'MONTH', 'MONTH', 'HOUR', 'HOUR', 'HOUR', 'YEAR', 'YEAR', 'WEEK', 'DAY', '', '']]);
check('2.11 JobPosting: the other types are unchanged – a service still gets its offer', Talea\Builder\CollectionSchema::forItem(['slug' => 's', 'detail' => 1, 'fields' => [['key' => 'cena', 'label' => 'Cena', 'type' => 'number']],
    'schema_org' => '{"type":"Service","fields":{"price":"cena"},"currency":"EUR"}'], ['name' => 'Montáž', 'data' => ['cena' => '1200']], 'u', 'd', '', 'https://example.cz/#firma')['offers'], // check-english: allow
    ['@type' => 'Offer', 'price' => '1200', 'priceCurrency' => 'EUR', 'url' => 'u']);
// the item template the preset brings: the job text and the Job application form with a CV and the hidden job name
$findForm = function (array $nodes) use (&$findForm): ?array {
    foreach ($nodes as $n) {
        if (($n['type'] ?? '') === 'form') {
            return $n;
        }
        if (($found = $findForm($n['children'] ?? [])) !== null) {
            return $found;
        }
    }

    return null;
};
$jobForm = $findForm(Talea\Builder\Presets::itemTemplate($jobsPreset, $jobFields)['children']);
check('2.11 jobs preset: the item template has a Job application form – name, e-mail, phone, a required CV attachment, a message, consent and the hidden job name that survives sanitizing', [
    $jobForm['content']['name'] ?? null, array_column($jobForm['content']['fields'] ?? [], 'type'), $jobForm['content']['fields'][3]['required'] ?? null, $jobForm['content']['fields'][6]['value'] ?? null, $jobForm['content']['fields'][5]['required'] ?? null],
    [t('Job application'), ['text', 'email', 'tel', 'file', 'textarea', 'checkbox', 'hidden'], true, '{{name}}', true]); // t(): the dictionary of an earlier test is still set
check('2.11 jobs preset: JobPosting data, newest first, hidden jobs lead to the jobs page, the contact links to the team', [$jobsPreset['schema']['type'], $jobsPreset['list'], $jobsPreset['redirect_hidden'], $jobsPreset['fields'][8][3] ?? null, str_contains($jobsPreset['claude'], 'valid_until')],
    ['JobPosting', ['sort' => 'newest'], true, ['preset' => 'people'], true]);
// retention of applications: enquiries from a jobs collection (their source) older than the months
$jobSources = ['collection:5'];
$applications = [
    ['enquiry_id' => 1, 'source' => 'collection:5', 'created_at' => '2026-03-01 10:00:00'], // an old application
    ['enquiry_id' => 2, 'source' => 'collection:5', 'created_at' => '2026-09-01 10:00:00'], // a recent one
    ['enquiry_id' => 3, 'source' => 'page:7', 'created_at' => '2025-01-01 10:00:00'], // an old enquiry from a page – not an application
    ['enquiry_id' => 4, 'source' => 'collection:9', 'created_at' => '2025-01-01 10:00:00'], // an old enquiry from another collection's item page
    ['enquiry_id' => 5, 'source' => 'collection:5', 'created_at' => '2026-04-02 12:00:00'], // exactly six months – not older yet
];
check('2.11 Jobs::expiredApplications – only from a jobs collection and older than the months', array_column(Talea\Core\Jobs::expiredApplications($applications, $jobSources, 6, '2026-10-02 12:00:00'), 'enquiry_id'), [1]);
check('2.11 Jobs::expiredApplications – a shorter retention takes the one at the limit too; 0 months or no jobs collection = nothing', [
    array_column(Talea\Core\Jobs::expiredApplications($applications, $jobSources, 1, '2026-10-02 12:00:00'), 'enquiry_id'), Talea\Core\Jobs::expiredApplications($applications, $jobSources, 0, '2026-10-02 12:00:00'),
    Talea\Core\Jobs::expiredApplications($applications, [], 6, '2026-10-02 12:00:00')], [[1, 2, 5], [], []]);
check('2.11 Jobs::suggestedRetention – by the company country, then by the site language; unknown = no hint', [Talea\Core\Jobs::suggestedRetention('CZ', 'en'), Talea\Core\Jobs::suggestedRetention('', 'de'), Talea\Core\Jobs::suggestedRetention('at', ''),
    Talea\Core\Jobs::suggestedRetention('FR', 'fr'), Talea\Core\Jobs::suggestedRetention('', 'en')], [['CZ', 6], ['DE', 6], ['AT', 6], null, null]);
check('2.11: the audit kind, the event and the setting of job applications are known', [Talea\Core\Audit::KINDS['job'], isset(Talea\Core\Events::TYPES['applications.purged']), Talea\Core\Settings::DEFAULTS['job_applications_months'], Talea\Builder\CollectionSchema::TYPES['JobPosting'][0]],
    ['Job openings', true, '0', 'Job opening']);
/* ---------- 2.11: document library (Core\Documents) – version-change detection, the download token, file paths ---------- */
$documentsCollection = ['preset' => 'documents', 'detail' => 1, 'fields' => [['key' => 'file', 'label' => 'File', 'type' => 'file'], ['key' => 'version', 'label' => 'Version', 'type' => 'text']]];
check('2.11 Documents::replacedFile – a changed file keeps the previous file with its version', Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/cenik-v1.pdf', 'version' => '1.0'], ['file' => '/media/cenik-v2.pdf', 'version' => '2.0']), ['/media/cenik-v1.pdf', '1.0']);
check('2.11 Documents::replacedFile – the same file, a first file, a collection without the preset and a file field of another type keep nothing', [
    Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/a.pdf', 'version' => '1'], ['file' => '/media/a.pdf', 'version' => '2']),
    Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '', 'version' => ''], ['file' => '/media/a.pdf']),
    Talea\Core\Documents::replacedFile(['preset' => '', 'fields' => $documentsCollection['fields']], ['file' => '/media/a.pdf'], ['file' => '/media/b.pdf']),
    Talea\Core\Documents::replacedFile(['preset' => 'documents', 'fields' => [['key' => 'file', 'label' => 'File', 'type' => 'text']]], ['file' => '/media/a.pdf'], ['file' => '/media/b.pdf']),
], [null, null, null, null]);
check('2.11 Documents::replacedFile – a removed file is kept too; without a version field the version is empty', [
    Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/a.pdf', 'version' => '3'], ['file' => '']),
    Talea\Core\Documents::replacedFile(['preset' => 'documents', 'fields' => [$documentsCollection['fields'][0]]], ['file' => '/media/a.pdf', 'version' => '3'], ['file' => '/media/b.pdf'])], [['/media/a.pdf', '3'], ['/media/a.pdf', '']]);
$documentKey = str_repeat('k', 64);
$documentToken = Talea\Core\Documents::token($documentKey, '/media/docs/cenik-2026.pdf', 1_900_000_000);
$tamperedSignature = substr($documentToken, 0, -1) . (substr($documentToken, -1) === 'a' ? 'b' : 'a');
$otherFile = (string) preg_replace('/^(\d+)\.[^.]+/', '$1.' . rtrim(strtr(base64_encode('/media/other.pdf'), '+/', '-_'), '='), $documentToken);
check('2.11 Documents::token – a valid token gives its file; expired, a changed signature, a changed file, another key and nonsense are refused', [
    Talea\Core\Documents::verifyToken($documentKey, $documentToken, 1_899_999_999), Talea\Core\Documents::verifyToken($documentKey, $documentToken, 1_900_000_001),
    Talea\Core\Documents::verifyToken($documentKey, $tamperedSignature, 1_899_999_999), Talea\Core\Documents::verifyToken($documentKey, $otherFile, 1_899_999_999),
    Talea\Core\Documents::verifyToken(str_repeat('x', 64), $documentToken, 1_899_999_999), Talea\Core\Documents::verifyToken($documentKey, 'nonsense', 1_899_999_999),
], ['/media/docs/cenik-2026.pdf', null, null, null, null, null]);
check('2.11 Documents::token – a signed token still serves only files from Media or https', [
    Talea\Core\Documents::verifyToken($documentKey, Talea\Core\Documents::token($documentKey, '/config.php', 1_900_000_000), 1_899_999_999),
    Talea\Core\Documents::verifyToken($documentKey, Talea\Core\Documents::token($documentKey, '/media/../config.php', 1_900_000_000), 1_899_999_999),
    Talea\Core\Documents::verifyToken($documentKey, Talea\Core\Documents::token($documentKey, 'https://files.example/a.pdf', 1_900_000_000), 1_899_999_999),
    preg_match('/^\d{10}\.[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $documentToken)], [null, null, 'https://files.example/a.pdf', 1]);
$versionsHtml = Talea\Core\Documents::versionsHtml([['file' => 'media/docs/cenik-v1.pdf', 'version' => '1.0 <b>', 'replaced_at' => '2026-03-01 10:00:00', 'replaced_by' => 'x']], '/web');
check('2.11 Documents::versionsHtml – a heading, a link to the file with the installation folder, the version and the day, everything escaped; nothing without versions',
    [str_starts_with($versionsHtml, '<h2>'), str_contains($versionsHtml, 'href="/web/media/docs/cenik-v1.pdf"'), str_contains($versionsHtml, 'cenik-v1.pdf · '), str_contains($versionsHtml, '&lt;b&gt;'), str_contains($versionsHtml, '<b>'),
        str_contains($versionsHtml, format_date('2026-03-01 10:00:00')), Talea\Core\Documents::versionsHtml([], '/web')], [true, true, true, true, false, true, '']);
check('2.11 Documents::filePath and fileName – an https address and an absolute path stay, a path in Media gets the installation folder', [Talea\Core\Documents::filePath('https://x.example/a.pdf', '/web'), Talea\Core\Documents::filePath('/media/a.pdf', '/web'),
    Talea\Core\Documents::filePath('media/a.pdf', '/web'), Talea\Core\Documents::fileName('/media/docs/Cen%C3%ADk%202026.pdf')], ['https://x.example/a.pdf', '/media/a.pdf', '/web/media/a.pdf', 'Ceník 2026.pdf']); // check-english: allow
check('2.11 Documents::gatedFile – a file from Media, never a path out of it', [Talea\Core\Documents::gatedFile(['send_file' => '/media/x.pdf']), Talea\Core\Documents::gatedFile(['send_file' => '/media/../config.php']), Talea\Core\Documents::gatedFile([])], ['/media/x.pdf', '', '']);
$documentsTemplate = (string) json_encode(Talea\Builder\Presets::itemTemplate((array) Talea\Builder\Presets::get('documents'), Talea\Builder\Collections::sanitizeFields([['key' => 'file', 'label' => 'File', 'type' => 'file']])));
check('2.11 documents preset: the item template downloads through the stable address and lists the previous versions; the kind, the address and the English option name are known',
    [str_contains($documentsTemplate, '{{latest}}'), str_contains($documentsTemplate, '{{versions}}'), Talea\Core\Audit::KINDS['document'], in_array('download', Talea\Admin\Modules\Pages::RESERVED_SLUGS, true), 'send_file'],
    [true, true, 'Document expires soon', true, 'send_file']);
/* ---------- 2.11: branches (LocalBusiness) and the Store locator element ---------- */
check('2.11 Hours::specification – opening hours as text to OpeningHoursSpecification; nothing from a line that does not parse or from an empty text', [
    Talea\Core\Hours::specification("Mo-Fr 9-17\nSa 9:00-12:00"), Talea\Core\Hours::specification("Mo-Fr 9-17\nby appointment"), Talea\Core\Hours::specification('')], [
    [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '17:00'],
        ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '09:00', 'closes' => '12:00']], [], []]);
$branchFields = [['key' => 'address', 'label' => 'Address', 'type' => 'text'], ['key' => 'location', 'label' => 'Location', 'type' => 'location'], ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
    ['key' => 'email', 'label' => 'E-mail', 'type' => 'text'], ['key' => 'hours', 'label' => 'Opening hours', 'type' => 'lines']];
$branchCollection = ['slug' => 'pobocky', 'detail' => 1, 'fields' => $branchFields,
    'schema_org' => json_encode(['type' => 'LocalBusiness', 'fields' => ['address' => 'address', 'telephone' => 'phone', 'email' => 'email', 'geo' => 'location', 'openingHours' => 'hours']])];
$branch = fn (array $data): ?array => Talea\Builder\CollectionSchema::forItem($branchCollection, ['name' => 'Brno', 'data' => $data], 'https://example.com/pobocky/brno', 'Our Brno store', '', 'https://example.com#firma');
$brnoNode = $branch(['address' => 'Náměstí Svobody 1, Brno', 'location' => '49.1951, 16.6068', 'phone' => '+420 123 456 789', 'email' => 'brno@example.com', 'hours' => 'Mo-Fr 9-17']); // check-english: allow
check('2.11 CollectionSchema: a branch is a LocalBusiness of the company with its address, contacts, geo and opening hours', [
    $brnoNode['@type'], $brnoNode['address'], $brnoNode['telephone'], $brnoNode['geo'], count($brnoNode['openingHoursSpecification']), $brnoNode['parentOrganization'], isset($brnoNode['provider'])],
    ['LocalBusiness', 'Náměstí Svobody 1, Brno', '+420 123 456 789', ['@type' => 'GeoCoordinates', 'latitude' => 49.1951, 'longitude' => 16.6068], 1, ['@id' => 'https://example.com#firma'], false]); // check-english: allow
$sparseNode = $branch(['address' => 'Somewhere 1', 'location' => 'in the centre', 'hours' => 'always open']);
check('2.11 CollectionSchema: no geo from text that is not a location and no hours that do not parse – rather left out than guessed', [isset($sparseNode['geo']), isset($sparseNode['openingHoursSpecification']), $sparseNode['address']], [false, false, 'Somewhere 1']);
check('2.11 CollectionSchema::geo', [Talea\Builder\CollectionSchema::geo('50.0875;14.4214'), Talea\Builder\CollectionSchema::geo(''), Talea\Builder\CollectionSchema::geo('Praha')],
    [['@type' => 'GeoCoordinates', 'latitude' => 50.0875, 'longitude' => 14.4214], null, null]);
check('2.11 Presets: branches – LocalBusiness mapped to the location and hours fields, created before the team', [
    $presets['branches']['schema']['type'], $presets['branches']['schema']['fields']['geo'], $presets['branches']['schema']['fields']['openingHours'], $presets['branches']['order'] < 100,
    Talea\Builder\Presets::field(['preset' => 'branches', 'fields' => [['key' => 'location', 'type' => 'location']]], 'branches', 'location', ['location'])], ['LocalBusiness', 'location', 'hours', true, 'location']);
$branchTemplate = Talea\Builder\Presets::itemTemplate($presets['branches'], Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], $presets['branches']['fields'])));
$branchTemplateJson = Talea\Builder\Build::toJson($branchTemplate);
check('2.11 Presets: the branch item template shows the photo, contacts, hours and a click-to-load map of the address', [
    str_contains($branchTemplateJson, '{{photo}}'), str_contains($branchTemplateJson, '<p>{{hours}}</p>'), str_contains($branchTemplateJson, '"type":"map"'), str_contains($branchTemplateJson, '"address":"{{address}}"')], [true, true, true, true]);
check('2.11 StoreLocator::telHref – digits and one leading plus, nothing from text', array_map(Talea\Builder\Elements\StoreLocator::telHref(...), ['+420 123 456 789', '(0049) 30 / 123-45', 'call us', '']),
    ['tel:+420123456789', 'tel:00493012345', '', '']);
check('2.11 StoreLocator::coordinates and directionsUrl – the address first, then the coordinates, otherwise nothing', [
    Talea\Builder\Elements\StoreLocator::coordinates('49.1951, 16.6068'), Talea\Builder\Elements\StoreLocator::coordinates('nowhere'),
    Talea\Builder\Elements\StoreLocator::directionsUrl('Náměstí Svobody 1, Brno', ['49.1951', '16.6068']), Talea\Builder\Elements\StoreLocator::directionsUrl('', ['49.1951', '16.6068']), Talea\Builder\Elements\StoreLocator::directionsUrl('', null)], // check-english: allow
    [['49.1951', '16.6068'], null, 'https://www.google.com/maps/search/?api=1&query=N%C3%A1m%C4%9Bst%C3%AD%20Svobody%201%2C%20Brno', 'https://www.google.com/maps/search/?api=1&query=49.1951%2C16.6068', '']);
check('2.11 Store locator: a Dynamic element with an English name, its texts for the script in the site dictionaries, Leaflet vendored', [
    Talea\Builder\Elements\StoreLocator::GROUP, 'store_locator', 'location_field',
    isset((require TALEA_SYSTEM . '/languages/cs.php')['Nearest to me']), isset((require TALEA_SYSTEM . '/languages/de.php')['Location access was refused – the list stays in its usual order.']),
    is_file(TALEA_ROOT . '/image/vendor/leaflet/leaflet.js') && is_file(TALEA_ROOT . '/image/vendor/leaflet/leaflet.css') && is_file(TALEA_ROOT . '/image/vendor/leaflet/images/marker-icon.png') && is_file(TALEA_ROOT . '/image/vendor/leaflet/LICENSE'),
    preg_match('/Leaflet 1\.9\.4/', (string) file_get_contents(TALEA_ROOT . '/image/vendor/leaflet/leaflet.js')) === 1],
    ['Dynamic', 'store_locator', 'location_field', true, true, true, true]);
/* ---------- 2.11 F1: six more ready-made collections ---------- */
$f1Presets = Talea\Builder\Presets::all();
check('2.11 presets: services, references, price_list, faq, machines and courses in order after people, with their pages and structured data', array_map(fn (string $k): array => [
    $f1Presets[$k]['order'], (bool) $f1Presets[$k]['detail'], (string) ($f1Presets[$k]['schema']['type'] ?? ''), is_callable($f1Presets[$k]['template'])], ['services', 'references', 'price_list', 'faq', 'machines', 'courses']),
    [[20, true, 'Service', true], [30, true, '', true], [40, false, '', false], [50, false, 'FAQPage', false], [70, true, 'Product', true], [80, true, 'Event', true]]);
check('2.11 presets: the lists – a price list and FAQ filter by category, courses show the upcoming ones by start and end sorted by the start', [
    $f1Presets['price_list']['list'], $f1Presets['faq']['list']['filter_field'], $f1Presets['courses']['list']],
    [['sort' => 'order', 'filter_field' => 'category', 'filters' => true], 'category', ['period' => 'upcoming', 'period_start_field' => 'start', 'period_end_field' => 'end', 'sort' => 'field', 'sort_field' => 'start']]);
check('2.11 presets: a reference links to the services preset, the schema maps price_from, sku and the event dates', [$f1Presets['references']['fields'][5][3]['preset'], $f1Presets['services']['schema']['fields'],
    $f1Presets['machines']['schema']['fields'], $f1Presets['courses']['schema']['fields']], ['services', ['price' => 'price_from'], ['sku' => 'model'], ['startDate' => 'start', 'endDate' => 'end', 'location' => 'place', 'price' => 'price']]);
$f1Fields = fn (string $k): array => Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]] + (isset($f[3]['preset']) ? ['kolekce' => 'sluzby'] : []), $f1Presets[$k]['fields'])); // check-english: allow
$f1Template = fn (string $k): string => Talea\Builder\Build::toJson(Talea\Builder\Presets::itemTemplate($f1Presets[$k], $f1Fields($k)));
check('2.11 presets: the item templates survive sanitizing and show the fields', [
    str_contains($f1Template('services'), '{{summary}}') && str_contains($f1Template('services'), '{{price_from}} {{price_note}}'),
    str_contains($f1Template('references'), '{{service_url}}') && str_contains($f1Template('references'), '"link":"{{link}}"'),
    str_contains($f1Template('machines'), '{{datasheet_name}}') && str_contains($f1Template('machines'), '{{parameters}}'),
    str_contains($f1Template('courses'), '<strong>{{when}}</strong>') && str_contains($f1Template('courses'), '{{capacity}}') && str_contains($f1Template('courses'), '"type":"form"')], [true, true, true, true]);
$f1List = Talea\Builder\Build::toJson(Talea\Builder\Presets::listPage($f1Presets['courses'], 'Kurzy', 'kurzy', $f1Fields('courses')));
check('2.11 presets: the list page of courses lists the upcoming ones with the start and the place on the card', [str_contains($f1List, '"period":"upcoming"'), str_contains($f1List, '"sort_field":"start"'), str_contains($f1List, '<p>{{start}}</p>'), str_contains($f1List, '<p>{{place}}</p>')], [true, true, true, true]);

/* ---------- 2.11 F1: screen mode (Front\Screen) ---------- */
check('2.11 Screen::seconds – within 5–60, empty = 10', array_map(Talea\Front\Screen::seconds(...), ['', '3', '90', '20', 0]), [10, 5, 60, 20, 10]);
$f1Secret = str_repeat('ab', 16);
check('2.11 Screen::opens – only on, with a secret, and the right one', [Talea\Front\Screen::opens(true, $f1Secret, $f1Secret), Talea\Front\Screen::opens(false, $f1Secret, $f1Secret), Talea\Front\Screen::opens(true, $f1Secret, str_repeat('ba', 16)),
    Talea\Front\Screen::opens(true, '', ''), Talea\Front\Screen::opens(true, $f1Secret, 'ABAB')], [true, false, false, false, false]);
check('2.11 Screen::dateFields – the first date-and-time field is the start, the second the end', [Talea\Front\Screen::dateFields([['key' => 'place', 'type' => 'text'], ['key' => 'start', 'type' => 'datetime'], ['key' => 'end', 'type' => 'datetime']]),
    Talea\Front\Screen::dateFields([['key' => 'when', 'type' => 'datetime']]), Talea\Front\Screen::dateFields([['key' => 'day', 'type' => 'date']])], [['start', 'end'], ['when', ''], null]);
$f1Course = ['name' => 'Kurzy', 'preset' => 'courses', 'fields' => [['key' => 'start', 'label' => 'Start', 'type' => 'datetime'], ['key' => 'end', 'label' => 'End', 'type' => 'datetime'], ['key' => 'place', 'label' => 'Place', 'type' => 'text'],
    ['key' => 'price', 'label' => 'Price', 'type' => 'number'], ['key' => 'description', 'label' => 'Description', 'type' => 'html'], ['key' => 'image', 'label' => 'Image', 'type' => 'image']]];
$f1Slide = Talea\Front\Screen::card($f1Course, ['name' => ['Welding basics', 'text'], 'start' => ['2. 11. 2026 09:00', 'text'], 'end' => ['2. 11. 2026 16:00', 'text'], 'place' => ['Brno', 'text'], 'price' => ['1900', 'number'],
    'description' => ['<p>Long</p>', 'html'], 'image' => ['media/2026/kurz.jpg', 'image']]);
check('2.11 Screen::card – a preset collection shows its card fields: the start as the date, the place as a line, the first image', [$f1Slide['kind'], $f1Slide['label'], $f1Slide['title'], $f1Slide['date'], $f1Slide['lines'], $f1Slide['text'], $f1Slide['image']],
    ['item', 'Kurzy', 'Welding basics', '2. 11. 2026 09:00', ['Brno'], '', 'media/2026/kurz.jpg']);
$f1Plain = ['name' => 'Stroje', 'preset' => '', 'fields' => [['key' => 'photo', 'label' => 'Photo', 'type' => 'image'], ['key' => 'model', 'label' => 'Model', 'type' => 'text'], ['key' => 'weight', 'label' => 'Weight', 'type' => 'number'],
    ['key' => 'about', 'label' => 'About', 'type' => 'lines'], ['key' => 'sheet', 'label' => 'Sheet', 'type' => 'file'], ['key' => 'extra', 'label' => 'Extra', 'type' => 'text']]];
$f1Slide = Talea\Front\Screen::card($f1Plain, ['name' => ['CNC', 'text'], 'model' => ['X-200', 'text'], 'weight' => ['1200', 'number'], 'about' => [str_repeat('word ', 80), 'lines'], 'sheet' => ['/media/x.pdf', 'link'], 'extra' => ['not shown', 'text']]);
check('2.11 Screen::card – without a preset the first three short fields: a number with its label, a longer text shortened, the rest left out', [$f1Slide['lines'], mb_strlen($f1Slide['text']) <= 240, str_ends_with($f1Slide['text'], '…'), $f1Slide['image']],
    [['X-200', 'Weight: 1200'], true, true, '']);
check('2.11 Screen::plain – formatted text as one line', Talea\Front\Screen::plain("<p>Open&nbsp;day</p>\n<ul><li>at  9</li></ul>"), 'Open day at 9');
check('2.11 screen: the reserved address, the settings and their export without the secret', [in_array('screen', Talea\Admin\Modules\Pages::RESERVED_SLUGS, true), Talea\Core\Settings::DEFAULTS['screen_seconds'], Talea\Core\Settings::DEFAULTS['screen_mode'],
    in_array('screen_collections', Talea\Core\SiteExport::SETTINGS, true), in_array('screen_secret', Talea\Core\SiteExport::SETTINGS, true)], [true, '10', '0', true, false]);
/* ---------- 2.11: official notice board (Core\Notices) ---------- */
$board = ['collection_id' => 7, 'preset' => 'notices', 'slug' => 'deska', 'detail' => 1,
    'fields' => Talea\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['key' => $f[0], 'label' => $f[1], 'type' => $f[2]], Talea\Builder\Presets::get('notices')['fields']))];
check('2.11 Notices: the preset has the dates, the archive page and its own item template; the board is recognised by preset and field', [
    array_column(Talea\Builder\Presets::get('notices')['fields'], 0), Talea\Builder\Presets::get('notices')['extra_pages'][0]['suffix'], Talea\Builder\Presets::get('notices')['extra_pages'][0]['list']['period'],
    is_callable(Talea\Builder\Presets::get('notices')['template']), Talea\Core\Notices::isBoard($board), Talea\Core\Notices::isBoard(['preset' => 'people', 'fields' => $board['fields']]),
    Talea\Core\Notices::isNotices(['preset' => 'notices', 'fields' => []]), Talea\Core\Notices::isBoard(['preset' => 'notices', 'fields' => []])],
    [['posted', 'taken_down', 'reference', 'issuer', 'category', 'document', 'summary'], 'archive', 'past', true, true, false, true, false]);
check('2.11 Notices::status – to be posted, on the board (the takedown day still counts), archived the day after, no dates', [
    Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-02'), Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-03'), Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-18'),
    Talea\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-19'), Talea\Core\Notices::status('2026-10-03', '', '2027-01-01'), Talea\Core\Notices::status('', '', '2026-10-02')],
    ['upcoming', 'current', 'current', 'archived', 'current', '']);
check('2.11 Notices::statusText – the sentence for visitors with the site\'s date format', [
    Talea\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-10'), Talea\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-19'), Talea\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-01'),
    Talea\Core\Notices::statusText('2026-10-03', '', '2026-10-10'), Talea\Core\Notices::statusText('', '', '2026-10-10')],
    [t('Posted from %s to %s', format_date('2026-10-03'), format_date('2026-10-18')), t('Taken down on %s – archived', format_date('2026-10-18')), t('To be posted on %s', format_date('2026-10-03')), t('Posted from %s', format_date('2026-10-03')), '']);
$noticeItem = ['name' => 'Záměr', 'slug' => 'zamer', 'created_at' => '2026-10-02 10:00:00', 'data' => ['posted' => '2026-10-03', 'taken_down' => '2026-10-18', 'reference' => 'MU/1', 'document' => '/media/zamer.pdf']]; // check-english: allow
check('2.11 {{notice_status}} only for a notice board – the people preset has none', [isset(Talea\Builder\Collections::values($board, $noticeItem, fn (string $p): string => '/' . $p)['notice_status']),
    isset(Talea\Builder\Collections::values(['preset' => 'people', 'slug' => 'lide', 'detail' => 1, 'fields' => $board['fields']], $noticeItem, fn (string $p): string => '/' . $p)['notice_status'])], [true, false]);
check('2.11 Notices::canHide – only a notice still to be posted (or without a date) may be hidden', [Talea\Core\Notices::canHide('2026-10-03', '2026-10-02'), Talea\Core\Notices::canHide('2026-10-02', '2026-10-02'), Talea\Core\Notices::canHide('2026-09-01', '2026-10-02'), Talea\Core\Notices::canHide('', '2026-10-02')],
    [true, false, false, true]);
$noticeRows = [
    ['item_id' => 1, 'visible' => true, 'posted' => '2026-10-01', 'taken_down' => '2026-10-18'],  // on the board: posted today
    ['item_id' => 2, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-10-01'],  // taken down yesterday: both in one run
    ['item_id' => 3, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-10-02'],  // the takedown day itself still counts
    ['item_id' => 4, 'visible' => false, 'posted' => '2026-09-01', 'taken_down' => ''],          // hidden – never posted
    ['item_id' => 5, 'visible' => true, 'posted' => '2026-10-03', 'taken_down' => ''],           // to be posted tomorrow
    ['item_id' => 6, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-09-20'], // already recorded, both
    ['item_id' => 7, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-09-20'], // posted recorded, the takedown not yet
];
check('2.11 Notices::due – posted when the day comes (visible only), taken_down the day after, each once', Talea\Core\Notices::due($noticeRows, [6 => ['posted' => true, 'taken_down' => true], 7 => ['posted' => true]], '2026-10-02'),
    [[1, 'posted', '2026-10-01'], [2, 'posted', '2026-09-01'], [2, 'taken_down', '2026-10-01'], [3, 'posted', '2026-09-01'], [7, 'taken_down', '2026-09-20']]);
check('2.11 Notices::due – a second run adds nothing', Talea\Core\Notices::due($noticeRows, [1 => ['posted' => true], 2 => ['posted' => true, 'taken_down' => true], 3 => ['posted' => true], 6 => ['posted' => true, 'taken_down' => true], 7 => ['posted' => true, 'taken_down' => true]], '2026-10-02'), []);
$noticePrevious = ['item_id' => 5, 'name' => 'Rozpočet', 'slug' => 'rozpocet', 'visible' => 1, 'data' => '{"posted":"2026-10-01","taken_down":"","reference":"MU/12","issuer":"","category":"","document":"","summary":""}']; // check-english: allow
check('2.11 Notices::changes – a new notice lists its values, a change only what differs, no change nothing', [
    Talea\Core\Notices::changes($board, null, ['name' => 'Rozpočet', 'slug' => 'rozpocet', 'visible' => 1, 'data' => '{"posted":"2026-10-01","taken_down":"","reference":"MU/12"}']), // check-english: allow
    Talea\Core\Notices::changes($board, $noticePrevious, ['name' => 'Rozpočet 2026', 'data' => '{"posted":"2026-10-01","taken_down":"2026-10-20","reference":"MU/12","issuer":"","category":"","document":"","summary":""}', 'updated_at' => 'x']), // check-english: allow
    Talea\Core\Notices::changes($board, $noticePrevious, ['name' => 'Rozpočet', 'slug' => 'rozpocet', 'data' => $noticePrevious['data']])], // check-english: allow
    [['name' => ['', 'Rozpočet'], 'slug' => ['', 'rozpocet'], 'visible' => ['', 'yes'], 'posted' => ['', '2026-10-01'], 'reference' => ['', 'MU/12']], // check-english: allow
        ['name' => ['Rozpočet', 'Rozpočet 2026'], 'taken_down' => ['', '2026-10-20']], []]); // check-english: allow
check('2.11 Notices::changesText and the job are known', [Talea\Core\Notices::changesText(['posted' => ['', '2026-10-03'], 'name' => ['A', 'B'], 'taken_down' => '2026-10-18']),
    Talea\Core\Scheduler::JOBS['notices'][0], Talea\Core\Scheduler::JOBS['notices'][1], isset(Talea\Core\Scheduler::jobs()['notices'])], ['posted: → 2026-10-03; name: A → B; taken_down: 2026-10-18', 3600, 'any', true]);
/* ---------- 2.12: calls and e-mail clicks counted as conversions (Core\Conversions) ---------- */
$clickType = Talea\Core\Conversions::type(...);
$clickPath = Talea\Core\Conversions::path(...);
check('2.12 Conversions::type – tel, mailto and whatsapp only, case and spaces forgiven, anything else is not counted',
    [$clickType('tel'), $clickType(' MAILTO '), $clickType('whatsapp'), $clickType('fax'), $clickType(''), $clickType('tel:+420'), $clickType('click_phone')], ['tel', 'mailto', 'whatsapp', null, null, null, null]);
check('2.12 Conversions::path – an absolute path without the query string and the fragment, at most 255 characters; anything else is a forged request',
    [$clickPath('/kontakt'), $clickPath('/kontakt?utm_source=x#telefon'), $clickPath(' /en/contact '), $clickPath('/'), $clickPath('kontakt'), $clickPath('https://example.com/kontakt'), $clickPath('/kon takt'), $clickPath("/a\nb"), $clickPath(''),
        $clickPath('/' . str_repeat('a', 254)), $clickPath('/' . str_repeat('a', 255))],
    ['/kontakt', '/kontakt', '/en/contact', '/', null, null, null, null, null, '/' . str_repeat('a', 254), null]);
$clickLink = fn (string $href): int => preg_match(Talea\Core\Conversions::LINK_PATTERN, '<p><a href="' . $href . '">x</a></p>');
check('2.12 Conversions::LINK_PATTERN – the links the script counts (tel:, mailto:, wa.me, api.whatsapp.com, whatsapp:), not an ordinary link; KEYS name every type',
    [$clickLink('tel:+420123456789'), $clickLink('mailto:info@example.com'), $clickLink('https://wa.me/420123456789'), $clickLink('https://api.whatsapp.com/send?phone=1'), $clickLink('whatsapp://send?phone=1'), $clickLink('https://example.com/tel:'), $clickLink('/kontakt'),
        array_keys(Talea\Core\Conversions::KEYS), in_array('conversion', Talea\Admin\Modules\Pages::RESERVED_SLUGS, true)],
    [1, 1, 1, 1, 1, 0, 0, Talea\Core\Conversions::TYPES, true]);
check('2.12 Conversions::total – every type together, missing keys are zero', [Talea\Core\Conversions::total(['calls' => 2, 'emails' => 1, 'whatsapp' => 4]), Talea\Core\Conversions::total(['path' => '/x', 'calls' => 1]), Talea\Core\Conversions::total([])], [7, 1, 0]);

/* ---------- 2.12: enquiry triage (Core\Triage) ---------- */
use Talea\Core\Triage;

check('2.12 Triage::clean – a known kind and priority (number or word), a plain-text reply; anything else is left unchanged', [
    Triage::clean('sales', 3, "Dobrý den,\r\n<b>děkujeme</b>."), Triage::clean('hack', 9, null), Triage::clean('spam', 'low', str_repeat('x', 6000))['suggested_reply'] !== null ? mb_strlen((string) Triage::clean('spam', 'low', str_repeat('x', 6000))['suggested_reply']) : 0, // check-english: allow
    Triage::clean(null, 'high', null)['priority']],
    [['category' => 'sales', 'priority' => 3, 'suggested_reply' => "Dobrý den,\nděkujeme."], ['category' => null, 'priority' => null, 'suggested_reply' => null], 5000, 3]); // check-english: allow
check('2.12 Triage::rule – an application from a job opening is a job; anything else is left to Claude or the assistant', [Triage::rule(['source' => 'collection:7'], ['collection:7']), Triage::rule(['source' => 'page:3'], ['collection:7'])],
    [['category' => 'job', 'priority' => 2, 'suggested_reply' => null], null]);
check('2.12 Triage::text – the form, the page, what it was about and every field; never the attachment path', Triage::text(['form' => 'Kontakt', 'page' => '/kontakt', 'topic' => 'Služby – Koupelny', // check-english: allow
    'data' => json_encode([['Jméno', 'Eva'], ['Životopis', 'cv.pdf (20 kB)', '2026/10/abc.pdf']])]), "Form: Kontakt\nPage: /kontakt\nAbout: Služby – Koupelny\nJméno: Eva\nŽivotopis: cv.pdf (20 kB)"); // check-english: allow
check('2.12 Triage: the background job is known and a machine never overwrites a person', [Talea\Core\Scheduler::JOBS['triage'][0], in_array('claude', Triage::MACHINES, true), in_array('Jana', Triage::MACHINES, true)], [300, true, false]);

/* ---------- 2.12: multi-step forms, conditions and the price estimate (Builder\Elements\Form) ---------- */
use Talea\Builder\Elements\Form as FormElement;

$calcFields = [
    ['label' => 'Typ', 'type' => 'radio', 'choices' => "Okna | 1200\nDveře | 9 900\nPoradenství"], // check-english: allow
    ['label' => 'Počet', 'type' => 'number', 'unit_price' => '1 500'], // check-english: allow
    ['label' => 'Doplňky', 'type' => 'checkboxes', 'checkbox_options' => "Montáž | 2000\nOdvoz | 500"], // check-english: allow
    ['label' => 'Barva dveří', 'type' => 'select', 'options' => "Bílá\nDub | 3000", 'show_when_field' => 'Typ', 'show_when_value' => 'Dveře'], // check-english: allow
    ['label' => 'Odstín', 'type' => 'text', 'show_when_field' => 'Barva dveří', 'show_when_value' => '*'], // check-english: allow
    ['label' => 'Krok 2', 'type' => 'step'],
    ['label' => 'Odhad', 'type' => 'estimate', 'base_price' => '500', 'currency' => 'Kč'], // check-english: allow
];
check('2.12 Form::optionPrices – "Label | price" lines, the visitor sees and sends only the label', [FormElement::optionPrices($calcFields[0]), FormElement::options($calcFields[2])],
    [['Okna' => 1200.0, 'Dveře' => 9900.0, 'Poradenství' => 0.0], ['Montáž', 'Odvoz']]); // check-english: allow
$calcAnswers = [0 => 'Okna', 1 => '4', 2 => ['Montáž'], 3 => 'Dub', 4 => 'tmavý']; // check-english: allow
$calcVisible = FormElement::visible($calcFields, $calcAnswers);
check('2.12 Form::visible – a condition on another answer, a chain of conditions, a missing field hides it', [$calcVisible[3], $calcVisible[4], $calcVisible[0],
    FormElement::visible($calcFields, [0 => 'Dveře', 3 => 'Dub'])[4], FormElement::visible([['label' => 'A', 'type' => 'text', 'show_when_field' => 'Nikde', 'show_when_value' => 'x']], [])[0]], // check-english: allow
    [false, false, true, true, false]);
check('2.12 Form::estimate – the base, chosen and ticked options and number × unit price, only of shown fields', [FormElement::estimate($calcFields, $calcAnswers, $calcVisible, 500.0),
    FormElement::estimate($calcFields, [0 => 'Dveře', 1 => '', 2 => [], 3 => 'Dub'], FormElement::visible($calcFields, [0 => 'Dveře', 3 => 'Dub']), 0.0)], [500.0 + 1200 + 4 * 1500 + 2000, 9900.0 + 3000]); // check-english: allow
check('2.12 Form::money – whole amounts without decimals, the currency after a no-break space', [FormElement::money(9700.0, 'Kč'), FormElement::money(12.5, ''), FormElement::price('1 200,50')], // check-english: allow
    [format_count(9700) . "\u{a0}Kč", format_count(12.5, 2), 1200.5]); // check-english: allow

/* ---------- 2.12: testimonial requests with consent (Core\Testimonials) ---------- */
use Talea\Core\Testimonials;

check('2.12 Testimonials::clean – words, a name and the consent to publish them are required; tags never get through', [
    Testimonials::clean(['text' => 'Skvělá spolupráce, <b>doporučuji</b>.', 'name' => ' Eva ', 'role' => "ředitelka\nACME", 'consent_words' => '1']), // check-english: allow
    Testimonials::clean(['text' => 'krátce', 'name' => 'Eva', 'consent_words' => '1']) === t('Please write a few words.'), // check-english: allow
    Testimonials::clean(['text' => 'Skvělá spolupráce s firmou.', 'name' => 'Eva']) === t('We can publish your words only with your consent.'), // check-english: allow
    Testimonials::clean(['text' => 'Skvělá spolupráce s firmou.', 'name' => '', 'consent_words' => '1']) === t('Please fill in your name.')], // check-english: allow
    [['text' => 'Skvělá spolupráce, doporučuji.', 'name' => 'Eva', 'role' => 'ředitelka ACME', 'words' => true, 'photo' => false], true, true, true]); // check-english: allow
check('2.12 Testimonials: the link is 32 hex characters and a valid-looking but unknown one finds nothing without the database', [Testimonials::DAYS, Testimonials::PRESET, (new ReflectionMethod(Testimonials::class, 'find'))->getNumberOfParameters()], [30, 'references', 2]);
/* ---------- 2.12: share images drawn by the site (Front\ShareImage) ---------- */
$ogChars = fn (string $s): int => mb_strlen($s) * 10; // a stand-in for GD: every character 10 px wide
check('2.12 ShareImage::wrap – words fill the line, a word wider than the line is broken by characters, no text = one empty line', [
    Talea\Front\ShareImage::wrap('Dřevěné schody na míru', $ogChars, 150), Talea\Front\ShareImage::wrap('Nejneobhospodařovávatelnějšími a', $ogChars, 100), Talea\Front\ShareImage::wrap('', $ogChars, 100)], // check-english: allow
    [['Dřevěné schody', 'na míru'], ['Nejneobhos', 'podařováva', 'telnějšími', 'a'], ['']]); // check-english: allow
$ogWidth = fn (string $s, int $size): int => (int) round(mb_strlen($s) * $size * 0.6); // 0.6 em per character
check('2.12 ShareImage::fit – a short title at the largest size, a long one goes down until three lines hold it', [
    Talea\Front\ShareImage::fit("Kontakt \n", $ogWidth, 1040, 3, 60, 34), Talea\Front\ShareImage::fit(str_repeat('slovo ', 16), $ogWidth, 1040, 3, 60, 34)],
    [[60, ['Kontakt']], [48, ['slovo slovo slovo slovo slovo slovo', 'slovo slovo slovo slovo slovo slovo', 'slovo slovo slovo slovo']]]); // check-english: allow
[$ogSize, $ogLines] = Talea\Front\ShareImage::fit(str_repeat('slovo ', 60), $ogWidth, 1040, 3, 60, 34);
check('2.12 ShareImage::fit – what the smallest size cannot hold is cut, the last line ends with an ellipsis', [$ogSize, count($ogLines), str_ends_with($ogLines[2], 'slovo…'), $ogWidth($ogLines[2], 34) <= 1040], [34, 3, true, true]);
$ogBrief = ['v' => 1, 'title' => 'Kontakt', 'site' => 'Firma', 'colors' => ['#2b5be3', '#ffffff', '#16181d'], 'logo' => '', 'logo_time' => 0]; // check-english: allow
$ogHash = Talea\Front\ShareImage::hash($ogBrief, 'key-a');
check('2.12 ShareImage::hash – 32 hex characters; the same brief gives the same address, another title, colour or key a different one', [
    preg_match('/^[a-f0-9]{32}$/', $ogHash), $ogHash === Talea\Front\ShareImage::hash($ogBrief, 'key-a'), $ogHash === Talea\Front\ShareImage::hash(array_replace($ogBrief, ['title' => 'Kontakty']), 'key-a'),
    $ogHash === Talea\Front\ShareImage::hash(array_replace($ogBrief, ['colors' => ['#000000', '#ffffff', '#16181d']]), 'key-a'), $ogHash === Talea\Front\ShareImage::hash($ogBrief, 'key-b')], [1, true, false, false, false]);
check('2.12 ShareImage::matches – the site\'s own hash passes; a tampered, malformed or foreign-key one never draws', [
    Talea\Front\ShareImage::matches($ogHash, $ogBrief, 'key-a'), Talea\Front\ShareImage::matches(strrev($ogHash), $ogBrief, 'key-a'), Talea\Front\ShareImage::matches(substr($ogHash, 1) . '0', $ogBrief, 'key-a'),
    Talea\Front\ShareImage::matches('../' . $ogHash, $ogBrief, 'key-a'), Talea\Front\ShareImage::matches($ogHash, $ogBrief, 'key-b')], [true, false, false, false, false]);
check('2.12 share images: on by default, exported with the site, /og reserved, the Open Graph size', [Talea\Core\Settings::DEFAULTS['share_image_auto'], in_array('share_image_auto', Talea\Core\SiteExport::SETTINGS, true),
    in_array('og', Talea\Admin\Modules\Pages::RESERVED_SLUGS, true), Talea\Front\ShareImage::WIDTH . '×' . Talea\Front\ShareImage::HEIGHT], ['1', true, true, '1200×630']);

/* ---------- 2.12: forms that know where they are, thank-you with next steps ---------- */
check('2.12 EnquiryTopic::itemSlug – the item from the address of its page, with a language prefix or a query; a list page or another collection is none', [
    Talea\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby/koupelna'), Talea\Front\EnquiryTopic::itemSlug('sluzby', '/en/sluzby/koupelna/?form=x'), Talea\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby'),
    Talea\Front\EnquiryTopic::itemSlug('sluzby', '/jine/koupelna'), Talea\Front\EnquiryTopic::itemSlug('', '/a/b'), Talea\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby/Koupelna%20X')],
    ['koupelna', 'koupelna', null, null, null, null]);
check('2.12 EnquiryTopic::compose – "collection – item", a page alone, trimmed and cut to the column', [
    Talea\Front\EnquiryTopic::compose('Služby', 'Rekonstrukce koupelny'), Talea\Front\EnquiryTopic::compose(' Kontakt '), Talea\Front\EnquiryTopic::compose('', 'Okno'), mb_strlen(Talea\Front\EnquiryTopic::compose(str_repeat('a', 200), str_repeat('b', 200)))], // check-english: allow
    ['Služby – Rekonstrukce koupelny', 'Kontakt', 'Okno', 255]); // check-english: allow
$nsWeek = Talea\Front\NextSteps::DEFAULT_WEEK;
$nsLunch = array_fill_keys(Talea\Core\Hours::DAYS, []);
foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $nsDay) { $nsLunch[$nsDay] = [['08:00', '12:00'], ['13:00', '17:00']]; }
$nsHoliday = ['id' => 1, 'from' => '2026-10-12', 'to' => '2026-10-12', 'closed' => true, 'hours' => '', 'note' => 'Holiday', 'notice_days' => 0];
$nsShort = ['id' => 2, 'from' => '2026-10-13', 'to' => '2026-10-13', 'closed' => false, 'hours' => '9-10', 'note' => '', 'notice_days' => 0];
$nsDeadline = fn (array $week, array $ex, string $from, int $hours): string => Talea\Front\NextSteps::deadline($week, $ex, new DateTimeImmutable($from), $hours)->format('Y-m-d H:i');
check('2.12 NextSteps::deadline – Friday 16:00 + 4 working hours with Mo–Fr 8–17 is Monday 11:00; a holiday on Monday moves it to Tuesday', [
    $nsDeadline($nsWeek, [], '2026-10-09 16:00', 4), $nsDeadline($nsWeek, [$nsHoliday], '2026-10-09 16:00', 4)], ['2026-10-12 11:00', '2026-10-13 11:00']);
check('2.12 NextSteps::deadline – within the day, over the lunch break, sent before opening or on a weekend, shorter hours of an exception, two working days', [
    $nsDeadline($nsWeek, [], '2026-10-05 09:00', 2), $nsDeadline($nsLunch, [], '2026-10-05 11:30', 2), $nsDeadline($nsWeek, [], '2026-10-05 06:00', 1), $nsDeadline($nsWeek, [], '2026-10-10 10:00', 1),
    $nsDeadline($nsWeek, [$nsHoliday, $nsShort], '2026-10-09 16:30', 2), $nsDeadline($nsWeek, [], '2026-10-05 10:00', 18)],
    ['2026-10-05 11:00', '2026-10-05 14:30', '2026-10-05 09:00', '2026-10-12 09:00', '2026-10-14 08:30', '2026-10-07 10:00']);
check('2.12 NextSteps::deadline – no open day at all: plain hours', $nsDeadline(array_fill_keys(Talea\Core\Hours::DAYS, []), [], '2026-10-09 16:00', 4), '2026-10-09 20:00');
$nsNow = new DateTimeImmutable('2026-10-05 09:00');
check('2.12 NextSteps::deadlineText – today, tomorrow, a weekday within the week, further away with the date', [
    Talea\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-05 16:00'), $nsNow), Talea\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-06 08:30'), $nsNow),
    Talea\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-08 11:00'), $nsNow), Talea\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-19 11:00'), $nsNow)],
    [t('We will reply today by %s.', '16:00'), t('We will reply tomorrow by %s.', '8:30'), t('We will reply %s by %s.', t('on Thursday'), '11:00'), t('We will reply by %s.', format_date('2026-10-19 11:00', true))]);
check('2.12 NextSteps::steps – one step per line, empty lines dropped; the content keys have English names', [Talea\Front\NextSteps::steps(['next_steps' => "Zavoláme vám\r\n\n  Přijedeme na zaměření \n"]), Talea\Front\NextSteps::steps([]), // check-english: allow
    'next_steps', 'reply_within_hours', 'who_replies'],
    [['Zavoláme vám', 'Přijedeme na zaměření'], [], 'next_steps', 'reply_within_hours', 'who_replies']); // check-english: allow

/* ---------- 2.12: pricing table, before and after, hotspots, timeline ---------- */
$f9App = (new ReflectionClass(Talea\Core\App::class))->newInstanceWithoutConstructor();
$f9Context = new Talea\Builder\Context($f9App);
$f9Editor = new Talea\Builder\Context($f9App, true);
[$f9Build, $f9Errors] = Talea\Builder\Build::sanitize(['children' => [
    ['type' => 'pricing_table', 'id' => 'cen1', 'content' => ['plans' => [
        ['name' => 'Basic', 'price' => '9', 'period' => '/ month', 'description' => 'Start', 'features' => "One\n- Two\n  \n-", 'button_text' => 'Choose', 'link' => 'javascript:alert(1)', 'highlighted' => false, 'badge' => 'Most popular'],
        ['name' => 'Pro', 'price' => '29', 'features' => 'One', 'button_text' => 'Choose', 'link' => '/order', 'highlighted' => true, 'badge' => 'Most popular'],
        ['name' => '', 'price' => '0'],
    ]]],
    ['type' => 'hotspots', 'id' => 'hs1', 'content' => ['src' => '/media/2026/plan.jpg', 'alt' => 'Plan', 'points' => [['x' => 250, 'y' => -5, 'name' => 'Entrance', 'description' => "Line 1\nLine & 2"], ['x' => 20, 'y' => 30, 'name' => '', 'description' => 'hidden']]]],
    ['type' => 'before_after', 'id' => 'pp1', 'content' => ['before_image' => '/media/2026/a.jpg', 'after_image' => 'http://x.cz/b.jpg', 'divider_position' => 130]],
    ['type' => 'timeline', 'id' => 'to1', 'content' => ['milestones' => [['date' => '2020', 'name' => 'Start', 'content' => '<p onclick="x">Text</p>'], ['date' => '', 'name' => '', 'content' => '<p>skip</p>'], ['date' => 'Today', 'name' => '', 'content' => '']]]],
]]);
[$f9Pricing, $f9Hotspots, $f9BeforeAfter, $f9Timeline] = $f9Build['children'];
check('2.12 sanitize: a plan link is checked like a button, the hotspot position is clamped to 0–100, the divider too, timeline HTML is cleaned, item defaults filled in', [
    $f9Pricing['content']['plans'][0]['link'], $f9Pricing['content']['plans'][1]['link'], $f9Pricing['content']['plans'][1]['period'], array_keys($f9Errors),
    $f9Hotspots['content']['points'][0]['x'], $f9Hotspots['content']['points'][0]['y'], $f9BeforeAfter['content']['divider_position'], $f9BeforeAfter['content']['after_image'], $f9Timeline['content']['milestones'][0]['content'], $f9Timeline['content']['milestones'][0]['src']],
    ['', '/order', '', ['children[0].content.plans.link', 'children[2].content.after_image'], 100, 0, 100, '', '<p>Text</p>', '']);
check('2.12 PricingTable::features – a line starting with "-" is not included, blank lines and a bare dash are skipped', Talea\Builder\Elements\PricingTable::features("One\n- Two\n  \n-\n -Three "), [[true, 'One'], [false, 'Two'], [false, 'Three']]);
$f9Html = Talea\Builder\Elements\PricingTable::render($f9Pricing, '', '', $f9Context);
check('2.12 pricing table: two cards (an unnamed plan is left out), the highlighted one with its class, label and primary button, the other with an outline button, an excluded feature crossed out with a text for screen readers, a discarded link falls back to #', [
    substr_count($f9Html, '<article class="tl-pricing-plan'), substr_count($f9Html, 'tl-pricing-plan--highlighted'), substr_count($f9Html, '<p class="tl-pricing-badge">Most popular</p>'),
    str_contains($f9Html, '<li class="tl-pricing-no"><span class="tl-pricing-sr">' . t('Not included:') . ' </span>Two</li>'), str_contains($f9Html, '<li><span class="tl-pricing-sr">' . t('Included:') . ' </span>One</li>'),
    str_contains($f9Html, '<a class="tl-button tl-button--outline" href="#">Choose</a>'), str_contains($f9Html, '<a class="tl-button tl-button--primary" href="/order">Choose</a>'),
    str_contains($f9Html, '<p class="tl-pricing-price"><strong>9</strong> <span>/ month</span></p>'), str_contains($f9Html, '<p class="tl-pricing-price"><strong>29</strong></p>'), isset($f9Context->types['button']), str_starts_with($f9Html, '<div class="tl-pricing">')],
    [2, 1, 1, true, true, true, true, true, true, true, true]);
$f9Html = Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Context);
check('2.12 before and after: without the after image nothing for visitors, a notice in the editor', [$f9Html, str_contains(Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Editor), t('Choose the before and after images in the Content panel.'))], ['', true]);
$f9BeforeAfter['content']['after_image'] = '/media/2026/b.jpg';
$f9Html = Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Context);
check('2.12 before and after: two figures with their labels, the range control with the divider position, the data hook for web.js, enabled only in the editor', [
    substr_count($f9Html, '<figure class="tl-before-after-'), substr_count($f9Html, '<figcaption>'), str_contains($f9Html, '<figure class="tl-before-after-before"><img src="/media/2026/a.jpg" alt="" loading="lazy"><figcaption>' . t('Before') . '</figcaption></figure>'),
    str_contains($f9Html, '<input type="range" class="tl-before-after-handle" min="0" max="100" value="100" aria-label="' . t('Compare before and after') . '">'),
    str_starts_with($f9Html, '<div class="tl-before-after" data-before-after style="--tl-split:100%">'), str_contains(Talea\Builder\Elements\BeforeAfter::render($f9BeforeAfter, '', '', $f9Editor), ' data-before-after data-enabled ')],
    [2, 2, true, true, true, true]);
$f9Html = Talea\Builder\Elements\Hotspots::render($f9Hotspots, '', '', $f9Context);
check('2.12 hotspots: one point (the unnamed one is left out) as a details popover placed at the clamped position, opening to the left, the number hidden from screen readers and the label read instead, the text with line breaks escaped, and the list under the image', [
    substr_count($f9Html, '<details'), str_contains($f9Html, '<details class="tl-hotspots-point tl-hotspots-point--left" name="hs-hs1" style="--x:100%;--y:0%">'),
    str_contains($f9Html, '<summary><span aria-hidden="true">1</span><span class="tl-hotspots-sr">Entrance</span></summary>'),
    str_contains($f9Html, '<div class="tl-hotspots-description"><strong>Entrance</strong><p>Line 1<br />' . "\n" . 'Line &amp; 2</p></div>'),
    str_contains($f9Html, '<ol class="tl-hotspots-list"><li><strong>Entrance</strong> – Line 1<br />'), str_contains($f9Html, '<img src="/media/2026/plan.jpg" alt="Plan" loading="lazy">')],
    [1, true, true, true, true, true]);
$f9Hotspots['content']['points'][0] = ['x' => 10, 'y' => 90, 'name' => 'Low', 'description' => ''];
check('2.12 hotspots: a point low down opens upwards, a point on the left opens to the right; without a text only the label', [
    str_contains(Talea\Builder\Elements\Hotspots::render($f9Hotspots, '', '', $f9Context), '<details class="tl-hotspots-point tl-hotspots-point--up" name="hs-hs1" style="--x:10%;--y:90%"><summary><span aria-hidden="true">1</span><span class="tl-hotspots-sr">Low</span></summary><div class="tl-hotspots-description"><strong>Low</strong></div></details>'),
    Talea\Builder\Elements\Hotspots::render(['id' => 'hs2', 'content' => ['src' => '', 'alt' => '', 'points' => []]], '', '', $f9Context)], [true, '']);
$f9Html = Talea\Builder\Elements\Timeline::render($f9Timeline, '', '', $f9Context);
check('2.12 timeline: an ordered list, an item without a date and title is left out, a date alone stays, the date, heading and cleaned text in the card', [
    str_starts_with($f9Html, '<ol class="tl-timeline">'), substr_count($f9Html, '<li class="tl-timeline-item">'),
    str_contains($f9Html, '<li class="tl-timeline-item"><div class="tl-timeline-card"><span class="tl-timeline-date">2020</span><h3>Start</h3><p>Text</p></div></li>'),
    str_contains($f9Html, '<span class="tl-timeline-date">Today</span></div></li></ol>'), Talea\Builder\Elements\Timeline::render(['id' => 'to2', 'content' => ['milestones' => []]], '', '', $f9Context)], [true, 2, true, true, '']);
$f9Text = Talea\Builder\Build::asText($f9Build);
check('2.12 Build::asText – plans with prices and features, points and milestones are page content for search and the .md version', [
    str_contains($f9Text, "<h3>Basic</h3><p>9 / month</p><p>Start</p><ul><li>One</li><li>Two (" . t('not included') . ")</li></ul>\n<h3>Pro</h3><p>29</p><ul><li>One</li></ul>"),
    str_contains($f9Text, '<ol><li>Entrance – Line 1' . "\n" . 'Line &amp; 2</li></ol>'), str_contains($f9Text, "<h3>2020 – Start</h3><p>Text</p>\n<h3>Today</h3>")], [true, true, true]);
$f9Schema = array_column(Talea\Builder\Build::schema(true, 'en')['elements'], null, 'type');
check('2.12 schema: the four elements are content blocks with a sensible default in the page language, the before-and-after hook cannot be a custom attribute', [
    array_map(fn (string $t): string => $f9Schema[$t]['group'], ['pricing_table', 'before_after', 'hotspots', 'timeline']), array_column($f9Schema['pricing_table']['properties']['plans']['default'], 'name'), $f9Schema['pricing_table']['properties']['plans']['default'][1]['highlighted'],
    $f9Schema['before_after']['properties']['before_label']['default'], count($f9Schema['hotspots']['properties']['points']['default']), $f9Schema['timeline']['properties']['milestones']['default'][2]['date'],
    preg_match(Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-before-after'), preg_match('/data-\(insert\|[^)]*before-after/', (string) file_get_contents(TALEA_SYSTEM . '/src/Front/Kernel.php'))],
    [['Content', 'Content', 'Content', 'Content'], ['Basic', 'Standard', 'Premium'], true, 'Before', 2, 'Today', 0, 1]);

/* ---------- 2.13: outbound connectors (Core\Connectors) ---------- */
check('2.13 Connectors: the curated list, an unknown service is none', [Talea\Core\Connectors::service('google'), Talea\Core\Connectors::service('evil')], [Talea\Connectors\Google::class, null]);
putenv('TALEA_CONNECTORS_FAKE=http://127.0.0.1:9');
$fakeUrls = [Talea\Core\Connectors::url('https://oauth2.googleapis.com/token'), Talea\Core\Connectors::url('https://sheets.googleapis.com/v4/spreadsheets?x=1'), Talea\Core\Connectors::url('http://example.com/a')];
putenv('TALEA_CONNECTORS_FAKE');
check('2.13 Connectors::url – tests send every https call to the fake (path and query kept); without it the real address', [$fakeUrls, Talea\Core\Connectors::url('https://oauth2.googleapis.com/token')],
    [['http://127.0.0.1:9/token', 'http://127.0.0.1:9/v4/spreadsheets?x=1', 'http://example.com/a'], 'https://oauth2.googleapis.com/token']);
check('2.13 Connectors: Google asks only for its listed scopes, offline, and the delivery queue retries five times', [count(Talea\Connectors\Google::SCOPES), Talea\Connectors\Google::AUTH, count(Talea\Core\Connectors::RETRY_DELAYS), Talea\Core\Scheduler::JOBS['connectors'][0]],
    [5, 'oauth', 5, 0]);

/* ---------- 2.14: self-healing internal links (Core\LinkHealing) ---------- */
$lh = fn (string $u, string $to = 'nove'): ?string => Talea\Core\LinkHealing::rewrite($u, 'stare', $to, 'https://example.com');
check('2.14 LinkHealing::rewrite – own paths in every form, nothing else', [
    $lh('/stare'), $lh('/stare/'), $lh('/en/stare#kontakt'), $lh('/stare?x=1'), $lh('https://example.com/stare'), $lh('/stare', ''), $lh('/stare#a', 'https://other.org/x/'),
    $lh('/stare-cenik'), $lh('/stare/podstranka'), $lh('https://other.org/stare'), $lh('stare'), $lh('/zz/stare'), $lh('/x/stare')],
    ['/nove', '/nove/', '/en/nove#kontakt', '/nove?x=1', 'https://example.com/nove', '/', 'https://other.org/x#a', null, null, null, null, null, null]);
check('2.14 LinkHealing::html – only href attributes, the rest and entities kept', [
    Talea\Core\LinkHealing::html('<p><a href="/stare?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href=\'/stare\'>x</a> /stare</p>', 'stare', 'nove')],
    ['<p><a href="/nove?a=1&amp;b=2">Old</a> <img src="/stare"> <a class="x" href=\'/nove\'>x</a> /stare</p>']);
$lhJson = '{"type":"button","content":{"link":"\\/stare","text":"stare"},"children":[{"content":{"html":"<a href=\\"\\/stare\\">a<\\/a>"}}],"prazdne":[]}';
check('2.14 LinkHealing::json – link values and HTML in any key; text that only mentions it and unchanged JSON stay byte for byte', [
    json_decode(Talea\Core\LinkHealing::json($lhJson, 'stare', 'nove'), true), Talea\Core\LinkHealing::json('{"a":"\/jine"}', 'stare', 'nove'), Talea\Core\LinkHealing::json('neplatne', 'stare', 'nove')],
    [['type' => 'button', 'content' => ['link' => '/nove', 'text' => 'stare'], 'children' => [['content' => ['html' => '<a href="/nove">a</a>']]], 'prazdne' => []], '{"a":"\/jine"}', 'neplatne']);
/* ---------- 2.14: content hygiene (Core\MediaHygiene, Core\ContentCheck, Core\Translations) ---------- */
$f16Rows = [
    ['media_id' => 1, 'image_path' => 'media/2026/01/foto-aa11bb.jpg', 'thumb_path' => 'media/2026/01/foto-aa11bb-nahled.jpg'],
    ['media_id' => 2, 'image_path' => 'media/2026/01/logo-cc22dd.svg', 'thumb_path' => 'media/2026/01/logo-cc22dd.svg'],
    ['media_id' => 3, 'image_path' => 'media/2026/02/cenik-ee33ff.pdf', 'thumb_path' => ''],
    ['media_id' => 4, 'image_path' => 'media/2026/02/hala-0011aa.png', 'thumb_path' => 'media/2026/02/hala-0011aa-nahled.png'],
    ['media_id' => 5, 'image_path' => 'media/2026/03/tym-2233bb.webp', 'thumb_path' => 'media/2026/03/tym-2233bb-nahled.webp'],
];
$f16Content = '{"src":"media\/2026\/01\/foto-aa11bb-1200.jpg"} <a href="https://example.com/media/2026/02/cenik-ee33ff.pdf">PDF</a> <img src="/media/2026/02/hala-0011aa.png.webp"> media/2026/02/hala-0011aa-nahled.png';
check('2.14 MediaHygiene::paths – JSON escapes, the site URL and the -1200, -nahled, .webp variants all point at the original file', Talea\Core\MediaHygiene::paths($f16Content),
    ['media/2026/01/foto-aa11bb.jpg', 'media/2026/02/cenik-ee33ff.pdf', 'media/2026/02/hala-0011aa.png']);
check('2.14 MediaHygiene::unused – the referenced files and the one a news item uses stay, the rest is unused', array_column(Talea\Core\MediaHygiene::unused($f16Rows, Talea\Core\MediaHygiene::paths($f16Content), [5]), 'media_id'), [2]);
check('2.14 MediaHygiene::unused – the thumbnail path counts as a reference; a .webp upload keeps its name', [array_column(Talea\Core\MediaHygiene::unused($f16Rows, ['media/2026/03/tym-2233bb-nahled.webp']), 'media_id'),
    Talea\Core\MediaHygiene::paths('media/2026/03/tym-2233bb.webp')], [[1, 2, 3, 4], ['media/2026/03/tym-2233bb.webp']]);
check('2.14 MediaHygiene::duplicates – groups of the same hash with two or more files, oldest first, files without a hash left out', Talea\Core\MediaHygiene::duplicates([
    ['media_id' => 9, 'sha1' => 'b'], ['media_id' => 3, 'sha1' => 'a'], ['media_id' => 7, 'sha1' => 'a'], ['media_id' => 4, 'sha1' => 'c'], ['media_id' => 5, 'sha1' => 'b'], ['media_id' => 6, 'sha1' => ''], ['media_id' => 1, 'sha1' => 'a']]),
    [[['media_id' => 1, 'sha1' => 'a'], ['media_id' => 3, 'sha1' => 'a'], ['media_id' => 7, 'sha1' => 'a']], [['media_id' => 5, 'sha1' => 'b'], ['media_id' => 9, 'sha1' => 'b']]]);
check('2.14 MediaHygiene::isOversized – over 2 MB or wider than 2560 px, never an SVG or an attachment', array_map([Talea\Core\MediaHygiene::class, 'isOversized'], [
    ['image_path' => 'media/2026/01/a.jpg', 'thumb_path' => 'media/2026/01/a-nahled.jpg', 'image_size' => 2 * 1024 * 1024 + 1, 'image_width' => 1600],
    ['image_path' => 'media/2026/01/b.jpg', 'thumb_path' => 'media/2026/01/b-nahled.jpg', 'image_size' => 900_000, 'image_width' => 4000],
    ['image_path' => 'media/2026/01/c.jpg', 'thumb_path' => 'media/2026/01/c-nahled.jpg', 'image_size' => 900_000, 'image_width' => 2000],
    ['image_path' => 'media/2026/01/d.svg', 'thumb_path' => 'media/2026/01/d.svg', 'image_size' => 3_000_000, 'image_width' => 5000],
    ['image_path' => 'media/2026/01/e.pdf', 'thumb_path' => '', 'image_size' => 30_000_000, 'image_width' => 0]]), [true, true, false, false, false]);
$f16Check = fn (array $input): array => array_column(Talea\Core\ContentCheck::run($input), 'ok', 'check');
check('2.14 ContentCheck – a good text page: the title is the H1, H2 follows, the keyword from the title is in the description and the first paragraph, images described', $f16Check([
    'title' => 'Kuchyně na míru pro rodinné domy v Brně', 'description' => 'Navrhujeme a vyrábíme kuchyně na míru pro rodinné domy v Brně a okolí – od zaměření po montáž, se zárukou pěti let.', // check-english: allow
    'html' => '<p>Kuchyně na míru stavíme už dvacet let.</p><h2>Jak pracujeme</h2><p>Text</p><h3>Zaměření</h3><img src="/media/a.jpg" alt="Kuchyň">', 'title_is_h1' => true]), // check-english: allow
    ['title_length' => true, 'description_length' => true, 'single_h1' => true, 'heading_order' => true, 'keyword' => true, 'images_alt' => true]);
check('2.14 ContentCheck – a short title, no description, two H1s, a skipped level, the keyword missing, an image without alt', [$f16Check([
    'title' => 'Kuchyně', 'description' => '', 'html' => '<h1>Nabídka</h1><p>Vyrábíme nábytek.</p><h3>Skok</h3><img src="/media/a.jpg"><img src="/media/b.jpg" alt="">', 'title_is_h1' => true]), // check-english: allow
    array_column(Talea\Core\ContentCheck::run(['title' => 'Kuchyně', 'description' => '', 'html' => '<h1>Nabídka</h1><h3>Skok</h3>', 'title_is_h1' => true]), 'message', 'check')['heading_order']], // check-english: allow
    [['title_length' => false, 'description_length' => false, 'single_h1' => false, 'heading_order' => false, 'keyword' => false, 'images_alt' => false],
        t('Headings skip a level (%s) – screen readers and search engines read the outline.', 'H1 → H3')]);
check('2.14 ContentCheck – a build page has its own H1 (none = a warning), a too long title and description are warnings, the keyword search ignores case and diacritics', $f16Check([
    'title' => str_repeat('Dlouhý titulek ', 5), 'description' => str_repeat('Popis stránky pro vyhledávače. ', 6), // check-english: allow
    'html' => '<h2>DLOUHY titulek bez diakritiky</h2><p>dlouhy TITULEK v odstavci</p>', 'title_is_h1' => false]), // check-english: allow
    ['title_length' => false, 'description_length' => false, 'single_h1' => false, 'heading_order' => true, 'keyword' => false, 'images_alt' => true]);
check('2.14 ContentCheck::forPage – a text page is judged with its title as the H1, a build page by its draft', [
    array_column(Talea\Core\ContentCheck::forPage(['title' => 'O nás', 'seo_title' => '', 'description' => '', 'text' => '<h2>Tým</h2>', 'build' => null, 'build_draft' => null]), 'ok', 'check')['single_h1'], // check-english: allow
    array_column(Talea\Core\ContentCheck::forPage(['title' => 'O nás', 'seo_title' => '', 'description' => '', 'text' => '', 'build' => null, // check-english: allow
        'build_draft' => Talea\Builder\Build::toJson(Talea\Builder\Build::fromText('Náš tým', '<p>Lidé.</p>'))]), 'ok', 'check')['single_h1']], [true, true]); // check-english: allow
check('2.14 Translations::status – missing, present, outdated (the original changed after the translation was saved); a never-changed row counts by its date', [
    Talea\Core\Translations::status(null, '2026-01-10 10:00:00'), Talea\Core\Translations::status(['updated_at' => '2026-01-11 10:00:00'], '2026-01-10 10:00:00'),
    Talea\Core\Translations::status(['updated_at' => '2026-01-09 10:00:00'], '2026-01-10 10:00:00'), Talea\Core\Translations::status(['updated_at' => null, 'created_at' => '2026-01-09 10:00:00'], '2026-01-10 10:00:00'),
    Talea\Core\Translations::status(['updated_at' => '2026-01-09 10:00:00'], null)], ['missing', 'present', 'outdated', 'outdated', 'present']);
check('2.14 MCP: the two read-only tools are in the catalog as reads', [Talea\Mcp\Catalog::access('list_media_without_alt'), Talea\Mcp\Catalog::access('translation_status'), Talea\Mcp\Tools::annotations('translation_status')['readOnlyHint']], ['read', 'read', true]);
/* ---------- 2.14: EU duties as templates (Core\Privacy) ---------- */
$f17Embeds = ['{"type":"video","content":{"url":"https://www.youtube.com/watch?v=abc123","title":""}}', '<p>Text without any embed</p>', '{"type":"map","content":{"address":"Praha"}}'];
check('2.14 Privacy::providersIn – a YouTube video in a build, Analytics and Tag Manager from the settings, the CAPTCHA from its setting; a map element alone is not Google Maps (it embeds after a click)', [
    Talea\Core\Privacy::providersIn($f17Embeds, ['ga4_id' => 'G-ABCD1234', 'gtm_id' => 'GTM-XYZ12', 'captcha_provider' => 'turnstile']),
    Talea\Core\Privacy::providersIn(['<iframe src="https://maps.google.com/maps?q=Praha&output=embed"></iframe>', '<script>fbq("init", "1")</script>'], ['matomo_url' => 'https://stats.example.com/']),
    Talea\Core\Privacy::providersIn(['<p>nothing</p>'], ['ga4_id' => '', 'captcha_provider' => ''])],
    [['youtube', 'google-analytics', 'google-tag-manager', 'turnstile'], ['google-maps', 'matomo', 'facebook'], []]);
$f17Rows = [];
foreach (Talea\Core\Privacy::providersIn($f17Embeds, []) as $f17Key) {
    foreach (Talea\Core\Privacy::KNOWN[$f17Key][2] as $f17Cookie) {
        $f17Rows[] = $f17Cookie[0] . ':' . $f17Cookie[3];
    }
}
check('2.14 Privacy: the YouTube embed maps to its cookies with the marketing category; every known cookie has a known category', [$f17Rows,
    array_values(array_unique(array_filter(array_merge(...array_values(array_map(fn (array $p): array => array_column($p[2], 3), Talea\Core\Privacy::KNOWN))), fn (string $c): bool => !isset(Talea\Core\Privacy::CATEGORIES[$c]))))],
    [['VISITOR_INFO1_LIVE:marketing', 'YSC:marketing', 'PREF:marketing'], []]);
check('2.14 Privacy::anonymiseData – labels stay, values go, the attachment entry is left out', Talea\Core\Privacy::anonymiseData([['Jméno', 'Jan Novák'], ['Email', 'jan@example.com'], ['Telefon', '+420 777 123 456'], ['Zpráva', 'Dobrý den, …'], ['CV', 'cv.pdf (120 kB)', '2026/10/abcdefabcdefabcdefabcdef.pdf']]), // check-english: allow
    [['Jméno', ''], ['Email', ''], ['Telefon', ''], ['Zpráva', '']]); // check-english: allow
$f17Md = Talea\Core\Privacy::markdown([['heading' => 'Forms', 'lines' => ['Form “Contact”: fields Name (text), Email (e-mail)']]], 'Record of processing');
check('2.14 Privacy::markdown – a title, the template notice and the sections as lists; the scheduler job and the settings are known', [str_starts_with($f17Md, '# Record of processing'), str_contains($f17Md, "## Forms\n\n- Form “Contact”: fields Name (text), Email (e-mail)"), str_contains($f17Md, t('Generated on %s from the site’s configuration. A template to review and complete – not legal advice.', date('j. n. Y'))),
    Talea\Core\Scheduler::JOBS['cookie_scan'], isset(Talea\Core\Scheduler::jobs()['cookie_scan']), Talea\Core\Settings::DEFAULTS['enquiries_expiry'], Talea\Core\Settings::DEFAULTS['accessibility_toolbar'],
    Talea\Mcp\Catalog::TOOLS['processing_record'], Talea\Mcp\Catalog::TOOLS['accessibility_statement']],
    [true, true, true, [86400, 'cron', 'What cookies the site sets'], true, 'delete', '0', ['read', ''], ['read', '']]);
/* ---------- 2.14: links that look after themselves (Core\RedirectMatcher, Core\Links, Core\InternalLinks) ---------- */
$f15Score = fn (string $missing, string $candidate): int => Talea\Core\RedirectMatcher::score($missing, $candidate, ['cs', 'en']);
check('2.14 RedirectMatcher: the same address written differently scores 100', [$f15Score('/O-nas/', 'o-nas'), $f15Score('o-nas.html', 'o-nas'), $f15Score('/kontakt/index.php', 'kontakt')], [100, 100, 100]); // check-english: allow
check('2.14 RedirectMatcher: an old prefix or a moved language prefix scores 95', [$f15Score('novinky/moje-novinka', 'news/moje-novinka'), $f15Score('/blog/clanek-x', 'novinky/clanek-x'), $f15Score('en/kontakt', 'kontakt'), $f15Score('kontakt', 'en/kontakt')], [95, 95, 95, 95]); // check-english: allow
check('2.14 RedirectMatcher: the same last segment elsewhere scores 90, a similar slug below', [$f15Score('sluzby/weby', 'weby'), $f15Score('weby', 'sluzby/weby'), $f15Score('kontakty', 'kontakt') < 90 && $f15Score('kontakty', 'kontakt') >= Talea\Core\RedirectMatcher::MIN_SCORE, // check-english: allow
    $f15Score('cenik-2019', 'cenik') < 90 && $f15Score('cenik-2019', 'cenik') >= Talea\Core\RedirectMatcher::MIN_SCORE], [90, 90, true, true]);
check('2.14 RedirectMatcher: random paths, bot probes and unrelated pages do not match', [$f15Score('asdkjh-qwe', 'kontakt'), $f15Score('wp-content/uploads/x.jpg', 'kontakt'), $f15Score('o-nas', 'o-firme'), $f15Score('xy', 'xyz'), $f15Score('', 'kontakt')], [0, 0, 0, 0, 0]); // check-english: allow
$f15Build = ['v' => 1, 'children' => [['id' => 'e1', 'type' => 'section', 'children' => [['id' => 'e2', 'type' => 'button', 'content' => ['text' => 'Go', 'link' => 'http://127.0.0.1:1/dead']],
    ['id' => 'e3', 'type' => 'text', 'content' => ['html' => '<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="{{fact.web}}">f</a></p>']],
    ['id' => 'e4', 'type' => 'pricing_table', 'content' => ['plans' => [['name' => 'A', 'link' => '/kontakt'], ['name' => 'B', 'link' => '#']]]]]]]];
check('2.14 Links::collect: links of a page build with their element ids, texts, nested plans; tokens, anchors and mailto left out', Talea\Core\Links::collect('page', ['build' => json_encode($f15Build), 'text' => '']),
    [['http://127.0.0.1:1/dead', 'e2'], ['https://example.com/a?x=1&y=2', 'e3'], ['/kontakt', 'e4']]);
check('2.14 Links::collect: a text page and a collection item (link fields and links in texts)', [Talea\Core\Links::collect('page', ['build' => null, 'text' => '<a href="https://example.com/">x</a>']),
    Talea\Core\Links::collect('item', ['data' => json_encode(['web' => 'https://example.org/firma', 'description' => '<p><a href="https://example.com/b">b</a></p>', 'cislo' => 5])])],
    [[['https://example.com/', '']], [['https://example.org/firma', ''], ['https://example.com/b', '']]]);
check('2.14 Links::hint: the archived copy only as a suggestion for outside addresses', [str_contains(Talea\Core\Links::hint('https://example.com/x', 404), 'https://web.archive.org/web/2020/https://example.com/x'), str_contains(Talea\Core\Links::hint('/news/stara', 404), 'no longer exists')], [true, true]);
check('2.14 Links::isPublic: the test seam is off without the environment variable', Talea\Core\Links::isPublic('http://127.0.0.1:1/x'), false);
check('2.14 InternalLinks::words: title words for the term overlap – lower case, no diacritics, four letters or more', Talea\Core\InternalLinks::words('Reference &amp; portfolio: Zateplení domů v Brně'), ['reference', 'portfolio', 'zatepleni', 'domu', 'brne']); // check-english: allow
check('2.14: the redirects job runs daily, redirect.auto is a known event, orphan is an audit kind', [Talea\Core\Scheduler::JOBS['redirects'][0], isset(Talea\Core\Events::TYPES['redirect.auto']), isset(Talea\Core\Audit::KINDS['orphan'])], [86400, true, true]);
$keyedSettings = static function (string $key): Talea\Core\Settings {
    $s = (new ReflectionClass(Talea\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Talea\Core\Settings::class, 'values'))->setValue($s, ['secret_key' => $key]);
    (new ReflectionProperty(Talea\Core\Settings::class, 'db'))->setValue($s, new Talea\Core\Db('mysql:host=127.0.0.1;dbname=none', '', '')); // never connects: the key is set

    return $s;
};
/* ---------- 2.13: Search Console and Bing data (Core\SearchData) ---------- */
check('2.13 Bing: a token service whose key goes in the query, never in a header; the daily job search_data', [Talea\Core\Connectors::service('bing'), Talea\Connectors\Bing::AUTH, Talea\Connectors\Bing::authHeaders('k', ''), Talea\Connectors\Bing::authQuery('k'),
    Talea\Connectors\Google::authQuery('k'), Talea\Core\Scheduler::JOBS['search_data'][0], isset(Talea\Connectors\Google::settings()['search_console_site']), array_keys(Talea\Connectors\Bing::settings())],
    [Talea\Connectors\Bing::class, 'token', [], ['apikey' => 'k'], [], 86400, true, ['site_url']]);
check('2.13 SearchData::googleRows – Search Console rows become query or page rows, the CTR in per cent, the position rounded; a row without a key is left out', Talea\Core\SearchData::googleRows(['rows' => [
    ['keys' => ['talea cms'], 'clicks' => 42, 'impressions' => 900, 'ctr' => 0.04666, 'position' => 3.44], ['keys' => [], 'clicks' => 1], ['keys' => ['  spaced  '], 'clicks' => 0, 'impressions' => 10, 'ctr' => 0, 'position' => 50.26]]], 'query'),
    [['kind' => 'query', 'key' => 'talea cms', 'clicks' => 42, 'impressions' => 900, 'ctr' => 4.67, 'position' => 3.4], ['kind' => 'query', 'key' => 'spaced', 'clicks' => 0, 'impressions' => 10, 'ctr' => 0.0, 'position' => 50.3]]);
check('2.13 SearchData::googleSitemaps – submitted and indexed summed over the content types; nothing from a malformed answer', [Talea\Core\SearchData::googleSitemaps(['sitemap' => [['path' => 'https://example.com/sitemap.xml', 'contents' => [['type' => 'web', 'submitted' => '12', 'indexed' => '9'], ['type' => 'image', 'submitted' => '3', 'indexed' => '1']]], ['contents' => []]]]),
    Talea\Core\SearchData::googleSitemaps(['error' => 'x']), Talea\Core\SearchData::googleRows([], 'page')],
    [[['kind' => 'sitemap', 'key' => 'https://example.com/sitemap.xml', 'clicks' => 10, 'impressions' => 15, 'ctr' => 66.67, 'position' => 0.0]], [], []]);
$bingSince = strtotime('-28 days');
$bingDay = '/Date(' . ((time() - 5 * 86400) * 1000) . '-0700)/';
$bingRows = Talea\Core\SearchData::bingRows(['d' => [
    ['Query' => 'talea bing', 'Clicks' => 5, 'Impressions' => 100, 'AvgImpressionPosition' => 4.0, 'Date' => $bingDay],
    ['Query' => 'talea bing', 'Clicks' => 1, 'Impressions' => 20, 'AvgImpressionPosition' => 10.0, 'Date' => $bingDay],
    ['Query' => 'stale', 'Clicks' => 9, 'Impressions' => 90, 'AvgImpressionPosition' => 1.0, 'Date' => '/Date(' . ((time() - 60 * 86400) * 1000) . ')/'],
    ['Query' => 'undated', 'Clicks' => 2, 'Impressions' => 0, 'AvgImpressionPosition' => 7.0], ['Query' => '', 'Clicks' => 3], 'junk',
]], 'query', $bingSince);
check('2.13 SearchData::bingRows – daily rows summed per query with the position weighted by impressions, old days dropped, undated rows kept, sorted by clicks', $bingRows,
    [['kind' => 'query', 'key' => 'talea bing', 'clicks' => 6, 'impressions' => 120, 'ctr' => 5.0, 'position' => 5.0], ['kind' => 'query', 'key' => 'undated', 'clicks' => 2, 'impressions' => 0, 'ctr' => 0.0, 'position' => 7.0]]);
check('2.13 SearchData::bingRows – pages come as Query or Page, a bare list works too; bingDate reads WCF and ISO dates', [
    array_column(Talea\Core\SearchData::bingRows([['Page' => 'https://example.com/a', 'Clicks' => 1, 'Impressions' => 2, 'AvgImpressionPosition' => 3], ['Query' => 'https://example.com/b', 'Clicks' => 4, 'Impressions' => 8, 'AvgImpressionPosition' => 2]], 'page'), 'key'),
    Talea\Core\SearchData::bingDate('/Date(1696291200000-0700)/'), Talea\Core\SearchData::bingDate('2026-10-01T00:00:00'), Talea\Core\SearchData::bingDate('soon'), Talea\Core\SearchData::bingDate(null)],
    [['https://example.com/b', 'https://example.com/a'], 1696291200, strtotime('2026-10-01T00:00:00'), null, null]);
/* ---------- 2.13: social post drafts (Core\SocialDrafts) ---------- */
$sdLink = Talea\Core\SocialDrafts::trackedLink('https://example.com/novinky/nova-hala', 'facebook', 'nova-hala');
check('2.13 SocialDrafts: the tracked link carries the network, the medium and the news slug; an existing query is kept', [$sdLink, Talea\Core\SocialDrafts::trackedLink('https://example.com/novinky/a?x=1', 'x', 'a')],
    ['https://example.com/novinky/nova-hala?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala', 'https://example.com/novinky/a?x=1&utm_source=x&utm_medium=social&utm_campaign=a']);
check('2.13 SocialDrafts: hashtags from the tags – CamelCase without diacritics, at most three, no duplicates', [Talea\Core\SocialDrafts::hashtags(['Nová hala', 'výroba', 'nova hala', 'CNC stroje', 'čtvrtý']), Talea\Core\SocialDrafts::hashtags(['', '!!!'])], // check-english: allow
    [['#NovaHala', '#Vyroba', '#CncStroje'], []]);
$sdLead = trim(str_repeat('Otevřeli jsme novou výrobní halu s moderními stroji. ', 12)); // about 620 characters // check-english: allow
$sdTags = ['#NovaHala', '#Vyroba'];
$sdFacebook = Talea\Core\SocialDrafts::text('facebook', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
$sdLinkedin = Talea\Core\SocialDrafts::text('linkedin', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
$sdX = Talea\Core\SocialDrafts::text('x', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
$sdInstagram = Talea\Core\SocialDrafts::text('instagram', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
check('2.13 SocialDrafts: Facebook and LinkedIn start with the title and end with the hashtags and the link; Facebook shortens the lead, LinkedIn keeps more of it',
    [str_starts_with($sdFacebook, "Nová hala\n\nOtevřeli"), str_ends_with($sdFacebook, "#NovaHala #Vyroba\n\n" . $sdLink), str_contains($sdFacebook, '…'), str_ends_with($sdLinkedin, $sdLink), mb_strlen($sdLinkedin) > mb_strlen($sdFacebook)], // check-english: allow
    [true, true, true, true, true]);
check('2.13 SocialDrafts: X fits 280 with the link counted as 23 – the hashtags and the link stay whole, the lead gives way', [Talea\Core\SocialDrafts::xLength($sdX) <= 280, Talea\Core\SocialDrafts::xLength($sdX) > 240, str_ends_with($sdX, "#NovaHala #Vyroba\n" . $sdLink),
    Talea\Core\SocialDrafts::xLength('abc https://example.com/a/very/long/path/that/goes/on'), Talea\Core\SocialDrafts::xLength(Talea\Core\SocialDrafts::text('x', str_repeat('T', 300), '', [], $sdLink))], [true, true, true, 27, 280]);
check('2.13 SocialDrafts: Instagram has no link in the text, says link in bio and keeps the hashtags', [str_contains($sdInstagram, 'http'), str_ends_with($sdInstagram, "#NovaHala #Vyroba\n\n" . t('Link in bio'))], [false, true]);
check('2.13 SocialDrafts: the chosen networks in a fixed order, unknown ones dropped; shorten() cuts at a word; plain() strips the editor HTML',
    [Talea\Core\SocialDrafts::chosen('x, facebook,evil'), Talea\Core\SocialDrafts::chosen(Talea\Core\SocialDrafts::DEFAULT_NETWORKS), Talea\Core\SocialDrafts::shorten('Dlouhý text s mnoha slovy', 14), Talea\Core\SocialDrafts::shorten('krátký', 20), Talea\Core\SocialDrafts::plain('<p>A &amp; B</p><p>C</p>')], // check-english: allow
    [['facebook', 'x'], ['facebook', 'linkedin'], 'Dlouhý text…', 'krátký', 'A & B C']); // check-english: allow
check('2.13 SocialDrafts: finish() completes an assistant text – its own links out, the hashtags and the tracked link back, X within its limit, Instagram without a link',
    [Talea\Core\SocialDrafts::finish('linkedin', 'Nový text https://evil.example/x od asistenta.', $sdTags, $sdLink), Talea\Core\SocialDrafts::xLength(Talea\Core\SocialDrafts::finish('x', $sdLead, $sdTags, $sdLink)) <= 280, str_contains(Talea\Core\SocialDrafts::finish('instagram', 'Text', [], $sdLink), 'http')], // check-english: allow
    ["Nový text od asistenta.\n\n#NovaHala #Vyroba\n\n" . $sdLink, true, false]); // check-english: allow
/* ---------- 2.13: Google Business Profile sync and reviews (Core\GoogleBusiness) ---------- */
$gbpWeek = array_fill_keys(Talea\Core\Hours::DAYS, []);
$gbpWeek['Monday'] = [['08:00', '12:00'], ['13:00', '17:30']];
$gbpWeek['Saturday'] = [['09:00', '24:00']];
$gbpRegular = Talea\Core\GoogleBusiness::regularHours($gbpWeek);
check('2.13 GBP: the regular week as Business Information periods – one per range, the day in capitals, 24:00 as hours 24, an empty week = no periods', [
    count($gbpRegular['periods']), $gbpRegular['periods'][1], $gbpRegular['periods'][2]['closeTime'], Talea\Core\GoogleBusiness::regularHours(array_fill_keys(Talea\Core\Hours::DAYS, []))],
    [3, ['openDay' => 'MONDAY', 'openTime' => ['hours' => 13, 'minutes' => 0], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17, 'minutes' => 30]], ['hours' => 24, 'minutes' => 0], ['periods' => []]]);
$gbpToday = new DateTimeImmutable('2026-10-03');
$gbpSpecial = Talea\Core\GoogleBusiness::specialHours([
    ['from' => '2026-12-24', 'to' => '2026-12-26', 'closed' => true, 'hours' => ''],              // three closed days → three periods
    ['from' => '2026-12-31', 'to' => '2026-12-31', 'closed' => false, 'hours' => '9-12, 13-15'], // two ranges → two periods
    ['from' => '2026-10-01', 'to' => '2026-10-04', 'closed' => true, 'hours' => ''],              // started before today: only today and tomorrow
    ['from' => '2027-11-01', 'to' => '2027-11-02', 'closed' => true, 'hours' => ''],              // beyond 12 months: left out
    ['from' => '2026-11-11', 'to' => '2026-11-11', 'closed' => false, 'hours' => 'nonsense'],     // unparsable hours: nothing Google could show
], $gbpToday)['specialHourPeriods'];
check('2.13 GBP: exceptions as specialHours – day by day, closed or with ranges, from today for 12 months', [
    count($gbpSpecial), $gbpSpecial[0], $gbpSpecial[3], array_map(fn (array $p): string => sprintf('%d-%02d-%02d', $p['startDate']['year'], $p['startDate']['month'], $p['startDate']['day']), $gbpSpecial)],
    [7, ['startDate' => ['year' => 2026, 'month' => 12, 'day' => 24], 'endDate' => ['year' => 2026, 'month' => 12, 'day' => 24], 'closed' => true],
        ['startDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'openTime' => ['hours' => 9, 'minutes' => 0], 'endDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'closeTime' => ['hours' => 12, 'minutes' => 0]],
        ['2026-12-24', '2026-12-25', '2026-12-26', '2026-12-31', '2026-12-31', '2026-10-03', '2026-10-04']]);
check('2.13 GBP: a years-long exception stops at 12 months and three ranges a day stop at 400 periods', [count(Talea\Core\GoogleBusiness::specialHours([['from' => '2026-01-01', 'to' => '2029-01-01', 'closed' => true, 'hours' => '']], $gbpToday)['specialHourPeriods']),
    count(Talea\Core\GoogleBusiness::specialHours([['from' => '2026-01-01', 'to' => '2029-01-01', 'closed' => false, 'hours' => '8-9, 9-10, 10-11']], $gbpToday)['specialHourPeriods'])], [366, 400]);
$gbpPost = Talea\Core\GoogleBusiness::postBody(['title' => 'New &amp; <b>bigger</b> hall', 'intro' => "<p>We   opened\na new hall.</p>"], 'https://example.cz/novinky/hala', 'https://example.cz/media/hala.jpg', 'cs');
$gbpLong = Talea\Core\GoogleBusiness::postBody(['title' => 'T', 'intro' => str_repeat('a', 2000)], 'https://example.cz/n', '', 'en');
check('2.13 GBP: a news item as a STANDARD post – title and intro as plain text, the image, a LEARN_MORE button; a long intro is cut to 1500 characters; no image = no media', [
    $gbpPost, mb_strlen($gbpLong['summary']), mb_substr($gbpLong['summary'], -1), isset($gbpLong['media'])],
    [['languageCode' => 'cs', 'summary' => "New & bigger hall\n\nWe opened a new hall.", 'topicType' => 'STANDARD', 'callToAction' => ['actionType' => 'LEARN_MORE', 'url' => 'https://example.cz/novinky/hala'],
        'media' => [['mediaFormat' => 'PHOTO', 'sourceUrl' => 'https://example.cz/media/hala.jpg']]], 1500, '…', false]);
$gbpRow = Talea\Core\GoogleBusiness::reviewRow(['reviewId' => 'r1', 'reviewer' => ['displayName' => ' <b>Jana</b> '], 'starRating' => 'FOUR', 'comment' => 'Fine', 'createTime' => '2026-09-20T10:00:00Z',
    'reviewReply' => ['comment' => 'Thanks', 'updateTime' => '2026-09-21T08:00:00Z']], '2026-10-03 12:00:00');
check('2.13 GBP: a review from the v4 API – stars from the word, the name without tags, the reply; no stars or id = no row', [
    $gbpRow['stars'], $gbpRow['author'], $gbpRow['reply'], $gbpRow['replied_at'] !== null, $gbpRow['reviewed_at'] !== '2026-10-03 12:00:00',
    Talea\Core\GoogleBusiness::reviewRow(['reviewId' => 'r2', 'starRating' => 'SIX'], 'now'), Talea\Core\GoogleBusiness::reviewRow(['starRating' => 'FIVE'], 'now')],
    [4, 'Jana', 'Thanks', true, true, null, null]);
$gbpRows = [['stars' => 5], ['stars' => 2], ['stars' => 4], ['stars' => 3], ['stars' => 5]];
check('2.13 GBP: the newest N reviews with at least M stars; the limits are clamped', [Talea\Core\GoogleBusiness::filter($gbpRows, 2, 4), Talea\Core\GoogleBusiness::filter($gbpRows, 10, 0), Talea\Core\GoogleBusiness::filter($gbpRows, 0, 9)],
    [[['stars' => 5], ['stars' => 4]], $gbpRows, [['stars' => 5]]]);
check('2.13 GBP: the queue handler, the daily job, the element in the builder and its English vocabulary, the facts', [
    Talea\Core\Connectors::handler('gbp.hours'), Talea\Core\Scheduler::JOBS['gbp'][0], in_array(Talea\Builder\Elements\GoogleReviews::class, Talea\Builder\Build::ELEMENTS, true),
    'google_reviews', ['type' => 'google_reviews', 'content' => ['count' => 3, 'min_stars' => 4, 'summary' => true, 'link' => 'https://maps.google.com/?cid=1']],
    isset(Talea\Core\Facts::BUILT_IN['google_rating']), isset(Talea\Core\Facts::BUILT_IN['google_reviews']), array_diff(Talea\Core\GoogleBusiness::CONFIG, array_keys(Talea\Connectors\Google::settings())) === []],
    [Talea\Core\GoogleBusiness::class, 86400, true, 'google_reviews', ['type' => 'google_reviews', 'content' => ['count' => 3, 'min_stars' => 4, 'summary' => true, 'link' => 'https://maps.google.com/?cid=1']], true, true, true]);
/* ---------- 2.13: enquiries to a sheet and the CRM (Core\EnquiryDelivery, EnquirySheet, EnquiryCrm) ---------- */
$f13Fields = Talea\Core\EnquiryDelivery::fields([['Firma', 'Acme s.r.o.'], ['Jméno a příjmení', 'Jan Novák'], ['E-mail', 'jan@example.cz'], ['Telefon', '+420 777 123 456'], ['Zpráva', "Chci kuchyň.\nDo léta."], ['Souhlas', 'ano'], ['CV', 'cv.pdf (12 kB)', '2026/09/abc.pdf']], // check-english: allow
    ['text', 'text', 'email', 'tel', 'textarea', 'checkbox', 'file']);
$f13Lead = Talea\Core\EnquiryDelivery::lead($f13Fields, 'jan@example.cz');
check('2.13 EnquiryDelivery::lead – the e-mail, the phone, the name by its label, the company, the rest as text without the consent and never the attachment path',
    [$f13Lead, $f13Fields[6]], [['name' => 'Jan Novák', 'email' => 'jan@example.cz', 'phone' => '+420 777 123 456', 'company' => 'Acme s.r.o.', 'text' => "Zpráva: Chci kuchyň.\nDo léta.\nCV: cv.pdf (12 kB)"], ['CV', 'cv.pdf (12 kB)', 'file']]); // check-english: allow
check('2.13 EnquiryDelivery::lead – without a name label the first text field is the name; an empty e-mail field keeps the enquiry e-mail',
    Talea\Core\EnquiryDelivery::lead([['Kdo', 'Eva', 'text'], ['Město', 'Brno', 'text'], ['Mail', '', 'email']], 'eva@example.cz'), ['name' => 'Eva', 'email' => 'eva@example.cz', 'phone' => '', 'company' => '', 'text' => 'Město: Brno']); // check-english: allow
check('2.13 EnquiryDelivery: names split at the last space, the forms list is matched by name, case and spaces aside', [
    Talea\Core\EnquiryDelivery::splitName('Jan Maria Novák'), Talea\Core\EnquiryDelivery::splitName('Novák'), Talea\Core\EnquiryDelivery::splitName(''), // check-english: allow
    Talea\Core\EnquiryDelivery::formWanted('', 'Poptávka'), Talea\Core\EnquiryDelivery::formWanted('Kontakt, poptávka ', 'Poptávka'), Talea\Core\EnquiryDelivery::formWanted('Kontakt', 'Poptávka'), // check-english: allow
    Talea\Core\EnquiryDelivery::title(['form' => 'Poptávka', 'topic' => 'Kuchyně']), Talea\Core\EnquiryDelivery::title(['form' => 'Poptávka', 'topic' => ''])], // check-english: allow
    [['Jan Maria', 'Novák'], ['', 'Novák'], ['', ''], true, true, false, 'Poptávka – Kuchyně', 'Poptávka']); // check-english: allow
$f13Payload = ['service' => 'google', 'enquiry' => 5, 'date' => '2026-10-03 10:00', 'form' => 'Poptávka', 'topic' => 'Kuchyně', 'email' => 'jan@example.cz', 'page' => 'https://example.cz/kontakt', 'fields' => $f13Fields]; // check-english: allow
check('2.13 EnquirySheet: the create body carries the title and a bold frozen header, a row has the fixed columns then every other field in its own cell', [
    Talea\Core\EnquirySheet::createBody('Acme – enquiries', Talea\Core\EnquirySheet::COLUMNS)['properties'], Talea\Core\EnquirySheet::createBody('A', ['Date', 'Form'])['sheets'][0]['properties'],
    array_column(array_column(Talea\Core\EnquirySheet::createBody('A', ['Date', 'Form'])['sheets'][0]['data'][0]['rowData'][0]['values'], 'userEnteredValue'), 'stringValue'),
    Talea\Core\EnquirySheet::appendBody($f13Payload)],
    [['title' => 'Acme – enquiries'], ['title' => 'Form', 'gridProperties' => ['frozenRowCount' => 1]], ['Date', 'Form'],
        ['values' => [['2026-10-03 10:00', 'Poptávka', 'Kuchyně', 'jan@example.cz', 'https://example.cz/kontakt', 'Jan Novák', '+420 777 123 456', 'Zpráva: Chci kuchyň.', 'Do léta.', 'CV: cv.pdf (12 kB)']]]]); // check-english: allow
check('2.13 HubSpot bodies: the search by e-mail, the contact with only the filled properties, the note associated to the contact', [
    Talea\Connectors\HubSpot::searchBody('jan@example.cz')['filterGroups'][0]['filters'][0], Talea\Connectors\HubSpot::contactBody($f13Lead), Talea\Connectors\HubSpot::contactBody(['name' => 'Eva', 'email' => '', 'phone' => '', 'company' => '']),
    Talea\Connectors\HubSpot::noteBody('777', 'Text', 1700000000)],
    [['propertyName' => 'email', 'operator' => 'EQ', 'value' => 'jan@example.cz'], ['properties' => ['email' => 'jan@example.cz', 'firstname' => 'Jan', 'lastname' => 'Novák', 'phone' => '+420 777 123 456', 'company' => 'Acme s.r.o.']], ['properties' => ['lastname' => 'Eva']], // check-english: allow
        ['properties' => ['hs_timestamp' => '1700000000000', 'hs_note_body' => 'Text'], 'associations' => [['to' => ['id' => '777'], 'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => 202]]]]]]);
check('2.13 Pipedrive: the API of the company domain (nothing else is an address), the person with primary e-mail and phone, the lead and its note', [
    Talea\Connectors\Pipedrive::api('Acme-1'), Talea\Connectors\Pipedrive::api('evil.example.com'), Talea\Connectors\Pipedrive::api(''), Talea\Connectors\Pipedrive::authHeaders('x', ''),
    Talea\Connectors\Pipedrive::personBody($f13Lead), Talea\Connectors\Pipedrive::personBody(['name' => 'Eva', 'email' => '', 'phone' => '']), Talea\Connectors\Pipedrive::leadBody('Poptávka – Kuchyně', 42), Talea\Connectors\Pipedrive::noteBody('Text', 'lead-1', 42)], // check-english: allow
    ['https://acme-1.pipedrive.com/api/v1', null, null, [], ['name' => 'Jan Novák', 'email' => [['value' => 'jan@example.cz', 'primary' => true]], 'phone' => [['value' => '+420 777 123 456', 'primary' => true]]], ['name' => 'Eva'], // check-english: allow
        ['title' => 'Poptávka – Kuchyně', 'person_id' => 42], ['content' => 'Text', 'lead_id' => 'lead-1', 'person_id' => 42]]); // check-english: allow
check('2.13 Raynet: HTTP Basic from the user and the key, the lead with the contact and the notice, empty parts left out', [
    Talea\Connectors\Raynet::authHeaders('rn-key', 'user@example.cz'), Talea\Connectors\Raynet::leadBody('Poptávka – Kuchyně', $f13Lead, 'Text'), Talea\Connectors\Raynet::leadBody('Poptávka', ['name' => 'Eva', 'email' => '', 'phone' => '', 'company' => ''], '')], // check-english: allow
    [['Authorization' => 'Basic ' . base64_encode('user@example.cz:rn-key')], ['topic' => 'Poptávka – Kuchyně', 'firstName' => 'Jan', 'lastName' => 'Novák', 'companyName' => 'Acme s.r.o.', 'contactInfo' => ['email' => 'jan@example.cz', 'tel1' => '+420 777 123 456'], 'notice' => 'Text'], // check-english: allow
        ['topic' => 'Poptávka', 'lastName' => 'Eva']]); // check-english: allow
check('2.13 Connectors: the three CRMs are in the curated list with the enquiry switch in their settings; the queue prefixes sheets and crm have their handlers', [
    array_map(fn (string $c): string => $c::KEY, Talea\Core\Connectors::SERVICES), array_map(fn (string $c): bool => isset($c::settings()['enquiries']) && $c::settings()['enquiries'][2] === 'check', Talea\Core\Connectors::SERVICES),
    Talea\Core\Connectors::handler('sheets.append'), Talea\Core\Connectors::handler('crm.lead'), Talea\Core\Connectors::handler('other.x')],
    [['google', 'bing', 'hubspot', 'pipedrive', 'raynet'], [true, false, true, true, true], Talea\Core\EnquirySheet::class, Talea\Core\EnquiryCrm::class, null]);
/* ---------- 2.15: comments on drafts (Core\DraftComments, Core\Preview) ---------- */
$dc = Talea\Core\DraftComments::class;
check('2.15 DraftComments::parseTarget: a page draft only, with a positive id', [$dc::parseTarget('page:12'), $dc::parseTarget('page:0'), $dc::parseTarget('page:12x'), $dc::parseTarget('part:header:en'), $dc::parseTarget('popup:3'), $dc::parseTarget('')],
    [['kind' => 'page', 'id' => 12], null, null, null, null, null]);
check('2.15 DraftComments::clean: plain text only – tags out, entities decoded, spaces collapsed, one blank line at most, trimmed to the limit',
    [$dc::clean(" <b>Please</b> fix &amp; the   heading\n\n\n  second line <script>x()</script> ", 2000), $dc::clean("abcdef", 3), $dc::clean("<p></p>  \t ", 80), $dc::clean("a\x00b\x07c", 80)],
    ["Please fix & the heading\nsecond line x()", 'abc', '', 'abc']);
check('2.15 DraftComments::cleanElement: builder ids only', [$dc::cleanElement('nad1'), $dc::cleanElement('e_1-x'), $dc::cleanElement('a b'), $dc::cleanElement(''), $dc::cleanElement(str_repeat('a', 41))], ['nad1', 'e_1-x', null, null, null]);
check('2.15: the comment event is known, the tools are a read and a write, the resolve action maps to its tool',
    [isset(Talea\Core\Events::TYPES['comment.received']), Talea\Mcp\Catalog::TOOLS['list_draft_comments'], Talea\Mcp\Catalog::TOOLS['resolve_draft_comment']],
    [true, ['read', ''], ['write', '']]);
// the comments flag is signed into the preview key: a plain key never allows comments and a flag added by hand breaks the signature
$dcSettings = $keyedSettings(str_repeat('ef', 32));
$dcDb = new Talea\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''); // never connects: the secret key is set
$dcPlain = Talea\Core\Preview::key($dcDb, $dcSettings, 'page:5', 60);
$dcComments = Talea\Core\Preview::key($dcDb, $dcSettings, 'page:5', 60, true);
check('2.15 Preview: a key with comments verifies and allows them, a plain key verifies and does not, a forged flag or another target fails',
    [Talea\Core\Preview::verify($dcDb, $dcSettings, 'page:5', $dcComments), Talea\Core\Preview::allowsComments($dcDb, $dcSettings, 'page:5', $dcComments),
        Talea\Core\Preview::verify($dcDb, $dcSettings, 'page:5', $dcPlain), Talea\Core\Preview::allowsComments($dcDb, $dcSettings, 'page:5', $dcPlain),
        Talea\Core\Preview::verify($dcDb, $dcSettings, 'page:5', preg_replace('/^(\d{10})\./', '$1k.', $dcPlain)), Talea\Core\Preview::allowsComments($dcDb, $dcSettings, 'page:6', $dcComments),
        preg_match('/^\d{10}k\.[a-f0-9]{64}$/', $dcComments)],
    [true, true, true, false, false, false, 1]);

/* ---------- 2.15: guardrails for Claude and the reason of a change (Core\Guardrails) ---------- */
check('2.15 Guardrails::targetPage – a page tool with an id is that page; another build target or another tool is none', [
    Talea\Core\Guardrails::targetPage('update_page', ['id' => 12]), Talea\Core\Guardrails::targetPage('save_build', ['id' => '7']), Talea\Core\Guardrails::targetPage('save_build', ['id' => 7, 'component' => 3]),
    Talea\Core\Guardrails::targetPage('save_build', ['part' => 'header']), Talea\Core\Guardrails::targetPage('update_news', ['id' => 12]), Talea\Core\Guardrails::targetPage('publish_build', ['id' => 0])],
    [12, 7, null, null, null, null]);
check('2.15 Guardrails::reason – one line of plain text, at most 255 characters; anything else is none', [
    Talea\Core\Guardrails::reason("  Request #4:\n<b>new</b> hours  "), mb_strlen(Talea\Core\Guardrails::reason(str_repeat('a', 400))), Talea\Core\Guardrails::reason(['x']), Talea\Core\Guardrails::reason(null)],
    ['Request #4: new hours', 255, '', '']);
$withReason = Talea\Mcp\Server::withReason(['name' => 'update_page', 'inputSchema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]);
check('2.15 Server::withReason – write tools offer a reason, read tools do not', [array_keys($withReason['inputSchema']['properties']),
    Talea\Mcp\Server::withReason(['name' => 'get_page', 'inputSchema' => ['type' => 'object', 'properties' => []]])['inputSchema']['properties']], [['id', 'reason'], []]);
/* ---------- 2.15: agent notebook – topic validation ---------- */
check('2.15: Notebook::topic – a known topic in any case and with spaces around; anything else (an unknown word, empty, not a string) is refused', [
    Talea\Core\Notebook::topic('decisions'), Talea\Core\Notebook::topic(' Style '), Talea\Core\Notebook::topic('TODO'), Talea\Core\Notebook::topic('pricing'),
    Talea\Core\Notebook::topic(''), Talea\Core\Notebook::topic(null), Talea\Core\Notebook::topic(['style'])],
    ['decisions', 'style', 'todo', null, null, null, null]);
check('2.15: Notebook::TOPICS – the topics the admin filter, read_notebook and the export know, every one with a label', array_keys(Talea\Core\Notebook::TOPICS), ['decisions', 'style', 'credits', 'history', 'todo', 'other']);
/* ---------- 2.15: requests to Claude (Core\Requests) ---------- */
$f19 = Talea\Core\Requests::class;
check('2.15 Requests: status transitions – new starts, in progress ends in done or declined, a closed request is only reopened into in progress, the same status is not a move', [
    $f19::canMove('new', 'in_progress'), $f19::canMove('new', 'done'), $f19::canMove('new', 'declined'), $f19::canMove('in_progress', 'done'), $f19::canMove('in_progress', 'declined'),
    $f19::canMove('in_progress', 'new'), $f19::canMove('done', 'in_progress'), $f19::canMove('done', 'new'), $f19::canMove('done', 'declined'), $f19::canMove('declined', 'in_progress'), $f19::canMove('declined', 'done'),
    $f19::canMove('new', 'new'), $f19::canMove('new', 'nonsense'), array_keys($f19::STATUSES) === array_keys($f19::ORDER)],
    [true, true, true, true, true, false, true, false, false, true, false, false, false, true]);
check('2.15 Requests: what a request is about – a chosen page, news item or collection item, a path or an https address; nothing else', [
    $f19::cleanAbout('page:12'), $f19::cleanAbout(' news:5 '), $f19::cleanAbout('item:33'), $f19::cleanAbout('page:0'), $f19::cleanAbout('user:3'), $f19::cleanAbout('/price-list'),
    $f19::cleanAbout('https://example.com/cenik?x=1'), $f19::cleanAbout('javascript:alert(1)'), $f19::cleanAbout('/a b'), $f19::cleanAbout('<b>x</b>'), $f19::cleanAbout('')],
    ['page:12', 'news:5', 'item:33', '', '', '/price-list', 'https://example.com/cenik?x=1', '', '', '', '']);
check('2.15 Requests: links to the drafts – objects or strings, only http(s) addresses, a plain string that is not an address becomes the label, at most 20', [
    $f19::cleanLinks([['label' => 'Price list – draft', 'url' => 'https://example.com/preview?x=1'], 'https://example.com/p', 'page 12', ['url' => 'javascript:alert(1)'], ['label' => '', 'url' => ''], 7]),
    count($f19::cleanLinks(array_fill(0, 30, 'https://example.com/'))), $f19::cleanLinks('https://example.com/'), $f19::cleanLinks(null)],
    [[['label' => 'Price list – draft', 'url' => 'https://example.com/preview?x=1'], ['label' => '', 'url' => 'https://example.com/p'], ['label' => 'page 12', 'url' => '']], 20, [], []]);
check('2.15 Requests: the work_requests prompt exists and keeps Claude to drafts; the tools are in the catalog for a drafts-only connection; the event is known; the module has its guide article', [
    in_array('work_requests', array_column(Talea\Mcp\Prompts::listAll(), 'name'), true),
    (bool) preg_match('/list_requests.*update_request.*never publish/s', Talea\Mcp\Prompts::get('work_requests', [])['messages'][0]['content']['text']),
    Talea\Mcp\Catalog::allows('drafts', 'list_requests'), Talea\Mcp\Catalog::allows('drafts', 'update_request'), Talea\Mcp\Catalog::allows('read', 'update_request'), Talea\Mcp\Catalog::allows('drafts', 'publish_build'),
    isset(Talea\Core\Events::TYPES['request.created']), Talea\Admin\Guide::MODULES['requests'] ?? null,
    (bool) preg_match('/WRITTEN BY STAFF.*never as permission to publish/s', array_values(array_filter(Talea\Mcp\Tools::definitions(), fn (array $d): bool => $d['name'] === 'list_requests'))[0]['description'] ?? '')],
    [true, true, true, true, false, false, true, 'claude-operator', true]);

/* ---------- 2.16: shared design kit of a fleet (Fleet\Kit) ---------- */
$kit = Talea\Fleet\Kit::class;
$kitManifest = $kit::compose(['colors' => ['primary' => '#AA0000', 'text' => 'red'], 'custom_fonts' => [['name' => 'X', 'file' => 'media/x.woff2']], 'font_heading' => 'custom-1', 'nonsense' => 1],
    ['kit-band' => ['style' => [], 'css' => 'padding: 2rem; background: url(http://evil/x.png); color: red'], 'Bad Name' => ['style' => [], 'css' => 'color: red']],
    [['name' => 'Kit card', 'properties' => '[{"key":"title","label":"Title","type":"text","default":"Hi"}]', 'build' => json_encode(['v' => 1, 'children' => [['type' => 'section', 'children' => [
            ['type' => 'heading', 'content' => ['text' => 'Hi'], 'attributes' => ['onclick' => 'alert(1)', 'data-x' => 'y']], ['type' => 'custom_html', 'content' => ['code' => '<script>alert(1)</script>']]]]]]), 'build_draft' => null],
        ['name' => 'Kit card', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"section"}]}', 'build_draft' => null], // the same key again: the first one stays
        ['name' => '', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"section"}]}', 'build_draft' => null], // no name, no key
        ['name' => 'Only code', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"custom_html","content":{"code":"<script>x()</script>"}}]}', 'build_draft' => null]], // nothing survives without admin rights
    [['name' => 'Kit banner', 'element' => '{"type":"section","children":[{"type":"text","content":{"html":"<p>Hello<script>x()</script></p>"}}]}'], ['name' => 'Broken', 'element' => '{"type":"nonsense"}']]);
check('2.16 Kit::compose – the design system is sanitized and comes without custom fonts, a class needs a valid name and keeps only safe declarations, a component loses the custom HTML element and a script attribute, a repeated or empty key and a build with nothing left are dropped, a section is cleaned like a build', [
    $kitManifest['design_system']['colors']['primary'], $kitManifest['design_system']['colors']['text'], $kitManifest['design_system']['custom_fonts'], $kitManifest['design_system']['font_heading'], isset($kitManifest['design_system']['nonsense']),
    array_keys($kitManifest['classes']), $kitManifest['classes']['kit-band']['css'],
    array_column($kitManifest['components'], 'key'), count($kitManifest['components'][0]['build']['children'][0]['children']), $kitManifest['components'][0]['build']['children'][0]['children'][0]['attributes'], array_column($kitManifest['components'][0]['properties'], 'key'),
    array_column($kitManifest['sections'], 'key'), $kitManifest['sections'][0]['element']['children'][0]['content']['html'], $kit::summary($kitManifest)],
    ['#aa0000', '#16181d', [], 'modern', false, ['kit-band'], 'padding: 2rem; color: red;', ['kit-card'], 1, ['data-x' => 'y'], ['title'], ['kit-banner'], '<p>Hello</p>', 'design system, 1 class, 1 component, 1 section']);
check('2.16 Kit::sanitize – unknown keys and wrong shapes are dropped, the result always has the three lists; a sanitized manifest sanitizes to itself except for fresh element ids',
    [$kit::sanitize(['foo' => 1, 'classes' => 'x', 'components' => 'y']), $kit::sanitize(null), preg_replace('/"id":"[a-z0-9]+"/', '', $kit::encode($kit::sanitize($kitManifest))) === preg_replace('/"id":"[a-z0-9]+"/', '', $kit::encode($kitManifest))],
    [['classes' => [], 'components' => [], 'sections' => []], ['classes' => [], 'components' => [], 'sections' => []], true]);
$kitRefs = $kit::compose(null, [], [
    ['component_id' => 7, 'name' => 'Inner card', 'properties' => '[]', 'build' => '{"v":1,"children":[{"type":"section","children":[{"type":"text","content":{"html":"<p>x</p>"}}]}]}', 'build_draft' => null],
    ['component_id' => 9, 'name' => 'Outer card', 'properties' => '[]', 'build' => json_encode(['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'component', 'content' => ['component' => '7']], ['type' => 'component', 'content' => ['component' => '55']]]]]]), 'build_draft' => null]], []);
check('2.16 Kit – a component used inside a kit component travels by the kit key, a foreign integer never; a manifest from outside is cleaned the same way',
    [$kitRefs['components'][1]['build']['children'][0]['children'][0]['content']['component'], $kitRefs['components'][1]['build']['children'][0]['children'][1]['content']['component'],
        $kit::sanitize(['components' => [['key' => 'x', 'name' => 'X', 'build' => ['v' => 1, 'children' => [['type' => 'component', 'content' => ['component' => '3']]]]]]])['components'][0]['build']['children'][0]['content']['component']],
    ['@inner-card', '', '']);
check('2.16 Kit::announced – only a well-formed announcement of a newer version is fetched', [
    $kit::announced(['version' => 2, 'sha256' => str_repeat('a', 64)], 1), $kit::announced(['version' => 1, 'sha256' => str_repeat('a', 64)], 1), $kit::announced(['version' => '2', 'sha256' => str_repeat('a', 64)], 1),
    $kit::announced(['version' => 2, 'sha256' => 'xyz'], 1), $kit::announced(['version' => 2], 1), $kit::announced(null, 0), $kit::announced(['version' => 5_000_000, 'sha256' => str_repeat('a', 64)], 0)],
    [['version' => 2, 'sha256' => str_repeat('a', 64)], null, null, null, null, null, null]);
// the console signs the kit with its own key; the site checks the signature and the announced hash before it reads the manifest
$kitSettings = static function (): Talea\Core\Settings {
    $s = (new ReflectionClass(Talea\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Talea\Core\Settings::class, 'values'))->setValue($s, ['site_key_secret' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    (new ReflectionProperty(Talea\Core\Settings::class, 'db'))->setValue($s, new Talea\Core\Db('mysql:host=127.0.0.1;dbname=none', '', '')); // never connects: the key is set

    return $s;
};
$kitConsole = $kitSettings();
$kitJson = $kit::encode($kitManifest);
$kitSha = hash('sha256', $kitJson);
$kitBody = (string) json_encode(['ok' => true, 'version' => 3, 'sha256' => $kitSha, 'manifest' => $kitJson], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$kitAnswer = fn (string $body, string $signature): array => ['status' => 200, 'body' => $body, 'json' => json_decode($body, true), 'signature' => $signature, 'error' => ''];
$kitSignature = Talea\Fleet\Keys::sign($kitConsole, $kitBody);
$kitRefused = function (array $answer, string $key, int $version, string $sha): ?string {
    try {
        Talea\Fleet\Kit::verifyAnswer($answer, $key, $version, $sha);

        return null;
    } catch (RuntimeException $e) {
        return explode(' – ', $e->getMessage())[0];
    }
};
$kitTampered = str_replace('#aa0000', '#bb0000', $kitBody);
$kitForged = (string) json_encode(['ok' => true, 'version' => 3, 'sha256' => hash('sha256', str_replace('#aa0000', '#bb0000', $kitJson)), 'manifest' => str_replace('#aa0000', '#bb0000', $kitJson)]);
check('2.16 Kit::verifyAnswer – the signed kit with the announced hash passes; a tampered body, another console\'s key, a different version, a manifest that does not hash to the announcement, or no answer are refused', [
    $kit::verifyAnswer($kitAnswer($kitBody, $kitSignature), Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha)['components'][0]['key'],
    $kitRefused($kitAnswer($kitTampered, $kitSignature), Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
    $kitRefused($kitAnswer($kitBody, $kitSignature), Talea\Fleet\Keys::publicKey($kitSettings()), 3, $kitSha),
    $kitRefused($kitAnswer($kitBody, $kitSignature), Talea\Fleet\Keys::publicKey($kitConsole), 4, $kitSha),
    $kitRefused($kitAnswer($kitForged, Talea\Fleet\Keys::sign($kitConsole, $kitForged)), Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
    $kitRefused(['status' => 0, 'body' => '', 'json' => null, 'signature' => '', 'error' => 'No answer.'], Talea\Fleet\Keys::publicKey($kitConsole), 3, $kitSha),
    isset(Talea\Core\Events::TYPES['fleet.kit_received']), isset(Talea\Core\Events::TYPES['fleet.kit_refused']), isset(Talea\Core\Events::TYPES['fleet.kit_published'])],
    ['kit-card', 'The kit is not signed by the console', 'The kit is not signed by the console', 'The console handed over a different kit version than it announced', 'The kit does not match the hash the console announced', 'The console did not hand over the kit (No answer.).', true, true, true]);

/* ---------- 2.17: undo a whole Claude session (Core\AgentJournal) ---------- */
check('2.17 AgentJournal: content tables are journaled, logs and security tables are not', [Talea\Core\AgentJournal::journaled('pages'), Talea\Core\AgentJournal::journaled('settings'),
    Talea\Core\AgentJournal::journaled('change_log'), Talea\Core\AgentJournal::journaled('api_tokens'), Talea\Core\AgentJournal::journaled('agent_journal')],
    [true, true, false, false, false]);
check('2.17 AgentJournal::same – rows compare by value (the database gives strings, JSON numbers), a missing row only equals a missing row', [
    Talea\Core\AgentJournal::same(['ids' => '5', 'title' => 'A', 'x' => null], ['ids' => 5, 'title' => 'A', 'x' => null]), Talea\Core\AgentJournal::same(['ids' => '5'], ['ids' => '6']),
    Talea\Core\AgentJournal::same(null, null), Talea\Core\AgentJournal::same(null, ['ids' => 1]), Talea\Core\Scheduler::JOBS['agent_journal'][0]],
    [true, false, true, false, 86400]);
/* ---------- 2.17: scheduled Claude runs (Core\AgentSchedules) – next_due in the site's time zone ---------- */
$prague = new DateTimeZone('Europe/Prague');
$due = fn (string $cadence, int $day, string $time, string $after): string => Talea\Core\AgentSchedules::nextDue($cadence, $day, $time, new DateTimeImmutable($after, $prague))->format('Y-m-d H:i P');
check('2.17 nextDue daily: later today, otherwise tomorrow – across a month end and a year end', [$due('daily', 1, '08:00', '2026-01-31 07:59'), $due('daily', 1, '08:00', '2026-01-31 08:00'), $due('daily', 1, '08:00', '2026-12-31 09:00')],
    ['2026-01-31 08:00 +01:00', '2026-02-01 08:00 +01:00', '2027-01-01 08:00 +01:00']);
check('2.17 nextDue weekly: the ISO weekday this week when still ahead, otherwise next week – across a month end', [$due('weekly', 1, '07:00', '2026-02-27 10:00'), $due('weekly', 1, '07:00', '2026-03-02 06:00'), $due('weekly', 1, '07:00', '2026-03-02 07:00'), $due('weekly', 7, '18:30', '2026-03-02 07:00')],
    ['2026-03-02 07:00 +01:00', '2026-03-02 07:00 +01:00', '2026-03-09 07:00 +01:00', '2026-03-08 18:30 +01:00']);
check('2.17 nextDue monthly: the day of month (1–28) this month when still ahead, otherwise next month – February, December', [$due('monthly', 28, '06:00', '2026-02-28 05:00'), $due('monthly', 28, '06:00', '2026-02-28 06:00'), $due('monthly', 1, '09:00', '2026-12-15 12:00'), $due('monthly', 15, '09:00', '2026-01-31 12:00')],
    ['2026-02-28 06:00 +01:00', '2026-03-28 06:00 +01:00', '2027-01-01 09:00 +01:00', '2026-02-15 09:00 +01:00']);
check('2.17 nextDue keeps the wall-clock time across the daylight-saving changes of 2026 (29 March, 25 October)', [$due('daily', 1, '07:00', '2026-03-28 07:00'), $due('weekly', 7, '07:00', '2026-10-24 12:00'), $due('monthly', 25, '07:00', '2026-10-24 12:00'), $due('daily', 1, '7:00', '2026-03-28 07:30')],
    ['2026-03-29 07:00 +02:00', '2026-10-25 07:00 +01:00', '2026-10-25 07:00 +01:00', '2026-03-29 07:00 +02:00']);
// under English, as the MCP handler runs it – the admin dictionary in the test is Czech and would translate the task texts
$instructions = fn (string $task, string $text = ''): string => Talea\Core\Language::runWith('en', fn (): string => Talea\Core\AgentSchedules::instructions(['task' => $task, 'text' => $text]));
check('2.17 every task text ends with the rules (drafts only, never publish, stop for a person); the administrator\'s text is the task for custom and an addition for the others',
    [...array_map(fn (string $task): bool => str_contains($instructions($task), 'never publish') && str_contains($instructions($task), 'report_agent_run'), array_keys(Talea\Core\AgentSchedules::TASKS)),
        str_starts_with($instructions('custom', 'Check the prices.'), 'Check the prices.'), str_contains($instructions('review', 'Only Services.'), 'Also: Only Services.'), str_contains($instructions('review'), 'site_audit')],
    [true, true, true, true, true, true, true, true]);
check('2.17 validate: the day fits the cadence, the time is HH:MM, custom needs a text; the missed run is a known warning the alerts send',
    [Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'weekly', 'day' => 1, 'time' => '07:00']), Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'monthly', 'day' => 31, 'time' => '07:00']) !== null,
        Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'custom', 'text' => '', 'cadence' => 'daily', 'day' => 1, 'time' => '07:00']) !== null, Talea\Core\AgentSchedules::validate(['name' => 'A', 'task' => 'review', 'cadence' => 'daily', 'day' => 1, 'time' => '7:00']) !== null,
        isset(Talea\Core\Events::TYPES['agent_run.missed']), in_array('agent_run.missed', Talea\Core\Alerts::WARNINGS, true), isset(Talea\Core\Scheduler::JOBS['agent_runs'])],
    [null, true, true, true, true, true, true]);

/* ---------- 3.0: the extension API (Extension\Registry, Extension\Api) ---------- */
check('3.0 Registry::satisfies – version requirements of add-ons', [
    Talea\Extension\Registry::satisfies('3.0.0', '>=3.0'), Talea\Extension\Registry::satisfies('3.2.1', '>=3.0 <4.0'), Talea\Extension\Registry::satisfies('4.0.0', '>=3.0 <4.0'),
    Talea\Extension\Registry::satisfies('3.1.0', '^3'), Talea\Extension\Registry::satisfies('4.1.0', '^3'), Talea\Extension\Registry::satisfies('2.16.0', '3.0'), Talea\Extension\Registry::satisfies('3.0.0', ''),
    Talea\Extension\Registry::satisfies('3.0.0', 'banana')],
    [true, true, false, true, false, false, true, false]);
$reg = Talea\Extension\Registry::get();
$reg->addToken('unit', 'hi', fn (array $a): string => '<b>' . htmlspecialchars($a['name'] ?? '?') . '</b>');
$reg->addToken('unit', 'boom', fn (array $a): string => throw new RuntimeException('x'));
$reg->addFilter('footer', fn (string $h): string => $h . '[a]');
$reg->addFilter('footer', fn (string $h): string => throw new RuntimeException('x'));
$reg->addFilter('footer', fn (string $h): string => $h . '[b]');
check('3.0 add-on tokens and filters: attributes in quotes (3.3.2: never in escaped quotes – that is how a visitor\'s text looks), a failing token prints nothing, a failing filter is skipped, unknown tokens stay', [
    Talea\Extension\Registry::fillTokens('<p>{{ext.unit.hi name="Jana"}} {{ext.unit.hi name=&quot;Petr&quot;}} {{ext.unit.boom}} {{ext.other.x}}</p>'), Talea\Extension\Registry::applyFilter('footer', '')],
    ['<p><b>Jana</b> {{ext.unit.hi name=&quot;Petr&quot;}}  {{ext.other.x}}</p>', '[a][b]']);
$apiErrors = [];
$api = new Talea\Extension\Api($reg, 'unit', new Talea\Core\App(['db' => []], new Talea\Core\Request([], [], [])));
foreach ([fn () => $api->filter('body', fn ($h) => $h), fn () => $api->mcpTool('x', 'd', [], 'admin', fn () => 1), fn () => $api->token('Bad Name', fn () => '')] as $call) {
    try { $call(); } catch (InvalidArgumentException $e) { $apiErrors[] = $e->getMessage(); }
}
$api->mcpTool('greet', 'Greets.', ['properties' => []], 'write', fn (array $a): string => 'hi');
check('3.0 Api: unknown filters, access levels and names are refused; a tool is ext_<slug>_<name> with its access in the catalog', [count($apiErrors), $reg->tool('ext_unit_greet')['access'] ?? null,
    Talea\Mcp\Catalog::access('ext_unit_greet'), Talea\Mcp\Catalog::allows('read', 'ext_unit_greet'), Talea\Mcp\Catalog::allows('full', 'ext_unit_greet')],
    [3, 'write', 'write', false, true]);

/* ---------- extension API 2 (issue #27): version gate, declared settings, event and alert types, the contract ---------- */
$api1 = new Talea\Extension\Api($reg, 'oldone', new Talea\Core\App(['db' => []], new Talea\Core\Request([], [], [])), 1);
$gated = [];
foreach ([fn () => $api1->healthRows(fn () => []), fn () => $api1->earlyRequest(fn () => null), fn () => $api1->job('x', 60, 'l', fn () => 'ok', 'cron'), fn () => $api1->eventType('oldone.x', 'd')] as $call) {
    try { $call(); } catch (LogicException $e) { $gated[] = true; }
}
$api1->job('plain', 60, 'A job', fn () => 'ok');
$api2 = new Talea\Extension\Api($reg, 'newone', new Talea\Core\App(['db' => []], new Talea\Core\Request([], [], [])), 2, ['early_request']);
$api2->eventType('newone.expiring', 'Something expires.', alert: true);
$api2->job('heavy', 60, 'Heavy', fn () => 'ok', 'cron');
$api2Errors = [];
foreach ([fn () => $api2->eventType('other.thing', 'd'), fn () => $api2->eventType('backup.failed', 'd'), fn () => $api2->job('x', 60, 'l', fn () => 'ok', 'sometimes'), fn () => $api2->httpGet('https://example.com/')] as $call) {
    try { $call(); } catch (InvalidArgumentException | LogicException $e) { $api2Errors[] = get_class($e); }
}
check('API 2: the methods of version 2 and a runner refuse an add-on written for API 1; version 1 jobs stay "any"', [count($gated), $reg->jobs()['ext_oldone_plain'][1], Talea\Extension\Api::V2_METHODS === ['earlyRequest', 'notFound', 'healthRows', 'handoverFindings', 'eventType', 'settings', 'httpGet'], Talea\Extension\Api::SUPPORTED],
    [4, 'any', true, [1, 2]]);
check('API 2: an event type is <slug>.<name>, Talea\'s own names are taken, an unknown runner and an undeclared capability are refused; cron jobs are marked', [$api2Errors, $reg->jobs()['ext_newone_heavy'][1],
    isset(Talea\Core\Events::types()['newone.expiring']), in_array('newone.expiring', Talea\Core\Alerts::warnings(), true), isset(Talea\Core\Events::TYPES['newone.expiring'])],
    [['InvalidArgumentException', 'InvalidArgumentException', 'InvalidArgumentException', 'LogicException'], 'cron', true, true, false]);
check('API 2: a warning of an add-on\'s alert type is worth an alert e-mail, one of an unknown type is not', array_column(Talea\Core\Alerts::worth([
    ['id' => 1, 'created_at' => '', 'type' => 'newone.expiring', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 2, 'created_at' => '', 'type' => 'stranger.thing', 'severity' => 'warning', 'message' => '', 'data' => []],
    ['id' => 3, 'created_at' => '', 'type' => 'stranger.thing', 'severity' => 'error', 'message' => '', 'data' => []]]), 'id'), [1, 3]);
check('API 2: declared settings – types are checked, numbers are clamped, a flag is 1 or 0', [
    Talea\Extension\SettingsSchema::sanitize('number:1:365', '9999'), Talea\Extension\SettingsSchema::sanitize('number:1:365', 'abc'), Talea\Extension\SettingsSchema::sanitize('flag', 'on'), Talea\Extension\SettingsSchema::sanitize('flag', ''),
    Talea\Extension\SettingsSchema::sanitize('choice:a|b', 'c'), Talea\Extension\SettingsSchema::sanitize('email', 'x'), Talea\Extension\SettingsSchema::sanitize('url', 'https://example.com/a'), Talea\Extension\SettingsSchema::sanitize('text', "a\nb")],
    ['365', null, '1', '0', null, null, 'https://example.com/a', 'a b']);
$schemaError = false;
try { Talea\Extension\SettingsSchema::normalize(['Bad Name' => ['label' => 'x', 'type' => 'text']]); } catch (InvalidArgumentException) { $schemaError = true; }
try { Talea\Extension\SettingsSchema::normalize(['ok' => ['label' => 'x', 'type' => 'binary']]); $schemaError = false; } catch (InvalidArgumentException) { }
check('API 2: a setting with a bad name or an unknown type is refused', $schemaError, true);
$apiContract = json_decode((string) file_get_contents(__DIR__ . '/contracts/extension-api.json'), true);
check('API 2: the contract records version 2, both supported versions, and which methods are new in 2', [$apiContract['version'], $apiContract['supported'], $apiContract['v2_methods'], $apiContract['methods']['job']],
    [2, [1, 2], ['earlyRequest', 'eventType', 'handoverFindings', 'healthRows', 'httpGet', 'notFound', 'settings'], ['string $name', 'int $interval', 'string $label', 'callable $run', 'string $runner']]);

/* ---------- 3.0: structured importers – the common base, Ghost and Blogger (Import\…) ---------- */
$ghostPath = dirname(__DIR__) . '/tools/fixtures/ghost-export.json';
$bloggerPath = dirname(__DIR__) . '/tools/fixtures/blogger-export.xml';
$kinds = fn (iterable $records): array => array_map(fn (object $r): string => substr(strrchr(get_class($r), '\\'), 1) . ':' . ($r instanceof Talea\Import\Post ? $r->type . '/' . $r->status : $r->key), iterator_to_array($records));
$ghost = new Talea\Import\Ghost($ghostPath, 'https://old.example');
$ghost->verify();
$ghostRecords = iterator_to_array($ghost->read());
check('3.0 Ghost: authors and public tags come first, then posts and pages with their status; the internal tag is skipped', $kinds($ghostRecords),
    ['Author:u1', 'Tag:t1', 'Tag:t2', 'Post:post/published', 'Post:post/draft', 'Post:page/published', 'Post:post/scheduled']);
$kiln = $ghostRecords[3];
check('3.0 Ghost: the post – primary tag as the category, the other tag as a tag, __GHOST_URL__ resolved, meta from posts_meta, old address /slug/', [
    $kiln->title, $kiln->categoryKeys, $kiln->tagKeys, $kiln->featureImageUrl, $kiln->seoTitle, $kiln->seoDescription, $kiln->oldUrl, $kiln->excerpt, $kiln->authorKey, str_contains($kiln->html, 'https://old.example/img/team.png')],
    ['Firing the first kiln', ['t1'], ['t2'], 'https://old.example/img/team.png', 'Firing the first kiln – Clay Notes', 'How the first firing of our new kiln went.', '/firing-the-first-kiln/', 'Fourteen hours, one kiln, no cracks.', 'u1', true]);
check('3.0 Ghost: a post with only a lexical document is rendered (heading, paragraphs, bold) and the unknown card is a warning',
    [$ghostRecords[4]->html, $ghostRecords[4]->warnings], ["<h2>Celadon</h2>\n<p>Feldspar, silica and a little <strong>iron</strong>.</p>\n<p>Fire in reduction.</p>\n", ['block:bookmark']]);
check('3.0 Ghost: read($skip) continues with the same keys', array_keys(iterator_to_array($ghost->read(5))), [5, 6]);
check('3.0 Ghost: site name from the settings, address from the administrator', $ghost->site(), ['name' => 'Clay Notes', 'url' => 'https://old.example']);
$ghostOk = true;
try {
    (new Talea\Import\Ghost($bloggerPath))->verify();
    $ghostOk = false;
} catch (RuntimeException) {
}
check('3.0 Ghost: a file that is not a Ghost export is refused', $ghostOk, true);

$blogger = new Talea\Import\Blogger($bloggerPath);
$blogger->verify();
$bloggerRecords = iterator_to_array($blogger->read());
check('3.0 Blogger: the author and the labels come before the first post that uses them; settings, template and the comment are skipped', $kinds($bloggerRecords),
    ['Author:http://www.blogger.com/profile/0000000000000000001', 'Post:page/published', 'Tag:vegetables', 'Tag:spring', 'Post:post/published', 'Tag:compost', 'Post:post/draft']);
$beds = $bloggerRecords[4];
check('3.0 Blogger: the post – labels as tags, old address from link rel=alternate, slug from it, thumbnail at full size, dates', [
    $beds->title, $beds->tagKeys, $beds->oldUrl, $beds->slug, $beds->featureImageUrl, $beds->publishedAt, str_contains($beds->html, '<img'), $beds->authorKey],
    ['Planting the first beds', ['vegetables', 'spring'], 'http://127.0.0.1:65000/2019/05/planting-first-beds.html', 'planting-first-beds', 'http://127.0.0.1:65000/s1600/team.png', '2019-05-14T08:30:00.000+02:00', true, 'http://www.blogger.com/profile/0000000000000000001']);
check('3.0 Blogger: app:draft = a draft without an old address; the author’s noreply address is not an e-mail', [$bloggerRecords[6]->status, $bloggerRecords[6]->oldUrl, $bloggerRecords[0]->email], ['draft', '', '']);
check('3.0 Blogger: site from the feed', $blogger->site(), ['name' => 'Garden Diary', 'url' => 'http://127.0.0.1:65000']);
check('3.0 Blogger::fullSize', array_map(Talea\Import\Blogger::fullSize(...), ['https://h.example/a/s72-c/x.png', 'https://h.example/a/w72-h72-p-k-no-nu/x.png', 'https://h.example/a/x.png', '']),
    ['https://h.example/a/s1600/x.png', 'https://h.example/a/s1600/x.png', 'https://h.example/a/x.png', '']);
$bloggerOk = true;
try {
    (new Talea\Import\Blogger(dirname(__DIR__) . '/tools/fixtures/wordpress-sample.xml'))->verify();
    $bloggerOk = false;
} catch (RuntimeException) {
}
check('3.0 Blogger: a WordPress export is refused', $bloggerOk, true);

// the common base: the preview of a whole file in one pass, the mapping, slug collisions, the registry
$ghostState = Talea\Import\Batch::newState('ghost-clay.json');
Talea\Import\Batch::analyze($ghostState, 30, $ghostPath);
check('3.0 Batch::analyze – counts, the first titles, the dictionary for later batches and the Ghost footnotes', [
    $ghostState['phase'], $ghostState['total'], $ghostState['overview']['articles'], $ghostState['overview']['pages'], $ghostState['overview']['tags'], $ghostState['overview']['titles']['page'],
    $ghostState['overview']['blocks'], isset($ghostState['overview']['warnings']['no_site_url']), $ghostState['dictionary']['tags']['t1']['name'], count($ghostState['overview']['notes'])],
    ['preview', 7, ['published' => 1, 'draft' => 1, 'scheduled' => 1], ['published' => 1], 2, ['About the workshop'], ['bookmark' => 1], true, 'Workshop', 3]);
$preview = Talea\Import\Preview::empty();
foreach ([new Talea\Import\Post('1', 'post', 'Lávka přes Bystřinu', 'lavka', '<p>a</p>'), new Talea\Import\Post('2', 'post', 'Lávka', 'lavka', '<p>b</p>'), // check-english: allow
    new Talea\Import\Post('3', 'page', 'Lávka', 'lavka', '<p>c</p>'), new Talea\Import\Post('4', 'post', 'Bez adresy', '', '<img src="/relative.png">', featureImageUrl: 'data:image/png;base64,x')] as $r) { // check-english: allow
    Talea\Import\Preview::tally($preview, $r);
}
Talea\Import\Preview::finish($preview, $blogger);
check('3.0 Preview: two posts with one slug are a duplicate, a page with the same slug is not; images without an absolute address are counted', $preview['warnings'], ['missing_images' => 2, 'duplicate_slugs' => 1]);
check('3.0 Mapping::normalize – unknown choices fall back, authors only to existing users, the language only to a version the site has, the address gets https', Talea\Import\Mapping::normalize(
    ['posts' => 'skip', 'pages' => 'nonsense', 'categories' => 'tag', 'authors' => ['u1' => '7', 'u2' => 9, 'u3' => 'x'], 'language' => 'fr', 'drafts' => '', 'default_category' => '-3', 'site_url' => 'old.example/'], ['de'], [7]),
    ['posts' => 'skip', 'pages' => 'page', 'categories' => 'tag', 'tags' => 'tag', 'authors' => ['u1' => 7], 'language' => '', 'drafts' => false, 'builder' => true, 'redirects' => true, 'default_category' => 0, 'site_url' => 'https://old.example']);
check('3.0 Batch: status, dates and the source label', [Talea\Import\Batch::status('scheduled'), Talea\Import\Batch::status('draft'), Talea\Import\Batch::status('sent'),
    Talea\Import\Batch::date('2024-03-10T09:00:00.000Z'), Talea\Import\Batch::date('', 1789000000), Talea\Import\Batch::label('ghost', 'https://www.Old.example/'), Talea\Import\Batch::label('blogger', '')],
    [['visible' => 1], ['visible' => 0], null, date('Y-m-d H:i:s', strtotime('2024-03-10T09:00:00.000Z')), date('Y-m-d H:i:s', 1789000000), 'ghost:old.example', 'blogger']);
check('3.0 Sources and file names: the key from the file name, only known systems and extensions, safe upload names', [
    array_keys(Talea\Import\Sources::all()), Talea\Import\Sources::keyOfFile('ghost-my-blog.json'), Talea\Import\Sources::keyOfFile('ghost-my-blog.xml'), Talea\Import\Sources::keyOfFile('export.json'),
    Talea\Import\Batch::uploadName('blogger', 'Můj blog (2024).xml'), Talea\Import\Batch::uploadName('ghost', 'clay.notes.JSON'), Talea\Import\Batch::isValidName('../ghost-x.json'), Talea\Import\Batch::isValidName('blogger-x.xml')], // check-english: allow
    [['ghost', 'blogger', 'joomla', 'drupal', 'webflow'], 'ghost', null, null, 'blogger-muj-blog-2024.xml', 'ghost-clay-notes.json', false, true]);


/* ---------- 3.0: importers on the common base – Joomla, Drupal (Import\Fetch) and Webflow ---------- */
$joomla = new Talea\Import\Joomla(dirname(__DIR__) . '/tools/fixtures/joomla-fetch.json');
$joomlaRecords = iterator_to_array($joomla->read());
check('3.0 Joomla: authors, categories and tags first, then the articles; the trashed one is skipped, state 0 is a draft, a future publish_up is scheduled, archived is published', $kinds($joomlaRecords),
    ['Author:42', 'Author:43', 'Category:2', 'Category:8', 'Category:9', 'Tag:2', 'Tag:3', 'Post:post/published', 'Post:post/draft', 'Post:post/scheduled', 'Post:post/published']);
check('3.0 Joomla: the article – intro and full text with the Read more mark, relative images and links made absolute, the featured image without #joomlaImage, the category and tag keys, the best-guess old address through the category path, metadesc, the author', [
    $joomlaRecords[7]->oldUrl, $joomlaRecords[7]->featureImageUrl, $joomlaRecords[7]->categoryKeys, $joomlaRecords[7]->tagKeys, $joomlaRecords[7]->authorKey, $joomlaRecords[7]->seoDescription, $joomlaRecords[7]->publishedAt,
    str_contains($joomlaRecords[7]->html, "</p>\n<!--more-->\n<p>"), str_contains($joomlaRecords[7]->html, 'src="https://old.example/images/blog/oven.jpg"'), str_contains($joomlaRecords[7]->html, 'href="https://old.example/blog/news/15-summer-market"'),
    $joomlaRecords[9]->oldUrl, $joomlaRecords[10]->oldUrl, $joomlaRecords[4]->parent],
    ['/blog/news/12-hello-from-the-bakery', 'https://old.example/images/blog/oven.jpg', ['9'], ['2'], '42', 'Opening day of the bakery.', '2024-03-10 09:00:00', true, true, true, '/blog/15-summer-market', '/uncategorised/16-archived-thoughts', '8']);
check('3.0 Joomla: the site address from the fetched file, read($skip) continues with the same keys, no e-mails of the users', [$joomla->site(), array_keys(iterator_to_array($joomla->read(9))), $joomlaRecords[0]->email, $joomlaRecords[0]->name], [['name' => '', 'url' => 'https://old.example'], [9, 10], '', 'Marta Editor']);
check('3.0 Joomla::imagePath and the API page: items with attributes only, the next link; a non-API answer is refused', [
    Talea\Import\Joomla::imagePath('images/a.jpg#joomlaImage://local-images/a.jpg?width=1'), Talea\Import\Joomla::imagePath('images/b.jpg'),
    Talea\Import\Joomla::page(['links' => ['self' => 'a', 'next' => 'https://old.example/api/index.php/v1/content/articles?page[offset]=2'], 'data' => [['id' => '1', 'attributes' => []], 'junk', ['id' => '2']]], 'articles'),
    (function (): string { try { Talea\Import\Joomla::page(['foo' => 1], 'articles'); return 'accepted'; } catch (RuntimeException $e) { return 'refused'; } })(),
    Talea\Import\Joomla::headers('abc'), Talea\Import\Joomla::headers(''), Talea\Import\Joomla::firstPage('https://old.example/', 'categories')],
    ['images/a.jpg', 'images/b.jpg', [[['id' => '1', 'attributes' => []]], [], 'https://old.example/api/index.php/v1/content/articles?page[offset]=2'], 'refused',
        ['Accept: application/vnd.api+json', 'X-Joomla-Token: abc'], ['Accept: application/vnd.api+json'], 'https://old.example/api/index.php/v1/content/categories?page[offset]=0&page[limit]=50']);
$joomlaOk = true;
try {
    (new Talea\Import\Joomla(dirname(__DIR__) . '/tools/fixtures/drupal-fetch.json'))->verify();
    $joomlaOk = false;
} catch (RuntimeException) {
}
check('3.0 Joomla: a Drupal fetch is refused', $joomlaOk, true);

$drupal = new Talea\Import\Drupal(dirname(__DIR__) . '/tools/fixtures/drupal-fetch.json');
$drupalRecords = iterator_to_array($drupal->read());
check('3.0 Drupal: the author and the tags from the included resources (once, though every page includes them), articles as posts, the unpublished node a draft, the basic page a page', $kinds($drupalRecords),
    ['Author:0a1b2c3d-00aa-4000-8000-0000000000aa', 'Tag:0a1b2c3d-00bb-4000-8000-0000000000b1', 'Tag:0a1b2c3d-00bb-4000-8000-0000000000b2', 'Post:post/published', 'Post:post/published', 'Post:post/draft', 'Post:page/published']);
check('3.0 Drupal: the article – body.processed with the file address made absolute, the image through include, the tag keys, the author, the metatag description, the summary, path.alias as the old address and slug; a node without an alias → /node/nid', [
    $drupalRecords[3]->slug, $drupalRecords[3]->oldUrl, $drupalRecords[3]->featureImageUrl, $drupalRecords[3]->tagKeys, $drupalRecords[3]->authorKey, $drupalRecords[3]->seoDescription, $drupalRecords[3]->excerpt, $drupalRecords[3]->language,
    str_contains($drupalRecords[3]->html, 'src="https://old.example/sites/default/files/2024-03/oven.jpg"'), $drupalRecords[5]->oldUrl, $drupalRecords[6]->oldUrl, $drupalRecords[1]->slug, $drupalRecords[2]->slug, $drupalRecords[0]->name],
    ['hello-from-drupal', '/blog/hello-from-drupal', 'https://old.example/sites/default/files/2024-03/oven.jpg', ['0a1b2c3d-00bb-4000-8000-0000000000b1'], '0a1b2c3d-00aa-4000-8000-0000000000aa', 'Welcome text for the search engines.', 'A warm welcome.', 'en',
        true, '/node/3', '/about', 'sourdough', 'events', 'Marta Editor']);
check('3.0 Drupal: the skipped step is a footnote, Basic for user:password and Bearer otherwise, the API page with included resources and links.next.href', [
    count($drupal->notes()), Talea\Import\Drupal::headers('me:secret')[1], Talea\Import\Drupal::headers('tok')[1], Talea\Import\Drupal::headers(''),
    Talea\Import\Drupal::page(['jsonapi' => ['version' => '1.0'], 'data' => [['type' => 'node--article', 'id' => 'x', 'attributes' => []], ['type' => 'bad']], 'included' => [['type' => 'file--file', 'id' => 'f', 'attributes' => []]], 'links' => ['next' => ['href' => 'https://old.example/jsonapi/node/article?page[offset]=2']]], 'articles'),
    (function (): string { try { Talea\Import\Drupal::page(['data' => []], 'articles'); return 'accepted'; } catch (RuntimeException $e) { return 'refused'; } })()],
    [4, 'Authorization: Basic ' . base64_encode('me:secret'), 'Authorization: Bearer tok', ['Accept: application/vnd.api+json'],
        [[['type' => 'node--article', 'id' => 'x', 'attributes' => []]], [['type' => 'file--file', 'id' => 'f', 'attributes' => []]], 'https://old.example/jsonapi/node/article?page[offset]=2'], 'refused']);

$webflow = new Talea\Import\Webflow(dirname(__DIR__) . '/tools/fixtures/webflow-blog.csv', 'https://www.example.com/blog/');
$webflow->verify();
$webflowRecords = iterator_to_array($webflow->read());
check('3.0 Webflow: the category and the tags of a row come before it (on first sight), Draft and Archived rows are drafts', $kinds($webflowRecords),
    ['Category:c:recipes', 'Tag:t:sourdough', 'Tag:t:spring', 'Post:post/published', 'Category:c:events', 'Tag:t:events', 'Post:post/draft', 'Post:post/draft']);
check('3.0 Webflow: the row – Item ID as the key, the rich text, the summary, the main image, the dates without the parenthesised zone name, the old address under the entered collection folder; references as slugs with names made from them', [
    $webflowRecords[3]->key, $webflowRecords[3]->title, $webflowRecords[3]->oldUrl, $webflowRecords[3]->featureImageUrl, $webflowRecords[3]->excerpt, $webflowRecords[3]->publishedAt, $webflowRecords[3]->categoryKeys, $webflowRecords[3]->tagKeys,
    str_contains($webflowRecords[3]->html, '<img src="https://uploads-ssl.webflow.com/65a0/65a0-oven.png"'), $webflowRecords[6]->publishedAt, $webflowRecords[7]->oldUrl, $webflowRecords[0]->name, $webflowRecords[0]->slug, array_keys(iterator_to_array($webflow->read(6)))],
    ['65a0000000000000000000a1', 'Spring sourdough', 'https://www.example.com/blog/spring-sourdough', 'https://uploads-ssl.webflow.com/65a0/65a0-oven.png', 'A spring recipe for sourdough.', 'Tue Mar 05 2024 10:00:00 GMT+0000', ['c:recipes'], ['t:sourdough', 't:spring'],
        true, 'Mon Apr 01 2024 08:00:00 GMT+0000', 'https://www.example.com/blog/old-news', 'Recipes', 'recipes', [6, 7]]);
check('3.0 Webflow helpers: references, names from slugs, dates; the site is not in the file', [Talea\Import\Webflow::references('sourdough; Spring ;sourdough;'), Talea\Import\Webflow::nameFromSlug('cold-rise_bread'), Talea\Import\Webflow::date('nonsense (Zone)'), $webflow->site(), $webflow->imagesFromAnyHost()],
    [['sourdough', 'spring'], 'Cold rise bread', '', ['name' => '', 'url' => ''], true]);
$webflowOk = true;
try {
    (new Talea\Import\Webflow(dirname(__DIR__) . '/tools/fixtures/ghost-export.json'))->verify();
    $webflowOk = false;
} catch (RuntimeException) {
}
check('3.0 Webflow: a file without the Name and Slug columns is refused', $webflowOk, true);

// the fetcher: the URL rules (pure and with the resolved address), the file name, the step plan
check('3.0 Fetch::allowedUrl – the old site’s domain (with www), http(s), standard ports, no user name', array_map(fn (string $u): bool => Talea\Import\Fetch::allowedUrl($u, 'https://old.example'),
    ['http://www.old.example/api/index.php/v1/content/articles', 'https://other.example/api', 'https://old.example:8443/api', 'https://u@old.example/api', 'ftp://old.example/api', 'https://old.example/jsonapi?page[offset]=50']), [true, false, false, false, false, true]);
check('3.0 Fetch::allowedSite – internal, loopback, link-local and private addresses are refused', array_map(Talea\Import\Fetch::allowedSite(...), ['http://10.0.0.5', 'http://127.0.0.1', 'http://[::1]/', 'http://169.254.169.254', 'http://192.168.1.1/', 'http://100.64.0.1', 'http://0.0.0.0']), [false, false, false, false, false, false, false]);
check('3.0 Fetch: the file name from the domain, the plan keeps the required first step and known ticked steps in the system’s order, the skeleton is not done', [
    Talea\Import\Fetch::fileName('joomla', 'https://www.Old-Site.example/'), Talea\Import\Sources::keyOfFile('joomla-old-site-example.json'), Talea\Import\Sources::keyOfFile('webflow-blog.csv'), array_keys(Talea\Import\Sources::remote()),
    Talea\Import\Fetch::state(Talea\Import\Joomla::class, 'https://old.example/', ['tags', 'bogus', 'articles'])['steps'], Talea\Import\Fetch::state(Talea\Import\Drupal::class, 'https://old.example', [])['steps'],
    Talea\Import\Fetch::skeleton('drupal', 'https://old.example/')['talea_fetch']['done'], Talea\Import\Fetch::sessionKey('a') === Talea\Import\Fetch::sessionKey('a'), Talea\Import\Fetch::sessionKey('a') !== Talea\Import\Fetch::sessionKey('b')],
    ['joomla-old-site-example.json', 'joomla', 'webflow', ['joomla', 'drupal'], ['articles', 'tags'], ['articles'], false, true, true]);
$halfFetched = tempnam(sys_get_temp_dir(), 'talea-fetch');
file_put_contents($halfFetched, json_encode(Talea\Import\Fetch::skeleton('joomla', 'https://old.example')));
$halfOk = 'accepted';
try {
    (new Talea\Import\Joomla($halfFetched))->verify();
} catch (RuntimeException $e) {
    $halfOk = $e->getMessage();
}
unlink($halfFetched);
check('3.0 Fetch: a file whose fetch did not finish is refused by the Source', $halfOk, 'The fetch from the site did not finish. Start it again.');


/* ---------- 3.0: online booking of appointments (Core\Booking) ---------- */
$bk = Talea\Core\Booking::class;
$bkTz = new DateTimeZone('Europe/Prague');
$bkNow = new DateTimeImmutable('2026-11-09 08:00', $bkTz); // the day before
$bkDay = '2026-11-10';
$bkRanges = [['09:00', '12:00'], ['13:00', '17:00']];
check('3.0 Booking::free – slots across a lunch break step by the duration', $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, $bkNow, 0, 60),
    ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30']);
check('3.0 Booking::free – a 45-minute service fits only where 45 minutes are left', $bk::free([['09:00', '11:00']], [], [], 45, 0, 45, $bkDay, $bkNow, 0, 60), ['09:00', '09:45']);
check('3.0 Booking::free – a day off (an exception) takes its hours out', $bk::free($bkRanges, [], [['2026-11-10 13:00:00', '2026-11-10 15:00:00']], 30, 0, 30, $bkDay, $bkNow, 0, 60),
    ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '15:00', '15:30', '16:00', '16:30']);
check('3.0 Booking::free – an existing booking blocks its time and the buffer around it; a booking of another day does not', $bk::free([['09:00', '12:00']], [['2026-11-10 10:00:00', '2026-11-10 10:30:00'], ['2026-11-11 09:00:00', '2026-11-11 12:00:00']], [], 30, 15, 30, $bkDay, $bkNow, 0, 60),
    ['09:00', '11:00', '11:30']);
check('3.0 Booking::free – the lead time: nothing sooner than two hours from now, never in the past', [
    $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new DateTimeImmutable('2026-11-10 08:30', $bkTz), 2, 60),
    $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new DateTimeImmutable('2026-11-10 16:45', $bkTz), 0, 60),
    $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new DateTimeImmutable('2026-11-11 08:00', $bkTz), 0, 60)],
    [['10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'], [], []]);
check('3.0 Booking::free – the horizon: a day beyond it has no times, the last day in it has', [
    $bk::free($bkRanges, [], [], 60, 0, 60, '2026-11-20', $bkNow, 0, 10), $bk::free($bkRanges, [], [], 60, 0, 60, '2026-11-19', $bkNow, 0, 10), $bk::free($bkRanges, [], [], 60, 0, 60, 'nonsense', $bkNow, 0, 10)],
    [[], ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'], []]);
// the clocks go forward on 2026-03-29 at 02:00 in Prague: the slots are stepped on the real timeline and shown as wall-clock times
check('3.0 Booking::free – a DST day gives the same wall-clock times as any other, and a range across the switch skips the hour that does not exist', [
    $bk::free([['09:00', '12:00']], [], [], 60, 0, 60, '2026-03-29', new DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60),
    $bk::free([['01:00', '04:00']], [], [], 60, 0, 60, '2026-03-29', new DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60),
    $bk::free([['09:00', '12:00']], [['2026-03-29 10:00:00', '2026-03-29 11:00:00']], [], 60, 0, 60, '2026-03-29', new DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60)],
    [['09:00', '10:00', '11:00'], ['01:00', '03:00'], ['09:00', '11:00']]);
check('3.0 Booking::leastBooked – "anyone" goes to the least-booked free person that day, ties to the first in the order, nobody free = null', [
    $bk::leastBooked([1, 2, 3], [1 => 2, 2 => 0, 3 => 1], [1, 2, 3]), $bk::leastBooked([1, 2], [], [2, 1]), $bk::leastBooked([3], [1 => 0, 3 => 5], [1, 2, 3]), $bk::leastBooked([], [], [1, 2])],
    [2, 2, 3, null]);
check('3.0 Booking::step – the duration up to an hour, otherwise a quarter', [$bk::step(30), $bk::step(45), $bk::step(60), $bk::step(90), $bk::step(0)], [30, 45, 60, 15, 15]);
check('3.0 Booking::parseHours – weekday names or numbers with ranges, empty days left out, a wrong range or day refused', [
    $bk::parseHours(['monday' => '9-12, 13:00-17:00', 2 => '', '7' => '10-14']), $bk::parseHours(['funday' => '9-12']), $bk::parseHours([1 => '12-9']), $bk::parseHours([3 => 'whenever'])],
    [[1 => [['09:00', '12:00'], ['13:00', '17:00']], 7 => [['10:00', '14:00']]], null, null, null]);
check('3.0 Booking::offRange – a day, a part of a day, a span; an end before the start is refused', [
    $bk::offRange('2026-12-24', ''), $bk::offRange('2026-12-24 08:00', '2026-12-24 12:00'), $bk::offRange('2026-12-24', '2026-12-26'), $bk::offRange('2026-12-26', '2026-12-24'), $bk::offRange('christmas', '')],
    [['2026-12-24 00:00:00', '2026-12-25 00:00:00'], ['2026-12-24 08:00:00', '2026-12-24 12:00:00'], ['2026-12-24 00:00:00', '2026-12-27 00:00:00'], null, null]);
$bkSite = ['Tuesday' => [['08:00', '16:00']]] + array_fill_keys(Talea\Core\Hours::DAYS, []);
$bkClosed = [['id' => 1, 'from' => '2026-11-10', 'to' => '2026-11-10', 'closed' => true, 'hours' => '', 'note' => 'Inventory', 'notice_days' => 0]];
check('3.0 Booking::dayRanges – own hours, else the site\'s; a closed day of the site is a day off for everyone', [
    $bk::dayRanges([2 => [['10:00', '18:00']]], $bkSite, [], new DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, [], new DateTimeImmutable('2026-11-10', $bkTz)),
    $bk::dayRanges([2 => [['10:00', '18:00']]], $bkSite, $bkClosed, new DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, $bkClosed, new DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, [], new DateTimeImmutable('2026-11-11', $bkTz))],
    [[['10:00', '18:00']], [['08:00', '16:00']], [], [], []]);
check('3.0 Booking: the tools are in the catalog with the right access, the job and the events exist, the element has an English name and its hook attribute is reserved', [
    Talea\Mcp\Catalog::TOOLS['list_bookings'], Talea\Mcp\Catalog::TOOLS['booking_availability'], Talea\Mcp\Catalog::TOOLS['save_booking_staff'], Talea\Mcp\Catalog::TOOLS['cancel_booking'], Talea\Mcp\Catalog::allows('drafts', 'list_bookings'), Talea\Mcp\Catalog::allows('drafts', 'cancel_booking'),
    Talea\Core\Scheduler::JOBS['booking_reminders'][0], isset(Talea\Core\Events::TYPES['booking.created']), isset(Talea\Core\Events::TYPES['booking.cancelled']), 'booking',
    Talea\Builder\Build::className('booking'), (bool) preg_match(Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-booking'), (bool) preg_match(Talea\Builder\Build::ATTRIBUTE_PATTERN, 'data-booking-x'), Talea\Admin\Guide::MODULES['bookings'] ?? null,
    Talea\Core\Settings::DEFAULTS['booking_lead_hours'], Talea\Core\Settings::DEFAULTS['booking_horizon_days'], in_array('booking_services', Talea\Core\SiteImport::TABLES, true)],
    [['read', ''], ['read', ''], ['write', ''], ['destructive', ''], true, false, 3600, true, true, 'booking', Talea\Builder\Elements\Booking::class, false, true, 'bookings', '2', '60', true]);

/* ---------- 3.1: "Ask Claude" on the dashboard (Core\AskClaude) ---------- */
$ask = Talea\Core\AskClaude::class;
check('3.1 AskClaude::title – the first sentence, whitespace folded, shortened at a word with an ellipsis; "e.g." and dates do not end a sentence; empty stays empty', [
    $ask::title("We are closed from 24 to 26 December – put it on the site."),
    $ask::title("  Update the price list.\n\nThe PDF is attached, the old one goes away. "),
    $ask::title('Write a news item, e.g. about the new service. Thanks!'),
    $ask::title('Closed on 24. 12. because of the holiday. Put it on the site.'),
    $ask::title(str_repeat('word ', 30)), mb_strlen($ask::title(str_repeat('a', 200))), $ask::title("  \n ")],
    ['We are closed from 24 to 26 December – put it on the site.', 'Update the price list.', 'Write a news item, e.g. about the new service.', 'Closed on 24. 12. because of the holiday.',
        rtrim(str_repeat('word ', 15)) . '…', 80, '']);
check('3.1 AskClaude::examples – only those whose section the person may open, at most eight, every module ident exists; the prompt carries the site address and one place for the text', [
    array_keys($ask::examples(['enquiries' => 1])), count($ask::examples(['pages' => 1, 'news' => 1, 'collections' => 1, 'enquiries' => 1, 'stats' => 1, 'audit' => 1])), $ask::examples([]),
    array_values(array_diff(array_unique(array_column($ask::EXAMPLES, 0)), array_map(fn (string $c): string => $c::IDENT, Talea\Admin\Kernel::MODULES))),
    str_contains($ask::prompt('https://example.com/'), 'https://example.com (') && substr_count($ask::prompt('https://example.com'), '{text}') === 1],
    [['triage'], 8, [], [], true]);

/* ---------- 3.1.1: what Claude is told to do as drafts matches what a drafts-only connection may call ---------- */
// every tool a drafts routine is told to use is allowed for drafts-only connections, unless its sentence says it needs
// full access or is conditional ("when this connection may"); the texts: the work_requests and scheduled_run prompts,
// the tasks of scheduled runs, and the next step list_requests hands out
$draftTexts = ['work_requests' => Talea\Mcp\Prompts::get('work_requests', [])['messages'][0]['content']['text'], 'scheduled_run' => Talea\Mcp\Prompts::get('scheduled_run', [])['messages'][0]['content']['text']]
    + array_map(fn (array $task): string => $task[1], Talea\Core\AgentSchedules::TASKS)
    + ['next of list_requests' => (string) (preg_match("/'next' => '([^']+)'/", (string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/RequestTools.php'), $nextMatch) ? $nextMatch[1] : '')];
$draftGaps = [];
foreach ($draftTexts as $where => $text) {
    foreach (preg_split('/(?<=[.;:])\s+/', $text) ?: [] as $sentence) {
        preg_match_all('/\b[a-z]+(?:_[a-z]+)+\b/', $sentence, $names);
        foreach (array_unique($names[0]) as $tool) {
            if (isset(Talea\Mcp\Catalog::TOOLS[$tool]) && !Talea\Mcp\Catalog::allows('drafts', $tool) && !preg_match('/full access|when this connection may/', $sentence)) {
                $draftGaps[] = $where . ': ' . $tool;
            }
        }
    }
}
check('3.1.1: drafts routines are only told to use tools a drafts-only connection may call (or told what needs full access)', $draftGaps, []);

check('3.1.1: the Client and Enquiries only presets can ask Claude; every module icon exists and no two menu sections share one by accident', [
    in_array('requests', Talea\Admin\Modules\Roles::PRESETS['client'][3], true), in_array('requests', Talea\Admin\Modules\Roles::PRESETS['office'][3], true), in_array('requests', Talea\Admin\Modules\Roles::PRESETS['writer'][3], true),
    array_values(array_diff(array_map(fn (string $c): string => $c::ICON, Talea\Admin\Kernel::MODULES), (function (): array { $icon = require TALEA_SYSTEM . '/views/admin/icons.php'; preg_match_all("/^    '([a-z_-]+)' =>/m", (string) file_get_contents(TALEA_SYSTEM . '/views/admin/icons.php'), $m); return $m[1]; })()))],
    [true, true, false, []]);

/* ---------- 3.2: what a drafts-only connection may save, and Waiting for you ---------- */
$adminCs = require TALEA_SYSTEM . '/languages/admin-cs.php';
$adminDe = require TALEA_SYSTEM . '/languages/admin-de.php';
check('3.2: a drafts-only connection may save hidden items, proposed hours, triage and notes – not facts, not deletes; list_pending_review is a read', [
    array_map(fn (string $tool): bool => Talea\Mcp\Catalog::allows('drafts', $tool), ['save_collection_item', 'save_hours_exception', 'update_enquiry', 'write_notebook', 'list_pending_review', 'save_fact', 'delete_hours_exception', 'delete_notebook_entry', 'delete_collection_item', 'restore_item_version']),
    Talea\Mcp\Catalog::allows('read', 'list_pending_review'), Talea\Mcp\Catalog::allows('read', 'save_collection_item'),
    // each of the four refuses a drafts-only connection what is more than a draft (the handler asks Auth::draftsOnly)
    array_map(fn (string $file): bool => str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/' . $file . '.php'), 'draftsOnly()'), ['CollectionTools', 'FactTools', 'EnquiryAndPopupTools']),
    str_contains(Talea\Mcp\Prompts::get('review_pending', [])['messages'][0]['content']['text'], 'list_pending_review')],
    [[true, true, true, true, true, false, false, false, false, false], true, false, [true, true, true], true]);
check('3.2: Waiting for you – every kind opens an admin section that exists, its label is translated (cs, de), and the migration adds the proposed column', [
    array_values(array_diff(array_column(Talea\Core\PendingReview::KINDS, 1), array_map(fn (string $c): string => $c::IDENT, Talea\Admin\Kernel::MODULES))),
    array_values(array_filter(array_column(Talea\Core\PendingReview::KINDS, 0), fn (string $label): bool => !isset($adminCs[$label], $adminDe[$label]))),
    str_contains($migrationSource, "'proposed', 'boolean'"),
    // the site, the export and the door sign read only applied exceptions; proposals have their own list
    (bool) preg_match("/hours_exceptions} WHERE proposed = FALSE/", (string) file_get_contents(TALEA_SYSTEM . '/src/Core/Hours.php')),
    str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/SiteExport.php'), 'AND proposed = FALSE')],
    [[], [], true, true, true]);
/* ---------- 3.2: feature defaults – Bookings is a feature, Statistics has one switch ---------- */
check('3.2: Bookings is a feature that new installations start without; a site that never saved its choice does not get it', [
    Talea\Core\Extensions::CATALOG['bookings'][2],
    array_values(array_intersect(['bookings', 'stats'], Talea\Core\Extensions::enabled($reportSettings(['extensions' => ''])))),
    Talea\Admin\Modules\Bookings::EXTENSION, Talea\Builder\Elements\Booking::EXTENSION,
    in_array(Talea\Builder\Elements\Booking::TYPE, Talea\Builder\Build::disabledTypes(['news', 'enquiries']), true), in_array(Talea\Builder\Elements\Booking::TYPE, Talea\Builder\Build::disabledTypes(['bookings']), true),
    ],
    [false, ['stats'], 'bookings', 'bookings', true, false]);
check('3.2: the public booking pages, reminders and MCP tools follow the Bookings feature; no MCP tool is gated', [
    Talea\Core\Booking::isOn($reportSettings(['extensions' => 'news,claude'])), Talea\Core\Booking::isOn($reportSettings(['extensions' => 'news,bookings'])),
    array_values(array_unique(array_map(fn (string $tool): string => Talea\Mcp\Catalog::TOOLS[$tool][1], ['list_bookings', 'booking_availability', 'save_booking_service', 'save_booking_staff', 'cancel_booking', 'confirm_booking', 'decline_booking', 'propose_booking_times']))),
    substr_count((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/BookingTools.php'), '$this->requireBookings();')],
    [false, true, [''], 6]);
check('3.2: Statistics have one switch – the feature; the old setting is not read, not saved by the Analytics tab and still accepted over MCP', [
    Talea\Front\Stats::enabled($reportSettings(['extensions' => 'stats', 'stats' => '0'])), Talea\Front\Stats::enabled($reportSettings(['extensions' => 'news,claude', 'stats' => '1'])),
    Talea\Admin\Modules\Settings::verifyValue('stats', '1'), str_contains((string) file_get_contents(TALEA_SYSTEM . '/views/admin/settings/analytics.php'), "\$field('stats'"),
    (bool) preg_match((new ReflectionClassConstant(Talea\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue(), 'stats'), isset(Talea\Core\Settings::DEFAULTS['stats'])],
    [true, false, null, false, true, true]);

/* ---------- 3.3.2 (N25): a custom attribute never reaches a hook of the site's scripts, and never overrides the element's own ---------- */
// every data-* attribute a script on the public page mentions must be refused as a custom attribute – a new hook in web.js fails here until it is reserved
$frontScripts = ['image/web.js' => (string) file_get_contents(TALEA_ROOT . '/image/web.js'), 'image/vitals.js' => (string) file_get_contents(TALEA_ROOT . '/image/vitals.js')];
foreach (glob(TALEA_SYSTEM . '/views/front/*.php') ?: [] as $frontView) {
    preg_match_all('#<script>(.*?)</script>#s', (string) file_get_contents($frontView), $inlineScripts);
    $frontScripts['views/front/' . basename($frontView)] = implode("\n", $inlineScripts[1]);
}
// image/editor.js runs on the page for editing in place: the hooks it looks up in the whole document
preg_match_all('/document\.querySelector(?:All)?\(\'([^\']*)\'\)/', (string) file_get_contents(TALEA_ROOT . '/image/editor.js'), $editorSelectors);
$frontScripts['image/editor.js'] = implode("\n", $editorSelectors[1]);
$unreserved = [];
$hidden = [];
foreach ($frontScripts as $script => $source) {
    preg_match_all('/data-[a-z0-9-]*[a-z0-9]/', $source, $hooks);
    foreach (array_unique($hooks[0]) as $hook) {
        if (preg_match(Talea\Builder\Build::ATTRIBUTE_PATTERN, $hook)) {
            $unreserved[] = $script . ': ' . $hook;
        }
    }
    // a hook the scan above cannot see (dataset, a name put together) would slip through – write the full name in the script
    if (preg_match('/\.dataset\b|[\'"]data-[\'"]\s*\+|[\'"]data-[a-z0-9-]*-[\'"]\s*\+/', $source)) {
        $hidden[] = $script;
    }
}
check('3.3.2 N25: every data-* hook of the public scripts (web.js, vitals.js, inline scripts of the site views, editor.js on the page) is reserved; each is written in full', [
    count($frontScripts) > 5, $unreserved, $hidden], [true, [], []]);
[$n25Build, $n25Errors] = Talea\Builder\Build::sanitize(['children' => [['type' => 'store_locator', 'attributes' => ['data-leaflet' => 'https://evil.example/', 'data-attribution' => '<img src=x onerror=alert(1)>',
    'data-basket' => 'javascript:alert(1)', 'data-compare-url' => 'javascript:alert(2)', 'data-product' => '{}', 'data-popup' => '1', 'data-collection' => 'x', 'data-locator' => '', 'data-track' => 'ok']]]], false);
check('3.3.2 N25: the hooks of the store locator, the basket, the comparison and the popups cannot be saved as custom attributes; a harmless one stays', [
    $n25Build['children'][0]['attributes'] ?? [], isset($n25Errors['children[0].attributes'])], [['data-track' => 'ok'], true]);
$n25App = new Talea\Core\App([]);
$n25Html = fn (array $build): string => Talea\Builder\Build::html($build, new Talea\Builder\Context($n25App));
// a build stored before 3.3.2 (never sanitized again): the hook is left out, the element renders as before
$n25Stored = $n25Html(['children' => [['id' => 'h1', 'type' => 'heading', 'tag' => 'h2', 'content' => ['text' => 'Hi'], 'attributes' => ['data-leaflet' => 'https://evil.example/', 'data-basket' => 'javascript:alert(1)', 'onclick' => 'alert(1)', 'data-x' => 'y', 'title' => 'T']]]]);
[$n25Button] = Talea\Builder\Build::sanitize(['children' => [['type' => 'button', 'content' => ['text' => 'Go', 'link' => 'https://example.com/', 'new_window' => true], 'attributes' => ['rel' => 'opener', 'title' => 'Mine']],
    ['type' => 'back_to_top', 'attributes' => ['aria-label' => 'Custom', 'data-x' => 'y']]]]);
$n25Own = $n25Html($n25Button);
check('3.3.2 N25: a stored build with a reserved hook renders without it (no error); the element\'s own attributes come before the custom ones, so the browser keeps the element\'s', [
    $n25Stored, (bool) preg_match('/<a class="tl-button tl-button--primary" href="https:\/\/example\.com\/" target="_blank" rel="noopener" rel="opener" title="Mine">/', $n25Own),
    (bool) preg_match('/<a class="tl-back-to-top" href="#" aria-label="[^"]+" aria-label="Custom" data-x="y">/', $n25Own)],
    ['<h2 data-x="y" title="T">Hi</h2>', true, true]);
$webJs = $frontScripts['image/web.js'];
check('3.3.2 N25: web.js loads Leaflet only from the site\'s own copy, writes the map credit as text and makes basket and comparison links only from http(s) or site addresses', [
    (bool) preg_match('/leaflet\.origin === location\.origin && \/\\\\\/image\\\\\/vendor\\\\\/leaflet\\\\\/\$\/\.test\(leaflet\.pathname\)/', $webJs), str_contains($webJs, "attributionControl: false"), str_contains($webJs, 'link.textContent = credit;'),
    str_contains($webJs, 'attribution:'), str_contains($webJs, "safeUrl(form.getAttribute('data-basket'))"), str_contains($webJs, "safeUrl(box.form.getAttribute('data-compare-url'))"),
    substr_count($webJs, 'A(basket.page)') + substr_count($webJs, 'A(compare.url'), Talea\Builder\Elements\StoreLocator::LEAFLET_PATH],
    [true, true, true, false, true, true, 0, 'image/vendor/leaflet/']);

/* ---------- 3.3.2 (N26): a fact filled into an address is checked like any other link ---------- */
$n26Cache = new ReflectionProperty(Talea\Core\Facts::class, 'cache');
$n26Fact = fn (string $key, string $type, string $value): array => ['key' => $key, 'label' => $key, 'type' => $type, 'value' => $value, 'display' => $value, 'schema' => '', 'source' => '', 'updated' => null, 'builtIn' => false, 'translated' => false];
$n26Cache->setValue(null, [Talea\Core\Language::siteColumn() => ['promo' => $n26Fact('promo', 'text', 'javascript:alert(document.domain)'), 'shop' => $n26Fact('shop', 'url', 'https://shop.example/a?b=1&c=2'),
    'phone' => $n26Fact('phone', 'phone', '+420 123 456 789'), 'tricky' => $n26Fact('tricky', 'text', ' JaVa'), 'entity' => $n26Fact('entity', 'text', 'jav&#x61;script:alert(1)'),
    'data' => $n26Fact('data', 'text', 'data:text/html,<script>alert(1)</script>'), 'name' => $n26Fact('name', 'text', 'A "quoted" <name>')]]);
// the harness of the audit: a text fact "javascript:…" behind a Button
[$n26Build] = Talea\Builder\Build::sanitize(['children' => [['type' => 'button', 'content' => ['text' => 'Promo', 'link' => '{{fact.promo}}']], ['type' => 'button', 'content' => ['text' => 'Call', 'link' => 'tel:{{fact.phone}}']],
    ['type' => 'button', 'content' => ['text' => 'Shop', 'link' => '{{ fact.shop }}']]]]);
$n26Button = $n25Html($n26Build);
$n26Fill = fn (string $html): string => Talea\Core\Facts::fill($html, $n25App);
check('3.3.2 N26: a Button linked to a text fact "javascript:…" renders no javascript: link; a phone and a web address fact still work', [
    (bool) preg_match('/href="\s*javascript:/i', $n26Button), substr_count($n26Button, 'href="#">Promo</a>'), str_contains($n26Button, 'href="tel:+420 123 456 789">Call'), str_contains($n26Button, 'href="https://shop.example/a?b=1&amp;c=2">Shop')],
    [false, 1, true, true]);
check('3.3.2 N26: page, news and Custom HTML – every quoting, an image, a form, a token split around a scheme, entities, data: and a ">" in an earlier attribute; text and <code> as before', [
    $n26Fill('<p><a href="{{fact.promo}}">a</a><a href=\'{{fact.promo}}\'>b</a><a href={{ fact.promo }}>c</a><a HREF = "{{fact.promo}}">d</a></p>'),
    $n26Fill('<img src="{{fact.promo}}" alt="{{fact.name}}"><form action="{{fact.promo}}"><button formaction="{{fact.data}}">x</button></form>'),
    $n26Fill('<a href="{{fact.tricky}}script:alert(1)">e</a><a href="{{fact.entity}}">f</a><a title="x>y" href="{{fact.promo}}">g</a><a href="/{{fact.promo}}">h</a>'),
    $n26Fill('<p>{{fact.promo}} {{fact.name}}</p><code><a href="{{fact.promo}}">i</a></code>')],
    ['<p><a href="#">a</a><a href="#">b</a><a href="#">c</a><a HREF="#">d</a></p>',
     '<img src="" alt="A &quot;quoted&quot; &lt;name&gt;"><form action=""><button formaction="">x</button></form>',
     '<a href="#">e</a><a href="jav&amp;#x61;script:alert(1)">f</a><a title="x>y" href="#">g</a><a href="/javascript:alert(document.domain)">h</a>',
     '<p>javascript:alert(document.domain) A &quot;quoted&quot; &lt;name&gt;</p><code><a href="{{fact.promo}}">i</a></code>']);
/* ---------- 3.3.3 (N50): fact tokens in addresses – the HTML is read tag by tag, a stray src=" hides nothing ---------- */
$n50Facts = $n26Cache->getValue()[Talea\Core\Language::siteColumn()];
$n26Cache->setValue(null, [Talea\Core\Language::siteColumn() => $n50Facts + ['email' => $n26Fact('email', 'email', 'info@example.com'), 'path' => $n26Fact('path', 'text', 'a b'),
    'evil' => $n26Fact('evil', 'text', 'x onmouseover=alert(1)')]]);
check('3.3.3 N50: the two strings of the report – a stray src=" in text and inside another attribute – leave no javascript: link', [
    $n26Fill('<p>Use src="</p><a href="{{fact.promo}}">x</a>'), $n26Fill('<a title="a src=" href="{{fact.promo}}">x</a>'),
    $n26Fill('<a data-x="src=" href="{{fact.promo}}">y</a>'), $n26Fill("<p>src='</p><img alt='src=' src='{{fact.promo}}'>")],
    ['<p>Use src="</p><a href="#">x</a>', '<a title="a src=" href="#">x</a>', '<a data-x="src=" href="#">y</a>', "<p>src='</p><img alt='src=' src=\"\">"]);
check('3.3.3 N50: what hides markup from a regular expression – a script, a style, a comment, an escaped script, a "<" in text – is read as the browser reads it', [
    $n26Fill('<script>var a = \'<a title="\';</script><a href="{{fact.promo}}">s</a>'), $n26Fill('<style>a[title="</style><a href="{{fact.promo}}">st</a>'),
    $n26Fill('<!-- <a title=" --><a href="{{fact.promo}}">c</a>'), $n26Fill('<script><!--<script>x="</script>"</script>--></script><a href="{{fact.promo}}">d</a>'),
    $n26Fill('a < b </ x> <a href="{{fact.promo}}">lt</a>'), $n26Fill('<svg><style><a href="{{fact.promo}}">sv</a></style></svg><svg><a xlink:href="{{fact.promo}}">x</a></svg>')],
    ['<script>var a = \'<a title="\';</script><a href="#">s</a>', '<style>a[title="</style><a href="#">st</a>', '<!-- <a title=" --><a href="#">c</a>',
     '<script><!--<script>x="</script>"</script>--></script><a href="#">d</a>', 'a < b </ x> <a href="#">lt</a>',
     '<svg><style><a href="#">sv</a></style></svg><svg><a xlink:href="#">x</a></svg>']);
check('3.3.3 N50: tel:, mailto: and https://… with a fact still work; srcset (N64) is checked address by address; a token in a tag or attribute name is left out and an unquoted value is quoted', [
    $n26Fill('<a href="tel:{{fact.phone}}">t</a><a href="mailto:{{fact.email}}">m</a><a href="https://x.example/{{fact.path}}?q={{fact.shop}}">h</a>'),
    $n26Fill('<img srcset="{{fact.promo}} 1x, /a.jpg 2x"><img srcset="/a.jpg 1x, https://cdn.example/{{fact.path}} 2x">'),
    $n26Fill('<a {{fact.promo}} href="/ok">n</a><img alt={{fact.evil}}><img alt="{{fact.evil}}">'),
    $n26Fill('<pre>{{fact.promo}}</pre><code><a title="</code>" href="{{fact.promo}}">x</a></code><a href="{{fact.promo}}">after</a>')],
    ['<a href="tel:+420 123 456 789">t</a><a href="mailto:info@example.com">m</a><a href="https://x.example/a b?q=https://shop.example/a?b=1&amp;c=2">h</a>',
     '<img srcset=""><img srcset="/a.jpg 1x, https://cdn.example/a b 2x">', '<a  href="/ok">n</a><img alt="x onmouseover=alert(1)"><img alt="x onmouseover=alert(1)">',
     '<pre>{{fact.promo}}</pre><code><a title="</code>" href="{{fact.promo}}">x</a></code><a href="#">after</a>']);
[$n50Build] = Talea\Builder\Build::sanitize(['children' => [['type' => 'text', 'content' => ['html' => '<p>Use src="</p><p><a href="{{fact.promo}}">x</a> <a title="a src=" href="{{fact.promo}}">y</a></p>']]]], false);
$n50Html = $n25Html($n50Build);
check('3.3.3 N50: a builder Text element saved by an editor with both strings renders no javascript: link', [(bool) preg_match('/href="\s*javascript:/i', $n50Html), substr_count($n50Html, 'href="#"')], [false, 2]);
check('3.3.3 N50: Facts::save refuses a text fact that starts with another scheme than http(s), mailto or tel; other text stays allowed', array_map(Talea\Core\Facts::startsWithScheme(...),
    ['javascript:alert(1)', ' JaVa' . "\t" . 'Script:alert(1)', "\x01javascript:x", 'javascript: alert(1)', 'data:text/html,x', 'vbscript:x', 'foo:bar', 'https://example.com/', 'mailto:a@b.cz', 'tel:+420',
     'Note: open daily', 'Open 8:00–16:00', 'Po–Pá 8:00', 'Pozn.: viz níže', 'Price 100 CZK', '']), // check-english: allow
    [true, true, true, true, true, true, true, false, false, false, false, false, false, false, false, false]);
$n26Cache->setValue(null, [Talea\Core\Language::siteColumn() => []]); // no facts – the N38 builds below are filled without a database

/* ---------- 3.3.2 (N38): add-on tokens only in what editors wrote, never in what a visitor sent ---------- */
$reg->addToken('naive', 'echo', fn (array $a): string => (string) ($a['x'] ?? 'NAIVE')); // an add-on that prints its attribute as it is
$n38Query = '<input type="search" name="q" value="' . e('{{ext.naive.echo x="<img src=x onerror=alert(1)>"}}') . '">';
$n38Visitor = $n38Query . '<p>' . e('{{ext.naive.echo}}') . '</p>';
$n38Context = new Talea\Builder\Context($n25App);
$n38Context->content = $n38Visitor;
$n38Wrapper = Talea\Builder\Build::html(['children' => [['id' => 't1', 'type' => 'text', 'tag' => 'div', 'content' => ['html' => '<p>{{ext.naive.echo x="own"}}</p>']], ['id' => 'o1', 'type' => 'page_content', 'tag' => 'div', 'content' => []]]], $n38Context);
$kernelSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Front/Kernel.php');
preg_match('/private function page\(.*?\n    }\n/s', $kernelSource, $pageMethod);
check('3.3.2 N38: an escaped-quote token (a visitor\'s search query) is never run; a site-part wrapper fills its own tokens but not the page content it wraps; the page as a whole is not filled', [
    Talea\Extension\Registry::fillTokens($n38Query), str_contains($n38Wrapper, '<p>own</p>'), str_contains($n38Wrapper, $n38Visitor), str_contains($n38Wrapper, '<img'), str_contains($n38Wrapper, 'NAIVE'),
    $n38Context->content === $n38Visitor, ($pageMethod[0] ?? '') !== '' && !str_contains($pageMethod[0], 'fillTokens('), substr_count($kernelSource, 'self::authored(') >= 5],
    [$n38Query, true, true, false, false, true, true, true]);
$n26Cache->setValue(null, []);
/* ---------- 3.3.2: security release, workstream C ---------- */
$mcpSettings = (new ReflectionClassConstant(Talea\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue();
check('3.3.2 (N27): Claude cannot set GTM or Matomo (script chosen by their owner); GA4 and Plausible load from a fixed host and stay; the tool says why',
    [array_map(fn (string $key): int => preg_match($mcpSettings, $key), ['gtm_id', 'matomo_url', 'matomo_id', 'ga4_id', 'plausible_domain']),
        str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Handlers/SettingsTools.php'), "['gtm_id', 'matomo_url', 'matomo_id']"), Talea\Admin\Modules\Settings::verifyValue('gtm_id', 'GTM-<x>')],
    [[0, 0, 0, 1, 1], true, null]);
check('3.3.2 (N29): visitors\' personal data are never journaled for undo; content still is', array_map(Talea\Core\AgentJournal::journaled(...),
    ['enquiries', 'testimonial_requests', 'bookings', 'subscribers', 'mail', 'pages', 'settings']), [false, false, false, false, false, true, true]);
$settingsScreens = array_values(array_filter(Talea\Admin\Kernel::MODULES, fn (string $c): bool => is_a($c, Talea\Admin\Modules\Settings::class, true)));
check('3.3.2 (N24): the demo filters every Settings screen by its class – no backup, restore, download or pairing action through any of them; looking is allowed', [
    count($settingsScreens) >= 5,
    array_values(array_filter(array_map(fn (string $c): string => $c::IDENT, $settingsScreens), fn (string $ident): bool => !Talea\Core\Demo::blocksAdmin($ident, 'backup', '', true)
        || !Talea\Core\Demo::blocksAdmin($ident, 'download_backup', '', false) || !Talea\Core\Demo::blocksAdmin($ident, 'restore_backup', '', true)
        || !Talea\Core\Demo::blocksAdmin($ident, 'delete_backup', '', true) || !Talea\Core\Demo::blocksAdmin($ident, 'fleet_pair', '', true)
        || Talea\Core\Demo::blocksAdmin($ident, 'list', '', false))),
    Talea\Core\Demo::blocksAdmin('settings', 'save', 'general', true), Talea\Core\Demo::blocksAdmin('settings', 'save', 'mail', true), Talea\Core\Demo::blocksAdmin('business', 'hours_add', '', true),
    Talea\Core\Demo::blocksAdmin('status', 'save', '', true), Talea\Core\Demo::blocksAdmin('claude_settings', 'save', '', true), Talea\Core\Demo::blocksAdmin('pages', 'save', '', true)],
    [true, [], false, true, false, true, true, false]);
check('3.3.2 (N24): the demo saves only allow-listed settings – never script hosts, code, secrets, the policy link or maintenance', array_map(fn (array $k): bool => Talea\Core\Demo::blocksSetting($k[0], $k[1]),
    [['site_name', 'text'], ['company_city', 'text'], ['ga4_id', 'pattern'], ['matomo_url', 'url'], ['gtm_id', 'pattern'], ['head_code', 'code'], ['captcha_secret', 'secret'], ['cookies_policy_url', 'text'], ['maintenance', 'flag'], ['update_url', 'url'], ['site_email', 'email']]),
    [false, false, false, true, true, true, true, true, true, true, true]);
check('3.3.2 (N17): the privacy policy link is a path or https on saving, and a path or http(s) when printed – never javascript:, data: or //host', [
    array_map(fn (string $v): ?string => Talea\Admin\Modules\Settings::verifyValue('cookies_policy_url', $v), ['/privacy-policy', 'https://example.com/p', '', 'javascript:alert(1)', '//evil.example', 'http://example.com/p', 'data:text/html,x']),
    array_map(fn (string $v): string => Talea\Core\Privacy::policyUrl($reportSettings(['cookies_policy_url' => $v])), ['/zasady', 'http://old.example/p', 'javascript:alert(1)', 'JaVaScRiPt:x', '//evil.example', '/\\evil', ' /x '])],
    [['/privacy-policy', 'https://example.com/p', '', null, null, null, null], ['/zasady', 'http://old.example/p', '', '', '', '', '/x']]);
check('3.3.2 (N31): an empty other build target does not hide the page from the protected-pages guardrail; update_page and trash_page have no other target', [
    Talea\Core\Guardrails::targetPage('save_build', ['id' => 5, 'popup' => 0]), Talea\Core\Guardrails::targetPage('edit_build', ['id' => 5, 'part' => '', 'component' => '0', 'collection' => null]),
    Talea\Core\Guardrails::targetPage('save_build', ['id' => 5, 'popup' => 3]), Talea\Core\Guardrails::targetPage('publish_build', ['id' => 5, 'part' => 'header']),
    Talea\Core\Guardrails::targetPage('update_page', ['id' => 5, 'popup' => 3]), Talea\Core\Guardrails::targetPage('trash_page', ['id' => 5, 'collection' => 'x'])],
    [5, 5, null, null, 5, 5]);
check('3.3.2 (N32): with deleting switched off, deleting a redirect or a part variant, restoring an item version and e-mailing a testimonial request count as destructive', [
    (new ReflectionClassConstant(Talea\Core\Guardrails::class, 'DESTRUCTIVE_CALLS'))->getValue()],
    [['save_redirect' => 'delete', 'save_part_variant' => 'delete', 'restore_item_version' => '', 'request_testimonial' => 'send']]);
check('3.3.2 (N34): tries are counted per IPv4 address and per IPv6 /64 (an IPv4 address written as IPv6 counts as itself)', array_map(Talea\Core\Antispam::network(...),
    ['203.0.113.7', '2001:db8:1:2:3:4:5:6', '2001:db8:1:2:ffff::1', '::ffff:203.0.113.7', 'unknown']), ['203.0.113.7', '2001:db8:1:2::/64', '2001:db8:1:2::/64', '203.0.113.7', 'unknown']);
check('3.3.2 (N34): a page password has a limit per address and one per page across all addresses', [Talea\Core\PageLock::ATTEMPTS, Talea\Core\PageLock::PAGE_ATTEMPTS > Talea\Core\PageLock::ATTEMPTS,
    str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/PageLock.php'), 'Antispam::network(')], [10, true, true]);
$htaccess332 = (string) file_get_contents(TALEA_ROOT . '/.htaccess');
$router332 = (string) file_get_contents(TALEA_SYSTEM . '/dev-router.php');
preg_match("/if \\(preg_match\\('(#\\^\\/\\(system.+?#i)', \\\$path\\)\\)/", $router332, $routerRule);
check('3.3.2 (N36): .htaccess and the development router serve only extensions/<slug>/public/ and no PHP from it', [
    str_contains($htaccess332, 'RewriteRule ^extensions/(?![^/]+/public/) - [F,L,NC]'), str_contains($htaccess332, 'RewriteRule ^extensions/[^/]+/public/.+\.(php\d?|pht|phps|phtml|phar)$ - [F,L,NC]'),
    array_map(fn (string $path): int => preg_match($routerRule[1] ?? '/$^/', $path), ['/extensions/hello/Extension.php', '/extensions/hello/extension.json', '/extensions/README.md', '/extensions/hello/public/x.PHP',
        '/extensions/hello/public/x.pht', '/extensions/hello/public/x.PHPS', '/Extensions/hello/Extension.php', '/extensions/hello/public/app.css', '/media/a.jpg'])],
    [true, true, [1, 1, 1, 1, 1, 1, 1, 0, 0]]); // 3.3.3 (N64): .pht and .phps too, and the second rule ignores case
check('3.3.2 (N39): add-on tools may name a role or a section; without it read and draft are open, write needs an editor, destructive an administrator', [
    Talea\Extension\Api::TOOL_ROLES, (new ReflectionClassConstant(Talea\Extension\Api::class, 'DEFAULT_ROLE'))->getValue(),
    array_map(fn (ReflectionParameter $p): string => $p->getName() . ($p->isOptional() ? '?' : ''), (new ReflectionMethod(Talea\Extension\Api::class, 'mcpTool'))->getParameters())],
    [['author', 'editor', 'admin'], ['read' => 'author', 'draft' => 'author', 'write' => 'editor', 'destructive' => 'admin'], ['name', 'description', 'schema', 'access', 'handler', 'requires?']]);
check('3.3.2 (N40): the installation takes the version and the security flag it was decided for', array_map(fn (ReflectionParameter $p): string => $p->getName(),
    (new ReflectionMethod(Talea\Core\Updater::class, 'install'))->getParameters()), ['db', 'expectedVersion', 'expectedSecurity']);
check('3.3.2 (N43): an API fetch that carries a token is not redirected from https to plain http', [
    Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api', ['Accept: application/json', 'Authorization: Bearer x']),
    Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api?key=abc', ['Accept: application/json']),
    Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'http://old.example/api', ['Accept: application/vnd.api+json']),
    Talea\Import\Fetch::downgradesCredentials('https://old.example/api', 'https://www.old.example/api', ['Authorization: Bearer x']),
    Talea\Import\Fetch::downgradesCredentials('http://old.example/api', 'http://old.example/api2', ['X-Joomla-Token: x'])],
    [true, true, false, false, false]);

/* ---------- 3.3.3: security release, workstream A ---------- */
// N52: one normalized host for the lookup, the pin and the request
check('3.3.3 N52: Outbound::host – IDN to punycode, lowercase; a percent sign, other characters and numeric IPv4 spellings are refused', array_map(Talea\Core\Outbound::host(...),
    ['%61.attacker.tld', '%70inme.localhost', 'čeština.example', 'WWW.Example.COM', 'ｅxample.com', 'a_b.example', 'a..b', '0x7f.1', '2130706433', '127.1', '[::1]', '[0:0::1]', '93.184.216.34', 'example.com.', 'ex ample.com', '[not-ip]', '']), // check-english: allow
    [null, null, 'xn--etina-gya30d.example', 'www.example.com', 'example.com', null, null, null, null, null, '::1', '::1', '93.184.216.34', 'example.com.', null, null, null]);
check('3.3.3 N52: Outbound::url writes the normalized host into the URL – the name checked, pinned and requested is one string', [
    Talea\Core\Outbound::url('https://Čeština.Example:8443/a/%C4%8D?x=%41#f'), Talea\Core\Outbound::url('http://[::1]/x'), Talea\Core\Outbound::url('https://%61.example/'),
    Talea\Core\Outbound::url('https://u:p@example.com/'), Talea\Core\Outbound::url('ftp://example.com/'), Talea\Core\Outbound::url('https://example.com\\@169.254.169.254/')],
    [['url' => 'https://xn--etina-gya30d.example:8443/a/%C4%8D?x=%41#f', 'host' => 'xn--etina-gya30d.example', 'port' => 8443, 'scheme' => 'https'],
     ['url' => 'http://[::1]/x', 'host' => '::1', 'port' => 80, 'scheme' => 'http'], null, null, null, null]);
$n52Wp = new Talea\Core\ImageDownloader('https://stary-web.example');
$n52Any = new Talea\Core\ImageDownloader('https://čeština.example', true); // check-english: allow
check('3.3.3 N52: every checker refuses a %xx host and accepts an IDN host in its normalized form (images, fetch, fleet, links)', [
    $n52Wp->isAllowedUrl('https://%73tary-web.example/a.png'), $n52Any->isAllowedUrl('https://%61.attacker.tld/x.jpg'), $n52Any->isAllowedUrl('https://čeština.example/a.png'), $n52Any->domain(), // check-english: allow
    (new Talea\Core\ImageDownloader('https://xn--etina-gya30d.example'))->isAllowedUrl('https://www.čeština.example/a.png'), $n52Wp->verifiedIp('%61.example'), // check-english: allow
    Talea\Import\Fetch::allowedUrl('https://%6fld.example/api', 'https://old.example'), Talea\Import\Fetch::allowedUrl('https://čeština.example/api', 'https://čeština.example'), Talea\Import\Fetch::allowedSite('https://%6fld.example'), // check-english: allow
    Talea\Fleet\Http::allowedUrl('https://%61.example/'), Talea\Fleet\Http::allowedUrl('https://čeština.example/'), Talea\Fleet\Http::pin('https://%61.example/'), Talea\Fleet\Http::statuses(['https://%61.example/']), // check-english: allow
    Talea\Core\Links::isPublic('https://%61.example/'), Talea\Core\Links::target('https://čeština.invalid/')], // check-english: allow
    [false, false, true, 'xn--etina-gya30d.example', true, null, false, true, false, false, true, null, [0], false, false]);
check('3.3.3 N9: the link check pins every resolved address – IPv6, CGNAT 100.64/10 and NAT64 are internal, a name that does not resolve is not requested', [
    Talea\Core\Links::isPublic('http://100.64.0.1/'), Talea\Core\Links::isPublic('http://[::1]/'), Talea\Core\Links::isPublic('http://[fd00::1]/'), Talea\Core\Links::isPublic('http://[64:ff9b::a9fe:a9fe]/'),
    Talea\Core\Links::target('https://[2606:4700:4700::1111]/x'), Talea\Core\Links::target('https://nothing-here.invalid/'), Talea\Core\Links::target('https://example.com:8443/')],
    [false, false, false, false, ['url' => 'https://[2606:4700:4700::1111]/x', 'host' => '2606:4700:4700::1111', 'port' => 443, 'scheme' => 'https', 'ip' => '2606:4700:4700::1111'], false, null]);
check('3.3.3 N22: NAT64 (64:ff9b::/96, 64:ff9b:1::/48) and SIIT (::ffff:0:a.b.c.d) are not public; ordinary IPv6 still is', array_map(Talea\Core\ImageDownloader::isPublicIp(...),
    ['64:ff9b::a9fe:a9fe', '64:ff9b:1::a00:1', '64:ff9b:1:ffff::1', '::ffff:0:a9fe:a9fe', '::ffff:0:7f00:1', '::ffff:0:5db8:d822', '64:ff9b:2::1', '2606:4700::1111']),
    [false, false, false, false, false, false, true, true]);
check('3.3.3 N52: curl compares the address it connected to with the pinned one (any notation of the same address)', [
    defined('CURLOPT_PREREQFUNCTION') ? str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/Outbound.php'), 'CURLOPT_PREREQFUNCTION') : true,
    Talea\Core\Outbound::sameAddress('127.0.0.1', '::ffff:127.0.0.1'), Talea\Core\Outbound::sameAddress('::1', '0:0::1'), Talea\Core\Outbound::sameAddress('[::1]', '::1'),
    Talea\Core\Outbound::sameAddress('127.0.0.1', '127.0.0.2'), Talea\Core\Outbound::sameAddress('', '127.0.0.1')],
    [true, true, true, true, false, false]);
$n52Sources = array_map(fn (string $f): string => (string) file_get_contents(TALEA_SYSTEM . '/src/' . $f), ['Core/ImageDownloader.php', 'Import/Fetch.php', 'Fleet/Http.php', 'Core/Links.php']);
check('3.3.3 N52: no curl caller builds its own CURLOPT_RESOLVE entry – all pin through Outbound::pin', [array_sum(array_map(fn (string $s): int => substr_count($s, 'CURLOPT_RESOLVE'), $n52Sources)),
    array_map(fn (string $s): bool => str_contains($s, 'Outbound::pin('), $n52Sources)], [0, [true, true, true, true]]);

// N55: a Talea archive brings settings through the same validation as the admin form and MCP
check('3.3.3 N55: imported company_map and social_* must be web addresses; texts and numbers are checked by their field type', [
    Talea\Admin\Modules\Settings::checkable('company_map'), Talea\Admin\Modules\Settings::checkable('social_facebook'), Talea\Admin\Modules\Settings::checkable('design_system'),
    Talea\Admin\Modules\Settings::verifyValue('company_map', 'javascript:alert(1)'), Talea\Admin\Modules\Settings::verifyValue('social_x', ' JavaScript:alert(2)'),
    Talea\Admin\Modules\Settings::verifyValue('social_linkedin', 'https://www.linkedin.com/company/x'), Talea\Admin\Modules\Settings::verifyValue('company_map', ''),
    str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/SiteImport.php'), 'Settings::checkable($key) ? \Talea\Admin\Modules\Settings::verifyValue($key, $value)')],
    [true, true, false, null, null, 'https://www.linkedin.com/company/x', '', true]);

// N63: imported content checked again – only risky markup changes
$n63Wp = Talea\Core\WpContent::safeHtml(...);
check('3.3.3 N63: ImportRecheck::risk – script, handlers, script addresses and plugins are risky (2), a raw < or > in an attribute value is (1), the rest is fine (0)', array_map(Talea\Core\ImportRecheck::risk(...), [
    '<p>Hi <a href="https://x.example/">x</a></p>', '<p class="lead">Fine&nbsp;text</p>', '<p><img alt="<b>x</b>" src="a.jpg"></p>', '<p title="a > b">x</p>', '<img src=x onerror=alert(1)>',
    '<a href=" java&#10;script:alert(1)">x</a>', '<script>x()</script>', '<object data="x.swf"></object>', '<iframe srcdoc="<b>x</b>"></iframe>', '<img src="data:image/png;base64,AAAA">',
    '<a href="data:text/html,x">x</a>', '<img srcset="/a.jpg 1x, javascript:x 2x">', '<meta http-equiv="refresh" content="0;url=/">', 'plain text']),
    [0, 0, 1, 1, 2, 2, 2, 2, 2, 0, 2, 2, 2, 0]);
check('3.3.3 N63: ImportRecheck::html keeps safe HTML byte for byte, writes raw attribute text out again, and sanitizes risky HTML the way the import did', [
    Talea\Core\ImportRecheck::html('<p class="lead">v&nbsp;Praze <a href="/x">x</a></p>', $n63Wp), Talea\Core\ImportRecheck::html('<p><img alt="<b>x</b>" src="a.jpg"></p>', $n63Wp),
    Talea\Core\ImportRecheck::html('<p>Hi<img src="a.jpg" onerror="alert(1)"></p>', Talea\Core\Html::safe(...)), Talea\Core\ImportRecheck::html('<p><a href="javascript:alert(1)">x</a> ok</p>', $n63Wp)],
    ['<p class="lead">v&nbsp;Praze <a href="/x">x</a></p>', '<p><img alt="&lt;b&gt;x&lt;/b&gt;" src="a.jpg"></p>', '<p>Hi<img src="a.jpg"></p>', '<p>x ok</p>']);
$n63Safe = '{"v":1,"children":[{"id":"txt001","type":"text","tag":"div","content":{"html":"<p>Fine</p>"}},{"id":"htm001","type":"custom_html","tag":"div","content":{"html":"<script>own()</script>"}}]}';
$n63Risky = Talea\Core\ImportRecheck::build('{"v":1,"children":[{"id":"txt001","type":"text","tag":"div","content":{"html":"<p onclick=\\"x()\\">T</p>"}},{"id":"btn001","type":"button","content":{"text":"Go","link":"javascript:alert(1)"}},{"id":"htm001","type":"custom_html","tag":"div","content":{"html":"<script>own()</script>"}}]}');
check('3.3.3 N63: ImportRecheck::build leaves a safe build as it is (Custom HTML is the administrator\'s), sanitizes a risky one and keeps its Custom HTML; a second pass changes nothing', [
    Talea\Core\ImportRecheck::build($n63Safe) === $n63Safe, str_contains((string) $n63Risky, 'onclick'), str_contains((string) $n63Risky, 'javascript:'), str_contains((string) $n63Risky, '<script>own()</script>'),
    Talea\Core\ImportRecheck::build((string) $n63Risky) === $n63Risky, Talea\Core\ImportRecheck::build('not json')],
    [true, false, false, true, true, null]);
check('3.3.3 N63: the imported-content recheck runs as a background job and System status reports it', [
    Talea\Core\Scheduler::JOBS['import_recheck'][0] ?? null,
    str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Core/Health.php'), 'ImportRecheck::state('), Talea\Core\Settings::DEFAULTS['imported_recheck'] ?? null],
    [0, true, '']);
/* ---------- 3.3.3: authentication, sessions and page passwords ---------- */
$authSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Core/Auth.php');
preg_match('/public function login\(.*?\n    }\n/s', $authSource, $loginSource);
check('3.3.3 (N51): sign-in checks the lock and the block before the password, verifies the dummy hash for them, and answers every failure with one text', [
    // the account's own hash is used only when the account is neither locked nor blocked
    str_contains($loginSource[0] ?? '', '$closed = $user !== null && (!empty($user[\'blocked\']) || self::isLocked($user));')
        && str_contains($loginSource[0] ?? '', '$user !== null && !$closed ? (string) $user[\'password\'] : self::DUMMY_HASH'),
    substr_count($loginSource[0] ?? '', 'return t('), str_contains($loginSource[0] ?? '', "t('The account is"),
    isset($adminCs[Talea\Core\Auth::SIGN_IN_FAILED], $adminDe[Talea\Core\Auth::SIGN_IN_FAILED]), str_contains(Talea\Core\Auth::SIGN_IN_FAILED, 'reset your password')],
    [true, 2, false, true, true]);
check('3.3.3 (N61): the typed password is checked as it is; one saved trimmed before still opens', [
    Talea\Core\Auth::matchingPassword(' space-around-1 ', password_hash(' space-around-1 ', PASSWORD_DEFAULT)),
    Talea\Core\Auth::matchingPassword(' old-trimmed-12 ', password_hash('old-trimmed-12', PASSWORD_DEFAULT)),
    Talea\Core\Auth::matchingPassword('space-around-1', password_hash(' space-around-1 ', PASSWORD_DEFAULT)),
    Talea\Core\Auth::matchingPassword('wrong-password', password_hash('right-password', PASSWORD_DEFAULT))],
    [' space-around-1 ', 'old-trimmed-12', null, null]);
$now333 = 1_800_000_000;
check('3.3.3 (N60): a sign-in ends after 8 idle hours or 24 hours in total, however often the admin keeps it alive', [
    Talea\Core\Auth::sessionValid($now333 - 3600, $now333 - 60, $now333), Talea\Core\Auth::sessionValid($now333 - 9 * 3600, $now333 - 8 * 3600, $now333),
    Talea\Core\Auth::sessionValid($now333 - 24 * 3600, $now333 - 60, $now333), Talea\Core\Auth::sessionValid($now333 - 23 * 3600, $now333 - 7 * 3600, $now333),
    Talea\Core\Auth::IDLE_LIMIT, Talea\Core\Auth::SESSION_LIMIT,
    // the tokens of Claude connections are not sessions: the MCP server signs the user in without one
    str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Server.php'), '$this->app->auth()->signInAs($user);')],
    [true, false, false, true, 28800, 86400, true]);
$cloudflareSettings = $reportSettings(['trusted_proxy' => 'cloudflare']);
check('3.3.3 (N54): the sign-in, reset and MCP limits count the visitor behind Cloudflare, an IPv6 address by its /64', [
    Talea\Core\Antispam::visitorKey(new Talea\Core\Request([], [], ['REMOTE_ADDR' => '162.158.1.1', 'HTTP_CF_CONNECTING_IP' => '2001:db8:1:2:3:4:5:6']), $cloudflareSettings),
    Talea\Core\Antispam::visitorKey(new Talea\Core\Request([], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']), $cloudflareSettings),
    Talea\Core\Antispam::visitorKey(new Talea\Core\Request([], [], ['REMOTE_ADDR' => '162.158.1.1', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1']), $reportSettings(['trusted_proxy' => ''])),
    array_map(fn (string $file): bool => str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/' . $file . '.php'), 'Antispam::visitorKey(') && !str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/' . $file . '.php'), "hash('sha256', 'talea|' . \$this->app->request->ip())"),
        ['Admin/Kernel', 'Admin/PasswordReset', 'Mcp/Server'])],
    ['2001:db8:1:2::/64', '203.0.113.9', '162.158.1.1', [true, true, true]]);
check('3.3.3 (N62): the MCP wrong-token count only caps the rows it writes – it never refuses a request', [
    (bool) preg_match('/if \(\$token === null\) \{\s+if \(!\$limited\) \{\s+\$db->insert/', (string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Server.php')),
    str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Mcp/Server.php'), 'it never refuses a request')], [true, true]);
$pageLockSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Core/PageLock.php');
check('3.3.3 (N58): the page cap is checked only after a wrong password – the right one always opens the page', [
    strpos($pageLockSource, "'page-lock-all'") > strpos($pageLockSource, 'password_verify('), str_contains($pageLockSource, ", 'page-lock-all', self::WINDOW, false)")], [true, false]);
$mailSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Core/Mail.php');
check('3.3.3 (N59): the reset link is queued and sent after the response; the queue claims a message before sending it', [
    str_contains((string) file_get_contents(TALEA_SYSTEM . '/src/Admin/PasswordReset.php'), 'Mail::later('), str_contains((string) file_get_contents(TALEA_ROOT . '/admin.php'), 'Talea\Core\Mail::afterResponse($app);'),
    str_contains($mailSource, 'WHERE mail_id = ? AND attempts = ? AND sent_at IS NULL')], [true, true, true]);
$accountSource = (string) file_get_contents(TALEA_SYSTEM . '/src/Admin/Account.php');
check('3.3.3 (N56): a new e-mail and a new passkey need the current password; the old address hears about the change; the texts are translated', [
    substr_count($accountSource, "password_verify((string) (\$_POST['current_password'] ?? ''), \$user['password'])"), str_contains($accountSource, 'noticeOfNewEmail($user, $email)'),
    str_contains((string) file_get_contents(TALEA_ROOT . '/image/passkeys.js'), "current_password: password ? password.value : ''"),
    array_values(array_filter(['Enter your current password to change the e-mail address. Nothing was saved.', 'Enter your current password to add a passkey.', 'The e-mail address of your account was changed'],
        fn (string $k): bool => !isset($adminCs[$k], $adminDe[$k])))],
    [4, true, true, []]);
/* ---------- query parameters are English (the former Czech names must not come back) ---------- */
$czechParams = 'nahled_klic|nahled_konec|nahled|stavba|polozka|varianta|vysledek|upravit|uprava|uprav|strana|hledat|razeni|soubor|preklad_z|sekce|pohled|komentar|odhlasit|potvrdit|tema|typ|klic|stav|jazyk|cast|umisteni|pole|nova|nepouzite|osoba|sluzba|kdo|kde|kategorie|clanek|heslo|idr|dni|nadrazena|odber|mnozstvi|rezervace|formular|chyba|produkt';
$czechFound = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/system/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        $code = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/(?:request|\$r)->(?:get|getInt|has)\([\'"](' . $czechParams . ')[\'"]|[?&](' . $czechParams . ')=/', $code, $m)) {
            $czechFound[] = basename($file->getPathname()) . ': ' . implode(', ', array_filter(array_merge($m[1], $m[2])));
        }
    }
}
check('query parameters: no Czech parameter name in the PHP code (the interface is English)', $czechFound, []);

echo $errors === 0 ? "  ok     unit tests ({$total})\n" : "  ERRORS FOUND: {$errors} of {$total}\n";
exit($errors === 0 ? 0 : 1);
