<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;

final class ImportersTest extends TestCase
{
    /** 3.0: structured importers – the common base, Ghost and Blogger (Import\…) */
    public function testGhostAndBlogger(): void
    {
        $ghostPath = TALEA_ROOT . '/tools/fixtures/ghost-export.json';
        $bloggerPath = TALEA_ROOT . '/tools/fixtures/blogger-export.xml';
        $kinds = fn (iterable $records): array => array_map(fn (object $r): string => substr(strrchr(get_class($r), '\\'), 1) . ':' . ($r instanceof \Talea\Import\Post ? $r->type . '/' . $r->status : $r->key), iterator_to_array($records));
        $ghost = new \Talea\Import\Ghost($ghostPath, 'https://old.example');
        $ghost->verify();
        $ghostRecords = iterator_to_array($ghost->read());
        $this->assertSame(['Author:u1', 'Tag:t1', 'Tag:t2', 'Post:post/published', 'Post:post/draft', 'Post:page/published', 'Post:post/scheduled'], $kinds($ghostRecords), '3.0 Ghost: authors and public tags come first, then posts and pages with their status; the internal tag is skipped');
        $kiln = $ghostRecords[3];
        $this->assertSame(['Firing the first kiln', ['t1'], ['t2'], 'https://old.example/img/team.png', 'Firing the first kiln – Clay Notes', 'How the first firing of our new kiln went.', '/firing-the-first-kiln/', 'Fourteen hours, one kiln, no cracks.', 'u1', true], [
            $kiln->title, $kiln->categoryKeys, $kiln->tagKeys, $kiln->featureImageUrl, $kiln->seoTitle, $kiln->seoDescription, $kiln->oldUrl, $kiln->excerpt, $kiln->authorKey, str_contains($kiln->html, 'https://old.example/img/team.png')], '3.0 Ghost: the post – primary tag as the category, the other tag as a tag, __GHOST_URL__ resolved, meta from posts_meta, old address /slug/');
        $this->assertSame(["<h2>Celadon</h2>\n<p>Feldspar, silica and a little <strong>iron</strong>.</p>\n<p>Fire in reduction.</p>\n", ['block:bookmark']], [$ghostRecords[4]->html, $ghostRecords[4]->warnings], '3.0 Ghost: a post with only a lexical document is rendered (heading, paragraphs, bold) and the unknown card is a warning');
        $this->assertSame([5, 6], array_keys(iterator_to_array($ghost->read(5))), '3.0 Ghost: read($skip) continues with the same keys');
        $this->assertSame(['name' => 'Clay Notes', 'url' => 'https://old.example'], $ghost->site(), '3.0 Ghost: site name from the settings, address from the administrator');
        $ghostOk = true;
        try {
            (new \Talea\Import\Ghost($bloggerPath))->verify();
            $ghostOk = false;
        } catch (\RuntimeException) {
        }
        $this->assertTrue($ghostOk, '3.0 Ghost: a file that is not a Ghost export is refused');

        $blogger = new \Talea\Import\Blogger($bloggerPath);
        $blogger->verify();
        $bloggerRecords = iterator_to_array($blogger->read());
        $this->assertSame(['Author:http://www.blogger.com/profile/0000000000000000001', 'Post:page/published', 'Tag:vegetables', 'Tag:spring', 'Post:post/published', 'Tag:compost', 'Post:post/draft'], $kinds($bloggerRecords), '3.0 Blogger: the author and the labels come before the first post that uses them; settings, template and the comment are skipped');
        $beds = $bloggerRecords[4];
        $this->assertSame(['Planting the first beds', ['vegetables', 'spring'], 'http://127.0.0.1:65000/2019/05/planting-first-beds.html', 'planting-first-beds', 'http://127.0.0.1:65000/s1600/team.png', '2019-05-14T08:30:00.000+02:00', true, 'http://www.blogger.com/profile/0000000000000000001'], [
            $beds->title, $beds->tagKeys, $beds->oldUrl, $beds->slug, $beds->featureImageUrl, $beds->publishedAt, str_contains($beds->html, '<img'), $beds->authorKey], '3.0 Blogger: the post – labels as tags, old address from link rel=alternate, slug from it, thumbnail at full size, dates');
        $this->assertSame(['draft', '', ''], [$bloggerRecords[6]->status, $bloggerRecords[6]->oldUrl, $bloggerRecords[0]->email], '3.0 Blogger: app:draft = a draft without an old address; the author’s noreply address is not an e-mail');
        $this->assertSame(['name' => 'Garden Diary', 'url' => 'http://127.0.0.1:65000'], $blogger->site(), '3.0 Blogger: site from the feed');
        $this->assertSame(['https://h.example/a/s1600/x.png', 'https://h.example/a/s1600/x.png', 'https://h.example/a/x.png', ''], array_map(\Talea\Import\Blogger::fullSize(...), ['https://h.example/a/s72-c/x.png', 'https://h.example/a/w72-h72-p-k-no-nu/x.png', 'https://h.example/a/x.png', '']), '3.0 Blogger::fullSize');
        $bloggerOk = true;
        try {
            (new \Talea\Import\Blogger(TALEA_ROOT . '/tools/fixtures/wordpress-sample.xml'))->verify();
            $bloggerOk = false;
        } catch (\RuntimeException) {
        }
        $this->assertTrue($bloggerOk, '3.0 Blogger: a WordPress export is refused');

        // the common base: the preview of a whole file in one pass, the mapping, slug collisions, the registry
        $ghostState = \Talea\Import\Batch::newState('ghost-clay.json');
        \Talea\Import\Batch::analyze($ghostState, 30, $ghostPath);
        $this->assertSame(['preview', 7, ['published' => 1, 'draft' => 1, 'scheduled' => 1], ['published' => 1], 2, ['About the workshop'], ['bookmark' => 1], true, 'Workshop', 3], [
            $ghostState['phase'], $ghostState['total'], $ghostState['overview']['articles'], $ghostState['overview']['pages'], $ghostState['overview']['tags'], $ghostState['overview']['titles']['page'],
            $ghostState['overview']['blocks'], isset($ghostState['overview']['warnings']['no_site_url']), $ghostState['dictionary']['tags']['t1']['name'], count($ghostState['overview']['notes'])], '3.0 Batch::analyze – counts, the first titles, the dictionary for later batches and the Ghost footnotes');
        $preview = \Talea\Import\Preview::empty();
        foreach ([new \Talea\Import\Post('1', 'post', 'Lávka přes Bystřinu', 'lavka', '<p>a</p>'), new \Talea\Import\Post('2', 'post', 'Lávka', 'lavka', '<p>b</p>'), // check-english: allow
            new \Talea\Import\Post('3', 'page', 'Lávka', 'lavka', '<p>c</p>'), new \Talea\Import\Post('4', 'post', 'Bez adresy', '', '<img src="/relative.png">', featureImageUrl: 'data:image/png;base64,x')] as $r) { // check-english: allow
            \Talea\Import\Preview::tally($preview, $r);
        }
        \Talea\Import\Preview::finish($preview, $blogger);
        $this->assertSame(['missing_images' => 2, 'duplicate_slugs' => 1], $preview['warnings'], '3.0 Preview: two posts with one slug are a duplicate, a page with the same slug is not; images without an absolute address are counted');
        $this->assertSame(['posts' => 'skip', 'pages' => 'page', 'categories' => 'tag', 'tags' => 'tag', 'authors' => ['u1' => 7], 'language' => '', 'drafts' => false, 'builder' => true, 'redirects' => true, 'default_category' => 0, 'site_url' => 'https://old.example'], \Talea\Import\Mapping::normalize(
            ['posts' => 'skip', 'pages' => 'nonsense', 'categories' => 'tag', 'authors' => ['u1' => '7', 'u2' => 9, 'u3' => 'x'], 'language' => 'fr', 'drafts' => '', 'default_category' => '-3', 'site_url' => 'old.example/'], ['de'], [7]), '3.0 Mapping::normalize – unknown choices fall back, authors only to existing users, the language only to a version the site has, the address gets https');
        $this->assertSame([['visible' => 1], ['visible' => 0], null, date('Y-m-d H:i:s', strtotime('2024-03-10T09:00:00.000Z')), date('Y-m-d H:i:s', 1789000000), 'ghost:old.example', 'blogger'], [\Talea\Import\Batch::status('scheduled'), \Talea\Import\Batch::status('draft'), \Talea\Import\Batch::status('sent'),
            \Talea\Import\Batch::date('2024-03-10T09:00:00.000Z'), \Talea\Import\Batch::date('', 1789000000), \Talea\Import\Batch::label('ghost', 'https://www.Old.example/'), \Talea\Import\Batch::label('blogger', '')], '3.0 Batch: status, dates and the source label');
        $this->assertSame(// check-english: allow
            [['ghost', 'blogger', 'joomla', 'drupal', 'webflow'], 'ghost', null, null, 'blogger-muj-blog-2024.xml', 'ghost-clay-notes.json', false, true], [
            array_keys(\Talea\Import\Sources::all()), \Talea\Import\Sources::keyOfFile('ghost-my-blog.json'), \Talea\Import\Sources::keyOfFile('ghost-my-blog.xml'), \Talea\Import\Sources::keyOfFile('export.json'),
            \Talea\Import\Batch::uploadName('blogger', 'Můj blog (2024).xml'), \Talea\Import\Batch::uploadName('ghost', 'clay.notes.JSON'), \Talea\Import\Batch::isValidName('../ghost-x.json'), \Talea\Import\Batch::isValidName('blogger-x.xml')], '3.0 Sources and file names: the key from the file name, only known systems and extensions, safe upload names'); // check-english: allow
    }

