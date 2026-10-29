<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Talea\Admin\Account;

final class AccountFormsTest extends TestCase
{
    public function testEveryFormOfMyAccountPostsAnOperationTheHandlerKnows(): void
    {
        // the forms of My account named their operation in Czech (profil, heslo, totp_vypni, totp_potvrd) long after the handler // check-english: allow
        // was renamed: saving the details, a new password and turning two-factor sign-in on or off silently did nothing
        preg_match_all('/name="op" value="([a-z_]+)"/', (string) file_get_contents(TALEA_SYSTEM . '/views/admin/account.php'), $posted);
        preg_match_all("/case '([a-z_]+)':/", (string) file_get_contents(TALEA_SYSTEM . '/src/Admin/Account.php'), $handled);

        $this->assertNotSame([], $posted[1], 'the view names its operations');
        $this->assertSame([], array_values(array_diff(array_unique($posted[1]), $handled[1])), 'every operation a form of My account posts has a case in Account::handle()');
    }

    public function testEveryOperationAnswersOnTheScreenOfItsForm(): void
    {
        // a new token or the backup codes are shown only in the answer to the POST: on another screen they would be lost
        preg_match_all("/case '([a-z_]+)':/", (string) file_get_contents(TALEA_SYSTEM . '/src/Admin/Account.php'), $handled);
        $tabs = (new \ReflectionClassConstant(Account::class, 'OP_TABS'))->getValue();

        $this->assertSame([], array_values(array_diff($handled[1], array_keys($tabs), ['passkey_options', 'passkey_save'])), 'every operation but the passkey JSON has a screen');
        $this->assertSame([], array_values(array_diff($tabs, array_keys(Account::TABS))), 'every screen it names exists');
    }
}
