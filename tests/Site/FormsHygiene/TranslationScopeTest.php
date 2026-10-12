<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The translation overview and translation_status show each person only what their own lists show (a news author without the Pages section). */
#[Group('site')]
final class TranslationScopeTest extends SiteTestCase
{
    use McpHelpers;

    public function testAnAuthorSeesNoHiddenPagesInTheTranslationStatus(): void
    {
        $this->site()->setting('additional_languages', 'de');
        $visible = $this->createPage(['title' => 'Scope visible', 'visible' => true]);
        $hidden = $this->createPage(['title' => 'Scope hidden', 'visible' => false]);
        $this->createPage(['title' => 'Scope visible DE', 'visible' => false, 'language' => 'de', 'translation_of' => $this->site()->publicId('pages', $visible)]);
        $this->createPage(['title' => 'Scope hidden DE', 'visible' => false, 'language' => 'de', 'translation_of' => $this->site()->publicId('pages', $hidden)]);

        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Scope Author', 'username' => 'scopeauthor', 'password' => $this->site()->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $token = 'talea_' . str_repeat('a', 48);
        $this->site()->exec('INSERT INTO tl_api_tokens (user_id, name, token_hash, created_at) SELECT user_id, ?, ?, NOW() FROM tl_users WHERE username = ?', ['scope', hash('sha256', $token), 'scopeauthor']);

        $asAdmin = array_column($this->site()->mcpResult('translation_status', ['status' => 'all', 'type' => 'page'])['items'] ?? [], null, 'id');
        $this->assertArrayHasKey($this->site()->publicId('pages', $hidden), $asAdmin, 'the administrator sees the hidden page in translation_status');

        $items = array_column($this->site()->mcpResult('translation_status', ['status' => 'all', 'type' => 'page'], token: $token)['items'] ?? [], null, 'id');
        $this->assertArrayNotHasKey($this->site()->publicId('pages', $hidden), $items, 'an author does not see the title of a hidden page');
        $this->assertStringNotContainsString('Scope hidden', (string) json_encode($items), 'no title of a hidden page leaks');

        $own = $items[$this->site()->publicId('pages', $visible)] ?? null;
        $this->assertNotNull($own, 'the author sees the published page');
        $this->assertSame('present', $own['translations']['de']['status'], 'a translation outside the view still counts for the status of the original');
        $this->assertNull($own['translations']['de']['id'] ?? null, 'but its id is left out');
    }
}
