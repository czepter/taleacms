<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class QrTest extends TestCase
{
    /** QR code (two-factor sign-in): own encoder without a library */
    public function testEncoder(): void
    {
        // Reed–Solomon: the known vector „HELLO WORLD“ version 1-M from the standard's tutorial (thonky.com, QR Code Tutorial)
        $this->assertSame([196, 35, 39, 119, 235, 215, 231, 226, 93, 23], \Talea\Core\Qr::correctionCodes([32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17], 10), 'Qr: Reed–Solomon correction codes (known vector)');
        $qrRows = fn (array $m): array => array_map(fn (array $r): string => implode('', array_map(fn (bool $b): string => $b ? '#' : '.', $r)), $m);
        $qr = $qrRows(\Talea\Core\Qr::matrix('Talea', 2));
        // format bits read from the matrix (column 8 and row 8 at the top left corner) = the standard's table for level M, mask 2: 101111001111100
        $qrFormat = '';
        foreach ([[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]] as [$y, $x]) {
            $qrFormat = ($qr[$y][$x] === '#' ? '1' : '0') . $qrFormat;
        }
        $this->assertSame('101111001111100', $qrFormat, 'Qr: format bits M/mask 2 per the standard\'s table');
        $this->assertSame([21, '#######..#.#..#######', '#######.#.#.#.#######'], [count($qr), $qr[0], $qr[6]], 'Qr: version 1 = 21 × 21 with the finder pattern');
        // matrices verified by an independent reader (Chrome BarcodeDetector) – guards that the encoder does not break
        $qrHash = fn (string $text): string => sha1(implode("\n", array_map(fn (array $r): string => implode('', array_map('intval', $r)), \Talea\Core\Qr::matrix($text))));
        $this->assertSame('21b4e92a0dd7dfcfe3d26161ea6baf9459312669', $qrHash('otpauth://totp/Acme%3Aadmin?secret=JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP&issuer=Acme&digits=6&period=30'), 'Qr: otpauth address (version 6) matches the verified matrix');
        $this->assertSame('f56c7dba89a16b5ef644e40e3b771ae4a0818ffd', $qrHash(str_repeat('Talea QR 0123456789 ', 12)), 'Qr: version 12 (version bits, more blocks) matches the verified matrix');
        $qrSvg = \Talea\Core\Qr::svg('otpauth://totp/x?secret=AB', 'QR <kód>'); // check-english: allow
        $this->assertTrue(str_contains($qrSvg, 'role="img" aria-label="QR &lt;kód&gt;"') && !str_contains($qrSvg, '<script'), 'Qr: SVG with a caption, without a script'); // check-english: allow
    }
}
