<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Builder\Collections;

/**
 * A form that adds an item to a collection instead of an enquiry (the form element's destination "collection"): what a visitor
 * sends becomes a hidden draft item that a person publishes, never a live one.
 *
 *  - The form maps its answers to the collection's fields: per field a template with {Field label} placeholders (and one for
 *    the item's name). The values go through Collections::sanitizeData like any other save, so a value that does not fit its
 *    field type is left empty.
 *  - A visitor fills simple fields and formatted text (FILLABLE) and uploads an image or a file (UPLOADS); galleries, links to
 *    other items and the like are not for visitors. Formatted text gets the visitor's plain text as paragraphs (htmlFromText):
 *    never markup they typed. An image or file is never taken from a typed address: only from an upload,
 *    re-encoded or checked, and kept waiting outside the web root until the item is published (Core\MediaStaging).
 *  - The slug is made from the name, and the language is the page's, never the visitor's choice.
 */
final class CollectionSubmission
{
    /** Collection field types a visitor can fill with text. */
    public const array FILLABLE = ['text', 'lines', 'html', 'number', 'date', 'datetime', 'radio', 'link'];

    /** Collection field types a visitor can fill with an upload. */
    public const array UPLOADS = ['image', 'file'];

    public const int MAX_UPLOAD = 8 * 1024 * 1024;

    /** The collection a form adds to, named by its ID or its address (slug), or null (the form sends enquiries, or the collection is gone). @return array<string, mixed>|null */
    public static function collection(Db $db, array $content): ?array
    {
        if (($content['destination'] ?? 'enquiry') !== 'collection') {
            return null;
        }
        $named = (string) ($content['collection'] ?? '');
        $row = Uuid::valid($named) ? $db->byPublicId('collections', $named) : null;

        return $row !== null ? Collections::byId($db, (int) $row['collection_id']) : (preg_match('/^[a-z0-9-]{1,110}$/', $named) === 1 ? Collections::bySlug($db, $named) : null);
    }

    /**
     * A template with {Field label} placeholders filled from the answers (a label that is not in the form gives nothing).
     *
     * @param list<array<int, string>> $data [label, answer, …] per field as the form collected them
     */
    public static function fill(string $template, array $data): string
    {
        $answers = [];
        foreach ($data as $entry) {
            $answers[mb_strtolower(trim((string) $entry[0]))] ??= (string) $entry[1];
        }
        $text = (string) preg_replace_callback('/\{([^{}]{1,200})\}/u', static fn (array $m): string => $answers[mb_strtolower(trim($m[1]))] ?? '', $template);

        return trim((string) preg_replace('/^[\s,]+|[\s,]+$/u', '', $text));
    }

    /**
     * Adds the item. Returns its id, or the reason it was refused for the page to report: 'collection', 'name', 'required', or
     * 'field:<n>' – the answer of the form's field n (or 'field' when no single field made it) does not fit its collection field (a
     * number, a date, an option the collection does not have). Nothing is dropped silently: an answer that does not fit is refused.
     *
     * @param list<array<int, string>> $data the answers as [label, answer, …]
     * @param array<string, array{0: string, 1: string}> $uploads field label => [temporary file, file name] of what the visitor uploaded
     * @param array<string, mixed> $fixed collection field key => value set by the personal link (Core\FormLinks), checked when it was made
     */
    public static function save(App $app, array $content, array $data, array $uploads, string $language, int $formId = 0, array $fixed = []): int|string
    {
        $db = $app->db();
        $collection = self::collection($db, $content);
        if ($collection === null) {
            return 'collection';
        }
        $fields = (array) $collection['fields'];
        $byKey = array_column($fields, null, 'key');
        $name = mb_substr(self::fill((string) ($content['item_name'] ?? ''), $data), 0, 200);
        if ($name === '') {
            return 'name';
        }
        [$input, $files] = self::input($byKey, $content, $data, $uploads);
        // the link's fixed values (an image, the service) go over what the form maps; the person never saw them
        $errors = [];
        $values = Collections::sanitizeData($fields, array_replace($input, array_intersect_key($fixed, $byKey)), $errors);
        foreach (array_keys(array_diff_key($errors, $fixed)) as $key) {
            return self::refusedField($content, (string) $key);
        }
        foreach ($files as $key => [$tmp, $fileName]) {
            if (($path = self::stage($db, $tmp, $fileName, (string) $byKey[$key]['type'])) !== '') {
                $values[$key] = $path;
            }
        }
        if (Collections::missingRequired($fields, $values) !== []) {
            return 'required';
        }
        $idk = (int) $collection['collection_id'];
        $slug = Slug::makeUnique(slugify($name, 150), static fn (string $s): bool => $db->value('SELECT item_id FROM {collection_items} WHERE collection_id = ? AND language = ? AND slug = ?', [$idk, $language, $s]) !== null);
        $itemId = $db->insert('collection_items', ['collection_id' => $idk, 'name' => $name, 'slug' => $slug, 'data' => (string) json_encode($values, JSON_UNESCAPED_UNICODE),
            'visible' => 0, 'language' => $language, 'created_at' => date('Y-m-d H:i:s'), 'sort_order' => 0, 'submitted_via' => $formId > 0 ? $formId : null]);
        Slug::fromId($db, 'collection_items', $itemId);
        Events::record($db, 'collection.submission', 'info', t('A submission arrived – a hidden draft in %s.', (string) $collection['name']), ['item' => $itemId]);

        return $itemId;
    }

