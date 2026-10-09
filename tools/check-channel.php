<?php
/**
 * Verifies the update channel the way a Kaleta installation sees it: whatever is currently on the project website must be
 * signed with a key from the repository and the download package must match the signed hash. Runs daily in GitHub Actions
 * (.github/workflows/daily-check.yml) - catches a forged or damaged file before users' sites run into it.
 *
 *   php tools/check-channel.php [aktualizace.json url]
 *
 * Signs nothing and needs no private key.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/system/bootstrap.php';

use Kaleta\Core\Signature;

$manifestUrl = $argv[1] ?? 'https://kaletacms.com/aktualizace.json';
$keys = dirname(__DIR__) . '/system/aktualizace.pub';
$errors = [];
$download = static function (string $url): string {
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60, 'follow_location' => 1, 'max_redirects' => 5, 'header' => "User-Agent: Kaleta-kontrola\r\n"]]));
    if ($data === false) {
        throw new RuntimeException("nelze stáhnout $url");
    }

    return $data;
};

try {
    $m = json_decode($download($manifestUrl), true, 16, JSON_THROW_ON_ERROR);
    foreach (['verze', 'url', 'sha256', 'podpis'] as $key) {
        if (!is_string($m[$key] ?? null) || $m[$key] === '') {
            throw new RuntimeException("v souboru chybí položka $key");
        }
    }
    echo "Nabízená verze: {$m['verze']}" . (!empty($m['bezpecnostni']) ? ' (bezpečnostní)' : '') . "\n";
    if (!str_starts_with($m['url'], 'https://')) {
        $errors[] = 'adresa balíčku není https';
    }
    if (!Signature::isValid(Signature::packageMessage($m['verze'], strtolower($m['sha256']), !empty($m['bezpecnostni'])), $m['podpis'], $keys)) {
        $errors[] = 'podpis souboru aktualizace.json NEPLATÍ pro žádný klíč v system/aktualizace.pub';
    }
    $zip = tempnam(sys_get_temp_dir(), 'kaleta');
    file_put_contents($zip, $download($m['url']));
    if (!hash_equals(strtolower($m['sha256']), hash_file('sha256', $zip))) {
        $errors[] = 'otisk staženého balíčku neodpovídá podepsanému otisku';
    }
    // the package must not slip installations public keys other than those in the repository
    $archive = new ZipArchive();
    if ($archive->open($zip) === true) {
        $inPackage = $archive->getFromName('system/aktualizace.pub');
        $clean = static fn (string $s): array => array_values(array_filter(array_map(static fn (string $r): string => trim(explode(' ', trim($r))[0]), explode("\n", $s)), static fn (string $r): bool => $r !== '' && $r[0] !== '#'));
        if ($inPackage === false) {
            $errors[] = 'balíček neobsahuje system/aktualizace.pub';
        } elseif (array_diff($clean($inPackage), $clean((string) file_get_contents($keys))) !== []) {
            $errors[] = 'balíček obsahuje veřejný klíč, který v repozitáři není';
        }
        $archive->close();
    } else {
        $errors[] = 'balíček nejde otevřít jako ZIP';
    }
    @unlink($zip);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

if ($errors !== []) {
    fwrite(STDERR, "KANÁL AKTUALIZACÍ NENÍ V POŘÁDKU:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "Kanál aktualizací je v pořádku: podpis platí, balíček odpovídá otisku, klíče sedí.\n";
