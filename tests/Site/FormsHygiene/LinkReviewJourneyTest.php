<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A personal link that reviews an item, walked to the end (link → open → send → compare → apply) with what a real form has: the
 * testimonial form (a photo, "{Name}, {Role}"), choices, a date for a date and time, a time range and an answer that does not fit.
 * Applying must never wipe what the person did not see, and an answer that does not fit is asked again, never dropped.
 */
#[Group('site')]
final class LinkReviewJourneyTest extends SiteTestCase
{
    use McpHelpers;

    public function testTheTestimonialFormKeepsThePhotoAndReadsTheRoleBack(): void
    {
        $site = $this->site();
        $form = $site->mcpResult('create_form_link', ['label' => 'warm-up'])['id'] ?? ''; // makes the testimonial form and the References collection
        $this->assertNotSame('', $form);
        $formId = (int) $site->value("SELECT form_id FROM tl_forms WHERE origin = 'system:testimonial'");
        $item = (string) ($site->mcpResult('save_collection_item', ['collection' => 'references', 'name' => 'Eva Novak',
            'values' => ['quote' => 'Great work.', 'client' => 'Eva Novak, director, ACME', 'image' => 'media/eva.webp'], 'visible' => true])['id'] ?? '');
        $link = $site->mcpResult('create_form_link', ['form' => $site->publicId('forms', $formId), 'item' => $item]);
        $this->assertSame(['Great work.', 'Eva Novak', 'director, ACME'], array_values($link['prefill'] ?? []), 'review: "{Name}, {Role}" is read back into both fields');
        $this->assertArrayNotHasKey('not_prefilled', $link, 'review: a photo is no value the form misses');

        $token = substr((string) parse_url((string) $link['link'], PHP_URL_PATH), -32);
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $visitor = $site->client();
        $opened = $visitor->get('/_f/' . $token);
        $this->assertStringContainsString('value="director, ACME"', $opened->body, 'review: the role is filled in');
        $signed = ['source' => $opened->field('source'), 'element' => $opened->field('element'), 'as_time' => $opened->field('as_time'), 'as_signature' => $opened->field('as_signature'), 'back' => '/_f/' . $token, 'link' => $token];
        sleep(1);
        // the person keeps the words, clears the role and uploads no photo
        $this->assertStringContainsString('result=ok', $visitor->post('/form', $signed + ['p0' => 'Great work.', 'p1' => 'Eva Novak', 'p2' => '', 'p4' => '1'])->redirect);
        $id = $site->mcpResult('list_form_links', ['item' => $item])['links'][0]['id'];
        $rows = array_column($site->mcpResult('review_form_answer', ['id' => $id])['fields'] ?? [], null, 'label');
        $this->assertSame(['media/eva.webp', true, false], [$rows['Image']['after'] ?? null, $rows['Image']['kept'] ?? null, $rows['Image']['changed'] ?? null], 'review: the comparison says the photo stays');
        $this->assertSame(['Eva Novak, director, ACME', 'Eva Novak', true], [$rows['Client']['before'] ?? null, $rows['Client']['after'] ?? null, $rows['Client']['changed'] ?? null], 'review: the cleared role shows as a change');
        $site->mcpResult('review_form_answer', ['id' => $id, 'decision' => 'apply']);
        $data = json_decode((string) $site->value('SELECT data FROM tl_collection_items WHERE item_id = ?', [$site->rowId($item)]), true);
        $this->assertSame(['media/eva.webp', 'Eva Novak', 'Great work.'], [$data['image'] ?? null, $data['client'] ?? null, $data['quote'] ?? null],
            'review: the photo the person did not upload again stays; the role they saw and cleared is cleared');
    }

