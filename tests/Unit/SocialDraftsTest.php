<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SocialDraftsTest extends TestCase
{
    /** 2.13: social post drafts (Core\SocialDrafts) */
    public function testDrafts(): void
    {
        $sdLink = \Talea\Core\SocialDrafts::trackedLink('https://example.com/novinky/nova-hala', 'facebook', 'nova-hala');
        $this->assertSame(['https://example.com/novinky/nova-hala?utm_source=facebook&utm_medium=social&utm_campaign=nova-hala', 'https://example.com/novinky/a?x=1&utm_source=x&utm_medium=social&utm_campaign=a'], [$sdLink, \Talea\Core\SocialDrafts::trackedLink('https://example.com/novinky/a?x=1', 'x', 'a')], '2.13 SocialDrafts: the tracked link carries the network, the medium and the news slug; an existing query is kept');
        $this->assertSame(// check-english: allow
            [['#NovaHala', '#Vyroba', '#CncStroje'], []], [\Talea\Core\SocialDrafts::hashtags(['Nová hala', 'výroba', 'nova hala', 'CNC stroje', 'čtvrtý']), \Talea\Core\SocialDrafts::hashtags(['', '!!!'])], '2.13 SocialDrafts: hashtags from the tags – CamelCase without diacritics, at most three, no duplicates'); // check-english: allow
        $sdLead = trim(str_repeat('Otevřeli jsme novou výrobní halu s moderními stroji. ', 12)); // about 620 characters // check-english: allow
        $sdTags = ['#NovaHala', '#Vyroba'];
        $sdFacebook = \Talea\Core\SocialDrafts::text('facebook', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
        $sdLinkedin = \Talea\Core\SocialDrafts::text('linkedin', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
        $sdX = \Talea\Core\SocialDrafts::text('x', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
        $sdInstagram = \Talea\Core\SocialDrafts::text('instagram', 'Nová hala', $sdLead, $sdTags, $sdLink); // check-english: allow
        $this->assertSame(// check-english: allow
            [true, true, true, true, true], [str_starts_with($sdFacebook, "Nová hala\n\nOtevřeli"), str_ends_with($sdFacebook, "#NovaHala #Vyroba\n\n" . $sdLink), str_contains($sdFacebook, '…'), str_ends_with($sdLinkedin, $sdLink), mb_strlen($sdLinkedin) > mb_strlen($sdFacebook)], '2.13 SocialDrafts: Facebook and LinkedIn start with the title and end with the hashtags and the link; Facebook shortens the lead, LinkedIn keeps more of it'); // check-english: allow
        $this->assertSame([true, true, true, 27, 280], [\Talea\Core\SocialDrafts::xLength($sdX) <= 280, \Talea\Core\SocialDrafts::xLength($sdX) > 240, str_ends_with($sdX, "#NovaHala #Vyroba\n" . $sdLink),
            \Talea\Core\SocialDrafts::xLength('abc https://example.com/a/very/long/path/that/goes/on'), \Talea\Core\SocialDrafts::xLength(\Talea\Core\SocialDrafts::text('x', str_repeat('T', 300), '', [], $sdLink))], '2.13 SocialDrafts: X fits 280 with the link counted as 23 – the hashtags and the link stay whole, the lead gives way');
        $this->assertSame([false, true], [str_contains($sdInstagram, 'http'), str_ends_with($sdInstagram, "#NovaHala #Vyroba\n\n" . t('Link in bio'))], '2.13 SocialDrafts: Instagram has no link in the text, says link in bio and keeps the hashtags');
        $this->assertSame(// check-english: allow
            [['facebook', 'x'], ['facebook', 'linkedin'], 'Dlouhý text…', 'krátký', 'A & B C'], [\Talea\Core\SocialDrafts::chosen('x, facebook,evil'), \Talea\Core\SocialDrafts::chosen(\Talea\Core\SocialDrafts::DEFAULT_NETWORKS), \Talea\Core\SocialDrafts::shorten('Dlouhý text s mnoha slovy', 14), \Talea\Core\SocialDrafts::shorten('krátký', 20), \Talea\Core\Html::plain('<p>A &amp; B</p><p>C</p>')], '2.13 SocialDrafts: the chosen networks in a fixed order, unknown ones dropped; shorten() cuts at a word; plain() strips the editor HTML'); // check-english: allow
        $this->assertSame(// check-english: allow
            ["Nový text od asistenta.\n\n#NovaHala #Vyroba\n\n" . $sdLink, true, false], [\Talea\Core\SocialDrafts::finish('linkedin', 'Nový text https://evil.example/x od asistenta.', $sdTags, $sdLink), \Talea\Core\SocialDrafts::xLength(\Talea\Core\SocialDrafts::finish('x', $sdLead, $sdTags, $sdLink)) <= 280, str_contains(\Talea\Core\SocialDrafts::finish('instagram', 'Text', [], $sdLink), 'http')], '2.13 SocialDrafts: finish() completes an assistant text – its own links out, the hashtags and the tracked link back, X within its limit, Instagram without a link'); // check-english: allow
    }
}
