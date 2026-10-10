<?php

declare(strict_types=1);

namespace Talea\Import;

/**
 * Webflow: the CSV export of one CMS collection (Designer → CMS → the collection → Export). No API key is needed.
 *
 * Columns are matched by name, case-insensitively: Name, Slug, Post Body (rich text HTML; any "… Body" or "Content"
 * column), Post Summary, Main Image / Thumbnail image (addresses on Webflow's CDN – website-files.com,
 * uploads-ssl.webflow.com – so images come from any public host), Published On, Created On, Updated On, the Draft and
 * Archived flags (both hidden here), Category (one reference) and Tags (several, separated by ";") as slugs of the
 * referenced items, and Item ID when the export has it (the stable key; otherwise the slug). Static Webflow pages are not
 * in a collection export – the preview points to the URL importer for them. The collection's folder is not in the file
 * either: the administrator enters the address with it (https://www.example.com/blog) and the old address of an item is
 * that address + /slug.
 *
 * The file is read as a stream (fgetcsv), so read($skip) walks the rows again without keeping them in memory.
 */
final class Webflow implements Source
{
    private const int MAX_COLUMNS = 200;

    /** @var array<string, int>|null column role => index, from the header row */
    private ?array $columns = null;

    public function __construct(private readonly string $path, private readonly string $siteUrl = '')
    {
    }

    public static function key(): string
    {
        return 'webflow';
    }

    public static function name(): string
    {
        return 'Webflow';
    }

    public static function extensions(): array
    {
        return ['csv'];
    }

    public static function hint(): string
    {
        return 'In the Webflow Designer open CMS → the collection → Export and download the .csv file (one collection per file).';
    }

    public function verify(): void
    {
        $size = (int) @filesize($this->path);
        if ($size === 0 || $size > Batch::MAX_BYTES) {
            throw new \RuntimeException('The file is empty or larger than 256 MB.');
        }
        $this->columns();
    }

    public function site(): array
    {
        return ['name' => '', 'url' => '']; // the CSV names neither the site nor the collection's folder
    }

    public function imagesFromAnyHost(): bool
    {
        return true;
    }

    public function notes(): array
    {
        return [
            'Enter the address of the collection including its folder, e.g. https://www.example.com/blog – the old address of an item is that address followed by /slug, and the redirects count on it.',
            'Static Webflow pages are not in a collection export. Import them from the live site with Import and export → From a live website.',
            'Draft and archived items come as hidden news items. Images are downloaded from Webflow’s CDN (website-files.com), which keeps them after the site is gone.',
        ];
    }