    /**
     * What the answers make of the item's fields: the mapped values by field key, and the uploads by field key.
     *
     * @param array<string, array<string, mixed>> $byKey the collection's fields by key
     * @param list<array<int, string>> $data
     * @param array<string, array{0: string, 1: string}> $uploads
     * @return array{0: array<string, string>, 1: array<string, array{0: string, 1: string}>}
     */
    private static function input(array $byKey, array $content, array $data, array $uploads): array
    {
        $input = [];
        $files = [];
        foreach ((array) ($content['mapping'] ?? []) as $row) {
            $key = (string) ($row['target'] ?? '');
            $type = (string) ($byKey[$key]['type'] ?? '');
            $template = trim((string) ($row['template'] ?? ''));
            if (in_array($type, self::UPLOADS, true)) {
                // the form's own file (a media/… path without placeholders) is a fixed value: every item gets it
                if (!str_contains($template, '{') && preg_match('#^/?media/[A-Za-z0-9/_.-]{1,300}$#', $template) === 1 && !str_contains($template, '..')) {
                    $input[$key] = $template;
                    continue;
                }
                // only {Label} of an upload field: whatever else a visitor typed is not a file
                if (preg_match('/^\{([^{}]+)\}$/u', $template, $m) === 1) {
                    foreach ($uploads as $label => $upload) {
                        if (mb_strtolower(trim((string) $label)) === mb_strtolower(trim($m[1]))) {
                            $files[$key] = $upload;
                        }
                    }
                }
            } elseif ($type === 'html') {
                $input[$key] = self::htmlFromText(self::fill($template, $data));
            } elseif ($type === 'radio') {
                // the collection's own option, whatever the case the form wrote it in
                $answer = self::fill($template, $data);
                $input[$key] = array_values(array_filter((array) ($byKey[$key]['options'] ?? []), static fn (mixed $o): bool => mb_strtolower(trim((string) $o)) === mb_strtolower($answer)))[0] ?? $answer;
            } elseif (in_array($type, self::FILLABLE, true)) {
                $input[$key] = self::fill($template, $data);
            }
        }

        return [$input, $files];
    }

    /** 'field:<n>' for the form field whose answer fills the collection field $key alone or first, 'field' when there is none. */
    private static function refusedField(array $content, string $key): string
    {
        foreach ((array) ($content['mapping'] ?? []) as $row) {
            if ((string) ($row['target'] ?? '') === $key && preg_match('/\{([^{}]{1,200})\}/u', (string) ($row['template'] ?? ''), $m) === 1) {
                foreach (array_values((array) ($content['fields'] ?? [])) as $n => $f) {
                    if (mb_strtolower(trim((string) ($f['label'] ?? ''))) === mb_strtolower(trim($m[1]))) {
                        return 'field:' . $n;
                    }
                }
            }
        }

        return 'field';
    }

    /**
     * The item the answers would make, without saving anything (MCP try_form): its name and field values, the mapped fields that
     * stay empty, and refused: the fields whose answer does not fit (sending the form would ask the visitor to correct it). Answers are by form field key, as try_form takes them.
     *
     * @param array<string, mixed> $content the form's content
     * @param array<string, mixed> $answers form field key => answer (a list for checkboxes)
     * @return array<string, mixed>|null null when the form does not add to a collection
     */
    public static function preview(Db $db, array $content, array $answers): ?array
    {
        $collection = self::collection($db, $content);
        if ($collection === null) {
            return null;
        }
        $data = [];
        foreach ((array) ($content['fields'] ?? []) as $f) {
            $answer = $answers[(string) ($f['key'] ?? '')] ?? '';
            $data[] = [(string) ($f['label'] ?? ''), is_array($answer) ? implode(', ', array_map('strval', array_filter($answer, 'is_scalar'))) : (is_scalar($answer) ? (string) $answer : '')];
        }
        $fields = (array) $collection['fields'];
        $byKey = array_column($fields, null, 'key');
        [$input] = self::input($byKey, $content, $data, []);
        $errors = [];
        $values = Collections::sanitizeData($fields, $input, $errors);
        $mapped = array_values(array_unique(array_map(static fn (array $r): string => (string) ($r['target'] ?? ''), array_filter((array) ($content['mapping'] ?? []), 'is_array'))));
        $empty = array_values(array_filter($mapped, static fn (string $key): bool => isset($byKey[$key]) && !in_array($byKey[$key]['type'], self::UPLOADS, true) && ($values[$key] ?? '') === ''));

        return ['collection' => (string) $collection['name'], 'name' => mb_substr(self::fill((string) ($content['item_name'] ?? ''), $data), 0, 200),
            'values' => array_intersect_key($values, array_flip($mapped)), 'empty_fields' => array_values(array_diff($empty, array_keys($errors))),
            'refused' => array_keys($errors), 'problems' => self::mappingProblems($db, $content)];
    }

