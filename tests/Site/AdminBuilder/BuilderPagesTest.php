<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AdminBuilder;

use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The page builder in the administration: draft, conflicts, library, classes, preview, publishing, versions (was: section 8 "page builder"). */
#[Group('site')]
final class BuilderPagesTest extends SiteTestCase
{
    use AuthorSession;

    private const string BUILD = '{"v":1,"children":[{"id":"sek1","type":"section","children":[{"id":"nad1","type":"heading","tag":"h1","content":{"text":"Builder test"},"style":{"base":{"color":"primary"},"mobile":{"font_size":"2"}},"classes":["card"]},{"id":"faq1","type":"faq","content":{"items":[{"question":"How much does it cost?","answer":"<p>It depends on the scope.</p>"}]}},{"id":"txt1","type":"text","content":{"html":"<h2>How it works</h2><p>Step by step.</p><h2>How it works</h2><h3 id=\\"vlastni\\">Custom</h3>"}},{"id":"bad1","type":"nonexistent_type"}]}]}';

    private function pageId(): int
    {
        return (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'about-us'");
    }

    /** The old page_action(): a POST of the builder with the session token. @param array<string, string> $fields */
    private function pageAction(string $action, array $fields = [], bool $csrf = true): Response
    {
        $fields = ($csrf ? ['_csrf' => $this->site()->csrf()] : []) + $fields;

        return $this->site()->admin()->post('/admin.php?module=pages&action=' . $action . '&id=' . $this->pageId(), $fields);
    }

    private function publishLook(): void
    {
        $this->adminPost('/admin.php?module=appearance&action=publish_look', [], '/admin.php?module=appearance');
        $this->site()->clearPageCache();
    }

    public function testBuilderOpensAndConvertsATextPage(): void
    {
        $this->assertPage('/admin.php?module=pages&action=builder&id=' . $this->pageId(), 200, 'id="builder-data"', message: 'builder opens and converts a text page');
    }

    public function testDraftSaveReturnsTheCleanedBuildAndErrors(): void
    {
        $response = $this->pageAction('build_save', ['build' => self::BUILD]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('"ok":true', $response->body, 'saving the draft is ok');
        $this->assertStringContainsString('Unknown element type', $response->body, 'the unknown element is reported');
    }

    public function testInvalidJsonAndConflictsAreRefused(): void
    {
        $this->assertSame(400, $this->pageAction('build_save', ['build' => '{nonsense'])->status, 'invalid build JSON refused');

        $conflict = $this->pageAction('build_save', ['version' => '0000000000000000', 'build' => self::BUILD]);
        $this->assertSame(409, $conflict->status, 'save from a foreign version refused (concurrent edit)');
        $this->assertStringContainsString('"conflict":true', $conflict->body, 'the conflict answer says so');
        $this->assertStringContainsString('Builder test', $conflict->body, 'the conflict returns the newer version from the server');

        $this->assertSame(409, $this->pageAction('build_publish', ['version' => '0000000000000000'])->status, 'publishing from a foreign version refused');
        $this->assertSame(200, $this->pageAction('build_save', ['version' => '0000000000000000', 'overwrite' => '1', 'build' => self::BUILD])->status, 'overwriting a foreign version on request');
    }

    public function testBuilderRequiresCsrfAndTheLibraryOnlyPost(): void
    {
        $this->assertSame(400, $this->pageAction('build_save', ['build' => self::BUILD], csrf: false)->status, 'builder without CSRF refused');
        $this->assertPage('/admin.php?module=pages&action=build_section&id=' . $this->pageId() . '&key=faq', 404, message: 'section library only via POST');
    }

    public function testLibrarySectionAndClasses(): void
    {
        $section = $this->pageAction('build_section&key=benefits');
        $this->assertSame(200, $section->status);
        $this->assertStringContainsString('"card"', $section->body, 'a section from the library creates its classes');

        $class = $this->pageAction('build_class', ['name' => 'card', 'style' => '{"base":{"background":"surface","padding_y":"l"}}', 'css' => 'letter-spacing: 0.01em; background: url(x)']);
        $this->assertSame(200, $class->status);
        $this->assertStringContainsString('Declaration not allowed', $class->body, 'class saved, dangerous CSS dropped');

        $this->assertSame(400, $this->pageAction('build_class', ['name' => 'Big Card'])->status, 'invalid class name refused');
        $this->assertSame('1', (string) $this->site()->value("SELECT value LIKE '%\"card\"%' FROM ka_settings WHERE name = 'look_draft'"), 'a change of an existing class in the builder goes to the draft look');
    }

    public function testDraftIsNotOnTheWebBeforePublishingAndPreviewsWork(): void
    {
        $this->publishLook();
        $visitor = $this->site()->client();

        $this->assertStringNotContainsString('Builder test', $visitor->get('/about-us')->body, 'the draft is not on the web before publishing');
        $this->assertPage('/about-us?build=draft&editor=1', 200, 'data-ka-id="nad1"', message: 'draft preview for the editor');
        $this->assertPage('/about-us?build=draft', 200, 'noindex', message: 'draft preview is not indexed');
        $this->assertStringNotContainsString('Builder test', $visitor->get('/about-us?build=draft&editor=1')->body, 'the visitor does not see the draft preview');

        $share = $this->pageAction('build_share', ['days' => '3']);
        $link = (string) ($share->json()['link'] ?? '');
        $shared = $visitor->get($link);
        $this->assertSame(200, $share->status);
        $this->assertStringStartsWith($this->site()->base . '/about-us?build=draft&preview_key=', $link, 'signed share link');
        $this->assertStringContainsString('Builder test', $shared->body, 'the shared link shows the draft without signing in');
        $this->assertStringNotContainsString('data-ka-id', $shared->body, 'and without editor marks');
    }

    public function testPublishedBuildOnTheWeb(): void
    {
        $this->assertSame(200, $this->pageAction('build_publish')->status, 'publishing the build');
        $this->site()->clearPageCache();
        $body = $this->site()->client()->get('/about-us')->body;

        $this->assertStringContainsString('<h1 id="s-nad1" class="card">Builder test</h1>', $body, 'published build on the web, one tag per element');
        $this->assertStringContainsString('<h2 id="how-it-works">', $body, 'subheading anchors');
        $this->assertStringContainsString('<h2 id="how-it-works-2">', $body, 'anchors are unique');
        $this->assertStringContainsString('<h3 id="custom">', $body, 'a custom id stays');
        $this->assertStringNotContainsString('data-ka-id', $body, 'no editor marks on the public web');
        $this->assertStringContainsString('@layer elements', $body, 'element CSS in layers');
        $this->assertStringContainsString('#s-nad1 { color: var(--ka-color-primary); }', $body, 'element style');
        $this->assertStringContainsString('.card { background-color: var(--ka-color-surface)', $body, 'class style');
        $this->assertStringContainsString('"FAQPage"', $body, 'questions and answers as structured data');
        $this->assertPage('/search?q=Builder+test', 200, 'Found: 1', message: 'search finds the build content');
    }

    public function testVersionsRestoreDiscardAndReturnToText(): void
    {
        $this->pageAction('build_save', ['build' => str_replace('Builder test', 'Second version', self::BUILD)]);
        $this->pageAction('build_publish');
        $id = $this->pageId();

        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM ka_build_revisions WHERE page_id = $id AND build LIKE '%Builder test%'"), 'the previously published version is in the history');
        $idr = (int) $this->site()->value("SELECT revision_id FROM ka_build_revisions WHERE page_id = $id AND build LIKE '%Builder test%'");

        $this->assertStringContainsString('Builder test', $this->pageAction('build_restore', ['revision_id' => (string) $idr])->body, 'restoring a version to the draft');
        $this->assertStringContainsString('Second version', $this->pageAction('build_discard')->body, 'discarding changes returns the published build');

        $author = $this->authorClient();
        $this->assertSame(403, $author->get('/admin.php?module=pages&action=builder&id=' . $id)->status, 'a news author may not use the builder');

        $this->pageAction('build_text', ['page_id' => (string) $id]);
        $this->site()->clearPageCache();
        $body = $this->site()->client()->get('/about-us')->body;
        $this->assertStringContainsString('<h1>Second version</h1>', $body, 'return to text keeps the content');
        $this->assertStringContainsString('class="wrap content"', $body, 'without the layout');
    }
}
