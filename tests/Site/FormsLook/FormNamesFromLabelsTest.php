<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsLook;

use Talea\Tests\Site\Support\FormNames;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Publishing gives every field without a technical name a readable one from its label (docs/specs/form-builder.md, Field names) –
 * stored once: a later label, an insertion or a draft that carries no names changes none of them.
 */
#[Group('site')]
final class FormNamesFromLabelsTest extends SiteTestCase
{
    use SiteHelpers;

    private static string $page = '';
    private static string $form = '';
    private static string $definition = '';

    /** The names of the form record's published fields, by label. @return array<string, string> */
    private function recordNames(): array
    {
        $fields = json_decode($this->sql('SELECT build FROM tl_forms WHERE public_id = ?', [self::$form]), true)['children'][0]['content']['fields'];

        return array_column($fields, 'name', 'label');
    }

    private function fieldsOfRecord(): array
    {
        return $this->call('get_build', ['form' => self::$form])['build']['children'][0]['content']['fields'];
    }

    public function testPublishingNamesTheFieldsFromTheirLabels(): void
    {
        $created = $this->call('create_page', ['title' => 'Labels', 'slug' => 'labels', 'visible' => true]);
        self::$page = (string) $created['id'];
        $this->call('save_build', ['id' => self::$page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => ['name' => 'Labels', 'fields' => [
            ['label' => 'Vorname', 'type' => 'text'], ['label' => 'E-Mail', 'type' => 'email'], ['label' => 'Größe', 'type' => 'number'], ['label' => '2nd guest', 'type' => 'text'],
            ['label' => 'E mail', 'type' => 'text'], ['label' => 'Own', 'type' => 'text', 'name' => 'chosen'],
        ]]]]]]]]);
        self::$form = $this->sql('SELECT public_id FROM tl_forms');
        self::$definition = (string) ($this->call('get_build', ['form' => self::$form])['build']['children'][0]['id'] ?? '');
        $this->assertSame(['Vorname' => 'vorname', 'E-Mail' => 'e-mail', 'Größe' => 'grosse', '2nd guest' => 'f-2nd-guest', 'E mail' => 'e-mail-2', 'Own' => 'chosen'], $this->recordNames());
        $this->site()->clearPageCache();
        $this->assertSame(['vorname', 'e-mail', 'grosse', 'f-2nd-guest', 'e-mail-2', 'chosen'], FormNames::of($this->site()->client()->get('/labels')->body), 'the page posts under them');
    }

    #[Depends('testPublishingNamesTheFieldsFromTheirLabels')]
    public function testALabelChangeAfterPublishingKeepsTheName(): void
    {
        $fields = $this->fieldsOfRecord();
        $fields[1]['label'] = 'Your email address';
        $this->call('edit_build', ['form' => self::$form, 'publish' => true, 'operations' => [['op' => 'update', 'id' => self::$definition, 'content' => ['fields' => $fields]]]]);
        $this->assertSame('e-mail', $this->recordNames()['Your email address']);
    }

    #[Depends('testALabelChangeAfterPublishingKeepsTheName')]
    public function testAnInsertionKeepsTheOtherNamesAndANewFieldTakesItsLabel(): void
    {
        $fields = $this->fieldsOfRecord();
        array_splice($fields, 1, 0, [['label' => 'Company', 'type' => 'text']]);
        $this->call('edit_build', ['form' => self::$form, 'publish' => true, 'operations' => [['op' => 'update', 'id' => self::$definition, 'content' => ['fields' => $fields]]]]);
        $this->assertSame(['Vorname' => 'vorname', 'Company' => 'company', 'Your email address' => 'e-mail', 'Größe' => 'grosse', '2nd guest' => 'f-2nd-guest', 'E mail' => 'e-mail-2', 'Own' => 'chosen'], $this->recordNames());
    }

    #[Depends('testAnInsertionKeepsTheOtherNamesAndANewFieldTakesItsLabel')]
    public function testADraftThatCarriesNoNamesPublishesTheSameNames(): void
    {
        $before = $this->recordNames();
        $fields = array_map(static fn (array $f): array => ['name' => ''] + $f, $this->fieldsOfRecord());
        foreach ($fields as $i => $f) {
            $fields[$i]['name'] = '';
            $fields[$i]['label'] = 'Renamed ' . $i; // the labels changed as well
        }
        $this->call('edit_build', ['form' => self::$form, 'publish' => true, 'operations' => [['op' => 'update', 'id' => self::$definition, 'content' => ['fields' => $fields]]]]);
        $after = json_decode($this->sql('SELECT build FROM tl_forms WHERE public_id = ?', [self::$form]), true)['children'][0]['content']['fields'];
        $this->assertSame(array_values($before), array_column($after, 'name'), 'by key, the published names come back');
    }

    #[Depends('testADraftThatCarriesNoNamesPublishesTheSameNames')]
    public function testTheInstalledContactFormGetsNamesWhenItsPageIsPublished(): void
    {
        $page = $this->sql("SELECT public_id FROM tl_pages WHERE slug = 'contact'");
        $this->call('publish_build', ['id' => $page]);
        $names = FormNames::of($this->site()->client()->get('/contact')->body);
        $this->assertSame('your-name', $names[0]);
        $this->assertContains('email', $names);
        $this->assertSame([], array_filter($names, static fn (string $n): bool => preg_match('/^k[a-z0-9]{4}$/', $n) === 1), 'no key names');
    }
}
