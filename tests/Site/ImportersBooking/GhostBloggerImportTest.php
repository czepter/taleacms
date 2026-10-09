<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Old section 95: 3.0 structured importers - Ghost and Blogger (Import\Batch). */
#[Group('site')]
final class GhostBloggerImportTest extends SiteTestCase
{
    use ImportSteps;

    private static string $source = '';
    private static string $bloggerFile = '';

    private function fixtures(): string
    {
        return dirname(__DIR__, 3) . '/tools/fixtures';
    }

    /** A small "old site" that only serves the image the fixtures point at; they name it as 127.0.0.1:65000. */
    private function source(): string
    {
        if (self::$source === '') {
            $dir = $this->site()->workDir('sources');
            mkdir($dir . '/img', 0775, true);
            mkdir($dir . '/s1600', 0775, true);
            $image = imagecreatetruecolor(320, 200);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 120, 60, 30));
            imagepng($image, $dir . '/img/team.png');
            copy($dir . '/img/team.png', $dir . '/s1600/team.png');
            file_put_contents($dir . '/router.php', '<?php return false;');
            self::$source = 'http://127.0.0.1:' . $this->site()->startPhp($dir, 'router.php');
            self::$bloggerFile = $this->site()->workDir('blogger') . '/blogger-export.xml';
            file_put_contents(self::$bloggerFile, str_replace('http://127.0.0.1:65000', self::$source, (string) file_get_contents($this->fixtures() . '/blogger-export.xml')));
        }

        return self::$source;
    }

    private function newsRow(string $sql): string
    {
        return (string) $this->site()->value($sql);
    }

    public function testGhostPreview(): void
    {
        $this->source();
        $this->assertPage('/admin.php?module=transfer', 200, 'option value="blogger">Blogger', message: 'the section for other systems lists Ghost and Blogger');
        $this->uploadSource('ghost', $this->fixtures() . '/ghost-export.json');
        $this->batch('ghost-ghost-export.json');
        $this->assertPage('/admin.php?module=transfer&action=source_preview&file=ghost-ghost-export.json', 200, 'routes.yaml', message: 'Ghost: the preview says that routes.yaml is not read');
        $preview = $this->preview('ghost-ghost-export.json');
        $this->assertStringContainsString('name="site_url"', $preview->body, 'Ghost: the preview asks for the site address');
        $this->assertStringContainsString('Firing the first kiln', $preview->body, 'Ghost: the preview shows the first titles');
        $this->assertStringContainsString('bookmark 1', $preview->body, 'Ghost: the preview shows the unsupported card');
    }

    public function testGhostImport(): void
    {
        $answer = $this->ghostRun();
        $this->assertTrue(str_contains($answer->body, 'The content import is finished') || str_contains($answer->body, 'Import obsahu je hotový'), 'Ghost: the import finished in one batch');
        $today = $this->site()->php('echo date("Y-m-d");');
        $this->assertSame(
            "firing-the-first-kiln:1:2024-03-10:Workshop:Glazes:Firing the first kiln – Clay Notes|glaze-recipes-we-keep:0:$today:Glazes:-:|spring-market:1:2099-05-01:Nezařazené:-:",
            $this->newsRow("SELECT GROUP_CONCAT(CONCAT(n.slug, ':', n.visible, ':', DATE(n.published_at), ':', k.name, ':', IFNULL((SELECT GROUP_CONCAT(s.name) FROM ka_news_tags ns JOIN ka_tags s ON s.tag_id = ns.tag_id WHERE ns.news_id = n.news_id), '-'), ':', n.seo_title) ORDER BY n.news_id SEPARATOR '|') FROM ka_news n JOIN ka_categories k ON k.category_id = n.category_id WHERE n.slug IN ('firing-the-first-kiln', 'glaze-recipes-we-keep', 'spring-market')"),
            'Ghost: posts as news items with status and date, the primary tag as the category, the other tag as a tag, SEO fields',
        );
        $this->assertSame('1:0:1:Who we are and when we are open.', $this->newsRow("SELECT CONCAT(visible, ':', in_menu, ':', build IS NOT NULL, ':', description) FROM ka_pages WHERE slug = 'about-the-workshop'"), 'Ghost: the page is a published build outside the menu with the excerpt as its description');
        $this->assertStringNotContainsString('podvrh', $this->site()->client()->get('/novinky/firing-the-first-kiln')->body, "Ghost: the html card's script is cleaned out");
        $this->assertSame([301, $this->site()->base . '/novinky/firing-the-first-kiln'], $this->anonymous('/firing-the-first-kiln/'), 'Ghost: the old address /slug/ redirects to the news item');
        $this->assertSame([200, ''], $this->anonymous('/about-the-workshop'), 'Ghost: the old page address is the new one (no redirect needed)');
    }

    private function ghostRun(): \Kaleta\Tests\Site\Support\Response
    {
        return $this->runImport('ghost-ghost-export.json', ['site_url' => $this->source()]);
    }

    public function testGhostImagesAndSecondRun(): void
    {
        $this->downloadImages('ghost-ghost-export.json');
        $this->assertSame('1:1:0:1', $this->newsRow("SELECT CONCAT(image LIKE 'media/%', ':', text LIKE '%media/%', ':', text LIKE '%<img src=\"http://127.0.0.1%', ':', text LIKE '%<a href=\"http://127.0.0.1%') FROM ka_news WHERE slug = 'firing-the-first-kiln'"), 'Ghost: the featured image is in Media and the text refers to the copy there (the link to another old post stays - the redirect catches it)');
        $this->transfer('source_select', ['file' => 'ghost-ghost-export.json']);
        $this->batch('ghost-ghost-export.json');
        $answer = $this->ghostRun();
        $this->assertSame('3/1/1', $this->newsRow("SELECT CONCAT((SELECT COUNT(*) FROM ka_news WHERE slug LIKE 'firing-the-first-kiln%' OR slug LIKE 'glaze-recipes%' OR slug LIKE 'spring-market%'), '/', (SELECT COUNT(*) FROM ka_pages WHERE slug LIKE 'about-the-workshop%'), '/', (SELECT COUNT(*) FROM ka_categories WHERE name = 'Workshop'))"), 'Ghost: a second import skips everything');
        $this->assertTrue($this->skippedTile($answer, 4), 'Ghost: the result shows 4 skipped');
    }

    public function testBloggerImport(): void
    {
        $this->source();
        $this->uploadSource('blogger', self::$bloggerFile);
        $this->batch('blogger-blogger-export.xml');
        $preview = $this->preview('blogger-blogger-export.xml');
        $this->assertTrue(str_contains($preview->body, 'Comments are skipped') || str_contains($preview->body, 'Komentáře se vynechávají'), 'Blogger: the preview says comments are skipped');
        $this->assertStringNotContainsString('name="site_url"', $preview->body, "Blogger: the preview knows the blog's address");
        $this->assertStringContainsString('Planting the first beds', $preview->body, 'Blogger: the preview shows the first titles');
        $this->transfer('source_run', ['file' => 'blogger-blogger-export.xml', 'posts' => 'news', 'pages' => 'page', 'categories' => 'category', 'tags' => 'tag', 'drafts' => '1', 'builder' => '1', 'redirects' => '1', 'default_category' => '0']);
        $this->batch('blogger-blogger-export.xml');
        $this->assertSame(
            'planting-first-beds:1:2019-05-14:Spring,Vegetables|compost-notes:0:2024-06-01:Compost',
            $this->newsRow("SELECT GROUP_CONCAT(CONCAT(n.slug, ':', n.visible, ':', DATE(n.published_at), ':', IFNULL((SELECT GROUP_CONCAT(s.name ORDER BY s.name) FROM ka_news_tags ns JOIN ka_tags s ON s.tag_id = ns.tag_id WHERE ns.news_id = n.news_id), '-')) ORDER BY n.news_id SEPARATOR '|') FROM ka_news n WHERE n.slug IN ('planting-first-beds', 'compost-notes') OR n.title LIKE 'Lovely%'"),
            'Blogger: the post with its labels as tags and date, the draft hidden, the comment not imported',
        );
        $this->assertPage('/about-this-diary', 200, 'slugs eat first', message: 'Blogger: the page');
        $this->assertStringNotContainsString('podvrh', $this->site()->client()->get('/novinky/planting-first-beds')->body, 'Blogger: the script in the post is cleaned out, the paragraphs are made');
        $this->assertSame([301, $this->site()->base . '/novinky/planting-first-beds'], $this->anonymous('/2019/05/planting-first-beds.html'), 'Blogger: the old /2019/05/slug.html address redirects');
        $this->downloadImages('blogger-blogger-export.xml');
        $this->assertSame('1:1', $this->newsRow("SELECT CONCAT(image LIKE 'media/%', ':', text LIKE '%media/%') FROM ka_news WHERE slug = 'planting-first-beds'"), 'Blogger: the image in the text and the thumbnail at full size are in Media (any public host)');
        $this->assertSame('blogger:127.0.0.1,ghost:127.0.0.1', $this->newsRow("SELECT GROUP_CONCAT(DISTINCT source ORDER BY source) FROM ka_import_map WHERE source LIKE 'ghost:%' OR source LIKE 'blogger:%'"), 'the imports are recorded in ka_import_map under their own source labels');
        $this->assertSame(403, $this->site()->client()->get('/storage/import/sources/ghost-ghost-export.json')->status, 'the sources folder is not accessible from the web');
    }
}
