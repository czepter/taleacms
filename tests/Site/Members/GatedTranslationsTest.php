<?php

declare(strict_types=1);

namespace Talea\Tests\Site\Members;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\Response;
use Talea\Tests\Site\Support\SiteTestCase;

/**
 * A language version of members-only content stays members-only (Core\Members): the translation starts as a copy of the original's text,
 * so a translation that came out public would put the gated text on the open web without any warning. Walked the ways a translation is
 * made: Claude's create_page with translation_of, and the editor's "Add translation" forms of a page, a news item and a collection item.
 */
#[Group('site')]
final class GatedTranslationsTest extends SiteTestCase
{
    /** @var array<string, string> */
    private static array $s = [];

    /**
     * The editor submits the form whose action ends with $action as the browser would: every field as the page filled it, $changes on top.
     *
     * @param array<string, mixed> $changes
     */
    private function submit(Response $page, string $action, array $changes = []): Response
    {
        $document = \Dom\HTMLDocument::createFromString($page->body, LIBXML_NOERROR);
        $fields = [];
        foreach ($document->querySelectorAll('form') as $form) {
            $target = html_entity_decode((string) $form->getAttribute('action'));
            if (!str_ends_with((string) parse_url($target, PHP_URL_PATH), $action)) {
                continue;
            }
            foreach ($form->querySelectorAll('input[name], textarea[name], select[name]') as $field) {
                $name = (string) $field->getAttribute('name');
                $type = strtolower((string) $field->getAttribute('type'));
                $value = match ($field->localName) {
                    'textarea' => $field->textContent,
                    'select' => (string) ($field->querySelector('option[selected]') ?? $field->querySelector('option'))?->getAttribute('value'),
                    default => in_array($type, ['checkbox', 'radio'], true) && !$field->hasAttribute('checked') || in_array($type, ['submit', 'button', 'file'], true) ? null : (string) $field->getAttribute('value'),
                };
                if ($value === null) {
                    continue;
                }
                if (str_ends_with($name, '[]')) {
                    $fields[substr($name, 0, -2)][] = $value;
                } else {
                    $fields[$name] = $value;
                }
            }

            return $this->site()->admin()->post($target, $changes + $fields);
        }
        $this->fail("No form with the action $action on the page.");
    }

    private function anonymousSees(string $path, string $secret, string $message): void
    {
        $answer = $this->site()->client('anonymous-' . md5($path))->get($path);
        $this->assertSame(403, $answer->status, "$message: $path must ask for the sign-in");
        $this->assertStringNotContainsString($secret, $answer->body, "$message: $path must not show the text");
        $this->assertStringContainsString('tl-member-email', $answer->body, "$message: the sign-in form");
    }

