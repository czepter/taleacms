<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\EnglishInstall;

use Kaleta\Tests\Site\Support\CzechCheck;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The English admin of the business starter: every screen, the messages after saving and pop-ups – no Czech. */
#[Group('site')]
final class BusinessAdminEnglishTest extends SiteTestCase
{
    use BusinessInstall;
    use CzechCheck;

    public function testEveryAdminScreen(): void
    {
        foreach ($this->adminScreens() as $query) {
            $this->assertCzechFree("/admin.php?$query", 200, $this->site()->admin(), label: 'admin');
        }
    }

    /** Follows the redirect of a POST (curl -L) and returns the page that shows the message. */
    private function postAndFollow(string $path, array $fields, string $formPage = '/admin.php'): string
    {
        $response = $this->adminPost($path, $fields, $formPage);

        return $this->follow($this->site()->admin(), $response, $path);
    }

    /** Like curl -L: follows the redirects (at most five) and returns the body of the page the message is shown on. */
    private function follow(\Kaleta\Tests\Site\Support\Http $client, \Kaleta\Tests\Site\Support\Response $response, string $label): string
    {
        for ($hop = 0; $hop < 5 && $response->redirect !== ''; $hop++) {
            $response = $client->get($response->redirect);
        }
        $this->assertSame(200, $response->status, "$label: ends on a page");

        return $response->body;
    }

    /** The page shows a message (flash) and it has no Czech. */
    private function assertMessageWithoutCzech(string $body, string $label): void
    {
        $this->assertStringContainsString('class="notice', $body, "$label: no message is shown");
        $this->assertNoCzech($body, $label);
    }

    public function testMessagesAfterSaving(): void
    {
        $this->assertMessageWithoutCzech($this->postAndFollow('/admin.php?module=settings&action=save', ['tab' => 'company', 'company_name' => 'Acme Ltd', 'company_country' => 'GB']), 'message after saving settings');
        $this->assertMessageWithoutCzech($this->postAndFollow('/admin.php?module=menu&action=save&location=footer', ['items' => '[{"type":"news","text":""}]']), 'message after saving a menu');

        $big = $this->site()->workDir('upload') . '/big.jpg';
        file_put_contents($big, str_repeat("\0", 3 * 1024 * 1024));
        $csrf = $this->site()->csrf();
        $upload = $this->site()->admin()->upload('/admin.php?module=media&action=upload', ['_csrf' => $csrf], ['files[]' => [$big, 'image/jpeg', 'big.jpg']]);
        $this->assertMessageWithoutCzech($this->follow($this->site()->admin(), $upload, 'upload'), 'message after an upload over the server limit');
    }

    public function testAuthorSavesANewsItem(): void
    {
        $this->adminPost('/admin.php?module=users&action=save', ['user_id' => 0, 'name' => 'Tom', 'username' => 'tom', 'password' => $this->site()->password, 'admin' => 0], '/admin.php?module=users&action=new');
        $author = $this->site()->client('tom');
        $this->site()->signIn($author, 'tom');
        $form = $this->assertCzechFree('/admin.php?module=news&action=new', 200, $author, label: 'author: new news item');
        $saved = $author->post('/admin.php?module=news&action=save', ['_csrf' => $form->csrf(), 'news_id' => 0, 'title' => 'Draft', 'category_id' => 1, 'intro' => '<p>Lead</p>']);
        $this->assertMessageWithoutCzech($this->follow($author, $saved, 'author: save'), 'author: message after saving a news item');
    }

    public function testPopups(): void
    {
        $token = $this->site()->csrf(null, '/admin.php?module=popups&action=new');
        $this->site()->admin()->post('/admin.php?module=popups&action=create', ['_csrf' => $token, 'template' => 'lead_magnet', 'name' => '']);
        $popup = (int) $this->site()->value('SELECT popup_id FROM ka_popups ORDER BY popup_id DESC LIMIT 1');
        $this->assertGreaterThan(0, $popup, 'the pop-up from the template was created');
        $admin = $this->site()->admin();
        $this->assertCzechFree("/admin.php?module=popups&action=edit&id=$popup", 200, $admin, label: 'pop-up settings');
        $this->assertCzechFree("/admin.php?module=popups&action=builder&id=$popup", 200, $admin, label: 'pop-up in the builder');
        $this->assertCzechFree("/_popup/$popup?build=koncept&editor=1", 200, $admin, label: 'pop-up template on the builder canvas');
        $toggle = $admin->post('/admin.php?module=popups&action=toggle', ['_csrf' => $token, 'popup_id' => $popup]);
        $this->assertMessageWithoutCzech($this->follow($admin, $toggle, 'toggle'), 'message: an unpublished pop-up cannot be turned on');
        $this->assertCzechFree('/admin.php?module=popups', 200, $admin, label: 'pop-up list');
    }
}
