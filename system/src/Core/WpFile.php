<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Reading a WordPress export (WXR file: Tools → Export → All content). It writes nothing, only reads.
 *
 * Security and size:
 *  - it is read as a stream through XMLReader, so even an export of hundreds of MB takes no memory – only one post is in memory at a time;
 *  - a file with a DOCTYPE is rejected entirely. A WordPress export never has one, and without a DOCTYPE no entity can be defined
 *    (neither an external file or URL – an XXE attack – nor a "billion laughs"). In addition it is read with LIBXML_NONET and without LIBXML_NOENT;
 *  - the e-mail and IP address of comments are not read at all, so they cannot get any further.
 */
final class WpFile
{
    public const string FOLDER = KALETA_ROOT . '/storage/import';

    /** Upper limit of the file size: a larger export is better split (WordPress can do it by date or author). */
    public const int MAX_BYTES = 1024 * 1024 * 1024;

    /** @param string $path full path to the file on disk */
    public function __construct(private readonly string $path)
    {
    }

    /* ---------- folder storage/import ---------- */

    /** Folder for exports; it creates itself, including the denial of web access. */
    public static function folder(): string
    {
        if (!is_dir(self::FOLDER) && !@mkdir(self::FOLDER, 0775, true)) {
            throw new \RuntimeException('Cannot create the storage/import folder – check write permissions.');
        }
        if (!is_file(self::FOLDER . '/.htaccess')) {
            @file_put_contents(self::FOLDER . '/.htaccess', "Require all denied\n");
        }

        return self::FOLDER;
    }

    /**
     * The *.xml files in the folder (uploaded through the form or over FTP), newest on top.
     *
     * @return list<array{soubor:string, velikost:int, cas:int}>
     */
    public static function listAll(): array
    {
        $files = [];
        foreach (glob(self::FOLDER . '/*.{xml,XML}', GLOB_BRACE) ?: [] as $path) {
            if (self::isValidName(basename($path))) {
                $files[] = ['file' => basename($path), 'velikost' => (int) filesize($path), 'cas' => (int) filemtime($path)];
            }
        }
        usort($files, fn (array $a, array $b): int => $b['cas'] <=> $a['cas']);

        return $files;
    }

    /** A file name from the form must not lead anywhere other than the import folder. */
    public static function isValidName(string $file): bool
    {
        return $file !== '' && strlen($file) <= 150 && basename($file) === $file && !str_starts_with($file, '.')
            && !preg_match('#[/\\\\\x00-\x1f]#', $file) && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xml';
    }

    /** Path to an existing file by the name from the form; null = invalid name or the file does not exist. */
    public static function path(string $file): ?string
    {
        return self::isValidName($file) && is_file(self::FOLDER . '/' . $file) ? self::FOLDER . '/' . $file : null;
    }

    /** A safe name for an uploaded file: without diacritics and spaces, always with the .xml extension. */
    public static function uploadName(string $previous): string
    {
        return slugify(pathinfo($previous, PATHINFO_FILENAME), 80) . '.xml';
    }

    /* ---------- verification and reading ---------- */

    /**
     * Quick verification before accepting the file: extension, size, a well-formed start of the XML and the WordPress export namespace.
     * The rest of the file is verified on the first pass (preview) – an XML error stops it with the line number.
     *
     * @throws \RuntimeException with a Czech message for the user
     */
    public function verify(): void
    {
        if (strtolower(pathinfo($this->path, PATHINFO_EXTENSION)) !== 'xml') {
            throw new \RuntimeException('The file must have the .xml extension – it is an export from WordPress (Tools → Export).');
        }
        $this->verifyContent();
    }

    /**
     * The same without the extension check – for a just-uploaded file that still sits in the temporary folder under a random name.
     *
     * @throws \RuntimeException
     */
    public function verifyContent(): void
    {
        $size = (int) @filesize($this->path);
        if ($size === 0 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('The file is empty or larger than 1 GB. Export a large site from WordPress in parts (by date).');
        }
        $this->header();
    }

