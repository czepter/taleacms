<?php

declare(strict_types=1);

namespace Talea\Tests\Site\EnglishInstall;

use Talea\Tests\Site\Support\CzechCheck;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** The German admin (formal "Sie" by choice, then the default informal "du") of the business starter: the same screens, no Czech. */
#[Group('site')]
final class BusinessAdminGermanTest extends SiteTestCase
{
    use BusinessInstall;
    use CzechCheck;

    public function testFormalGermanAdmin(): void
    {
        $this->site()->exec("UPDATE tl_users SET language = 'de', register = 'formal' WHERE username = 'admin'");
        $last = null;
        foreach ($this->adminScreens() as $query) {
            $last = $this->assertCzechFree("/admin.php?$query", 200, $this->site()->admin(), true, 'German admin');
        }
        $this->assertStringContainsString('Einstellungen', (string) $last?->body, 'German admin: the settings screen is in German');
        $this->assertStringContainsString('So funktioniert es', (string) $last?->body, 'German admin: the guide link is in German');
    }

    #[Depends('testFormalGermanAdmin')]
    public function testInformalGermanAdmin(): void
    {
        $this->site()->exec("UPDATE tl_users SET register = '' WHERE username = 'admin'"); // no choice = du
        $last = null;
        foreach ($this->adminScreens() as $query) {
            $last = $this->assertCzechFree("/admin.php?$query", 200, $this->site()->admin(), true, 'German informal admin');
            if (str_contains($query, 'action=builder')) {
                $this->assertStringContainsString('admin-de-du.js', (string) $last->body, "informal German admin: the builder ($query) loads the script overlay too");
            }
        }
        $this->assertStringContainsString('admin-de-du.js', (string) $last?->body, 'informal German admin: the script overlay admin-de-du.js is loaded');
        $mail = $this->site()->admin()->get('/admin.php?module=settings&tab=mail')->body;
        $this->assertDoesNotMatchRegularExpression('/(Geben|Wählen|Tragen|Speichern|Verwenden|Prüfen|Klicken) Sie /', $mail, 'informal German admin: a formal imperative (Sie) is still in the mail settings');
    }

    #[Depends('testInformalGermanAdmin')]
    public function testEditInPlaceFollowsTheAdminLanguage(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_categories (name, slug, description, language) VALUES ('Stories', 'stories', '', '')");
        $site->exec("INSERT INTO tl_news (slug, title, intro, text, category_id, published_at, visible, language) VALUES ('first-post', 'First post', '', '<p>Hello</p>', ?, '2026-01-01 10:00:00', TRUE, '')",
            [(int) $site->value("SELECT category_id FROM tl_categories WHERE slug = 'stories'")]);
        $body = $site->admin()->get('/news/first-post?edit=text')->body;
        $this->assertStringContainsString('tl-edit-text', $body, 'edit in place: the form is shown');
        $this->assertStringContainsString('admin-de.js', $body, 'edit in place: the editor toolbar gets the German script texts of the administration');
        $this->assertStringContainsString('admin-de-du.js', $body, 'edit in place: and the informal overlay (du)');
        $this->assertStringContainsString('>Titel</label>', $body, 'edit in place: the form labels are in the language of the administration, not of the (English) site');
    }
}
