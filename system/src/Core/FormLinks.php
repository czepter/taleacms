<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Build;
use Talea\Builder\Collections;
use Talea\Builder\FormRecords;

/**
 * Personal links to a form (the form's setting access = link): /_f/<token>, 32 random hex characters, valid for the days the form
 * says and for one submission; only a hash is stored. A link is made for an enquiry (its address is the person's own, so the
 * link can be mailed) or for a name only (it is returned to pass on, or mailed from the admin to an address a person typed).
 * What the person sent arrives wherever the form sends answers; the link keeps the consent they ticked and when.
 *
 * A link carries drafts: a history of answers (form field key => answer) the form shows filled in, the latest one by default. The
 * first is usually ours (prefill, by "site"), the person adds their own with "Save draft" (by "person", the form option link_save),
 * and the admin may add another suggestion while nobody sent the form. Nothing in a draft reaches the site until the person sends it.
 * A link may also carry values the
 * item it makes gets without being shown (fixed: collection field key => value), and an existing collection item the person
 * reviews (target_item_id): their answer then becomes a hidden draft next to it, and a person applies it to the item or discards
 * it (apply(), discard()). Values belong to the link, never to the form, so one form serves many people.
 */
final class FormLinks
{
    public const int DAYS = 30;

    /**
     * Form field types a link can fill in and a saved draft keeps. Never the consent (checkbox): the person gives it themselves, with
     * the final send – a ticked box we prepared or one a draft remembered is no consent.
     */
    public const array PREFILLABLE = ['text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkboxes', 'date', 'timerange'];

    /** What a link's status means in lists. */
    public const array STATUSES = ['open' => 'Not used yet', 'used' => 'Answered', 'expired' => 'Expired', 'revoked' => 'Revoked'];

    /**
     * Creates a link to a form; sends the e-mail when asked. Returns the link (absolute), its public id and whether the e-mail went out.
     *
     * @param array<string, mixed> $form a row of forms
     * @param array<string, mixed> $prefill form field key or label => suggested answer
     * @param array<string, mixed> $fixed collection field key => value of the item the answer makes
     * @param bool $typedAddress a person in the admin typed the address and may send to it
     * @return array{link: string, sent: bool, id: string, prefill: array<string, string|list<string>>, not_prefilled?: list<string>} not_prefilled: labels
     *         of form fields the reviewed item has a value for that the form cannot show (the person sees them empty; applying keeps the item's value)
     * @throws \DomainException when there is nobody to name the link after, no e-mail address to send it to, or a value does not fit
     */
    public static function create(App $app, array $form, int $enquiry, bool $send, string $label = '', string $email = '', array $prefill = [], array $fixed = [], int $item = 0, bool $typedAddress = false): array
    {
        $db = $app->db();
        $content = self::content($form);
        $row = $enquiry > 0 ? $db->one('SELECT enquiry_id, form, email FROM {enquiries} WHERE enquiry_id = ?', [$enquiry]) : null;
        if ($enquiry > 0 && $row === null) {
            throw new \DomainException('The enquiry does not exist.');
        }
        $email = trim($row !== null ? (string) $row['email'] : $email);
        $label = mb_substr(trim(strip_tags($label)), 0, 190);
        $target = $item > 0 ? self::targetItem($db, $content, $item) : null;
        if ($label === '' && $target !== null) {
            $label = mb_substr((string) $target['name'], 0, 190);
        }
        if ($row === null && $label === '') {
            throw new \DomainException('Name the person the link is for (label), or pass an enquiry.');
        }
        if ($send && $row === null && !$typedAddress) {
            throw new \DomainException('Only a link for an enquiry can be e-mailed – the address must come from the person themself. Return the link and pass it on.');
        }
        if (($send || $email !== '') && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException($send ? 'There is no valid e-mail address to send the link to.' : 'The e-mail address is not valid.');
        }
        $fields = (array) ($content['fields'] ?? []);
        $values = self::cleanPrefill($fields, $prefill);
        $missed = [];
        if ($target !== null) {
            $fromItem = self::fromItem($content, $target, (array) (CollectionSubmission::collection($db, $content)['fields'] ?? []));
            $values += $fromItem['values']; // what the person typed wins over the item's own values
            $missed = array_values(array_filter($fromItem['missed'], static fn (string $l): bool => !isset($values[(string) (self::field($fields, $l)['key'] ?? '')])));
        }
        $fixedValues = self::cleanFixed($db, $content, $fixed);
        $days = self::days($content);
        $token = bin2hex(random_bytes(16));
        $id = $db->insert('form_links', ['enquiry_id' => $enquiry > 0 ? $enquiry : null, 'form_id' => (int) $form['form_id'], 'token_hash' => hash('sha256', $token), 'email' => $email, 'label' => $label,
            'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + $days * 86400),
            'drafts' => $values !== [] ? self::json([self::entry($values, 'site')]) : null, 'fixed' => $fixedValues !== [] ? self::json($fixedValues) : null, 'target_item_id' => $target !== null ? (int) $target['item_id'] : null]);
        $link = Mailing::absolute($app, '_f/' . $token);
        $sent = $send && self::mail($app, $form, $content, $email, $link, $days, $label, $values);
        \Talea\Admin\ChangeLog::write($app, 'enquiries', 'form_link', mb_substr($row !== null ? (string) $row['form'] : $label, 0, 80) . ($sent ? ' (e-mail)' : ''));

        return ['link' => $link, 'sent' => $sent, 'id' => $db->publicId('form_links', $id), 'prefill' => $values] + ($missed !== [] ? ['not_prefilled' => $missed] : []);
    }