    public function testSetUpAGatedPageNewsItemAndCollectionItem(): void
    {
        $site = $this->site();
        $site->setting('extensions', $site->settingValue('extensions') . ',members');
        $site->setting('additional_languages', 'de');
        $this->adminPost('/admin.php?module=members&action=group_save', ['name' => 'Clients'], '/admin.php?module=members');
        self::$s['clients'] = (string) $site->value("SELECT public_id FROM tl_member_groups WHERE name = 'Clients'");
        $this->assertNotSame('', self::$s['clients']);

        $page = $site->mcpResult('create_page', ['title' => 'Club prices', 'slug' => 'club-prices', 'content' => '<p>Secret club price 77</p>', 'visible' => true]);
        $site->mcpResult('set_content_groups', ['type' => 'page', 'id' => $page['id'], 'groups' => [self::$s['clients']]]);
        self::$s['page'] = $page['id'];

        $category = (string) $site->value('SELECT name FROM tl_categories ORDER BY category_id LIMIT 1');
        $news = $site->mcpResult('create_news', ['title' => 'Members news headline', 'category' => $category, 'content' => '<p>Secret news body 99</p>']);
        $site->exec('UPDATE tl_news SET visible = 1, published_at = NOW() - INTERVAL 1 DAY WHERE news_id = ?', [$site->rowId($news['id'])]);
        $site->mcpResult('set_content_groups', ['type' => 'news', 'id' => $news['id'], 'groups' => [self::$s['clients']]]);
        self::$s['news'] = $news['id'];
        $german = $site->mcpResult('create_category', ['name' => 'Neuigkeiten']);
        $site->exec("UPDATE tl_categories SET language = 'de' WHERE category_id = ?", [$site->rowId((string) ($german['id'] ?? ''))]);
        self::$s['germanCategory'] = (string) $site->value("SELECT public_id FROM tl_categories WHERE language = 'de'");

        $site->mcpResult('create_collection', ['name' => 'Resources', 'slug' => 'resources', 'item_pages' => true, 'fields' => [['label' => 'Text', 'type' => 'text']]]);
        $item = $site->mcpResult('save_collection_item', ['collection' => 'resources', 'name' => 'Handbook item', 'slug' => 'handbook', 'values' => ['text' => 'Secret item text 55'], 'visible' => true]);
        $site->exec('UPDATE tl_collection_items SET visible = 1 WHERE public_id = ?', [$item['id']]);
        $site->mcpResult('set_content_groups', ['type' => 'item', 'id' => $item['id'], 'groups' => [self::$s['clients']]]);
        self::$s['item'] = $item['id'];
        self::$s['collection'] = (string) $site->value("SELECT public_id FROM tl_collections WHERE slug = 'resources'");

        $this->anonymousSees('/club-prices', 'Secret club price', 'the original page');
        $this->anonymousSees('/resources/handbook', 'Secret item text', 'the original item');
    }

    public function testClaudesTranslationOfAGatedPageIsGatedToo(): void
    {
        $site = $this->site();
        // the documented way (Mcp\Prompts: translate a page): create_page with language, translation_of and the copied content
        $translation = $site->mcpResult('create_page', ['title' => 'Clubpreise', 'slug' => 'clubpreise', 'language' => 'de', 'translation_of' => self::$s['page'],
            'content' => '<p>Secret club price 77 (Deutsch)</p>', 'visible' => true]);
        $this->assertSame(['Clients'], $site->mcpResult('get_page', ['id' => $translation['id']])['member_groups'] ?? null, 'the translation has the groups of its original');
        $this->anonymousSees('/de/clubpreise', 'Secret club price', 'Claude\'s German version of a members-only page');
    }

    public function testTheEditorsTranslationOfAGatedPageIsGatedToo(): void
    {
        $site = $this->site();
        $form = $site->admin()->get('/admin.php?module=pages&action=new&language=de&translation_of=' . self::$s['page']);
        $this->assertSame(200, $form->status);
        $this->assertSame(1, preg_match('~value="' . self::$s['clients'] . '" checked~', $form->body), 'the form of a new translation starts with the groups of the original ticked');
        $saved = $this->submit($form, '/pages/save', ['title' => 'Clubpreise Text', 'slug' => 'clubpreise-text']);
        $this->assertContains($saved->status, [302, 303], 'saved: ' . mb_substr($saved->text(), 0, 300));
        $this->anonymousSees('/de/clubpreise-text', 'Secret club price', 'the editor\'s German version of a members-only page');
    }

    public function testTheEditorsTranslationOfAGatedNewsItemIsGatedToo(): void
    {
        $site = $this->site();
        $form = $site->admin()->get('/admin.php?module=news&action=new&translation_of=' . self::$s['news']);
        $this->assertSame(200, $form->status);
        $this->assertSame(1, preg_match('~value="' . self::$s['clients'] . '" checked~', $form->body), 'the form of a new translation starts with the groups of the original ticked');
        $saved = $this->submit($form, '/news/save', [
            'title' => 'Mitglieder-Nachricht', 'slug' => 'mitglieder-nachricht', 'text' => '<p>Secret news body 99 (Deutsch)</p>', 'category_id' => self::$s['germanCategory'],
            'status' => 'published', 'published_at' => date('Y-m-d H:i', strtotime('-1 hour')), 'translation_of' => self::$s['news'],
        ]);
        $this->assertContains($saved->status, [302, 303], 'saved: ' . mb_substr($saved->text(), 0, 300));
        $this->assertSame('1', (string) $site->value("SELECT visible FROM tl_news WHERE slug = 'mitglieder-nachricht' AND language = 'de'"), 'the German news item is published');
        $this->anonymousSees('/de/news/mitglieder-nachricht', 'Secret news body', 'the editor\'s German version of a members-only news item');
    }

