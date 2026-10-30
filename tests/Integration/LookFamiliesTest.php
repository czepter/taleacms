<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Builder\DesignSystem;
use Talea\Core\App;
use Talea\Core\Db;
use Talea\Core\Look;
use Talea\Core\LookFamily;
use Talea\Core\Migrator;
use Talea\Core\Request;
use Talea\Core\Settings;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/**
 * Look families (docs/specs/multisite.md): the parent Two hands its published look down to the children Three and Four.
 */
#[CoversClass(LookFamily::class)]
#[CoversClass(Look::class)]
final class LookFamiliesTest extends DatabaseTestCase
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
                Sites::add($this->db(), 'Two', 'two');     // tl2_, the parent
                Sites::add($this->db(), 'Three', 'three'); // tl3_
                Sites::add($this->db(), 'Four', 'four');   // tl4_
                Migrator::migrate($config);
                $this->db()->run('UPDATE {sites} SET ready_at = ? WHERE ready_at IS NULL', [date('Y-m-d H:i:s')]);
                self::$sites = true;
            }
        }
        parent::setUp();
        Sites::forgetCurrent();
        putenv('TALEA_MULTISITE=1');
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

    private function site(string $prefix): Db
    {
        return $this->db()->forSite($prefix);
    }

    /** @return array<string, mixed> */
    private function row(string $prefix): array
    {
        return (array) $this->db()->one('SELECT * FROM {sites} WHERE prefix = ?', [$prefix]);
    }

    private function app(string $prefix): App
    {
        $app = new App(['db' => $this->dbConfig() + ['site_prefix' => $prefix]], Request::fromGlobals());
        (new \ReflectionProperty(App::class, 'db'))->setValue($app, $this->site($prefix));

        return $app;
    }

    /** Joins the given children to Two (their drafts are thrown away); what Two has published is copied at once. */
    private function join(string ...$children): void
    {
        [$parent] = LookFamily::checkParent($this->db(), (string) $this->row('tl2_')['public_id'], (int) $this->row('tl3_')['site_id']);
        foreach ($children as $prefix) {
            $this->assertNull(LookFamily::set($this->db(), $this->row($prefix), $parent, true, null));
        }
    }

    private function publishDesign(string $primary): void
    {
        $app = $this->app('tl2_');
        $ds = DesignSystem::load($app->settings());
        $ds['colors']['primary'] = $primary;
        Look::setDesignSystem($app->settings(), $ds);
        Look::publish($app);
    }

    public function testPublishingCopiesTheDesignSystemAndTheDarkModeIntoEveryChild(): void
    {
        $this->join('tl3_', 'tl4_');
        $this->app('tl2_')->settings()->set('dark_mode', 'auto');
        $this->publishDesign('#112233');

        foreach (['tl3_', 'tl4_'] as $prefix) {
            $settings = new Settings($this->site($prefix));
            $this->assertSame('#112233', DesignSystem::load($settings)['colors']['primary'], $prefix);
            $this->assertSame('auto', $settings->get('dark_mode'), $prefix);
        }
        $this->assertSame('#112233', DesignSystem::load($this->app('tl2_')->settings())['colors']['primary']);
    }

    public function testTheChildsEarlierLookIsKeptAsAVersionOfItsOwn(): void
    {
        $this->join('tl3_');
        $three = $this->site('tl3_');
        (new Settings($three))->set('design_system', (string) json_encode(['colors' => ['primary' => '#abcdef']]));
        $before = (int) $three->value('SELECT COUNT(*) FROM {look_versions}');
        $this->publishDesign('#112233');

        $this->assertSame($before + 1, (int) $three->value('SELECT COUNT(*) FROM {look_versions}'));
        $kept = json_decode((string) $three->value('SELECT data FROM {look_versions} ORDER BY id DESC LIMIT 1'), true);
        $this->assertSame('#abcdef', $kept['design_system']['colors']['primary'], 'the look before the push, so this child can be rolled back alone');
        $this->assertSame(0, (int) $this->site('tl4_')->value('SELECT COUNT(*) FROM {look_versions}'), 'a site outside the family is untouched');
    }

    public function testClassesAreMergedByName(): void
    {
        $parent = $this->site('tl2_');
        $three = $this->site('tl3_');
        $parent->run("INSERT INTO {classes} (name, style, css, updated_at) VALUES ('card', '{}', '.card{color:red}', NOW()), ('lead', '{}', '', NOW())");
        $three->run("INSERT INTO {classes} (name, style, css, updated_at) VALUES ('card', '{}', '.card{color:blue}', NOW()), ('local-only', '{}', '', NOW())");
        $this->join('tl3_');

        $this->assertSame('.card{color:red}', $three->value("SELECT css FROM {classes} WHERE name = 'card'"), 'the parent overwrites a class of the same name');
        $this->assertNotNull($three->value("SELECT 1 FROM {classes} WHERE name = 'lead'"));
        $this->assertNotNull($three->value("SELECT 1 FROM {classes} WHERE name = 'local-only'"), 'the child keeps its own extra classes');
        $this->assertNull(LookFamily::classRefusal($three, 'local-only'));
        $this->assertNotNull(LookFamily::classRefusal($three, 'card'), 'a class the parent has is read-only in the child');
    }

    public function testHeaderAndFooterAreCopiedPerLanguageAndAChildLanguageTheParentLacksStays(): void
    {
        $parent = $this->site('tl2_');
        $three = $this->site('tl3_');
        $parts = "INSERT INTO {site_parts} (type, language, variant, name, build, build_draft) VALUES (?, ?, ?, '', ?, ?)";
        $parent->run($parts, ['header', '', '', 'P-header', null]);
        $parent->run($parts, ['footer', '', '', 'P-footer', null]);
        $parent->run($parts, ['footer', 'de', '', 'P-footer-de', null]);
        $parent->run($parts, ['footer', '', 'short', 'P-footer-short', null]);
        $parent->run($parts, ['header', 'de', '', null, 'only a draft']);
        $three->run($parts, ['header', '', '', 'old-header', 'old draft']);
        $three->run($parts, ['footer', 'fr', '', 'C-footer-fr', null]);
        $three->run($parts, ['news_item', '', '', 'C-wrapper', null]);
        $this->join('tl3_');

        $build = fn (string $type, string $language, string $variant = ''): mixed => $three->value('SELECT build FROM {site_parts} WHERE type = ? AND language = ? AND variant = ?', [$type, $language, $variant]);
        $this->assertSame('P-header', $build('header', ''));
        $this->assertNull($three->value("SELECT build_draft FROM {site_parts} WHERE type = 'header' AND language = ''"), 'the child\'s draft of a shared part is gone');
        $this->assertSame('P-footer', $build('footer', ''));
        $this->assertSame('P-footer-de', $build('footer', 'de'));
        $this->assertSame('P-footer-short', $build('footer', '', 'short'), 'variants come along');
        $this->assertSame('C-footer-fr', $build('footer', 'fr'), 'a language the parent lacks keeps the child\'s own part');
        $this->assertSame('C-wrapper', $build('news_item', ''), 'wrappers stay local');
        $this->assertNull($build('header', 'de'), 'an unpublished draft of the parent is not copied');
    }

    public function testAFamilyIsOneLevelDeepAndOfOneOwner(): void
    {
        $this->join('tl3_');
        $two = (string) $this->row('tl2_')['public_id'];
        $four = $this->row('tl4_');

        $this->assertSame('A site that takes its look from another site cannot be a parent.', LookFamily::checkParent($this->db(), (string) $this->row('tl3_')['public_id'], (int) $four['site_id'])[1], 'a child is no parent');
        $this->assertSame('This site is the parent of a look family: its children have to leave first.', LookFamily::checkParent($this->db(), (string) $four['public_id'], (int) $this->row('tl2_')['site_id'])[1], 'a parent is no child');
        $this->assertSame('Choose a site that is set up as the parent.', LookFamily::checkParent($this->db(), $two, (int) $this->row('tl2_')['site_id'])[1], 'not its own parent');
        $this->assertSame('Choose a site that is set up as the parent.', LookFamily::checkParent($this->db(), 'f47ac10b-58cc-4372-a567-0e02b2c3d479', (int) $four['site_id'])[1], 'a site of another owner is not in this registry');
        $this->assertNull(LookFamily::checkParent($this->db(), $two, (int) $four['site_id'])[1]);
    }

    public function testJoiningWithAPendingLookDraftNeedsDiscardDraft(): void
    {
        $three = $this->site('tl3_');
        Look::setDesignSystem(new Settings($three), DesignSystem::DEFAULTS);
        $this->assertTrue(Look::hasDraft(new Settings($three)));
        [$parent] = LookFamily::checkParent($this->db(), (string) $this->row('tl2_')['public_id'], (int) $this->row('tl3_')['site_id']);

        $this->assertNotNull(LookFamily::set($this->db(), $this->row('tl3_'), $parent, false, null));
        $this->assertNull($this->row('tl3_')['look_parent_id'], 'refused: nothing changed');
        $this->assertTrue(Look::hasDraft(new Settings($three)));

        $this->assertNull(LookFamily::set($this->db(), $this->row('tl3_'), $parent, true, null));
        $this->assertSame((int) $this->row('tl2_')['site_id'], (int) $this->row('tl3_')['look_parent_id']);
        $this->assertFalse(Look::hasDraft(new Settings($three)), 'the draft was thrown away');
    }

    public function testLeavingKeepsTheCurrentCopyAndUnlocks(): void
    {
        $this->join('tl3_');
        $this->publishDesign('#112233');
        $three = $this->site('tl3_');
        $this->assertNotNull(LookFamily::refusal($three));
        $this->assertStringContainsString('Two', (string) LookFamily::refusal($three, false));

        $this->assertNull(LookFamily::set($this->db(), $this->row('tl3_'), null, false, null));
        $this->assertNull($this->row('tl3_')['look_parent_id']);
        $this->assertNull(LookFamily::refusal($three));
        $this->assertSame('#112233', DesignSystem::load(new Settings($three))['colors']['primary']);
        $this->publishDesign('#445566');
        $this->assertSame('#112233', DesignSystem::load(new Settings($three))['colors']['primary'], 'no longer pushed');
    }

    public function testAChildsRollbackMayBePublishedButNotALookOfItsOwn(): void
    {
        $this->join('tl3_');
        $three = $this->site('tl3_');
        $settings = new Settings($three);
        Look::setDesignSystem($settings, DesignSystem::DEFAULTS);
        $this->assertNotNull(LookFamily::draftRefusal($three, $settings));
        Look::discard($settings);
        $this->publishDesign('#112233'); // a version of the child's earlier look exists now
        $app = $this->app('tl3_');
        Look::restoreVersion($app, (int) $three->value('SELECT MAX(id) FROM {look_versions}'));
        $this->assertNull(LookFamily::draftRefusal($three, $app->settings()), 'a rollback goes through');
    }

    public function testATrashedOrArchivedParentLetsItsChildrenGo(): void
    {
        $this->join('tl3_', 'tl4_');
        $two = (int) $this->row('tl2_')['site_id'];

        $this->assertNull(Sites::save($this->db(), $two, 'Two', 'paused', null));
        $this->assertSame($two, (int) $this->row('tl3_')['look_parent_id'], 'pausing changes nothing');

        $this->assertNull(Sites::save($this->db(), $two, 'Two', 'archived', null));
        $this->assertNull($this->row('tl3_')['look_parent_id']);
        $this->assertNull($this->row('tl4_')['look_parent_id']);
        $this->assertSame(1, (int) $this->site('tl3_')->value("SELECT COUNT(*) FROM {change_log} WHERE action = 'leave look family'"), 'one row per child');

        $this->join('tl3_');
        Sites::trash($this->db(), $two);
        $this->assertNull($this->row('tl3_')['look_parent_id']);
        $this->assertSame(2, (int) $this->site('tl3_')->value("SELECT COUNT(*) FROM {change_log} WHERE action = 'leave look family'"));
    }

    public function testOutsideMultisiteNothingIsLocked(): void
    {
        $this->join('tl3_');
        putenv('TALEA_MULTISITE');
        $this->assertNull(LookFamily::refusal($this->site('tl3_')));
        $this->assertSame(0, LookFamily::childCount($this->site('tl2_')));
    }
}