    /**
     * What is wrong with a form's mapping, before a visitor finds out: a field the collection does not have, a field a visitor
     * cannot fill (its answer would be lost), and a {placeholder} that is no field of the form (it stays empty).
     *
     * @param array<string, mixed> $content the form's content
     * @return list<string>
     */
    public static function mappingProblems(Db $db, array $content): array
    {
        $collection = self::collection($db, $content);
        if ($collection === null) {
            return ($content['destination'] ?? 'enquiry') === 'collection' ? [t('The form adds to a collection that does not exist.')] : [];
        }
        $byKey = array_column((array) $collection['fields'], null, 'key');
        $labels = array_map(static fn (array $f): string => mb_strtolower(trim((string) ($f['label'] ?? ''))), array_filter((array) ($content['fields'] ?? []), 'is_array'));
        $problems = [];
        $templates = [(string) ($content['item_name'] ?? '')];
        foreach ((array) ($content['mapping'] ?? []) as $row) {
            $key = (string) ($row['target'] ?? '');
            $field = $byKey[$key] ?? null;
            if ($field === null) {
                $problems[] = t('The form fills “%s”, which is not a field of the collection “%s”.', $key, (string) $collection['name']);
                continue;
            }
            if (!in_array($field['type'], [...self::FILLABLE, ...self::UPLOADS], true)) {
                $problems[] = t('The field “%s” of the collection cannot be filled by a form – what a visitor writes for it is lost. Remove it from the mapping.', (string) $field['label']);
            }
            $templates[] = (string) ($row['template'] ?? '');
            // a choice of the form that fills a choice of the collection: every option of the form must be one of the collection's
            if ($field['type'] === 'radio' && preg_match('/^\{([^{}]{1,200})\}$/u', trim((string) ($row['template'] ?? '')), $m) === 1
                && ($formField = FormLinks::field((array) ($content['fields'] ?? []), $m[1])) !== null && in_array($formField['type'], ['select', 'radio'], true)) {
                $known = array_map(static fn (mixed $o): string => mb_strtolower(trim((string) $o)), (array) ($field['options'] ?? []));
                $foreign = array_filter(array_map('strval', \Talea\Builder\Elements\Form::options($formField)), static fn (string $o): bool => !in_array(mb_strtolower(trim($o)), $known, true));
                if ($foreign !== []) {
                    $problems[] = t('The form offers options the collection field “%s” does not have: %s. A visitor who picks one is asked to choose again.', (string) $field['label'], implode(', ', $foreign));
                }
            }
        }
        foreach ($templates as $template) {
            preg_match_all('/\{([^{}]{1,200})\}/u', $template, $m);
            foreach ($m[1] as $label) {
                if (!in_array(mb_strtolower(trim($label)), $labels, true)) {
                    $problems[] = t('“{%s}” is not a field of the form – it stays empty.', trim($label));
                }
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * A visitor's plain text as formatted text: everything escaped, an empty line starts a paragraph, a single line break stays one.
     */
    public static function htmlFromText(string $text): string
    {
        $paragraphs = preg_split('/\R\s*\R/u', trim(str_replace("\r\n", "\n", $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode("\n", array_map(static fn (string $p): string => '<p>' . nl2br(e(trim($p)), false) . '</p>', $paragraphs));
    }

    /**
     * Formatted text as the plain text a form field shows (the way back of htmlFromText): paragraphs and blocks are separated by an
     * empty line, a <br> becomes a line break, list items a line each; markup and entities are gone.
     */
    public static function textFromHtml(string $html): string
    {
        $html = (string) preg_replace('/\s+/u', ' ', $html); // line breaks in the markup itself mean nothing
        $html = (string) preg_replace('#\s*<br\s*/?>\s*#i', "\n", $html);
        $html = (string) preg_replace('#</(p|h[1-6]|blockquote|ul|ol|figure|div|table|pre)>#i', "\n\n", $html);
        $html = (string) preg_replace('#</(li|tr|figcaption)>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $lines = array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** The upload, checked and re-encoded, waiting for approval; its public path, or '' when it cannot be used. */
    private static function stage(Db $db, string $tmp, string $fileName, string $type): string
    {
        if (!is_file($tmp) || filesize($tmp) > self::MAX_UPLOAD) {
            return '';
        }
        try {
            $saved = $type === 'image' ? Images::saveFile($tmp, $fileName, true) : Files::saveFile($tmp, $fileName, true);
        } catch (\RuntimeException) {
            return ''; // the answer still arrives, without the file
        }
        $db->insert('media', $saved + ['owner_id' => null, 'staged_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);

        return $saved['image_path'];
    }
}
