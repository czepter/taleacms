<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Auth;

/** The proof of identity in the session (#332): known kind, ten minutes. */
final class FreshProofTest extends TestCase
{
    public function testFreshProofNeedsAKnownKindWithinTenMinutes(): void
    {
        $proof = ['at' => 1000, 'kind' => 'password'];
        $this->assertTrue(Auth::freshProof($proof, ['password'], 1599));
        $this->assertFalse(Auth::freshProof($proof, ['password'], 1600), 'ten minutes are over');
        $this->assertFalse(Auth::freshProof($proof, ['passkey', 'backup'], 1100), 'a kind that is not asked for');
        $this->assertFalse(Auth::freshProof(null, ['password'], 1100));
        $this->assertFalse(Auth::freshProof('password', ['password'], 1100));
        $this->assertFalse(Auth::freshProof(['kind' => 'password'], ['password'], 1100), 'no time');
        $this->assertFalse(Auth::freshProof(['at' => 1000], ['password'], 1100), 'no kind');
        $this->assertFalse(Auth::freshProof(['at' => '1000', 'kind' => 'password'], ['password'], 1100), 'a time that is not a number');
    }
}