    public function testChoicesDatesAndTimesComeBackAndAnAnswerThatDoesNotFitIsAskedAgain(): void
    {
        $site = $this->site();
        $this->mcpText('create_collection', ['name' => 'Shoots', 'fields' => [['label' => 'Package', 'type' => 'choice', 'options' => ['Wedding', 'Portrait']],
            ['label' => 'When', 'type' => 'datetime'], ['label' => 'Time', 'type' => 'text'], ['label' => 'Rating', 'type' => 'number']]]);
        $page = $this->createPage(['title' => 'Shoot host', 'visible' => true]);
        $saved = $site->mcpResult('save_build', ['id' => $site->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Your shoot', 'access' => 'link', 'destination' => 'collection', 'collection' => 'shoots', 'item_name' => '{Name}',
            'mapping' => [['target' => 'package', 'template' => '{Package}'], ['target' => 'when', 'template' => '{Day}'], ['target' => 'time', 'template' => '{Hours}'], ['target' => 'rating', 'template' => '{Stars}']],
            'fields' => [['label' => 'Name', 'type' => 'text', 'required' => true], ['label' => 'Package', 'type' => 'select', 'options' => "wedding\nPortrait\nFamily"], ['label' => 'Day', 'type' => 'date'],
                ['label' => 'Hours', 'type' => 'timerange'], ['label' => 'Stars', 'type' => 'text'], ['label' => 'OK', 'type' => 'checkbox', 'required' => true]]]]]]]]]);
        $this->assertStringContainsString('options the collection field “Package” does not have: Family', implode(' | ', array_column($saved['check'] ?? [], 'message')), 'review: options the collection lacks are named');

        $formId = (int) $site->value("SELECT form_id FROM tl_forms WHERE name = 'Your shoot'");
        $item = (string) ($site->mcpResult('save_collection_item', ['collection' => 'shoots', 'name' => 'Lisa', 'values' => ['package' => 'Wedding', 'when' => '2026-06-20 14:00', 'time' => '10:00 – 12:00', 'rating' => '4'], 'visible' => true])['id'] ?? '');
        $link = $site->mcpResult('create_form_link', ['form' => $site->publicId('forms', $formId), 'item' => $item]);
        $this->assertSame(['Lisa', 'wedding', '2026-06-20', '10:00-12:00', '4'], array_values($link['prefill'] ?? []), 'review: a choice in another case, the day of a date and time and a time range are filled in');

        $token = substr((string) parse_url((string) $link['link'], PHP_URL_PATH), -32);
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $site->clearPageCache();
        $visitor = $site->client();
        $opened = $visitor->get('/_f/' . $token);
        $this->assertStringContainsString('name="p3[]" value="10:00"', $opened->body, 'review: the time range shows its start');
        $this->assertStringContainsString('name="p3[]" value="12:00"', $opened->body, 'review: the time range shows its end');
        $signed = ['source' => $opened->field('source'), 'element' => $opened->field('element'), 'as_time' => $opened->field('as_time'), 'as_signature' => $opened->field('as_signature'), 'back' => '/_f/' . $token, 'link' => $token];
        sleep(1);

        // "5 stars" is no number: the field is marked, nothing is stored, and the link shows what the person wrote
        $refused = $visitor->post('/form', $signed + ['p0' => 'Lisa', 'p1' => 'wedding', 'p2' => '2026-06-20', 'p3' => ['10:00', '12:00'], 'p4' => '5 stars', 'p5' => '1']);
        $this->assertStringContainsString('result=field&field=4', $refused->redirect, 'review: an answer that does not fit its item field is asked again');
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_collection_items i JOIN tl_collections k ON k.collection_id = i.collection_id WHERE k.slug = 'shoots' AND i.visible = FALSE"), 'review: nothing half-filled is stored');
        $this->assertStringContainsString('value="5 stars"', $visitor->get('/_f/' . $token)->body, 'review: the person finds their answer again');
        $this->assertStringContainsString('result=field&field=1', $visitor->post('/form', $signed + ['p0' => 'Lisa', 'p1' => 'Family', 'p2' => '2026-06-20', 'p3' => ['10:00', '12:00'], 'p4' => '5', 'p5' => '1'])->redirect,
            'review: an option the collection does not have is asked again');

        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $this->assertStringContainsString('result=ok', $visitor->post('/form', $signed + ['p0' => 'Lisa', 'p1' => 'wedding', 'p2' => '2026-06-20', 'p3' => ['10:00', '12:00'], 'p4' => '5', 'p5' => '1'])->redirect);
        $id = $site->mcpResult('list_form_links', ['item' => $item])['links'][0]['id'];
        $changed = array_values(array_filter($site->mcpResult('review_form_answer', ['id' => $id])['fields'] ?? [], fn (array $r): bool => $r['changed']));
        $this->assertSame([['Rating', '4', '5']], array_map(fn (array $r): array => [$r['label'], $r['before'], $r['after']], $changed), 'review: only the rating changed – the same day keeps its time, the choice its case');
        $site->mcpResult('review_form_answer', ['id' => $id, 'decision' => 'apply']);
        $data = json_decode((string) $site->value('SELECT data FROM tl_collection_items WHERE item_id = ?', [$site->rowId($item)]), true);
        $this->assertSame(['Wedding', '2026-06-20 14:00', '10:00 – 12:00', '5'], [$data['package'] ?? null, $data['when'] ?? null, $data['time'] ?? null, $data['rating'] ?? null], 'review: applying changes the rating and nothing else');
    }
}
