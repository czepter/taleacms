<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UpdaterTest extends TestCase
{
    public function testProbeVerdict(): void
    {
        $ok = [200, 'TALEA-PROBE 9.9.9'];
        $this->assertSame([null, null, true, null, true, null, null, true], [
            \Talea\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
            \Talea\Core\Updater::probeVerdict(['probe' => [0, ''], 'home' => [0, ''], 'admin' => [0, '']], '9.9.9'),
            \Talea\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null,
            \Talea\Core\Updater::probeVerdict(['probe' => [200, 'TALEA-PROBE 2.7.0'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
            \Talea\Core\Updater::probeVerdict(['probe' => [500, ''], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9') !== null,
            \Talea\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [301, ''], 'admin' => [302, '']], '9.9.9'),
            \Talea\Core\Updater::probeVerdict(['probe' => [403, 'Access denied.'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
            \Talea\Core\Updater::probeVerdict(['probe' => [200, 'TALEA-PROBE 2.7.0'], 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null], '2.8: Updater::probeVerdict');
    }

    /** publisher signatures: several keys, key rotation and revocation */
    public function testPublisherSignatures(): void
    {
        if (function_exists('sodium_crypto_sign_keypair')) {
            $pair = fn (): array => (fn (string $p): array => [sodium_crypto_sign_secretkey($p), sodium_crypto_sign_publickey($p)])(sodium_crypto_sign_keypair());
            [[$skPrimary, $pkPrimary], [$skBackup, $pkBackup], [$skNew, $pkNew], [$skForeign]] = [$pair(), $pair(), $pair(), $pair()];
            $sign = fn (string $message, string $sk): string => base64_encode(sodium_crypto_sign_detached($message, $sk));
            $pub = tempnam(sys_get_temp_dir(), 'rs');
            file_put_contents($pub, "# note\n" . base64_encode($pkPrimary) . " operating\n\nnonsense-that-is-not-a-key\n" . base64_encode($pkBackup) . " backup 2026-09-20\n");
            $message = \Talea\Core\Signature::packageMessage('3.0.1', str_repeat('A', 64), false);
            $this->assertSame(2, count(\Talea\Core\Signature::keys($pub)), 'Signature::keys: two valid keys, notes and nonsense are skipped');
            $this->assertTrue(\Talea\Core\Signature::isValid($message, $sign($message, $skPrimary), $pub), 'Signature: the operating key is valid');
            $this->assertTrue(\Talea\Core\Signature::isValid($message, $sign($message, $skBackup), $pub), 'Signature: the backup key is valid too');
            $this->assertFalse(\Talea\Core\Signature::isValid($message, $sign($message, $skForeign), $pub), 'Signature: a foreign key is invalid');
            $this->assertFalse(\Talea\Core\Signature::isValid($message, 'AAAA', $pub), 'Signature: a damaged signature is invalid');
            $this->assertFalse(\Talea\Core\Signature::isValid(\Talea\Core\Signature::packageMessage('3.0.1', str_repeat('A', 64), true), $sign($message, $skPrimary), $pub), 'Signature: a regular release cannot be passed off as a security one');
            $this->assertSame('3.0.1|abc|regular', \Talea\Core\Signature::packageMessage('3.0.1', 'ABC', false), 'Signature: the package hash is compared case-insensitively');
            // a leak of the primary key: a release signed with the backup key brings a file without it and with a new primary key
            file_put_contents($pub, base64_encode($pkNew) . " operating\n" . base64_encode($pkBackup) . " backup\n");
            $this->assertFalse(\Talea\Core\Signature::isValid($message, $sign($message, $skPrimary), $pub), 'key rotation: a revoked key is no longer valid');
            $this->assertTrue(\Talea\Core\Signature::isValid($message, $sign($message, $skNew), $pub), 'key rotation: the new operating key is valid');
            file_put_contents($pub, '');
            $this->assertFalse(\Talea\Core\Signature::isValid($message, $sign($message, $skNew), $pub), 'Signature: with no keys nothing is valid');
            unlink($pub);
        }
        // Talea's publisher keys are created only before the first release (docs/RELEASING.md); until then the file may have no key, but it must be readable.
        $this->assertTrue(is_array(\Talea\Core\Signature::keys(TALEA_ROOT . '/system/update.pub')), 'system/update.pub is readable');
    }

    /** update: cleaning up files the new release no longer contains */
    public function testCleanupOfRemovedFiles(): void
    {
        $cleanup = sys_get_temp_dir() . '/talea-uklid-' . bin2hex(random_bytes(4));
        mkdir($cleanup . '/system/stare', 0775, true);
        mkdir($cleanup . '/media', 0775, true);
        foreach (['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', 'vlastni.php'] as $f) {
            file_put_contents($cleanup . '/' . $f, 'x');
        }
        $deleted = \Talea\Core\Updater::cleanUpObsolete($cleanup, ['index.php', 'system/stare/zrusene.php', 'system/zustava.php', 'media/foto.jpg', 'config.php', '../mimo.php'], ['index.php', 'system/zustava.php']);
        $this->assertSame(1, $deleted, 'Update: deletes only the file removed by the new release');
        $this->assertFalse(is_dir($cleanup . '/system/stare'), 'Update: the removed file and its empty folder are gone');
        $this->assertSame([true, true, true, true], [is_file($cleanup . '/media/foto.jpg'), is_file($cleanup . '/config.php'), is_file($cleanup . '/vlastni.php'), is_file($cleanup . '/system/zustava.php')], 'Update: protected paths and own files stay');
        // files that ride along only for the update (old classes, the alias file of 1.4–2.0) go after it – unless edited; nothing else
        mkdir($cleanup . '/system/src/Old', 0775, true);
        file_put_contents($cleanup . '/system/class-aliases.php', "<?php\nreturn [];\n");
        file_put_contents($cleanup . '/system/src/Old/Gone.php', 'edited');
        file_put_contents($cleanup . '/system/files.json', json_encode(['legacy' => ['system/class-aliases.php' => hash('sha256', "<?php\nreturn [];\n"),
            'system/src/Old/Gone.php' => hash('sha256', 'original'), 'system/zustava.php' => hash('sha256', 'x')]]));
        $this->assertSame([1, false, true, true], [\Talea\Core\Updater::cleanUpRemoved($cleanup), is_file($cleanup . '/system/class-aliases.php'),
            is_file($cleanup . '/system/src/Old/Gone.php'), is_file($cleanup . '/system/zustava.php')], 'Update: legacy files go after the update, edited and other files stay');
        exec('rm -rf ' . escapeshellarg($cleanup));
    }
}
