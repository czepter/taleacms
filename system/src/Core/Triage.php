<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Enquiry triage (2.12): every enquiry gets a kind (sales, support, a job application, a supplier's offer, spam, other), a
 * priority and a drafted reply, so the morning starts with what matters.
 *
 *  - A rule sorts what is certain: an application sent from a job opening (Core\Jobs) is a job, normal priority.
 *  - Claude sorts the rest over MCP (triage_enquiries lists the unsorted ones, update_enquiry saves the result).
 *  - The site's own AI assistant can sort new enquiries in the background – only when the administrator switches it on
 *    (Enquiries → settings), because the text of the enquiry goes to the AI provider.
 *
 * A drafted reply is never sent by itself; the admin copies it or opens it in their e-mail. Who sorted an enquiry is kept,
 * and a person's own sorting is never overwritten by Claude or the assistant.
 */
final class Triage
{
    public const array CATEGORIES = ['sales' => 'Sales', 'support' => 'Support', 'job' => 'Job application', 'supplier' => 'Supplier or offer', 'spam' => 'Spam', 'other' => 'Other'];

    public const array PRIORITIES = [1 => 'low', 2 => 'normal', 3 => 'high'];

    /** Sorted by a machine – a person may sort it again; anything else is a person's name and stays. */
    public const array MACHINES = ['', 'claude', 'assistant', 'rule'];

    /** At most this many enquiries per run of the background job (each is one request to the AI provider). */
    private const int PER_RUN = 10;

    /**
     * A triage result from Claude, the assistant or a form, cleaned: an unknown kind or priority is null (= unchanged), the
     * reply plain text up to 5 000 characters.
     *
     * @return array{category: ?string, priority: ?int, suggested_reply: ?string}
     */
    public static function clean(mixed $category, mixed $priority, mixed $reply): array
    {
        $priorities = array_flip(self::PRIORITIES);

        return [
            'category' => is_string($category) && isset(self::CATEGORIES[$category]) ? $category : null,
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
        foreach ($db->all("SELECT * FROM {enquiries} WHERE triaged_by = '' AND created_at > NOW() - INTERVAL 7 DAY ORDER BY enquiry_id LIMIT " . self::PER_RUN) as $enquiry) {
            try {
                $result = $assistant->triage(self::text($enquiry), (string) $settings->get('site_name'));
            } catch (\RuntimeException $e) {
                $db->update('enquiries', ['triaged_by' => 'assistant', 'triaged_at' => date('Y-m-d H:i:s')], ['enquiry_id' => (int) $enquiry['enquiry_id']]); // not asked again
                $failed = $e->getMessage();

                continue;
            }
            if (self::save($db, (int) $enquiry['enquiry_id'], self::clean($result['category'] ?? null, $result['priority'] ?? null, $result['reply'] ?? null), 'assistant')) {
                $done++;
            }
        }

        return 'sorted ' . $done . ($failed !== '' ? ', the assistant failed: ' . mb_substr($failed, 0, 200) : '');
    }
}
