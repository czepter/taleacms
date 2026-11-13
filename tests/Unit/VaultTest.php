<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Vault;

/** The secretbox that seals stored secrets (#335). */
final class VaultTest extends TestCase
{
    public function testSealAndOpen(): void
    {
        $key = Vault::derive('a-secret-of-at-least-thirty-two-characters');
        $sealed = Vault::seal($key, 'smtp-password');
        $this->assertNotSame('smtp-password', $sealed);
        $this->assertNotSame($sealed, Vault::seal($key, 'smtp-password'), 'every seal has its own nonce');
        $this->assertSame('smtp-password', Vault::open($key, $sealed));
        $this->assertSame('', Vault::open($key, Vault::seal($key, '')), 'an empty value seals too');
    }

    public function testWrongKeyAndGarbageOpenNothing(): void
    {
        $key = Vault::derive('one-secret-one-secret-one-secret-1');
        $sealed = Vault::seal($key, 'x');
        $this->assertNull(Vault::open(Vault::derive('another-secret-another-secret-12'), $sealed), 'another key');
        $this->assertNull(Vault::open($key, null));
        $this->assertNull(Vault::open($key, 'not base64 at all!'));
        $this->assertNull(Vault::open($key, base64_encode('short')), 'too short for a nonce');
        $this->assertNull(Vault::open($key, substr($sealed, 0, -4) . 'AAAA'), 'a damaged box');
    }

    public function testDeriveIsThirtyTwoStableBytes(): void
    {
        $this->assertSame(32, strlen(Vault::derive('s')));
        $this->assertSame(Vault::derive('s'), Vault::derive('s'));
        $this->assertNotSame(Vault::derive('s'), Vault::derive('t'));
    }
}
