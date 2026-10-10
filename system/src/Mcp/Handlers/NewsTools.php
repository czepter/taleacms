<?php

declare(strict_types=1);

namespace Talea\Mcp\Handlers;

use Talea\Admin\Modules\Media;
use Talea\Admin\Modules\Categories;
use Talea\Admin\Modules\Pages;
use Talea\Core\App;
use Talea\Core\Language;
use Talea\Core\SocialDrafts;
use Talea\Front\SiteIdentity;
use Talea\Builder\SiteParts;
use Talea\Builder\DesignSystem;
use Talea\Builder\Library;
use Talea\Builder\Collections;
use Talea\Builder\Publisher;
use Talea\Builder\Build;
use Talea\Builder\HtmlConverter;

/**
 * MCP tools: news (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait NewsTools
{
    /** list_news */
    private function toolListNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        $where = ['c.deleted_at IS NULL']; // the trash is neither listed nor edited via MCP
        $p = [];
        if (($authors = $auth->managedAuthors()) !== null) {
            $where[] = 'c.author_id IN (' . implode(',', $authors) . ')';
        }
        if (!$auth->hasModule('news')) {
            $where[] = 'c.visible = TRUE AND c.published_at <= NOW()'; // 3.3.2 (N12): without the News section only what visitors see, as list_pages
        }
        $statuses = ['published' => 'c.visible = TRUE AND c.published_at <= NOW()', 'scheduled' => 'c.visible = TRUE AND c.published_at > NOW()', 'drafts' => 'c.visible = FALSE'];
        if (isset($statuses[$a['status'] ?? ''])) {
            $where[] = $statuses[$a['status']];
        }
        if (!empty($a['category'])) {
            $where[] = 'c.category_id = ?';
            $p[] = $this->category((string) $a['category']);
        }
        if (!empty($a['search'])) {
            $where[] = $db->dialect()->likeInsensitive('c.title');
            $p[] = '%' . addcslashes((string) $a['search'], '%_\\') . '%';
        }

        return $db->all(
            'SELECT c.news_id AS id, c.title, c.slug, t.name AS category, c.published_at AS date, c.visible AS published
             FROM {news} c JOIN {categories} t ON t.category_id = c.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.published_at DESC LIMIT ?',
            [...$p, max(1, min(50, (int) ($a['limit'] ?? 20)))],
        );
    }

    /** get_news */
    private function toolGetNews(string $name, array $a): mixed
    {
        $db = $this->app->db();

        $c = $this->newsItem((int) ($a['id'] ?? 0));
        if (!$this->app->auth()->hasModule('news') && (!$c['visible'] || strtotime((string) $c['published_at']) > time())) {
            throw new \InvalidArgumentException('The news item does not exist or the user has no access to it.'); // 3.3.2 (N12): a draft only with the News section
        }
        $generated = $c['image'] === '' && $this->app->settings()->get('share_image') === ''
            ? \Talea\Front\ShareImage::url($this->app, \Talea\Core\Facts::fillText($c['seo_title'] !== '' ? $c['seo_title'] : $c['title'], $this->app)) : null; // drawn by the site (2.12)

        return ['id' => $c['news_id'], 'date' => $c['published_at'], 'title' => $c['title'], 'slug' => $c['slug'], 'intro' => $c['intro'], 'content' => $c['text'], 'image' => $c['image'],
            'image_caption' => $c['image_caption'], 'published' => $c['visible'], 'faq' => $c['faq'], 'seo_title' => $c['seo_title'], 'seo_description' => $c['seo_description']]
            + ($generated !== null ? ['share_image_generated' => $generated] : [])
            + self::validityOutput($c) + ['member_groups' => array_column($db->all('SELECT g.name FROM {content_groups} c JOIN {member_groups} g ON g.group_id = c.group_id WHERE c.content_type = \'news\' AND c.content_id = ? ORDER BY g.name', [(int) $c['news_id']]), 'name'), 'category' => $db->value('SELECT name FROM {categories} WHERE category_id = ?', [$c['category_id']]),
                'tags' => array_column($db->all('SELECT s.name FROM {tags} s JOIN {news_tags} cs ON cs.tag_id = s.tag_id WHERE cs.news_id = ?', [$c['news_id']]), 'name'),
                'url' => $this->app->request->origin() . $this->app->url('news/' . $c['slug'])];
    }

    /** create_news and update_news */
    private function toolCreateNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        if (!$auth->hasModule('news')) {
            throw new \DomainException('You have no access to news (user role).');
        }

        return $this->saveNewsItem($name === 'update_news' ? $this->newsItem((int) ($a['id'] ?? 0)) : null, $a);
    }

    /** update_news: the same as create_news */
    private function toolUpdateNews(string $name, array $a): mixed
    {
        return $this->toolCreateNews($name, $a);
    }

    /** trash_news */
    private function toolTrashNews(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->hasModule('news'), 'News items can be deleted only by users with the News section.');
        $item = $db->one('SELECT news_id, visible, author_id FROM {news} WHERE news_id = ? AND deleted_at IS NULL', [$id]) ?? throw new \InvalidArgumentException('The news item does not exist. Use list_news.');
        $need($auth->canPublish() || (!$item['visible'] && (int) $item['author_id'] === $auth->id()), 'A published news item or someone else’s can be deleted only with the publishing permission.');
        $db->run('UPDATE {news} SET deleted_at = NOW(), visible = FALSE WHERE news_id = ?', [$id]);
        \Talea\Front\Cache::clear();

        return ['trashed' => $id, 'restore' => 'restore_from_trash with type news within 30 days'];
    }

    /** get_social_drafts (2.13, Core\SocialDrafts) */
    private function toolGetSocialDrafts(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('news')) {
            throw new \DomainException('Social post drafts belong to news – the user has no access to the News section.');
        }
        $c = $this->newsItem((int) ($a['id'] ?? 0));
        $published = $c['visible'] && strtotime((string) $c['published_at']) <= time();
        if ($published) {
            SocialDrafts::prepare($this->app, (int) $c['news_id']); // a news item published through Claude gets its drafts here at the latest
        }

        return ['news_id' => (int) $c['news_id'], 'published' => $published,
            'drafts' => array_map(fn (array $d): array => array_diff_key($d, ['news_id' => 1]), SocialDrafts::forNews($this->app->db(), (int) $c['news_id'])),
            'networks' => SocialDrafts::networks($this->app->settings()),
            'note' => $published ? 'The user copies and posts them (Administration → News → the news item → Social posts); the site never posts anywhere. update_social_draft changes a text.'
                : 'Drafts are prepared when the news item is published (update_news with publish: true, or when its scheduled time comes).'];
    }

    /** update_social_draft (2.13) */
    private function toolUpdateSocialDraft(string $name, array $a): mixed
    {
        if (!$this->app->auth()->hasModule('news')) {
            throw new \DomainException('Social post drafts belong to news – the user has no access to the News section.');
        }
        $db = $this->app->db();
        $draft = SocialDrafts::find($db, (int) ($a['id'] ?? 0)) ?? throw new \InvalidArgumentException('The draft does not exist. Use get_social_drafts.');
        $this->newsItem($draft['news_id']); // the user's scope (an author only their own news)
        $error = SocialDrafts::update($db, $draft['id'], (string) ($a['text'] ?? ''));
        if ($error !== null) {
            throw new \DomainException($error);
        }
        \Talea\Admin\ChangeLog::write($this->app, 'news', 'social draft', $draft['network'] . ' – ' . (string) $db->value('SELECT title FROM {news} WHERE news_id = ?', [$draft['news_id']]));

        return ['draft' => array_diff_key((array) SocialDrafts::find($db, $draft['id']), ['news_id' => 1]), 'x_length' => $draft['network'] === 'x' ? SocialDrafts::xLength((string) ($a['text'] ?? '')) : null];
    }

    /** list_categories */
    private function toolListCategories(string $name, array $a): mixed
    {
        $db = $this->app->db();

        return array_map(fn (array $r): array => ['id' => (int) $r['category_id'], 'name' => $r['name'], 'slug' => $r['slug'], 'language' => $r['language'], 'news' => (int) $r['news_count']], Categories::listAll($db));
    }

    /** create_category */
    private function toolCreateCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        if (!$auth->canPublish() || !$auth->hasModule('categories')) {
            throw new \DomainException('Only editors and administrators can create categories.');
        }
        $displayName = mb_substr(trim((string) ($a['name'] ?? '')), 0, 100);
        if ($displayName === '') {
            throw new \InvalidArgumentException('The category name is missing.');
        }
        $seo = $this->availableSlug('categories', 'category_id', slugify($displayName, 110), '');

        return ['id' => $db->insert('categories', ['name' => $displayName, 'slug' => $seo, 'description' => \Talea\Core\Html::safe((string) ($a['description'] ?? ''))]), 'slug' => $seo];
    }

    /** update_category */
    private function toolUpdateCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be changed by editors and administrators.');
        $c = $db->one('SELECT * FROM {categories} WHERE category_id = ?', [$id]) ?? throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
        $changes = [];
        if (trim((string) ($a['name'] ?? '')) !== '') {
            $changes['name'] = mb_substr(trim((string) $a['name']), 0, 255);
        }
        if (isset($a['description'])) {
            $changes['description'] = \Talea\Core\Html::forUser((string) $a['description'], $auth);
        }
        if (isset($a['order'])) {
            $changes['weight'] = max(0, min(65535, (int) $a['order']));
        }
        if (trim((string) ($a['slug'] ?? '')) !== '') {
            $changes['slug'] = \Talea\Core\Slug::makeUnique(slugify((string) $a['slug'], 110), fn (string $x): bool => \Talea\Core\Slug::taken($db, 'categories', $x, (string) $c['language'], $id), 120);
        }
        if ($changes !== []) {
            $db->update('categories', $changes, ['category_id' => $id]);
            if (isset($changes['slug']) && $changes['slug'] !== $c['slug']) {
                \Talea\Admin\Modules\Redirects::add($db, \Talea\Core\Slug::redirectPath($db, 'news/category/' . $c['slug'], (string) $c['language']), \Talea\Core\Slug::redirectPath($db, 'news/category/' . $changes['slug'], (string) $c['language']));
            }
            \Talea\Front\Cache::clear();
        }
        $c = (array) $db->one('SELECT * FROM {categories} WHERE category_id = ?', [$id]);

        return ['id' => $id, 'name' => $c['name'], 'slug' => $c['slug'], 'order' => (int) $c['weight']];
    }

    /** delete_category */
    private function toolDeleteCategory(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->canPublish() && $auth->hasModule('categories'), 'Categories can be deleted by editors and administrators.');
        if ($db->value('SELECT category_id FROM {categories} WHERE category_id = ?', [$id]) === null) {
            throw new \InvalidArgumentException('The category does not exist. Use list_categories.');
        }
        if ((int) $db->value('SELECT COUNT(*) FROM {news} WHERE category_id = ?', [$id]) > 0) {
            throw new \DomainException('The category still has news items (including those in the trash) – move them to another category first.');
        }
        $db->delete('categories', ['category_id' => $id]);

        return ['deleted' => $id];
    }
}
