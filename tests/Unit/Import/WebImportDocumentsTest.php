<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Import;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Talea\Core\WebImportFiles;

#[CoversClass(WebImportFiles::class)]
final class WebImportDocumentsTest extends TestCase
{
    public function testDocumentsAreDownloadedFromTheOldSitesOwnHostOnly(): void
    {
        $downloader = WebImportFiles::downloader('https://www.old.example');
        $this->assertSame([true, false, false], [$downloader->isAllowedUrl('https://old.example/a.pdf'), $downloader->isAllowedUrl('https://cdn.other.example/a.pdf'),
            $downloader->isAllowedUrl('https://www.old.example.evil.example/a.pdf')], 'a redirect step to another host is refused: every step is checked by isAllowedUrl');
    }
}
