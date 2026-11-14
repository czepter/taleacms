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

    public function testTokenAndTwoFactorStartAskForThePasswordInADialog(): void
    {
        $view = (string) file_get_contents(TALEA_SYSTEM . '/views/admin/account.php');
        preg_match_all('~<dialog[^>]*data-reauth=""[^>]*>.*?</dialog>~s', $view, $dialogs);
        $inDialogs = implode("\n", $dialogs[0]);
        $outside = preg_replace('~<dialog.*?</dialog>~s', '', $view);

        // the password row (or, for a passkey-only account, the backup code; or the passkey) is the partial admin/fresh_proof
        foreach (['start', 'token'] as $id) {
            $this->assertStringContainsString("'admin/fresh_proof', ['app' => \$app, 'id' => '$id']", $inDialogs, "the $id confirmation sits in a dialog[data-reauth]");
            $this->assertStringNotContainsString("'id' => '$id'", (string) $outside, "and is not a visible row");
        }
        $this->assertStringContainsString('name="op" value="token_new"', $inDialogs, 'the dialog button keeps the operation');
    }

    /** What My account hands its view (the keys written next to the passkey and sign-in data) is read there: nothing is computed for nothing. */
    public function testEveryValueHandedToTheAccountViewIsRead(): void
    {
        $root = dirname(__DIR__, 3);
        $source = (string) file_get_contents($root . '/system/src/Admin/Account.php');
        $views = (string) file_get_contents($root . '/system/views/admin/account.php');
        foreach (glob($root . '/system/views/admin/account/*.php') ?: [] as $partial) {
            $views .= (string) file_get_contents($partial);
        }
        $this->assertSame(1, preg_match("/'keys' =>.*?'remembered' =>/s", $source, $block));
        preg_match_all("/'(\\w+)' =>/", $block[0], $keys);
        foreach (array_unique($keys[1]) as $key) {
            $this->assertSame(1, preg_match('/\\$' . $key . '\\b/', $views), "\$$key is passed to the view and never read");
        }
    }
}
