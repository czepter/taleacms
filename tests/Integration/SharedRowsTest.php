<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Builder\Components;
use Talea\Core\App;
use Talea\Core\Facts;
use Talea\Core\Migrator;
use Talea\Core\Request;
use Talea\Core\Sites;
use Talea\Front\Cache;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Multisite phase 1, stage 3 (docs/specs/multisite.md): components and facts are owner tables with a site_id – a site's own row or one
 * shared with every site of the owner (NULL). What a site sees, the override of a shared fact, the cache that a shared change clears for
 * all sites, and what a clone takes along (its source's local components, with the component elements of the copied builds repointed).
 */
#[CoversClass(Components::class)]
#[CoversClass(Facts::class)]
#[CoversClass(Sites::class)]
#[CoversClass(Cache::class)]
final class SharedRowsTest extends DatabaseTestCase
{
    private static bool $sites = false;

    protected function setUp(): void
    {
        // the new sites' tables are DDL, which ends a transaction on MySQL: made once, before the test's transaction starts
        if (!self::$sites) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null; // no database: parent::setUp() skips the test
            }
            if ($config !== null) {
                Sites::add($this->db(), 'Two', 'two');                 // tl2_
                Sites::add($this->db(), 'Copy of one', 'copy', 1, true); // tl3_
                Sites::add($this->db(), 'Doomed', 'doomed');           // tl4_
                Migrator::migrate($config);
                self::$sites = true;
            }
        }
        parent::setUp();
        Sites::forgetCurrent();
        (new \ReflectionProperty(Facts::class, 'cache'))->setValue(null, []); // rows of an earlier test were rolled back
        (new \ReflectionProperty(Facts::class, 'memoSite'))->setValue(null, 'tl_');
    }

    public static function tearDownAfterClass(): void
    {
        self::$sites = false;
        Sites::forgetCurrent();
        parent::tearDownAfterClass();
    }

    /** An App on the test's own connection (its transaction is rolled back after the test) working on the site with this prefix. */
    private function app(string $prefix = 'tl_'): App
    {
        $app = new App(['db' => $this->dbConfig() + ($prefix !== 'tl_' ? ['site_prefix' => $prefix] : [])], Request::fromGlobals());
        (new \ReflectionProperty(App::class, 'db'))->setValue($app, $this->db()->forSite($prefix));

        return $app;
    }

    /** A component of the current site of $db, or a shared one. */
    private function component(\Talea\Core\Db $db, string $name, bool $shared = false, ?string $build = null): int
    {
        return Components::create($db, ['name' => $name, 'properties' => '[]', 'build' => $build, 'updated_at' => date('Y-m-d H:i:s')], $shared);
    }

    /** @return list<string> */
    private function names(\Talea\Core\Db $db): array
    {
        return array_column(Components::all($db), 'name');
    }

    public function testTheCurrentSiteIsNumberedByItsPrefix(): void
    {
        $this->assertSame([1, 2, 3], [Sites::currentId($this->db()), Sites::currentId($this->db()->forSite('tl2_')), Sites::currentId($this->db()->forSite('tl3_'))]);
        $this->assertSame('(site_id IS NULL OR site_id = 2)', Sites::visible($this->db()->forSite('tl2_')));
        $this->assertSame('(c.site_id IS NULL OR c.site_id = 1)', Sites::visible($this->db(), 'c'));
    }

    public function testASiteSeesItsOwnComponentsAndTheSharedOnesOnly(): void
    {
        $one = $this->db();
        $two = $one->forSite('tl2_');
        $mine = $this->component($one, 'Only on one');
        $theirs = $this->component($two, 'Only on two');
        $shared = $this->component($one, 'For everybody', true);

        $this->assertSame(['For everybody', 'Only on one'], $this->names($one));
        $this->assertSame(['For everybody', 'Only on two'], $this->names($two));
        $this->assertSame([1, 2, null], [Components::byId($one, $mine)['site_id'], Components::byId($two, $theirs)['site_id'], Components::byId($two, $shared)['site_id']]);
        $this->assertNotNull(Components::byId($two, $shared), 'a shared component is found from every site');
        $this->assertNull(Components::byId($two, $mine), 'another site\'s own component is refused by number');
        $this->assertNull(Components::byId($one, $theirs));
    }

    public function testOnlyVisibleComponentsHaveBuilds(): void
    {
        $build = '{"v":1,"children":[]}';
        $one = $this->db();
        $two = $one->forSite('tl2_');
        $mine = $this->component($one, 'A', false, $build);
        $theirs = $this->component($two, 'B', false, $build);
        $shared = $this->component($one, 'C', true, $build);

        $this->assertEqualsCanonicalizing([$mine, $shared], array_keys(Components::builds($one)));
        $this->assertEqualsCanonicalizing([$theirs, $shared], array_keys(Components::builds($two)));
    }

    public function testSharingIsOneWay(): void
    {
        $id = $this->component($this->db(), 'Local');
        $this->assertFalse(Components::isShared((array) Components::byId($this->db(), $id)));
        Components::share($this->db(), $id);
        $this->assertTrue(Components::isShared((array) Components::byId($this->db(), $id)));
        $this->assertContains('Local', $this->names($this->db()->forSite('tl2_')), 'now the other site sees it');
    }

    public function testAFactOfTheSiteWinsOverTheSharedOne(): void
    {
        $one = $this->app();
        $two = $this->app('tl2_');
        $this->assertNull(Facts::save($one, 'founded', ['label' => 'Founded', 'type' => 'year', 'value' => '2004'], '', true));
        $this->assertSame(['2004', '2004', true], [Facts::all($one)['founded']['value'], Facts::all($two)['founded']['value'], Facts::all($two)['founded']['shared']]);

        // site 2 saves its own value for the same key: a row of its own, the shared one stays for the others
        $this->assertNull(Facts::save($two, 'founded', ['value' => '2010'], '', false));
        $this->assertSame('2004', Facts::all($one)['founded']['value']);
        $this->assertSame(['2010', false, 'Founded'], [Facts::all($two)['founded']['value'], Facts::all($two)['founded']['shared'], Facts::all($two)['founded']['label']]);
        $this->assertSame(2, (int) $this->db()->value('SELECT COUNT(*) FROM {facts} WHERE fact_key = ?', ['founded']));

        // deleting on site 2 removes only its own row; the shared value shows again
        $this->assertTrue(Facts::delete($two, 'founded'));
        $this->assertSame(['2004', true], [Facts::all($two)['founded']['value'], Facts::all($two)['founded']['shared']]);
        $this->assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {facts} WHERE fact_key = ?', ['founded']));

        // with nothing of its own, a delete takes the shared row away for everybody
        $this->assertTrue(Facts::delete($two, 'founded'));
        $this->assertArrayNotHasKey('founded', Facts::all($one));
    }

    public function testAKeyHasOneSharedRowPerLanguage(): void
    {
        $one = $this->app();
        $two = $this->app('tl2_');
        $this->assertNull(Facts::save($one, 'phone_hours', ['label' => 'Hours', 'value' => 'mornings'], '', true));
        $this->assertNull(Facts::save($two, 'phone_hours', ['value' => 'evenings'], '', false)); // site 2's own copy
        $this->assertSame('A shared fact with this key already exists.', Facts::save($two, 'phone_hours', ['value' => 'evenings'], '', true), 'the unique key lets two NULL rows in; Facts::save does not');
        $this->assertSame(1, (int) $this->db()->value('SELECT COUNT(*) FROM {facts} WHERE fact_key = ? AND site_id IS NULL', ['phone_hours']));

        // a new fact without a choice is the site's own, and the same key can live on another site
        $this->assertNull(Facts::save($one, 'local_one', ['label' => 'Local', 'value' => 'a']));
        $this->assertNull(Facts::save($two, 'local_one', ['label' => 'Local', 'value' => 'b']));
        $this->assertSame(['a', 'b'], [Facts::all($one)['local_one']['value'], Facts::all($two)['local_one']['value']]);
        $this->assertSame([false, false], [Facts::all($one)['local_one']['shared'], Facts::all($two)['local_one']['shared']]);

        // a translation of a shared fact is shared with it
        $this->assertNull(Facts::save($one, 'phone_hours', ['value' => 'morgens'], 'de'));
        $this->assertSame(1, (int) $this->db()->value("SELECT COUNT(*) FROM {facts} WHERE fact_key = 'phone_hours' AND language = 'de' AND site_id IS NULL"));
    }

    public function testFactsAreNotRememberedAcrossSites(): void
    {
        $one = $this->app();
        $two = $this->app('tl2_');
        $this->assertNull(Facts::save($one, 'only_here', ['label' => 'Here', 'value' => 'yes']));
        $this->assertArrayHasKey('only_here', Facts::all($one));
        $this->assertArrayNotHasKey('only_here', Facts::all($two), 'the static cache is keyed by the site prefix');
        $this->assertArrayHasKey('only_here', Facts::all($one));
    }

    public function testASharedChangeClearsThePageCacheOfEverySite(): void
    {
        $root = TALEA_ROOT . '/storage/cache';
        $folders = [$root . '/pages', $root . '/pages-tl2_', $root . '/pages-tl3_'];
        $before = [];
        foreach ($folders as $folder) {
            @mkdir($folder, 0775, true);
            file_put_contents($folder . '/page.html', 'cached');
            $before[$folder] = (string) @file_get_contents($folder . '/generation');
        }
        try {
            Cache::clearAll();
            foreach ($folders as $folder) {
                $this->assertFileDoesNotExist($folder . '/page.html', $folder);
                $this->assertNotSame($before[$folder], (string) file_get_contents($folder . '/generation'), 'a new generation');
            }

            // a local fact clears only this site's folder (the first, because no site is routed here); a shared one all of them
            foreach ($folders as $folder) {
                file_put_contents($folder . '/page.html', 'cached');
            }
            $app = $this->app();
            $this->assertNull(Facts::save($app, 'local_fact', ['label' => 'L', 'value' => 'x']));
            $this->assertFileDoesNotExist($folders[0] . '/page.html');
            $this->assertFileExists($folders[1] . '/page.html');
            $this->assertNull(Facts::save($app, 'shared_fact', ['label' => 'S', 'value' => 'x'], '', true));
            $this->assertFileDoesNotExist($folders[1] . '/page.html');
            $this->assertFileDoesNotExist($folders[2] . '/page.html');
        } finally {
            foreach ($folders as $folder) {
                @unlink($folder . '/page.html');
            }
        }
    }

    public function testACloneTakesTheLocalComponentsAndPointsItsBuildsAtTheCopies(): void
    {
        $db = $this->db();
        $sharedId = $this->component($db, 'Shared card', true, '{"v":1,"children":[]}');
        $a = $this->component($db, 'Card A');
        $b = $this->component($db, 'Card B');
        $element = fn (int $id): array => ['id' => 'x' . $id, 'type' => 'component', 'tag' => 'div', 'content' => ['component' => (string) $id, 'values' => []], 'children' => []];
        $build = fn (int ...$ids): string => (string) json_encode(['v' => 1, 'children' => array_map($element, $ids)]);
        // card B contains card A; the page uses A, B and the shared card; the draft and the history carry the same references
        $db->update('components', ['build' => $build($a)], ['component_id' => $b]);
        $pageId = $db->insert('pages', ['slug' => 'cards', 'title' => 'Cards', 'text' => '', 'build' => $build($a, $b, $sharedId), 'build_draft' => $build($b)]);
        $db->insert('build_revisions', ['page_id' => $pageId, 'build' => $build($a), 'created_at' => date('Y-m-d H:i:s')]);
        $db->insert('experiments', ['name' => 'Test', 'kind' => 'element', 'page_id' => $pageId, 'element_id' => 'x1', 'variant_component_id' => $a, 'goal_type' => 'click', 'previous_build' => $build($b), 'created_at' => date('Y-m-d H:i:s')]);
        $db->insert('facts', ['fact_key' => 'own_fact', 'language' => '', 'label' => 'Own', 'type' => 'text', 'value' => 'v', 'updated_at' => date('Y-m-d H:i:s'), 'site_id' => 1]);
        $this->component($db->forSite('tl2_'), 'Belongs to site two');

        Sites::provision($db, (array) $db->one('SELECT * FROM {sites} WHERE prefix = ?', ['tl3_']));
        Sites::forgetCurrent();

        $copy = $db->forSite('tl3_');
        $this->assertSame(['Card A', 'Card B', 'Shared card'], $this->names($copy), 'the copy sees its own copies and the shared card – not site two\'s');
        $own = array_column($db->all('SELECT component_id, name FROM {components} WHERE site_id = 3'), 'component_id', 'name');
        $this->assertCount(2, $own);
        $this->assertNotContains($a, $own);
        $this->assertEqualsCanonicalizing(['Card A', 'Card B'], array_keys($own));
        $this->assertSame(2, (int) $db->value('SELECT COUNT(*) FROM {components} WHERE name = ?', ['Card A']), 'the source and one copy');
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {components} WHERE name = ?', ['Shared card']), 'a shared component is not copied');

        $ids = fn (?string $json): array => array_map(fn (array $n): int => (int) $n['content']['component'], (array) (json_decode((string) $json, true)['children'] ?? []));
        $page = (array) $copy->one('SELECT build, build_draft FROM {pages} WHERE slug = ?', ['cards']);
        $this->assertSame([$own['Card A'], $own['Card B'], $sharedId], $ids($page['build']), 'the shared card keeps its number, the local ones are the copies');
        $this->assertSame([$own['Card B']], $ids($page['build_draft']), 'drafts too');
        $this->assertSame([$own['Card A']], $ids((string) $copy->value('SELECT build FROM {build_revisions} WHERE page_id = ?', [$pageId])), 'and the history');
        $this->assertSame([$own['Card A']], $ids((string) $db->value('SELECT build FROM {components} WHERE component_id = ?', [$own['Card B']])), 'the copies refer to each other');

        $experiment = (array) $copy->one('SELECT variant_component_id, previous_build FROM {experiments}');
        $this->assertSame([$own['Card A'], [$own['Card B']]], [(int) $experiment['variant_component_id'], $ids($experiment['previous_build'])], 'the variant B of a test is the copy, not the source\'s component');
        $this->assertSame($a, (int) $db->value('SELECT variant_component_id FROM {experiments}'), 'the source\'s test keeps its own');

        // the source is untouched
        $this->assertSame([$a, $b, $sharedId], $ids((string) $db->value('SELECT build FROM {pages} WHERE slug = ?', ['cards'])));
        $this->assertSame([$a], $ids((string) $db->value('SELECT build FROM {components} WHERE component_id = ?', [$b])));
        $this->assertSame('v', $db->value("SELECT value FROM {facts} WHERE fact_key = 'own_fact' AND site_id = 3"), 'local facts are copied');

        Sites::provision($db, (array) $db->one('SELECT * FROM {sites} WHERE prefix = ?', ['tl3_'])); // run again: the same, not doubled
        $this->assertSame(2, (int) $db->value('SELECT COUNT(*) FROM {components} WHERE site_id = 3'));
        $this->assertSame(1, (int) $db->value('SELECT COUNT(*) FROM {facts} WHERE site_id = 3'));
    }

    public function testTheCleanupTakesAwayARemovedSitesOwnRows(): void
    {
        $this->db()->pdo()->rollBack(); // DROP is DDL: it ends a transaction on MySQL
        $db = $this->db();
        $id = (int) $db->value('SELECT site_id FROM {sites} WHERE prefix = ?', ['tl4_']);
        $mine = $this->component($db->forSite('tl4_'), 'Goes with the site');
        $kept = $this->component($db->forSite('tl4_'), 'Shared before', true);
        $db->insert('facts', ['fact_key' => 'doomed_fact', 'language' => '', 'label' => 'D', 'type' => 'text', 'value' => 'v', 'updated_at' => date('Y-m-d H:i:s'), 'site_id' => $id]);
        Sites::trash($db, $id);
        $db->update('sites', ['trashed_at' => date('Y-m-d H:i:s', time() - 31 * 86400)], ['site_id' => $id]);

        $this->assertSame($id, Sites::currentId($db->forSite('tl4_'))); // memoised
        $this->assertSame('dropped 1', Sites::cleanup($db));

        $this->assertNull($db->one('SELECT 1 FROM {components} WHERE component_id = ?', [$mine]));
        $this->assertNotNull($db->one('SELECT 1 FROM {components} WHERE component_id = ?', [$kept]), 'a shared component stays');
        $this->assertSame(0, (int) $db->value("SELECT COUNT(*) FROM {facts} WHERE fact_key = 'doomed_fact'"));
        $db->delete('components', ['component_id' => $kept]);

        // the removed site's prefix is handed out again with a new number: the memo must not keep the old one
        Sites::add($db, 'Again', 'again');
        $new = (int) $db->value('SELECT site_id FROM {sites} WHERE prefix = ?', ['tl4_']);
        $this->assertNotSame($id, $new);
        $this->assertSame($new, Sites::currentId($db->forSite('tl4_')));
        $db->delete('sites', ['site_id' => $new]);
    }

    public function testAnUnregisteredFurtherPrefixHasNoSiteNumber(): void
    {
        $this->expectException(\RuntimeException::class);
        Sites::currentId($this->db()->forSite('tl9_')); // never site 1
    }
}
