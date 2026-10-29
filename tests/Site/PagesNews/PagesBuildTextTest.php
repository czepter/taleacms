<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Http;
use Talea\Tests\Site\Support\SiteTestCase;

/** "Back to text" drops the layout of a page, so it needs the same publish permission as the other page changes. */
#[Group('site')]
final class PagesBuildTextTest extends SiteTestCase
{
    private const BUILD = '{"v":1,"children":[]}';

    private function user(string $name, int $level): Http
    {
        $site = $this->site();
        $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, ?, NOW(), NOW())', [$name, password_hash($site->password, PASSWORD_DEFAULT), $name, $level]);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'pages' FROM tl_users WHERE username = ?", [$name]);
        $client = $site->client($name);
        $site->signIn($client, $name);

        return $client;
    }

    private function page(string $slug, bool $visible): string
    {
        $site = $this->site();
        $site->mcp('create_page', ['title' => ucfirst($slug), 'slug' => $slug, 'visible' => $visible, 'in_menu' => false, 'content' => '<p>Text</p>']);
        $site->exec('UPDATE tl_pages SET build = ?, build_draft = ?, visible = ? WHERE slug = ?', [self::BUILD, self::BUILD, $visible, $slug]);

        return $site->publicId('pages', (int) $site->value('SELECT page_id FROM tl_pages WHERE slug = ?', [$slug]));
    }

    private function backToText(Http $client, string $id): void
    {
        $client->post('/admin.php?module=pages&action=build_text', ['_csrf' => $this->site()->csrf($client), 'page_id' => $id]);
    }

    public function testAnAuthorCannotStripAVisiblePage(): void
    {
        $id = $this->page('live', true);
        $this->backToText($this->user('text-author', 0), $id);
        $this->assertSame(self::BUILD, (string) $this->site()->value("SELECT build FROM tl_pages WHERE slug = 'live'"));
    }

    public function testAnAuthorCanDoItOnAHiddenPageAndAnEditorOnAnyPage(): void
    {
        $hidden = $this->page('hidden', false);
        $live = $this->page('live', true);
        $this->backToText($this->user('text-author-2', 0), $hidden);
        $this->assertNull($this->site()->value("SELECT build FROM tl_pages WHERE slug = 'hidden'"));
        $this->backToText($this->site()->admin(), $live);
        $this->assertNull($this->site()->value("SELECT build FROM tl_pages WHERE slug = 'live'"));
    }
}
