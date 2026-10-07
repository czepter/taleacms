<?php
/**
 * Kaleta - release preparation (run by the publisher on their own computer, not uploaded to the web).
 *
 *   php tools/release.php 1.0.1 --url=https://github.com/phprs-cms/kaletacms/releases/download/v1.0.1/kaleta-1.0.1.zip \
 *       --zmena="Oprava ..." --zmena="Nové ..." [--bezpecnostni]
 *
 * --bezpecnostni marks the release as a security fix: installations update to it by themselves and the administrator gets an e-mail.
 * Instead of a file, the private key can be passed in the KALETA_KLIC environment variable (base64) - for releasing from GitHub Actions.
 *
 * Creates dist/kaleta-<version>.zip (files tracked by git) and dist/aktualizace.json signed with the private key.
 * Keys: tools/klice/vydavatel.key (primary) and tools/klice/zalozni.key (backup, should be kept offline) are PRIVATE - never into git.
 * system/aktualizace.pub carries the public keys (one per line), it is part of the system. Key rotation and revocation: docs/RELEASING.md.
 *   php tools/release.php --novy-klic=zalozni      creates a key pair and appends the public one to system/aktualizace.pub
 *   php tools/release.php 1.0.1 --klic=zalozni …   signs the release with the backup key (the primary one lost or leaked)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Jen z příkazové řádky.');
}
$root = dirname(__DIR__);
$version = $argv[1] ?? '';
$options = ['url' => '', 'zmeny' => [], 'bezpecnostni' => false, 'klic' => 'provozni'];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $options['url'] = substr($arg, 6);
    } elseif ($arg === '--bezpecnostni') {
        $options['bezpecnostni'] = true;
    } elseif (str_starts_with($arg, '--zmena=')) {
        $options['zmeny'][] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--klic=')) {
        $options['klic'] = substr($arg, 7);
    }
}
if (str_starts_with($version, '--novy-klic=')) {
    // a new key pair: the private one into tools/klice/ (never into git), the public one is APPENDED to system/aktualizace.pub
    require_once $root . '/system/src/Core/Signature.php';
    $name = substr($version, 12);
    $target = ['provozni' => $root . '/tools/klice/vydavatel.key', 'zalozni' => $root . '/tools/klice/zalozni.key'][$name] ?? exit("Použití: --novy-klic=provozni nebo --novy-klic=zalozni\n");
    if (is_file($target)) {
        exit("Soubor {$target} už existuje. Při výměně klíče ho nejdřív přesuňte do archivu - nikdy ho nepřepisujte naslepo.\n");
    }
    @mkdir(dirname($target), 0700, true);
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($target, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    chmod($target, 0600);
    $pk = sodium_crypto_sign_publickey($pair);
    $pub = $root . '/system/aktualizace.pub';
    file_put_contents($pub, rtrim((string) @file_get_contents($pub)) . "\n" . base64_encode($pk) . ' ' . $name . ' ' . date('Y-m-d') . ' id=' . Kaleta\Core\Signature::id($pk) . "\n");
    file_put_contents($pub, ltrim((string) file_get_contents($pub)));
    exit("Nový klíč „{$name}“ (id " . Kaleta\Core\Signature::id($pk) . ") je v {$target}.\n"
        . "1) Soukromý soubor si HNED zazálohujte mimo tento počítač" . ($name === 'zalozni' ? " a z disku ho pak smažte - záložní klíč má ležet offline" : '') . ".\n"
        . "2) system/aktualizace.pub commitněte; instalace nový klíč poznají až po vydání, které ho přinese (podepsaném klíčem, který už znají).\n");
}
if (!preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version)) {
    exit("Použití: php tools/release.php <verze> --url=<adresa ZIPu> [--zmena=\"...\"]\n");
}
if (!str_contains((string) file_get_contents($root . '/system/bootstrap.php'), "const KALETA_VERSION = '{$version}';")) {
    exit("V system/bootstrap.php není KALETA_VERSION = '{$version}'. Nejprve zvyšte verzi a změnu commitněte.\n");
}
// the oldest PHP the release runs on: sites on an older one are not offered it (Core\Updater::state) and refuse to install it
if (!preg_match("/const KALETA_MIN_PHP = '(\\d+\\.\\d+)';/", (string) file_get_contents($root . '/system/bootstrap.php'), $minPhp)) {
    exit("V system/bootstrap.php chybí KALETA_MIN_PHP.\n");
}

// --- keys: system/aktualizace.pub carries several public keys (primary + backup), a signature is valid against any of them - see docs/RELEASING.md
require_once $root . '/system/src/Core/Signature.php';
$publicKeyFile = $root . '/system/aktualizace.pub';
$keyFiles = ['provozni' => $root . '/tools/klice/vydavatel.key', 'zalozni' => $root . '/tools/klice/zalozni.key'];
$privateKeyFile = $keyFiles[$options['klic']] ?? exit("Neznámý klíč „{$options['klic']}“ - použijte --klic=provozni nebo --klic=zalozni.\n");
$sk = base64_decode(trim(getenv('KALETA_KLIC') !== false ? (string) getenv('KALETA_KLIC') : (string) @file_get_contents($privateKeyFile)), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    exit("Soukromý klíč {$privateKeyFile} chybí nebo je poškozený. Nový pár založíte příkazem: php tools/release.php --novy-klic={$options['klic']}\n");
}
$keyId = Kaleta\Core\Signature::id(sodium_crypto_sign_publickey_from_secretkey($sk));
if (!isset(Kaleta\Core\Signature::keys($publicKeyFile)[$keyId])) {
    exit("Klíč {$keyId} není uveden v system/aktualizace.pub - instalace by jeho podpis odmítly.\n");
}

// --- package from the files tracked by git
$files = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files')));
$exclude = ['tools/', 'docs/', '.github/', '.claude/', 'CLAUDE.md', '.gitignore', '.gitleaks.toml', '.git-blame-ignore-revs', 'phpstan.neon.dist', 'phpstan-baseline.neon', 'docker/', 'Dockerfile', 'docker-compose.yaml', '.dockerignore']; // the root CLAUDE.md is for development; layout/CLAUDE.md (layout rules) belongs in the package
@mkdir($root . '/dist');
$zipFile = $root . "/dist/kaleta-{$version}.zip";
@unlink($zipFile);
$zip = new ZipArchive();
$zip->open($zipFile, ZipArchive::CREATE);
$hashes = [];
foreach ($files as $file) {
    foreach ($exclude as $v) {
        if ($file === $v || str_starts_with($file, $v)) {
            continue 2;
        }
    }
    $zip->addFile($root . '/' . $file, $file);
    // the list of core files with hashes: by it an installation recognizes changed, missing and added files (Core\Integrity)
    // without user folders and without install.php (an update does not overwrite it and the administrator may delete it after installation)
    if (!preg_match('#^(media|storage)/|^install\.php$#', $file)) {
        $hashes[$file] = hash_file('sha256', $root . '/' . $file);
    }
}
// classes of older releases that this one renamed or removed ride along unchanged: an older release installs this package
// and, in the same request, may still load its own classes after its cleanup. Every earlier release counts, not only the
// previous one – a site may skip releases (1.3 straight to 1.4.1). Each file comes from the newest release that had it.
// The new version deletes them on the first admin load (Updater::cleanUpRemoved, 'legacy' list).
$legacy = [];
$git = fn (string $args): string => (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ' . $args . ' 2>/dev/null');
foreach (array_filter(explode("\n", $git('tag --sort=-v:refname --merged HEAD^ "v*"'))) as $release) {
    // system/class-aliases.php (1.4–2.0): the autoloader of 1.x requires it on a class it cannot find
    foreach (array_filter(explode("\n", $git('ls-tree -r --name-only ' . escapeshellarg($release) . ' -- system/src/ system/class-aliases.php'))) as $old) {
        if (!in_array($old, $files, true) && !isset($legacy[$old])) {
            $content = $git('show ' . escapeshellarg($release . ':' . $old));
            $zip->addFromString($old, $content);
            $legacy[$old] = hash('sha256', $content);
        }
    }
}
require_once $root . '/system/src/Core/Integrity.php';
ksort($hashes);
$zip->addFromString('system/soubory.json', json_encode([
    'verze' => $version, 'soubory' => $hashes, 'legacy' => $legacy,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Integrity::stringToSign($version, $hashes), $sk)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$zip->close();

$sha = hash_file('sha256', $zipFile);
$manifest = [
    'verze' => $version, 'vydano' => date('Y-m-d'), 'url' => $options['url'], 'sha256' => $sha,
    'podpis' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage($version, $sha, $options['bezpecnostni']), $sk)),
    'klic' => $keyId, // only for reference, which key signed it; installations try all keys they know
    'min_php' => $minPhp[1], 'bezpecnostni' => $options['bezpecnostni'], 'zmeny' => $options['zmeny'],
];
file_put_contents($root . '/dist/aktualizace.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Hotovo: dist/kaleta-{$version}.zip (" . round(filesize($zipFile) / 1024) . " kB) a dist/aktualizace.json\n";
echo $options['url'] === '' ? "POZOR: nezadali jste --url, doplňte adresu ZIPu do dist/aktualizace.json PŘED podpisem (spusťte znovu s --url).\n" : "1) ZIP nahrajte na {$options['url']}\n2) aktualizace.json nahrajte na web projektu.\n";
