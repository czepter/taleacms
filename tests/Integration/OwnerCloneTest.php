<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Migrator;
use Talea\Core\OwnerClone;
use Talea\Core\Owners;
use Talea\Core\Sites;
use Talea\Tests\Support\DatabaseTestCase;

/** Copying a site across owners (OwnerClone): what is never taken along, what is cleared inside the copy, what the two change logs say. */
#[CoversClass(OwnerClone::class)]
final class OwnerCloneTest extends DatabaseTestCase
{
    private static bool $made = false;

    protected function setUp(): void
    {
        if (!self::$made) {
            try {
                $config = $this->dbConfig();
            } catch (\LogicException) {
                $config = null;
            }
            if ($config !== null) {
                putenv('TALEA_MULTISITE=1');
                putenv('TALEA_OWNERS=1');
                $db = $this->db();
                Owners::add($db, 'Beta', 'organisation', 'boss@beta.example');
                $lines = Owners::provisionPending($db, $config);
                if ($lines !== ['owner tlb_: ready']) {
                    throw new \RuntimeException(implode("\n", $lines));
                }
                Sites::add($db->forOwner('tlb_'), 'Beta copy of Alpha', 'from-alpha', 1, true, 'tl_'); // tlb2_: a client gets a copy of owner 1's site
                Sites::add($db, 'Alpha copy of Beta', 'from-beta', 1, true, 'tlb_');                    // tl2_: owner 1 gets a copy of the client's site
                Sites::add($db, 'Alpha copy', 'same', 1, true);                                         // tl3_: inside one owner
                Migrator::migrate($config);
                self::$made = true;
                putenv('TALEA_OWNERS');
                putenv('TALEA_MULTISITE');
            }
        }
        parent::setUp();
        putenv('TALEA_MULTISITE=1');
        putenv('TALEA_OWNERS=1');
    }

    protected function tearDown(): void
    {
        putenv('TALEA_OWNERS');
        putenv('TALEA_MULTISITE');
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::$made = false;
        parent::tearDownAfterClass();
    }

    private function row(\Talea\Core\Db $owner, string $prefix): array
    {
        return (array) $owner->one('SELECT * FROM {sites} WHERE prefix = ?', [$prefix]);
    }

    private function setting(\Talea\Core\Db $db, string $name, string $value): void
    {
        $db->upsert('settings', ['name' => $name, 'value' => $value], ['name']);
    }

    public function testACopyAcrossOwnersNeverTakesTheSourcesAccountSettingsAlong(): void
    {
        $alpha = $this->db();
        $beta = $alpha->forOwner('tlb_');
        foreach (['smtp_host' => 'mail.alpha.test', 'smtp_password' => 'alpha-mail-pass', 'mail_from' => 'owner@alpha.test', 'ai_key' => 'alpha-ai-key', 'site_name' => 'Alpha', 'company_name' => 'Alpha Ltd'] as $k => $v) {
            $this->setting($alpha, $k, $v);
        }
        Sites::provision($beta, $this->row($beta, 'tlb2_'));

        $copy = $beta->forSite('tlb2_');
        $this->assertSame('Alpha', $copy->value("SELECT value FROM {settings} WHERE name = 'site_name'"), 'the site\'s own look and company data come along');
        $this->assertSame('Alpha Ltd', $copy->value("SELECT value FROM {settings} WHERE name = 'company_name'"));
        foreach (['smtp_host', 'smtp_password', 'mail_from', 'ai_key'] as $secret) {
            $this->assertNull($copy->value('SELECT value FROM {settings} WHERE name = ?', [$secret]), $secret . ' belongs to the source owner');
        }
    }

    public function testACopyInsideOneOwnerStillTakesTheAccountSettings(): void
    {
        $alpha = $this->db();
        $this->setting($alpha, 'smtp_password', 'alpha-mail-pass');
        $this->setting($alpha, 'ai_key', 'alpha-ai-key');
        Sites::provision($alpha, $this->row($alpha, 'tl3_'));

        $copy = $alpha->forSite('tl3_');
        $this->assertSame('alpha-mail-pass', $copy->value("SELECT value FROM {settings} WHERE name = 'smtp_password'"));
        $this->assertSame('alpha-ai-key', $copy->value("SELECT value FROM {settings} WHERE name = 'ai_key'"));
    }

    public function testUsersOfTheSourceOwnerAreClearedInsideTheCopyNotAfterIt(): void
    {
        // a user number that exists in owner 1 only: the foreign keys to the client's own users (PostgreSQL checks them at once) must not see it
        $alpha = $this->db();
        $beta = $alpha->forOwner('tlb_');
        $user = $alpha->insert('users', ['username' => 'author-a', 'password' => 'x', 'name' => 'Author', 'email' => 'a@a.example', 'admin' => 1, 'language' => '', 'confirmed_at' => date('Y-m-d H:i:s')]);
        $this->assertNull($beta->value('SELECT 1 FROM {users} WHERE user_id = ?', [$user]), 'the client has no such user');
        $category = $alpha->insert('categories', ['name' => 'Cat', 'slug' => 'cat', 'description' => '']);
        $news = $alpha->insert('news', ['title' => 'Story', 'slug' => 'story', 'text' => '', 'intro' => '', 'category_id' => $category, 'author_id' => $user, 'published_at' => date('Y-m-d H:i:s')]);
        $alpha->insert('news_revisions', ['news_id' => $news, 'user_id' => $user, 'title' => 'Story', 'text' => '', 'intro' => '', 'created_at' => date('Y-m-d H:i:s')]);

        Sites::provision($beta, $this->row($beta, 'tlb2_'));

        $copy = $beta->forSite('tlb2_');
        $this->assertSame(1, (int) $copy->value('SELECT COUNT(*) FROM {news} WHERE slug = ?', ['story']), 'the news item was copied');
        $this->assertNull($copy->value('SELECT author_id FROM {news} WHERE slug = ?', ['story']));
        $this->assertSame(1, (int) $copy->value('SELECT COUNT(*) FROM {news_revisions}'));
        $this->assertNull($copy->value('SELECT user_id FROM {news_revisions}'));
        $this->assertSame($user, (int) $alpha->value('SELECT author_id FROM {news} WHERE slug = ?', ['story']), 'the source is untouched');
    }

    public function testTheChangeLogOfAClientNeverNamesTheOtherClient(): void
    {
        $alpha = $this->db();
        $beta = $alpha->forOwner('tlb_');
        // owner 1 -> client: the client's log is neutral, owner 1's (the source) names the client
        Sites::provision($beta, $this->row($beta, 'tlb2_'));
        $in = (string) $beta->forSite('tlb2_')->value("SELECT description FROM {change_log} WHERE action = 'clone_in'");
        $this->assertSame("Created as a copy by the installation's operator", $in);
        $this->assertStringNotContainsString('Alpha', $in);
        $this->assertStringContainsString('Beta', (string) $alpha->value("SELECT description FROM {change_log} WHERE action = 'clone_out'"), 'owner 1\'s own log names the client');

        // client -> owner 1: the client's log (the source) is neutral, owner 1's new site names the client
        Sites::provision($alpha, $this->row($alpha, 'tl2_'));
        $this->assertStringContainsString('Beta', (string) $alpha->forSite('tl2_')->value("SELECT description FROM {change_log} WHERE action = 'clone_in'"));
        $this->assertSame("Copied by the installation's operator", (string) $beta->value("SELECT description FROM {change_log} WHERE action = 'clone_out'"));
    }
}
