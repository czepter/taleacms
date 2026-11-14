<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Talea\Admin\Modules\Settings;
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

    public function testWeakeningTwoFactor(): void
    {
        $this->assertTrue(Settings::weakensTwoFactor('everyone', 'admins'));
        $this->assertTrue(Settings::weakensTwoFactor('admins', ''));
        $this->assertTrue(Settings::weakensTwoFactor('everyone', ''));
        $this->assertFalse(Settings::weakensTwoFactor('', 'admins'));
        $this->assertFalse(Settings::weakensTwoFactor('admins', 'everyone'));
        $this->assertFalse(Settings::weakensTwoFactor('admins', 'admins'));
        $this->assertFalse(Settings::weakensTwoFactor('', ''));
    }

    public function testTheEscapeConstant(): void
    {
        putenv('TALEA_PASSKEY_ONLY_OFF=1');
        try {
            $this->assertTrue(Auth::passkeyOnlyOff());
        } finally {
            putenv('TALEA_PASSKEY_ONLY_OFF');
        }
        $this->assertFalse(Auth::passkeyOnlyOff());
    }

    /** The documented form of the escape in config.php can be read again and again without a warning. */
    #[RunInSeparateProcess]
    public function testTheGuardedDefineSurvivesBeingReadTwice(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($file, "<?php\ndefined('TALEA_PASSKEY_ONLY_OFF') || define('TALEA_PASSKEY_ONLY_OFF', true);\nreturn [];\n");
        $warnings = [];
        set_error_handler(static function (int $no, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        try {
            require $file;
            require $file;
        } finally {
            restore_error_handler();
            unlink($file);
        }
        $this->assertSame([], $warnings);
        $this->assertTrue(Auth::passkeyOnlyOff(), 'and the constant switches passkey-only off');
    }
}
