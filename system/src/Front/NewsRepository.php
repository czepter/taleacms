<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Core\Db;
use Kaleta\Core\Images;
use Kaleta\Core\Settings;

/**
 * Reading news for the site (table ka_news). The site shows only a published news item (visible = 1) whose publish
 * date has already come.
 */
final class NewsRepository
{
    private const string SELECT = "
        SELECT c.*, t.name AS category_name, t.slug AS category_slug,
               NULLIF(u.name, '') AS author_name, -- the login name is not shown on the site; without a filled-in name author_id is not output
               u.position AS author_position, u.photo AS author_photo, u.bio AS author_bio, u.url AS author_url
        FROM {news} c
        JOIN {categories} t ON t.category_id = c.category_id
        LEFT JOIN {users} u ON u.user_id = c.author_id";

    /**
     * Columns for listings: without the long texts (text, FAQ) that a listing does not print. The keys stay in the array
     * (empty) so that templates do not break. A new ka_news column that should be visible in listings must be added here too.
     */
    private const string LIST_COLUMNS = "c.news_id, c.slug, c.title, c.intro, '' AS text, c.image, c.category_id, c.author_id, c.published_at, c.visible, c.keywords, c.noindex, '' AS faq, c.visit,
        c.edited_at, c.updated_at, c.language, c.translation_of";

    private const string PUBLISHED = 'c.visible = 1 AND c.published_at <= NOW()';

    /** Condition "published news item in the language of the currently shown site version". */
    private readonly string $published;

    /** @param string $base path to the installation ("" or "/web") - prepended to URLs of images from media/ */
    public function __construct(private readonly Db $db, private readonly Settings $settings, private readonly string $base = '')
    {
        $this->published = self::PUBLISHED . " AND c.language = '" . \Kaleta\Core\Language::siteColumn() . "'";
    }

    /**
     * Adjusts a news item before it is passed to the template: the URL of the main image from media/ gets the installation path.
     *
     * @param array<string, mixed> $newsItem
     * @return array<string, mixed>
     */
    private function prepare(array $newsItem): array
    {
        if ($newsItem['image'] !== '' && !preg_match('#^(https?:)?/#', $newsItem['image'])) {
            $newsItem['image'] = $this->base . '/' . $newsItem['image'];
        }
        // responsive images: the main image and images in the text get a srcset from the variants created on upload
        $newsItem['image_srcset'] = Images::srcset(ltrim(substr($newsItem['image'], strlen($this->base)), '/'), $this->base);
        foreach (['intro', 'text'] as $part) {
            if (str_contains($newsItem[$part], 'media/')) {
                $newsItem[$part] = preg_replace_callback('#<img\b(?![^>]*\bsrcset=)([^>]*?)\bsrc="([^"]*?(media/\d{4}/\d{2}/[^"]+))"#i', function (array $m): string {
                    $srcset = Images::srcset($m[3], $this->base);

                    return $srcset === '' ? $m[0] : '<img' . $m[1] . 'src="' . $m[2] . '" srcset="' . e($srcset) . '" sizes="(max-width: 800px) 100vw, 800px"';
                }, $newsItem[$part]) ?? $newsItem[$part];
            }
        }

        return $newsItem;
    }

    public function perPage(): int
    {
        return max(1, $this->settings->int('news_per_page'));
    }