    public function read(int $skip = 0): \Generator
    {
        $columns = $this->columns();
        $handle = $this->open();
        $order = 0;
        $seen = ['categories' => [], 'tags' => []];
        try {
            fgetcsv($handle, null, ',', '"', '');
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($row === [null] || count($row) < 2) {
                    continue; // an empty line
                }
                $cell = fn (string $role): string => isset($columns[$role]) ? trim((string) ($row[$columns[$role]] ?? '')) : '';
                $categories = self::references($cell('category'));
                $tags = self::references($cell('tags'));
                // the category and the tags go first, on first sight – a post may refer to them in a later batch
                foreach ($categories as $slug) {
                    if (!isset($seen['categories'][$slug])) {
                        $seen['categories'][$slug] = true;
                        if ($order++ >= $skip) {
                            yield $order - 1 => new Category('c:' . $slug, self::nameFromSlug($slug), $slug);
                        }
                    }
                }
                foreach ($tags as $slug) {
                    if (!isset($seen['tags'][$slug])) {
                        $seen['tags'][$slug] = true;
                        if ($order++ >= $skip) {
                            yield $order - 1 => new Tag('t:' . $slug, self::nameFromSlug($slug), $slug);
                        }
                    }
                }
                if ($order++ >= $skip) {
                    yield $order - 1 => $this->post($cell, array_map(fn (string $s): string => 'c:' . $s, $categories), array_map(fn (string $s): string => 't:' . $s, $tags));
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /* ---------- one row (covered by tools/unit-tests.php through the fixture) ---------- */

    /**
     * @param \Closure(string): string $cell
     * @param list<string> $categoryKeys
     * @param list<string> $tagKeys
     */
    private function post(\Closure $cell, array $categoryKeys, array $tagKeys): Post
    {
        $slug = $cell('slug');
        $title = $cell('name');
        $hidden = self::flag($cell('draft')) || self::flag($cell('archived'));
        $published = self::date($cell('published'));
        $created = self::date($cell('created'));
        $image = $cell('image') !== '' ? $cell('image') : $cell('thumbnail');
        $site = rtrim($this->siteUrl, '/');

        return new Post(
            key: $cell('id') !== '' ? $cell('id') : $slug,
            type: 'post',
            title: $title,
            slug: $slug,
            html: $cell('body'),
            excerpt: $cell('summary'),
            status: $hidden ? 'draft' : ($published !== '' && strtotime($published) > time() ? 'scheduled' : 'published'),
            publishedAt: $published !== '' ? $published : $created,
            updatedAt: self::date($cell('updated')),
            categoryKeys: $categoryKeys,
            tagKeys: $tagKeys,
            featureImageUrl: preg_match('#^https?://#i', $image) ? $image : '',
            oldUrl: $slug !== '' ? ($site !== '' ? $site : '') . '/' . $slug : '',
        );
    }

    /** Reference columns hold the slugs of the referenced items, several separated by ";". @return list<string> */
    public static function references(string $value): array
    {
        $slugs = [];
        foreach (explode(';', $value) as $part) {
            $slug = slugify(trim($part), 90);
            if (trim($part) !== '' && !in_array($slug, $slugs, true) && count($slugs) < 20) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    /** "spring-garden" → "Spring garden" – the export carries the slug of a referenced item, not its name. */
    public static function nameFromSlug(string $slug): string
    {
        return mb_substr(ucfirst(str_replace(['-', '_'], ' ', $slug)), 0, 80);
    }

    /** Webflow writes "Tue Mar 05 2024 10:00:00 GMT+0000 (Coordinated Universal Time)" – the name in parentheses is not a date. */
    public static function date(string $value): string
    {
        $value = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $value));

        return $value !== '' && strtotime($value) !== false ? $value : '';
    }

    private static function flag(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['true', 'yes', '1'], true);
    }

    /* ---------- the header row ---------- */

    /**
     * Column roles from the header: a Webflow export always has Name and Slug.
     *
     * @return array<string, int>
     * @throws \RuntimeException
     */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }
        $handle = $this->open();
        $header = fgetcsv($handle, null, ',', '"', '');
        fclose($handle);
        $columns = [];
        foreach (is_array($header) ? array_slice($header, 0, self::MAX_COLUMNS) : [] as $i => $name) {
            $name = strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $name)));
            $role = match (true) {
                $name === 'name' => 'name',
                $name === 'slug' => 'slug',
                $name === 'item id' => 'id',
                $name === 'post body', !isset($columns['body']) && (str_ends_with($name, ' body') || $name === 'body' || $name === 'content') => 'body',
                $name === 'post summary', !isset($columns['summary']) && str_contains($name, 'summary') => 'summary',
                $name === 'main image' => 'image',
                $name === 'thumbnail image' => 'thumbnail',
                $name === 'published on' => 'published',
                $name === 'created on' => 'created',
                $name === 'updated on' => 'updated',
                $name === 'draft' => 'draft',
                $name === 'archived' => 'archived',
                $name === 'category', $name === 'categories' => 'category',
                $name === 'tags', $name === 'tag' => 'tags',
                !isset($columns['image']) && str_ends_with($name, ' image') => 'image',
                default => '',
            };
            if ($role !== '' && !isset($columns[$role])) {
                $columns[$role] = $i;
            }
        }
        if (!isset($columns['name'], $columns['slug'])) {
            throw new \RuntimeException('This is not a Webflow collection export – the first row has no Name and Slug columns. In the Webflow Designer open CMS → the collection → Export and download the .csv file.');
        }

        return $this->columns = $columns;
    }

    /** @return resource */
    private function open()
    {
        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The file could not be opened.');
        }

        return $handle;
    }
}