    /**
     * Changes a link nobody used yet: a new suggestion (one more draft), more days, the name. Null leaves a part as it is.
     *
     * @param array<string, mixed> $link a row of form_links
     * @param array<string, mixed>|null $prefill form field key or label => suggested answer (a new draft by the site)
     * @throws \DomainException when the link was used or revoked, or a value does not fit
     */
    public static function update(App $app, array $link, ?array $prefill, int $extendDays = 0, ?string $label = null, ?array $fixed = null): void
    {
        if (self::status($link) === 'used' || self::status($link) === 'revoked') {
            throw new \DomainException('The link was used or revoked – it cannot be changed. Make a new one.');
        }
        $form = FormRecords::byId($app->db(), (int) $link['form_id']) ?? throw new \DomainException('The form of this link is gone.');
        $content = self::content($form);
        $changes = [];
        if ($prefill !== null) {
            // our new suggestion is one more draft: the person sees it, their earlier drafts stay in the history
            $values = self::cleanPrefill((array) ($content['fields'] ?? []), $prefill);
            if ($values !== self::draft($link)) {
                $changes['drafts'] = self::json(array_slice([...self::drafts($link), self::entry($values, 'site')], -self::DRAFTS));
            }
        }
        if ($fixed !== null) {
            $values = self::cleanFixed($app->db(), $content, $fixed);
            $changes['fixed'] = $values !== [] ? self::json($values) : null;
        }
        if ($extendDays > 0) {
            // from now, when it ran out already; from its end otherwise
            $from = max(time(), (int) strtotime((string) $link['expires_at']));
            $changes['expires_at'] = date('Y-m-d H:i:s', $from + min(365, $extendDays) * 86400);
        }
        if ($label !== null && ($label = mb_substr(trim(strip_tags($label)), 0, 190)) !== '') {
            $changes['label'] = $label;
        }
        if ($changes !== []) {
            $app->db()->update('form_links', $changes, ['id' => (int) $link['id']]);
            \Talea\Admin\ChangeLog::write($app, 'enquiries', 'form_link_edit', mb_substr((string) $link['label'], 0, 80));
        }
    }

    /** The link stops working at once (a used one stays as it is: it records the answer and the consent). */
    public static function revoke(App $app, array $link): bool
    {
        if (self::status($link) !== 'open' && self::status($link) !== 'expired') {
            return false;
        }
        $app->db()->update('form_links', ['state' => 'revoked'], ['id' => (int) $link['id']]);
        \Talea\Admin\ChangeLog::write($app, 'enquiries', 'form_link_revoke', mb_substr((string) $link['label'], 0, 80));

        return true;
    }

    /** open | used | expired | revoked @param array<string, mixed> $link */
    public static function status(array $link): string
    {
        return match (true) {
            ($link['used_at'] ?? null) !== null => 'used',
            ($link['state'] ?? '') === 'revoked' => 'revoked',
            strtotime((string) $link['expires_at']) <= time() => 'expired',
            default => 'open',
        };
    }

