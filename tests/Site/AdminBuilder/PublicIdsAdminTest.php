<?php

declare(strict_types=1);

namespace Talea\Tests\Site\AdminBuilder;

use Talea\Core\Db;
use Talea\Core\Uuid;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * HF-16, the administration: no link, form value or JSON answer carries the integer key of a row of a table with a public id, and
 * the administration refuses an integer where a public id belongs (it is an unknown record, like a missing one).
 */
#[Group('site')]
final class PublicIdsAdminTest extends SiteTestCase
{
    /** Integer ids of a public-id table in a link or a form value of a list: `id=12`, `item=12`, `value="12"` of the id fields. */
    private const string INTEGER_ID = '/(?:[?&;](?:amp;)?(?:id|item|article|parent|page_id|news_id|media_id|category_id|user_id)=\d+(?![\w.-])|[?&;](?:amp;)?section=[1-9]\d*(?![\w.-])|name="(?:page_id|news_id|media_id|category_id|user_id|folder_id|id|selected\[\])" value="\d+")/';

    public function testIntegerIdsAreRefusedOnPagesAndNews(): void
    {
        $site = $this->site();
        $pageId = (int) $site->value("SELECT page_id FROM tl_pages WHERE deleted_at IS NULL ORDER BY page_id LIMIT 1");
        $newsId = (int) $site->value('SELECT news_id FROM tl_news WHERE deleted_at IS NULL ORDER BY news_id LIMIT 1');
        $this->assertGreaterThan(0, $pageId);
        $this->assertGreaterThan(0, $newsId);

        $this->assertPage('/admin.php?module=pages&action=edit&id=' . $site->publicId('pages', $pageId), 200, 'name="page_id"', message: 'a page opens by its public id');
        $this->assertPage('/admin.php?module=pages&action=edit&id=' . $pageId, 404, message: 'a page does not open by its integer key');
        $this->assertPage('/admin.php?module=news&action=edit&id=' . $site->publicId('news', $newsId), 200, 'name="news_id"', message: 'a news item opens by its public id');
        $this->assertPage('/admin.php?module=news&action=edit&id=' . $newsId, 404, message: 'a news item does not open by its integer key');

        $pageTitle = (string) $site->value('SELECT title FROM tl_pages WHERE page_id = ?', [$pageId]);
        $pages = (int) $site->value('SELECT COUNT(*) FROM tl_pages');
        $response = $site->admin()->post('/admin.php?module=pages&action=save', ['_csrf' => $site->csrf(), 'page_id' => (string) $pageId, 'title' => 'Changed by integer', 'slug' => 'changed-by-integer']);
        $this->assertSame(404, $response->status, 'saving a page under its integer key is refused');
        $this->assertSame($pageTitle, (string) $site->value('SELECT title FROM tl_pages WHERE page_id = ?', [$pageId]), 'the page is not changed');
        $this->assertSame($pages, (int) $site->value('SELECT COUNT(*) FROM tl_pages'), 'and no page is created in its place');

        $newsTitle = (string) $site->value('SELECT title FROM tl_news WHERE news_id = ?', [$newsId]);
        $news = (int) $site->value('SELECT COUNT(*) FROM tl_news');
        $response = $site->admin()->post('/admin.php?module=news&action=save', ['_csrf' => $site->csrf(), 'news_id' => (string) $newsId, 'title' => 'Changed by integer',
            'category_id' => $site->publicId('categories', (int) $site->value('SELECT MIN(category_id) FROM tl_categories')), 'author_id' => $site->publicId('users', 1)]);
        $this->assertSame(404, $response->status, 'saving a news item under its integer key is refused');
        $this->assertSame($newsTitle, (string) $site->value('SELECT title FROM tl_news WHERE news_id = ?', [$newsId]), 'the news item is not changed');
        $this->assertSame($news, (int) $site->value('SELECT COUNT(*) FROM tl_news'), 'and no news item is created in its place');

        // an integer where a related record belongs counts as no record: the category field is refused, nothing is saved
        $response = $site->admin()->post('/admin.php?module=news&action=save', ['_csrf' => $site->csrf(), 'news_id' => '0', 'title' => 'Integer category',
            'category_id' => (string) $site->value('SELECT MIN(category_id) FROM tl_categories'), 'author_id' => $site->publicId('users', 1)]);
        $this->assertSame(200, $response->status, 'the form comes back');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_news WHERE title = 'Integer category'"), 'a news item with a category given as a number is not saved');
    }

