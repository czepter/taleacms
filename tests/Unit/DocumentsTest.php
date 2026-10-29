<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DocumentsTest extends TestCase
{
    /** 2.11: document library (Core\Documents) – version-change detection, the download token, file paths */
    public function testDocumentLibrary(): void
    {
        $documentsCollection = ['preset' => 'documents', 'detail' => 1, 'fields' => [['key' => 'file', 'label' => 'File', 'type' => 'file'], ['key' => 'version', 'label' => 'Version', 'type' => 'text']]];
        $this->assertSame(['/media/cenik-v1.pdf', '1.0'], \Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/cenik-v1.pdf', 'version' => '1.0'], ['file' => '/media/cenik-v2.pdf', 'version' => '2.0']), '2.11 Documents::replacedFile – a changed file keeps the previous file with its version');
        $this->assertSame([null, null, null, null], [
            \Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/a.pdf', 'version' => '1'], ['file' => '/media/a.pdf', 'version' => '2']),
            \Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '', 'version' => ''], ['file' => '/media/a.pdf']),
            \Talea\Core\Documents::replacedFile(['preset' => '', 'fields' => $documentsCollection['fields']], ['file' => '/media/a.pdf'], ['file' => '/media/b.pdf']),
            \Talea\Core\Documents::replacedFile(['preset' => 'documents', 'fields' => [['key' => 'file', 'label' => 'File', 'type' => 'text']]], ['file' => '/media/a.pdf'], ['file' => '/media/b.pdf']),
        ], '2.11 Documents::replacedFile – the same file, a first file, a collection without the preset and a file field of another type keep nothing');
        $this->assertSame([['/media/a.pdf', '3'], ['/media/a.pdf', '']], [
            \Talea\Core\Documents::replacedFile($documentsCollection, ['file' => '/media/a.pdf', 'version' => '3'], ['file' => '']),
            \Talea\Core\Documents::replacedFile(['preset' => 'documents', 'fields' => [$documentsCollection['fields'][0]]], ['file' => '/media/a.pdf', 'version' => '3'], ['file' => '/media/b.pdf'])], '2.11 Documents::replacedFile – a removed file is kept too; without a version field the version is empty');
        $documentKey = str_repeat('k', 64);
        $documentToken = \Talea\Core\Documents::token($documentKey, '/media/docs/cenik-2026.pdf', 1_900_000_000);
        $tamperedSignature = substr($documentToken, 0, -1) . (substr($documentToken, -1) === 'a' ? 'b' : 'a');
        $otherFile = (string) preg_replace('/^(\d+)\.[^.]+/', '$1.' . rtrim(strtr(base64_encode('/media/other.pdf'), '+/', '-_'), '='), $documentToken);
        $this->assertSame(['/media/docs/cenik-2026.pdf', null, null, null, null, null], [
            \Talea\Core\Documents::verifyToken($documentKey, $documentToken, 1_899_999_999), \Talea\Core\Documents::verifyToken($documentKey, $documentToken, 1_900_000_001),
            \Talea\Core\Documents::verifyToken($documentKey, $tamperedSignature, 1_899_999_999), \Talea\Core\Documents::verifyToken($documentKey, $otherFile, 1_899_999_999),
            \Talea\Core\Documents::verifyToken(str_repeat('x', 64), $documentToken, 1_899_999_999), \Talea\Core\Documents::verifyToken($documentKey, 'nonsense', 1_899_999_999),
        ], '2.11 Documents::token – a valid token gives its file; expired, a changed signature, a changed file, another key and nonsense are refused');
        $this->assertSame([null, null, 'https://files.example/a.pdf', 1], [
            \Talea\Core\Documents::verifyToken($documentKey, \Talea\Core\Documents::token($documentKey, '/config.php', 1_900_000_000), 1_899_999_999),
            \Talea\Core\Documents::verifyToken($documentKey, \Talea\Core\Documents::token($documentKey, '/media/../config.php', 1_900_000_000), 1_899_999_999),
            \Talea\Core\Documents::verifyToken($documentKey, \Talea\Core\Documents::token($documentKey, 'https://files.example/a.pdf', 1_900_000_000), 1_899_999_999),
            preg_match('/^\d{10}\.[A-Za-z0-9_-]+\.[a-f0-9]{64}$/', $documentToken)], '2.11 Documents::token – a signed token still serves only files from Media or https');
        $versionsHtml = \Talea\Core\Documents::versionsHtml([['file' => 'media/docs/cenik-v1.pdf', 'version' => '1.0 <b>', 'replaced_at' => '2026-03-01 10:00:00', 'replaced_by' => 'x']], '/web');
        $this->assertSame([true, true, true, true, false, true, ''], [str_starts_with($versionsHtml, '<h2>'), str_contains($versionsHtml, 'href="/web/media/docs/cenik-v1.pdf"'), str_contains($versionsHtml, 'cenik-v1.pdf · '), str_contains($versionsHtml, '&lt;b&gt;'), str_contains($versionsHtml, '<b>'),
                str_contains($versionsHtml, format_date('2026-03-01 10:00:00')), \Talea\Core\Documents::versionsHtml([], '/web')], '2.11 Documents::versionsHtml – a heading, a link to the file with the installation folder, the version and the day, everything escaped; nothing without versions');
        $this->assertSame(['https://x.example/a.pdf', '/media/a.pdf', '/web/media/a.pdf', 'Ceník 2026.pdf'], [\Talea\Core\Documents::filePath('https://x.example/a.pdf', '/web'), \Talea\Core\Documents::filePath('/media/a.pdf', '/web'), // check-english: allow
            \Talea\Core\Documents::filePath('media/a.pdf', '/web'), \Talea\Core\Documents::fileName('/media/docs/Cen%C3%ADk%202026.pdf')], '2.11 Documents::filePath and fileName – an https address and an absolute path stay, a path in Media gets the installation folder'); // check-english: allow
        $this->assertSame(['/media/x.pdf', '', ''], [\Talea\Core\Documents::gatedFile(['send_file' => '/media/x.pdf']), \Talea\Core\Documents::gatedFile(['send_file' => '/media/../config.php']), \Talea\Core\Documents::gatedFile([])], '2.11 Documents::gatedFile – a file from Media, never a path out of it');
        $documentsTemplate = (string) json_encode(\Talea\Builder\Presets::itemTemplate((array) \Talea\Builder\Presets::get('documents'), \Talea\Builder\Collections::sanitizeFields([['key' => 'file', 'label' => 'File', 'type' => 'file']])));
        $this->assertSame([true, true, 'Document expires soon', true, 'send_file'], [str_contains($documentsTemplate, '{{latest}}'), str_contains($documentsTemplate, '{{versions}}'), \Talea\Core\Audit::KINDS['document'], in_array('download', \Talea\Front\Kernel::reservedSlugs(), true), 'send_file'], '2.11 documents preset: the item template downloads through the stable address and lists the previous versions; the kind, the address and the English option name are known');
    }
}
