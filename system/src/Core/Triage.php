<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Enquiry triage (2.12): every enquiry gets a kind (built in: sales, support, a job application, a supplier's offer, spam,
 * other; the administrator renames, describes, orders and adds kinds in Settings → Enquiries), a priority and a drafted
 * reply, so the morning starts with what matters.
 *
 *  - A rule sorts what is certain: an application sent from a job opening (Core\Jobs) is a job, normal priority.
 *  - Claude sorts the rest over MCP (triage_enquiries lists the unsorted ones, update_enquiry saves the result).
 *  - The site's own AI assistant can sort new enquiries in the background – only when the administrator switches it on
 *    (Settings → Enquiries), because the text of the enquiry goes to the AI provider.
 *  - It can also check each new enquiry for spam right when it arrives (spam()), so spam notifies nobody.
 *
 * A drafted reply is never sent by itself; the admin copies it or opens it in their e-mail. Who sorted an enquiry is kept,
 * and a person's own sorting is never overwritten by Claude or the assistant.
 */
final class Triage
{
    /** The built-in kinds: key => [name, how to recognise it]. A site starts with them and changes them in Settings → Enquiries. */
    public const array CATEGORIES = [
        'sales' => ['Sales', 'Wants to buy, order or get a quote.'],
        'support' => ['Support', 'An existing customer with a problem or question.'],
        'job' => ['Job application', 'Applies for a job.'],
        'supplier' => ['Supplier or offer', 'Offers their own products or services, or a cooperation.'],
        'spam' => ['Spam', 'Advertising, SEO offers, nonsense, scams.'],
        'other' => ['Other', 'Anything else.'],
    ];

    /** Kinds Talea relies on: a rule sorts job applications, spam stays out of the inbox. They can be described, not renamed or removed. */
    public const array FIXED = ['job', 'spam'];

    /** At most this many kinds, and this long a name and a description. */
    public const int MAX_KINDS = 20;
    private const int MAX_NAME = 60;
    private const int MAX_DESCRIPTION = 300;

    public const array PRIORITIES = [1 => 'low', 2 => 'normal', 3 => 'high'];

    /** Sorted by a machine – a person may sort it again; anything else is a person's name and stays. */
    public const array MACHINES = ['', 'claude', 'assistant', 'rule'];

    /** At most this many enquiries per run of the background job (each is one request to the AI provider). */
    private const int PER_RUN = 10;

    /**
     * The site's kinds in their order (setting enquiry_kinds; empty = the built-in ones): key => name as shown, the
     * description the administrator wrote ('' = none), the built-in description (English, for the assistant and as a hint)
     * and whether it is fixed. A built-in kind without its own name shows its translated name; the fixed kinds are always there.
     *
     * @return array<string, array{name: string, description: string, default: string, fixed: bool}>
     */
    public static function kinds(Settings $settings): array
    {
        return self::kindsFrom($settings->get('enquiry_kinds'));
    }

