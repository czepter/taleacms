<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\FormsHygiene;

use Kaleta\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Self-healing internal links (was: section 75,). */
#[Group('site')]
final class LinkHealingTest extends SiteTestCase
{
    use McpHelpers;

    public function testRenamingAPageRewritesTheLinksToIt(): void
    {
        $target = $this->createPage(['title' => 'Heal target', 'slug' => 'lh-stare', 'visible' => true], 'create_page');
        $source = $this->createPage(['title' => 'Heal source', 'visible' => true], 'create_page');
        $build = ['v' => 1, 'children' => [
            ['type' => 'button', 'content' => ['text' => 'Go', 'link' => '/lh-stare#cast']],
            ['type' => 'text', 'content' => ['html' => '<p><a href="/en/lh-stare">x</a> <a href="/lh-stare-jina">y</a></p>']],
        ]];
        $this->site()->exec('UPDATE ka_pages SET build = ?, text = ? WHERE page_id = ?', [json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '<p><a href="/lh-stare">t</a></p>', $source]);
        $this->site()->exec("INSERT INTO ka_menus (location, language, items) VALUES ('lhtest', '', ?)", ['[{"type":"link","url":"/lh-stare","text":"M"}]']);

        $this->mcpText('update_page', ['id' => $this->site()->publicId('pages', $target), 'slug' => 'lh-nove']);

        $this->assertSame('11111', (string) $this->site()->value("SELECT CONCAT(build LIKE '%/lh-nove#cast%', build LIKE '%/en/lh-nove%', build LIKE '%/lh-stare-jina%', build NOT LIKE '%/lh-stare\"%', text LIKE '%/lh-nove%') FROM ka_pages WHERE page_id = ?", [$source]),
            'link healing: a renamed page – button, text and the language form point to the new address, a longer address is left alone');
        $this->assertSame('1', (string) $this->site()->value("SELECT items LIKE '%/lh-nove%' FROM ka_menus WHERE location = 'lhtest'"), 'link healing: the menu points to the new address');
        $this->assertSame('1', (string) $this->site()->value("SELECT COUNT(*) FROM ka_events WHERE type = 'links.healed' AND data LIKE '%lh-nove%'"), 'link healing: the change is an event with the count');

        $this->site()->exec("DELETE FROM ka_menus WHERE location = 'lhtest'");
    }
}
