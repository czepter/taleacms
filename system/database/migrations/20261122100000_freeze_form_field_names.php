<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Talea\Core\MigrationSupport;

/**
 * Field names that do not move (docs/specs/form-builder.md, Field names): until now a form field was posted as p<position>.
 * From here on it is posted under its technical name, else under its key (Form::fieldName). So that the forms that exist keep
 * the names they have, every field of every published form element gets its position name written down once: name = "p<i>".
 * Later inserting, reordering or deleting fields no longer renames another one.
 *
 * Published builds are frozen by their own positions (that is what they render now); a draft takes the name of the published
 * field with the same key, because it may already hold an unpublished insertion. Revisions were published, so they keep the
 * names they rendered. Sections, page templates, blueprints, looks and imports only make new forms, which use their keys.
 *
 * The migration carries its own walker and constants: it cannot rely on the application code staying as it is today.
 */
final class FreezeFormFieldNames extends AbstractMigration
{
    /** table => [primary key columns, has a draft column, owner table]. */
    private const array TABLES = [
        'forms' => [['form_id'], true, false],
        'pages' => [['page_id'], true, false],
        'site_parts' => [['type', 'language', 'variant'], true, false],
        'popups' => [['popup_id'], true, false],
        'collections' => [['collection_id'], true, false],
        'collection_templates' => [['collection_id', 'language'], true, false],
        'components' => [['component_id'], true, true],
        'build_revisions' => [['revision_id'], false, false],
    ];

    public function up(): void
    {
        self::freeze(
            $this->getAdapter()->getConnection(),
            (string) $this->getAdapter()->getOption('table_prefix'),
            !MigrationSupport::ownerTableInSiteRun($this->getAdapter(), 'components'),
        );
    }

    /** Keeping the names is harmless and cannot be told from names the owner chose, so there is nothing to undo. */
    public function down(): void
    {
    }

    /**
     * Writes the position names; safe to run again (a second run changes nothing).
     *
     * @param bool $ownerTables whether this run is the one that owns the shared tables (components)
     * @return int how many rows were changed
     */
    public static function freeze(\PDO $pdo, string $prefix, bool $ownerTables): int
    {
        $changed = 0;
        foreach (self::TABLES as $table => [$keys, $hasDraft, $owner]) {
            if ($owner && !$ownerTables) {
                continue;
            }
            $name = $prefix . $table;
            $like = '%"type":"form"%';
            $where = $hasDraft ? '(build LIKE ? OR build_draft LIKE ?)' : 'build LIKE ?';
            $ids = $pdo->prepare('SELECT ' . implode(', ', $keys) . ' FROM ' . $name . ' WHERE ' . $where);
            $ids->execute($hasDraft ? [$like, $like] : [$like]);
            $rows = $ids->fetchAll(\PDO::FETCH_ASSOC);
            $match = implode(' AND ', array_map(static fn (string $k): string => $k . ' = ?', $keys));
            $read = $pdo->prepare('SELECT build' . ($hasDraft ? ', build_draft' : '') . ' FROM ' . $name . ' WHERE ' . $match);
            foreach ($rows as $row) {
                $key = array_values($row);
                $read->execute($key);
                $stored = $read->fetch(\PDO::FETCH_ASSOC);
                $read->closeCursor();
                if ($stored === false) {
                    continue;
                }
                $published = self::decode($stored['build']);
                $draft = $hasDraft ? self::decode($stored['build_draft'] ?? null) : null;
                $set = [];
                if ($published !== null) {
                    $forms = [];
                    self::collect($published, $forms);
                    $any = false;
                    foreach ($forms as &$form) {
                        $any = self::freezeByPosition($form) || $any;
                    }
                    unset($form);
                    if ($any) {
                        $set['build'] = self::encode($published);
                    }
                }
                if ($draft !== null) {
                    $before = self::encode($draft);
                    $counterparts = [];
                    if ($published !== null) {
                        self::collect($published, $counterparts);
                    }
                    $drafts = [];
                    self::collect($draft, $drafts);
                    foreach ($drafts as &$form) {
                        $twin = self::counterpart($counterparts, $form, $table === 'forms');
                        if ($twin !== null) {
                            self::freezeByKey($form, $twin);
                        }
                    }
                    unset($form);
                    if (self::encode($draft) !== $before) {
                        $set['build_draft'] = self::encode($draft);
                    }
                }
                if ($set === []) {
                    continue;
                }
                $update = $pdo->prepare('UPDATE ' . $name . ' SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($set))) . ' WHERE ' . $match);
                $update->execute([...array_values($set), ...$key]);
                $changed++;
            }
        }

        return $changed;
    }

    /** @return array<string, mixed>|null the build, or null when there is none or it is not JSON */
    private static function decode(mixed $json): ?array
    {
        if (!is_string($json) || $json === '') {
            return null;
        }
        $build = json_decode($json, true);

        return is_array($build) ? $build : null;
    }

    /** The same encoding as Build::toJson. */
    private static function encode(array $build): string
    {
        return (string) json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * References to every form element of a build.
     *
     * @param array<int, array<string, mixed>> $out
     */
    private static function collect(array &$build, array &$out): void
    {
        if (isset($build['children']) && is_array($build['children'])) {
            self::collectIn($build['children'], $out);
        }
    }

    private static function collectIn(array &$nodes, array &$out): void
    {
        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'form') {
                $out[] = &$node;
            }
            if (isset($node['children']) && is_array($node['children'])) {
                self::collectIn($node['children'], $out);
            }
        }
    }

    /** A published form element: every field without a name gets p<position>, counted over the whole list as Form::fields() does. */
    private static function freezeByPosition(array &$form): bool
    {
        $changed = false;
        if (!isset($form['content']['fields']) || !is_array($form['content']['fields'])) {
            return false;
        }
        foreach ($form['content']['fields'] as $i => &$field) {
            if (is_array($field) && trim((string) ($field['name'] ?? '')) === '') {
                $field['name'] = 'p' . $i;
                $changed = true;
            }
        }

        return $changed;
    }

    /** The published form a draft form is the draft of: the same element id, or for a form record the only one. */
    private static function counterpart(array $published, array $draft, bool $record): ?array
    {
        foreach ($published as $form) {
            if ($record || (string) ($form['id'] ?? '') === (string) ($draft['id'] ?? '')) {
                return $form;
            }
        }

        return null;
    }

    /** A draft field takes the name of the published field with its key; a field only the draft has stays unnamed (it uses its key). */
    private static function freezeByKey(array &$draft, array $published): void
    {
        $names = [];
        foreach ((array) ($published['content']['fields'] ?? []) as $field) {
            if (is_array($field) && (string) ($field['key'] ?? '') !== '' && (string) ($field['name'] ?? '') !== '') {
                $names[(string) $field['key']] = (string) $field['name'];
            }
        }
        if (!isset($draft['content']['fields']) || !is_array($draft['content']['fields'])) {
            return;
        }
        foreach ($draft['content']['fields'] as &$field) {
            if (is_array($field) && trim((string) ($field['name'] ?? '')) === '' && isset($names[(string) ($field['key'] ?? '')])) {
                $field['name'] = $names[(string) $field['key']];
            }
        }
    }
}
