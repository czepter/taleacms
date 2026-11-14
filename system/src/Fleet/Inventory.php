<?php

declare(strict_types=1);

namespace Talea\Fleet;

/**
 * The console's inventory (#341): every site with what it runs – Talea version, PHP, database, web server family, the features
 * switched on and the languages (from the heartbeat, Fleet\Heartbeat) – filterable by each column and as a CSV file. The admin screen
 * and the MCP tool fleet_inventory use the same functions. Pure.
 */
final class Inventory
{
    /** filter key => column label; the keys are the query parameters */
    public const array COLUMNS = ['version' => 'Version', 'php' => 'PHP', 'database' => 'Database', 'web_server' => 'Web server', 'feature' => 'Features', 'language' => 'Languages'];

    /**
     * One site as a row of the inventory; a heartbeat from an older site gives empty strings and lists.
     *
     * @param array<string, mixed> $site a row of Console::overview() (with beat, reasons and accepted)
     * @return array{id: int, public_id: string, name: string, url: string, version: string, php: string, database: string, web_server: string, features: list<string>, languages: list<string>,
     *               last_report: ?string, certificate_expires: ?string, domain_expires: ?string, attention: list<string>, accepted: list<string>, enquiries_waiting: ?int}
     */
    public static function row(array $site): array
    {
        $beat = (array) ($site['beat'] ?? []);
        $list = fn (mixed $v): array => array_values(array_filter(is_array($v) ? $v : [], 'is_string'));

        return ['id' => (int) $site['id'], 'public_id' => (string) ($site['public_id'] ?? ''), 'name' => (string) $site['name'], 'url' => (string) $site['url'], 'version' => (string) $site['version'],
            'php' => (string) ($beat['php'] ?? ''), 'database' => (string) ($beat['database'] ?? ''), 'web_server' => (string) ($beat['web_server'] ?? ''),
            'features' => $list($beat['extensions'] ?? []), 'languages' => $list($beat['languages'] ?? []), 'last_report' => $site['last_seen'] ?? null,
            'certificate_expires' => $site['cert_expires'] ?? null, 'domain_expires' => $site['domain_expires'] ?? null,
            'attention' => array_values($site['reasons'] ?? []), 'accepted' => array_values($site['accepted_reasons'] ?? []), 'enquiries_waiting' => $beat['enquiries_unanswered'] ?? null];
    }

    /**
     * What each filter column holds across the fleet, sorted (versions naturally).
     *
     * @param list<array<string, mixed>> $rows Inventory::row() results
     * @return array<string, list<string>>
     */
    public static function values(array $rows): array
    {
        $out = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $found = [];
            foreach ($rows as $row) {
                foreach ($key === 'feature' ? $row['features'] : ($key === 'language' ? $row['languages'] : [$row[$key]]) as $value) {
                    if ((string) $value !== '') {
                        $found[(string) $value] = (string) $value;
                    }
                }
            }
            natsort($found);
            $out[$key] = array_values($found);
        }

        return $out;
    }

    /**
     * Only the rows that match every filter that is set (an empty one is ignored) and the text (a word of the name or the address).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $filters filter key => the value wanted
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, array $filters, string $query): array
    {
        $query = mb_strtolower(trim($query));

        return array_values(array_filter($rows, function (array $row) use ($filters, $query): bool {
            foreach (self::COLUMNS as $key => $_) {
                $wanted = (string) ($filters[$key] ?? '');
                if ($wanted === '') {
                    continue;
                }
                $has = match ($key) {
                    'feature' => in_array($wanted, $row['features'], true),
                    'language' => in_array($wanted, $row['languages'], true),
                    default => (string) $row[$key] === $wanted,
                };
                if (!$has) {
                    return false;
                }
            }

            return $query === '' || str_contains(mb_strtolower($row['name'] . ' ' . $row['url']), $query);
        }));
    }

    /**
     * The filters from a getter (the request's, or the arguments of an MCP call): the six keys, trimmed.
     *
     * @param callable(string): string $get
     * @return array<string, string>
     */
    public static function filtersFrom(callable $get): array
    {
        $out = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $out[$key] = trim($get($key));
        }

        return $out;
    }

    /**
     * The rows as a CSV file for a spreadsheet: UTF-8 with a byte-order mark, semicolons, a cell that would run as a formula is made plain text.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function csv(array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Site'), t('Address'), t('Version'), t('PHP'), t('Database'), t('Web server'), t('Features'), t('Languages'), t('Last report'), t('Certificate ends'), t('Domain registration ends'), t('What needs attention')], ';', '"', '');
        foreach ($rows as $row) {
            $cells = [$row['name'], $row['url'], $row['version'], $row['php'], $row['database'], $row['web_server'], implode(', ', $row['features']), implode(', ', $row['languages']),
                (string) $row['last_report'], substr((string) $row['certificate_expires'], 0, 10), (string) $row['domain_expires'], implode(', ', $row['attention'])];
            fputcsv($f, array_map(fn (mixed $v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) === 1 ? "'" . $v : (string) $v, $cells), ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);

        return $csv;
    }
}
