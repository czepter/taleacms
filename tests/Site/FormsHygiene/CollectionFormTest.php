<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A form that adds a hidden draft item to a collection instead of an enquiry (Core\CollectionSubmission). */
#[Group('site')]
final class CollectionFormTest extends SiteTestCase
{
    use McpHelpers;

    public function testAFormAddsAHiddenDraftItemAndStoresNoEnquiry(): void
    {
        $this->mcpText('create_collection', ['name' => 'Voices', 'preset' => 'references']);
        $collection = $this->site()->publicId('collections', (int) $this->site()->value("SELECT collection_id FROM tl_collections WHERE slug = 'voices'"));
        $page = $this->createPage(['title' => 'Say it', 'visible' => true]);
        $this->mcpText('save_build', ['id' => $this->site()->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Voice', 'destination' => 'collection', 'collection' => $collection, 'item_name' => '{Your name}',
            'mapping' => [['target' => 'quote', 'template' => '{Your words}'], ['target' => 'client', 'template' => '{Your name}, {Role}'], ['target' => 'image', 'template' => 'https://evil.example/x.png']],
            'fields' => [['label' => 'Your name', 'type' => 'text', 'required' => true], ['label' => 'Role', 'type' => 'text'], ['label' => 'Your words', 'type' => 'textarea', 'required' => true]]]]]]]]]);
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $this->site()->clearPageCache();

        $visitor = $this->site()->client();
        $form = $visitor->get('/say-it');
        $signed = ['source' => $form->field('source'), 'element' => $form->field('element'), 'as_time' => $form->field('as_time'), 'as_signature' => $form->field('as_signature'), 'back' => '/say-it'];
        sleep(1);

        $refused = $visitor->post('/form', $signed + ['p0' => 'Anna', 'p1' => '', 'p2' => '']);
        $this->assertStringContainsString('result=field', $refused->redirect, 'collection form: a required answer is still required');
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_collection_items WHERE collection_id = (SELECT collection_id FROM tl_collections WHERE slug = ?)', ['voices']), 'collection form: nothing is stored without it');

        $sent = $visitor->post('/form', $signed + ['p0' => 'Anna', 'p1' => 'Designer', 'p2' => 'Lovely work <b>indeed</b>']);
        $this->assertStringContainsString('result=ok', $sent->redirect, 'collection form: the submission is accepted');
        $item = $this->site()->one('SELECT name, visible, data FROM tl_collection_items WHERE collection_id = (SELECT collection_id FROM tl_collections WHERE slug = ?)', ['voices']);
        $this->assertSame('Anna|0', $item['name'] . '|' . $item['visible'], 'collection form: a hidden draft named after the answer');
        $data = json_decode((string) $item['data'], true);
        $this->assertSame('Anna, Designer', $data['client'] ?? null, 'collection form: a template puts several answers together');
        $this->assertStringNotContainsString('<b>', (string) ($data['quote'] ?? ''), 'collection form: the value is cleaned like any other save');
        $this->assertArrayNotHasKey('image', array_filter($data), 'collection form: an image is never taken from typed text');
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_enquiries'), 'collection form: no enquiry is stored');
    }

    public function testAnIncompleteDestinationFallsBackToEnquiries(): void
    {
        $page = $this->createPage(['title' => 'Broken', 'visible' => true]);
        $this->mcpText('save_build', ['id' => $this->site()->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'No target', 'destination' => 'collection', 'fields' => [['label' => 'Email', 'type' => 'email']]]]]]]]]);
        $this->assertSame('1', (string) $this->site()->value("SELECT build LIKE '%\"destination\":\"enquiry\"%' FROM tl_forms WHERE name = 'No target'"), 'collection form: without a collection it stays an enquiry form');
    }
}