    /** The open link of a token: not used, not expired, not revoked (and made for this form, when given); null otherwise. With $justUsed: a link used within the last hour. @return array<string, mixed>|null */
    public static function find(Db $db, string $token, int $formId = 0, bool $justUsed = false): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return null;
        }
        // $justUsed: the person was just sent back to the page of their link – it shows the thank-you for an hour after the submission
        $link = $justUsed ? $db->one('SELECT * FROM {form_links} WHERE token_hash = ? AND used_at > ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() - 3600)])
            : $db->one("SELECT * FROM {form_links} WHERE token_hash = ? AND used_at IS NULL AND state <> 'revoked' AND expires_at > ?", [hash('sha256', $token), date('Y-m-d H:i:s')]);

        return $link !== null && ($formId === 0 || (int) $link['form_id'] === $formId) ? $link : null; // $formId 0: the page of a link asks for the link's own form
    }

    /** The link of a token whatever its state (to tell the person it was used or ran out); null for a token that never was. @return array<string, mixed>|null */
    public static function lookup(Db $db, string $token): ?array
    {
        return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $db->one('SELECT * FROM {form_links} WHERE token_hash = ?', [hash('sha256', $token)]) : null;
    }

    /** Takes the link for one submission: false when somebody else took it a moment ago. */
    public static function claim(Db $db, array $link): bool
    {
        return $db->run("UPDATE {form_links} SET used_at = ? WHERE id = ? AND used_at IS NULL AND state <> 'revoked'", [date('Y-m-d H:i:s'), (int) $link['id']])->rowCount() === 1;
    }

    /** The submission failed: the link can be used again. */
    public static function release(Db $db, array $link): void
    {
        $db->run('UPDATE {form_links} SET used_at = NULL WHERE id = ?', [(int) $link['id']]);
    }

    /** The submission arrived: what the person agreed to, and the item it made (an answer to a reviewed item waits for a person). */
    public static function finish(Db $db, array $link, int $itemId, string $consent): void
    {
        $db->update('form_links', ['item_id' => $itemId > 0 ? $itemId : null, 'consent' => $consent]
            + (!empty($link['target_item_id']) && $itemId > 0 ? ['state' => 'waiting'] : []), ['id' => (int) $link['id']]);
    }

    /** The links of an enquiry for its detail: when, to whom, whether it came back and the draft it made. @return list<array<string, mixed>> */
    public static function ofEnquiry(Db $db, int $enquiry): array
    {
        return $db->all('SELECT id, form_id, email, label, created_at, expires_at, used_at, item_id FROM {form_links} WHERE enquiry_id = ? ORDER BY id DESC', [$enquiry]);
    }

    /** The links of a form, newest first. @return list<array<string, mixed>> */
    public static function ofForm(Db $db, int $formId, int $limit = 200): array
    {
        return $db->all('SELECT * FROM {form_links} WHERE form_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)), [$formId]);
    }

    /** The links that review an item, or whose answer is that item (a draft next to the original). @return list<array<string, mixed>> */
    public static function ofItem(Db $db, int $itemId): array
    {
        return $db->all('SELECT * FROM {form_links} WHERE target_item_id = ? OR (item_id = ? AND target_item_id IS NOT NULL) ORDER BY id DESC', [$itemId, $itemId]);
    }

    /** How many drafts a link keeps (the oldest goes first). */
    public const int DRAFTS = 20;

    /** The drafts of a link, oldest first: by "site" (our suggestion) or "person" (saved by them). @return list<array{at: string, by: string, values: array<string, string|list<string>>}> */
    public static function drafts(array $link): array
    {
        $drafts = json_decode((string) ($link['drafts'] ?? ''), true);

        return is_array($drafts) ? array_values(array_filter($drafts, static fn (mixed $d): bool => is_array($d) && is_array($d['values'] ?? null))) : [];
    }

    /** The answers the form shows now: the latest draft. @return array<string, string|list<string>> */
    public static function draft(array $link): array
    {
        $drafts = self::drafts($link);

        return $drafts === [] ? [] : (array) $drafts[count($drafts) - 1]['values'];
    }

    /**
     * Keeps what the person typed without sending it (the form option link_save): one more draft at the end of the link's history.
     * Answers are checked like a suggestion; one that does not fit (a choice that is no option) is left out, never refused – the
     * person sends the form later and the usual checks apply then.
     *
     * @param list<array<string, mixed>> $fields the form's fields
     * @param array<string, mixed> $answers field key => answer
     */
    public static function saveDraft(Db $db, array $link, array $fields, array $answers): void
    {
        $values = [];
        foreach ($answers as $key => $answer) {
            try {
                $values += self::cleanPrefill($fields, [(string) $key => $answer]);
            } catch (\DomainException) {
                // left out
            }
        }
        $db->update('form_links', ['drafts' => self::json(array_slice([...self::drafts($link), self::entry($values, 'person')], -self::DRAFTS))], ['id' => (int) $link['id']]);
    }

    /** One draft of the history. @param array<string, string|list<string>> $values @return array{at: string, by: string, values: array<string, string|list<string>>} */
    private static function entry(array $values, string $by): array
    {
        return ['at' => date('Y-m-d H:i:s'), 'by' => $by, 'values' => $values];
    }

    /** @return array<string, mixed> the link's fixed values by collection field key */
    public static function fixed(array $link): array
    {
        $values = json_decode((string) ($link['fixed'] ?? ''), true);

        return is_array($values) ? $values : [];
    }

    /**
     * The suggested answers, checked against the form's fields: a field is named by its key or its label; a choice must be one of
     * the field's options, a date a real day. Empty values are left out.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $input
     * @return array<string, string|list<string>>
     * @throws \DomainException naming the field that does not fit
     */
    public static function cleanPrefill(array $fields, array $input): array
    {
        $out = [];
        foreach ($input as $name => $value) {
            $field = self::field($fields, (string) $name);
            if ($field === null) {
                throw new \DomainException(sprintf('prefill: the form has no field “%s”. Name a field by its label or key.', $name));
            }
            $type = (string) $field['type'];
            if ($type === 'checkbox') {
                throw new \DomainException(sprintf('prefill: the field “%s” is the consent – the person ticks it themselves when they send the form.', $field['label']));
            }
            if (!in_array($type, self::PREFILLABLE, true)) {
                throw new \DomainException(sprintf('prefill: the field “%s” cannot be filled in by a link.', $field['label']));
            }
            if (is_array($value) && $type !== 'checkboxes') {
                throw new \DomainException(sprintf('prefill: the field “%s” takes one value.', $field['label']));
            }
            $text = is_array($value) ? '' : trim(str_replace("\r\n", "\n", (string) (is_bool($value) ? ($value ? '1' : '') : (is_scalar($value) ? $value : ''))));
            $options = in_array($type, ['select', 'radio', 'checkboxes'], true) ? array_map('strval', \Talea\Builder\Elements\Form::options($field)) : [];
            $clean = match ($type) {
                'textarea' => mb_substr($text, 0, 5000),
                'select', 'radio' => $text === '' ? '' : self::option($options, $text),
                'checkboxes' => ($list = array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : (preg_split('/\R|,\s*/u', $text) ?: [])), static fn (string $v): bool => $v !== ''))) === []
                    ? [] : (in_array(null, $list = array_map(static fn (string $v): ?string => self::option($options, $v), $list), true) ? null : $list),
                'timerange' => $text === '' ? '' : (preg_match('/^(([01]\d|2[0-3]):[0-5]\d)\s*[-–]\s*(([01]\d|2[0-3]):[0-5]\d)$/u', $text, $t) === 1 ? $t[1] . '-' . $t[3] : null),
                'date' => $text === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 && checkdate((int) substr($text, 5, 2), (int) substr($text, 8, 2), (int) substr($text, 0, 4))) ? $text : null,
                'number' => $text === '' || preg_match('/^-?\d{1,12}([.,]\d{1,6})?$/', $text) === 1 ? $text : null,
                'email' => $text === '' || filter_var($text, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($text, 0, 190) : null,
                default => mb_substr(str_replace("\n", ' ', $text), 0, 300),
            };
            if ($clean === null) {
                throw new \DomainException(sprintf('prefill: “%s” is not a valid answer for the field “%s”.', is_array($value) ? implode(', ', array_map('strval', $value)) : $text, $field['label']));
            }
            if ($clean !== '' && $clean !== []) {
                $out[(string) $field['key']] = $clean;
            }
        }

        return $out;
    }

    /**
     * What the person sees filled in when they review an item: the answers the form's mapping made an item field of come back from
     * that field (a template of several answers, "{Name}, {Role}", is read back into its parts), and the item's name for the fields
     * the item name is made of. Formatted text comes back as its plain text (CollectionSubmission::textFromHtml), a date and time as
     * the date a date field shows.
     *
     * @param array<string, mixed> $content the form's content
     * @param array<string, mixed> $item a row of collection_items
     * @param list<array<string, mixed>> $collectionFields the fields of the item's collection (for their types)
     * @return array<string, string|list<string>>
     */
    public static function prefillFromItem(array $content, array $item, array $collectionFields = []): array
    {
        return self::fromItem($content, $item, $collectionFields)['values'];
    }

    /**
     * prefillFromItem with what it could not show: missed = labels of form fields the item has a value for that does not fit the
     * field (an option the form does not offer, a field type a link cannot fill). An upload is never missed: no form shows a file.
     *
     * @return array{values: array<string, string|list<string>>, missed: list<string>}
     */
    public static function fromItem(array $content, array $item, array $collectionFields = []): array
    {
        $types = array_column($collectionFields, 'type', 'key');
        $data = is_array($item['data']) ? $item['data'] : (json_decode((string) $item['data'], true) ?: []);
        $suggested = [];
        $templates = [[(string) ($content['item_name'] ?? ''), (string) $item['name']]];
        foreach ((array) ($content['mapping'] ?? []) as $row) {
            $target = (string) ($row['target'] ?? '');
            $value = $data[$target] ?? null;
            if (in_array($types[$target] ?? '', CollectionSubmission::UPLOADS, true) || !is_scalar($value)) {
                continue;
            }
            $templates[] = [(string) ($row['template'] ?? ''), ($types[$target] ?? '') === 'html' ? CollectionSubmission::textFromHtml((string) $value) : (string) $value];
        }
        foreach ($templates as [$template, $value]) {
            foreach ($value !== '' ? self::readTemplate($template, $value) : [] as $label => $part) {
                $suggested[$label] ??= $part;
            }
        }
        $fields = (array) ($content['fields'] ?? []);
        $out = [];
        $missed = [];
        foreach ($suggested as $label => $value) {
            $field = self::field($fields, (string) $label);
            if ($field === null) {
                continue; // a placeholder that is no field of the form (CollectionSubmission::mappingProblems names it)
            }
            if ($field['type'] === 'date' && preg_match('/^(\d{4}-\d{2}-\d{2})[ T]\d/', $value, $m) === 1) {
                $value = $m[1];
            }
            try {
                $out += self::cleanPrefill($fields, [(string) $label => $value]);
            } catch (\DomainException) {
                $missed[] = (string) $field['label']; // the person fills it in; applying keeps the item's value when they leave it empty
            }
        }

        return ['values' => $out, 'missed' => array_values(array_unique($missed))];
    }

    /**
     * The answers a template was filled with, read back from the value it made (the way back of CollectionSubmission::fill):
     * "{Name}, {Role}" and "Eva, CEO" give Name = Eva, Role = CEO; a later part that was empty may be missing with its separator
     * ("Eva"). A value that does not have the template's shape gives nothing.
     *
     * @return array<string, string> label => answer
     */
    public static function readTemplate(string $template, string $value): array
    {
        $parts = preg_split('/\{([^{}]{1,200})\}/u', trim($template), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        if (count($parts) < 3) {
            return [];
        }
        $pattern = '^' . preg_quote(ltrim($parts[0]), '/') . '(.*?)';
        for ($i = 3; $i < count($parts); $i += 2) {
            $pattern .= '(?:' . preg_quote($parts[$i - 1], '/') . '(.*?))?';
        }
        if (preg_match('/' . $pattern . preg_quote(rtrim((string) end($parts)), '/') . '$/su', trim($value), $m) !== 1) {
            return [];
        }
        $answers = [];
        for ($i = 1, $n = 1; $i < count($parts); $i += 2, $n++) {
            $answers[trim($parts[$i])] ??= trim($m[$n] ?? '');
        }

        return array_filter($answers, static fn (string $a): bool => $a !== '');
    }

    /**
     * Applies the answer to a reviewed item: the fields the form fills (and the link's fixed values) and the name go into the item,
     * whose previous state is kept as a version; the draft goes to the trash.
     *
     * @throws \DomainException when there is no answer waiting
     */
    public static function apply(App $app, array $link): void
    {
        [$original, $draft, $collection, $keys] = self::review($app->db(), $link);
        $data = (array) $original['data'];
        foreach (self::outcome($app->db(), $link, $original, $draft, $collection, $keys) as $key => $result) {
            $data[$key] = $result['value'];
        }
        $new = ['name' => (string) $draft['name'] !== '' ? (string) $draft['name'] : (string) $original['name'], 'data' => self::json($data), 'updated_at' => date('Y-m-d H:i:s')];
        $row = $app->db()->one('SELECT * FROM {collection_items} WHERE item_id = ?', [(int) $original['item_id']]) ?? [];
        Collections::saveVersion($app, $row, $new);
        $app->db()->update('collection_items', $new, ['item_id' => (int) $original['item_id']]);
        $app->db()->update('collection_items', ['deleted_at' => date('Y-m-d H:i:s')], ['item_id' => (int) $draft['item_id']]);
        $app->db()->update('form_links', ['state' => 'applied'], ['id' => (int) $link['id']]);
        SeoScore::refresh($app, 'item', (int) $original['item_id']);
        \Talea\Front\Cache::clear();
        \Talea\Admin\ChangeLog::write($app, 'collections', 'answer_applied', mb_substr((string) $original['name'], 0, 80) . ' (' . (string) $collection['name'] . ')');
    }

    /** Leaves the reviewed item as it is; the draft answer goes to the trash. */
    public static function discard(App $app, array $link): void
    {
        [$original, $draft] = self::review($app->db(), $link);
        $app->db()->update('collection_items', ['deleted_at' => date('Y-m-d H:i:s')], ['item_id' => (int) $draft['item_id']]);
        $app->db()->update('form_links', ['state' => 'discarded'], ['id' => (int) $link['id']]);
        \Talea\Admin\ChangeLog::write($app, 'collections', 'answer_discarded', mb_substr((string) $original['name'], 0, 80));
    }

    /**
     * Original and answer side by side: per field the item has now and the answer brings (only the fields the answer fills).
     *
     * kept = the item's value stays because the person never saw it (a file not uploaded again, a value the form could not show).
     *
     * @return array{original: array<string, mixed>, draft: array<string, mixed>, rows: list<array{label: string, before: string, after: string, changed: bool, kept: bool}>}
     */
    public static function comparison(Db $db, array $link): array
    {
        [$original, $draft, $collection, $keys] = self::review($db, $link);
        $labels = array_column((array) $collection['fields'], 'label', 'key');
        $types = array_column((array) $collection['fields'], 'type', 'key');
        $rows = [['label' => t('Name'), 'before' => (string) $original['name'], 'after' => (string) $draft['name'], 'changed' => (string) $original['name'] !== (string) $draft['name'], 'kept' => false]];
        foreach (self::outcome($db, $link, $original, $draft, $collection, $keys) as $key => $result) {
            $before = self::textOf((string) ($types[$key] ?? ''), $original['data'][$key] ?? '');
            $after = self::textOf((string) ($types[$key] ?? ''), $result['value']);
            $rows[] = ['label' => (string) ($labels[$key] ?? $key), 'before' => $before, 'after' => $after, 'changed' => $before !== $after, 'kept' => $result['kept']];
        }

        return ['original' => $original, 'draft' => $draft, 'rows' => $rows];
    }

    /** The form's content (its Form element as published). @param array<string, mixed> $form @return array<string, mixed> */
    public static function content(array $form): array
    {
        return (array) (FormRecords::definition(Build::fromJson($form['build'] ?? null))['content'] ?? []);
    }

    /** The field of a form named by its key or its label (case does not matter). @param list<array<string, mixed>> $fields @return array<string, mixed>|null */
    public static function field(array $fields, string $name): ?array
    {
        foreach ($fields as $f) {
            if ((string) ($f['key'] ?? '') !== '' && (string) $f['key'] === $name) {
                return $f;
            }
        }
        foreach ($fields as $f) {
            if (mb_strtolower(trim((string) ($f['label'] ?? ''))) === mb_strtolower(trim($name)) && trim($name) !== '') {
                return $f;
            }
        }

        return null;
    }

    /** How many days a new link of the form works. @param array<string, mixed> $content */
    public static function days(array $content): int
    {
        return max(1, min(365, (int) ($content['link_days'] ?? self::DAYS)));
    }

    /**
     * Mails the link with the form's own subject and text. Placeholders: {link}, {days}, {site name}, {name} (whom the link is for),
     * {first name}, and {Field label} for a suggested answer.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $content
     * @param array<string, string|list<string>> $prefill
     */
    public static function mail(App $app, array $form, array $content, string $email, string $link, int $days, string $label, array $prefill = []): bool
    {
        $site = (string) $app->settings()->get('site_name');
        $values = ['link' => $link, 'days' => (string) $days, 'site name' => $site, 'name' => $label, 'first name' => (string) strtok($label, ' ')];
        foreach ((array) ($content['fields'] ?? []) as $f) {
            $answer = $prefill[(string) ($f['key'] ?? '')] ?? null;
            if ($answer !== null) {
                $values[mb_strtolower(trim((string) $f['label']))] ??= is_array($answer) ? implode(', ', $answer) : $answer;
            }
        }
        // in the language of the site the person wrote to, not of the admin who clicked
        [$subject, $text] = Language::runWith($app->settings()->get('site_language') ?: 'en', function () use ($content, $form, $link, $days, $site, $values): array {
            $fill = static fn (string $s): string => (string) preg_replace_callback('/\{([^{}\n]{1,200})\}/u', static fn (array $m): string => $values[mb_strtolower(trim($m[1]))] ?? $m[0], $s);
            $subject = trim((string) ($content['link_subject'] ?? ''));
            $body = trim((string) ($content['link_text'] ?? ''));

            return [$subject !== '' ? str_replace(["\r", "\n"], ' ', $fill($subject)) : t('%s: %s', (string) $form['name'], $site),
                $body !== '' ? $fill($body) : t("Hello,\n\nplease fill in this short form:\n\n%s\n\nThe link works for %d days.\n\n%s", $link, $days, $site)];
        });

        return Mail::send($app->settings(), $email, $subject, $text);
    }

    /** The item a link reviews: in the collection the form adds to, not in the trash. @param array<string, mixed> $content @return array<string, mixed> */
    private static function targetItem(Db $db, array $content, int $item): array
    {
        $collection = CollectionSubmission::collection($db, $content);
        if ($collection === null) {
            throw new \DomainException('item: this form does not add to a collection, so it cannot review an item.');
        }
        $row = $db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND collection_id = ? AND deleted_at IS NULL', [$item, (int) $collection['collection_id']]);

        return $row ?? throw new \DomainException(sprintf('item: the item is not in the collection “%s” the form adds to.', $collection['name']));
    }

    /**
     * Fixed values of the item the answer makes, checked like any item value; only for a form that adds to a collection.
     *
     * @param array<string, mixed> $content
     * @param array<string, mixed> $input collection field key => value
     * @return array<string, mixed>
     */
    private static function cleanFixed(Db $db, array $content, array $input): array
    {
        if ($input === []) {
            return [];
        }
        $collection = CollectionSubmission::collection($db, $content);
        if ($collection === null) {
            throw new \DomainException('fixed: only a form that adds to a collection takes fixed values.');
        }
        $fields = array_column((array) $collection['fields'], null, 'key');
        $used = [];
        foreach (array_keys($input) as $key) {
            if (!isset($fields[(string) $key])) {
                throw new \DomainException(sprintf('fixed: the collection “%s” has no field “%s”. list_collections shows the keys.', $collection['name'], $key));
            }
            $used[] = $fields[(string) $key];
        }
        $errors = [];
        $clean = Collections::sanitizeData($used, $input, $errors);
        if ($errors !== []) {
            throw new \DomainException(sprintf('fixed: the value of “%s” does not fit the field.', implode('”, “', $errors)));
        }

        return array_filter($clean, static fn (string $v): bool => $v !== '');
    }

    /**
     * The original, the draft answer, the collection and the field keys the answer fills.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: list<string>}
     */
    private static function review(Db $db, array $link): array
    {
        if (($link['state'] ?? '') !== 'waiting' || empty($link['target_item_id']) || empty($link['item_id'])) {
            throw new \DomainException('No answer is waiting for this link.');
        }
        $original = $db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND deleted_at IS NULL', [(int) $link['target_item_id']]);
        $draft = $db->one('SELECT * FROM {collection_items} WHERE item_id = ? AND deleted_at IS NULL', [(int) $link['item_id']]);
        $collection = $original !== null ? Collections::byId($db, (int) $original['collection_id']) : null;
        if ($original === null || $draft === null || $collection === null) {
            throw new \DomainException('The item or the answer is gone (in the trash?).');
        }
        $original['data'] = json_decode((string) $original['data'], true) ?: [];
        $draft['data'] = json_decode((string) $draft['data'], true) ?: [];
        $form = FormRecords::byId($db, (int) $link['form_id']);
        $keys = array_map(static fn (array $r): string => (string) ($r['target'] ?? ''), (array) (($form !== null ? self::content($form) : [])['mapping'] ?? []));
        $keys = array_values(array_unique(array_filter([...$keys, ...array_keys(self::fixed($link))], static fn (string $k): bool => $k !== '')));
        if ($keys === []) {
            $keys = array_keys(array_filter($draft['data'], static fn (mixed $v): bool => $v !== '' && $v !== []));
        }

        return [$original, $draft, $collection, $keys];
    }

    /**
     * What applying writes per field. The answer, except where an empty answer would wipe a value the person never saw: an upload
     * field (no form shows the current file) or a field whose answers the form could not fill in from the item. A field the person
     * saw filled in and cleared is cleared. Formatted text whose words did not change keeps its formatting (the form showed plain text),
     * and a date and time whose day did not change keeps its time (a date field showed only the day).
     *
     * @param list<string> $keys
     * @return array<string, array{value: mixed, kept: bool}>
     */
    private static function outcome(Db $db, array $link, array $original, array $draft, array $collection, array $keys): array
    {
        $form = FormRecords::byId($db, (int) $link['form_id']);
        $content = $form !== null ? self::content($form) : [];
        $fields = (array) $collection['fields'];
        $types = array_column($fields, 'type', 'key');
        $shown = self::fromItem($content, $original, $fields)['values'];
        $formFields = (array) ($content['fields'] ?? []);
        $fixed = self::fixed($link);
        $empty = static fn (mixed $v): bool => $v === null || $v === '' || $v === [];
        // a field the person saw completely: every answer its template uses was filled in from the item
        $sawAll = static function (string $key) use ($content, $shown, $formFields): bool {
            foreach ((array) ($content['mapping'] ?? []) as $row) {
                if ((string) ($row['target'] ?? '') !== $key) {
                    continue;
                }
                preg_match_all('/\{([^{}]{1,200})\}/u', (string) ($row['template'] ?? ''), $m);
                foreach ($m[1] as $label) {
                    if (!isset($shown[(string) (self::field($formFields, $label)['key'] ?? '')])) {
                        return false;
                    }
                }
            }

            return true;
        };
        $out = [];
        foreach ($keys as $key) {
            $before = $original['data'][$key] ?? '';
            $answer = $draft['data'][$key] ?? '';
            $type = (string) ($types[$key] ?? '');
            if ($empty($answer) && !$empty($before) && !array_key_exists($key, $fixed) && (in_array($type, CollectionSubmission::UPLOADS, true) || !$sawAll($key))) {
                $out[$key] = ['value' => $before, 'kept' => true];
            } elseif ($type === 'html' && self::textOf('html', $before) === self::textOf('html', $answer)) {
                $out[$key] = ['value' => $before, 'kept' => false];
            } elseif ($type === 'datetime' && is_string($before) && is_string($answer) && strlen($answer) === 10 && str_starts_with($before, $answer . ' ')) {
                $out[$key] = ['value' => $before, 'kept' => false]; // a date field showed the day of a date and time: the same day keeps the time
            } else {
                $out[$key] = ['value' => $answer, 'kept' => false];
            }
        }

        return $out;
    }

    /** The option of a choice a value names, ignoring case and spaces at the ends; null when it is none of them. @param list<string> $options */
    private static function option(array $options, string $value): ?string
    {
        foreach ($options as $option) {
            if (mb_strtolower(trim($option)) === mb_strtolower(trim($value))) {
                return $option;
            }
        }

        return null;
    }

    /** A value as the text the comparison shows: a list joined, formatted text as its plain text. */
    private static function textOf(string $type, mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(static fn (mixed $x): string => is_scalar($x) ? (string) $x : '', $value));
        }

        return $type === 'html' ? CollectionSubmission::textFromHtml((string) $value) : (string) $value;
    }

    private static function json(array $values): string
    {
        return (string) json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
