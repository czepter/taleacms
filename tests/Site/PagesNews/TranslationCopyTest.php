<?php

declare(strict_types=1);

namespace Talea\Tests\Site\PagesNews;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** A translation of a builder page starts as a copy of the original's build and opens in the builder; an unknown admin section says so. */
#[Group('site')]
final class TranslationCopyTest extends SiteTestCase
{
    private const BUILD = '{"v":1,"children":[{"id":"h1abc","type":"heading","tag":"h1","content":{"text":"Hello"}}]}';

    public function testTheTranslationOfABuilderPageIsACopyOpenedInTheBuilder(): void
    {
        $site = $this->site();
        $site->setting('additional_languages', 'de');
        $site->mcpText('create_page', ['title' => 'Copy original']);
        $original = (int) $site->value("SELECT page_id FROM tl_pages WHERE slug = 'copy-original'");
        $site->exec('UPDATE tl_pages SET build_draft = ? WHERE page_id = ?', [self::BUILD, $original]);
        $originalId = $site->publicId('pages', $original);

        $form = $this->assertPage('/admin.php?module=pages&action=new&language=de&translation_of=' . $originalId, 200, 'name="copy_build" value="1"', message: 'the form of a translation of a builder page carries the copy flag');
        $this->assertTrue($form->contains('Create the translation and open the builder'));
        $this->assertFalse($form->contains('name="template"'), 'no template choice for a translation');

        $saved = $this->adminPost('/admin.php?module=pages&action=save', ['title' => 'Kopie', 'language' => 'de', 'translation_of' => $originalId, 'copy_build' => '1', 'text' => '', 'visible' => '1'],
            '/admin.php?module=pages&action=new&language=de&translation_of=' . $originalId);
        $copy = $site->rows("SELECT page_id, build_draft FROM tl_pages WHERE language = 'de' AND translation_of = ?", [$original]);
        $this->assertCount(1, $copy);
        $this->assertSame(self::BUILD, json_encode(json_decode((string) $copy[0]['build_draft']), JSON_UNESCAPED_SLASHES), 'the draft is a copy of the original build');
        $this->assertStringContainsString('action=builder&id=' . $site->publicId('pages', (int) $copy[0]['page_id']), $saved->redirect, 'the translation opens in the builder');
    }

    public function testAnUnknownSectionDoesNotExist(): void
    {
        $this->assertPage('/admin.php?module=nothing_here', 404, 'This page does not exist.');
    }
}
