<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\AdminBuilder;

use Kaleta\Tests\Site\Support\Response;
use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** The page builder in the administration: draft, conflicts, library, classes, preview, publishing, versions (was: section 8 "builder stránek"). */
#[Group('site')]
final class BuilderPagesTest extends SiteTestCase
{
    use AuthorSession;

    private const string BUILD = '{"v":1,"children":[{"id":"sek1","type":"sekce","children":[{"id":"nad1","type":"heading","tag":"h1","obsah":{"text":"Builder test"},"style":{"zaklad":{"color":"primary"},"mobil":{"font_size":"2"}},"classes":["karta"]},{"id":"faq1","type":"faq","obsah":{"items":[{"question":"Kolik to stojí?","answer":"<p>Záleží na rozsahu.</p>"}]}},{"id":"txt1","type":"text","obsah":{"html":"<h2>Jak to funguje</h2><p>Krok za krokem.</p><h2>Jak to funguje</h2><h3 id=\"vlastni\">Vlastní</h3>"}},{"id":"zly1","type":"skript"}]}]}';

    private function pageId(): int
    {
        return (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'o-nas'");
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
        $this->assertPage('/admin.php?module=pages&action=builder&id=' . $this->pageId(), 200, 'id="stavitel-data"', message: 'builder opens and converts a text page');
    }

    public function testDraftSaveReturnsTheCleanedBuildAndErrors(): void
    {
        $response = $this->pageAction('build_save', ['build' => self::BUILD]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('"ok":true', $response->body, 'saving the draft is ok');
        $this->assertStringContainsString('Neznámý typ prvku', $response->body, 'the unknown element is reported');
    }

    public function testInvalidJsonAndConflictsAreRefused(): void
    {
        $this->assertSame(400, $this->pageAction('build_save', ['build' => '{nesmysl'])->status, 'invalid build JSON refused');

        $conflict = $this->pageAction('build_save', ['verze' => '0000000000000000', 'build' => self::BUILD]);
        $this->assertSame(409, $conflict->status, 'save from a foreign version refused (concurrent edit)');
        $this->assertStringContainsString('"konflikt":true', $conflict->body, 'the conflict answer says so');
        $this->assertStringContainsString('Builder test', $conflict->body, 'the conflict returns the newer version from the server');

        $this->assertSame(409, $this->pageAction('build_publish', ['verze' => '0000000000000000'])->status, 'publishing from a foreign version refused');
        $this->assertSame(200, $this->pageAction('build_save', ['verze' => '0000000000000000', 'prepsat' => '1', 'build' => self::BUILD])->status, 'overwriting a foreign version on request');
    }

    public function testBuilderRequiresCsrfAndTheLibraryOnlyPost(): void
    {
        $this->assertSame(400, $this->pageAction('build_save', ['build' => self::BUILD], csrf: false)->status, 'builder without CSRF refused');
        $this->assertPage('/admin.php?module=pages&action=build_section&id=' . $this->pageId() . '&key=faq', 404, message: 'section library only via POST');
    }

    public function testLibrarySectionAndClasses(): void
    {
        $section = $this->pageAction('build_section&key=vyhody');
        $this->assertSame(200, $section->status);
        $this->assertStringContainsString('"karta"', $section->body, 'a section from the library creates its classes');

        $class = $this->pageAction('build_class', ['nazev' => 'karta', 'style' => '{"zaklad":{"background":"surface","padding_y":"l"}}', 'css' => 'letter-spacing: 0.01em; background: url(x)']);
        $this->assertSame(200, $class->status);
        $this->assertStringContainsString('Nepovolená deklarace', $class->body, 'class saved, dangerous CSS dropped');

        $this->assertSame(400, $this->pageAction('build_class', ['nazev' => 'Karta Velka'])->status, 'invalid class name refused');
        $this->assertSame('1', (string) $this->site()->value("SELECT value LIKE '%\"karta\"%' FROM ka_settings WHERE name = 'look_draft'"), 'a change of an existing class in the builder goes to the draft look');
    }

    public function testDraftIsNotOnTheWebBeforePublishingAndPreviewsWork(): void
    {
        $this->publishLook();
        $visitor = $this->site()->client();

        $this->assertStringNotContainsString('Builder test', $visitor->get('/o-nas')->body, 'the draft is not on the web before publishing');
        $this->assertPage('/o-nas?build=koncept&editor=1', 200, 'data-ka-id="nad1"', message: 'draft preview for the editor');
        $this->assertPage('/o-nas?build=koncept', 200, 'noindex', message: 'draft preview is not indexed');
        $this->assertStringNotContainsString('Builder test', $visitor->get('/o-nas?build=koncept&editor=1')->body, 'the visitor does not see the draft preview');

        $share = $this->pageAction('build_share', ['days' => '3']);
        $link = (string) ($share->json()['link'] ?? '');
        $shared = $visitor->get($link);
        $this->assertSame(200, $share->status);
        $this->assertStringStartsWith($this->site()->base . '/o-nas?build=koncept&preview_key=', $link, 'signed share link');
        $this->assertStringContainsString('Builder test', $shared->body, 'the shared link shows the draft without signing in');
        $this->assertStringNotContainsString('data-ka-id', $shared->body, 'and without editor marks');
    }

    public function testPublishedBuildOnTheWeb(): void
    {
        $this->assertSame(200, $this->pageAction('build_publish')->status, 'publishing the build');
        $this->site()->clearPageCache();
        $body = $this->site()->client()->get('/o-nas')->body;

        $this->assertStringContainsString('<h1 id="s-nad1" class="karta">Builder test</h1>', $body, 'published build on the web, one tag per element');
        $this->assertStringContainsString('<h2 id="jak-to-funguje">', $body, 'subheading anchors');
        $this->assertStringContainsString('<h2 id="jak-to-funguje-2">', $body, 'anchors are unique');
        $this->assertStringContainsString('<h3 id="vlastni">', $body, 'a custom id stays');
        $this->assertStringNotContainsString('data-ka-id', $body, 'no editor marks on the public web');
        $this->assertStringContainsString('@layer prvky', $body, 'element CSS in layers');
        $this->assertStringContainsString('#s-nad1 { color: var(--ka-barva-primarni); }', $body, 'element style');
        $this->assertStringContainsString('.karta { background-color: var(--ka-barva-plocha)', $body, 'class style');
        $this->assertStringContainsString('"FAQPage"', $body, 'questions and answers as structured data');
        $this->assertPage('/hledani?q=Builder+test', 200, 'Nalezeno: 1', message: 'search finds the build content');
    }

    public function testVersionsRestoreDiscardAndReturnToText(): void
    {
        $this->pageAction('build_save', ['build' => str_replace('Builder test', 'Druhá verze', self::BUILD)]);
        $this->pageAction('build_publish');
        $id = $this->pageId();

        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM ka_build_revisions WHERE page_id = $id AND build LIKE '%Builder test%'"), 'the previously published version is in the history');
        $idr = (int) $this->site()->value("SELECT revision_id FROM ka_build_revisions WHERE page_id = $id AND build LIKE '%Builder test%'");

        $this->assertStringContainsString('Builder test', $this->pageAction('build_restore', ['idr' => (string) $idr])->body, 'restoring a version to the draft');
        $this->assertStringContainsString('Druhá verze', $this->pageAction('build_discard')->body, 'discarding changes returns the published build');

        $author = $this->authorClient();
        $this->assertSame(403, $author->get('/admin.php?module=pages&action=builder&id=' . $id)->status, 'a news author may not use the builder');

        $this->pageAction('build_text', ['page_id' => (string) $id]);
        $this->site()->clearPageCache();
        $body = $this->site()->client()->get('/o-nas')->body;
        $this->assertStringContainsString('<h1>Druhá verze</h1>', $body, 'return to text keeps the content');
        $this->assertStringContainsString('class="obal obsah"', $body, 'without the layout');
    }
}
