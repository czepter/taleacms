<?php

declare(strict_types=1);

namespace Talea\Fleet;

use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Events;
use Talea\Core\Response;
use Talea\Core\Settings;

/**
 * The console's side (2.9): a Talea install with the extension "fleet" keeps the sites paired with it.
 *
 *  - Pairing: the console makes a one-time code (24 hours); the site sends it with its public key, signed by that key.
 *  - Heartbeat: each site reports every hour, signed; an older or repeated heartbeat is refused. The console stores the
 *    last one and answers, signed by its own key (and announces the newest design kit, Fleet\Kit).
 *  - Versions: the console shows which version each site runs; sites update by pulling a new image, never through the console.
 *  - Uptime: the console asks each site's home page every 5 minutes; down twice in a row = the event fleet.site_down.
 * The console never calls into a site and holds no credentials for it: it only answers the sites.
 */
final class Console
{
    public const int CODE_HOURS = 24;
    public const int SILENT_AFTER = 26 * 3600;
    public const int DOWN_AFTER = 2;

    /** What a heartbeat may carry (Fleet\Heartbeat) – anything else is dropped. */
    private const array HEARTBEAT_KEYS = ['name', 'url', 'version', 'php', 'schema_version', 'status', 'problems', 'jobs_failing', 'cron_last_run', 'last_backup', 'offsite_backup',
        'enquiries_unanswered', 'enquiries_7_days', 'visits_7_days', 'audit', 'problems_7_days', 'claude', 'kit_version', 'error_reporting'];

    /** Reasons for attention => weight; the list on the console is sorted by the sum. */
    public const array REASONS = [
        'down' => 100, 'not_reporting' => 80, 'errors' => 60, 'jobs_failing' => 40, 'no_backup' => 30,
        'no_heartbeat' => 20, 'warnings' => 10, 'no_cron' => 5, 'enquiries' => 5, 'audit' => 2,
    ];

    /** A new one-time pairing key (shown once). */
    public static function newPairingKey(App $app): string
    {
        $s = $app->settings();
        $code = bin2hex(random_bytes(16));
        $db = $app->db();
        $db->run('DELETE FROM {fleet_pairing} WHERE expires_at < NOW() - INTERVAL 7 DAY');
        $db->insert('fleet_pairing', ['code_hash' => hash('sha256', $code), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_HOURS * 3600)]);

