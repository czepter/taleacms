<?php

declare(strict_types=1);

namespace Kaleta\Import;

/**
 * Ghost: the JSON export from Ghost Admin → Settings → Labs → Export your content.
 *
 * What is read: db[0].data.posts (or data.posts in older exports) with users, tags, posts_tags, posts_authors and
 * posts_meta. Posts of type "page" become pages. The HTML comes from posts.html; a post that only has a lexical document
 * (Ghost 5 without rendered HTML) is rendered here – paragraphs, headings, lists, quotes, links and images – and every
 * other card is reported as a warning; a post with only a mobiledoc falls back to its plain text. Ghost has no
 * categories: the primary tag (the first one by sort order) is offered as the category, the other tags as tags; internal
 * tags (#name) are skipped. Old addresses are /<slug>/ – Ghost's default routes; a custom routes.yaml is not in the
 * export, so the preview says so. Ghost 5 writes the site address as __GHOST_URL__, which the export does not resolve:
 * the administrator enters the address in the preview and it is put in here.
 * Members, newsletters, comments and the theme are not transferred.
 */
final class Ghost implements Source
{
    /** @var array<string, mixed>|null the export's data block, parsed once per request */
    private ?array $data = null;

    public function __construct(private readonly string $path, private readonly string $siteUrl = '')
    {
    }

    public static function key(): string
    {
        return 'ghost';
    }

    public static function name(): string
    {
        return 'Ghost';
    }

    public static function extensions(): array
    {
        return ['json'];
    }

    public static function hint(): string
    {
        return 'In Ghost Admin open Settings → Labs → Export your content and download the .json file.';
    }

    public function verify(): void
    {
        $size = (int) @filesize($this->path);
        if ($size === 0 || $size > Batch::MAX_BYTES) {
            throw new \RuntimeException('The file is empty or larger than 256 MB.');
        }
        $this->load();
    }

    public function site(): array
    {
        $name = '';
        foreach ($this->load()['settings'] ?? [] as $setting) {
            if (is_array($setting) && ($setting['key'] ?? '') === 'title') {
                $name = self::text($setting['value'] ?? '');
            }
        }

        return ['name' => mb_substr($name, 0, 150), 'url' => $this->siteUrl];
    }

    public function imagesFromAnyHost(): bool
    {
        return false;
    }

    public function notes(): array
    {
        return [
            'Old addresses are taken as /slug/ (Ghost’s default routes). A custom routes.yaml is not part of the export and is not read.',
            'The primary tag of a post is offered as its category, the other tags as tags; internal tags (#name) are skipped.',
            'Members, newsletters, comments and the theme are not transferred. A post that was only sent by e-mail is imported as a draft.',
        ];
    }

