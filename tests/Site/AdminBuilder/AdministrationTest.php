<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/** The administration: sign-in, screens, settings hubs, news author restrictions (was: section 5 "administrace"; starts anonymous). */
#[Group('site')]
final class AdministrationTest extends SiteTestCase
{
    protected static function siteOptions(): array
    {
        return ['login' => false];
    }

    public function testSignInProtections(): void
    {
        $admin = $this->site()->admin();
        $this->assertPage('/admin.php?action=password', 200, 'Send link', message: 'forgotten password form');
        $this->assertPage('/admin.php?action=password&token=' . str_repeat('a', 64), 400, 'The link has expired or has already been used', message: 'forgotten password: invalid link');
        $this->assertPage('/admin.php', 200, 'Password', message: 'without signing in there is only the login');

        $csrf = $admin->get('/admin.php')->csrf();
        $this->assertSame(401, $admin->post('/admin.php', ['_csrf' => $csrf, 'username' => 'admin', 'password' => 'wrong-password-123'])->status, 'wrong password refused');
        $this->assertSame(400, $admin->post('/admin.php', ['username' => 'admin', 'password' => $this->site()->password])->status, 'POST without CSRF refused');
        $admin->post('/admin.php', ['_csrf' => $csrf, 'username' => 'admin', 'password' => $this->site()->password]);
    }

    #[Depends('testSignInProtections')]
    public function testOverviewAndScreens(): void
    {
        $this->assertPage('/admin.php', 200, 'Dashboard', message: 'overview');
        $overview = $this->assertPage('/admin.php', 200, '<h1>Dashboard</h1>', message: 'overview: the screen heading is h1');
        $this->assertStringContainsString('<li class=""><a href="/admin.php?module=appearance">', $overview->body, 'first steps do not count the appearance of the starter site as done');
        $this->assertStringContainsString('<li class=""><a href="/admin.php?module=pages"><strong>Prepare your pages', $overview->body, 'nor the pages of the starter site');
        $this->assertPage('/admin.php?module=pages', 200, '<nav class="menu-wrap" aria-label="Main menu">', message: 'administration: main menu in <nav>');

        foreach (['pages', 'pages&action=new', 'enquiries', 'parts', 'components', 'components&action=new', 'collections', 'collections&action=new', 'news', 'news&action=new', 'news&action=links', 'categories', 'categories&action=new', 'tags', 'media', 'stats', 'appearance', 'users', 'users&action=new', 'redirects', 'changelog', 'transfer', 'extensions'] as $module) {
            $this->assertPage("/admin.php?module=$module", 200, message: "module $module");
        }
        $this->assertPage('/admin.php?module=users', 200, 'May do everything', message: 'users with a summary of permissions');
        foreach (['general', 'seo', 'analytics', 'cookies', 'mail', 'webhooks', 'backups'] as $tab) {
            $this->assertPage("/admin.php?module=settings&tab=$tab", 200, message: "settings/$tab");
        }
    }

    #[Depends('testSignInProtections')]
    public function testSettingsHubsAndTokens(): void
    {
        foreach (['company' => 'business', 'health' => 'status'] as $tab => $target) {
            $redirect = $this->site()->admin()->get("/admin.php?module=settings&tab=$tab")->redirect;
            $this->assertSame($target, (string) preg_replace('/.*module=/', '', $redirect), "3.2: settings&tab=$tab leads to $target");
        }
        $this->assertPage('/admin.php?module=status', 200, 'Cron', message: '3.2: System status is its own screen');
        $this->assertPage('/admin.php?module=claude_settings', 200, 'name="claude_instructions"', message: '3.2: Claude settings hold the instructions and the guardrails');
        $this->assertPage('/admin.php?module=facts', 200, 'tabs-hub', message: '3.2: Business details show the hub tabs');
        $this->assertPage('/admin.php', 200, 'module=claude_settings', message: '3.2: the menu leads to the hubs');
        $this->assertPage('/admin.php?module=business&action=download_backup&file=x', 404, message: '3.2: Business details refuse the actions of Settings it does not offer');

        // 3.3.2 (N41): the cron and monitoring tokens change only from System status
        $this->site()->exec("REPLACE INTO tl_settings (name, value) VALUES ('tasks_token', 'before-n41'), ('health_token', 'before-n41')");
        $this->adminPost('/admin.php?module=business&action=save', ['new_tasks_token' => '1', 'new_token' => '1'], '/admin.php?module=business');
        $afterBusiness = (string) $this->site()->value("SELECT GROUP_CONCAT(value ORDER BY name) FROM tl_settings WHERE name IN ('tasks_token', 'health_token')");
        $alerts = (string) $this->site()->value("SELECT value FROM tl_settings WHERE name = 'alerts_enabled'");
        $fields = ['new_token' => '1'] + ($alerts === '0' ? [] : ['alerts_enabled' => '1']);
        $this->adminPost('/admin.php?module=status&action=save', $fields, '/admin.php?module=status');
        $health = (string) $this->site()->value("SELECT CONCAT(value <> 'before-n41', LENGTH(value)) FROM tl_settings WHERE name = 'health_token'");
        $this->assertSame('before-n41,before-n41|132', "$afterBusiness|$health", '3.3.2: Business details never replace the cron or monitoring token, System status does');
    }

