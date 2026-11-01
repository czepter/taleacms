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
 *  - A visitor fills only simple fields (FILLABLE) and uploads an image or a file (UPLOADS); formatted text, galleries, links to
 *    other items and the like are not for visitors. An image or file is never taken from a typed address: only from an upload,
 *    re-encoded or checked, and kept waiting outside the web root until the item is published (Core\MediaStaging).
 *  - The slug is made from the name, and the language is the page's, never the visitor's choice.
 */
final class CollectionSubmission
{
    /** Collection field types a visitor can fill with text. */
    public const array FILLABLE = ['text', 'lines', 'number', 'date', 'datetime', 'radio', 'link'];

    /** Collection field types a visitor can fill with an upload. */
    public const array UPLOADS = ['image', 'file'];

    public const int MAX_UPLOAD = 8 * 1024 * 1024;

    /** The collection a form adds to, or null (the form sends enquiries, or the collection is gone). @return array<string, mixed>|null */
    public static function collection(Db $db, array $content): ?array
    {
        if (($content['destination'] ?? 'enquiry') !== 'collection') {
            return null;
        }
        $row = $db->byPublicId('collections', (string) ($content['collection'] ?? ''));

        return $row === null ? null : Collections::byId($db, (int) $row['collection_id']);
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
     * Adds the item. Returns its id, or the reason it was refused ('collection', 'name' or 'required') for the page to report.
     *
     * @param list<array<int, string>> $data the answers as [label, answer, …]
     * @param array<string, array{0: string, 1: string}> $uploads field label => [temporary file, file name] of what the visitor uploaded
     */
    public static function save(App $app, array $content, array $data, array $uploads, string $language, int $formId = 0): int|string
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
        $input = [];
        $files = [];
        foreach ((array) ($content['mapping'] ?? []) as $row) {
            $key = (string) ($row['target'] ?? '');
            $type = (string) ($byKey[$key]['type'] ?? '');
            $template = trim((string) ($row['template'] ?? ''));
            if (in_array($type, self::UPLOADS, true)) {
                // only {Label} of an upload field: whatever else a visitor typed is not a file
                if (preg_match('/^\{([^{}]+)\}$/u', $template, $m) === 1) {
                    foreach ($uploads as $label => $upload) {
                        if (mb_strtolower(trim((string) $label)) === mb_strtolower(trim($m[1]))) {
                            $files[$key] = $upload;
                        }
                    }
                }
            } elseif (in_array($type, self::FILLABLE, true)) {
                $input[$key] = self::fill($template, $data);
            }
        }
        $values = Collections::sanitizeData($fields, $input);
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
        $db->insert('media', $saved + ['owner_id' => 0, 'staged_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);

        return $saved['image_path'];
    }
}
