<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\ImageDownloader;
use Kaleta\Core\MigrationReport;

/**
 * MCP tools for moving a site to Kaleta (2.7): the parity report and the import of old form entries. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait MigrationTools
{
    /** migration_report */
    private function toolMigrationReport(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The migration report is for administrators.');
        }
        $id = trim((string) ($a['report_id'] ?? ''));
        if ($id === '') {
            $url = trim((string) ($a['url'] ?? ''));
            $url = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
            if (!\Kaleta\Core\WebImport::validUrl($url) || !ImageDownloader::isAvailable()) {
                throw new \InvalidArgumentException('Give the address of the old site, e.g. https://www.example.com.');
            }
            $state = MigrationReport::newState($url);
        } else {
            $state = MigrationReport::load($id) ?? throw new \InvalidArgumentException('The report does not exist; start a new one with the url.');
        }
        $report = new MigrationReport($this->app, new ImageDownloader($state['web'], true));
        if ($state['phase'] !== 'done') {
            $report->step($state);
            MigrationReport::save($state);
        }
        $r = $report->result($state);
        $s = $r['summary'];
        $rows = array_values(array_filter($r['rows'], fn (array $row): bool => $row['problems'] !== []));

        return ['report_id' => $state['id'], 'old_site' => $state['web'],
            'phase' => ['finding' => 'finding', 'check' => 'checking', 'done' => 'done'][$state['phase']] ?? $state['phase'],
            'summary' => ['addresses' => $s['urls'], 'checked' => $s['checked'], 'ok' => $s['ok'], 'redirected' => $s['redirected'],
                'not_published' => $s['hidden'], 'missing' => $s['missing'], 'errors' => $s['failed'], 'warnings' => $s['warnings']],
            'problems' => array_map(fn (array $row): array => ['old' => $row['old'], 'new' => $row['new'] ?: null, 'status' => $row['status'],
                'problems' => array_map(fn (string $p): array => ['code' => $p, 'severity' => MigrationReport::PROBLEMS[$p] ?? 'info', 'message' => MigrationReport::describe($p)], $row['problems']),
                'old_title' => $row['old_title'], 'new_title' => $row['new_title']], array_slice($rows, 0, 100)),
            'more_problems' => max(0, count($rows) - 100),
            'site_checks' => array_map(fn (array $c): array => ['message' => $c['message'], 'fix_in' => $this->app->request->origin() . $this->app->url($c['fix'])], $r['web']),
            'next' => $state['phase'] !== 'done' ? 'Call again with the same report_id until the phase is done.'
                : 'Fix what you can as drafts (save_redirect for missing addresses, descriptions, forms), list the rest for the user, and run a new report before the domain is switched.'];
    }

    /** import_enquiries */
    private function toolImportEnquiries(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() || !$auth->hasModule('enquiries')) {
            throw new \DomainException('Old enquiries are imported by an administrator with the Enquiries section.');
        }
        $source = strtolower(trim((string) ($a['source'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9.\-]{1,30}$/', $source)) {
            throw new \InvalidArgumentException('source: a short name of where the entries come from, e.g. breakdance or old-site.cz.');
        }
        $entries = $a['entries'] ?? null;
        if (!is_array($entries) || $entries === [] || count($entries) > 200) {
            throw new \InvalidArgumentException('entries: a list of 1–200 entries; send more in further calls.');
        }
        $status = ['new' => 0, 'read' => 1, 'resolved' => 2][(string) ($a['status'] ?? 'read')] ?? throw new \InvalidArgumentException('status must be new, read or resolved.');
        $db = $this->app->db();
        $months = $this->app->settings()->int('enquiries_months');
        $limit = $months > 0 ? date('Y-m-d H:i:s', strtotime('-' . $months . ' months')) : null;
        $imported = $skipped = $old = 0;
        $errors = [];
        foreach (array_values($entries) as $i => $e) {
            $entry = self::enquiryEntry($e);
            if (is_string($entry)) {
                $errors[] = 'entries[' . $i . ']: ' . $entry;
                continue;
            }
            $key = sha1($entry['date'] . '|' . $entry['form'] . '|' . json_encode($entry['data'], JSON_UNESCAPED_UNICODE));
            if ($db->value('SELECT 1 FROM {import_map} WHERE source = ? AND type = ? AND source_id = ?', ['form:' . $source, 'enquiry', $key]) !== null) {
                $skipped++;
                continue;
            }
            $id = $db->insert('enquiries', ['created_at' => $entry['date'], 'form' => $entry['form'], 'source' => mb_substr('import:' . $source, 0, 40),
                'page' => $entry['page'], 'email' => $entry['email'], 'data' => (string) json_encode($entry['data'], JSON_UNESCAPED_UNICODE), 'status' => $status]);
            $db->run('INSERT INTO {import_map} (source, type, source_id, local_id) VALUES (?, ?, ?, ?)', ['form:' . $source, 'enquiry', $key, $id]);
            $imported++;
            if ($limit !== null && $entry['date'] < $limit) {
                $old++;
            }
        }

        return ['imported' => $imported, 'already_imported' => $skipped, 'errors' => $errors,
            'older_than_retention' => $old,
            'note' => $old > 0 ? sprintf('%d entries are older than the %d months enquiries are kept (Settings → Privacy and cookies); the site deletes them with the next clean-up. Tell the user.', $old, $months) : null];
    }

    /**
     * One entry of import_enquiries, checked: {date, form, page, email, fields: [{label, value}] or {label: value}}.
     *
     * @return array{date: string, form: string, page: string, email: string, data: list<array{0: string, 1: string}>}|string the entry, or what is wrong
     */
    public static function enquiryEntry(mixed $e): array|string
    {
        if (!is_array($e)) {
            return 'an entry is an object';
        }
        $time = strtotime((string) ($e['date'] ?? ''));
        if ($time === false || $time > time() + 86400) {
            return 'date must be a date and time, e.g. 2025-03-14 09:30';
        }
        $fields = [];
        $raw = $e['fields'] ?? [];
        if (is_array($raw) && !array_is_list($raw)) {
            $raw = array_map(fn (string|int $k, mixed $v): array => ['label' => (string) $k, 'value' => $v], array_keys($raw), $raw);
        }
        foreach (is_array($raw) ? $raw : [] as $f) {
            if (!is_array($f) || !is_scalar($f['value'] ?? null)) {
                continue;
            }
            $label = mb_substr(trim((string) ($f['label'] ?? '')), 0, 200);
            $value = mb_substr(trim(strip_tags((string) $f['value'])), 0, 5000);
            if ($label !== '' && $value !== '') {
                $fields[] = [$label, $value];
            }
            if (count($fields) >= 60) {
                break;
            }
        }
        if ($fields === []) {
            return 'fields are empty';
        }
        $email = trim((string) ($e['email'] ?? ''));
        if ($email === '') {
            foreach ($fields as [, $value]) {
                if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $email = $value;
                    break;
                }
            }
        }

        return ['date' => date('Y-m-d H:i:s', $time), 'form' => mb_substr(trim((string) ($e['form'] ?? '')), 0, 120),
            'page' => mb_substr(trim((string) ($e['page'] ?? '')), 0, 255),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_substr($email, 0, 190) : '', 'data' => $fields];
    }
}
