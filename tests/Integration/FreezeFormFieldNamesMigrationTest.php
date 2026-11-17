<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use Talea\Tests\Support\DatabaseTestCase;

/** The migration that writes the position names (p0, p1 …) of the existing forms down once (docs/specs/form-builder.md, Field names). */
final class FreezeFormFieldNamesMigrationTest extends DatabaseTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once TALEA_SYSTEM . '/database/migrations/20261122100000_freeze_form_field_names.php';
    }

    /**
     * A build with one form (and a text element around it) whose fields are [key, name].
     *
     * @param list<array{0: string, 1?: string, 2?: string}> $fields key, name, type
     */
    private function build(array $fields, string $id = 'f1'): string
    {
        $form = ['id' => $id, 'type' => 'form', 'content' => ['name' => 'Enquiry', 'fields' => array_map(
            static fn (array $f): array => ['key' => $f[0], 'label' => 'Label ' . $f[0], 'type' => $f[2] ?? 'text'] + (($f[1] ?? '') !== '' ? ['name' => $f[1]] : []),
            $fields,
        )]];

        return (string) json_encode(['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'children' => [['id' => 'h1', 'type' => 'heading', 'content' => ['text' => 'Hello'], 'children' => []], $form]]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array{0: string, 1: string}> key => name of the fields of the form with this id found in a stored build */
    private function names(?string $json, string $id = 'f1'): array
    {
        $found = [];
        $walk = function (array $nodes) use (&$walk, &$found, $id): void {
            foreach ($nodes as $node) {
                if (($node['type'] ?? '') === 'form' && ($node['id'] ?? '') === $id) {
                    foreach ($node['content']['fields'] as $field) {
                        $found[] = [$field['key'], $field['name'] ?? ''];
                    }
                }
                $walk($node['children'] ?? []);
            }
        };
        $walk((array) (json_decode((string) $json, true)['children'] ?? []));

        return $found;
    }

    private function freeze(bool $owner = true): int
    {
        return \FreezeFormFieldNames::freeze($this->db()->pdo(), self::PREFIX, $owner);
    }

    public function testEveryPublishedFormGetsItsPositionNamesAndADraftTakesThemByKey(): void
    {
        $db = $this->db();
        $now = date('Y-m-d H:i:s');
        // a form record: the draft inserted a field at the top and moved another one – freezing it by its own positions would rename "email"
        $published = $this->build([['kname', '', 'text'], ['kmail', '', 'email'], ['kstep', '', 'step'], ['knote', '', 'textarea']]);
        $draft = $this->build([['knew1', '', 'text'], ['kname', '', 'text'], ['knote', '', 'textarea'], ['kmail', '', 'email']]);
        $form = $db->insert('forms', ['name' => 'Contact', 'build' => $published, 'build_draft' => $draft, 'created_at' => $now]);
        // an unadopted form in a page (published and draft), and one in a trashed page
        $page = $db->insert('pages', ['slug' => 'contact', 'title' => 'Contact', 'text' => '', 'visible' => 1, 'build' => $this->build([['ka', ''], ['kb', 'already']]), 'build_draft' => $this->build([['kb', 'already'], ['ka', ''], ['kc', '']])]);
        $trashed = $db->insert('pages', ['slug' => 'old', 'title' => 'Old', 'text' => '', 'visible' => 0, 'deleted_at' => $now, 'build' => $this->build([['kt1', ''], ['kt2', '']])]);
        $plain = (string) json_encode(['v' => 1, 'children' => [['id' => 'p1', 'type' => 'paragraph', 'content' => ['text' => 'No form here: "type":"x"'], 'children' => []]]]);
        $without = $db->insert('pages', ['slug' => 'about', 'title' => 'About', 'text' => '', 'visible' => 1, 'build' => $plain]);
        $odd = $db->insert('pages', ['slug' => 'odd', 'title' => 'Odd', 'text' => '', 'visible' => 1, 'build' => '{"type":"form" not json']);
        // the other places a build lives
        $db->insert('site_parts', ['type' => 'footer', 'language' => '', 'variant' => '', 'build' => $this->build([['kf1', ''], ['kf2', '']])]);
        $db->insert('site_parts', ['type' => 'footer', 'language' => 'de', 'variant' => '', 'build' => $this->build([['kg1', ''], ['kg2', '']])]);
        $popup = $db->insert('popups', ['name' => 'Pop', 'slug' => 'pop', 'rules' => '{}', 'build' => $this->build([['kp1', ''], ['kp2', '']])]);
        $collection = $db->insert('collections', ['name' => 'Jobs', 'slug' => 'jobs', 'fields' => '[]', 'build' => $this->build([['kc1', ''], ['kc2', '']])]);
        $db->insert('collection_templates', ['collection_id' => $collection, 'language' => '', 'build' => $this->build([['kt1', ''], ['kt2', '']])]);
        $db->insert('collection_templates', ['collection_id' => $collection, 'language' => 'de', 'build' => $this->build([['kt3', ''], ['kt4', '']])]);
        $component = $db->insert('components', ['name' => 'Box', 'properties' => '[]', 'build' => $this->build([['ko1', ''], ['ko2', '']])]);
        $revision = $db->insert('build_revisions', ['part' => 'footer', 'created_at' => $now, 'build' => $this->build([['kr1', ''], ['kr2', '']])]);
        $untouched = [
            ['pages', 'page_id', $without, 'build', $plain],
            ['pages', 'page_id', $odd, 'build', '{"type":"form" not json'],
        ];

        $changed = $this->freeze();
        $this->assertGreaterThanOrEqual(10, $changed);

        $row = fn (string $table, string $key, int $id): array => $db->one('SELECT build' . ($table === 'build_revisions' ? '' : ', build_draft') . ' FROM {' . $table . '} WHERE ' . $key . ' = ?', [$id]);
        $this->assertSame([['kname', 'p0'], ['kmail', 'p1'], ['kstep', 'p2'], ['knote', 'p3']], $this->names($row('forms', 'form_id', $form)['build']), 'every field, steps included, by its position');
        $this->assertSame([['knew1', ''], ['kname', 'p0'], ['knote', 'p3'], ['kmail', 'p1']], $this->names($row('forms', 'form_id', $form)['build_draft']), 'the draft takes the published name by key; a field only the draft has stays unnamed');
        $r = $row('pages', 'page_id', $page);
        $this->assertSame([['ka', 'p0'], ['kb', 'already']], $this->names($r['build']), 'a name that exists is kept');
        $this->assertSame([['kb', 'already'], ['ka', 'p0'], ['kc', '']], $this->names($r['build_draft']));
        $this->assertSame([['kt1', 'p0'], ['kt2', 'p1']], $this->names($row('pages', 'page_id', $trashed)['build']), 'a page in the trash can be restored, so it is frozen too');
        $this->assertSame([['kf1', 'p0'], ['kf2', 'p1']], $this->names($db->value("SELECT build FROM {site_parts} WHERE type = 'footer' AND language = ''")));
        $this->assertSame([['kg1', 'p0'], ['kg2', 'p1']], $this->names($db->value("SELECT build FROM {site_parts} WHERE type = 'footer' AND language = 'de'")), 'a part is addressed by its whole key');
        $this->assertSame([['kp1', 'p0'], ['kp2', 'p1']], $this->names($row('popups', 'popup_id', $popup)['build']));
        $this->assertSame([['kc1', 'p0'], ['kc2', 'p1']], $this->names($row('collections', 'collection_id', $collection)['build']));
        $this->assertSame([['kt1', 'p0'], ['kt2', 'p1']], $this->names($db->value("SELECT build FROM {collection_templates} WHERE collection_id = ? AND language = ''", [$collection])));
        $this->assertSame([['kt3', 'p0'], ['kt4', 'p1']], $this->names($db->value("SELECT build FROM {collection_templates} WHERE collection_id = ? AND language = 'de'", [$collection])));
        $this->assertSame([['ko1', 'p0'], ['ko2', 'p1']], $this->names($row('components', 'component_id', $component)['build']));
        $this->assertSame([['kr1', 'p0'], ['kr2', 'p1']], $this->names($row('build_revisions', 'revision_id', $revision)['build']), 'a revision keeps the names it rendered');
        foreach ($untouched as [$table, $key, $id, $column, $before]) {
            $this->assertSame($before, $row($table, $key, $id)[$column], 'a row without a form (or one that is not JSON) is byte-identical');
        }

        $this->assertSame(0, $this->freeze(), 'a second run changes nothing');
        $this->assertSame([['kname', 'p0'], ['kmail', 'p1'], ['kstep', 'p2'], ['knote', 'p3']], $this->names($row('forms', 'form_id', $form)['build']));
    }

    public function testADraftOfAFormRecordFindsItsPublishedFormWhateverTheElementId(): void
    {
        $db = $this->db();
        $form = $db->insert('forms', ['name' => 'Record', 'created_at' => date('Y-m-d H:i:s'), 'build' => $this->build([['kaaaa', ''], ['kbbbb', '']], 'old-id'), 'build_draft' => $this->build([['kbbbb', ''], ['kaaaa', '']], 'new-id')]);
        $this->freeze();
        $this->assertSame([['kbbbb', 'p1'], ['kaaaa', 'p0']], $this->names($db->value('SELECT build_draft FROM {forms} WHERE form_id = ?', [$form]), 'new-id'));
    }

    public function testADraftWithoutAPublishedCounterpartStaysUnnamed(): void
    {
        $db = $this->db();
        $page = $db->insert('pages', ['slug' => 'newform', 'title' => 'New', 'text' => '', 'visible' => 1, 'build' => $this->build([['kold1', '']], 'f1'), 'build_draft' => $this->build([['kold1', ''], ['knew1', '']], 'f2')]);
        $this->freeze();
        $this->assertSame([['kold1', ''], ['knew1', '']], $this->names($db->value('SELECT build_draft FROM {pages} WHERE page_id = ?', [$page]), 'f2'), 'a form only the draft has is new: it uses its keys');
    }

    public function testASecondSiteRunLeavesTheSharedComponentsAlone(): void
    {
        $component = $this->db()->insert('components', ['name' => 'Shared', 'properties' => '[]', 'build' => $this->build([['kz1', '']])]);
        $this->freeze(false);
        $this->assertSame([['kz1', '']], $this->names($this->db()->value('SELECT build FROM {components} WHERE component_id = ?', [$component])), 'components belong to the owner run');
        $this->freeze(true);
        $this->assertSame([['kz1', 'p0']], $this->names($this->db()->value('SELECT build FROM {components} WHERE component_id = ?', [$component])));
    }
}
