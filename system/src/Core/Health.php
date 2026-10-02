<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * System health (health check): a set of quick checks of the server, database, security and operation.
 * The result is shown in Settings and is also available as JSON for monitoring (/stav.json?token=...).
 */
final class Health
{
    /**
     * @return list<array{skupina:string, nazev:string, stav:string, info:string}> stav: ok | varovani | chyba
     */
    public static function checks(App $app): array
    {
        $k = [];
        $add = function (string $group, string $name, bool|string $state, string $info) use (&$k): void {
            $k[] = ['skupina' => $group, 'nazev' => $name, 'stav' => is_bool($state) ? ($state ? 'ok' : 'chyba') : $state, 'info' => $info];
        };
        $db = $app->db();
        $siteSettings = $app->settings();

        // --- server (names and texts go through t(); the "stav" values are not translated - monitoring reads them)
        $add(t('Server'), t('PHP version'), PHP_VERSION_ID >= 80400, PHP_VERSION_ID >= 80400 ? PHP_VERSION : t('%s - the system requires 8.4 or newer', PHP_VERSION));
        foreach (['pdo_mysql' => t('database'), 'mbstring' => t('text with diacritics'), 'gd' => t('image processing')] as $ext => $purpose) {
            $add(t('Server'), t('Extension %s', $ext), extension_loaded($ext), extension_loaded($ext) ? $purpose : t('%s - missing', $purpose));
        }
        foreach (['exif' => t('correct rotation of photos from phones'), 'intl' => t('language-aware sorting'), 'curl' => t('notifying search engines about new content')] as $ext => $purpose) {
            $add(t('Server'), t('Extension %s', $ext), extension_loaded($ext) ? 'ok' : 'varovani', extension_loaded($ext) ? $purpose : t('%s - installing it is recommended', $purpose));
        }
        $add(t('Server'), t('Upload size limit'), self::bytes((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024 ? 'ok' : 'varovani', 'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size'));
        $freeSpace = @disk_free_space(KALETA_ROOT);
        if ($freeSpace !== false) {
            $add(t('Server'), t('Free disk space'), $freeSpace > 200 * 1024 * 1024 ? 'ok' : 'varovani', self::size((int) $freeSpace));
        }

        // --- database
        $add(t('Database'), t('Server'), 'ok', (string) $db->value('SELECT VERSION()'));
        $pending = Migration::latest() - max(1, $siteSettings->int('db_version'));
        $add(t('Database'), t('Database structure'), $pending <= 0, $pending <= 0 ? t('up to date (version %d)', $siteSettings->int('db_version')) : t('%d updates pending - they will run the next time the administration loads', $pending));
        $size = (int) $db->value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [addcslashes($db->prefix, '_%') . '%']);
        $add(t('Database'), t('Velikost'), 'ok', t('%s, news items: %d', self::size($size), (int) $db->value('SELECT COUNT(*) FROM {novinky}')));

        // --- files and security
        foreach (['media' => t('uploaded images'), 'storage/log' => t('error log'), 'storage/cache' => t('temporary data')] as $folder => $purpose) {
            $ok = is_dir(KALETA_ROOT . '/' . $folder) ? is_writable(KALETA_ROOT . '/' . $folder) : is_writable(KALETA_ROOT);
            $add(t('Files'), t('Write access to %s/', $folder), $ok, $ok ? $purpose : t('%s - set write permissions', $purpose));
        }
        $add(t('Bezpečnost'), t('Instalátor'), !is_file(KALETA_ROOT . '/install.php') ? 'ok' : 'varovani', is_file(KALETA_ROOT . '/install.php') ? t('install.php is still on the server - delete it') : t('install.php has been removed'));
        $add(t('Bezpečnost'), 'HTTPS', $app->request->isHttps() ? 'ok' : 'varovani', $app->request->isHttps() ? t('the site runs over an encrypted connection') : t('the site does not run over HTTPS - sign-in details travel unencrypted'));
        $add(t('Bezpečnost'), t('Debug mode'), !$app->debug(), $app->debug() ? t('debug = true is set in config.php; turn it off on a live site') : t('vypnutý'));
        $add(t('Bezpečnost'), t('Security headers'), 'ok', t('the system sends X-Content-Type-Options, Referrer-Policy and X-Frame-Options; the administration also sends a Content-Security-Policy and forbids caching'));
        $without2fa = (int) $db->value("SELECT COUNT(*) FROM {uzivatele} WHERE admin = 2 AND blokovat = 0 AND totp_tajemstvi = ''");
        $add(t('Bezpečnost'), t('Two-factor sign-in for administrators'), $without2fa === 0 ? 'ok' : 'varovani', $without2fa === 0 ? t('all administrators have it') : t('%d administrator(s) do not have it - it is turned on under My account (avatar at the top right)', $without2fa));
        $blockedCount = (int) $db->value('SELECT COUNT(*) FROM {uzivatele} WHERE blokovat = 1');
        $add(t('Bezpečnost'), t('Blocked accounts'), $blockedCount === 0 ? 'ok' : 'varovani', $blockedCount === 0 ? t('žádné') : t('%d - blocked by an administrator; you can unblock them in Users', $blockedCount));

        $core = Integrity::check();
        $add(t('Bezpečnost'), t('Core files'), $core['stav'], $core['info']);

        // --- operation
        $log = KALETA_ROOT . '/storage/log/chyby.log';
        $errorCount = 0;
        if (is_file($log)) {
            $from = date('c', time() - 86400);
            foreach (array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -500) as $row) {
                $errorCount += (int) (substr($row, 1, 25) >= $from);
            }
        }
        $add(t('Operation'), t('Errors in the last 24 hours'), $errorCount === 0 ? 'ok' : 'varovani', $errorCount === 0 ? t('žádné') : t('%d - details in storage/log/chyby.log', $errorCount));
        $last = Backup::listAll()[0]['cas'] ?? 0;
        $age = $last > 0 ? (int) floor((time() - $last) / 86400) : null;
        $add(t('Operation'), t('Database backup'), $age !== null && $age <= 8 ? 'ok' : 'varovani', $age === null ? t('none yet - create one on the Backups and updates tab') : ($age === 0 ? t('today') : t('%d days ago', $age)) . ', ' . ($siteSettings->bool('auto_backups') ? t('automatic backups on') : t('automatic backups off')));
        $add(t('Operation'), t('Search engine indexing'), $siteSettings->bool('indexing') ? 'ok' : 'varovani', $siteSettings->bool('indexing') ? t('allowed') : t('disabled on the SEO and GEO tab - the site will not appear in search results'));
        $media = 0;
        if (is_dir(KALETA_ROOT . '/media')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS)) as $file) {
                $media += $file->getSize();
            }
        }
        $add(t('Operation'), t('Media size'), 'ok', self::size($media));
        $remote = explode('|', $app->settings()->get('remote_backup_status'), 2);
        if ($app->settings()->get('remote_backup') !== 'vypnuto') {
            $add(t('Operation'), t('Off-site backups'), ($remote[1] ?? '') === 'ok' ? 'ok' : 'varovani', ($remote[1] ?? '') === 'ok' ? t('last copy uploaded %s', $remote[0]) : (($remote[1] ?? '') !== '' ? t('last attempt %s failed: %s', $remote[0], $remote[1]) : t('no copy has been made yet')));
        } else {
            $add(t('Operation'), t('Off-site backups'), 'varovani', t('off – backups are stored only on the same server as the site (Settings → Backups and updates)'));
        }
        $smtp = $app->settings()->get('mail_mode') === 'smtp' && $app->settings()->get('smtp_host') !== '';
        // background tasks (scheduled news items, mail queue, push, newsletter) are run by site visits or cron
        $lastRun = $siteSettings->int('notification_check');
        $before = $lastRun > 0 ? (int) floor((time() - $lastRun) / 60) : null;
        $add(t('Operation'), t('Background tasks'), $before !== null && $before <= 30 ? 'ok' : 'varovani', $before === null
            ? t('have not run yet – the first visit to the site will start them')
            : ($before <= 30 ? t('last run %d min ago', $before) : ($before < 120 ? t('last run %d min ago', $before) : t('last run %d h ago', (int) round($before / 60)))
                . ' – ' . t('on a low-traffic site set up cron; you will find the address further down this page')));
        // themeless since 1.6: a custom PHP layout left in layout/ is no longer used
        $leftover = array_map('basename', array_map('dirname', glob(KALETA_ROOT . '/layout/*/base.php') ?: []));
        if ($leftover !== []) {
            $add(t('Operation'), t('Custom layout'), 'varovani', t('%s in the layout/ folder is no longer used – since 1.6 the look comes only from Site appearance and the builder. Move what you need into shared classes and site parts, then delete the folder.', implode(', ', $leftover)));
        }
        $given = (int) $db->value('SELECT COUNT(*) FROM {webhook_deliveries} WHERE delivered IS NULL AND next_attempt IS NULL AND created > NOW() - INTERVAL 7 DAY');
        if ($given > 0 || $siteSettings->get('webhook_url') !== '' || $siteSettings->get('webhook_enquiries') !== '') {
            $add(t('Operation'), t('Webhooks'), $given === 0 ? 'ok' : 'varovani', $given === 0 ? t('all calls of the last 7 days delivered') : t('%d call(s) of the last 7 days not delivered – see Settings → Webhooks', $given));
        }
        $cron = $siteSettings->int('tasks_last_run');
        $cronMinutes = $cron > 0 ? (int) floor((time() - $cron) / 60) : null;
        $add(t('Operation'), t('Cron'), $cronMinutes !== null && $cronMinutes <= Mailing::CRON_MINUTES ? 'ok' : 'varovani', match (true) {
            $cronMinutes === null => t('not set up – scheduled work waits for visits, and newsletters cannot be sent; you will find the address further down this page'),
            $cronMinutes <= Mailing::CRON_MINUTES => t('last run %d min ago', $cronMinutes),
            default => t('last run %s – newsletters are not being sent until cron runs again', format_date((new \DateTimeImmutable())->setTimestamp($cron), true)),
        });
        $update = (new Updater($siteSettings))->state();
        $add(t('Operation'), t('Updates'), !$update['nastaveno'] || $update['chyba'] !== null || $update['nova'] !== null ? 'varovani' : 'ok', match (true) {
            !$update['nastaveno'] => t('no update source is set'),
            $update['chyba'] !== null => t('the update source is not responding: %s', (string) $update['chyba']),
            $update['nova'] !== null => t('version %s is available (Settings → Backups and updates)', (string) $update['nova']['verze']),
            default => t('the system is up to date (%s)', KALETA_VERSION) . ($update['overeno'] > 0 ? ', ' . t('checked %s', format_date((new \DateTimeImmutable())->setTimestamp((int) $update['overeno']), true)) : ''),
        });
        // 2.8: background jobs (Core\Scheduler) and the problems of the last week (Core\Events)
        $failing = array_filter(Scheduler::overview($db), fn (array $j): bool => $j['failures'] > 0);
        $add(t('Operation'), t('Background jobs'), $failing === [] ? 'ok' : (max(array_column($failing, 'failures')) >= Scheduler::FAILURES_TO_ALERT ? 'chyba' : 'varovani'),
            $failing === [] ? t('every job worked the last time it ran') : implode('; ', array_map(fn (array $j): string => t('%s failed %d× in a row: %s', t($j['label']), $j['failures'], $j['last_error']), $failing)));
        $problems = Events::problems($db, 168);
        if ($problems !== []) {
            $add(t('Operation'), t('Problems in the last 7 days'), 'varovani', implode(', ', array_map(fn (string $type, int $n): string => $type . ' ×' . $n, array_keys($problems), $problems)));
        }
        $add(t('Operation'), t('Mail delivery'), $smtp || function_exists('mail') ? 'ok' : 'varovani', $smtp ? t('via SMTP server %s', $app->settings()->get('smtp_host')) : (function_exists('mail') ? t('using the server\'s mail() function – SMTP is more reliable (Settings → Mail)') : t('the mail() function is disabled – set up SMTP (Settings → Mail)')));

        return $k;
    }

    /** Summary for monitoring: the worst status found. */
    public static function summary(array $checks): string
    {
        $statuses = array_column($checks, 'stav');

        return in_array('chyba', $statuses, true) ? 'chyba' : (in_array('varovani', $statuses, true) ? 'varovani' : 'ok');
    }

    private static function size(int $byteCount): string
    {
        foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $unit) {
            if ($byteCount < 1024 || $unit === 'TB') {
                return format_number($byteCount, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $byteCount /= 1024;
        }

        return '';
    }

    private static function bytes(string $ini): int
    {
        $number = (int) $ini;

        return match (strtoupper(substr(trim($ini), -1))) {
            'G' => $number * 1024 ** 3, 'M' => $number * 1024 ** 2, 'K' => $number * 1024, default => $number,
        };
    }
}
