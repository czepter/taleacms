<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\Auth;

/** The list conditions shared by the list tools and the translation overview. */
final class AuthListScopeTest extends TestCase
{
    /** @param list<string> $modules */
    private function auth(int $type, array $modules): Auth
    {
        $auth = (new \ReflectionClass(Auth::class))->newInstanceWithoutConstructor();
        foreach (['user' => ['user_id' => 7, 'admin' => $type], 'modules' => $modules] as $name => $value) {
            (new \ReflectionProperty(Auth::class, $name))->setValue($auth, $value);
        }

        return $auth;
    }

    public function testAdministratorAndEditorAreNotLimitedByTheSections(): void
    {
        $admin = $this->auth(Auth::ADMIN, []);
        $this->assertSame('', $admin->pageListScope() . $admin->newsListScope() . $admin->collectionItemListScope());
    }

    public function testAnAuthorWithoutSectionsSeesOnlyPublishedAndOwn(): void
    {
        $author = $this->auth(Auth::AUTHOR, []);
        $this->assertSame(' AND p.visible = TRUE', $author->pageListScope('p.'));
        $this->assertSame(' AND c.author_id IN (7) AND c.visible = TRUE AND c.published_at <= NOW()', $author->newsListScope('c.'));
        $this->assertSame(' AND visible = TRUE', $author->collectionItemListScope());
    }

    public function testASectionLiftsItsOwnLimitOnly(): void
    {
        $author = $this->auth(Auth::AUTHOR, ['news']);
        $this->assertSame(' AND author_id IN (7)', $author->newsListScope(), 'an author with the News section still sees only their own items');
        $this->assertSame(' AND visible = TRUE', $author->pageListScope());
        $editor = $this->auth(Auth::EDITOR, ['pages']);
        $this->assertSame('', $editor->pageListScope());
        $this->assertSame(' AND visible = TRUE AND published_at <= NOW()', $editor->newsListScope());
    }
}
