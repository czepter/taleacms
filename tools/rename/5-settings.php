<?php
/**
 * Step 5 of the English identifiers (docs/glossary.md): settings keys (ka_nastaveni.promenna). The old => new list is
 * Core\Settings::LEGACY_KEYS; migration 0026 renames the stored rows (written by this script). Keys are replaced only where
 * they are certainly settings keys:
 *   1. ->get('…') ->int('…') ->bool('…') ->set('…') everywhere (PHP, templates, layouts, tools)
 *   2. SQL on ka_nastaveni: promenna = '…', promenna IN (…), INSERT/REPLACE … VALUES ('…', …) (also in the shell tests)
 *   3. every quoted key in the files that list settings (below) – except 'statistika' and 'rozsireni', which are also an
 *      extension key and a form field there
 * Not touched: build JSON values, database column aliases, public template data keys, installer form fields.
 *   php tools/rename/5-settings.php [--apply]
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/system/bootstrap.php';
$map = Kaleta\Core\Settings::LEGACY_KEYS;
$apply = in_array('--apply', $argv, true);
$alt = implode('|', array_map(fn (string $k): string => preg_quote($k, '/'), array_keys($map)));

$listing = [
    'system/src/Core/Settings.php', 'system/src/Admin/Modules/Settings.php', 'system/src/Mcp/Translator.php', 'system/src/Core/SiteExport.php',
    'system/src/Admin/Modules/Appearance.php', 'system/src/Builder/Elements/SocialLinks.php', 'system/views/admin/appearance/list.php',
];
$files = [];
foreach (['system', 'layout', 'tools'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if ($f->isFile() && preg_match('/\.(php|sh|mjs)$/', $rel) && !str_starts_with($rel, 'tools/rename/') && !str_starts_with($rel, 'system/languages/')
            && $rel !== 'system/src/Admin/LegacyUrls.php') {
            $files[] = $rel;
        }
    }
}
array_push($files, 'admin.php', 'index.php', 'install.php');

$count = 0;
$changed = [];
foreach ($files as $rel) {
    $code = (string) file_get_contents("$root/$rel");
    // the list of old keys itself stays as it is
    $keep = '';
    if ($rel === 'system/src/Core/Settings.php') {
        $start = strpos($code, 'public const array LEGACY_KEYS = [');
        $end = strpos($code, '];', $start) + 2;
        $keep = substr($code, $start, $end - $start);
        $code = substr($code, 0, $start) . '@@LEGACY_KEYS@@' . substr($code, $end);
    }
    $new = $code;
    $swap = function (array $m) use ($map, &$count): string {
        $count++;

        return $m[1] . $m[2] . $map[$m[3]] . $m[2];
    };
    // 1) settings calls
    $new = (string) preg_replace_callback("/(->(?:get|int|bool|set)\\(\\s*)(['\"])($alt)\\2/", $swap, $new);
    // 2) SQL on the settings table
    $new = (string) preg_replace_callback("/(promenna\\s*=\\s*)(\\\\?['\"])($alt)\\2/i", fn (array $m): string => $m[1] . $m[2] . $map[$m[3]] . $m[2], $new);
    $new = (string) preg_replace_callback('/promenna\s+IN\s*\(([^)]*)\)/i', fn (array $m): string => (string) preg_replace_callback("/(['\"])($alt)\\1/", fn (array $k): string => $k[1] . $map[$k[2]] . $k[1], $m[0]), $new);
    $new = (string) preg_replace_callback('/^.*(INTO\s+(?:ka_|\{)nastaveni|nastaveni\}?\s*\(promenna).*$/mi', fn (array $m): string => (string) preg_replace_callback("/(\\(\\s*)(['\"])($alt)\\2(\\s*,)/", fn (array $k): string => $k[1] . $k[2] . $map[$k[3]] . $k[2] . $k[4], $m[0]), $new);
    // 3) files that list settings
    if (in_array($rel, $listing, true) || str_starts_with($rel, 'system/views/admin/settings/')) {
        $plain = array_diff_key($map, ['statistika' => 1, 'rozsireni' => 1]);
        $palt = implode('|', array_map(fn (string $k): string => preg_quote($k, '/'), array_keys($plain)));
        $new = (string) preg_replace_callback("/(['\"])($palt)\\1/", fn (array $m): string => $m[1] . $plain[$m[2]] . $m[1], $new);
    }
    if ($keep !== '') {
        $new = str_replace('@@LEGACY_KEYS@@', $keep, $new);
        $code = str_replace('@@LEGACY_KEYS@@', $keep, $code);
    }
    if ($new !== $code) {
        $changed[$rel] = $new;
    }
}

// migration 0026: rename the stored rows; a row whose current key exists already (written again by the release that ran the
// update) is dropped – so the migration can run twice
$sql = "-- Settings keys in English (Core\\Settings::LEGACY_KEYS, written by tools/rename/5-settings.php). Repeatable: a row is renamed\n"
    . "-- only when the current key is not there yet; what is left under an old key is removed.\n";
foreach ($map as $old => $new) {
    $sql .= "UPDATE IGNORE ka_nastaveni SET promenna = '$new' WHERE promenna = '$old';\n";
}
foreach (['nazev_webu', 'popis_webu'] as $old) { // per-language keys: nazev_webu_en -> site_name_en
    $like = str_replace('_', '\\\\_', $old) . '\\\\_%';
    $sql .= "UPDATE IGNORE ka_nastaveni SET promenna = CONCAT('{$map[$old]}', SUBSTRING(promenna, " . (strlen($old) + 1) . ")) WHERE promenna LIKE '$like';\n";
}
$sql .= "DELETE FROM ka_nastaveni WHERE promenna IN ('" . implode("', '", array_keys($map)) . "') OR promenna LIKE 'nazev\\\\_webu\\\\_%' OR promenna LIKE 'popis\\\\_webu\\\\_%';\n";

echo count($changed) . " files change, $count references\n";
if (!$apply) {
    echo "dry run – add --apply\n";
    exit(0);
}
foreach ($changed as $rel => $code) {
    file_put_contents("$root/$rel", $code);
}
file_put_contents("$root/system/sql/migrace/0026-settings-keys.sql", $sql);
echo "applied; migration 0026 written – set KALETA_DB_VERSION to 26\n";
