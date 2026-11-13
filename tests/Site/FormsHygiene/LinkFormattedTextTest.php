<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The whole journey of a personal link that reviews an item with formatted text (html): the link is made from the item, the person
 * opens it and sees the text, sends a changed text, the comparison shows it, and applying puts it into the item. Before, html was no
 * field a form could fill: the answer arrived empty and applying would have wiped the item's text. A mapping that still cannot be
 * filled (a gallery) is named by the check of save_build and by try_form, before anybody gets a link.
 */
#[Group('site')]
final class LinkFormattedTextTest extends SiteTestCase
{
    use McpHelpers;

    public function testAPersonChangesFormattedTextThroughALinkAndTheAnswerReachesTheItem(): void
    {
        $site = $this->site();
        $this->mcpText('create_collection', ['name' => 'Voices', 'fields' => [['label' => 'Text', 'type' => 'html'], ['label' => 'Rating', 'type' => 'number'], ['label' => 'Photos', 'type' => 'gallery']]]);
        $page = $this->createPage(['title' => 'Voice host', 'visible' => true]);
        $form = ['type' => 'form', 'id' => 'voiceform', 'content' => ['name' => 'Your voice', 'access' => 'link', 'destination' => 'collection', 'collection' => 'voices', 'item_name' => '{Your name}',
            'mapping' => [['target' => 'text', 'template' => '{Your words}'], ['target' => 'rating', 'template' => '{Stars}'], ['target' => 'photos', 'template' => '{Your words}']],
            'fields' => [['label' => 'Your name', 'type' => 'text', 'required' => true], ['label' => 'Your words', 'type' => 'textarea'], ['label' => 'Stars', 'type' => 'number'], ['label' => 'OK to publish', 'type' => 'checkbox', 'required' => true]]]];
        $saved = $site->mcpResult('save_build', ['id' => $site->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [$form]]]]]);
        $checks = implode(' | ', array_column($saved['check'] ?? [], 'message'));
        $this->assertStringContainsString('“Photos” of the collection cannot be filled by a form', $checks, 'formatted text: the check names a mapped field a form cannot fill');
        $this->assertStringNotContainsString('“Text”', $checks, 'formatted text: an html field is no problem any more');

        $formId = (int) $site->value("SELECT form_id FROM tl_forms WHERE name = 'Your voice'");
        // the form became a record when the page was published: try_form runs it there, by the form element's id in its own build
        $element = (array) json_decode((string) $site->value('SELECT build FROM tl_forms WHERE form_id = ?', [$formId]), true)['children'][0];
        $tried = $site->mcpResult('try_form', ['form' => $site->publicId('forms', $formId), 'element' => (string) $element['id'], 'published' => true,
            'answers' => array_combine(array_column($element['content']['fields'], 'key'), ['Mike', "Line one & <b>two</b>\nnext line\n\nSecond paragraph", '5', '1'])]);
        $this->assertSame("<p>Line one &amp; &lt;b&gt;two&lt;/b&gt;<br>\nnext line</p>\n<p>Second paragraph</p>", $tried['item']['values']['text'] ?? null, 'formatted text: try_form shows the answer as escaped paragraphs');
        $this->assertSame(['Mike', '5'], [$tried['item']['name'] ?? null, $tried['item']['values']['rating'] ?? null], 'formatted text: try_form shows the item it would make');
        $this->assertSame(['photos'], $tried['item']['empty_fields'] ?? null, 'formatted text: try_form names the mapped field that stays empty');

        $item = (string) ($site->mcpResult('save_collection_item', ['collection' => 'voices', 'name' => 'Lisa + Mike', 'values' => ['text' => '<p>We <strong>loved</strong> it.</p><p>Thanks!</p>', 'rating' => '4'], 'visible' => true])['id'] ?? '');
        $stored = (string) (json_decode((string) $site->value('SELECT data FROM tl_collection_items WHERE item_id = ?', [$site->rowId($item)]), true)['text'] ?? '');
        $this->assertStringContainsString('<strong>loved</strong>', $stored);
        $link = $site->mcpResult('create_form_link', ['form' => $site->publicId('forms', $formId), 'item' => $item]);
        $this->assertSame("We loved it.\n\nThanks!", $link['prefill']['Your words'] ?? null, 'formatted text: the link shows the item\'s text as plain paragraphs');
        $token = substr((string) parse_url((string) $link['link'], PHP_URL_PATH), -32);
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $site->clearPageCache();

        // the person opens the link and sees the text, unchanged, then sends it with only the rating changed
        $visitor = $site->client();
        $opened = $visitor->get('/_f/' . $token);
        $this->assertStringContainsString(">We loved it.\n\nThanks!</textarea>", $opened->body, 'formatted text: the text area shows the item\'s text');
        $signed = ['source' => $opened->field('source'), 'element' => $opened->field('element'), 'as_time' => $opened->field('as_time'), 'as_signature' => $opened->field('as_signature'), 'back' => '/_f/' . $token, 'link' => $token];
        sleep(1);
        $this->assertStringContainsString('result=ok', $visitor->post('/form', $signed + ['p0' => 'Lisa + Mike', 'p1' => "We loved it.\n\nThanks!", 'p2' => '5', 'p3' => '1'])->redirect, 'formatted text: the answer is sent');
        $answer = json_decode((string) $site->value('SELECT data FROM tl_collection_items WHERE visible = FALSE AND deleted_at IS NULL'), true);
        $this->assertSame("<p>We loved it.</p>\n<p>Thanks!</p>", $answer['text'] ?? null, 'formatted text: the answer keeps the text (it arrived empty before)');
        $id = $site->mcpResult('list_form_links', ['item' => $item])['links'][0]['id'];
        $changed = array_values(array_filter($site->mcpResult('review_form_answer', ['id' => $id])['fields'] ?? [], fn (array $r): bool => $r['changed']));
        $this->assertSame([['4', '5']], array_map(fn (array $r): array => [$r['before'], $r['after']], $changed), 'formatted text: the same words are no change, only the rating is');
        $site->mcpResult('review_form_answer', ['id' => $id, 'decision' => 'apply']);
        $data = json_decode((string) $site->value('SELECT data FROM tl_collection_items WHERE item_id = ?', [$site->rowId($item)]), true);
        $this->assertSame([$stored, '5'], [$data['text'] ?? null, $data['rating'] ?? null], 'formatted text: unchanged words keep their formatting, the rating is applied');

        // a second link: the person rewrites the text, and the new text replaces the old one
        $second = $site->mcpResult('create_form_link', ['form' => $site->publicId('forms', $formId), 'item' => $item]);
        $token = substr((string) parse_url((string) $second['link'], PHP_URL_PATH), -32);
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $opened = $visitor->get('/_f/' . $token);
        $signed = ['source' => $opened->field('source'), 'element' => $opened->field('element'), 'as_time' => $opened->field('as_time'), 'as_signature' => $opened->field('as_signature'), 'back' => '/_f/' . $token, 'link' => $token];
        sleep(1);
        $visitor->post('/form', $signed + ['p0' => 'Lisa + Mike', 'p1' => 'A day to remember.', 'p2' => '5', 'p3' => '1']);
        $id = $site->mcpResult('list_form_links', ['item' => $item])['links'][0]['id'];
        $rows = array_column($site->mcpResult('review_form_answer', ['id' => $id])['fields'] ?? [], null, 'label');
        $this->assertSame(["We loved it.\n\nThanks!", 'A day to remember.', true], [$rows['Text']['before'] ?? null, $rows['Text']['after'] ?? null, $rows['Text']['changed'] ?? null], 'formatted text: the comparison reads as text');
        $site->mcpResult('review_form_answer', ['id' => $id, 'decision' => 'apply']);
        $data = json_decode((string) $site->value('SELECT data FROM tl_collection_items WHERE item_id = ?', [$site->rowId($item)]), true);
        $this->assertSame('<p>A day to remember.</p>', $data['text'] ?? null, 'formatted text: a changed text is applied');
    }
}
