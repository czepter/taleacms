<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A personal link that fills the form in (Core\FormLinks): our suggestion is the link's first draft, the person sees and changes it, saves
 * more drafts into the history, and an answer to a reviewed collection item waits next to it until a person applies it.
 */
#[Group('site')]
final class LinkPrefillTest extends SiteTestCase
{
    use McpHelpers;

    public function testALinkFillsInTheFormSavesDraftsAndAnAnswerIsAppliedToTheItem(): void
    {
        $site = $this->site();
        $this->mcpText('create_collection', ['name' => 'Voices', 'preset' => 'references']);
        $page = $this->createPage(['title' => 'Voice form host', 'visible' => true]);
        // the collection named by its address (slug), as list_collections returns it
        $this->mcpText('save_build', ['id' => $site->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Confirm your voice', 'access' => 'link', 'link_save' => true, 'destination' => 'collection', 'collection' => 'voices', 'item_name' => '{Your name}',
            'mapping' => [['target' => 'quote', 'template' => '{Your words}']],
            'fields' => [['label' => 'Your name', 'type' => 'text', 'required' => true], ['label' => 'Your words', 'type' => 'textarea', 'required' => true], ['label' => 'OK to publish', 'type' => 'checkbox', 'required' => true]]]]]]]]]);
        $form = (int) $site->value("SELECT form_id FROM tl_forms WHERE name = 'Confirm your voice'");
        $this->assertGreaterThan(0, $form, 'prefill: the form with a collection slug became a record');
        $collections = $site->mcpResult('list_collections');
        $this->assertNotSame('', (string) (array_values(array_filter($collections, fn (array $c): bool => $c['collection'] === 'voices'))[0]['id'] ?? ''), 'prefill: list_collections gives the collection id');

        $saved = $site->mcpResult('save_collection_item', ['collection' => 'voices', 'name' => 'Eva', 'values' => ['quote' => 'Old words'], 'visible' => true]);
        $item = (string) ($saved['id'] ?? '');
        $this->assertStringContainsString('no field', $this->mcpText('create_form_link', ['form' => $site->publicId('forms', $form), 'label' => 'X', 'prefill' => ['Nope' => 'x']]), 'prefill: an unknown field is refused');

        $link = $site->mcpResult('create_form_link', ['form' => $site->publicId('forms', $form), 'item' => $item]);
        $this->assertSame(['Your name' => 'Eva', 'Your words' => 'Old words'], $link['prefill'] ?? null, 'prefill: a reviewed item fills in the fields its mapping takes');
        $path = (string) parse_url((string) $link['link'], PHP_URL_PATH);
        $token = substr($path, -32);
        $site->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $site->clearPageCache();

        $visitor = $site->client();
        $opened = $visitor->get('/_f/' . $token);
        $this->assertStringContainsString('>Old words</textarea>', $opened->body, 'prefill: the textarea shows the suggestion');
        $this->assertStringContainsString('value="Eva"', $opened->body, 'prefill: the text field shows the suggestion');
        $this->assertStringContainsString('name="save_draft"', $opened->body, 'prefill: the person can save a draft');
        $signed = ['source' => $opened->field('source'), 'element' => $opened->field('element'), 'as_time' => $opened->field('as_time'), 'as_signature' => $opened->field('as_signature'), 'back' => '/_f/' . $token, 'link' => $token];

        $draft = $visitor->post('/form', $signed + ['save_draft' => '1', 'p0' => 'Eva', 'p1' => 'Draft words']);
        $this->assertStringContainsString('result=saved', $draft->redirect, 'prefill: a draft is saved without sending');
        $visitor->post('/form', $signed + ['save_draft' => '1', 'p0' => 'Eva', 'p1' => 'Second draft']);
        $again = $visitor->get('/_f/' . $token);
        $this->assertStringContainsString('>Second draft</textarea>', $again->body, 'prefill: the latest draft is shown');
        $this->assertStringContainsString('Saved versions (3)', $again->body, 'prefill: the history lists our suggestion and both drafts');
        $this->assertStringContainsString('>Draft words</textarea>', $visitor->get('/_f/' . $token . '?version=1')->body, 'prefill: an earlier draft can be loaded');
        $this->assertStringContainsString('>Old words</textarea>', $visitor->get('/_f/' . $token . '?version=0')->body, 'prefill: our suggestion is the first draft');
        $this->assertSame('0', (string) $site->value('SELECT COUNT(*) FROM tl_form_links WHERE used_at IS NOT NULL'), 'prefill: saving a draft does not use the link');

        sleep(1);
        $sent = $visitor->post('/form', $signed + ['p0' => 'Eva', 'p1' => 'New words', 'p2' => '1']);
        $this->assertStringContainsString('result=ok', $sent->redirect, 'prefill: the answer is sent');
        $links = $site->mcpResult('list_form_links', ['item' => $item]);
        $this->assertSame(['used', 'waiting'], [$links['links'][0]['status'] ?? '', $links['links'][0]['answer_state'] ?? ''], 'prefill: the answer waits next to the item');
        $compare = $site->mcpResult('review_form_answer', ['id' => $links['links'][0]['id']]);
        $changed = array_values(array_filter($compare['fields'] ?? [], fn (array $r): bool => $r['changed']));
        $this->assertSame([['Old words', 'New words']], array_map(fn (array $r): array => [$r['before'], $r['after']], $changed), 'prefill: the comparison shows what the answer changes');

        $site->mcpResult('review_form_answer', ['id' => $links['links'][0]['id'], 'decision' => 'apply']);
        $data = json_decode((string) $site->value('SELECT data FROM tl_collection_items WHERE item_id = ?', [$site->rowId($item)]), true);
        $this->assertSame('New words', $data['quote'] ?? null, 'prefill: applying puts the answer into the item');
        $this->assertSame('1', (string) $site->value('SELECT COUNT(*) FROM tl_collection_items WHERE deleted_at IS NULL'), 'prefill: the draft answer went to the trash');
    }
}
