<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** A form only people with a personal link can send (Core\FormLinks, /_f/<token>). */
#[Group('site')]
final class LinkFormTest extends SiteTestCase
{
    use McpHelpers;

    public function testALinkOpensTheFormOnceAndOnlyWithItsToken(): void
    {
        $this->mcpText('create_collection', ['name' => 'Voices', 'preset' => 'references']);
        $collection = $this->site()->publicId('collections', (int) $this->site()->value("SELECT collection_id FROM tl_collections WHERE slug = 'voices'"));
        $page = $this->createPage(['title' => 'Members only', 'visible' => true]);
        $this->mcpText('save_build', ['id' => $this->site()->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Personal voice', 'access' => 'link', 'destination' => 'collection', 'collection' => $collection, 'item_name' => '{Your name}',
            'mapping' => [['target' => 'quote', 'template' => '{Your words}']],
            'fields' => [['label' => 'Your name', 'type' => 'text', 'required' => true], ['label' => 'Your words', 'type' => 'textarea', 'required' => true], ['label' => 'OK to publish', 'type' => 'checkbox', 'required' => true]]]]]]]]]);
        $form = (int) $this->site()->value("SELECT form_id FROM tl_forms WHERE name = 'Personal voice'");
        $this->assertGreaterThan(0, $form, 'link form: publishing made the form a record');
        $token = bin2hex(random_bytes(16));
        $this->site()->exec('INSERT INTO tl_form_links (form_id, token_hash, email, label, created_at, expires_at) VALUES (?, SHA2(?, 256), ?, ?, NOW(), NOW() + INTERVAL 1 DAY)', [$form, $token, '', 'Eva']);
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $this->site()->clearPageCache();

        $visitor = $this->site()->client();
        $opened = $visitor->get('/_f/' . $token);
        $this->assertStringContainsString('name="link" value="' . $token . '"', $opened->body, 'link form: the page posts the token back');
        $this->assertStringContainsString('noindex', $opened->body, 'link form: the page is not indexed');
        $this->assertSame(404, $visitor->get('/_f/' . str_repeat('a', 32))->status, 'link form: an unknown link is a 404');
        $signed = ['source' => $opened->field('source'), 'element' => $opened->field('element'), 'as_time' => $opened->field('as_time'), 'as_signature' => $opened->field('as_signature'), 'back' => '/_f/' . $token];
        sleep(1);

        $without = $visitor->post('/form', $signed + ['p0' => 'Eva', 'p1' => 'Great.', 'p2' => '1']);
        $this->assertStringContainsString('result=verification', $without->redirect, 'link form: no token, no submission');
        $this->assertSame('0', (string) $this->site()->value('SELECT COUNT(*) FROM tl_collection_items'), 'link form: nothing stored without the token');

        $sent = $visitor->post('/form', $signed + ['link' => $token, 'p0' => 'Eva', 'p1' => 'Great.', 'p2' => '1']);
        $this->assertStringContainsString('result=ok', $sent->redirect, 'link form: the token is accepted');
        $this->assertSame('1|OK to publish', $this->site()->value('SELECT CONCAT(COUNT(*), \'|\', MAX(consent)) FROM tl_form_links WHERE used_at IS NOT NULL AND item_id IS NOT NULL'), 'link form: the link is used and keeps the consent as worded');
        $this->assertSame(404, $visitor->get('/_f/' . $token)->status, 'link form: a used link is a 404');
        $again = $visitor->post('/form', $signed + ['link' => $token, 'p0' => 'Eva', 'p1' => 'Twice', 'p2' => '1']);
        $this->assertStringContainsString('result=verification', $again->redirect, 'link form: a link works once');
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) FROM tl_collection_items'), 'link form: only one item');
    }
}
