<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Admin\Modules\Media;
use Talea\Builder\Components;
use Talea\Core\LinkHealing;
use Talea\Core\MediaHygiene;
use Talea\Core\Migrator;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Multisite (docs/specs/multisite.md): what lives once per owner but is used per site – media files and components – must be judged
 * over every site: a file another site uses is in use, and link healing rewrites only the current site's own components.
 */
#[CoversClass(Media::class)]
#[CoversClass(MediaHygiene::class)]
#[CoversClass(LinkHealing::class)]
#[CoversClass(Sites::class)]
final class MultisiteOwnerRowsTest extends DatabaseTestCase
{
    private static bool $sites = false;
    private static string $third = '';

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
                $db = $this->db();
                Sites::add($db, 'Two', 'two'); // tl2_
                // tables of a prefix that is not registered (an older backup, a foreign install) are never taken over by a new site
                $db->pdo()->exec('CREATE TABLE tl3_stray (a INT)');
                self::$third = Sites::add($db, 'Three', 'three');
                $db->pdo()->exec('DROP TABLE tl3_stray');
                Migrator::migrate($config);
                $db->run('UPDATE {sites} SET ready_at = ? WHERE ready_at IS NULL', [date('Y-m-d H:i:s')]);
                self::$sites = true;
            }
        }
        parent::setUp();
        Sites::forgetCurrent();
    }

    public static function tearDownAfterClass(): void
    {
        self::$sites = false;
        Sites::forgetCurrent();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        putenv('TALEA_MULTISITE');
        parent::tearDown();
    }

    public function testANewSiteSkipsAPrefixWhoseTablesExist(): void
    {
        $this->assertSame('tl4_', self::$third, 'tl3_ has a table, so the third site is tl4_');
        $this->assertSame(['tl_', 'tl2_', 'tl4_'], Sites::prefixes($this->db()));
    }

    private function media(string $name): int
    {
        return $this->db()->insert('media', ['image_path' => 'media/2026/10/' . $name . '.jpg', 'thumb_path' => '', 'created_at' => date('Y-m-d H:i:s')]);
    }

    public function testAFileUsedOnlyOnAnotherSiteIsInUseHere(): void
    {
        $db = $this->db();
        $onTwo = $this->media('only-on-two');
        $nowhere = $this->media('nowhere');
        $db->forSite('tl2_')->insert('site_parts', ['type' => 'footer', 'build' => '{"v":1,"children":[{"type":"image","content":{"src":"media/2026/10/only-on-two.jpg"}}]}']);

        $this->assertArrayNotHasKey($onTwo, Media::findUsagesElsewhere($db), 'with the flag off the install is one site');
        putenv('TALEA_MULTISITE=1');
        $used = Media::findUsagesElsewhere($db);
        $this->assertArrayHasKey($onTwo, $used);
        $this->assertStringContainsString('(Two)', implode(' ', $used[$onTwo]), 'the place names its site');
        $this->assertArrayNotHasKey($nowhere, $used);
        $unused = array_column(MediaHygiene::report($db)['unused'], 'media_id');
        $this->assertContains($nowhere, array_map('intval', $unused));
        $this->assertNotContains($onTwo, array_map('intval', $unused), 'never listed as unused');
        $this->assertArrayHasKey($onTwo, Media::findUsagesElsewhere($db->forSite('tl2_')), 'and in use on the site itself');
    }

    public function testANewsItemOfAnotherSiteCountsToo(): void
    {
        $db = $this->db();
        $id = $this->media('in-news-two');
        $two = $db->forSite('tl2_');
        $category = $two->insert('categories', ['name' => 'Cat', 'slug' => 'cat', 'description' => '']);
        $news = $two->insert('news', ['title' => 'Story', 'slug' => 'story', 'text' => '', 'intro' => '', 'category_id' => $category, 'published_at' => date('Y-m-d H:i:s')]);
        $two->insert('media_usage', ['media_id' => $id, 'news_id' => $news]);

        putenv('TALEA_MULTISITE=1');
        $this->assertStringContainsString('Story (Two)', implode(' ', Media::findUsagesElsewhere($db)[$id] ?? []));
    }

    public function testAFurtherSiteThatIsNotSetUpYetIsLeftOut(): void
    {
        putenv('TALEA_MULTISITE=1');
        $this->db()->run('UPDATE {sites} SET ready_at = NULL WHERE prefix = ?', ['tl2_']);
        $this->assertSame(['Three'], array_keys(Sites::otherReady($this->db())));
    }

    public function testLinkHealingRewritesOnlyTheCurrentSitesOwnComponents(): void
    {
        $db = $this->db();
        $build = '{"v":1,"children":[{"id":"a","type":"link","tag":"a","content":{"text":"x","url":"/old"}}]}';
        $own = Components::create($db, ['name' => 'Own', 'properties' => '[]', 'build' => $build, 'updated_at' => date('Y-m-d H:i:s')]);
        $shared = Components::create($db, ['name' => 'Shared', 'properties' => '[]', 'build' => $build, 'updated_at' => date('Y-m-d H:i:s')], true);
        $other = Components::create($db->forSite('tl2_'), ['name' => 'Other', 'properties' => '[]', 'build' => $build, 'updated_at' => date('Y-m-d H:i:s')]);

        LinkHealing::heal($db, 'old', 'new');

        $url = fn (int $id): string => (string) json_decode((string) $db->value('SELECT build FROM {components} WHERE component_id = ?', [$id]), true)['children'][0]['content']['url'];
        $this->assertSame('/new', $url($own));
        $this->assertSame('/old', $url($shared), 'a shared component may link another site\'s addresses');
        $this->assertSame('/old', $url($other), 'another site\'s own component is not this site\'s to change');
    }
}
