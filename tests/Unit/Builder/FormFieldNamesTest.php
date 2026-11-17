<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\Context;
use Talea\Builder\Elements\Form;
use Talea\Builder\Renderer;
use Talea\Core\App;
use Talea\Core\Request;
use Talea\Core\Settings;

/** The technical name of a form field (docs/specs/form-builder.md, Field names): the one rule, its checks and what the form renders. */
final class FormFieldNamesTest extends TestCase
{
    /**
     * Sanitizes one form and returns its fields and the errors.
     *
     * @param list<array<string, mixed>> $fields
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private function sanitize(array $fields): array
    {
        [$build, $errors] = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'id' => 'f1', 'content' => ['fields' => $fields]]]]);

        return [$build['children'][0]['content']['fields'], $errors];
    }

    /** @param list<array<string, mixed>> $fields */
    private function render(array $fields): string
    {
        [$build] = Build::sanitize(['v' => 1, 'children' => [['type' => 'form', 'id' => 'f1', 'content' => ['fields' => $fields]]]]);
        $app = new App(['db' => ['driver' => 'mysql', 'host' => 'x', 'name' => 'x']], new Request([], [], ['REQUEST_URI' => '/quiz', 'HTTP_HOST' => 'localhost']));
        $settings = (new \ReflectionClass(Settings::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Settings::class, 'values'))->setValue($settings, ['secret_key' => str_repeat('a', 64)]);
        (new \ReflectionProperty(App::class, 'settings'))->setValue($app, $settings);

        return Renderer::html($build, new Context($app));
    }

    /** @return list<string> the posted names a rendered form announces in data-fields */
    private function announced(string $html): array
    {
        $this->assertSame(1, preg_match('/data-fields="([^"]*)"/', $html, $m), 'data-fields is on the form');

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> every name="…" of the form's own inputs, in order */
    private function inputNames(string $html): array
    {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname="([^"]*)"/', $html, $m);

