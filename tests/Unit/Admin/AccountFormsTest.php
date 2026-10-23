<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;

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
}