    /**
     * Published news, newest first.
     *
     * @return array{0: list<array<string, mixed>>, 1: int} news and their total count
     */
    public function listPublished(int $pageNumber, ?int $limit = null, bool $withText = false): array
    {
        return $this->query($this->published, [], 'c.published_at DESC, c.news_id DESC', $pageNumber, $limit, $withText);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function inCategory(int $idt, int $pageNumber, ?int $limit = null): array
    {
        return $this->query($this->published . ' AND c.category_id = ?', [$idt], 'c.published_at DESC, c.news_id DESC', $pageNumber, $limit);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function withTag(int $ids, int $pageNumber): array
    {
        return $this->query($this->published . ' AND EXISTS (SELECT 1 FROM {news_tags} cs WHERE cs.news_id = c.news_id AND cs.tag_id = ?)', [$ids], 'c.published_at DESC, c.news_id DESC', $pageNumber);
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function search(string $q, int $pageNumber): array
    {
        // index without diacritics (Core\Search): "cafe" finds "café"; short words and parts of words are searched in the title
        \Kaleta\Core\Search::complete($this->db); // news from before the index are filled in automatically
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $query = \Kaleta\Core\Search::query($q);
        if ($query === '') {
            return $this->query($this->published . ' AND c.title LIKE ?', [$like], 'c.published_at DESC, c.news_id DESC', $pageNumber);
        }

        return $this->query(
            $this->published . ' AND (MATCH(c.search_text) AGAINST (? IN BOOLEAN MODE) OR c.title LIKE ?)',
            [$query, $like],
            'c.published_at DESC, c.news_id DESC',
            $pageNumber,
        );
    }

    /** @return array<string, mixed>|null */
    public function bySlug(string $seo, bool $includeUnpublished = false): ?array
    {
        $newsItem = $this->db->one(self::SELECT . ' WHERE c.slug = ? AND c.deleted_at IS NULL' . ($includeUnpublished ? '' : ' AND ' . self::PUBLISHED), [$seo]);
        if ($newsItem === null) {
            return null;
        }
        // caption, author and alt of the main image: from the news item, otherwise from the media library
        $library = $newsItem['image'] !== '' && !preg_match('#^(https?:)?//#', $newsItem['image'])
            ? $this->db->one('SELECT name, description, author FROM {media} WHERE image_path = ? LIMIT 1', [ltrim($newsItem['image'], '/')]) : null;
        $description = $newsItem['image_caption'] !== '' ? $newsItem['image_caption'] : (string) ($library['description'] ?? '');
        $author = $newsItem['image_author'] !== '' ? $newsItem['image_author'] : (string) ($library['author'] ?? '');
        $newsItem['image_alt'] = (string) ($library['name'] ?? '') !== '' ? (string) $library['name'] : $description;
        $parts = array_filter([e($description), $author !== '' ? '<span class="article-photo-author">' . e(t('Photo: %s', $author)) . '</span>' : '']);
        $newsItem['image_caption_html'] = $parts === [] ? '' : '<figcaption class="article-caption">' . implode(' ', $parts) . '</figcaption>';

        return $this->prepare($newsItem);
    }

    /**
     * Similar news: first by the number of shared tags, then newer ones from the same category.
     *
     * @param array<string, mixed> $newsItem
     * @return list<array<string, mixed>>
     */
    public function similar(array $newsItem, int $count = 4): array
    {
        return $this->db->all(
            'SELECT c.title, c.slug, c.published_at, COUNT(cs.tag_id) AS shoda
             FROM {news} c LEFT JOIN {news_tags} cs ON cs.news_id = c.news_id AND cs.tag_id IN (SELECT tag_id FROM {news_tags} WHERE news_id = ?)
             WHERE ' . $this->published . ' AND c.news_id <> ? AND (c.category_id = ? OR cs.tag_id IS NOT NULL) AND c.published_at > NOW() - INTERVAL 2 YEAR
             GROUP BY c.news_id, c.title, c.slug, c.published_at ORDER BY shoda DESC, c.published_at DESC LIMIT ?',
            [$newsItem['news_id'], $newsItem['news_id'], $newsItem['category_id'], $count],
        );
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    private function query(string $where, array $params, string $order, int $pageNumber, ?int $limit = null, bool $withText = false): array
    {
        // fixed count (RSS, feeds, API) = nobody paginates, the total count is not computed
        $total = $limit !== null ? 0 : (int) $this->db->value("SELECT COUNT(*) FROM {news} c WHERE {$where}", $params);
        $limit ??= $this->perPage();
        $pageNumber = max(1, min($pageNumber, 100000));
        $newsItems = $this->db->all(
            ($withText ? self::SELECT : str_replace('SELECT c.*,', 'SELECT ' . self::LIST_COLUMNS . ',', self::SELECT)) . " WHERE {$where} ORDER BY {$order} LIMIT ? OFFSET ?",
            [...$params, $limit, ($pageNumber - 1) * $limit],
        );

        return [array_map($this->prepare(...), $newsItems), $total];
    }
}