    /** 3.0: importers on the common base – Joomla, Drupal (Import\Fetch) and Webflow */
    public function testJoomlaDrupalAndWebflow(): void
    {
        $kinds = fn (iterable $records): array => array_map(fn (object $r): string => substr(strrchr(get_class($r), '\\'), 1) . ':' . ($r instanceof \Talea\Import\Post ? $r->type . '/' . $r->status : $r->key), iterator_to_array($records));
        $joomla = new \Talea\Import\Joomla(TALEA_ROOT . '/tools/fixtures/joomla-fetch.json');
        $joomlaRecords = iterator_to_array($joomla->read());
        $this->assertSame(['Author:42', 'Author:43', 'Category:2', 'Category:8', 'Category:9', 'Tag:2', 'Tag:3', 'Post:post/published', 'Post:post/draft', 'Post:post/scheduled', 'Post:post/published'], $kinds($joomlaRecords), '3.0 Joomla: authors, categories and tags first, then the articles; the trashed one is skipped, state 0 is a draft, a future publish_up is scheduled, archived is published');
        $this->assertSame(['/blog/news/12-hello-from-the-bakery', 'https://old.example/images/blog/oven.jpg', ['9'], ['2'], '42', 'Opening day of the bakery.', '2024-03-10 09:00:00', true, true, true, '/blog/15-summer-market', '/uncategorised/16-archived-thoughts', '8'], [
            $joomlaRecords[7]->oldUrl, $joomlaRecords[7]->featureImageUrl, $joomlaRecords[7]->categoryKeys, $joomlaRecords[7]->tagKeys, $joomlaRecords[7]->authorKey, $joomlaRecords[7]->seoDescription, $joomlaRecords[7]->publishedAt,
            str_contains($joomlaRecords[7]->html, "</p>\n<!--more-->\n<p>"), str_contains($joomlaRecords[7]->html, 'src="https://old.example/images/blog/oven.jpg"'), str_contains($joomlaRecords[7]->html, 'href="https://old.example/blog/news/15-summer-market"'),
            $joomlaRecords[9]->oldUrl, $joomlaRecords[10]->oldUrl, $joomlaRecords[4]->parent], '3.0 Joomla: the article – intro and full text with the Read more mark, relative images and links made absolute, the featured image without #joomlaImage, the category and tag keys, the best-guess old address through the category path, metadesc, the author');
        $this->assertSame([['name' => '', 'url' => 'https://old.example'], [9, 10], '', 'Marta Editor'], [$joomla->site(), array_keys(iterator_to_array($joomla->read(9))), $joomlaRecords[0]->email, $joomlaRecords[0]->name], '3.0 Joomla: the site address from the fetched file, read($skip) continues with the same keys, no e-mails of the users');
        $this->assertSame(['images/a.jpg', 'images/b.jpg', [[['id' => '1', 'attributes' => []]], [], 'https://old.example/api/index.php/v1/content/articles?page[offset]=2'], 'refused',
                ['Accept: application/vnd.api+json', 'X-Joomla-Token: abc'], ['Accept: application/vnd.api+json'], 'https://old.example/api/index.php/v1/content/categories?page[offset]=0&page[limit]=50'], [
            \Talea\Import\Joomla::imagePath('images/a.jpg#joomlaImage://local-images/a.jpg?width=1'), \Talea\Import\Joomla::imagePath('images/b.jpg'),
            \Talea\Import\Joomla::page(['links' => ['self' => 'a', 'next' => 'https://old.example/api/index.php/v1/content/articles?page[offset]=2'], 'data' => [['id' => '1', 'attributes' => []], 'junk', ['id' => '2']]], 'articles'),
            (function (): string { try { \Talea\Import\Joomla::page(['foo' => 1], 'articles'); return 'accepted'; } catch (\RuntimeException $e) { return 'refused'; } })(),
            \Talea\Import\Joomla::headers('abc'), \Talea\Import\Joomla::headers(''), \Talea\Import\Joomla::firstPage('https://old.example/', 'categories')], '3.0 Joomla::imagePath and the API page: items with attributes only, the next link; a non-API answer is refused');
        $joomlaOk = true;
        try {
            (new \Talea\Import\Joomla(TALEA_ROOT . '/tools/fixtures/drupal-fetch.json'))->verify();
            $joomlaOk = false;
        } catch (\RuntimeException) {
        }
        $this->assertTrue($joomlaOk, '3.0 Joomla: a Drupal fetch is refused');

        $drupal = new \Talea\Import\Drupal(TALEA_ROOT . '/tools/fixtures/drupal-fetch.json');
        $drupalRecords = iterator_to_array($drupal->read());
        $this->assertSame(['Author:0a1b2c3d-00aa-4000-8000-0000000000aa', 'Tag:0a1b2c3d-00bb-4000-8000-0000000000b1', 'Tag:0a1b2c3d-00bb-4000-8000-0000000000b2', 'Post:post/published', 'Post:post/published', 'Post:post/draft', 'Post:page/published'], $kinds($drupalRecords), '3.0 Drupal: the author and the tags from the included resources (once, though every page includes them), articles as posts, the unpublished node a draft, the basic page a page');
        $this->assertSame(['hello-from-drupal', '/blog/hello-from-drupal', 'https://old.example/sites/default/files/2024-03/oven.jpg', ['0a1b2c3d-00bb-4000-8000-0000000000b1'], '0a1b2c3d-00aa-4000-8000-0000000000aa', 'Welcome text for the search engines.', 'A warm welcome.', 'en',
                true, '/node/3', '/about', 'sourdough', 'events', 'Marta Editor'], [
            $drupalRecords[3]->slug, $drupalRecords[3]->oldUrl, $drupalRecords[3]->featureImageUrl, $drupalRecords[3]->tagKeys, $drupalRecords[3]->authorKey, $drupalRecords[3]->seoDescription, $drupalRecords[3]->excerpt, $drupalRecords[3]->language,
            str_contains($drupalRecords[3]->html, 'src="https://old.example/sites/default/files/2024-03/oven.jpg"'), $drupalRecords[5]->oldUrl, $drupalRecords[6]->oldUrl, $drupalRecords[1]->slug, $drupalRecords[2]->slug, $drupalRecords[0]->name], '3.0 Drupal: the article – body.processed with the file address made absolute, the image through include, the tag keys, the author, the metatag description, the summary, path.alias as the old address and slug; a node without an alias → /node/nid');
        $this->assertSame([4, 'Authorization: Basic ' . base64_encode('me:secret'), 'Authorization: Bearer tok', ['Accept: application/vnd.api+json'],
                [[['type' => 'node--article', 'id' => 'x', 'attributes' => []]], [['type' => 'file--file', 'id' => 'f', 'attributes' => []]], 'https://old.example/jsonapi/node/article?page[offset]=2'], 'refused'], [
            count($drupal->notes()), \Talea\Import\Drupal::headers('me:secret')[1], \Talea\Import\Drupal::headers('tok')[1], \Talea\Import\Drupal::headers(''),
            \Talea\Import\Drupal::page(['jsonapi' => ['version' => '1.0'], 'data' => [['type' => 'node--article', 'id' => 'x', 'attributes' => []], ['type' => 'bad']], 'included' => [['type' => 'file--file', 'id' => 'f', 'attributes' => []]], 'links' => ['next' => ['href' => 'https://old.example/jsonapi/node/article?page[offset]=2']]], 'articles'),
            (function (): string { try { \Talea\Import\Drupal::page(['data' => []], 'articles'); return 'accepted'; } catch (\RuntimeException $e) { return 'refused'; } })()], '3.0 Drupal: the skipped step is a footnote, Basic for user:password and Bearer otherwise, the API page with included resources and links.next.href');

        $webflow = new \Talea\Import\Webflow(TALEA_ROOT . '/tools/fixtures/webflow-blog.csv', 'https://www.example.com/blog/');
        $webflow->verify();
        $webflowRecords = iterator_to_array($webflow->read());
        $this->assertSame(['Category:c:recipes', 'Tag:t:sourdough', 'Tag:t:spring', 'Post:post/published', 'Category:c:events', 'Tag:t:events', 'Post:post/draft', 'Post:post/draft'], $kinds($webflowRecords), '3.0 Webflow: the category and the tags of a row come before it (on first sight), Draft and Archived rows are drafts');
        $this->assertSame(['65a0000000000000000000a1', 'Spring sourdough', 'https://www.example.com/blog/spring-sourdough', 'https://uploads-ssl.webflow.com/65a0/65a0-oven.png', 'A spring recipe for sourdough.', 'Tue Mar 05 2024 10:00:00 GMT+0000', ['c:recipes'], ['t:sourdough', 't:spring'],
                true, 'Mon Apr 01 2024 08:00:00 GMT+0000', 'https://www.example.com/blog/old-news', 'Recipes', 'recipes', [6, 7]], [
            $webflowRecords[3]->key, $webflowRecords[3]->title, $webflowRecords[3]->oldUrl, $webflowRecords[3]->featureImageUrl, $webflowRecords[3]->excerpt, $webflowRecords[3]->publishedAt, $webflowRecords[3]->categoryKeys, $webflowRecords[3]->tagKeys,
            str_contains($webflowRecords[3]->html, '<img src="https://uploads-ssl.webflow.com/65a0/65a0-oven.png"'), $webflowRecords[6]->publishedAt, $webflowRecords[7]->oldUrl, $webflowRecords[0]->name, $webflowRecords[0]->slug, array_keys(iterator_to_array($webflow->read(6)))], '3.0 Webflow: the row – Item ID as the key, the rich text, the summary, the main image, the dates without the parenthesised zone name, the old address under the entered collection folder; references as slugs with names made from them');
        $this->assertSame([['sourdough', 'spring'], 'Cold rise bread', '', ['name' => '', 'url' => ''], true], [\Talea\Import\Webflow::references('sourdough; Spring ;sourdough;'), \Talea\Import\Webflow::nameFromSlug('cold-rise_bread'), \Talea\Import\Webflow::date('nonsense (Zone)'), $webflow->site(), $webflow->imagesFromAnyHost()], '3.0 Webflow helpers: references, names from slugs, dates; the site is not in the file');
        $webflowOk = true;
        try {
            (new \Talea\Import\Webflow(TALEA_ROOT . '/tools/fixtures/ghost-export.json'))->verify();
            $webflowOk = false;
        } catch (\RuntimeException) {
        }
        $this->assertTrue($webflowOk, '3.0 Webflow: a file without the Name and Slug columns is refused');

        // the fetcher: the URL rules (pure and with the resolved address), the file name, the step plan
        $this->assertSame([true, false, false, false, false, true], array_map(fn (string $u): bool => \Talea\Import\Fetch::allowedUrl($u, 'https://old.example'),
            ['http://www.old.example/api/index.php/v1/content/articles', 'https://other.example/api', 'https://old.example:8443/api', 'https://u@old.example/api', 'ftp://old.example/api', 'https://old.example/jsonapi?page[offset]=50']), '3.0 Fetch::allowedUrl – the old site’s domain (with www), http(s), standard ports, no user name');
        $this->assertSame([false, false, false, false, false, false, false], array_map(\Talea\Import\Fetch::allowedSite(...), ['http://10.0.0.5', 'http://127.0.0.1', 'http://[::1]/', 'http://169.254.169.254', 'http://192.168.1.1/', 'http://100.64.0.1', 'http://0.0.0.0']), '3.0 Fetch::allowedSite – internal, loopback, link-local and private addresses are refused');
        $this->assertSame(['joomla-old-site-example.json', 'joomla', 'webflow', ['joomla', 'drupal'], ['articles', 'tags'], ['articles'], false, true, true], [
            \Talea\Import\Fetch::fileName('joomla', 'https://www.Old-Site.example/'), \Talea\Import\Sources::keyOfFile('joomla-old-site-example.json'), \Talea\Import\Sources::keyOfFile('webflow-blog.csv'), array_keys(\Talea\Import\Sources::remote()),
            \Talea\Import\Fetch::state(\Talea\Import\Joomla::class, 'https://old.example/', ['tags', 'bogus', 'articles'])['steps'], \Talea\Import\Fetch::state(\Talea\Import\Drupal::class, 'https://old.example', [])['steps'],
            \Talea\Import\Fetch::skeleton('drupal', 'https://old.example/')['talea_fetch']['done'], \Talea\Import\Fetch::sessionKey('a') === \Talea\Import\Fetch::sessionKey('a'), \Talea\Import\Fetch::sessionKey('a') !== \Talea\Import\Fetch::sessionKey('b')], '3.0 Fetch: the file name from the domain, the plan keeps the required first step and known ticked steps in the system’s order, the skeleton is not done');
        $halfFetched = tempnam(sys_get_temp_dir(), 'talea-fetch');
        file_put_contents($halfFetched, json_encode(\Talea\Import\Fetch::skeleton('joomla', 'https://old.example')));
        $halfOk = 'accepted';
        try {
            (new \Talea\Import\Joomla($halfFetched))->verify();
        } catch (\RuntimeException $e) {
            $halfOk = $e->getMessage();
        }
        unlink($halfFetched);
        $this->assertSame('The fetch from the site did not finish. Start it again.', $halfOk, '3.0 Fetch: a file whose fetch did not finish is refused by the Source');
    }
}
