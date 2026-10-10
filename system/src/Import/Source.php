<?php

declare(strict_types=1);

namespace Talea\Import;

/**
 * A structured importer from another system (3.0): Ghost, Blogger and the ones that follow (Joomla, Drupal, Webflow…).
 *
 * A source only READS its export file and yields normalised records; it never touches the database, downloads nothing
 * and trusts nothing in the file. Everything else – the preview, the mapping chosen by the administrator, writing news
 * items, pages, categories, tags and redirects, downloading images, re-running without duplicates – is done once in
 * Import\Batch for every source. So a new system needs exactly: one class implementing this interface, its name in
 * Import\Sources::ALL and a fixture with a unit test (tools/fixtures/<key>-export.*, tools/unit-tests.php).
 *
 * The contract:
 *  - the constructor gets the path of the file on disk and the address of the old site as the administrator entered it
 *    (empty when the file itself names the site; a source whose export does not carry the address – Ghost – uses it to
 *    make image and old URLs absolute);
 *  - verify() throws \RuntimeException with an English message for the user when the file is not an export of this
 *    system, is too large or is damaged; it is called before the file is accepted and must be quick (read the start);
 *  - read() yields Author, Category, Tag and Post records (Media records are optional – images in posts are found in
 *    their HTML anyway), each keyed from zero in a stable order, so the batch runner can skip $skip records and continue
 *    where the previous request stopped. Authors, categories and tags must come BEFORE the first post that refers to them
 *    (yield them up front, or on first sight). A record's key is the identifier in the source (post number, tag id…) and
 *    must be stable between runs – tl_import_map uses it to skip what was imported earlier;
 *  - Post::$html is the raw HTML of the source; the runner cleans it (Core\WpContent::sanitize – allowed tags only,
 *    no scripts, frames, styles or event handlers). URLs stay as they are in the export; images are downloaded only from
 *    the old site's domain (imagesFromAnyHost() = false) or from any public host (true – for systems that keep images on
 *    a CDN, like Blogger). Post::$warnings names what the source could not convert ('block:image', 'block:gallery'…);
 *    the preview counts them;
 *  - notes() are the honest footnotes of the preview: what this source skips or assumes (comments, routes, templates).
 */
interface Source
{
    /** Identifier used in file names (<key>-<name>.<ext>), tl_import_map (<key>:<domain>) and the admin form. */
    public static function key(): string;

    /** The system's name as shown to the administrator (not translated). */
    public static function name(): string;

    /**
     * Allowed file extensions of the export, lowercase; the first one is used for uploaded files.
     *
     * @return non-empty-list<string>
     */
    public static function extensions(): array;

    /** Where the export comes from, in English (goes through t()): "In X open Settings → Export…". */
    public static function hint(): string;

    public function __construct(string $path, string $siteUrl = '');

    /**
     * Quick verification before the file is accepted: this system's export, not too large, well formed at the start.
     *
     * @throws \RuntimeException with an English message for the user
     */
    public function verify(): void;

    /**
     * The old site as the file names it. 'url' is empty when the export does not carry the address.
     *
     * @return array{name: string, url: string}
     */
    public function site(): array;

    /** Images in posts may come from any public host (CDN), not only from the old site's domain. */
    public function imagesFromAnyHost(): bool;

    /**
     * Footnotes for the preview: what is skipped or assumed. English, each goes through t().
     *
     * @return list<string>
     */
    public function notes(): array;

    /**
     * The records of the file, keyed by their order from zero (authors, categories and tags before the posts that use them).
     *
     * @param int $skip how many records to skip from the start (continuing the previous batch)
     * @return \Generator<int, Author|Category|Tag|Post|Media>
     * @throws \RuntimeException when the file turns out to be damaged
     */
    public function read(int $skip = 0): \Generator;
}
