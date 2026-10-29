<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** ?preview=1 on a news item shows unpublished items only to people the News section lets see them (authors: their own). */
#[Group('site')]
final class NewsPreviewScopeTest extends SiteTestCase
{
    public function testUnpublishedNewsPreviewFollowsTheAdminPolicy(): void
    {
        $site = $this->site();
        $hash = password_hash($site->password, PASSWORD_DEFAULT);
        $users = [];
        foreach (['author-a' => [0, 'news'], 'author-b' => [0, 'news'], 'editor-e' => [1, 'news'], 'staff-s' => [0, 'enquiries']] as $name => [$role, $module]) {
            $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, ?, NOW(), NOW())', [$name, $hash, $name, $role]);
            $users[$name] = (int) $site->value('SELECT user_id FROM tl_users WHERE username = ?', [$name]);
            $site->exec('INSERT INTO tl_user_permissions (user_id, module) VALUES (?, ?)', [$users[$name], $module]);
        }
        $category = (int) $site->value('SELECT category_id FROM tl_categories LIMIT 1');
        foreach (['draft-b' => ['0', '2026-01-01 10:00:00'], 'scheduled-b' => ['1', '2099-01-01 10:00:00']] as $slug => [$visible, $at]) {
            $site->exec('INSERT INTO tl_news (slug, title, intro, text, category_id, published_at, visible, language, author_id) VALUES (?, ?, \'\', \'<p>Secret</p>\', ?, ?, ?, \'\', ?)', [$slug, "Title $slug", $category, $at, $visible, $users['author-b']]);
        }
        $get = function (string $user, string $path) use ($site): int {
            $client = $site->client($user);
            if ($user !== 'visitor') {
                $site->signIn($client, $user);
            }

            return $client->get($path)->status;
        };

        foreach (['draft-b', 'scheduled-b'] as $slug) {
            $path = "/news/$slug?preview=1";
            $this->assertSame(404, $get('visitor', $path), "$slug: anonymous");
            $this->assertSame(404, $get('author-a', $path), "$slug: another author");
            $this->assertSame(404, $get('staff-s', $path), "$slug: staff without the News section");
            $this->assertSame(200, $get('author-b', $path), "$slug: its author");
            $this->assertSame(200, $get('editor-e', $path), "$slug: an editor");
        }
    }
}
