<?php

declare(strict_types=1);

namespace Talea\Tests\Site\FormsHygiene;

use PHPUnit\Framework\Attributes\Group;
use Talea\Tests\Site\Support\SiteTestCase;

/** Starting a form from a collection, personal links made in the admin, the Collections right, and uploads that wait for approval. */
#[Group('site')]
final class CollectionStarterTest extends SiteTestCase
{
    use McpHelpers;

    private function voices(): string
    {
        $this->mcpText('create_collection', ['name' => 'Voices', 'preset' => 'references']);

        return $this->site()->publicId('collections', (int) $this->site()->value("SELECT collection_id FROM tl_collections WHERE slug = 'voices'"));
    }

    public function testAFormIsStartedFromACollectionWithItsFieldsMapped(): void
    {
        $collection = $this->voices();
        $page = $this->adminPost('/admin.php?module=forms&action=create', ['name' => 'Send a voice', 'starter' => 'collection', 'collection' => $collection, 'by_link' => '1'], '/admin.php?module=forms&action=new');
        $this->assertStringContainsString('/forms/builder/', (string) $page->redirect, 'collection starter: the new form opens in the form builder');
        $draft = json_decode((string) $this->site()->value("SELECT build_draft FROM tl_forms WHERE name = 'Send a voice'"), true);
        $content = $draft['children'][0]['content'];
        $this->assertSame(['collection', $collection, 'link'], [$content['destination'], $content['collection'], $content['access']], 'collection starter: it adds to the collection and opens only by a link');
        $this->assertNotSame([], $content['mapping'], 'collection starter: the fields are mapped');
        foreach ($content['mapping'] as $row) {
            $this->assertMatchesRegularExpression('/^\{[^{}]+\}$/', $row['template'], 'collection starter: each field is mapped one to one');
        }
        $this->assertNotSame('', $content['item_name'], 'collection starter: the item gets a name from an answer');
        $this->assertSame('checkbox', end($content['fields'])['type'], 'collection starter: the last field is the consent');

        $open = $this->adminPost('/admin.php?module=forms&action=create', ['name' => 'Open voice', 'starter' => 'collection', 'collection' => $collection], '/admin.php?module=forms&action=new');
        $this->assertNotNull($open->redirect);
        $this->assertSame('open', json_decode((string) $this->site()->value("SELECT build_draft FROM tl_forms WHERE name = 'Open voice'"), true)['children'][0]['content']['access'], 'collection starter: without the box anyone can open it');

        $this->adminPost('/admin.php?module=forms&action=create', ['name' => 'Nowhere', 'starter' => 'collection', 'collection' => 'not-a-collection'], '/admin.php?module=forms&action=new');
        $this->assertSame('0', (string) $this->site()->value("SELECT COUNT(*) FROM tl_forms WHERE name = 'Nowhere'"), 'collection starter: an unknown collection creates nothing');
    }

