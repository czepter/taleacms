<?php
/**
 * Kaleta - release preparation (run by the publisher on their own computer, not uploaded to the web).
 *
 *   php tools/release.php 1.0.1 --url=https://github.com/phprs-cms/kaletacms/releases/download/v1.0.1/kaleta-1.0.1.zip \
 *       --change="Fix ..." --change="New ..." [--security]
 *
 * --security marks the release as a security fix: installations update to it by themselves and the administrator gets an e-mail.
 * Instead of a file, the private key can be passed in the KALETA_KEY environment variable (base64) - for releasing from GitHub Actions.
 *
 * Creates dist/kaleta-<version>.zip (files tracked by git) and dist/update.json signed with the private key.
 * Keys: tools/keys/publisher.key (primary) and tools/keys/backup.key (backup, should be kept offline) are PRIVATE - never into git.
 * system/update.pub carries the public keys (one per line), it is part of the system. Key rotation and revocation: docs/RELEASING.md.
 *   php tools/release.php --new-key=backup      creates a key pair and appends the public one to system/update.pub
 *   php tools/release.php 1.0.1 --key=backup …   signs the release with the backup key (the primary one lost or leaked)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Command line only.');
}
$root = dirname(__DIR__);
$version = $argv[1] ?? '';
$options = ['url' => '', 'changes' => [], 'security' => false, 'key' => 'operating', 'feed' => false, 'digest' => '', 'image' => ''];
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $options['url'] = substr($arg, 6);
    } elseif ($arg === '--feed') {
        $options['feed'] = true;
    } elseif (str_starts_with($arg, '--digest=')) {
        $options['digest'] = substr($arg, 9);
    } elseif (str_starts_with($arg, '--image=')) {
        $options['image'] = substr($arg, 8);
    } elseif ($arg === '--security') {
        $options['security'] = true;
    } elseif (str_starts_with($arg, '--change=')) {
        $options['changes'][] = substr($arg, 8);
    } elseif (str_starts_with($arg, '--key=')) {
        $options['key'] = substr($arg, 7);
    }
}
if (str_starts_with($version, '--new-key=')) {
    // a new key pair: the private one into tools/keys/ (never into git), the public one is APPENDED to system/update.pub
    require_once $root . '/system/src/Core/Signature.php';
    $name = substr($version, 12);
    $target = ['operating' => $root . '/tools/keys/publisher.key', 'backup' => $root . '/tools/keys/backup.key'][$name] ?? exit("Usage: --new-key=operating or --new-key=backup\n");
    if (is_file($target)) {
        exit("File {$target} already exists. When replacing a key, move it to an archive first - never overwrite it blindly.\n");
    }
    @mkdir(dirname($target), 0700, true);
    $pair = sodium_crypto_sign_keypair();
    file_put_contents($target, base64_encode(sodium_crypto_sign_secretkey($pair)) . "\n");
    chmod($target, 0600);
    $pk = sodium_crypto_sign_publickey($pair);
    $pub = $root . '/system/update.pub';
    file_put_contents($pub, rtrim((string) @file_get_contents($pub)) . "\n" . base64_encode($pk) . ' ' . $name . ' ' . date('Y-m-d') . ' id=' . Kaleta\Core\Signature::id($pk) . "\n");
    file_put_contents($pub, ltrim((string) file_get_contents($pub)));
    exit("New key \"{$name}\" (id " . Kaleta\Core\Signature::id($pk) . ") is in {$target}.\n"
        . "1) Back up the private file RIGHT NOW outside this computer" . ($name === 'backup' ? " and then delete it from the disk - the backup key is to be kept offline" : '') . ".\n"
        . "2) Commit system/update.pub; installations recognise the new key only after the release that brings it (signed with a key they already know).\n");
}
if (!preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version)) {
    exit("Usage: php tools/release.php <version> --url=<ZIP address> [--change=\"...\"]\n");
}
if (!str_contains((string) file_get_contents($root . '/system/bootstrap.php'), "const KALETA_VERSION = '{$version}';")) {
    exit("system/bootstrap.php does not have KALETA_VERSION = '{$version}'. Raise the version and commit the change first.\n");
}

// --- keys: system/update.pub carries several public keys (primary + backup), a signature is valid against any of them - see docs/RELEASING.md
require_once $root . '/system/src/Core/Signature.php';
$publicKeyFile = $root . '/system/update.pub';
$keyFiles = ['operating' => $root . '/tools/keys/publisher.key', 'backup' => $root . '/tools/keys/backup.key'];
$privateKeyFile = $keyFiles[$options['key']] ?? exit("Unknown key \"{$options['key']}\" - use --key=operating or --key=backup.\n");
$sk = base64_decode(trim(getenv('KALETA_KEY') !== false ? (string) getenv('KALETA_KEY') : (string) @file_get_contents($privateKeyFile)), true);
if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
    exit("Private key {$privateKeyFile} is missing or damaged. Create a new pair with: php tools/release.php --new-key={$options['key']}\n");
}
$keyId = Kaleta\Core\Signature::id(sodium_crypto_sign_publickey_from_secretkey($sk));
if (!isset(Kaleta\Core\Signature::keys($publicKeyFile)[$keyId])) {
    exit("Key {$keyId} is not listed in system/update.pub - installations would reject its signature.\n");
}

// --- feed mode (container releases, docs/specs/update-and-deployment.md): the CI published the image, this signs the feed entry for it
if ($options['feed']) {
    $digest = strtolower((string) preg_replace('/^sha256:/', '', $options['digest']));
    if (preg_match('/^[a-f0-9]{64}$/', $digest) !== 1 || $options['image'] === '') {
        exit("Feed mode needs --digest=sha256:<64 hex> (of the pushed image) and --image=<registry/name:version>.\n");
    }
    @mkdir($root . '/dist');
    file_put_contents($root . '/dist/update.json', json_encode([
        'version' => $version, 'released' => date('Y-m-d'), 'security' => $options['security'], 'changes' => $options['changes'],
        'image' => $options['image'], 'digest' => 'sha256:' . $digest, 'key' => $keyId,
        'signature' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage($version, $digest, $options['security']), $sk)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit("Done: dist/update.json for {$options['image']}. Upload it to the release as the asset update.json.\n");
}

// --- package from the files tracked by git
$files = array_filter(explode("\n", (string) shell_exec('cd ' . escapeshellarg($root) . ' && git ls-files')));
$exclude = ['tools/', 'docs/', '.github/', '.claude/', 'CLAUDE.md', '.gitignore', '.gitleaks.toml', '.git-blame-ignore-revs', 'phpstan.neon.dist', 'phpstan-baseline.neon', 'docker/', 'Dockerfile', 'docker-compose.yaml', 'docker-compose.coolify.yaml', '.dockerignore']; // the root CLAUDE.md is for development; layout/CLAUDE.md (layout rules) belongs in the package
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
$zip->addFromString('system/files.json', json_encode([
    'version' => $version, 'files' => $hashes, 'legacy' => $legacy,
    'signature' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Integrity::stringToSign($version, $hashes), $sk)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$zip->close();

$sha = hash_file('sha256', $zipFile);
$manifest = [
    'version' => $version, 'released' => date('Y-m-d'), 'url' => $options['url'], 'sha256' => $sha,
    'signature' => base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage($version, $sha, $options['security']), $sk)),
    'key' => $keyId, // only for reference, which key signed it; installations try all keys they know
    'min_php' => '8.4', 'security' => $options['security'], 'changes' => $options['changes'],
];
file_put_contents($root . '/dist/update.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo "Done: dist/kaleta-{$version}.zip (" . round(filesize($zipFile) / 1024) . " kB) and dist/update.json\n";
echo $options['url'] === '' ? "WARNING: no --url given - add the ZIP address to dist/update.json BEFORE signing (run again with --url).\n" : "1) Upload the ZIP to {$options['url']}\n2) Upload update.json to the project website.\n";