        return $m[1];
    }

    public function testFieldNamePrecedence(): void
    {
        $this->assertSame('email', Form::fieldName(['name' => 'email', 'key' => 'kaaaa'], 3));
        $this->assertSame('kaaaa', Form::fieldName(['name' => '', 'key' => 'kaaaa'], 3));
        $this->assertSame('kaaaa', Form::fieldName(['key' => 'kaaaa'], 3));
        $this->assertSame('p3', Form::fieldName([], 3), 'the position only for a field with neither');
    }

    public function testAValidNameIsKeptAndNormalised(): void
    {
        [$fields, $errors] = $this->sanitize([
            ['label' => 'A', 'type' => 'text', 'name' => ' Vorname '],
            ['label' => 'B', 'type' => 'email', 'name' => 'e-mail_2'],
            ['label' => 'C', 'type' => 'text', 'name' => str_repeat('a', 40)],
            ['label' => 'D', 'type' => 'text'],
        ]);
        $this->assertSame([], $errors);
        $this->assertSame(['vorname', 'e-mail_2', str_repeat('a', 40), ''], array_column($fields, 'name'), 'upper case is lower-cased, a field without a name stays without');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badNames(): array
    {
        $pattern = 'may use a–z, 0–9, _ and -';
        $own = 'is used by the form itself';

        return [
            'space' => ['two words', $pattern], 'dot' => ['a.b', $pattern], 'brackets' => ['a[]', $pattern], 'digit first' => ['1abc', $pattern], 'underscore first' => ['_a', $pattern],
            'umlaut' => ['größe', $pattern], 'too long' => [str_repeat('a', 41), $pattern], 'only a symbol' => ['-', $pattern],
            'source' => ['source', $own], 'element' => ['element', $own], 'link' => ['link', $own], 'back' => ['back', $own], 'save_draft' => ['save_draft', $own], 'seats' => ['seats', $own],
            'website' => ['website', $own], 'as_time' => ['as_time', $own], 'as_signature' => ['as_signature', $own], 'tl_landing' => ['tl_landing', $own],
            'tl_campaign' => ['tl_campaign', $own], 'tl_referrer' => ['tl_referrer', $own], 'recaptcha' => ['g-recaptcha-response', $own], 'hcaptcha' => ['h-captcha-response', $own],
            'turnstile' => ['cf-turnstile-response', $own], 'step' => ['step', $own], 'form' => ['form', $own], 'result' => ['result', $own], 'field' => ['field', $own],
            'tl_ prefix' => ['tl_anything', $own], 'as_ prefix' => ['AS_other', $own],
            'another key shape' => ['kzzzz', 'Another field'],
        ];
    }

    #[DataProvider('badNames')]
    public function testABadNameIsClearedAndReportedAndTheFieldStays(string $name, string $message): void
    {
        [$fields, $errors] = $this->sanitize([['label' => 'Email', 'type' => 'email', 'name' => $name]]);
        $this->assertCount(1, $fields, 'the field is kept');
        $this->assertSame('', $fields[0]['name']);
        $this->assertSame('email', $fields[0]['type']);
        $this->assertCount(1, $errors);
        $path = array_key_first($errors);
        $this->assertStringEndsWith('.content.fields.0.name', $path);
        $this->assertStringStartsWith('Email: ', $errors[$path]);
        $this->assertStringContainsString($message, $errors[$path]);
        $this->assertSame(Form::fieldName($fields[0], 0), $fields[0]['key'], 'it is posted under its key');
    }

    public function testAnUnlabelledFieldIsNamedByItsNumberInTheError(): void
    {
        [, $errors] = $this->sanitize([['label' => 'A', 'type' => 'text'], ['label' => '', 'type' => 'text', 'name' => 'source']]);
        $this->assertStringStartsWith('Field 2: ', (string) reset($errors));
    }

    public function testADuplicateKeepsTheFirstField(): void
    {
        [$fields, $errors] = $this->sanitize([
            ['label' => 'A', 'type' => 'text', 'name' => 'email'],
            ['label' => 'B', 'type' => 'text', 'name' => 'EMAIL'],
            ['label' => 'C', 'type' => 'text', 'name' => 'other'],
        ]);
        $this->assertSame(['email', '', 'other'], array_column($fields, 'name'));
        $this->assertSame(1, count($errors));
        $this->assertStringEndsWith('.content.fields.1.name', (string) array_key_first($errors));
        $this->assertStringContainsString('Another field of this form', (string) reset($errors));
    }

    public function testANameMayNotBeTheKeyOfAnotherField(): void
    {
        [$fields, $errors] = $this->sanitize([
            ['label' => 'A', 'type' => 'text', 'key' => 'kaaaa', 'name' => 'kbbbb'],
            ['label' => 'B', 'type' => 'text', 'key' => 'kbbbb'],
        ]);
        $this->assertSame(['', ''], array_column($fields, 'name'));
        $this->assertCount(1, $errors);
        // a name that is the field's own key is only the default written out
        [$fields, $errors] = $this->sanitize([['label' => 'A', 'type' => 'text', 'key' => 'kaaaa', 'name' => 'kaaaa']]);
        $this->assertSame([], $errors);
        $this->assertSame('kaaaa', $fields[0]['name']);
    }

    public function testADrawnKeyAvoidsTheNamesInUse(): void
    {
        // names that look like keys are refused, but the draw also steps around any name asked for in the same save
        $names = array_map(static fn (int $n): string => 'n' . $n, range(1, 30));
        $fields = array_map(static fn (string $name): array => ['label' => $name, 'type' => 'text', 'name' => $name], $names);
        [$sanitized] = $this->sanitize($fields);
        $keys = array_column($sanitized, 'key');
        $this->assertCount(30, array_unique($keys));
        $this->assertSame([], array_intersect($keys, array_column($sanitized, 'name')));
    }

    public function testRenderedNamesPerFieldType(): void
    {
        $html = $this->render([
            ['label' => 'First name', 'type' => 'text', 'name' => 'vorname', 'key' => 'kaaaa'],
            ['label' => 'Mail', 'type' => 'email', 'name' => 'email', 'key' => 'kbbbb'],
            ['label' => 'Tags', 'type' => 'checkboxes', 'checkbox_options' => "A\nB", 'name' => 'tags', 'key' => 'kcccc'],
            ['label' => 'Slot', 'type' => 'timerange', 'name' => 'slot', 'key' => 'kdddd'],
            ['label' => 'Pick', 'type' => 'radio', 'choices' => "Yes\nNo", 'key' => 'keeee'],
            ['label' => 'Agree', 'type' => 'checkbox', 'name' => 'agree', 'key' => 'kffff'],
            ['label' => 'Day', 'type' => 'date', 'name' => 'datum', 'key' => 'kgggg'],
        ]);
        $this->assertSame(['vorname', 'email', 'tags', 'slot', 'keeee', 'agree', 'datum'], $this->announced($html));
        $names = $this->inputNames($html);
        $this->assertContains('vorname', $names);
        $this->assertContains('email', $names);
        $this->assertSame(2, count(array_keys($names, 'tags[]', true)), 'checkboxes post a list under the name');
        $this->assertSame(2, count(array_keys($names, 'slot[]', true)), 'a time range posts two times under the name');
        $this->assertSame(2, count(array_keys($names, 'keeee', true)), 'a field without a name is posted under its key');
        $this->assertContains('agree', $names);
        $this->assertContains('datum', $names);
        $this->assertDoesNotMatchRegularExpression('/name="p\d/', $html, 'the position is not a name any more');
    }

    public function testInsertingReorderingAndDeletingKeepEveryOtherName(): void
    {
        $first = [
            ['label' => 'Name', 'type' => 'text', 'name' => 'vorname'],
            ['label' => 'Mail', 'type' => 'email', 'name' => 'email'],
            ['label' => 'Note', 'type' => 'textarea'],
        ];
        [$stored] = $this->sanitize($first);
        $names = function (array $fields): array {
            $byLabel = array_combine(array_column($fields, 'label'), array_map(static fn (array $f, int $i): string => Form::fieldName($f, $i), $fields, array_keys($fields)));
            ksort($byLabel);

            return $byLabel;
        };
        $before = $names($stored);
        // insert a field between the first and the second (the editor sends the list in its new order, the stored keys with it)
        $inserted = [$stored[0], ['label' => 'Phone', 'type' => 'tel'], $stored[1], $stored[2]];
        [$afterInsert] = $this->sanitize($inserted);
        $this->assertSame($before, array_intersect_key($names($afterInsert), $before), 'insert');
        $reordered = [$afterInsert[3], $afterInsert[2], $afterInsert[0], $afterInsert[1]];
        [$afterReorder] = $this->sanitize($reordered);
        $this->assertSame($names($afterInsert), array_intersect_key($names($afterReorder), $names($afterInsert)), 'reorder');
        [$afterDelete] = $this->sanitize([$afterReorder[0], $afterReorder[1], $afterReorder[2]]); // the new Phone field is deleted again
        $this->assertSame($before, $names($afterDelete), 'delete');
        [$withoutNote] = $this->sanitize([$afterReorder[1], $afterReorder[2], $afterReorder[3]]);
        $this->assertSame(['Mail' => 'email', 'Name' => 'vorname', 'Phone' => $names($afterInsert)['Phone']], $names($withoutNote), 'deleting the first field renames none of the others');
        $this->assertSame($before['Name'], $names($afterDelete)['Name']);
    }

    public function testAFrozenFormKeepsItsPositionNamesAfterAnInsert(): void
    {
        // what the migration writes: name = p<i> on every field of an existing form
        [$stored] = $this->sanitize([
            ['label' => 'Name', 'type' => 'text', 'name' => 'p0'],
            ['label' => 'Mail', 'type' => 'email', 'name' => 'p1'],
        ]);
        [$after] = $this->sanitize([$stored[0], ['label' => 'Phone', 'type' => 'tel'], $stored[1]]);
        $this->assertSame('p1', Form::fieldName($after[2], 2), 'the e-mail field is still p1 although it moved to the third place');
        $this->assertSame($after[1]['key'], Form::fieldName($after[1], 1));
    }

    public function testEveryNameTheFormPostsThatIsNoFieldIsReserved(): void
    {
        // a form with the pieces that add inputs of their own: attribution, a link, a draft button, the hidden field of an item page
        $html = $this->render([['label' => 'Name', 'type' => 'text', 'name' => 'vorname', 'key' => 'kaaaa'], ['label' => 'Agree', 'type' => 'checkbox', 'key' => 'kbbbb']]);
        $own = $this->announced($html);
        foreach (array_unique(array_map(static fn (string $n): string => preg_replace('/\[\]$/', '', $n), $this->inputNames($html))) as $name) {
            $this->assertTrue(in_array($name, $own, true) || in_array($name, Form::RESERVED_NAMES, true), $name . ' is a field name or reserved');
        }
        // what other code posts or reads under a fixed name is reserved as well (a rendered form shows only part of it)
        foreach (['source', 'element', 'link', 'back', 'save_draft', 'seats'] as $posted) {
            $this->assertContains($posted, Form::RESERVED_NAMES);
        }
        foreach (['website', 'as_time', 'as_signature'] as $antispam) {
            $this->assertContains($antispam, Form::RESERVED_NAMES);
        }
        foreach (\Talea\Core\Captcha::PROVIDERS as $provider) {
            $this->assertContains($provider[2], Form::RESERVED_NAMES, 'the captcha response field of ' . $provider[0]);
        }
        preg_match_all('/name="([^"]+)"/', \Talea\Front\Forms::ATTRIBUTION_FIELDS, $attribution);
        foreach ($attribution[1] as $name) {
            $this->assertContains($name, Form::RESERVED_NAMES);
        }
    }

    public function testThePropertyIsOneRowInTheFieldDefinition(): void
    {
        $row = Form::properties()['fields']['fields']['name'];
        $this->assertSame('text', $row['type']);
        $this->assertSame(80, $row['max'], 'above the 40 allowed, so that a longer name is reported and not cut short');
        $this->assertArrayNotHasKey('hidden', $row);
        $this->assertArrayNotHasKey('group', $row, 'a field of the main group of the inspector');
    }

    /** @param list<array<string, mixed>> $fields @param list<array<string, mixed>> $published @return list<string> */
    private function published(array $fields, array $published = []): array
    {
        $content = \Talea\Builder\FormFieldNames::fromLabels(['fields' => $fields], ['fields' => $published]);

        return array_column($content['fields'], 'name');
    }

    public function testPublishingNamesFieldsFromTheirLabels(): void
    {
        $this->assertSame(['e-mail', 'vorname', 'grosse', 'cena', 'f-3-guests', 'phone'], $this->published([
            ['key' => 'ka001', 'label' => 'E-Mail'], ['key' => 'ka002', 'label' => 'Vorname'], ['key' => 'ka003', 'label' => 'Größe'],
            ['key' => 'ka004', 'label' => 'Čena'], ['key' => 'ka005', 'label' => '3 guests'], ['key' => 'ka006', 'label' => 'Phone'], // check-english: allow
        ]), 'readable names; a digit first gets f-');
        $this->assertSame(['strasse'], $this->published([['key' => 'ka001', 'label' => 'Straße']]), 'ß is transliterated');
    }

    public function testNamesFromLabelsAreUniqueValidAndCapped(): void
    {
        $names = $this->published([
            ['key' => 'ka001', 'label' => 'Email'], ['key' => 'ka002', 'label' => 'Email'], ['key' => 'ka003', 'label' => 'E mail'], ['key' => 'ka004', 'label' => 'Source'],
            ['key' => 'ka005', 'label' => 'Result'], ['key' => 'ka006', 'label' => 'tl_x'], ['key' => 'ka007', 'label' => str_repeat('long ', 20)], ['key' => 'ka008', 'label' => 'kabcd'],
        ]);
        $this->assertSame(['email', 'email-2', 'e-mail', 'source-2', 'result-2', 'tl-x', 'long-long-long-long-long-long-long-long'], array_slice($names, 0, 7));
        $this->assertLessThanOrEqual(40, strlen($names[6]));
        $this->assertSame('kabcd-2', $names[7], 'a key-shaped name is not drawn from a label');
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression(Form::NAME_PATTERN, $name);
        }
        $this->assertCount(count($names), array_unique($names));
        $capped = $this->published([['key' => 'ka001', 'label' => str_repeat('a', 39) . ' b'], ['key' => 'ka002', 'label' => str_repeat('a', 39) . ' c']]);
        $this->assertSame([str_repeat('a', 39), str_repeat('a', 38) . '-2'], $capped, 'a cut name leaves room for the suffix and ends in no separator');
    }

    public function testNoLabelGivesTheKeyWrittenOut(): void
    {
        $this->assertSame(['ka001', 'ka002'], $this->published([['key' => 'ka001', 'label' => ''], ['key' => 'ka002', 'label' => '???']]), 'no usable label: the key, stored so that a later label does not rename the field');
    }

    public function testSetNamesAndPublishedNamesAreNeverChanged(): void
    {
        $published = [['key' => 'ka001', 'label' => 'Old label', 'name' => 'p0'], ['key' => 'ka002', 'label' => 'Mail', 'name' => 'email']];
        $this->assertSame(['p0', 'email', 'mine', 'new-label'], $this->published([
            ['key' => 'ka001', 'label' => 'Totally new label'], ['key' => 'ka002', 'label' => 'Renamed'], ['key' => 'ka003', 'label' => 'x', 'name' => 'mine'], ['key' => 'ka004', 'label' => 'New label'],
        ], $published), 'a draft that carries no names takes the published ones by key; the owner’s name stays; a new field is named from its label');
        $this->assertSame(['email-2', 'email'], $this->published([['key' => 'ka009', 'label' => 'Email'], ['key' => 'ka002', 'label' => 'Mail']], $published), 'a new field never takes the name of a published one');
    }

    public function testAnExplicitNameInTheDraftWinsOverARestoredPublishedName(): void
    {
        $published = [['key' => 'ka001', 'label' => 'Mail', 'name' => 'email']];
        $names = $this->published([['key' => 'ka001', 'label' => 'Mail'], ['key' => 'ka002', 'label' => 'Other', 'name' => 'email']], $published);
        $this->assertSame(['mail', 'email'], $names, 'the owner’s explicit name stays; the restored field is named from its label, never a duplicate');
    }
}