    public function read(int $skip = 0): \Generator
    {
        $d = $this->load();
        $order = 0;
        $tags = [];
        foreach ($d['users'] ?? [] as $u) {
            if (is_array($u) && ($u['id'] ?? '') !== '') {
                $record = new Author((string) $u['id'], self::text($u['name'] ?? ''), mb_substr(self::text($u['email'] ?? ''), 0, 190));
                if ($order++ >= $skip) {
                    yield $order - 1 => $record;
                }
            }
        }
        foreach ($d['tags'] ?? [] as $t) {
            if (!is_array($t) || ($t['id'] ?? '') === '' || str_starts_with((string) ($t['name'] ?? ''), '#') || ($t['visibility'] ?? 'public') === 'internal') {
                continue;
            }
            $tags[(string) $t['id']] = true;
            $record = new Tag((string) $t['id'], self::text($t['name'] ?? ''), self::text($t['slug'] ?? ''));
            if ($order++ >= $skip) {
                yield $order - 1 => $record;
            }
        }
        // the posts' tags and authors by post, in their sort order
        $postTags = [];
        foreach ($d['posts_tags'] ?? [] as $pt) {
            if (is_array($pt) && isset($tags[(string) ($pt['tag_id'] ?? '')])) {
                $postTags[(string) ($pt['post_id'] ?? '')][] = [(int) ($pt['sort_order'] ?? 0), (string) $pt['tag_id']];
            }
        }
        $postAuthors = [];
        foreach ($d['posts_authors'] ?? [] as $pa) {
            $postId = is_array($pa) ? (string) ($pa['post_id'] ?? '') : '';
            if ($postId !== '' && (!isset($postAuthors[$postId]) || (int) ($pa['sort_order'] ?? 0) === 0)) {
                $postAuthors[$postId] = (string) ($pa['author_id'] ?? ''); // the primary author (sort order 0) wins
            }
        }
        $meta = [];
        foreach ($d['posts_meta'] ?? [] as $pm) {
            if (is_array($pm)) {
                $meta[(string) ($pm['post_id'] ?? '')] = $pm;
            }
        }
        foreach ($d['posts'] ?? [] as $p) {
            if (!is_array($p) || ($p['id'] ?? '') === '') {
                continue;
            }
            if ($order++ < $skip) {
                continue;
            }
            $id = (string) $p['id'];
            $pairs = $postTags[$id] ?? [];
            usort($pairs, fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $tagIds = array_values(array_unique(array_column($pairs, 1)));
            $m = $meta[$id] ?? [];
            [$html, $warnings] = $this->html($p);
            $slug = self::text($p['slug'] ?? '');
            yield $order - 1 => new Post(
                key: $id,
                type: ($p['type'] ?? '') === 'page' || (int) ($p['page'] ?? 0) === 1 ? 'page' : 'post',
                title: self::text($p['title'] ?? ''),
                slug: $slug,
                html: $html,
                excerpt: self::text($p['custom_excerpt'] ?? ''),
                status: match ((string) ($p['status'] ?? '')) {
                    'published' => 'published',
                    'scheduled' => 'scheduled',
                    default => 'draft', // draft; "sent" = only e-mailed, never on the site – not published silently
                },
                publishedAt: self::date($p['published_at'] ?? null),
                updatedAt: self::date($p['updated_at'] ?? null),
                authorKey: (string) ($postAuthors[$id] ?? $p['author_id'] ?? ''),
                categoryKeys: $tagIds === [] ? [] : [$tagIds[0]],
                tagKeys: array_slice($tagIds, 1),
                featureImageUrl: $this->url(self::text($p['feature_image'] ?? '')),
                seoTitle: self::text($m['meta_title'] ?? $p['meta_title'] ?? ''),
                seoDescription: self::text($m['meta_description'] ?? $p['meta_description'] ?? ''),
                oldUrl: $slug !== '' ? '/' . $slug . '/' : '',
                warnings: $warnings,
            );
        }
    }

    /* ---------- internal helpers ---------- */

    /**
     * The data block of the export; both shapes Ghost has written: {"db":[{"data":{…}}]} and {"meta":…,"data":{…}}.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $json = json_decode((string) @file_get_contents($this->path), true, 64);
        $data = is_array($json) ? ($json['db'][0]['data'] ?? $json['data'] ?? null) : null;
        if (!is_array($data) || !is_array($data['posts'] ?? null)) {
            throw new \RuntimeException('This is not a Ghost export. In Ghost Admin open Settings → Labs → Export your content and download the .json file.');
        }

        return $this->data = $data;
    }

    /**
     * The HTML of a post: rendered html when Ghost wrote it, else the lexical document rendered here, else the plain text.
     *
     * @param array<string, mixed> $p
     * @return array{0: string, 1: list<string>}
     */
    private function html(array $p): array
    {
        $html = (string) ($p['html'] ?? '');
        if (trim($html) !== '') {
            return [$this->url($html), []];
        }
        $lexical = json_decode((string) ($p['lexical'] ?? ''), true);
        if (is_array($lexical) && is_array($lexical['root']['children'] ?? null)) {
            $warnings = [];
            $html = self::lexical($lexical['root']['children'], $warnings);

            return [$this->url($html), array_values(array_unique($warnings))];
        }
        $plain = trim((string) ($p['plaintext'] ?? ''));
        $paragraphs = array_filter(array_map(trim(...), preg_split('/\n\s*\n/', $plain) ?: []), fn (string $s): bool => $s !== '');

        return [implode("\n", array_map(fn (string $s): string => '<p>' . nl2br(e($s), false) . '</p>', $paragraphs)), ['block:mobiledoc']];
    }

    /**
     * Lexical nodes → HTML: paragraphs, headings, lists, quotes, links, text with bold/italic/code, images. Other cards
     * (gallery, bookmark, embed, html, callout, button, toggle…) are left out and reported.
     *
     * @param list<mixed> $nodes the "children" of a lexical node, straight from the JSON – not trusted to be arrays
     * @param list<string> $warnings
     */
    public static function lexical(array $nodes, array &$warnings, int $depth = 0): string
    {
        $out = '';
        foreach ($nodes as $n) {
            if (!is_array($n) || $depth > 20) {
                continue;
            }
            $children = is_array($n['children'] ?? null) ? $n['children'] : [];
            $inner = fn (): string => self::lexical($children, $warnings, $depth + 1);
            $out .= match ((string) ($n['type'] ?? '')) {
                'paragraph' => '<p>' . $inner() . "</p>\n",
                'heading' => (fn (string $tag): string => '<' . $tag . '>' . $inner() . '</' . $tag . ">\n")(in_array($n['tag'] ?? '', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? (string) $n['tag'] : 'h2'),
                'list' => (fn (string $tag): string => '<' . $tag . '>' . $inner() . '</' . $tag . ">\n")(($n['listType'] ?? '') === 'number' ? 'ol' : 'ul'),
                'listitem' => '<li>' . $inner() . '</li>',
                'quote', 'aside' => '<blockquote>' . $inner() . "</blockquote>\n",
                'link' => '<a href="' . e((string) ($n['url'] ?? '')) . '">' . $inner() . '</a>',
                'linebreak' => '<br>',
                'text', 'extended-text' => self::formatted((string) ($n['text'] ?? ''), (int) ($n['format'] ?? 0)),
                'image' => '<figure><img src="' . e((string) ($n['src'] ?? '')) . '" alt="' . e((string) ($n['alt'] ?? '')) . '">'
                    . (trim((string) ($n['caption'] ?? '')) !== '' ? '<figcaption>' . (string) $n['caption'] . '</figcaption>' : '') . "</figure>\n",
                'horizontalrule' => "<hr>\n",
                default => self::unsupported((string) ($n['type'] ?? ''), $warnings),
            };
        }

        return $out;
    }

    /** @param list<string> $warnings */
    private static function unsupported(string $type, array &$warnings): string
    {
        $warnings[] = 'block:' . ($type !== '' ? preg_replace('/[^a-z0-9_-]/i', '', $type) : 'unknown');

        return '';
    }

    /** Lexical text format bits: 1 bold, 2 italic, 8 underline, 16 code. */
    private static function formatted(string $text, int $format): string
    {
        $html = e($text);
        if ($format & 16) {
            $html = '<code>' . $html . '</code>';
        }
        if ($format & 1) {
            $html = '<strong>' . $html . '</strong>';
        }
        if ($format & 2) {
            $html = '<em>' . $html . '</em>';
        }

        return $format & 8 ? '<u>' . $html . '</u>' : $html;
    }

    /** Ghost 5 writes the site address as __GHOST_URL__; with the address known it becomes absolute, otherwise a site-relative path stays. */
    private function url(string $text): string
    {
        return str_replace('__GHOST_URL__', rtrim($this->siteUrl, '/'), $text);
    }

    /** Ghost dates: ISO 8601 strings, or milliseconds since the epoch in Ghost 1.x. */
    private static function date(mixed $value): string
    {
        if (is_int($value) || is_float($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 12)) {
            return date('c', intdiv((int) $value, 1000));
        }

        return is_string($value) ? trim($value) : '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
