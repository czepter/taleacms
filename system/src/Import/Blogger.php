<?php

declare(strict_types=1);

namespace Kaleta\Import;

/**
 * Blogger: the Atom XML export from Blogger → Settings → Manage blog → Back up content.
 *
 * Entries carry their kind in a category of the "#kind" scheme: post and page are read, comments, settings and the
 * template are skipped (the preview says so). Labels become tags, the author (name, e-mail) an Author record on first
 * sight, the link rel="alternate" the old address (/2019/05/slug.html → redirect), app:control/app:draft the draft state.
 * The post's thumbnail (media:thumbnail, a 72 px copy) stands in for the featured image at full size; images in the text
 * live on blogger.googleusercontent.com or bp.blogspot.com – not the blog's own domain – so the downloader is allowed
 * any public host for this source.
 *
 * The file is read as a stream (XMLReader) with the same rules as a WordPress export: a DOCTYPE or custom entities are
 * rejected outright (XXE, billion laughs), nothing is loaded from the network, no entity substitution.
 */
final class Blogger implements Source
{
    private const string ATOM = 'http://www.w3.org/2005/Atom';
    private const string KIND = 'http://schemas.google.com/g/2005#kind';
    private const string LABELS = 'http://www.blogger.com/atom/ns#';

    public function __construct(private readonly string $path, private readonly string $siteUrl = '')
    {
    }

    public static function key(): string
    {
        return 'blogger';
    }

    public static function name(): string
    {
        return 'Blogger';
    }

    public static function extensions(): array
    {
        return ['xml'];
    }

    public static function hint(): string
    {
        return 'In Blogger open Settings → Manage blog → Back up content and download the .xml file.';
    }

    public function verify(): void
    {
        $size = (int) @filesize($this->path);
        if ($size === 0 || $size > Batch::MAX_BYTES) {
            throw new \RuntimeException('The file is empty or larger than 256 MB.');
        }
        $this->site();
    }

