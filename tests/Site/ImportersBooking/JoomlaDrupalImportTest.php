<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Old section 97: 3.0 importers on the common base - Joomla and Drupal through the fetch step (Import\Fetch), Webflow CSV. */
#[Group('site')]
final class JoomlaDrupalImportTest extends SiteTestCase
{
    use ImportSteps;

    private const string JOOMLA = 'joomla-127-0-0-1.json';
    private const string DRUPAL = 'drupal-127-0-0-1.json';
    private const string WEBFLOW = 'webflow-webflow-blog.csv';

    private function fake(): string
    {
        return 'http://127.0.0.1:' . $this->site()->port('fake');
    }

    /** The fake service's request log of one service (old $FAKE_LOGS-<name>.log). */
    private function fakeLog(string $name): string
    {
        return sys_get_temp_dir() . '/kaleta-fake-' . $this->site()->port('fake') . '-' . $name . '.log';
    }

    /** @return list<string> */
    private function logLines(string $name): array
    {
        return is_file($this->fakeLog($name)) ? (file($this->fakeLog($name), FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    private function calls(string $name, string $needle): int
    {
        return count(array_filter($this->logLines($name), static fn (string $l): bool => str_contains($l, $needle)));
    }

    /** The "offset":N values of the log lines for a path, joined by spaces. */
    private function offsets(string $name, string $path): string
    {
        $out = [];
        foreach ($this->logLines($name) as $line) {
            if (str_contains($line, $path) && preg_match('/"offset":\d+/', $line, $m) === 1) {
                $out[] = $m[0];
            }
        }

        return implode(' ', $out);
    }

    private function fetch(string $system, string $address, string $token, array $steps): void
    {
        $this->transfer('source_fetch', ['system' => $system, 'adresa' => $address, 'token' => $token, 'kroky' => $steps]);
    }

    private function row(string $sql): string
    {
        return (string) $this->site()->value($sql);
    }

    private function joomlaRun(): \Kaleta\Tests\Site\Support\Response
    {
        return $this->runImport(self::JOOMLA);
    }

    private function drupalRun(): void
    {
        $this->runImport(self::DRUPAL);
    }

    private function assertUnderStorageNot(string $needle, string $message): void
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->site()->path('storage'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_contains((string) file_get_contents($file->getPathname()), $needle)) {
                $found[] = $file->getPathname();
            }
        }
        $this->assertSame([], $found, $message);
    }

    public function testFetchFormsAndInternalAddress(): void
    {
        @unlink($this->fakeLog('joomla'));
        @unlink($this->fakeLog('drupal'));
        $page = $this->site()->admin()->get('/admin.php?module=transfer');
        $this->assertStringContainsString('action=source_fetch', $page->body, 'import and export: there is a fetch form');
        $this->assertStringContainsString('name="system" value="joomla"', $page->body, 'Joomla has a fetch form (address, token, steps)');
        $this->assertStringContainsString('name="system" value="drupal"', $page->body, 'Drupal has a fetch form');
        $this->assertStringContainsString('option value="webflow">Webflow', $page->body, 'Webflow is a file upload');
        $this->assertStringNotContainsString('option value="joomla"', $page->body, 'Joomla is not a file upload');
        $this->transfer('source_fetch', ['system' => 'joomla', 'adresa' => 'http://10.0.0.5', 'token' => 'whatever']);
        $this->assertTrue($this->site()->admin()->get('/admin.php?module=transfer')->matches('/not an internal address|ne vnitřní adresu/'), 'fetch: an internal address is refused');
    }

    public function testJoomlaFetch(): void
    {
        $steps = ['categories', 'users', 'tags'];
        $this->fetch('joomla', $this->fake(), 'wrong-token-value', $steps);
        $answer = $this->batch(self::JOOMLA);
        $this->assertMatchesRegularExpression('/refused the request.*401|odmítl.*401/', $answer->body, 'Joomla: a wrong token is a clear error with the 401');
        $this->assertUnderStorageNot('wrong-token-value', 'Joomla: the refused token is nowhere under storage/');

        $this->fetch('joomla', $this->fake(), 'jm-secret-token', $steps);
        $answer = $this->batch(self::JOOMLA);
        $this->assertMatchesRegularExpression('/Fetching from|Stahuji z/', $answer->body, 'Joomla: the fetch uses its page budget and continues in the next request');
        for ($i = 0; $i < 4; $i++) {
            $this->batch(self::JOOMLA);
        }
        // 8 calls: the refused attempt, articles paged 0/2/4, categories paged 0/2, users, tags - every one carried a token header
        $this->assertSame(
            '8|0|"offset":0 "offset":0 "offset":2 "offset":4|2',
            $this->calls('joomla', '"has_token":true') . '|' . $this->calls('joomla', '"has_token":false') . '|' . $this->offsets('joomla', 'content/articles') . '|' . $this->calls('joomla', 'content/categories'),
            'Joomla: the fake saw a token header on every call and was paged through articles with offsets 0, 2, 4 (the first 0 is the refused attempt)',
        );
        $preview = $this->preview(self::JOOMLA);
        $this->assertTrue($preview->contains('SEF URL') || $preview->contains('SEF adres'), 'Joomla: the preview warns about SEF addresses');
        $this->assertStringContainsString('Hello from the bakery', $preview->body, 'Joomla: the preview shows the first titles');
        $this->assertStringContainsString('Marta Editor', $preview->body, 'Joomla: the preview shows the authors');
        $this->assertStringNotContainsString('name="site_url"', $preview->body, 'Joomla: the preview knows the site address');
        $this->assertStringNotContainsString('jm-secret-token', $preview->body, 'Joomla: the preview never shows the token');
    }

    public function testJoomlaImport(): void
    {
        $this->joomlaRun();
        $this->assertSame(
            'hello-from-the-bakery:1:2024-03-10:News:Sourdough:Opening day of the bakery.|unpublished-recipe:0:2024-04-02:News:-:|summer-market:1:2099-06-01:Blog:Events:|archived-thoughts:1:2023-01-05:Uncategorised:-:',
            $this->row("SELECT GROUP_CONCAT(CONCAT(n.slug, ':', n.visible, ':', DATE(n.published_at), ':', k.name, ':', IFNULL((SELECT GROUP_CONCAT(s.name) FROM ka_news_tags ns JOIN ka_tags s ON s.tag_id = ns.tag_id WHERE ns.news_id = n.news_id), '-'), ':', n.seo_description) ORDER BY n.news_id SEPARATOR '|') FROM ka_news n JOIN ka_categories k ON k.category_id = n.category_id WHERE n.slug IN ('hello-from-the-bakery', 'unpublished-recipe', 'trashed-note', 'summer-market', 'archived-thoughts')"),
            'Joomla: published, unpublished (hidden), scheduled and archived articles as news items with their categories, tags and metadesc; the trashed one is not imported',
        );
        $this->assertSame('1:0:1:0', $this->row("SELECT CONCAT(intro LIKE '%opened the oven%', ':', text LIKE '%opened the oven%', ':', text LIKE '%first loaves%', ':', text LIKE '%podvrh%') FROM ka_news WHERE slug = 'hello-from-the-bakery'"), 'Joomla: introtext is the intro, fulltext the text, the script is cleaned out');
        $this->assertSame([301, $this->site()->base . '/novinky/hello-from-the-bakery'], $this->anonymous('/blog/news/12-hello-from-the-bakery'), 'Joomla: the best-guess old address /category-path/id-alias redirects to the news item');
        $this->downloadImages(self::JOOMLA);
        $this->assertSame('1:1:0', $this->row("SELECT CONCAT(image LIKE 'media/%', ':', text LIKE '%media/%', ':', text LIKE '%<img src=\"http://127.0.0.1%') FROM ka_news WHERE slug = 'hello-from-the-bakery'"), 'Joomla: the featured image (images/... made absolute) and the image in the text are in Media');
        $this->transfer('source_select', ['soubor' => self::JOOMLA]);
        $this->batch(self::JOOMLA);
        $result = $this->joomlaRun();
        $this->assertSame('4/2', $this->row("SELECT CONCAT((SELECT COUNT(*) FROM ka_news WHERE slug LIKE 'hello-from-the-bakery%' OR slug LIKE 'summer-market%' OR slug LIKE 'unpublished-recipe%' OR slug LIKE 'archived-thoughts%'), '/', (SELECT COUNT(*) FROM ka_categories WHERE name IN ('News', 'Blog')))"), 'Joomla: a second import of the fetched file adds nothing');
        $this->assertTrue($this->skippedTile($result, 4), 'Joomla: the result shows 4 skipped');
    }

    public function testJoomlaTokenIsNowhere(): void
    {
        $progress = $this->site()->admin()->get('/admin.php?module=transfer&action=source_progress&file=' . self::JOOMLA);
        $this->assertSame('0|0', $this->row("SELECT CONCAT((SELECT COUNT(*) FROM ka_settings WHERE value LIKE '%jm-secret-token%'), '|', (SELECT COUNT(*) FROM ka_change_log WHERE description LIKE '%jm-secret-token%' OR action LIKE '%jm-secret-token%' OR reason LIKE '%jm-secret-token%'))"), 'Joomla: the token is in neither ka_settings nor ka_change_log');
        $this->assertUnderStorageNot('jm-secret-token', 'Joomla: the token is in no file under storage/');
        $this->assertStringNotContainsString('jm-secret-token', $progress->body, 'Joomla: the token is on no page');
    }

    public function testDrupal(): void
    {
        $this->fetch('drupal', $this->fake() . '/', 'drupal:wrong-pass', ['pages', 'tags']);
        $this->assertMatchesRegularExpression('/refused the request.*401|odmítl.*401/', $this->batch(self::DRUPAL)->body, 'Drupal: wrong credentials are a clear error with the 401');
        $this->fetch('drupal', $this->fake(), 'drupal:dr-pass', ['pages', 'tags']);
        for ($i = 0; $i < 3; $i++) {
            $this->batch(self::DRUPAL);
        }
        $this->assertSame(
            '4|1|"offset":0 "offset":0 "offset":2|1',
            $this->calls('drupal', '"signed_in":true') . '|' . $this->calls('drupal', '"signed_in":false') . '|' . $this->offsets('drupal', 'node/article') . '|' . $this->calls('drupal', 'taxonomy_term/tags'),
            'Drupal: the fake saw the right Basic auth on the 4 calls after the refused one, articles paged with offsets 0 and 2, the tags endpoint asked once',
        );
        $preview = $this->preview(self::DRUPAL);
        $this->assertTrue($preview->contains('does not offer tags') || $preview->contains('nenabízí tags'), 'Drupal: the preview notes the skipped tags step');
        $this->assertStringContainsString('Hello from Drupal', $preview->body, 'Drupal: the preview shows the article title');
        $this->assertStringContainsString('About the bakery', $preview->body, 'Drupal: the preview shows the page title');
        $this->assertStringNotContainsString('dr-pass', $preview->body, 'Drupal: the preview never shows the credentials');
        $this->drupalRun();
        $this->assertSame(
            'hello-from-drupal:1:2024-03-10:Sourdough:Welcome text for the search engines.|second-post:1:2024-04-01:Events,Sourdough:|draft-post:0:2024-05-01:-:',
            $this->row("SELECT GROUP_CONCAT(CONCAT(n.slug, ':', n.visible, ':', DATE(n.published_at), ':', IFNULL((SELECT GROUP_CONCAT(s.name ORDER BY s.name) FROM ka_news_tags ns JOIN ka_tags s ON s.tag_id = ns.tag_id WHERE ns.news_id = n.news_id), '-'), ':', n.seo_description) ORDER BY n.news_id SEPARATOR '|') FROM ka_news n WHERE n.slug IN ('hello-from-drupal', 'second-post', 'draft-post')"),
            'Drupal: articles as news items with tags from the included terms, the metatag description, the unpublished one hidden',
        );
        $this->assertSame('1:0:1:0', $this->row("SELECT CONCAT((SELECT CONCAT(visible, ':', in_menu, ':', build IS NOT NULL) FROM ka_pages WHERE slug = 'about'), ':', (SELECT text LIKE '%podvrh%' FROM ka_news WHERE slug = 'hello-from-drupal'))"), 'Drupal: the basic page is a published build outside the menu; the script is cleaned out of the article');
        $this->assertSame([301, $this->site()->base . '/novinky/hello-from-drupal'], $this->anonymous('/blog/hello-from-drupal'), 'Drupal: the path alias redirects to the news item');
        $this->assertSame([200, ''], $this->anonymous('/about'), 'Drupal: the page keeps its alias as the new address');
        $this->downloadImages(self::DRUPAL);
        $this->assertSame('1:1', $this->row("SELECT CONCAT(image LIKE 'media/%', ':', text LIKE '%media/%') FROM ka_news WHERE slug = 'hello-from-drupal'"), 'Drupal: the field_image file and the image in the body are in Media');
        $this->transfer('source_select', ['soubor' => self::DRUPAL]);
        $this->batch(self::DRUPAL);
        $this->drupalRun();
        $this->assertSame('3/1', $this->row("SELECT CONCAT((SELECT COUNT(*) FROM ka_news WHERE slug LIKE 'hello-from-drupal%' OR slug LIKE 'second-post%' OR slug LIKE 'draft-post%'), '/', (SELECT COUNT(*) FROM ka_pages WHERE slug IN ('about', 'about-2')))"), 'Drupal: a second import adds nothing');
        $this->assertUnderStorageNot('dr-pass', 'Drupal: the credentials are in no file under storage/');
    }

    public function testWebflow(): void
    {
        $this->uploadSource('webflow', dirname(__DIR__, 3) . '/tools/fixtures/webflow-blog.csv');
        $this->batch(self::WEBFLOW);
        $preview = $this->preview(self::WEBFLOW);
        $this->assertStringContainsString('name="site_url"', $preview->body, 'Webflow: the preview asks for the collection address');
        $this->assertTrue($preview->contains('From a live website') || $preview->contains('Z běžícího webu'), 'Webflow: the preview points static pages to the URL importer');
        $this->assertStringContainsString('Spring sourdough', $preview->body, 'Webflow: the preview shows the first titles');
        $webflowRun = fn () => $this->runImport(self::WEBFLOW, ['site_url' => $this->site()->base . '/blog/']);
        $webflowRun();
        $this->assertSame(
            'spring-sourdough:1:2024-03-05:Recipes:Sourdough,Spring:1:0|market-day:0:2024-04-01:Events:Events:0:0|old-news:0:2024-01-10:Recipes:-:0:0',
            $this->row("SELECT GROUP_CONCAT(CONCAT(n.slug, ':', n.visible, ':', DATE(n.published_at), ':', k.name, ':', IFNULL((SELECT GROUP_CONCAT(s.name ORDER BY s.name) FROM ka_news_tags ns JOIN ka_tags s ON s.tag_id = ns.tag_id WHERE ns.news_id = n.news_id), '-'), ':', n.intro LIKE '%spring recipe%', ':', n.text LIKE '%podvrh%') ORDER BY n.news_id SEPARATOR '|') FROM ka_news n JOIN ka_categories k ON k.category_id = n.category_id WHERE n.slug IN ('spring-sourdough', 'market-day', 'old-news')"),
            'Webflow: the rows as news items - the summary as the intro, the category and the tags from the reference slugs, the draft and the archived one hidden',
        );
        $this->assertSame([301, $this->site()->base . '/novinky/spring-sourdough'], $this->anonymous('/blog/spring-sourdough'), 'Webflow: the old address under the collection folder redirects to the news item');
        $this->transfer('source_select', ['soubor' => self::WEBFLOW]);
        $this->batch(self::WEBFLOW);
        $webflowRun();
        $this->assertSame('3', $this->row("SELECT COUNT(*) FROM ka_news WHERE slug LIKE 'spring-sourdough%' OR slug LIKE 'market-day%' OR slug LIKE 'old-news%'"), 'Webflow: a second import adds nothing');
        $this->assertSame('drupal:127.0.0.1,joomla:127.0.0.1,webflow:127.0.0.1', $this->row("SELECT GROUP_CONCAT(DISTINCT source ORDER BY source) FROM ka_import_map WHERE source LIKE 'joomla:%' OR source LIKE 'drupal:%' OR source LIKE 'webflow:%'"), 'the three imports are recorded in ka_import_map under their own source labels');
        $this->assertSame(403, $this->site()->client()->get('/storage/import/sources/' . self::JOOMLA)->status, 'the fetched file is not accessible from the web');
    }
}
