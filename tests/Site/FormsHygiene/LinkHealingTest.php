<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Self-healing internal links (was: section 75, "2.14"). */
#[Group('site')]
final class LinkHealingTest extends SiteTestCase
{
    use McpHelpers;

    public function testRenamingAPageRewritesTheLinksToIt(): void
    {
        $target = $this->createPage(['titulek' => 'Heal target', 'adresa' => 'lh-stare', 'zobrazit' => true], 'vytvor_stranku');
        $source = $this->createPage(['titulek' => 'Heal source', 'zobrazit' => true], 'vytvor_stranku');
        $build = ['v' => 1, 'deti' => [
            ['typ' => 'tlacitko', 'obsah' => ['text' => 'Go', 'odkaz' => '/lh-stare#cast']],
            ['typ' => 'text', 'obsah' => ['html' => '<p><a href="/en/lh-stare">x</a> <a href="/lh-stare-jina">y</a></p>']],
        ]];
        $this->site()->exec('UPDATE ka_stranky SET stavba = ?, text = ? WHERE ids = ?', [json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '<p><a href="/lh-stare">t</a></p>', $source]);
        $this->site()->exec("INSERT INTO ka_menu (umisteni, jazyk, polozky) VALUES ('lhtest', '', ?)", ['[{"typ":"odkaz","url":"/lh-stare","text":"M"}]']);

        $this->mcpText('uprav_stranku', ['id' => $target, 'adresa' => 'lh-nove']);

        $this->assertSame('11111', (string) $this->site()->value("SELECT CONCAT(stavba LIKE '%/lh-nove#cast%', stavba LIKE '%/en/lh-nove%', stavba LIKE '%/lh-stare-jina%', stavba NOT LIKE '%/lh-stare\"%', text LIKE '%/lh-nove%') FROM ka_stranky WHERE ids = ?", [$source]),
            'link healing: a renamed page – button, text and the language form point to the new address, a longer address is left alone');
        $this->assertSame('1', (string) $this->site()->value("SELECT polozky LIKE '%/lh-nove%' FROM ka_menu WHERE umisteni = 'lhtest'"), 'link healing: the menu points to the new address');
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM ka_events WHERE type = 'links.healed' AND data LIKE '%lh-nove%'"), 'link healing: the change is an event with the count');

        $this->site()->exec("DELETE FROM ka_menu WHERE umisteni = 'lhtest'");
    }
}