    public function testListsCarryPublicIdsOnly(): void
    {
        $site = $this->site();
        $site->exec("INSERT INTO tl_media (image_path, thumb_path, name, created_at) VALUES ('media/2026/01/public-ids.jpg', 'media/2026/01/public-ids-thumb.jpg', 'Public ids', NOW())");
        $folderId = (int) $site->value("SELECT folder_id FROM tl_media_folders LIMIT 1");
        if ($folderId === 0) {
            $site->exec("INSERT INTO tl_media_folders (name) VALUES ('Folder')");
        }

        $lists = [
            'pages' => ['/admin.php?module=pages', 'pages'],
            'news' => ['/admin.php?module=news', 'news'],
            'media' => ['/admin.php?module=media', 'media'],
            'users' => ['/admin.php?module=users', 'users'],
        ];
        foreach ($lists as $name => [$path, $table]) {
            $body = $this->assertPage($path, 200, message: "the $name list opens")->body;
            $this->assertSame(0, preg_match(self::INTEGER_ID, $body, $match, PREG_OFFSET_CAPTURE), "the $name list carries no integer id: " . substr($body, max(0, (int) ($match[0][1] ?? 0) - 120), 200));
            $publicIds = array_column($site->rows("SELECT public_id FROM tl_$table"), 'public_id');
            $this->assertNotSame([], $publicIds, "$name has rows");
            $shown = array_filter($publicIds, fn (string $uuid): bool => str_contains($body, $uuid));
            $this->assertNotSame([], $shown, "the $name list carries the public ids of its rows");
            foreach ($shown as $uuid) {
                $this->assertTrue(Uuid::valid($uuid));
            }
        }

        // a form of one record: the hidden field and the links carry the public id
        $pageId = (int) $site->value('SELECT page_id FROM tl_pages WHERE deleted_at IS NULL ORDER BY page_id LIMIT 1');
        $form = $this->assertPage('/admin.php?module=pages&action=edit&id=' . $site->publicId('pages', $pageId), 200)->body;
        $this->assertSame(0, preg_match(self::INTEGER_ID, $form, $match, PREG_OFFSET_CAPTURE), 'the page form carries no integer id: ' . substr($form, max(0, (int) ($match[0][1] ?? 0) - 120), 200));
        $this->assertStringContainsString('name="page_id" value="' . $site->publicId('pages', $pageId) . '"', $form);

        // the JSON of the media library
        $json = json_decode($this->assertPage('/admin.php?module=media&action=listing', 200)->body, true);
        $this->assertNotSame([], $json['images']);
        foreach ($json['images'] as $image) {
            $this->assertTrue(Uuid::valid($image['id']), 'a media library answer names its image by the public id');
        }
        foreach ($json['folders'] as $folder) {
            $this->assertTrue(Uuid::valid($folder['id']), 'and its folders');
        }
    }

    /** Every module list and detail the administration offers opens without the integer key of a row of a public-id table in its HTML. */
    public function testEveryAdminAreaShowsNoRowNumbers(): void
    {
        $site = $this->site();
        $keys = implode('|', array_unique(array_values(Db::PRIMARY_KEYS)));
        $number = '/(?:"(?:' . $keys . '|page_id|news_id)":\s*\d+|(?:name|data-[a-z-]+)="(?:' . $keys . ')" value="\d+"|<h1[^>]*>[^<]*#\d+)/';
        $pages = ['pages', 'news', 'media', 'users', 'categories', 'tags', 'collections', 'popups', 'components', 'enquiries', 'subscribers', 'newsletters', 'redirects',
            'bookings', 'requests', 'parts', 'menu', 'changelog', 'fleet', 'settings', 'health'];
        foreach ($pages as $module) {
            $response = $site->admin()->get('/admin.php?module=' . $module);
            if ($response->status !== 200) {
                continue; // a module that is off or not for this site
            }
            $this->assertSame(0, preg_match(self::INTEGER_ID, $response->body, $match, PREG_OFFSET_CAPTURE), "$module: an integer id in a link or field: " . substr($response->body, max(0, (int) ($match[0][1] ?? 0) - 100), 200));
            $this->assertSame(0, preg_match($number, $response->body, $match, PREG_OFFSET_CAPTURE), "$module: a row number in the page: " . substr($response->body, max(0, (int) ($match[0][1] ?? 0) - 100), 200));
        }
        // the headings of a detail name the record, not its number
        $site->exec("INSERT INTO tl_enquiries (created_at, status, email, data, topic) VALUES (NOW(), 0, 'scan@example.test', '{}', 'Scan')");
        $enquiry = $site->publicId('enquiries', (int) $site->value('SELECT MAX(enquiry_id) FROM tl_enquiries'));
        $heading = $this->assertPage('/admin.php?module=enquiries&action=detail&id=' . $enquiry, 200)->body;
        $this->assertStringContainsString('scan@example.test', $heading);
        $this->assertSame(0, preg_match('/<h1[^>]*>[^<]*#\d+/', $heading), 'the enquiry heading has no row number');
    }

