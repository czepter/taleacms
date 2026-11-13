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

    public function __construct(private readonly Db $db, private readonly ImageDownloader $downloader, private readonly int $author, private readonly string $base)
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
     * Brings the documents of one piece of HTML into Media and points the links at them (at most PER_PAGE here, PER_IMPORT in all).
     *
     * @param array<string, mixed> $state the import state: web (the old site's address), domain, result.files
     */
    public function bring(string $html, array &$state): string
    {
        $documents = array_slice(self::documentLinks($html, (string) $state['web']), 0, self::PER_PAGE);
        $moved = [];
        foreach ($documents as $address) {
            if (($state['result']['files'] ?? 0) >= self::PER_IMPORT) {
                break;
            }
            if (($path = $this->file($address, $state)) !== null) {
                $moved[$address] = $this->base . '/' . $path;
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
