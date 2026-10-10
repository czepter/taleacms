<?php

declare(strict_types=1);

namespace Talea\Tests\Site\CollectionsB;

use Talea\Tests\Site\Support\SiteTestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;

/**
 * Was: sections 63 (2.11 F1 six more ready-made collections) and 64 (screen mode for a reception). The screen shows the courses made in 63,
 * so both live in one class.
 */
#[Group('site')]
final class ReadyMadeCollectionsTest extends SiteTestCase
{
    use Helpers;

    private const string NOT_A_SECRET = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testServicesReferencesPriceListFaqMachinesAndCoursesCome(): void
    {
        $site = $this->site();

        $text = $this->mcpText('create_collection', ['name' => 'Preset services', 'preset' => 'services']);
        $this->assertStringContainsString('how_to_use', $text, 'create_collection preset services answers with how to use it');
        $this->assertSame('services|1|Service|5|1', $this->presetRow('preset-services'), 'presets: services – item pages, Service schema, five fields, a hidden list page');
        $this->assertSame('price_from', $site->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.fields.price')) FROM tl_collections WHERE slug = 'preset-services'"), 'presets: the Service schema maps the price to price_from');

        $site->mcp('create_collection', ['name' => 'Preset references', 'preset' => 'references']);
        $this->assertSame('references|1|-|7|1', $this->presetRow('preset-references'), 'presets: references – item pages, no schema, seven fields (the service link included), a hidden list page');
        $this->assertSame('service|item|preset-services', $site->value("SELECT CONCAT(JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[5].key')), '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[5].type')), '|', JSON_UNQUOTE(JSON_EXTRACT(fields, '\$[5].collection'))) FROM tl_collections WHERE slug = 'preset-references'"), 'presets: the service field of a reference links to the services collection');

        $site->mcp('create_collection', ['name' => 'Preset price list', 'preset' => 'price_list']);
        $this->assertSame('price_list|0|-|4|1', $this->presetRow('preset-price-list'), 'presets: price list – no item pages, four fields, a hidden list page');
        $this->assertSame('1|1|1', $site->value("SELECT CONCAT(build LIKE '%\"filter_field\":\"category\"%', '|', build LIKE '%\"filters\":true%', '|', build LIKE '%<p>{{price}}</p>%') FROM tl_pages WHERE slug = 'preset-price-list'"), 'presets: the price list page filters by category with buttons, sorted by order');

        $site->mcp('create_collection', ['name' => 'Preset FAQ', 'preset' => 'faq']);
        $this->assertSame('faq|0|FAQPage|2|1', $this->presetRow('preset-faq'), 'presets: questions and answers – no item pages, FAQPage schema, two fields, a hidden list page');

        $site->mcp('create_collection', ['name' => 'Preset machines', 'preset' => 'machines']);
        $this->assertSame('machines|1|Product|6|1|model', $this->presetRow('preset-machines') . '|' . $site->value("SELECT JSON_UNQUOTE(JSON_EXTRACT(schema_org, '\$.fields.sku')) FROM tl_collections WHERE slug = 'preset-machines'"), 'presets: machines – item pages, Product schema with the model as SKU, six fields');

        $site->mcp('create_collection', ['name' => 'Preset courses', 'preset' => 'courses']);
        $this->assertSame('courses|1|Event|7|1', $this->presetRow('preset-courses'), 'presets: courses – item pages, Event schema, seven fields, a hidden list page');
        $this->assertSame('1|1|1|1', $site->value("SELECT CONCAT(build LIKE '%\"period\":\"upcoming\"%', '|', build LIKE '%\"period_start_field\":\"start\"%', '|', build LIKE '%\"sort_field\":\"start\"%', '|', build LIKE '%<p>{{start}}</p>%') FROM tl_pages WHERE slug = 'preset-courses'"), 'presets: the courses page lists the upcoming ones by start and end, sorted by the start');
        $this->assertSame('1|1|1', $site->value("SELECT CONCAT(build LIKE '%<strong>{{when}}</strong>%', '|', build LIKE '%{{capacity}}%', '|', build LIKE '%\"type\":\"form\"%') FROM tl_collections WHERE slug = 'preset-courses'"), 'presets: the course item template comes from the preset (the dates, the place, the registration form)');
    }

    #[Depends('testServicesReferencesPriceListFaqMachinesAndCoursesCome')]
    public function testCoursesShowOnlyTheUpcomingOne(): void
    {
        $future = date('Y-m-d', strtotime('+30 days'));
        $past = date('Y-m-d', strtotime('-30 days'));
        $text = $this->mcpText('save_collection_item', ['collection' => 'preset-courses', 'name' => 'Welding course', 'slug' => 'welding-course',
            'values' => ['start' => "$future 09:00", 'end' => "$future 16:00", 'place' => 'Brno', 'price' => '1900'], 'visible' => true]);
        $this->assertStringContainsString('welding-course', $text, 'presets: a course with a future start');
        $this->site()->mcp('save_collection_item', ['collection' => 'preset-courses', 'name' => 'Last year course', 'slug' => 'last-year-course', 'values' => ['start' => "$past 09:00", 'place' => 'Praha'], 'visible' => true]);

        $page = $this->assertPage('/preset-courses/welding-course', 200, 'Brno', message: 'presets: the course page shows the place and the formatted start');
        $this->assertStringContainsString('"Event"', $page->body, 'presets: the course page carries the Event structured data');
        $this->assertStringContainsString('"startDate"', $page->body, 'presets: the course page carries the Event start date');

        // the hidden list page in the administrator's preview shows only the course still to come
        $list = $this->site()->admin()->get('/preset-courses?build=draft');
        $this->assertStringContainsString('Welding course', $list->body, 'presets: the courses list shows the future course');
        $this->assertStringNotContainsString('Last year course', $list->body, 'presets: the courses list does not show the past one');
    }

    #[Depends('testCoursesShowOnlyTheUpcomingOne')]
    public function testScreenModeForAReception(): void
    {
        $site = $this->site();
        $visitor = $site->client();

        $this->assertSame(404, $visitor->get('/screen/' . self::NOT_A_SECRET)->status, 'screen: off → 404');

        $this->assertStringContainsString('Unknown collections: missing', $this->mcpText('update_settings', ['settings' => ['screen_collections' => ['missing']]]), 'MCP: the screen shows only collections that exist');

        $text = $this->mcpText('update_settings', ['settings' => ['screen_mode' => 1, 'screen_seconds' => '7', 'screen_collections' => ['preset-courses'], 'screen_hours' => 1, 'screen_clock' => 1]]);
        $secret = $site->settingValue('screen_secret');
        $this->assertSame(32, strlen($secret), 'screen: switching the mode on creates the secret part of the address');
        $this->assertStringContainsString('screen":{"on":true,"seconds":7,"collections":["preset-courses"]', $text, 'MCP: update_settings switches the screen on and reports it');
        $this->assertStringNotContainsString('screen_secret', $text, 'MCP: the report does not name the secret setting');
        $this->assertStringNotContainsString($secret, $text, 'MCP: the report does not contain the secret');

        $page = $this->assertPage("/screen/$secret", 200, '<meta name="robots" content="noindex, nofollow">', $visitor, 'screen: on with the right secret → 200 with noindex');
        foreach (['screen-slide screen-news', 'Welding course', 'screen-hours', 'id="screen-hours"'] as $needle) {
            $this->assertStringContainsString($needle, $page->body, "screen: slides of the news, the upcoming course, today's opening hours and the clock ($needle)");
        }
        $this->assertStringNotContainsString('Last year course', $page->body, 'screen: the past course is not shown');

        $this->assertStringContainsString('<noscript><meta http-equiv="refresh" content="7; url=/screen/' . $secret . '?s=1">', $page->body, 'screen: rotates by a meta refresh without the script');
        $this->assertStringContainsString('setInterval(function(){i=(i+1)%n;show(i)},7000)', $page->body, 'screen: rotates every 7 seconds with the script');
        $this->assertStringContainsString('--tl-color-primary:', $page->body, "screen: in the site's design tokens");

        $headers = $site->client('screen-headers')->get("/screen/$secret?s=1")->headers;
        $this->assertStringStartsWith('noindex', $headers['x-robots-tag'] ?? '', 'screen: noindex header');
        $this->assertStringContainsStringIgnoringCase('no-store', $headers['cache-control'] ?? '', 'screen: no-store header');
        $this->assertArrayNotHasKey('set-cookie', $headers, 'screen: no cookies');

        $this->assertSame(404, $visitor->get('/screen/' . strtr($secret, '0123456789abcdef', '1234567890abcdef0'))->status, 'screen: a wrong secret → 404');
        // (the old tr mapped 0-9a-f to 1-9a-f0: the same shift)

        $general = $this->assertPage('/admin.php?module=settings&tab=general', 200, "screen/$secret", message: 'screen: the admin shows the full address');
        $this->assertStringContainsString('data-copy="#screen-url"', $general->body, 'screen: the copy button');
        $this->assertStringContainsString('name="screen_collections[]" value="preset-courses" checked', $general->body, 'screen: the chosen collection');

        // the "new address" button: the form is posted as a browser does, the old address stops working
        $site->admin()->post('/admin.php?module=settings&action=save', $this->formAsABrowserPosts($general->body) . '&new_screen_token=1');
        $secret2 = $site->settingValue('screen_secret');
        $this->assertSame(32, strlen($secret2), 'screen: the button creates a new address (32 characters)');
        $this->assertNotSame($secret, $secret2, 'screen: the button creates a different address');
        $this->assertSame('404|200|7', $visitor->get("/screen/$secret")->status . '|' . $visitor->get("/screen/$secret2")->status . '|' . $site->settingValue('screen_seconds'), 'screen: the old address stops working, the new one works, the seconds stay');

        $site->mcp('update_settings', ['settings' => ['screen_mode' => 0]]);
        $this->assertSame(404, $visitor->get("/screen/$secret2")->status, 'screen: switched off → 404 even with the right secret');
    }

    /** The settings form as a browser would submit it: every named control with its current value, as a urlencoded body. */
    private function formAsABrowserPosts(string $html): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $x = new \DOMXPath($doc);
        $form = $x->query('//form[.//input[@name="tab"]]')->item(0);
        $pairs = [];
        foreach ($x->query('.//input|.//select|.//textarea', $form) as $e) {
            $name = $e->getAttribute('name');
            $type = $e->getAttribute('type');
            if ($name === '' || $type === 'submit' || (in_array($type, ['checkbox', 'radio'], true) && !$e->hasAttribute('checked'))) {
                continue;
            }
            if ($e->nodeName === 'select') {
                $option = $x->query('.//option[@selected]', $e)->item(0) ?? $x->query('.//option', $e)->item(0);
                $value = $option ? $option->getAttribute('value') : '';
            } else {
                $value = $e->nodeName === 'textarea' ? $e->textContent : $e->getAttribute('value');
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode($value);
        }

        return implode('&', $pairs);
    }
}