        return Link::makeKey(rtrim($s->get('site_url'), '/'), $code, Keys::publicKey($s), $s->get('site_name'));
    }

    /** POST /fleet/pair */
    public static function pair(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        if (!is_array($d) || ($d['action'] ?? '') !== 'pair' || !is_string($d['code'] ?? null) || !is_string($d['public_key'] ?? null) || !Keys::isPublicKey($d['public_key'])
            || !is_string($d['url'] ?? null) || preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~i', $d['url']) !== 1) {
            return Response::json(['error' => 'Not a pairing request.'], 400);
        }
        if (!Keys::verify($body, $signature, $d['public_key']) || abs(time() - (int) ($d['ts'] ?? 0)) > Link::MAX_SKEW) {
            return Response::json(['error' => 'The signature or the time of the request is not valid.'], 403);
        }
        $db = $app->db();
        $hash = hash('sha256', $d['code']);
        if ($db->value('SELECT 1 FROM {fleet_pairing} WHERE code_hash = ? AND used_at IS NULL AND expires_at > NOW()', [$hash]) === null) {
            return Response::json(['error' => 'The pairing key is unknown, used or expired – make a new one on the console.'], 403);
        }
        $row = ['name' => mb_substr((string) ($d['name'] ?? ''), 0, 150), 'url' => mb_substr(rtrim($d['url'], '/'), 0, 255), 'version' => mb_substr((string) ($d['version'] ?? ''), 0, 30)];
        $existing = $db->value('SELECT id FROM {fleet_sites} WHERE public_key = ?', [$d['public_key']]);
        if ($existing !== null) {
            $id = (int) $existing; // the same site paired again (e.g. after it disconnected): its history stays
            $db->update('fleet_sites', $row + ['last_ts' => 0], ['id' => $id]);
        } else {
            $id = $db->insert('fleet_sites', $row + ['public_key' => $d['public_key'], 'paired_at' => date('Y-m-d H:i:s'), 'version_since' => date('Y-m-d H:i:s')]);
        }
        $db->update('fleet_pairing', ['used_at' => date('Y-m-d H:i:s'), 'site_id' => $id], ['code_hash' => $hash]);
        Events::record($db, 'fleet.site_paired', 'info', t('%s was paired with the console.', $row['url']), ['site' => $id]);

        return self::signed($app->settings(), ['ok' => true, 'site_id' => $db->publicId('fleet_sites', $id), 'console_name' => $app->settings()->get('site_name')]);
    }

    /** POST /fleet/heartbeat */
    public static function heartbeat(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        $db = $app->db();
        $site = is_array($d) ? $db->byPublicId('fleet_sites', $d['site_id'] ?? null) : null; // the site's public id: its row number never leaves the console
        if ($site === null) {
            return Response::json(['error' => 'This console does not know the site.'], 404);
        }
        if (!Keys::verify($body, $signature, (string) $site['public_key'])) {
            return Response::json(['error' => 'The signature is not valid.'], 403);
        }
        $ts = (int) ($d['ts'] ?? 0);
        if (abs(time() - $ts) > Link::MAX_SKEW || $ts <= (int) $site['last_ts']) {
            return Response::json(['error' => 'An old or repeated heartbeat.'], 409);
        }
        $beat = self::clean($d);
        $version = (string) ($beat['version'] ?? '');
        $update = ['last_seen' => date('Y-m-d H:i:s'), 'last_ts' => $ts, 'heartbeat' => (string) json_encode($beat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => in_array($beat['status'] ?? '', ['ok', 'warning', 'error'], true) ? $beat['status'] : '', 'silent_reported' => 0, 'version' => $version];
        if (($beat['name'] ?? '') !== '') {
            $update['name'] = $beat['name'];
        }
        if (preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~i', (string) ($beat['url'] ?? '')) === 1) {
            $update['url'] = $beat['url'];
        }
        if ($version !== (string) $site['version']) {
            $update['version_since'] = date('Y-m-d H:i:s');
            if ((string) $site['version'] !== '' && $site['last_seen'] !== null) {
                Events::record($db, 'fleet.site_updated', 'info', t('%s runs version %s.', (string) ($update['url'] ?? $site['url']), $version), ['site' => (int) $site['id'], 'version' => $version]);
            }
        }
        $db->update('fleet_sites', $update, ['id' => (int) $site['id']]);

        // 2.16: the newest shared design kit is only announced here – a site that wants it asks /fleet/kit itself (Fleet\Kit)
        $kit = Kit::announcement($db);

        return self::signed($app->settings(), ['ok' => true] + ($kit !== null ? ['kit' => $kit] : []));
    }

    /** POST /fleet/unpair – the site ended the pairing. */
    public static function unpair(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        $db = $app->db();
        $site = is_array($d) ? $db->byPublicId('fleet_sites', $d['site_id'] ?? null) : null;
        if ($site === null || !Keys::verify($body, $signature, (string) $site['public_key']) || abs(time() - (int) ($d['ts'] ?? 0)) > Link::MAX_SKEW) {
            return Response::json(['error' => 'Not a valid request of a paired site.'], 403);
        }
        self::remove($app, (int) $site['id']);

        return self::signed($app->settings(), ['ok' => true]);
    }

    public static function remove(App $app, int $id): void
    {
        $db = $app->db();
        $url = (string) $db->value('SELECT url FROM {fleet_sites} WHERE id = ?', [$id]);
        if ($db->delete('fleet_sites', ['id' => $id]) > 0) {
            Events::record($db, 'fleet.site_removed', 'info', t('%s is no longer in the console.', $url), ['site' => $id]);
        }
    }

    /**
     * Why a site needs attention, by weight (REASONS). Pure.
     *
     * @param array<string, mixed> $site a row of tl_fleet_sites
     * @return array{score: int, reasons: list<string>}
     */
    public static function attention(array $site, int $now): array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
        $beat = is_array($beat) ? $beat : [];
        $lastSeen = $site['last_seen'] !== null ? (int) strtotime((string) $site['last_seen']) : null;
        $reasons = [];
        if ($site['up'] !== null && (int) $site['up'] === 0) {
            $reasons[] = 'down';
        }
        if ($lastSeen === null) {
            $reasons[] = 'no_heartbeat';
        } elseif ($now - $lastSeen > self::SILENT_AFTER) {
            $reasons[] = 'not_reporting';
        }
        if (($site['status'] ?? '') === 'error') {
            $reasons[] = 'errors';
        }
        if (!empty($beat['jobs_failing'])) {
            $reasons[] = 'jobs_failing';
        }
        if ($beat !== [] && (empty($beat['last_backup']) || $now - (int) $beat['last_backup'] > 8 * 86400)) {
            $reasons[] = 'no_backup';
        }
        if (($site['status'] ?? '') === 'warning') {
            $reasons[] = 'warnings';
        }
        if ($beat !== [] && (empty($beat['cron_last_run']) || $now - (int) $beat['cron_last_run'] > 86400)) {
            $reasons[] = 'no_cron';
        }
        if ((int) ($beat['enquiries_unanswered'] ?? 0) > 0) {
            $reasons[] = 'enquiries';
        }
        if (is_array($beat['audit'] ?? null) && array_sum($beat['audit']) > 0) {
            $reasons[] = 'audit';
        }

        return ['score' => array_sum(array_map(fn (string $r): int => self::REASONS[$r], $reasons)), 'reasons' => $reasons];
    }

    /**
     * All sites, the ones that need attention first.
     *
     * @return list<array<string, mixed>> rows of tl_fleet_sites with attention, reasons and the decoded heartbeat (beat)
     */
    public static function overview(Db $db, int $now): array
    {
        $out = [];
        foreach (self::sites($db) as $site) {
            $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
            $out[] = $site + self::attention($site, $now) + ['beat' => is_array($beat) ? $beat : []];
        }
        usort($out, fn (array $a, array $b): int => [$b['score'], (string) $a['name']] <=> [$a['score'], (string) $b['name']]);

        return $out;
    }

    /**
     * The background job: is each site's home page up (any answer below 500), and has any site stopped reporting?
     */
    public static function checkUptime(App $app, ?int $siteId = null): string
    {
        $db = $app->db();
        $sites = array_values(array_filter(self::sites($db), fn (array $s): bool => $siteId === null || (int) $s['id'] === $siteId));
        $statuses = Http::statuses(array_map(fn (array $s): string => (string) $s['url'] . '/', $sites));
        $down = 0;
        foreach ($sites as $i => $site) {
            $status = $statuses[$i] ?? 0;
            $up = $status > 0 && $status < 500;
            $failures = $up ? 0 : min(255, (int) $site['up_failures'] + 1);
            $update = ['up_status' => $status, 'up_checked' => date('Y-m-d H:i:s'), 'up_failures' => $failures];
            if ($up && $site['up'] !== null && (int) $site['up'] === 0) {
                $update += ['up' => 1, 'up_changed' => date('Y-m-d H:i:s')];
                Events::record($db, 'fleet.site_up', 'info', t('%s answers again.', (string) $site['url']), ['site' => (int) $site['id']]);
            } elseif ($up && $site['up'] === null) {
                $update += ['up' => 1, 'up_changed' => date('Y-m-d H:i:s')];
            } elseif (!$up && $failures >= self::DOWN_AFTER && ($site['up'] === null || (int) $site['up'] === 1)) {
                $update += ['up' => 0, 'up_changed' => date('Y-m-d H:i:s')];
                Events::record($db, 'fleet.site_down', 'error', t('%s does not answer (%s).', (string) $site['url'], $status > 0 ? 'HTTP ' . $status : t('no answer')), ['site' => (int) $site['id'], 'status' => $status]);
            }
            $down += $up ? 0 : 1;
            $lastSeen = $site['last_seen'] !== null ? (int) strtotime((string) $site['last_seen']) : (int) strtotime((string) $site['paired_at']);
            if ((int) $site['silent_reported'] === 0 && time() - $lastSeen > self::SILENT_AFTER) {
                $update['silent_reported'] = 1;
                Events::record($db, 'fleet.site_silent', 'warning', t('%s has not sent its heartbeat for more than a day.', (string) $site['url']), ['site' => (int) $site['id']]);
            }
            $db->update('fleet_sites', $update, ['id' => (int) $site['id']]);
        }

        return 'checked ' . count($sites) . ', down ' . $down;
    }

    /** @return list<array<string, mixed>> */
    public static function sites(Db $db): array
    {
        return $db->all('SELECT * FROM {fleet_sites} ORDER BY name, id');
    }

    /**
     * Only the known keys of a heartbeat, with sane types and lengths.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    public static function clean(array $d): array
    {
        $out = [];
        foreach (self::HEARTBEAT_KEYS as $key) {
            $value = $d[$key] ?? null;
            $out[$key] = match (true) {
                is_string($value) => mb_substr($value, 0, 255),
                is_int($value), is_bool($value), $value === null => $value,
                is_float($value) => (int) $value,
                is_array($value) => self::cleanList($value),
                default => null,
            };
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function cleanList(array $value, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($value, 0, 40, true) as $k => $v) {
            $k = is_int($k) ? $k : mb_substr((string) $k, 0, 60);
            $out[$k] = match (true) {
                is_string($v) => mb_substr($v, 0, 300),
                is_int($v), is_bool($v), $v === null => $v,
                is_array($v) && $depth < 2 => self::cleanList($v, $depth + 1),
                default => null,
            };
        }

        return $out;
    }

    /** A JSON answer signed by the console's key (the heartbeat reply, the kit). @param array<string, mixed> $data */
    public static function signed(Settings $s, array $data, int $status = 200): Response
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new Response($body, $status, ['Content-Type' => 'application/json; charset=utf-8', Http::HEADER => Keys::sign($s, $body), 'Cache-Control' => 'no-store']);
    }
}
