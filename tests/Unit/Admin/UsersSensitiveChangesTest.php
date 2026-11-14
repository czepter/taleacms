<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Talea\Admin\Modules\Roles;
use Talea\Admin\Modules\Users;

/** Which saves of a person need a fresh proof of the administrator (#332). */
final class UsersSensitiveChangesTest extends TestCase
{
    /** @return list<string> */
    private function allSections(): array
    {
        return array_keys(Roles::configurable());
    }

    public function testWhichChangesAreSensitive(): void
    {
        $author = ['admin' => 0, 'email' => 'a@example.test'];
        $admin = ['admin' => 2, 'email' => 'a@example.test'];
        $editor = ['admin' => 1, 'email' => 'a@example.test'];

        $this->assertSame([], Users::sensitiveChanges(null, ['admin' => 0, 'email' => 'new@example.test'], false, false, ['news']), 'a new author');
        $this->assertSame(['administrator'], Users::sensitiveChanges(null, ['admin' => 2, 'email' => ''], false, false, $this->allSections()), 'a new administrator');
        $this->assertSame(['administrator'], Users::sensitiveChanges($editor, ['admin' => 2, 'email' => 'a@example.test'], false, false, $this->allSections(), $this->allSections()), 'an editor becomes an administrator');
        $this->assertSame([], Users::sensitiveChanges($admin, ['admin' => 2, 'email' => 'a@example.test'], false, false, []), 'an administrator stays one');
        $this->assertSame(['password'], Users::sensitiveChanges($author, ['admin' => 0, 'email' => 'a@example.test'], true, false, ['news'], ['news']), 'a new password for an existing person');
        $this->assertSame(['two_factor'], Users::sensitiveChanges($author, ['admin' => 0, 'email' => 'a@example.test'], false, true, ['news'], ['news']));
        $this->assertSame([], Users::sensitiveChanges($author, ['admin' => 0, 'email' => 'A@Example.test'], false, false, ['news'], ['news']), 'a change of case only is no change');
        $this->assertSame(['email'], Users::sensitiveChanges($author, ['admin' => 0, 'email' => 'b@example.test'], false, false, ['news'], ['news']));
        $this->assertSame(['every_section'], Users::sensitiveChanges($author, ['admin' => 0, 'email' => 'a@example.test'], false, false, $this->allSections(), ['news']), 'every section for an author');
        $this->assertSame([], Users::sensitiveChanges($editor, ['admin' => 1, 'email' => 'a@example.test'], false, false, $this->allSections(), $this->allSections()), 'an editor already has every section');
        $this->assertSame(['every_section'], Users::sensitiveChanges(null, ['admin' => 1, 'email' => ''], false, false, $this->allSections()), 'a new editor');
    }
}
