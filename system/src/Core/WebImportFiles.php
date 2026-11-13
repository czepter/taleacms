<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * The documents an old page links to (a price list, a brochure) come over into Media with the website import: only files of the old site's
 * own domain – a CDN may serve images, not documents – through Core\ImageDownloader with its rules (public addresses, pinned connection,
 * redirects, size and time limits) and saved by Core\Files (allowed extension, real content). The link on the page then points at Media;
 * a document that cannot be brought over keeps its old address.
 */
final class WebImportFiles
{
    public const int PER_PAGE = 10;
    public const int PER_IMPORT = 200;

    /** What counts as a document (a subset of Core\Files::FILE_EXTENSIONS: no archives, audio, video or fonts). */
    private const array DOCUMENTS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv'];

    /** @var int documents this page may still bring over (one budget for its content and all its widgets: one instance is one page) */
    private int $left = self::PER_PAGE;

    /** @var array<string, string> address => the Media address, for the documents of this page already dealt with */
    private array $moved = [];

    /** @param (\Closure(): bool)|null $mayDownload false = this step has no time left: the page pauses (WebImportPaused) and continues in the next one */
    public function __construct(private readonly Db $db, private readonly ImageDownloader $downloader, private readonly int $author, private readonly string $base, private readonly ?\Closure $mayDownload = null)
    {
    }

    /**
     * The addresses of the documents a page links to on the old site's own domain.
     *
     * @return list<string> absolute addresses, in the order of the page
     */
    public static function documentLinks(string $html, string $origin): array
    {
        $domain = ImageDownloader::domainFromUrl($origin);
        $found = [];
        foreach (self::links($html) as $href) {
            $absolute = WebImport::absolute($href, $origin . '/');
            if ($absolute !== '' && ImageDownloader::domainFromUrl($absolute) === $domain && in_array(strtolower(pathinfo((string) parse_url($absolute, PHP_URL_PATH), PATHINFO_EXTENSION)), self::DOCUMENTS, true)) {
                $found[$absolute] = true;
            }
        }

        return array_keys($found);
    }

    /** @return list<string> */
    private static function links(string $html): array
    {
        if (stripos($html, '<a') === false) {
            return [];
        }
        $hrefs = [];
        Html::transform($html, function (\Dom\HTMLElement $body) use (&$hrefs): void {
            foreach ($body->querySelectorAll('a[href]') as $a) {
                $href = trim((string) $a->getAttribute('href'));
                if (!preg_match('#^(mailto|tel|javascript|data):#i', $href)) {
                    $hrefs[] = $href;
                }
            }
        });

        return $hrefs;
    }

    /**
     * Brings the documents of one piece of HTML into Media and points the links at them (at most PER_PAGE for the whole page – call this
     * for the content and every widget on the same instance – and PER_IMPORT in all).
     *
     * @param array<string, mixed> $state the import state: web (the old site's address), domain, result.files
     * @throws WebImportPaused when this step has no time left for another download
     */
    public function bring(string $html, array &$state): string
    {
        $moved = [];
        foreach (self::documentLinks($html, (string) $state['web']) as $address) {
            if (array_key_exists($address, $this->moved)) {
                if ($this->moved[$address] !== '') {
                    $moved[$address] = $this->moved[$address];
                }
                continue;
            }
            if ($this->left <= 0 || ($state['result']['files'] ?? 0) >= self::PER_IMPORT) {
                continue;
            }
            $this->left--;
            $path = $this->file($address, $state);
            $this->moved[$address] = $path === null ? '' : $this->base . '/' . $path;
            if ($path !== null) {
                $moved[$address] = $this->moved[$address];
            }
        }
        if ($moved === []) {
            return $html;
        }

        return Html::transform($html, function (\Dom\HTMLElement $body) use ($moved, $state): void {
            foreach (iterator_to_array($body->querySelectorAll('a[href]')) as $a) {
                $absolute = WebImport::absolute(trim((string) $a->getAttribute('href')), $state['web'] . '/');
                if (isset($moved[$absolute])) {
                    $a->setAttribute('href', $moved[$absolute]);
                }
            }
        });
    }

    /**
     * One document into Media (a document brought over before is reused); null when it cannot be.
     *
     * @param array<string, mixed> $state
     */
    private function file(string $address, array &$state): ?string
    {
        $source = 'web:' . mb_substr((string) $state['domain'], 0, 36);
        $key = sha1($address);
        $known = $this->db->value("SELECT local_id FROM {import_map} WHERE source = ? AND type = 'file' AND source_id = ?", [$source, $key]);
        if ($known !== null) {
            return (int) $known === 0 ? null : (string) $this->db->value('SELECT image_path FROM {media} WHERE media_id = ?', [(int) $known]);
        }
        if ($this->mayDownload !== null && !($this->mayDownload)()) {
            throw new WebImportPaused();
        }
        $temporary = WpFile::folder() . '/web-file-' . bin2hex(random_bytes(6)) . '.tmp';
        try {
            file_put_contents($temporary, $this->downloader->download($address, false));
            $saved = Files::saveFile($temporary, basename((string) parse_url($address, PHP_URL_PATH)));
            $id = $this->db->insert('media', $saved + ['owner_id' => $this->author, 'created_at' => date('Y-m-d H:i:s')]);
            $this->map($source, $key, $id);
            $state['result']['files'] = (int) ($state['result']['files'] ?? 0) + 1;

            return $saved['image_path'];
        } catch (\RuntimeException) {
            $this->map($source, $key, 0);

            return null;
        } finally {
            @unlink($temporary);
        }
    }

    private function map(string $source, string $key, int $id): void
    {
        $this->db->upsert('import_map', ['source' => $source, 'type' => 'file', 'source_id' => $key, 'local_id' => $id], ['source', 'type', 'source_id']);
    }
}
