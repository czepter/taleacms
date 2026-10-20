<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Files;

final class FilesTest extends TestCase
{
    /** attachments */
    public function testAttachments(): void
    {
        $this->assertTrue(Files::isAttachment('Zpráva.PDF'), 'Files: a PDF is an attachment'); // check-english: allow
        $this->assertFalse(Files::isAttachment('shell.php'), 'Files: PHP is not an attachment');
        $this->assertFalse(Files::isAttachment('shell.pdf.php'), 'Files: a double extension');
        $this->assertFalse(Files::isAttachment('x.svg') || Files::isAttachment('x.html'), 'Soubory: SVG a HTML ne'); // check-english: allow
        $this->assertSame('2 kB', Files::size(1536), 'Soubory: velikost'); // check-english: allow
        $this->assertSame('5,0 MB', \Talea\Core\Language::runWith('cs', fn () => Files::size(5 * 1048576)), 'Files::size: MB, Czech decimal comma');
    }
}