    #[Depends('testSignInProtections')]
    public function testSettingsAndLanguageVersions(): void
    {
        $this->assertPage('/admin.php?module=settings&tab=general', 200, 'name="home_page"', message: 'settings: home page choice');
        $this->assertPage('/admin.php?module=does-not-exist', 403, message: 'unknown module');
        $this->assertPage('/api/news', 404, message: '2.0: the public API of 1.x is gone');

        $this->site()->exec("INSERT INTO tl_settings VALUES ('additional_languages','cs') ON DUPLICATE KEY UPDATE value='cs'");
        $this->assertPage('/cs/', 200, 'lang="cs"', message: 'Czech version of the site');

        // 2.3.1: saving Settings → General as a browser does (every field of the form as it is) keeps the language versions
        $page = $this->site()->admin()->get('/admin.php?module=settings&tab=general');
        $this->site()->admin()->post('/admin.php?module=settings&action=save', $this->formQuery($page->body));
        $this->assertSame('cs', $this->site()->settingValue('additional_languages'), 'saving Settings → General keeps the language versions');
        $this->assertSame(301, $this->site()->client()->get('/cs/news/our-new-website-is-live')->status, 'a news item of another language version redirects');
    }

    /** Every field of the settings form as a browser would send it. */
    private function formQuery(string $html): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $form = $xpath->query('//form[.//input[@name="tab"]]')->item(0);
        $pairs = [];
        foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $element) {
            $name = $element->getAttribute('name');
            $type = $element->getAttribute('type');
            if ($name === '' || $type === 'submit' || (in_array($type, ['checkbox', 'radio'], true) && !$element->hasAttribute('checked'))) {
                continue;
            }
            if ($element->nodeName === 'select') {
                $option = $xpath->query('.//option[@selected]', $element)->item(0) ?? $xpath->query('.//option', $element)->item(0);
                $value = $option ? $option->getAttribute('value') : '';
            } else {
                $value = $element->nodeName === 'textarea' ? $element->textContent : $element->getAttribute('value');
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }

    #[Depends('testSettingsAndLanguageVersions')]
    public function testNewsValidationAndDefaultCategory(): void
    {
        $csrf = $this->site()->admin()->get('/admin.php?module=news&action=new')->csrf();
        $failed = $this->site()->admin()->post('/admin.php?module=news&action=save', ['_csrf' => $csrf, 'news_id' => 0, 'title' => '', 'category_id' => $this->site()->publicId('categories', 1)]);
        $this->assertSame(200, $failed->status, 'a failed news validation returns the form, not error 500');
        $this->assertStringContainsString('name="title"', $failed->body, 'the form comes back');

        // news without a category: a default category is created in the site language
        $pdo = $this->site()->pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec('DROP TABLE IF EXISTS kat_zaloha');
        $pdo->exec('CREATE TABLE kat_zaloha AS SELECT * FROM tl_categories');
        $pdo->exec('DELETE FROM tl_categories');
        $pdo->exec("UPDATE tl_settings SET value='cs' WHERE name='site_language'");
        try {
            $this->assertPage('/admin.php?module=news&action=new', 200, 'name="title"', message: 'a new news item without a category opens the editor');
            $this->assertSame('1:Novinky', $this->site()->value("SELECT CONCAT(COUNT(*), ':', MAX(name)) FROM tl_categories"), 'default category created in the site language'); // check-english: allow
        } finally {
            $pdo->exec('DELETE FROM tl_categories');
            $pdo->exec('INSERT INTO tl_categories SELECT * FROM kat_zaloha');
            $pdo->exec('DROP TABLE kat_zaloha');
            $pdo->exec("UPDATE tl_settings SET value='en' WHERE name='site_language'");
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    #[Depends('testNewsValidationAndDefaultCategory')]
    public function testNewsAuthorSeesOnlyTheirOwnAndPublishesNothing(): void
    {
        $newsId = $this->site()->publicId('news', (int) $this->site()->value('SELECT news_id FROM tl_news ORDER BY news_id LIMIT 1'));
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Author', 'username' => 'author', 'password' => $this->site()->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $author = $this->site()->client('author');
        $this->site()->signIn($author, 'author');

        $list = $author->get('/admin.php?module=news');
        $this->assertSame(200, $list->status);
        $this->assertStringNotContainsString("action=edit&amp;id=$newsId\"", $list->body, 'the author does not see foreign news');
        $this->assertSame(404, $author->get("/admin.php?module=news&action=edit&id=$newsId")->status, 'the author does not open a foreign news item');
        $this->assertSame(403, $author->get('/admin.php?module=pages')->status, 'the author has no access to pages');

        $csrf = $author->get('/admin.php?module=news&action=new')->csrf();
        $author->post('/admin.php?module=news&action=save', [
            '_csrf' => $csrf, 'news_id' => 0, 'title' => 'XSS-test', 'category_id' => $this->site()->publicId('categories', 1),
            'intro' => '<p onmouseover="alert(1)">Perex</p><script>alert(2)</script>', 'text' => '<p><img src=x onerror=alert(3)><a href="javascript:alert(4)">link</a></p>',
        ]);
        $this->assertSame('0', (string) $this->site()->value("SELECT CONCAT(intro, text) REGEXP 'script|onerror|onmouseover|javascript' FROM tl_news WHERE title = 'XSS-test'"), 'the author inserts no script into a news item');

        $this->assertPage('/admin.php', 200, 'News from authors awaiting publication', message: 'the editor sees authors\' news waiting for publishing on the overview');
        $this->assertPage('/admin.php?module=news&status=awaiting_publication', 200, 'XSS-test', message: 'news list: filter Waiting for publishing');
    }
}
