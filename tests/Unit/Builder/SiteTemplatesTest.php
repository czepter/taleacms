<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Builder;

use PHPUnit\Framework\TestCase;
use Talea\Builder\Build;
use Talea\Builder\Looks;
use Talea\Builder\SiteTemplates;

/** Site templates (Builder\SiteTemplates): finished designs whose pages pass the one validator and differ in layout, not only in tokens. */
final class SiteTemplatesTest extends TestCase
{
    public function testEveryTemplateHasALookAndValidPages(): void
    {
        $this->assertNotSame([], SiteTemplates::all());
        foreach (array_keys(SiteTemplates::TEMPLATES) as $key) {
            $this->assertNotNull(Looks::get(SiteTemplates::TEMPLATES[$key][2]), $key . ': its look exists');
            $pages = SiteTemplates::pages($key, 'en');
            $this->assertTrue($pages[0]['home'], $key . ': the home page comes first');
            foreach ($pages as $page) {
                $this->assertNotSame([], $page['build']['children'], $key . ' / ' . $page['title'] . ': has sections');
                [, $errors] = Build::sanitize($page['build']);
                $this->assertSame([], $errors, $key . ' / ' . $page['title'] . ': a stored build passes the validator unchanged');
            }
        }
    }

    public function testEachHomePageIsComposedForItsTemplate(): void
    {
        $firsts = [];
        foreach (array_keys(SiteTemplates::TEMPLATES) as $key) {
            $home = SiteTemplates::pages($key, 'en')[0]['build']['children'];
            $layouts = array_map(fn (array $section): string => (string) ($section['content']['layout'] ?? 'stack'), $home);
            $this->assertContains('compose', $layouts, $key . ': at least one section places its content on the Compose grid');
            $firsts[] = json_encode($home[0]['style']) . json_encode(array_column($home[0]['children'], 'type'));
        }
        $this->assertSame($firsts, array_unique($firsts), 'no two templates open with the same composition');
    }

    public function testNoInventedContent(): void
    {
        foreach (array_keys(SiteTemplates::TEMPLATES) as $key) {
            $text = Build::asText(SiteTemplates::pages($key, 'en')[0]['build']);
            $this->assertDoesNotMatchRegularExpression('/\b(19|20)\d{2}\b|\+\d{2}|@[a-z]/i', $text, $key . ': no years, phone numbers or addresses made up – placeholders in square brackets instead');
        }
    }
}
