<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Core integrity: the release package carries a signed list of files with hashes (system/files.json).
 * By it the system health check recognizes core files that someone changed, deleted or added. Edits of the core are
 * not supported - only media/, storage/ and config.php are the site's own; an update
 * returns the core to its original form.
 */
final class Integrity
{
    private const string CATALOG = KALETA_SYSTEM . '/files.json';

    /** @return array{stav:string, info:string, zmenene:list<string>, chybi:list<string>, navic:list<string>} */
    public static function check(string $keyFile = KALETA_SYSTEM . '/update.pub'): array
    {
        $empty = ['zmenene' => [], 'chybi' => [], 'navic' => []];
        if (!is_file(self::CATALOG)) {
            return ['status' => 'ok', 'info' => t('development version without a file list – the check only applies to released packages')] + $empty;
        }
        $data = json_decode((string) file_get_contents(self::CATALOG), true);
        $files = is_array($data['files'] ?? null) ? $data['files'] : null;
        if ($files === null || !Signature::isValid(self::stringToSign((string) ($data['version'] ?? ''), $files), (string) ($data['signature'] ?? ''), $keyFile)) {
            return ['status' => 'error', 'info' => t('the core file list (system/files.json) is damaged or lacks a valid publisher signature')] + $empty;
        }
        $changed = $missing = [];
        foreach ($files as $path => $hash) {
            $file = KALETA_ROOT . '/' . $path;
            if (!is_file($file)) {
                $missing[] = $path;
            } elseif (!hash_equals((string) $hash, hash_file('sha256', $file))) {
                $changed[] = $path;
            }
        }
        // PHP files that do not belong to the core (site root and system/) - a typical trace of a compromised site
        $extra = [];
        $candidates = glob(KALETA_ROOT . '/*.php') ?: [];
        $tree = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_SYSTEM, \FilesystemIterator::SKIP_DOTS));
        foreach ($tree as $f) {
            if ($f->isFile() && preg_match('/\.(php\d?|phtml|phar)$/i', $f->getFilename())) {
                $candidates[] = $f->getPathname();
            }
        }
        foreach ($candidates as $file) {
            $path = ltrim(str_replace('\\', '/', substr($file, strlen(KALETA_ROOT))), '/');
            if (!isset($files[$path]) && !in_array($path, ['config.php', 'install.php'], true)) {
                $extra[] = $path;
            }
        }
        $count = count($changed) + count($missing) + count($extra);

        return [
            'status' => $count === 0 ? 'ok' : 'varovani',
            'info' => $count === 0 ? t('all %d core files match release %s', count($files), (string) ($data['version'] ?? ''))
                : t('the core differs from the release: %d changed, %d missing, %d extra – %s', count($changed), count($missing), count($extra), implode(', ', array_slice([...$changed, ...$missing, ...$extra], 0, 6)) . ($count > 6 ? '…' : ''))
                    . '. ' . t('Changes to the core are not supported; an update restores the original files (Backups and updates).'),
            'zmenene' => $changed, 'chybi' => $missing, 'navic' => $extra,
        ];
    }

    /**
     * The text that the publisher signs (tools/release.php) and the installation verifies.
     *
     * @param array<string, string> $files path => sha256
     */
    public static function stringToSign(string $version, array $files): string
    {
        ksort($files);

        return 'kaleta-files|' . $version . '|' . hash('sha256', (string) json_encode($files, JSON_UNESCAPED_SLASHES));
    }
}
