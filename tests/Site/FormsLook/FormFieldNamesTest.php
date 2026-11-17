<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsLook;

use Talea\Tests\Site\Support\FormNames;
use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Field names that do not move (docs/specs/form-builder.md, Field names): a form posts, stores and delivers every answer under the
 * field's technical name (else its key), inserting, reordering and deleting fields renames no other field, a bad name is refused
 * with an error and never drops the field, an existing form keeps its p0, p1 … after the migration, and the e-mails take {name}.
 */
#[Group('site')]
final class FormFieldNamesTest extends SiteTestCase
{
    use SiteHelpers;

    private static string $page = '';
    private static string $form = '';
    private static string $definition = '';

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        return [
            ['label' => 'First name', 'type' => 'text', 'required' => true, 'name' => 'vorname'],
            ['label' => 'Email', 'type' => 'email', 'required' => true, 'name' => 'email'],
            ['label' => 'Day', 'type' => 'date', 'name' => 'datum'],
            ['label' => 'Tags', 'type' => 'checkboxes', 'checkbox_options' => "A\nB", 'name' => 'tags'],
            ['label' => 'Note', 'type' => 'textarea'],
            ['label' => 'I agree', 'type' => 'checkbox', 'required' => true, 'name' => 'consent'],
        ];
    }

    /** The names the form of the page posts its fields under. @return list<string> */
    private function posted(): array
    {
        $this->site()->clearPageCache();

        return FormNames::of($this->site()->client()->get('/named-form')->body);
    }

    /** @param list<array<string, mixed>> $fields */
    private function setFields(array $fields, bool $publish = true): array
    {
        $result = $this->call('edit_build', ['form' => self::$form, 'publish' => $publish, 'operations' => [['op' => 'update', 'id' => self::$definition, 'content' => ['fields' => $fields]]]]);
        $this->assertIsArray($result);

        return $result;
    }

    /** The stored field entries of the newest enquiry as name => value. @return array<string, string> */
    private function stored(): array
    {
        $data = json_decode($this->sql('SELECT data FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), true);

        return array_column(array_map(static fn (array $d): array => [$d['name'] ?? '', $d[1]], $data), 1, 0);
    }

    public function testAFormWithNamesRendersAndPostsUnderThem(): void
    {
        $site = $this->site();
        $site->setting('site_email', 'owner@example.test');
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '9', 'smtp_encryption' => 'none', 'smtp_user' => ''] as $key => $value) {
            $site->setting($key, $value); // nothing listens: the mail stays in the queue with its text, which the test reads
        }
        $created = $this->call('create_page', ['title' => 'Named form', 'slug' => 'named-form', 'visible' => true]);
        self::$page = (string) $created['id'];
        $saved = $this->call('save_build', ['id' => self::$page, 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Named', 'fields' => $this->fields(), 'confirmation' => true, 'confirmation_subject' => 'Thanks {vorname}',
            'confirmation_text' => 'Hi {vorname} on {Day}, you ticked {tags}. {email|no address}', 'notification_subject' => 'New {email} from {vorname}',
        ]]]]]]]);
        $this->assertSame([], $saved['errors'] ?? [], 'valid names raise no error');
        self::$form = $this->sql('SELECT public_id FROM tl_forms');
        $definition = $this->call('get_build', ['form' => self::$form])['build']['children'][0] ?? [];
        self::$definition = (string) ($definition['id'] ?? '');
        $this->assertSame(['vorname', 'email', 'datum', 'tags', 'note', 'consent'], array_column($definition['content']['fields'], 'name'), 'get_build shows the technical names, the one without was made from its label when the form was published');

        $html = $site->client()->get('/named-form')->body;
        $names = FormNames::of($html);
        $this->assertSame(['vorname', 'email', 'datum', 'tags'], array_slice($names, 0, 4));
        $this->assertSame('note', $names[4], 'a field without a name got one from its label when the form was published');
        $this->assertSame('consent', $names[5]);
        $this->assertStringContainsString('name="vorname"', $html);
        $this->assertStringContainsString('type="date"', $html);
        $this->assertSame(2, substr_count($html, 'name="tags[]"'), 'checkboxes post a list under the name');
        $this->assertDoesNotMatchRegularExpression('/name="p\d/', $html);
    }

    #[Depends('testAFormWithNamesRendersAndPostsUnderThem')]
    public function testAnAnswerIsStoredDeliveredAndExportedUnderTheNames(): void
    {
        $site = $this->site();
        $site->setting('webhook_enquiries', 'https://hooks.example.com/crm');
        $site->setting('webhook_test_url', 'http://127.0.0.1:' . $site->freePort()); // nothing listens: the call fails and its body stays in the log
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $site->exec('DELETE FROM tl_mail');
        $site->clearPageCache();
        $visitor = $site->client();
        $page = $visitor->get('/named-form');
        $names = FormNames::of($page->body);
        sleep(1); // the antispam minimum time
        $sent = $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/named-form', 'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'),
            'vorname' => 'Jane', 'email' => 'jane@example.org', 'datum' => '2026-12-24', 'tags' => ['A', 'B'], $names[4] => 'Please call', 'consent' => '1']);
        $this->assertStringContainsString('result=ok', $sent->redirect, 'the form is accepted');

        $this->assertSame(['vorname' => 'Jane', 'email' => 'jane@example.org', 'datum' => '2026-12-24', 'tags' => 'A, B', $names[4] => 'Please call', 'consent' => 'yes'], $this->stored(), 'every answer carries its name');
        $data = json_decode($this->sql('SELECT data FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), true);
        $this->assertSame(['First name', 'Jane'], [$data[0][0], $data[0][1]], 'label and value stay at 0 and 1');

        $body = json_decode($this->sql("SELECT body FROM tl_webhook_deliveries WHERE event = 'enquiry_received' ORDER BY id DESC LIMIT 1"), true);
        $this->assertSame(['label' => 'Email', 'value' => 'jane@example.org', 'name' => 'email'], $body['fields'][1] ?? null, 'the webhook carries the name next to label and value');

        $listed = $this->call('list_enquiries');
        $this->assertSame(['label' => 'First name', 'value' => 'Jane', 'name' => 'vorname'], $listed[0]['fields'][0] ?? null, 'list_enquiries too');

        $csv = $site->admin()->get('/admin.php?module=enquiries&action=csv')->body;
        $this->assertStringContainsString('Answers by technical name', $csv, 'the CSV has the new last column');
        $this->assertStringContainsString('""vorname"":""Jane""', $csv, 'with the answers by name');
        $this->assertStringContainsString('First name: Jane', $csv, 'the Content column is as before');

        $this->assertPage('/admin.php?module=enquiries&action=detail&id=' . $this->sql('SELECT public_id FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'), 200, ['Jane', 'jane@example.org'], message: 'the detail view reads the answers');

        // the e-mails: {vorname} and {Day} work next to labels, an e-mail address is never repeated to the visitor
        $thanks = $site->rows("SELECT subject, body FROM tl_mail WHERE recipient = 'jane@example.org'")[0] ?? [];
        $this->assertSame('Thanks Jane', $thanks['subject'] ?? '');
        $this->assertStringContainsString('Hi Jane on 2026-12-24, you ticked A, B. no address', (string) (json_decode((string) ($thanks['body'] ?? ''), true)['text'] ?? ''));
        $this->assertSame('New jane@example.org from Jane', $this->sql("SELECT subject FROM tl_mail WHERE recipient = 'owner@example.test'"), 'the owner’s notification takes every answer');
    }

    #[Depends('testAnAnswerIsStoredDeliveredAndExportedUnderTheNames')]
    public function testInsertingReorderingAndDeletingFieldsRenamesNoOtherField(): void
    {
        $base = $this->call('get_build', ['form' => self::$form])['build']['children'][0]['content']['fields'];
        $before = $this->posted();
        $this->assertCount(6, $before);

        $inserted = [$base[0], ['label' => 'Phone', 'type' => 'tel'], ...array_slice($base, 1)];
        $this->setFields($inserted);
        $after = $this->posted();
        $this->assertCount(7, $after);
        $this->assertSame([...array_slice($before, 0, 1), $after[1], ...array_slice($before, 1)], $after, 'inserted between the first and the second: the others keep their names');
        $this->assertSame('phone', $after[1], 'the new field is named from its label when it is published');

        $stored = $this->call('get_build', ['form' => self::$form])['build']['children'][0]['content']['fields'];
        $this->setFields(array_reverse($stored));
        $this->assertSame(array_reverse($after), $this->posted(), 'reordered: every field keeps its name');

        $reversed = $this->call('get_build', ['form' => self::$form])['build']['children'][0]['content']['fields'];
        $this->setFields(array_values(array_filter($reversed, static fn (array $f): bool => $f['label'] !== 'Phone' && $f['label'] !== 'First name')));
        $this->assertSame(['consent', $before[4], 'tags', 'datum', 'email'], $this->posted(), 'two fields deleted: the others keep their names');
    }

    #[Depends('testInsertingReorderingAndDeletingFieldsRenamesNoOtherField')]
    public function testABadNameIsReportedAndClearedButTheFieldStays(): void
    {
        $fields = [
            ['label' => 'A', 'type' => 'text', 'name' => 'email'],
            ['label' => 'B', 'type' => 'text', 'name' => 'email'],
            ['label' => 'C', 'type' => 'text', 'name' => 'website'],
            ['label' => 'D', 'type' => 'text', 'name' => 'two words'],
            ['label' => 'E', 'type' => 'text', 'name' => 'Own_Name'],
        ];
        $result = $this->setFields($fields, false);
        $errors = $result['errors'] ?? [];
        $this->assertCount(3, $errors, 'the duplicate, the reserved name and the bad pattern: ' . json_encode($errors));
        $paths = array_keys($errors);
        foreach ([1, 2, 3] as $n => $index) {
            $this->assertStringEndsWith(".fields.$index.name", $paths[$n]);
        }
        $this->assertStringContainsString('Another field of this form already has this technical name', (string) $errors[$paths[0]]);
        $this->assertStringContainsString('used by the form itself', (string) $errors[$paths[1]]);
        $draft = $this->call('get_build', ['form' => self::$form, 'draft' => true])['build']['children'][0]['content']['fields'] ?? [];
        $this->assertSame(['email', '', '', '', 'own_name'], array_column($draft, 'name'), 'the bad names are cleared, the fields are all kept');
        $this->call('discard_draft', ['form' => self::$form]);
    }

    #[Depends('testABadNameIsReportedAndClearedButTheFieldStays')]
    public function testTheBuilderSchemaListsTheProperty(): void
    {
        $schema = $this->raw('builder_schema');
        $this->assertStringContainsString('fields:items[key:text; label:text; name:text; type:choice(', $schema, 'builder_schema lists the property of a form field');
    }

    #[Depends('testTheBuilderSchemaListsTheProperty')]
    public function testAnExistingFormKeepsItsPositionNamesAfterTheMigration(): void
    {
        // an old form: the build as it was stored before this change (the fields have keys but no names), posted as p0, p1 …
        $old = json_decode($this->sql('SELECT build FROM tl_forms WHERE public_id = ?', [self::$form]), true);
        foreach ($old['children'][0]['content']['fields'] as $i => $field) {
            unset($old['children'][0]['content']['fields'][$i]['name']);
        }
        $count = count($old['children'][0]['content']['fields']);
        $position = array_search('Email', array_column($old['children'][0]['content']['fields'], 'label'), true);
        $this->assertIsInt($position);
        $this->site()->exec('UPDATE tl_forms SET build = ?, build_draft = NULL WHERE public_id = ?', [(string) json_encode($old, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::$form]);
        $this->assertMatchesRegularExpression('/^k[a-z0-9]{4}$/', $this->posted()[0], 'before the migration the fields would post their keys');
        require_once TALEA_SYSTEM . '/database/migrations/20261122100000_freeze_form_field_names.php';
        $this->assertGreaterThan(0, \FreezeFormFieldNames::freeze($this->site()->pdo, 'tl_', true));
        $this->assertSame(array_map(static fn (int $i): string => 'p' . $i, range(0, $count - 1)), $this->posted(), 'it posts exactly the names it posted before');

        $stored = $this->call('get_build', ['form' => self::$form])['build']['children'][0]['content']['fields'];
        $this->setFields([$stored[0], ['label' => 'Phone', 'type' => 'tel'], ...array_slice($stored, 1)]);
        $names = $this->posted();
        $this->assertCount($count + 1, $names);
        $this->assertSame('p' . $position, $names[$position + 1], 'an inserted field does not move the e-mail: it stays p' . $position);
        $this->assertSame('phone', $names[1]);

        // and an answer posted under that name is still read as the e-mail
        $site = $this->site();
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $visitor = $site->client();
        $page = $visitor->get('/named-form');
        sleep(1);
        $sent = $visitor->post('/form', ['source' => $page->field('source'), 'element' => $page->field('element'), 'back' => '/named-form', 'as_time' => $page->field('as_time'), 'as_signature' => $page->field('as_signature'),
            $names[0] => '1', $names[$position + 1] => 'old@example.org']); // the consent is the first field
        $this->assertStringContainsString('result=ok', $sent->redirect, $sent->redirect);
        $this->assertSame('old@example.org', $this->sql('SELECT email FROM tl_enquiries ORDER BY enquiry_id DESC LIMIT 1'));
        $this->assertSame('old@example.org', $this->stored()['p' . $position], 'the stored answer carries the frozen name');
    }
}