    /**
     * The kinds read from the value of the setting enquiry_kinds (see kinds()).
     *
     * @return array<string, array{name: string, description: string, default: string, fixed: bool}>
     */
    public static function kindsFrom(string $value): array
    {
        $stored = json_decode($value, true);
        $rows = is_array($stored) && $stored !== [] ? $stored : array_map(fn (string $key): array => ['key' => $key], array_keys(self::CATEGORIES));
        $kinds = [];
        foreach ($rows as $row) {
            $key = is_array($row) ? (string) ($row['key'] ?? '') : '';
            $name = is_array($row) ? trim((string) ($row['name'] ?? '')) : '';
            if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $key) || strlen($key) > 40 || isset($kinds[$key]) || ($name === '' && !isset(self::CATEGORIES[$key]))) {
                continue;
            }
            $kinds[$key] = ['name' => $name === '' || in_array($key, self::FIXED, true) ? t(self::CATEGORIES[$key][0]) : $name,
                'description' => trim((string) ($row['description'] ?? '')), 'default' => self::CATEGORIES[$key][1] ?? '', 'fixed' => in_array($key, self::FIXED, true)];
        }
        foreach (self::FIXED as $key) {
            $kinds[$key] ??= ['name' => t(self::CATEGORIES[$key][0]), 'description' => '', 'default' => self::CATEGORIES[$key][1], 'fixed' => true];
        }

        return array_slice($kinds, 0, self::MAX_KINDS + count(self::FIXED), true);
    }

    /** @return array<string, string> key => the name of each of the site's kinds */
    public static function names(Settings $settings): array
    {
        return array_map(fn (array $kind): string => $kind['name'], self::kinds($settings));
    }

    /** @return array<string, string> key => how to recognise each kind, for the assistant and Claude (the built-in text when none was written) */
    public static function descriptions(Settings $settings): array
    {
        return array_map(fn (array $kind): string => $kind['description'] !== '' ? $kind['description'] : $kind['default'], self::kinds($settings));
    }

    /**
     * The kinds as Settings → Enquiries sends them (kind_key[], kind_name[], kind_description[] in their order), cleaned
     * into the value of the setting: a row without a name is left out unless it is a built-in kind, a new kind gets a key from
     * its name, a built-in name that is not changed is not stored (so it stays translated). $move = "index:-1|1", $delete = index.
     *
     * @param list<string> $keys @param list<string> $names @param list<string> $descriptions
     */
    public static function storeKinds(array $keys, array $names, array $descriptions, string $move = '', ?int $delete = null): string
    {
        $rows = [];
        foreach (array_values($names) as $i => $name) {
            $key = (string) ($keys[$i] ?? '');
            $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $name) ?? ''), 0, self::MAX_NAME);
            $description = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($descriptions[$i] ?? '')) ?? ''), 0, self::MAX_DESCRIPTION);
            if ($delete === $i && !in_array($key, self::FIXED, true)) {
                continue;
            }
            if (!isset(self::CATEGORIES[$key]) && $name === '') {
                continue; // the empty row for a new kind, or a custom kind whose name was cleared
            }
            if (isset(self::CATEGORIES[$key]) && ($name === t(self::CATEGORIES[$key][0]) || in_array($key, self::FIXED, true))) {
                $name = '';
            }
            $rows[] = ['key' => $key, 'name' => $name, 'description' => $description];
        }
        $taken = array_column($rows, 'key');
        foreach ($rows as &$row) {
            if (!isset(self::CATEGORIES[$row['key']]) && !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $row['key'])) {
                $base = slugify($row['name'], 30) ?: 'kind';
                for ($candidate = $base, $n = 2; in_array($candidate, $taken, true) || isset(self::CATEGORIES[$candidate]); $n++) {
                    $candidate = $base . '-' . $n;
                }
                $row['key'] = $taken[] = $candidate;
            }
        }
        unset($row);
        if (preg_match('/^(\d+):(-1|1)$/', $move, $m) === 1 && isset($rows[(int) $m[1]], $rows[(int) $m[1] + (int) $m[2]])) {
            [$rows[(int) $m[1]], $rows[(int) $m[1] + (int) $m[2]]] = [$rows[(int) $m[1] + (int) $m[2]], $rows[(int) $m[1]]];
        }

        return (string) json_encode(array_slice($rows, 0, self::MAX_KINDS + count(self::FIXED)), JSON_UNESCAPED_UNICODE);
    }

    /**
     * A triage result from Claude, the assistant or a form, cleaned: an unknown kind or priority is null (= unchanged), the
     * reply plain text up to 5 000 characters. $kinds are the keys of the site's kinds (Triage::kinds); without them the built-in ones.
     *
     * @param list<string>|null $kinds
     * @return array{category: ?string, priority: ?int, suggested_reply: ?string}
     */
    public static function clean(mixed $category, mixed $priority, mixed $reply, ?array $kinds = null): array
    {
        $priorities = array_flip(self::PRIORITIES);

        return [
            'category' => is_string($category) && in_array($category, $kinds ?? array_keys(self::CATEGORIES), true) ? $category : null,
            'priority' => is_int($priority) && isset(self::PRIORITIES[$priority]) ? $priority : (is_string($priority) && isset($priorities[$priority]) ? $priorities[$priority] : null),
            'suggested_reply' => is_string($reply) ? mb_substr(trim(strip_tags(str_replace("\r\n", "\n", $reply))), 0, 5000) : null,
        ];
    }

    /**
     * Saves a triage. $by is claude | assistant | rule or a person's name; a machine never overwrites a person's sorting.
     * Returns whether anything was saved.
     */
    public static function save(Db $db, int $idp, array $clean, string $by): bool
    {
        $current = (string) ($db->value('SELECT triaged_by FROM {enquiries} WHERE enquiry_id = ?', [$idp]) ?? '');
        if (in_array($by, self::MACHINES, true) && !in_array($current, self::MACHINES, true)) {
            return false;
        }
        $changes = array_filter($clean, fn (mixed $v): bool => $v !== null);
        if ($changes === []) {
            return false;
        }
        $db->update('enquiries', $changes + ['triaged_by' => mb_substr($by, 0, 40), 'triaged_at' => date('Y-m-d H:i:s')], ['enquiry_id' => $idp]);

        return true;
    }

    /** The rule for what is certain: an application sent from a job opening is a job application. @param list<string> $jobSources */
    public static function rule(array $enquiry, array $jobSources): ?array
    {
        return in_array((string) ($enquiry['source'] ?? ''), $jobSources, true) ? ['category' => 'job', 'priority' => 2, 'suggested_reply' => null] : null;
    }

    /** Applies the rule to a new enquiry right after it is saved (Front\Forms). */
    public static function afterSubmit(App $app, int $idp): void
    {
        $enquiry = $app->db()->one('SELECT enquiry_id, source FROM {enquiries} WHERE enquiry_id = ?', [$idp]);
        if ($enquiry !== null && ($result = self::rule($enquiry, Jobs::sources($app->db()))) !== null) {
            self::save($app->db(), $idp, $result, 'rule');
        }
    }

    /**
     * The spam check of a new enquiry (Front\Forms), when the administrator switched it on: the assistant reads it before anyone
     * is notified. Spam is saved as spam (the admin finds it under the Spam filter) and the form then sends nothing – no
     * notification, webhook, delivery or confirmation; the visitor sees the usual thank-you. An unreachable assistant lets the
     * enquiry through: a lost real enquiry costs more than one spam mail.
     */
    public static function spam(App $app, int $idp): bool
    {
        $settings = $app->settings();
        $assistant = new Assistant($settings);
        if (!$settings->bool('spam_assistant') || !$assistant->isReady()) {
            return false;
        }
        $enquiry = $app->db()->one('SELECT * FROM {enquiries} WHERE enquiry_id = ?', [$idp]);
        try {
            if ($enquiry === null || !$assistant->spam(self::text($enquiry), (string) $settings->get('site_name'))) {
                return false;
            }
        } catch (\RuntimeException) {
            return false;
        }

        return self::save($app->db(), $idp, ['category' => 'spam', 'priority' => 1, 'suggested_reply' => null], 'assistant');
    }

    /**
     * The enquiry as plain text for Claude or the assistant: the form, what it was about, the page and every field (an
     * attachment by its name only). The visitor wrote it – whoever reads it must treat it as data, not as instructions.
     */
    public static function text(array $enquiry): string
    {
        $lines = ['Form: ' . $enquiry['form'], 'Page: ' . $enquiry['page']];
        if (($enquiry['topic'] ?? '') !== '') {
            $lines[] = 'About: ' . $enquiry['topic'];
        }
        foreach (json_decode((string) $enquiry['data'], true) ?: [] as $field) {
            if (is_array($field) && isset($field[0], $field[1])) {
                $lines[] = $field[0] . ': ' . mb_substr((string) $field[1], 0, 3000);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The background job: new enquiries the rule did not sort go to the AI assistant, when the administrator switched that
     * on and the assistant is set up. Spam-looking ones are only marked; nothing is deleted or sent.
     */
    public static function run(App $app): string
    {
        $settings = $app->settings();
        $assistant = new Assistant($settings);
        if (!$settings->bool('triage_assistant') || !Extensions::isEnabled($settings, 'assistant') || !$assistant->isReady()) {
            return 'off';
        }
        $db = $app->db();
        $done = 0;
        $failed = '';
        foreach ($db->all("SELECT * FROM {enquiries} WHERE triaged_by = '' AND deleted_at IS NULL AND created_at > NOW() - INTERVAL 7 DAY ORDER BY enquiry_id LIMIT " . self::PER_RUN) as $enquiry) {
            try {
                $result = $assistant->triage(self::text($enquiry), (string) $settings->get('site_name'), self::descriptions($settings));
            } catch (\RuntimeException $e) {
                $db->update('enquiries', ['triaged_by' => 'assistant', 'triaged_at' => date('Y-m-d H:i:s')], ['enquiry_id' => (int) $enquiry['enquiry_id']]); // not asked again
                $failed = $e->getMessage();

                continue;
            }
            if (self::save($db, (int) $enquiry['enquiry_id'], self::clean($result['category'] ?? null, $result['priority'] ?? null, $result['reply'] ?? null, array_keys(self::kinds($settings))), 'assistant')) {
                $done++;
            }
        }

        return 'sorted ' . $done . ($failed !== '' ? ', the assistant failed: ' . mb_substr($failed, 0, 200) : '');
    }
}