    public function site(): array
    {
        $site = ['nazev' => '', 'adresa' => $this->siteUrl];
        $reader = $this->open();
        try {
            $this->findFeed($reader);
            $hasMore = $this->step($reader);
            while ($hasMore && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === 0)) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== 1) {
                    $hasMore = $this->step($reader);
                    continue;
                }
                if ($reader->localName === 'entry') {
                    break;
                }
                if ($reader->localName === 'title') {
                    $site['nazev'] = mb_substr(self::plainText($this->node($reader)->textContent), 0, 150);
                } elseif ($reader->localName === 'link' && $reader->getAttribute('rel') === 'alternate' && $this->siteUrl === '') {
                    $site['adresa'] = rtrim(trim((string) $reader->getAttribute('href')), '/');
                }
                $hasMore = $this->skipElement($reader);
            }
        } finally {
            $reader->close();
        }

        return $site;
    }

    public function imagesFromAnyHost(): bool
    {
        return true;
    }

    public function notes(): array
    {
        return [
            'Comments are skipped; so are the blog’s settings and template.',
            'Labels become tags. Blogger has no categories – choose one for the posts below.',
            'Images are downloaded from Blogger’s image hosts (blogger.googleusercontent.com), so the blog may already be gone.',
        ];
    }

    public function read(int $skip = 0): \Generator
    {
        $reader = $this->open();
        $order = 0;
        $seen = ['autori' => [], 'stitky' => []];
        try {
            $this->findFeed($reader);
            $hasMore = $this->step($reader);
            while ($hasMore) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== 1) {
                    $hasMore = $this->step($reader);
                    continue;
                }
                if ($reader->localName !== 'entry') {
                    $hasMore = $this->skipElement($reader);
                    continue;
                }
                $entry = $this->node($reader);
                $kind = self::kind($entry);
                if ($kind === 'post' || $kind === 'page') {
                    // the author and the labels go first, on first sight – a post may refer to them in a later batch
                    $author = self::author($entry);
                    if ($author !== null && !isset($seen['autori'][$author->key])) {
                        $seen['autori'][$author->key] = true;
                        if ($order++ >= $skip) {
                            yield $order - 1 => $author;
                        }
                    }
                    $labels = self::labels($entry);
                    foreach ($labels as $slug => $label) {
                        if (!isset($seen['stitky'][$slug])) {
                            $seen['stitky'][$slug] = true;
                            if ($order++ >= $skip) {
                                yield $order - 1 => new Tag($slug, $label, $slug);
                            }
                        }
                    }
                    if ($order++ >= $skip) {
                        yield $order - 1 => self::post($entry, $kind, $author !== null ? $author->key : '', array_keys($labels));
                    }
                }
                $hasMore = $this->skipElement($reader);
            }
        } finally {
            $reader->close();
        }
    }

    /* ---------- one entry (covered by tools/unit-tests.php through the fixture) ---------- */

    /** post | page | comment | settings | template | '' from the #kind category. */
    private static function kind(\DOMElement $entry): string
    {
        foreach (self::children($entry, 'category') as $c) {
            if ($c->getAttribute('scheme') === self::KIND && preg_match('/#(\w+)$/', $c->getAttribute('term'), $m)) {
                return $m[1];
            }
        }

        return '';
    }

    private static function author(\DOMElement $entry): ?Author
    {
        $a = self::children($entry, 'author')[0] ?? null;
        if ($a === null) {
            return null;
        }
        $name = self::plainText(self::childText($a, 'name'));
        $email = self::plainText(self::childText($a, 'email'));
        $uri = trim(self::childText($a, 'uri'));
        $key = $uri !== '' ? $uri : ($email !== '' && $email !== 'noreply@blogger.com' ? $email : $name);

        return $key === '' ? null : new Author(mb_substr($key, 0, 190), $name, $email === 'noreply@blogger.com' ? '' : mb_substr($email, 0, 190));
    }

    /** @return array<string, string> slug => label */
    private static function labels(\DOMElement $entry): array
    {
        $labels = [];
        foreach (self::children($entry, 'category') as $c) {
            $term = self::plainText($c->getAttribute('term'));
            if ($c->getAttribute('scheme') === self::LABELS && $term !== '' && count($labels) < 20) {
                $labels[slugify($term, 90)] = mb_substr($term, 0, 80);
            }
        }

        return $labels;
    }

    /** @param list<string> $tagKeys */
    private static function post(\DOMElement $entry, string $kind, string $authorKey, array $tagKeys): Post
    {
        $old = '';
        foreach (self::children($entry, 'link') as $link) {
            if ($link->getAttribute('rel') === 'alternate' && str_contains($link->getAttribute('type'), 'html')) {
                $old = trim($link->getAttribute('href'));
            }
        }
        // the newer export also writes the path and the status in its own namespace; a draft has no alternate link
        $filename = trim(self::childText($entry, 'filename'));
        $old = $old === '' && $filename !== '' ? $filename : $old;
        $draft = strtolower(trim(self::childText(self::children($entry, 'control')[0] ?? $entry, 'draft'))) === 'yes'
            || strtoupper(trim(self::childText($entry, 'status'))) === 'DRAFT';
        $title = self::plainText(self::childText($entry, 'title'));
        $path = (string) parse_url($old, PHP_URL_PATH);
        $slug = preg_replace('/\.html?$/i', '', basename($path)) ?: '';
        $thumbnail = '';
        foreach (self::children($entry, 'thumbnail') as $t) {
            $thumbnail = trim($t->getAttribute('url'));
        }
        $published = trim(self::childText($entry, 'published'));
        $content = self::childText($entry, 'content');

        return new Post(
            key: mb_substr(trim(self::childText($entry, 'id')), 0, 190),
            type: $kind,
            title: $title,
            slug: $slug !== '' && $slug !== '/' && $slug !== '.' ? $slug : slugify($title, 150),
            html: $content,
            status: $draft ? 'draft' : ($published !== '' && strtotime($published) > time() ? 'scheduled' : 'published'),
            publishedAt: $published,
            updatedAt: trim(self::childText($entry, 'updated')),
            authorKey: $authorKey,
            tagKeys: $tagKeys,
            featureImageUrl: self::fullSize($thumbnail),
            oldUrl: $old,
        );
    }

    /** Blogger's thumbnail is a 72 px copy: the size segment (/s72-c/, /w72-h72-p-k-no-nu/) becomes the full size. */
    public static function fullSize(string $url): string
    {
        return $url === '' ? '' : (string) preg_replace('#/(?:s\d{2,4}(?:-c)?|w\d{2,4}-h\d{2,4}(?:-[a-z0-9-]+)?)/([^/]+)$#i', '/s1600/$1', $url);
    }

    /* ---------- XML reading (the same safety as Core\WpFile) ---------- */

    private function open(): \XMLReader
    {
        if (!class_exists(\XMLReader::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('The PHP extension xmlreader or dom is missing on the server – a WordPress export cannot be read without them.');
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        if (!@$reader->open($this->path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('The file could not be opened.');
        }

        return $reader;
    }

    /** Moves the reader to the <feed> root and verifies it is a Blogger export (Atom with the #kind categories of Blogger). */
    private function findFeed(\XMLReader $reader): void
    {
        while ($this->step($reader)) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->depth === 0) {
                if ($reader->localName !== 'feed' || $reader->namespaceURI !== self::ATOM || !str_contains(self::head($this->path), 'blogger.com')) {
                    throw new \RuntimeException('This is not a Blogger export. In Blogger open Settings → Manage blog → Back up content and download the .xml file.');
                }

                return;
            }
        }
        throw new \RuntimeException('The file contains no blog content (the feed element is missing).');
    }

    /** The first kilobytes of the file: the feed's id (tag:blogger.com,…) and generator are there. */
    private static function head(string $path): string
    {
        return (string) @file_get_contents($path, false, null, 0, 8192);
    }

    private function step(\XMLReader $reader): bool
    {
        return $this->check($reader, @$reader->read());
    }

    private function skipElement(\XMLReader $reader): bool
    {
        return $this->check($reader, @$reader->next());
    }

    private function check(\XMLReader $reader, bool $ok): bool
    {
        if ($ok && ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF || $reader->nodeType === \XMLReader::ENTITY)) {
            throw new \RuntimeException('The file contains a DOCTYPE or custom entities. An export never has them – the file was rejected for security reasons.');
        }
        $error = libxml_get_last_error();
        if (!$ok && $error !== false) {
            libxml_clear_errors();
            throw new \RuntimeException('The file is not valid XML – it is damaged or incomplete. Download the export again. The error is on line:', $error->line);
        }

        return $ok;
    }

    /** The element being read as a standalone DOM node – only this one element is in memory. */
    private function node(\XMLReader $reader): \DOMElement
    {
        $node = @$reader->expand(new \DOMDocument());
        if (!$node instanceof \DOMElement) {
            $error = libxml_get_last_error();
            libxml_clear_errors();
            throw new \RuntimeException('The file is not valid XML – it is damaged or incomplete. Download the export again. The error is on line:', $error !== false ? $error->line : 0);
        }
        foreach ($node->getElementsByTagName('*') as $child) {
            foreach ($child->childNodes as $n) {
                if ($n instanceof \DOMEntityReference) {
                    throw new \RuntimeException('The file contains a DOCTYPE or custom entities. An export never has them – the file was rejected for security reasons.');
                }
            }
        }

        return $node;
    }

    /**
     * Direct children by local name, whatever their namespace prefix (atom, app, media, blogger).
     *
     * @return list<\DOMElement>
     */
    private static function children(\DOMElement $element, string $localName): array
    {
        $found = [];
        foreach ($element->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->localName === $localName) {
                $found[] = $n;
            }
        }

        return $found;
    }

    private static function childText(\DOMElement $element, string $localName): string
    {
        $children = self::children($element, $localName);

        return $children === [] ? '' : $children[0]->textContent;
    }

    private static function plainText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
