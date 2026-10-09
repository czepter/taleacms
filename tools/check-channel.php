<?php
/**
 * Verifies the update channel the way a Kaleta installation sees it: whatever is currently on the project website must be
 * signed with a key from the repository and the download package must match the signed hash. Runs daily in GitHub Actions
 * (.github/workflows/daily-check.yml) - catches a forged or damaged file before users' sites run into it.
 *
 *   php tools/check-channel.php [update.json url]
 *
 * Signs nothing and needs no private key.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Signature;

$manifestUrl = $argv[1] ?? 'https://kaletacms.com/update.json';
$keys = dirname(__DIR__) . '/system/update.pub';
$errors = [];
$download = static function (string $url): string {
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta-check\r\n"]]));
    if ($data === false) {
        throw new RuntimeException("cannot download $url");
    }

    return $data;
};

try {
    $m = json_decode($download($manifestUrl), true, 16, JSON_THROW_ON_ERROR);
    foreach (['version', 'url', 'sha256', 'signature'] as $key) {
        if (!is_string($m[$key] ?? null) || $m[$key] === '') {
            throw new RuntimeException("the file lacks the item $key");
        }
    }
    echo "Offered version: {$m['version']}" . (!empty($m['security']) ? ' (security)' : '') . "\n";
    if (!str_starts_with($m['url'], 'https://')) {
        $errors[] = 'the package address is not https';
    }
    if (!Signature::isValid(Signature::packageMessage($m['version'], strtolower($m['sha256']), !empty($m['security'])), $m['signature'], $keys)) {
        $errors[] = 'the signature of update.json is INVALID for every key in system/update.pub';
    }
    $zip = tempnam(sys_get_temp_dir(), 'kaleta');
    file_put_contents($zip, $download($m['url']));
    if (!hash_equals(strtolower($m['sha256']), hash_file('sha256', $zip))) {
        $errors[] = 'the hash of the downloaded package does not match the signed hash';
    }
    // the package must not slip installations public keys other than those in the repository
    $archive = new ZipArchive();
    if ($archive->open($zip) === true) {
        $inPackage = $archive->getFromName('system/update.pub');
        $clean = static fn (string $s): array => array_values(array_filter(array_map(static fn (string $r): string => trim(explode(' ', trim($r))[0]), explode("\n", $s)), static fn (string $r): bool => $r !== '' && $r[0] !== '#'));
        if ($inPackage === false) {
            $errors[] = 'the package does not contain system/update.pub';
        } elseif (array_diff($clean($inPackage), $clean((string) file_get_contents($keys))) !== []) {
            $errors[] = 'the package contains a public key that is not in the repository';
        }
        $archive->close();
    } else {
        $errors[] = 'the package cannot be opened as a ZIP';
    }
    @unlink($zip);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($errors !== []) {
    fwrite(STDERR, "UPDATE CHANNEL IS NOT OK:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "Update channel is OK: the signature is valid, the package matches the hash, the keys match.\n";