    public function testALinkIsMadeForANameAndOnlyForALinkForm(): void
    {
        $collection = $this->voices();
        $this->adminPost('/admin.php?module=forms&action=create', ['name' => 'Send a voice', 'starter' => 'collection', 'collection' => $collection, 'by_link' => '1'], '/admin.php?module=forms&action=new');
        $this->adminPost('/admin.php?module=forms&action=create', ['name' => 'Plain', 'starter' => 'empty'], '/admin.php?module=forms&action=new');
        $link = $this->site()->publicId('forms', (int) $this->site()->value("SELECT form_id FROM tl_forms WHERE name = 'Send a voice'"));
        $plain = $this->site()->publicId('forms', (int) $this->site()->value("SELECT form_id FROM tl_forms WHERE name = 'Plain'"));

        $this->adminPost('/admin.php?module=forms&action=link', ['id' => $link, 'label' => 'Eva'], '/admin.php?module=forms');
        $this->assertSame('0', (string) $this->site()->value("SELECT COUNT(*) FROM tl_form_links WHERE label = 'Eva'"), 'admin link: a form that is not published has no link yet');

        $this->site()->admin()->post('/admin.php?module=forms&action=build_publish', ['_csrf' => $this->site()->csrf(), 'id' => $link]);
        $this->adminPost('/admin.php?module=forms&action=link', ['id' => $link, 'label' => 'Eva'], '/admin.php?module=forms');
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM tl_form_links WHERE label = 'Eva' AND email = '' AND enquiry_id IS NULL"), 'admin link: a published link form gets a link for a name');
        $this->adminPost('/admin.php?module=forms&action=link', ['id' => $link, 'label' => ''], '/admin.php?module=forms');
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) FROM tl_form_links'), 'admin link: a link needs a name');
        $this->site()->admin()->post('/admin.php?module=forms&action=build_publish', ['_csrf' => $this->site()->csrf(), 'id' => $plain]);
        $this->adminPost('/admin.php?module=forms&action=link', ['id' => $plain, 'label' => 'Ben'], '/admin.php?module=forms');
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) FROM tl_form_links'), 'admin link: a form open to everyone makes no links');
    }

    public function testSomeoneWithoutCollectionsCannotPointAFormAtACollection(): void
    {
        $site = $this->site();
        $collection = $this->voices();
        $this->adminPost('/admin.php?module=forms&action=create', ['name' => 'Plain', 'starter' => 'empty'], '/admin.php?module=forms&action=new');
        $form = $site->publicId('forms', (int) $site->value("SELECT form_id FROM tl_forms WHERE name = 'Plain'"));
        $site->exec('INSERT INTO tl_users (username, password, name, admin, last_login_at, confirmed_at) VALUES (?, ?, ?, 1, NOW(), NOW())', ['forms-only', password_hash($site->password, PASSWORD_DEFAULT), 'Forms only']);
        $site->exec("INSERT INTO tl_user_permissions (user_id, module) SELECT user_id, 'enquiries' FROM tl_users WHERE username = 'forms-only'");
        $client = $site->client('forms-only');
        $site->signIn($client, 'forms-only');

        $collectionForm = ['v' => 1, 'children' => [['type' => 'form', 'content' => ['name' => 'Plain', 'destination' => 'collection', 'collection' => $collection, 'item_name' => '{Your name}',
            'mapping' => [['target' => 'quote', 'template' => '{Your name}']], 'fields' => [['label' => 'Your name', 'type' => 'text']]]]]];
        $saved = $client->post('/admin.php?module=forms&action=build_save', ['_csrf' => $client->get('/admin.php?module=forms')->csrf(), 'id' => $form, 'build' => json_encode($collectionForm)]);
        $this->assertSame(422, $saved->status, 'rights: the draft is refused without the Collections right');
        $this->assertStringNotContainsString('"destination":"collection"', (string) $site->value("SELECT COALESCE(build_draft, '') FROM tl_forms WHERE name = 'Plain'"), 'rights: nothing was stored');

        $as = $client->post('/admin.php?module=forms&action=create', ['_csrf' => $client->get('/admin.php?module=forms&action=new')->csrf(), 'name' => 'Sneaky', 'starter' => 'collection', 'collection' => $collection]);
        $this->assertNotNull($as->redirect);
        $this->assertSame('0', (string) $site->value("SELECT COUNT(*) FROM tl_forms WHERE name = 'Sneaky'"), 'rights: no collection starter either');

        $ok = $site->admin()->post('/admin.php?module=forms&action=build_save', ['_csrf' => $site->csrf(), 'id' => $form, 'build' => json_encode($collectionForm)]);
        $this->assertSame(200, $ok->status, 'rights: an administrator may');
    }

    public function testAnUploadWaitsForApprovalAndTheItemKnowsItsForm(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('The PHP used by the test has no GD.');
        }
        $collection = $this->voices();
        $page = $this->createPage(['title' => 'Photo voice', 'visible' => true]);
        $this->mcpText('save_build', ['id' => $this->site()->publicId('pages', $page), 'publish' => true, 'build' => ['v' => 1, 'children' => [['type' => 'section', 'children' => [['type' => 'form', 'content' => [
            'name' => 'Photo voice', 'destination' => 'collection', 'collection' => $collection, 'item_name' => '{Your name}',
            'mapping' => [['target' => 'quote', 'template' => '{Your name}'], ['target' => 'image', 'template' => '{Photo}']],
            'fields' => [['label' => 'Your name', 'type' => 'text', 'required' => true], ['label' => 'Photo', 'type' => 'file']]]]]]]]]);
        $this->site()->exec("DELETE FROM tl_ip_checks WHERE type = 'form'");
        $this->site()->clearPageCache();

        $image = imagecreatetruecolor(40, 30);
        $path = $this->site()->workDir('files') . '/voice.png';
        imagepng($image, $path);
        $visitor = $this->site()->client();
        $form = $visitor->get('/photo-voice');
        $signed = ['source' => $form->field('source'), 'element' => $form->field('element'), 'as_time' => $form->field('as_time'), 'as_signature' => $form->field('as_signature'), 'back' => '/photo-voice'];
        sleep(1);

        $sent = $visitor->upload('/form', $signed + ['p0' => 'Anna'], ['p1' => [$path, 'image/png', 'voice.png']]);
        $this->assertStringContainsString('result=ok', (string) $sent->redirect, 'upload: the answer is accepted');
        $item = $this->site()->rows('SELECT data, submitted_via FROM tl_collection_items WHERE collection_id = (SELECT collection_id FROM tl_collections WHERE slug = ?)', ['voices'])[0];
        $photo = (string) (json_decode((string) $item['data'], true)['image'] ?? '');
        $this->assertNotSame('', $photo, 'upload: the item holds the uploaded picture');
        $this->assertSame('1', (string) $this->site()->value('SELECT COUNT(*) FROM tl_media WHERE staged_at IS NOT NULL AND image_path = ?', [$photo]), 'upload: the picture waits for approval');
        $this->assertSame(404, $visitor->get('/' . ltrim($photo, '/'))->status, 'upload: a visitor cannot open the waiting picture');
        $this->assertSame($this->site()->value("SELECT form_id FROM tl_forms WHERE name = 'Photo voice'"), $item['submitted_via'], 'provenance: the item remembers the form it came through');
    }
}