    public function testTheEditorsTranslationOfAGatedItemIsGatedToo(): void
    {
        $site = $this->site();
        $form = $site->admin()->get('/admin.php?module=collections&action=item&id=' . self::$s['collection'] . '&language=de&original=' . self::$s['item']);
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('Secret item text 55', $form->body, 'the translation starts as a copy of the original');
        $this->assertSame(1, preg_match('~value="' . self::$s['clients'] . '" checked~', $form->body), 'the form of a new translation starts with the groups of the original ticked');
        $saved = $this->submit($form, '/collections/save_item', ['visible' => '1']);
        $this->assertContains($saved->status, [302, 303], 'saved: ' . mb_substr($saved->text(), 0, 300));
        $this->assertSame('1', (string) $site->value("SELECT visible FROM tl_collection_items WHERE slug = 'handbook' AND language = 'de'"), 'the German item is published');
        $this->anonymousSees('/de/resources/handbook', 'Secret item text', 'the editor\'s German version of a members-only item');
    }

    public function testARefusedSaveKeepsTheTickedGroups(): void
    {
        // the editor ticks a group on a new page and picks an address that is taken: the form comes back – and must still have the tick,
        // or the next click on Save publishes the page to everybody
        $form = $this->site()->admin()->get('/admin.php?module=pages&action=new');
        $refused = $this->submit($form, '/pages/save', ['title' => 'Partner prices', 'slug' => 'club-prices', 'text' => '<p>Secret partner price 66</p>', 'member_groups' => [self::$s['clients']]]);
        $this->assertSame(200, $refused->status, 'the taken address is refused and the form shown again');
        $this->assertStringContainsString('Secret partner price 66', $refused->body, 'with what was typed');
        $this->assertSame(1, preg_match('~value="' . self::$s['clients'] . '" checked~', $refused->body), 'and with the group still ticked');
        $saved = $this->submit($refused, '/pages/save', ['slug' => 'partner-prices']);
        $this->assertContains($saved->status, [302, 303]);
        $this->anonymousSees('/partner-prices', 'Secret partner price', 'a page saved after a refused save');
    }

    public function testWithTheFeatureSwitchedOffATranslationStillTakesTheGroups(): void
    {
        // the restriction holds while Member login is off (Kernel::gate does not ask the feature); the form then has no fieldset to carry it
        $site = $this->site();
        $site->setting('extensions', str_replace(',members', '', $site->settingValue('extensions')));
        $form = $site->admin()->get('/admin.php?module=pages&action=new&language=de&translation_of=' . self::$s['page']);
        $this->assertStringNotContainsString('member_groups_present', $form->body, 'no gating fieldset while the feature is off');
        $this->submit($form, '/pages/save', ['title' => 'Clubpreise aus', 'slug' => 'clubpreise-aus']);
        $this->assertSame('1', (string) $site->value("SELECT COUNT(*) FROM tl_content_groups c JOIN tl_pages p ON p.page_id = c.content_id AND c.content_type = 'page' WHERE p.slug = 'clubpreise-aus'"),
            'the translation keeps the restriction of its original');
        $answer = $site->client('anonymous-off')->get('/de/clubpreise-aus');
        $this->assertSame(403, $answer->status);
        $this->assertStringNotContainsString('Secret club price', $answer->body);
        $site->setting('extensions', $site->settingValue('extensions') . ',members');
    }
}
