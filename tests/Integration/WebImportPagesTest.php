<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\ImageDownloader;
use Talea\Core\Settings;
use Talea\Core\WebImport;
use Talea\Tests\Support\DatabaseTestCase;

/** The page part of the website import (Core\WebImport::importHtml): what an imported page remembers, starter pages, nested addresses. */
#[CoversClass(WebImport::class)]
final class WebImportPagesTest extends DatabaseTestCase
{
    private const string PAGE = '<html><head><title>About us | Acme</title></head><body><main><h1>About us</h1><p>We have been building boats for thirty years and know every harbour on the coast.</p></main></body></html>';

    /** @return array{0: WebImport, 1: array<string, mixed>} */
    private function importer(array $options = ['images' => false, 'redirects' => true, 'news' => false]): array
    {
        $db = $this->db();
        $db->insert('settings', ['name' => 'extensions', 'value' => 'redirects,news,enquiries']);

        return [new WebImport($db, new Settings($db), 0, new ImageDownloader('https://old.example', true)), WebImport::newState('https://old.example', $options)];
    }

    public function testAnImportedPageIsHiddenRemembersItsOldAddressAndWaitsForItsLayout(): void
    {
        [$import, $state] = $this->importer();
        $import->importHtml('https://old.example/about-us/', self::PAGE, $state);

        $row = $this->db()->one("SELECT slug, visible, import_source, layout_pending FROM {pages} WHERE slug = 'about-us'");
        $this->assertNotNull($row);
        $this->assertSame(0, (int) $row['visible']);
        $this->assertSame('https://old.example/about-us/', $row['import_source']);
        $this->assertSame(1, (int) $row['layout_pending']);
        $this->assertSame(1, $state['result']['pages']);
    }
}
