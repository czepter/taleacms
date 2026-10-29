<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Admin\Modules\Roles;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/** Renaming, merging and deleting a tag changes public addresses: it needs the publishing permission, like categories. */
#[Group('site')]
final class TagsPublishCheckTest extends SiteTestCase
{
    private const string TAG = '7c1e9a52-3b4d-4f6e-9a8b-0c1d2e3f4a5b';
    private const string TARGET = '8d2f0b63-4c5e-4a7f-8b9c-1d2e3f4a5b6c';

    /** A signed-in user with the Writer preset's sections. */
    private function writer(string $name): Http
    {
        $site = $this->site();
        $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, ?, NOW(), NOW())', [$name, password_hash($site->password, PASSWORD_DEFAULT), $name, Roles::PRESETS['writer'][2]]);
        foreach (Roles::PRESETS['writer'][3] as $module) {
            $site->exec('INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, ? FROM tl_users WHERE username = ?', [$module, $name]);
        }
        $client = $site->client($name);
        $site->signIn($client, $name);

        return $client;
    }

    private function post(Http $as, string $action, array $fields): void
    {
        $as->post('/admin.php?module=tags&action=' . $action, ['_csrf' => $as->get('/admin.php?module=tags')->csrf()] + $fields);
    }

    public function testWriterCannotChangeTagsButAdministratorCan(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_tags (public_id, name, slug) VALUES (?, 'Keep me', 'keep-me')", [self::TAG]);
        $site->exec("INSERT INTO tl_tags (public_id, name, slug) VALUES (?, 'Target', 'target')", [self::TARGET]);
        $redirects = (int) $site->value('SELECT COUNT(*) FROM tl_redirects');

        $writer = $this->writer('tag-writer');
        $this->post($writer, 'save', ['tag_id' => self::TAG, 'name' => 'Renamed', 'description' => 'x', 'merge_into' => '']);
        $this->post($writer, 'save', ['tag_id' => self::TAG, 'name' => 'Keep me', 'merge_into' => self::TARGET]);
        $this->post($writer, 'delete', ['tag_id' => self::TAG]);
        $this->assertSame('Keep me', (string) $site->value("SELECT name FROM tl_tags WHERE slug = 'keep-me'"), 'the tag is unchanged and still there');
        $this->assertSame($redirects, (int) $site->value('SELECT COUNT(*) FROM tl_redirects'), 'no redirect was written');

        $this->post($site->admin(), 'save', ['tag_id' => self::TAG, 'name' => 'Renamed', 'description' => '', 'merge_into' => '']);
        $this->assertSame('Renamed', (string) $site->value("SELECT name FROM tl_tags WHERE slug = 'keep-me'"), 'an administrator may rename');
    }

    public function testWriterCanStillTagOwnNews(): void
    {
        $site = $this->site();
        $writer = $this->writer('tag-writer2');
        $writer->post('/admin.php?module=news&action=save', ['_csrf' => $writer->get('/admin.php?module=news&action=new')->csrf(), 'news_id' => '0', 'title' => 'Writer item', 'category_id' => (string) $site->value('SELECT public_id FROM tl_categories ORDER BY category_id LIMIT 1'), 'content' => '<p>x</p>', 'tags' => 'fresh-writer-tag']);
        $this->assertSame(1, (int) $site->value("SELECT COUNT(*) FROM tl_tags WHERE name = 'fresh-writer-tag'"), 'the tag is created while writing news');
    }
}
