<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Talea\Core\RemoteBackup;

final class RemoteBackupFtpTest extends TestCase
{
    /** ftp_ssl_connect() accepts any certificate; the cURL transfer must require TLS and verify the certificate and the name */
    public function testTheFtpsTransferVerifiesTheServerCertificate(): void
    {
        $file = fopen('php://memory', 'rb');
        $options = RemoteBackup::ftpOptions('user', 'secret', $file, 10);
        fclose($file);
        $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        $this->assertSame(CURLUSESSL_ALL, $options[CURLOPT_USE_SSL], 'plain FTP is never used, not even for the login');
        $this->assertSame(CURLPROTO_FTP, $options[CURLOPT_PROTOCOLS]);
        $this->assertSame('user:secret', $options[CURLOPT_USERPWD]);
    }

    public function testTheUrlKeepsTheFolderAndEncodesThePath(): void
    {
        $this->assertSame('ftp://nas.example/backups/talea/media/2026/a%20b.jpg', RemoteBackup::ftpUrl('nas.example', '/backups/talea/', 'media/2026/a b.jpg'));
        $this->assertSame('ftp://nas.example/talea-1.sql.gz', RemoteBackup::ftpUrl('nas.example', '', 'talea-1.sql.gz'));
    }
}
