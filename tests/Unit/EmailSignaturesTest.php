<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmailSignaturesTest extends TestCase
{
    /** 2.10: e-mail signatures from people records */
    public function testSignatures(): void
    {
        $signatureClass = \Talea\Builder\EmailSignature::class;
        $peopleFields = [['key' => 'fotka', 'label' => 'Fotka', 'type' => 'image'], ['key' => 'role', 'label' => 'Role', 'type' => 'text'], ['key' => 'jazyky', 'label' => 'Jazyky', 'type' => 'text'],
            ['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text'], ['key' => 'e_mail', 'label' => 'E-mail', 'type' => 'text'], ['key' => 'nepritomnost', 'label' => 'Nepřítomnost', 'type' => 'text'], ['key' => 'o_mne', 'label' => 'O mně', 'type' => 'html']]; // check-english: allow
        $peopleCollection = ['collection_id' => 5, 'name' => 'Tým', 'slug' => 'tym', 'detail' => 1, 'fields' => $peopleFields, 'schema_org' => '{"type":"Person","fields":{},"currency":""}']; // check-english: allow
        $this->assertSame(['photo' => 'fotka', 'role' => 'role', 'phone' => 'telefon', 'email' => 'e_mail'], $signatureClass::fields($peopleCollection), '2.10: EmailSignature::fields – the Czech preset by the keys and labels');
        $germanCollection = ['fields' => [['key' => 'portrait', 'label' => 'Porträt', 'type' => 'image'], ['key' => 'funktion', 'label' => 'Funktion', 'type' => 'text'], ['key' => 'handy', 'label' => 'Handy', 'type' => 'text'],
            ['key' => 'e_mail_adresse', 'label' => 'E-Mail-Adresse', 'type' => 'text'], ['key' => 'abwesend', 'label' => 'Abwesend', 'type' => 'text']]];
        $this->assertSame(// check-english: allow
            [['photo' => 'portrait', 'role' => 'funktion', 'phone' => 'handy', 'email' => 'e_mail_adresse'], ['photo' => null, 'role' => 'field_2', 'phone' => 'field_1', 'email' => null]], [$signatureClass::fields($germanCollection),
            $signatureClass::fields(['fields' => [['key' => 'field_1', 'label' => 'Telefonní číslo', 'type' => 'text'], ['key' => 'field_2', 'label' => 'Pozice ve firmě', 'type' => 'text'], ['key' => 'pole_3', 'label' => 'Mobil', 'type' => 'number']]])], '2.10: EmailSignature::fields – a user-made collection in another language, a label with diacritics'); // check-english: allow
        $this->assertSame(// check-english: allow
            ['photo' => null, 'role' => 'cim_jsem', 'phone' => 'telefon', 'email' => 'kontakt'], $signatureClass::fields(['fields' => [['key' => 'kontakt', 'label' => 'Kontakt', 'type' => 'text'], ['key' => 'cim_jsem', 'label' => 'Čím jsem', 'type' => 'text'], // check-english: allow
            ['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text'], ['key' => 'role', 'label' => 'Role v týmu', 'type' => 'text']], 'schema_org' => '{"type":"Person","fields":{"jobTitle":"cim_jsem","email":"kontakt"}}']), '2.10: EmailSignature::fields – the schema.org Person mapping wins over the names'); // check-english: allow
        $this->assertSame([true, true, false, false], [$signatureClass::isPeople($peopleCollection), $signatureClass::isPeople($germanCollection),
            $signatureClass::isPeople(['fields' => [['key' => 'logo', 'label' => 'Logo', 'type' => 'image'], ['key' => 'citat', 'label' => 'Citát', 'type' => 'lines']]]), // check-english: allow
            $signatureClass::isPeople(['fields' => [['key' => 'telefon', 'label' => 'Telefon', 'type' => 'text']]])], '2.10: EmailSignature::isPeople – Person, a photo with a contact; references and a bare phone are not people');
        $signatureSite = ['name' => 'Firma & spol.', 'url' => 'https://example.com/', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => 'Dlouhá 1, 110 00 Praha', 'color' => '#0f766e', // check-english: allow
            'text_font' => 'Georgia, serif', 'heading_font' => '"Helvetica Neue", Arial, sans-serif', 'logo' => 'https://example.com/media/logo.png'];
        $signaturePerson = ['name' => 'Jana <Nová>', 'data' => ['fotka' => 'media/2026/10/jana.jpg', 'role' => 'Obchodní ředitelka', 'jazyky' => 'CZ, EN', 'telefon' => '+420 777 123 456', 'e_mail' => 'jana@example.com', // check-english: allow
            'nepritomnost' => 'Dovolená do pátku', 'o_mne' => '<p>Deset let v oboru.</p>']]; // check-english: allow
        $signature = $signatureClass::render($peopleCollection, $signaturePerson, $signatureSite);
        $this->assertSame([0, true, true], [preg_match('/<style|<script|class=|\son[a-z]+=/i', $signature['html']), str_starts_with($signature['html'], '<table role="presentation"'), str_contains($signature['html'], 'max-width:600px')], '2.10: the signature is one table with inline styles only, at most 600 px wide');
        $this->assertSame(// check-english: allow
            [true, true, true, true, true, true, true, true, true], [str_contains($signature['html'], 'Jana &lt;Nová&gt;'), str_contains($signature['html'], 'Obchodní ředitelka'), // check-english: allow
            str_contains($signature['html'], 'href="tel:+420777123456"'), str_contains($signature['html'], 'href="mailto:jana@example.com"'), str_contains($signature['html'], 'src="https://example.com/media/2026/10/jana.jpg" width="72" height="72"'),
            str_contains($signature['html'], 'src="https://example.com/media/logo.png"'), str_contains($signature['html'], 'border-left:3px solid #0f766e'), str_contains($signature['html'], 'Georgia, serif'), str_contains($signature['html'], 'Firma &amp; spol.')], '2.10: the signature has the escaped name, the role, the phone with a tel: link, the brand colour and font, absolute photo and logo'); // check-english: allow
        $this->assertSame([false, false, false], [str_contains($signature['html'] . $signature['text'], 'Dovolen'), str_contains($signature['html'] . $signature['text'], 'Deset let'), str_contains($signature['html'] . $signature['text'], 'CZ, EN')], '2.10: the signature never carries the absence, the about text or the languages');
        $this->assertSame("Jana <Nová>\nObchodní ředitelka\n+420 777 123 456 · jana@example.com\nFirma & spol. · example.com\nDlouhá 1, 110 00 Praha", $signature['text'], '2.10: the plain-text version'); // check-english: allow
        $bareSignature = $signatureClass::render(['fields' => $peopleFields], ['name' => 'Petr', 'data' => ['e_mail' => 'not an address', 'fotka' => '']],
            ['name' => 'Web', 'url' => '', 'base' => 'https://example.com', 'phone' => '+420 222 000 111', 'address' => '', 'color' => 'red', 'text_font' => '', 'heading_font' => '', 'logo' => '']);
        $this->assertSame([false, false, true, true, "Petr\n+420 222 000 111\nWeb"], [str_contains($bareSignature['html'], '<img'), str_contains($bareSignature['html'], 'not an address'),
            str_contains($bareSignature['html'], 'border-left:3px solid #121212'), str_contains($bareSignature['html'], 'system-ui'), $bareSignature['text']], '2.10: a person without a photo and with an invalid e-mail: no image, the company phone, a safe colour and font');
        $this->assertSame([['seo_title' => '', 'description' => str_repeat('a', 300), 'image' => '', 'noindex' => 1, 'publish_at' => '2099-01-01 08:00:00', 'visible' => 0], 1], [\Talea\Builder\Collections::pageFields(['image' => 'javascript:alert(1)', 'noindex' => '1', 'publish_at' => '2099-01-01T08:00', 'description' => str_repeat('a', 400)], false),
            \Talea\Builder\Collections::pageFields(['publish_at' => '2001-01-01 08:00'], false)['visible']], 'Items as pages: SEO fields and publishing schedule');
        $privacy = \Talea\Core\Language::runWith('en', fn (): string => \Talea\Builder\Library::privacyPolicyText());
        $this->assertSame([true, true, false, true], [str_contains($privacy, 'not legal advice'), str_contains($privacy, 'enquiry form'), str_contains($privacy, 'newsletter'),
            str_contains(\Talea\Core\Language::runWith('cs', fn (): string => \Talea\Builder\Library::privacyPolicyText()), 'nikoli právní rada')], 'Privacy policy: a template with a notice, without settings only enquiries'); // check-english: allow
        // import of a Talea export (1.8): which files from the archive may go into media/, which names are exports
        $this->assertSame(['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', null, null, null, null, 'media/2026/09/dokument.pdf', null, 'media/2026/09/logo.svg'], array_map(\Talea\Core\ArchiveMedia::path(...), ['media/2026/09/foto.jpg', 'media/2026/09/foto.jpg.webp', 'media/x.php', 'media/../config.php', // check-english: allow
            'media/.htaccess', 'content.json', 'media/2026/09/dokument.pdf', 'media/a/b.phtml', 'media/2026/09/logo.svg']), 'Import Talea: soubory do media/'); // check-english: allow
        $this->assertSame([true, true, false, false, false, false, false], array_map(\Talea\Core\SiteImport::isValidName(...), ['export-20260928-101010.zip', 'web.json', 'state-0123456789abcdef.json',
            'talea-state-0123456789abcdef.json', '../web.zip', 'web.xml', '.web.zip']), 'Talea import: file names');
        // webhook signature (1.8): HMAC-SHA256 of "timestamp.body" – the receiver recomputes it with the shared secret
        $this->assertSame(['1789900000', 'sha256=' . hash_hmac('sha256', '1789900000.{"event":"test"}', 'whsec_test')], \Talea\Core\Webhook::signature('whsec_test', '{"event":"test"}', 1789900000), 'Webhook: HMAC signature of the timestamp and body');
        $this->assertTrue(\Talea\Core\Webhook::signature('whsec_test', '{"event":"test2"}', 1789900000)[1] !== \Talea\Core\Webhook::signature('whsec_test', '{"event":"test"}', 1789900000)[1], 'Webhook: another body = another signature');
        $h = \Talea\Core\RemoteBackup::signS3('PUT', 's3.eu-central-1.amazonaws.com', '/muj-bucket/talea-zaloha.sql.gz', hash('sha256', 'content'), 'eu-central-1', 'AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY', 1789900000);
        $this->assertTrue(str_contains($h['Authorization'], 'Credential=AKIDEXAMPLE/20260920/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature='), 'S3: scope and signed headers');
        $this->assertTrue((bool) preg_match('/Signature=[0-9a-f]{64}$/', $h['Authorization']), 'S3: the signature has 64 hexadecimal characters');
    }
}