    /**
     * The code of the administration does not read an integer id of a table with a public id: every id parameter goes through
     * Module::idParam() (or Db::internalId()), and no view or admin class puts the key of such a row in a link or a field.
     */
    public function testNoIntegerIdsInTheAdministrationCode(): void
    {
        $root = dirname(__DIR__, 3) . '/system';
        $files = [];
        foreach (['src/Admin', 'views/admin'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $this->assertNotSame([], $files);

        $keys = array_values(array_unique(array_filter(Db::PRIMARY_KEYS, fn (string $key): bool => $key !== 'id'))); // 'id' alone is checked by name below
        $key = implode('|', array_map(preg_quote(...), $keys));
        $patterns = array_map(fn (string $pattern): string => str_replace('KEYS', $key, $pattern), [
            // a link parameter or a form value built from the integer key of a row, e.g. 'id' => $row['page_id']
            'a link parameter from an integer key' => '/\'(?:id|item|edit|parent|section|article|page|folder)\' => (?:\(int\) )?\$\w+\[\'(?:KEYS)\'\]/',
            'a form value from an integer key' => '/value="<\?= (?:\(int\) )?\$[\w\[\]\'>-]+\[\'(?:KEYS)\'\] \?>"/',
            'an address from an integer key' => '/[?&]id=\' \. (?:\(int\) )?\$[\w\[\]\'>-]+\[\'(?:KEYS)\'\]/',
            // a request parameter read as a number where a public id travels
            'an id read as a number' => '/(?:get|post)Int\(\'(?:id|[a-z_]*_id|item|edit|parent|section|article|original|translation_of|category|merge_into|staff_member)\'/',
        ]);
        // tolerated: ids of tables without a public id (roles, notes, schedules, revisions, drafts, log records, webhook deliveries,
        // days off, hours exceptions, import states), which never leave the administration as row keys of the public tables
        $allowed = [
            'Modules/Roles.php', 'Modules/Notebook.php', 'Modules/Schedules.php', 'Modules/ChangeLog.php', 'Modules/Appearance.php',
            'BuilderActions.php', // draft comments, build revisions
            'Modules/Pages.php', 'Modules/Collections.php', // revision_id of the version history
            'Modules/News.php', // revision of a news item, a social draft
            'Modules/Settings.php', // webhook delivery, hours exception
            'Modules/Bookings.php', // a day off
            'Account.php', // 'lifetime', 'delete_token' is resolved by Db::internalId()
        ];
        // tolerated matches: the number of a component is what a build stores to point at it (Builder\Elements\Component), the editor's
        // choice list hands it back unchanged – it is a reference inside content, not an address of the record
        $tolerated = ["'id' => (int) \$k['component_id']"];
        $found = [];
        foreach ($files as $path) {
            $relative = substr($path, strlen($root) + 1);
            $text = (string) file_get_contents($path);
            foreach ($patterns as $label => $pattern) {
                if ($label === 'an id read as a number') {
                    // ids of other tables are tolerated in the files listed above, and 'revision_id' and 'page' (page numbers) never match
                    foreach ($allowed as $file) {
                        if (str_ends_with($relative, $file)) {
                            continue 2;
                        }
                    }
                }
                if (preg_match_all($pattern, $text, $matches) && ($hits = array_diff($matches[0], $tolerated)) !== []) {
                    $found[] = $relative . ': ' . $label . ' – ' . implode(', ', array_slice($hits, 0, 3));
                }
            }
        }
        $this->assertSame([], $found, "integer ids in the administration:\n" . implode("\n", $found));
    }
}