    /**
     * Data from the start of the file (before the first post): old site, authors, categories, tags.
     *
     * @return array{nazev:string, adresa:string, autori:array<string,string>, rubriky:array<string,array{nazev:string, predek:string}>, stitky:array<string,string>}
     */
    public function header(): array
    {
        $h = ['nazev' => '', 'adresa' => '', 'autori' => [], 'rubriky' => [], 'stitky' => []];
        $link = '';
        $reader = $this->open();
        try {
            $this->findChannel($reader);
            // children of <channel>: read one by one up to the first <item>
            $hasMore = $this->read($reader);
            while ($hasMore && !($reader->nodeType === \XMLReader::END_ELEMENT && $reader->depth === 1)) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== 2) {
                    $hasMore = $this->read($reader);
                    continue;
                }
                if ($reader->name === 'item') {
                    break;
                }
                $field = self::fields($this->node($reader));
                match ($reader->name) {
                    'title' => $h['nazev'] = self::plainText($field['title'] ?? ''),
                    'link' => $link = trim($field['link'] ?? ''),
                    'wp:base_site_url' => $h['adresa'] = trim($field['wp:base_site_url'] ?? ''),
                    'wp:author' => $h['autori'][(string) ($field['wp:author_login'] ?? '')] = self::plainText(($field['wp:author_display_name'] ?? '') !== '' ? $field['wp:author_display_name'] : ($field['wp:author_login'] ?? '')),
                    'wp:category' => $h['rubriky'][(string) ($field['wp:category_nicename'] ?? '')] = ['nazev' => self::plainText($field['wp:cat_name'] ?? ''), 'predek' => (string) ($field['wp:category_parent'] ?? '')],
                    'wp:tag' => $h['stitky'][(string) ($field['wp:tag_slug'] ?? '')] = self::plainText($field['wp:tag_name'] ?? ''),
                    default => null,
                };
                $hasMore = $this->additional($reader);
            }
        } finally {
            $reader->close();
        }
        // URL of the old site: the channel's <link> is the URL the site really ran on; base_site_url only as a fallback
        $h['adresa'] = $link !== '' ? $link : $h['adresa'];
        unset($h['autori'][''], $h['rubriky'][''], $h['stitky']['']);

        return $h;
    }

    /**
     * The file's posts (articles, pages, attachments…) one by one. The key is the order from zero – by it the import knows where it stopped last time.
     *
     * @param int $skip how many posts to skip from the start (skipping is fast, without parsing the content)
     * @return \Generator<int, array<string, mixed>>
     */
    public function items(int $skip = 0): \Generator
    {
        $reader = $this->open();
        try {
            $this->findChannel($reader);
            $order = 0;
            $hasMore = $this->read($reader);
            while ($hasMore) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === 2) {
                    if ($reader->name === 'item') {
                        if ($order >= $skip) {
                            yield $order => self::item($this->node($reader));
                        }
                        $order++;
                    }
                    $hasMore = $this->additional($reader);
                } else {
                    $hasMore = $this->read($reader);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Parses one <item>. Comments are not read – a company site does not take them over.
     *
     * @return array<string, mixed>
     */
    public static function item(\DOMElement $item): array
    {
        $p = [
            'id' => 0, 'type' => 'post', 'status' => '', 'title' => '', 'link' => '', 'adresa' => '', 'datum' => '', 'datum_gmt' => '', 'vydano' => '',
            'autor' => '', 'obsah' => '', 'lead' => '', 'heslo' => '', 'pripnuty' => false, 'priloha_url' => '', 'nahled' => 0,
            'rubriky' => [], 'stitky' => [], 'meta' => [], 'pole' => [],
        ];
        foreach ($item->childNodes as $n) {
            if (!$n instanceof \DOMElement) {
                continue;
            }
            $text = $n->textContent;
            switch ($n->nodeName) {
                case 'title': $p['title'] = self::plainText($text); break;
                case 'link': $p['link'] = trim($text); break;
                case 'pubDate': $p['vydano'] = trim($text); break;
                case 'dc:creator': $p['autor'] = trim($text); break;
                case 'content:encoded': $p['obsah'] = $text; break;
                case 'excerpt:encoded': $p['lead'] = $text; break;
                case 'wp:post_id': $p['id'] = (int) $text; break;
                case 'wp:post_date': $p['datum'] = trim($text); break;
                case 'wp:post_date_gmt': $p['datum_gmt'] = trim($text); break;
                case 'wp:post_name': $p['adresa'] = trim($text); break;
                case 'wp:status': $p['status'] = trim($text); break;
                case 'wp:post_type': $p['type'] = trim($text); break;
                case 'wp:post_password': $p['heslo'] = trim($text); break;
                case 'wp:is_sticky': $p['pripnuty'] = trim($text) === '1'; break;
                case 'wp:attachment_url': $p['priloha_url'] = trim($text); break;
                case 'category':
                    $kind = $n->getAttribute('domain') === 'post_tag' ? 'stitky' : ($n->getAttribute('domain') === 'category' ? 'rubriky' : '');
                    if ($kind !== '' && $n->getAttribute('nicename') !== '') {
                        $p[$kind][$n->getAttribute('nicename')] = self::plainText($text);
                    }
                    break;
                case 'wp:postmeta':
                    $meta = self::fields($n);
                    $key = (string) ($meta['wp:meta_key'] ?? '');
                    if ($key === '_thumbnail_id') {
                        $p['nahled'] = (int) ($meta['wp:meta_value'] ?? 0);
                    } elseif (in_array($key, WpSeo::keys(), true)) {
                        $p['meta'][$key] = mb_substr((string) ($meta['wp:meta_value'] ?? ''), 0, 2000); // SEO plugin data (Core\WpSeo)
                    } elseif (WpTypes::isCustomType($p['type']) && count($p['pole']) < 120) {
                        // custom fields of a custom post type (Core\WpTypes); the export writes wp:post_type before the meta
                        $value = (string) ($meta['wp:meta_value'] ?? '');
                        if (!str_starts_with($key, '_') || str_starts_with($value, 'field_')) {
                            $p['pole'][$key] = mb_substr($value, 0, 20000);
                        }
                    }
                    break;
            }
        }

        return $p;
    }

    /* ---------- internal helpers ---------- */

    private function open(): \XMLReader
    {
        if (!class_exists(\XMLReader::class) || !class_exists(\DOMDocument::class)) {
            throw new \RuntimeException('The PHP extension xmlreader or dom is missing on the server – a WordPress export cannot be read without them.');
        }
        libxml_use_internal_errors(true);
        libxml_clear_errors();
        // LIBXML_NONET: nothing is loaded from the network. Deliberately WITHOUT LIBXML_NOENT (entity substitution) and WITHOUT LIBXML_DTDLOAD.
        $reader = new \XMLReader();
        if (!@$reader->open($this->path, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new \RuntimeException('The file could not be opened.');
        }

        return $reader;
    }

    /** Moves the reader to <channel> and on the way verifies that this is a WordPress export without a DOCTYPE. */
    private function findChannel(\XMLReader $reader): void
    {
        while ($this->read($reader)) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->depth === 0) {
                $namespaceUri = (string) $reader->getAttribute('xmlns:wp');
                if ($reader->name !== 'rss' || !preg_match('#^https?://wordpress\.org/export/\d+\.\d+/?$#', $namespaceUri)) {
                    throw new \RuntimeException('This is not a WordPress export. In WordPress open Tools → Export, choose “All content” and download the .xml file.');
                }
            } elseif ($reader->depth === 1 && $reader->name === 'channel') {
                return;
            }
        }
        throw new \RuntimeException('The file contains no site content (the channel element is missing).');
    }

    /** One step of the reader; guards against DOCTYPE and XML errors. */
    private function read(\XMLReader $reader): bool
    {
        return $this->check($reader, @$reader->read());
    }

    /** Skips the whole element being read (including children) to its next sibling. */
    private function additional(\XMLReader $reader): bool
    {
        return $this->check($reader, @$reader->next());
    }

    private function check(\XMLReader $reader, bool $ok): bool
    {
        if ($ok && ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF || $reader->nodeType === \XMLReader::ENTITY)) {
            throw new \RuntimeException('The file contains a DOCTYPE or custom entities. A WordPress export never has them – the file was rejected for security reasons.');
        }
        $error = libxml_get_last_error();
        if (!$ok && $error !== false) {
            libxml_clear_errors();
            throw new \RuntimeException('The file is not valid XML – it is damaged or incomplete. Download the export from WordPress again. The error is on line:', $error->line);
        }

        return $ok;
    }

    /** The element being read as a standalone DOM node (only this one element – the rest of the file is not in memory). */
    private function node(\XMLReader $reader): \DOMElement
    {
        $node = @$reader->expand(new \DOMDocument());
        if (!$node instanceof \DOMElement) {
            $error = libxml_get_last_error();
            libxml_clear_errors();
            throw new \RuntimeException('The file is not valid XML – it is damaged or incomplete. Download the export from WordPress again. The error is on line:', $error !== false ? $error->line : 0);
        }
        foreach ($node->getElementsByTagName('*') as $child) {
            foreach ($child->childNodes as $n) {
                if ($n instanceof \DOMEntityReference) {
                    throw new \RuntimeException('The file contains a DOCTYPE or custom entities. A WordPress export never has them – the file was rejected for security reasons.');
                }
            }
        }

        return $node;
    }

    /**
     * Direct children of an element as an array "tag name => text".
     *
     * @return array<string, string>
     */
    private static function fields(\DOMElement $element): array
    {
        $field = [$element->nodeName => $element->textContent];
        foreach ($element->childNodes as $n) {
            if ($n instanceof \DOMElement) {
                $field[$n->nodeName] = $n->textContent;
            }
        }

        return $field;
    }

    /** Titles and names: without tags, entities converted to characters, without surrounding whitespace. */
    private static function plainText(string $text): string
    {
        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
