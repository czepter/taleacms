<?php

declare(strict_types=1);

namespace Talea\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Talea\Front\NewsText;

final class WordPressTest extends TestCase
{
    /** import from WordPress: reading the export (tools/fixtures/wordpress-sample.xml), preview, safe XML */
    public function testReadingTheExport(): void
    {
        $wpPath = TALEA_ROOT . '/tools/fixtures/wordpress-sample.xml';
        $wpRejects = static function (callable $f): bool { try { $f(); return false; } catch (\RuntimeException) { return true; } };
        $wp = new \Talea\Core\WpFile($wpPath);
        $this->assertFalse($wpRejects(fn () => $wp->verify()), 'WpFile: the sample export passes validation');
        $wpHeader = $wp->header();
        $this->assertSame(['Podhorský zpravodaj', 'https://www.podhorsky-zpravodaj.example'], [$wpHeader['name'], $wpHeader['url']], 'WpFile: the old site from <channel><link>'); // check-english: allow
        $this->assertSame(['redakce' => 'Redakce Zpravodaje', 'bhorakova' => 'Běla Horáková'], $wpHeader['authors'], 'WpFile: authors as login => display name'); // check-english: allow
        $this->assertSame(['zpravy' => ['name' => 'Zprávy', 'parent' => ''], 'z-radnice' => ['name' => 'Z radnice', 'parent' => 'zpravy']], $wpHeader['categories'], 'WpFile: categories with a hierarchy'); // check-english: allow
        $this->assertSame(['most', 'doprava', 'slavnosti'], array_keys($wpHeader['tags']), 'WpFile: three tags');
        $wpItems = iterator_to_array($wp->items());
        $this->assertSame(['post', 'post', 'post', 'post', 'page', 'nav_menu_item', 'attachment', 'attachment', 'attachment'], array_column($wpItems, 'type'), 'WpFile: nine items, types in file order');
        $this->assertSame([7, 8], array_keys(iterator_to_array($wp->items(7))), 'WpFile: skipping already processed items keeps the order');
        $this->assertSame([101, 'publish', true, 201, ['z-radnice' => 'Z radnice'], ['most', 'doprava']], [$wpItems[0]['id'], $wpItems[0]['status'], $wpItems[0]['sticky'], $wpItems[0]['preview'], $wpItems[0]['categories'], array_keys($wpItems[0]['tags'])], 'WpFile: the first post');
        $this->assertFalse(array_key_exists('komentare', $wpItems[0]), 'WpFile: comments are not read');
        $this->assertFalse((bool) preg_match('/posta\.example|198\.51\.100|203\.0\.113/', (string) json_encode($wpItems)), 'WpSoubor: e-mail ani IP se z exportu nikam nedostanou');
        $wpState = \Talea\Core\WpImport::newState('wordpress-sample.xml');
        \Talea\Core\WpImport::analyze($wpState, 30, $wpPath);
        $this->assertSame(['preview', 9, 0], [$wpState['phase'], $wpState['total'], $wpState['position']], 'WpImport preview: phase and item count');
        $this->assertSame([['publish' => 3, 'draft' => 1], ['publish' => 1]], [$wpState['overview']['articles'], $wpState['overview']['pages']], 'WpImport preview: posts by status and pages');
        $this->assertSame([2, 3, 2, 3], [$wpState['overview']['categories'], $wpState['overview']['tags'], $wpState['overview']['authors'], $wpState['overview']['attachments']], 'WpImport preview: categories, tags, authors, attachments');
        $this->assertSame([['nav_menu_item' => 1], ['kontaktni-formular' => 1]], [$wpState['overview']['other'], $wpState['overview']['shortcodes']], 'WpImport preview: warns about a foreign content type and a plugin shortcode');
        $this->assertSame('https://www.podhorsky-zpravodaj.example/wp-content/uploads/2026/05/pohled.jpg', $wpState['attachments'][202] ?? '', 'WpImport preview: attachment addresses for galleries and featured images');
        // import from WordPress: SEO plugin data (SmartCrawl, Yoast SEO, Rank Math)
        $this->assertSame([['_wds_title', '_wds_metadesc', '_wds_meta-robots-noindex'], []], [array_keys($wpItems[0]['meta']), $wpItems[3]['meta']], 'WpFile: reads only the SEO plugins\' meta keys');
        $this->assertSame([
            'SmartCrawl' => ['title' => 2, 'description' => 2, 'noindex' => 0, 'canonical' => 1],
            'Yoast SEO' => ['title' => 0, 'description' => 1, 'noindex' => 1, 'canonical' => 1],
            'Rank Math' => ['title' => 1, 'description' => 1, 'noindex' => 1, 'canonical' => 1],
        ], $wpState['overview']['seo'], 'WpImport preview: SEO data by plugin (the default Yoast pattern is not counted)');
        $wpSeoContext = ['title' => 'Lávka přes Bystřinu', 'sitename' => 'Podhorský zpravodaj', 'sitedesc' => 'Zprávy z údolí', 'excerpt' => 'Po roce oprav.', 'category' => 'Z radnice']; // check-english: allow
        $this->assertSame(['plugin' => 'Yoast SEO', 'title' => 'T', 'description' => '', 'noindex' => false, 'canonical' => '', 'focus_keyword' => ''], \Talea\Core\WpSeo::raw(['_yoast_wpseo_meta-robots-noindex' => '2', '_yoast_wpseo_title' => ' T ']), 'WpSeo::raw: the first plugin with a filled value; Yoast 2 = index');
        $this->assertSame('', \Talea\Core\WpSeo::raw(['_thumbnail_id' => '5'])['plugin'], 'WpSeo::raw: bez SEO meta');
        $this->assertSame([true, false, false, true, false, false], array_map(\Talea\Core\WpSeo::robotsNoindex(...), ['a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}', 'a:1:{i:0;s:5:"index";}', 'a:1:{i:0;s:12:"noimageindex";}', 'noindex,nofollow', 'O:8:"stdClass":0:{}', '']), 'WpSeo::robotsNoindex: a serialized Rank Math array only as text');
        $this->assertSame([true, true, true, true, true, true, false, false], array_map(\Talea\Core\WpSeo::isDefaultPattern(...), ['%%title%% %%sep%% %%sitename%%', '%%title%% %%page%% %%sep%% %%sitename%%', '%title% %sep% %sitename%', '%%title%% | %%sitename%%', '%%title%%', '', 'Blog – %%sitename%%', 'Nabídka %%title%%']), 'WpSeo::isDefaultPattern: only variables and separators = the plugin\'s default pattern'); // check-english: allow
        $this->assertSame('Lávka znovu otevřena – Podhorský zpravodaj', \Talea\Core\WpSeo::title('Lávka znovu otevřena %%sep%% %%sitename%%', $wpSeoContext), 'WpSeo::title: Yoast and SmartCrawl variables are filled in, the separator is a dash'); // check-english: allow
        $this->assertSame('Lávka přes Bystřinu – fotografie – Podhorský zpravodaj', \Talea\Core\WpSeo::title('%title% – fotografie %sep% %sitename%', $wpSeoContext), 'WpSeo::title: Rank Math variables'); // check-english: allow
        $this->assertSame(['', ''], [\Talea\Core\WpSeo::title('%%title%% %%sep%% %%sitename%%', $wpSeoContext), \Talea\Core\WpSeo::title('%title% %page% %sep% %sitename%', $wpSeoContext)], 'WpSeo::title: the default pattern is not imported');
        $this->assertSame('Lávka přes Bystřinu – ' . date('Y') . ' – Blog', \Talea\Core\WpSeo::title('%%title%% %%page%% – %%currentyear%% – Blog', $wpSeoContext), 'WpSeo::title: pagination and date vanish along with an extra separator'); // check-english: allow
        $this->assertSame('Lávka přes Bystřinu – Blog', \Talea\Core\WpSeo::title('%%title%% %%sep%% %%page%% %%sep%% Blog', $wpSeoContext), 'WpSeo::title: a removed variable leaves no double separator'); // check-english: allow
        $this->assertSame(['', null], [\Talea\Core\WpSeo::title('%%title%% %%sep%% %%neznama_promenna%%', $wpSeoContext), \Talea\Core\WpSeo::resolve('%%title%% %%neznama%%', $wpSeoContext)], 'WpSeo::title: an unknown variable = the title is dropped, not broken');
        $this->assertSame('Nabídka Lávka přes Bystřinu', \Talea\Core\WpSeo::title('Nabídka %%title%% %%cf_moje_pole%% %%ct_oblast%%', $wpSeoContext), 'WpSeo::title: custom fields (cf_) and terms (ct_) are only removed'); // check-english: allow
        $this->assertSame('', \Talea\Core\WpSeo::title('%%title%%%%cf_x%%', $wpSeoContext), 'WpSeo::title: identical to the post title is not stored');
        $this->assertSame(200, mb_strlen(\Talea\Core\WpSeo::title(str_repeat('ž', 300), $wpSeoContext, 200)), 'WpSeo::title: length by column'); // check-english: allow
        $this->assertSame('Po roce oprav. Více v rubrice Z radnice.', \Talea\Core\WpSeo::description('%%excerpt%% Více v rubrice %%primary_category%%.', $wpSeoContext), 'WpSeo::description: excerpt and main category'); // check-english: allow
        $this->assertSame('', \Talea\Core\WpSeo::description('%%excerpt%%', $wpSeoContext), 'WpSeo::description: only %%excerpt%% is the default pattern – the site builds the description itself');
        $this->assertSame('Sýr & víno v Podhorský zpravodaj', \Talea\Core\WpSeo::description('Sýr &amp; <b>víno</b> v %sitename%', $wpSeoContext), 'WpSeo::description: tags and entities gone'); // check-english: allow
        $this->assertSame([15, true], [count(\Talea\Core\WpSeo::keys()), in_array('rank_math_robots', \Talea\Core\WpSeo::keys(), true)], 'WpSeo::keys: one key list from all plugins');
        $this->assertSame(['lávka', 'a, b'], [\Talea\Core\WpSeo::raw(['rank_math_focus_keyword' => 'lávka, b'])['focus_keyword'], \Talea\Core\WpSeo::raw(['_wds_focus-keywords' => 'a, b'])['focus_keyword']], 'WpSeo::raw: Rank Math keeps the first keyword, others the field as it is'); // check-english: allow
        // import from WordPress: content cleanup
        $wpClean = \Talea\Core\WpContent::sanitize(...);
        $this->assertSame("<p>První řádek<br>\ndruhý řádek</p>\n<p>Druhý odstavec</p>", $wpClean("První řádek\ndruhý řádek\n\nDruhý odstavec"), 'WpContent: classic editor – paragraphs from empty lines, <br> from line ends'); // check-english: allow
        $this->assertSame("<p>Úvod</p>\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>", $wpClean("Úvod\n\n<h2>Titulek</h2>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>"), 'WpContent: block tags are not wrapped in <p>'); // check-english: allow
        $this->assertSame("<p>Text</p>\n<h2>Nadpis</h2>", $wpClean("<!-- wp:paragraph -->\n<p>Text</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Nadpis</h1>\n<!-- /wp:heading -->"), 'WpContent: Gutenberg comments vanish, paragraphs stay');
        $this->assertSame('<figure><img src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" loading="lazy"><figcaption>Most přes řeku</figcaption></figure>', $wpClean('[caption id="attachment_5" align="alignnone" width="300"]<a href="https://stary.example/wp-content/uploads/most.jpg"><img class="size-medium" src="https://stary.example/wp-content/uploads/most-300x200.jpg" alt="Most" width="300" height="200" /></a> Most přes řeku[/caption]'), 'WpContent: [caption] → figure with a caption, the link to the large image vanishes'); // check-english: allow
        $this->assertSame('<figure class="gallery"><img src="https://stary.example/a.jpg" alt="" loading="lazy"><img src="https://stary.example/b.png" alt="" loading="lazy"></figure>', $wpClean('[gallery ids="5,6,7,99" columns="2"]', [5 => 'https://stary.example/a.jpg', 6 => 'https://stary.example/b.png', 7 => 'https://stary.example/dokument.pdf']), 'WpContent: [gallery ids] → our gallery only from known images');
        $this->assertSame('<figure class="gallery"><img src="https://stary.example/a.jpg" alt="A" loading="lazy"></figure>', $wpClean('<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"><!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://stary.example/a.jpg" alt="A" class="wp-image-5"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->'), 'WpContent: Gutenberg gallery block → our gallery');
        $this->assertSame("<p>Text před</p>\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>\n<p>Text po</p>", $wpClean("Text před\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\nText po"), 'WpContent: a YouTube address on its own line is its own paragraph'); // check-english: allow
        $this->assertTrue(str_contains((new \ReflectionClass(NewsText::class))->newInstanceWithoutConstructor()->embedVideoUrls($wpClean("https://www.youtube.com/watch?v=dQw4w9WgXcQ")), 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'WpContent: the site turns such a paragraph into a player');
        $this->assertSame('<p>https://vimeo.com/76979871</p>', $wpClean('<!-- wp:embed {"url":"https://vimeo.com/76979871","type":"video"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/76979871</div></figure><!-- /wp:embed -->'), 'WpContent: embed block → address in a paragraph');
        $this->assertSame('<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>', $wpClean('<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560"></iframe><iframe src="https://zly.example/"></iframe>'), 'WpContent: YouTube iframe → address, a foreign iframe gone');
        $this->assertSame('<p>Text uvnitř  citace [sic] a [[ukázka]]</p>', $wpClean('[vc_row][vc_column width="1/2"]Text uvnitř[/vc_column][/vc_row] [contact-form-7 id="1"] citace [sic] a [[ukázka]]'), 'WpContent: plugin shortcodes vanish, their text and [sic] stay'); // check-english: allow
        $this->assertSame("<pre>pole[muj_klic] = 1;\n\nkonec</pre>", $wpClean("<pre>pole[muj_klic] = 1;\n\nkonec</pre>"), 'WpContent: brackets in a code sample do not change');
        $wpUnsafe = $wpClean('<p onclick="x()" style="color:red">Klik <a href="java&#9;script:alert(1)" onmouseover="x()">odkaz</a> <a href="https://dobry.example/" target="_blank">ven</a></p><script>alert(1)</script><style>p{}</style><img src="data:image/svg+xml;base64,AAAA"><img src="https://stary.example/a.jpg" onerror="alert(1)" srcset="x 2x"><svg onload="alert(1)"><circle/></svg><form action="/x"><input name="a"></form><object data="x"></object><div class="wrap"><span>Text v divu</span></div>');
        $this->assertSame("<p>Klik odkaz <a href=\"https://dobry.example/\" target=\"_blank\" rel=\"noopener\">ven</a></p>\n<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"></figure>\n<p>Text v divu</p>", $wpUnsafe, 'WpContent: scripts, styles, event handlers, javascript: and data: addresses do not pass'); // check-english: allow
        $this->assertFalse((bool) preg_match('/<script|<style|<svg|<form|<iframe|<object|\son[a-z]+=|javascript:|data:|style=|srcset=/i', $wpUnsafe), 'WpContent: nothing dangerous is left after cleaning');
        $this->assertSame([true, true, true, true, false, false, false, false, false], array_map(\Talea\Core\WpContent::isSafeUrl(...), ['https://a.cz/', '/clanek/x', '#kotva', 'mailto:a@b.cz', "java\nscript:alert(1)", ' JAVASCRIPT:alert(1)', 'data:text/html,x', 'vbscript:x', '']), 'WpObsah::bezpecnaAdresa');
        $this->assertSame(['<p>Ruční výtah &amp; spol.</p>', "<p>Odstavec jedna</p>\n<p>Odstavec dva</p>"], \Talea\Core\WpContent::introAndText('Ruční <b>výtah</b> &amp; spol.', "Odstavec jedna\n\nOdstavec dva"), 'WpContent: the perex from the WordPress excerpt, the full text'); // check-english: allow
        $this->assertSame(['<p>Odstavec jedna</p>', "<figure><img src=\"https://stary.example/a.jpg\" alt=\"\" loading=\"lazy\"><figcaption>Popisek</figcaption></figure>\n<p>Odstavec dva</p>"], \Talea\Core\WpContent::introAndText('', "[caption]<img src=\"https://stary.example/a.jpg\" alt=\"\"> Popisek[/caption]\n\nOdstavec jedna\n\nOdstavec dva"), 'WpContent: without an excerpt the first paragraph is the perex and is not repeated in the text'); // check-english: allow
        $this->assertSame(['<p>Před značkou</p>', '<p>Za značkou</p>'], \Talea\Core\WpContent::introAndText('', "Před značkou\n<!--more-->\nZa značkou"), 'WpContent: the "Read more" marker splits perex and text'); // check-english: allow
        $this->assertSame(['et_pb_section'], \Talea\Core\WpContent::unknownShortcodes('[gallery ids="1"] [caption]x[/caption] [et_pb_section]a[/et_pb_section] [sic] <code>[muj_klic]</code>'), 'WpContent: foreign shortcodes warned about in the preview');
        [$wpIntro, $wpText] = \Talea\Core\WpContent::introAndText($wpItems[0]['excerpt'], $wpItems[0]['content'], $wpState['attachments']);
        $this->assertSame([true, 1, true, 1, false], [str_starts_with($wpIntro, '<p>Po dvanácti měsících'), substr_count($wpText, '<figcaption>'), str_contains($wpText, '<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>'), substr_count($wpText, 'class="gallery"'), (bool) preg_match('/script|onclick|kontaktni-formular|javascript/i', $wpText)], 'WpContent: the sample post – perex, image with a caption, video, gallery, no script and shortcode'); // check-english: allow
    }

    /** import from WordPress: custom post types and fields as collections (2.7, tools/fixtures/wordpress-cpt.xml) */
    public function testCustomPostTypes(): void
    {
        $wpRejects = static function (callable $f): bool { try { $f(); return false; } catch (\RuntimeException) { return true; } };
        $this->assertSame([true, true, true, false, false, false, false, false, false, false, false], array_map(\Talea\Core\WpTypes::isCustomType(...), ['reference', 'team_member', 'product', 'page', 'nav_menu_item', 'wp_block', 'acf-field', 'breakdance_template', 'shop_order', 'wpcf7_contact_form', 'Bad Type!']), 'WpTypes::isCustomType: own types yes, WordPress and plugin internals no');
        $this->assertSame(['client_id' => 'A', 'cena' => '100'], \Talea\Core\WpTypes::fields(['client_id' => 'A', '_klient' => 'field_1', 'rank_math_title' => 'x', '_edit_lock' => '1', 'ekit_views' => '3', 'cena' => '100', 'site-sidebar-layout' => 'x']), 'WpTypes::fields: ACF fields always, plugin and underscore meta never');
        $this->assertSame(// check-english: allow
            ['image', 'image', 'number', 'image', 'date', 'number', 'link', 'text', 'number', 'html', 'lines', 'text', null, 'text'], array_map(fn (array $c): ?string => \Talea\Core\WpTypes::guessType($c[0], $c[1], [301 => 'https://x/a.jpg']), [
            ['fotka', '301'], ['logo_firmy', '77'], ['count', '77'], ['x', 'https://old.example/a/b.png?v=2'], ['date', '20240315'], ['date', '20241345'], ['web', 'https://novakovi.example'],
            ['cena', '1 200'], ['cena', '1200,50'], ['description', '<p>Hi</p>'], ['url', "Ulice 1\nMěsto"], ['name', 'Jana'], ['galerie', 'a:2:{i:0;s:3:"301";}'], ['x', '']]), 'WpTypes::guessType'); // check-english: allow
        $this->assertSame(['lines', 'image', 'text', '2024-03-15', '', 'Team member role', 'reference', ''], [\Talea\Core\WpTypes::fieldType(['text' => 3, 'lines' => 1]), \Talea\Core\WpTypes::fieldType(['image' => 1, 'text' => 0]), \Talea\Core\WpTypes::fieldType([]),
            \Talea\Core\WpTypes::date('20240315'), \Talea\Core\WpTypes::date('2024-02-30'), \Talea\Core\WpTypes::label('team_member-role'), \Talea\Core\WpTypes::prefix('https://a.cz/reference/kuchyne/'), \Talea\Core\WpTypes::prefix('https://a.cz/?p=4')], 'WpTypes::fieldType, date, label, prefix');
        $cptPath = TALEA_ROOT . '/tools/fixtures/wordpress-cpt.xml';
        $cptItems = iterator_to_array((new \Talea\Core\WpFile($cptPath))->items());
        $this->assertSame([['klient', '_klient', 'rok_dokonceni', '_rok_dokonceni', 'datum_predani', '_datum_predani', 'web_klienta', '_web_klienta', 'fotka', '_fotka', 'galerie', '_galerie', 'rank_math_seo_score', 'ekit_post_views_count'], []], [array_keys($cptItems[1]['fields']), $cptItems[0]['fields']], 'WpSoubor: fields of a custom post type are read, of other types not'); // check-english: allow
        $cptState = \Talea\Core\WpImport::newState('wordpress-cpt.xml');
        \Talea\Core\WpImport::analyze($cptState, 30, $cptPath);
        $this->assertSame([['reference' => [
            'count' => 2, 'prefixes' => ['reference' => 2], 'fields' => ['klient' => ['text' => 2], 'rok_dokonceni' => ['number' => 2], 'datum_predani' => ['date' => 2], 'web_klienta' => ['link' => 1], 'fotka' => ['image' => 1, 'text' => 0]],
            'left_out' => ['galerie' => true], 'content' => true, 'excerpt' => false]], []], [$cptState['overview']['types'], $cptState['overview']['other']], 'WpImport preview: a custom post type with its fields, address and what is left out'); // check-english: allow

        $wpTmp = sys_get_temp_dir() . '/talea-wp-' . bin2hex(random_bytes(4));
        mkdir($wpTmp);
        $wpHead = '<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title><link>https://stary.example</link>';
        file_put_contents($wpTmp . '/tajne.txt', 'TAJNY-OBSAH-SERVERU');
        $wpMalicious = [
            'vnější entita (XXE)' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file://' . $wpTmp . '/tajne.txt">]>' . $wpHead . '<item><title>&xxe;</title><content:encoded>&xxe;</content:encoded></item></channel></rss>', // check-english: allow
            'miliarda smíchů' => '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY a "haha"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;">]>' . $wpHead . '<item><title>&c;</title></item></channel></rss>', // check-english: allow
            'vnější DTD' => '<?xml version="1.0"?><!DOCTYPE rss SYSTEM "http://127.0.0.1:1/zly.dtd">' . $wpHead . '</channel></rss>', // check-english: allow
            'nedefinovaná entita' => '<?xml version="1.0"?>' . $wpHead . '<item><title>&neexistuje;</title></item></channel></rss>', // check-english: allow
            'jiné XML než export WordPressu' => '<?xml version="1.0"?><rss version="2.0"><channel><title>Obyčejné RSS</title><item><title>x</title></item></channel></rss>', // check-english: allow
            'poškozené XML' => '<?xml version="1.0"?>' . $wpHead . '<item><title>neuzavřeno</item>', // check-english: allow
            'HTML místo XML' => '<html><body>xmlns:wp="http://wordpress.org/export/1.2/"</body></html>', // check-english: allow
        ];
        foreach ($wpMalicious as $label => $xml) {
            file_put_contents($wpTmp . '/zly.xml', $xml);
            $read = '';
            $rejected = $wpRejects(function () use ($wpTmp, &$read): void {
                $bad = new \Talea\Core\WpFile($wpTmp . '/zly.xml');
                $bad->verify();
                $read = (string) json_encode([$bad->header(), iterator_to_array($bad->items())]);
            });
            $this->assertSame([true, false], [$rejected, str_contains($read, 'TAJNY-OBSAH') || str_contains($read, 'hahahaha')], 'WpFile rejects: ' . $label);
        }
        file_put_contents($wpTmp . '/dobry.xml', '<?xml version="1.0"?>' . $wpHead . '<item><title>A &amp; B</title></item></channel></rss>');
        $this->assertSame('A & B', iterator_to_array((new \Talea\Core\WpFile($wpTmp . '/dobry.xml'))->items())[0]['title'], 'WpFile: common entities (&amp;) are fine');
        exec('rm -rf ' . escapeshellarg($wpTmp));
        foreach (['export.xml' => true, 'Můj web.WordPress.2026-09-21.XML' => true, '../config.xml' => false, 'slozka/export.xml' => false, '.skryty.xml' => false, 'export.php' => false, 'export.xml.php' => false, "export\0.xml" => false, '' => false] as $name => $expectedResult) { // check-english: allow
            $this->assertSame($expectedResult, \Talea\Core\WpFile::isValidName((string) $name), 'WpSoubor::platnyNazev ' . json_encode((string) $name));
        }
        $this->assertSame('muj-web-wordpress-2026-09-21.xml', \Talea\Core\WpFile::uploadName('Můj web.WordPress.2026-09-21.xml'), 'WpFile: the uploaded file name without diacritics and always .xml'); // check-english: allow
    }

    /** import from WordPress: status, date, URLs */
    public function testStatusDateAndUrls(): void
    {
        $wpStatuses = [];
        foreach (['publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'inherit', 'nesmysl'] as $wpS) {
            $wpM = \Talea\Core\WpImport::articleStatus($wpS);
            $wpStatuses[$wpS] = $wpM === null ? 'vynechat' : ($wpM['visible'] ? 'vydany' : 'koncept'); // check-english: allow
        }
        $this->assertSame(['publish' => 'vydany', 'future' => 'vydany', 'draft' => 'koncept', 'pending' => 'koncept', 'private' => 'vynechat', 'trash' => 'vynechat', 'auto-draft' => 'vynechat', 'inherit' => 'vynechat', 'nesmysl' => 'vynechat'], $wpStatuses, 'WpImport::articleStatus'); // check-english: allow
        $this->assertSame(['visible' => 0], \Talea\Core\WpImport::articleStatus('publish', true), 'WpImport::articleStatus: a password-protected post is not published');
        $this->assertSame('2026-05-12 09:30:00', \Talea\Core\WpImport::date(['date' => '2026-05-12 09:30:00', 'date_gmt' => '2026-05-12 07:30:00']), 'WpImport::date: local time of the old site');
        $this->assertSame(date('Y-m-d H:i:s', 1789000000), \Talea\Core\WpImport::date(['date' => '0000-00-00 00:00:00', 'date_gmt' => '0000-00-00 00:00:00', 'pub_date' => ''], 1789000000), 'WpImport::date: a draft with a zero date gets today');
        $wpTaken = ['lavka', 'lavka-2'];
        $this->assertSame('lavka-3', \Talea\Core\WpImport::availableSlug('lavka', fn (string $a): bool => in_array($a, $wpTaken, true)), 'WpImport::freeAddress: a taken address gets a number');
        $this->assertSame('most', \Talea\Core\WpImport::availableSlug('most', fn (string $a): bool => in_array($a, $wpTaken, true)), 'WpImport::freeAddress: a free one stays');
        $this->assertSame(['2026/05/lavka', '', 'blog/příklad', ''], array_map(\Talea\Core\WpImport::oldPath(...), ['https://stary.example/2026/05/lavka/', 'https://stary.example/?p=104', 'https://stary.example/blog/p%C5%99%C3%ADklad/', 'https://stary.example/' . str_repeat('x', 300)]), 'WpImport::oldPath'); // check-english: allow
        $this->assertSame(['blog/party-' . "\u{1F389}", 'blog/party-' . "\u{1F389}"], [\Talea\Core\WpImport::oldPath('https://old.example/blog/party-' . "\u{1F389}" . '/'), \Talea\Core\WpImport::oldPath('https://old.example/blog/party-%F0%9F%8E%89/')],
            'WpImport::oldPath: an emoji address written raw or percent-encoded gives the same path');
        $this->assertSame(['https://s.example/u/foto.jpg', 'https://s.example/u/foto.JPG', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf'], array_map(\Talea\Core\WpImport::withoutSize(...), ['https://s.example/u/foto-300x200.jpg', 'https://s.example/u/foto-1024x683.JPG?ver=2', 'https://s.example/u/foto.png', 'https://s.example/u/plan-2x4.pdf']), 'WpImport::bezRozmeru');
        $this->assertSame(['wp:stary.example', 'wp', 40], [\Talea\Core\WpImport::source('https://WWW.Stary.example/blog'), \Talea\Core\WpImport::source(''), strlen(\Talea\Core\WpImport::source('https://' . str_repeat('a', 60) . '.example'))], 'WpImport::source: the old site\'s domain, at most 40 characters');
        $this->assertSame([null, null, null], [\Talea\Core\SiteExport::path('../config.php'), \Talea\Core\SiteExport::path('export-20260921-101500.zip/../../config.php'), \Talea\Core\SiteExport::path('talea-20260918-130917-rucni-7d777965.sql.gz')], 'SiteExport::path: only export names, nothing outside the folder');
    }

    /** import from WordPress: downloading images only from the old site and only from public URLs (SSRF protection) */
    public function testImageDownloader(): void
    {
        $wpDownload = new \Talea\Core\ImageDownloader('https://www.stary-web.example/blog/');
        $this->assertSame('stary-web.example', $wpDownload->domain(), 'ImageDownloader: the old site\'s domain without www');
        foreach ([
            'https://www.stary-web.example/wp-content/uploads/a.jpg' => true,
            'http://stary-web.example/a.png' => true,
            'https://STARY-WEB.example./a.png' => true,
            'https://stary-web.example:443/a.png' => true,
            'https://stary-web.example:8443/a.png' => false,                 // another port
            'http://stary-web.example:22/a.png' => false,
            'https://cdn.stary-web.example/a.png' => false,                  // another (sub)domain
            'https://stary-web.example.utocnik.example/a.png' => false,
            'https://utocnik.example/stary-web.example/a.png' => false,
            'https://stary-web.example@utocnik.example/a.png' => false,      // a domain hidden behind a user name
            'https://uzivatel:heslo@stary-web.example/a.png' => false,       // credentials in the URL
            'ftp://stary-web.example/a.png' => false,
            'file:///etc/passwd' => false,
            'gopher://stary-web.example/' => false,
            '//stary-web.example/a.png' => false,
            'http://127.0.0.1/a.png' => false,
            'http://169.254.169.254/latest/meta-data/' => false,
            "https://stary-web.example/a.png\r\nHost: jinam" => false,       // injected headers
            'https://stary-web.example\\@utocnik.example/a.png' => false,
            '' => false,
        ] as $wpUrl => $expectedResult) {
            $this->assertSame($expectedResult, $wpDownload->isAllowedUrl((string) $wpUrl), 'StahovaniObrazku::povolenaAdresa ' . json_encode((string) $wpUrl));
        }
        $this->assertFalse((new \Talea\Core\ImageDownloader(''))->isAllowedUrl('https://cokoli.example/a.png'), 'ImageDownloader: without the old site\'s address nothing is downloaded');
        foreach ([
            '93.184.216.34' => true, '8.8.8.8' => true, '172.32.0.1' => true, '100.128.0.1' => true, '2606:4700:4700::1111' => true, '::ffff:93.184.216.34' => true,
            '10.0.0.5' => false, '172.16.0.1' => false, '172.31.255.255' => false, '192.168.1.1' => false, '127.0.0.1' => false, '127.255.255.254' => false,
            '169.254.169.254' => false, '100.64.0.1' => false, '100.127.255.255' => false, '0.0.0.0' => false, '0.1.2.3' => false, '224.0.0.1' => false, '255.255.255.255' => false,
            '192.0.2.10' => false, '198.18.0.1' => false, '::1' => false, '::' => false, 'fc00::1' => false, 'fd12:3456::1' => false, 'fe80::1' => false, 'ff02::1' => false,
            '::ffff:10.0.0.1' => false, '::ffff:127.0.0.1' => false, '64:ff9b::a00:1' => false, '::10.0.0.1' => false, '2002:a00:1::1' => false, '2001:0:4136:e378:8000:63bf:3fff:fdd2' => false,
            '2001:4860:4860::8888' => true, '[::1]' => false, 'neni-ip' => false, '' => false,
        ] as $wpIp => $expectedResult) {
            $this->assertSame($expectedResult, \Talea\Core\ImageDownloader::isPublicIp((string) $wpIp), 'StahovaniObrazku::verejnaIp ' . $wpIp);
        }
        $this->assertSame([null, null, '93.184.216.34'], [$wpDownload->verifiedIp('127.0.0.1'), $wpDownload->verifiedIp('[::1]'), $wpDownload->verifiedIp('93.184.216.34')], 'ImageDownloader: an IP address instead of a domain is judged the same');
        $this->assertFalse($wpDownload->isAllowedUrl(\Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'https://utocnik.example/a.png')), 'ImageDownloader: a redirect to another domain does not pass the next check round');
        $this->assertSame([false, false], [$wpDownload->isAllowedUrl(\Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', '//utocnik.example/a.png')), $wpDownload->isAllowedUrl(\Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'http://169.254.169.254/'))], 'ImageDownloader: a redirect to //elsewhere and to the internal network does not pass');
        $this->assertSame(['https://stary-web.example/jinde/b.png', 'https://stary-web.example/u/b.png'], [\Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', '/jinde/b.png'), \Talea\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', 'b.png')], 'ImageDownloader: a relative redirect stays on the old site');
        $wpPng = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        $wpSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
        $this->assertSame('image/png', \Talea\Core\ImageDownloader::imageType('image/png; charset=binary', $wpPng), 'ImageDownloader::imageType: PNG by header and content');
        $this->assertNull(\Talea\Core\ImageDownloader::imageType('image/jpeg', '<html><body>přihlášení</body></html>'), 'ImageDownloader::imageType: the header claims an image, the content is HTML'); // check-english: allow
        $this->assertNull(\Talea\Core\ImageDownloader::imageType('text/html', $wpPng), 'ImageDownloader::imageType: the content is an image, the header is not');
        $this->assertSame([null, null], [\Talea\Core\ImageDownloader::imageType('image/svg+xml', $wpSvg), \Talea\Core\ImageDownloader::imageType('image/png', $wpSvg)], 'ImageDownloader::imageType: SVG is always rejected');
        $this->assertNull(\Talea\Core\ImageDownloader::imageType('image/png', ''), 'ImageDownloader::imageType: an empty response');
        $this->assertSame([15 * 1024 * 1024, 3, 5, 20], [\Talea\Core\ImageDownloader::MAX_BYTES, \Talea\Core\ImageDownloader::MAX_REDIRECTS, \Talea\Core\ImageDownloader::CONNECT_TIMEOUT, \Talea\Core\ImageDownloader::TOTAL_TIMEOUT], 'ImageDownloader: limits as specified (15 MB, 3 redirects, 5 s connection, 20 s total)');
        $wpSourceHtml = (string) file_get_contents(TALEA_ROOT . '/system/src/Core/ImageDownloader.php');
        $this->assertSame([1, true, false, true], [substr_count($wpSourceHtml, 'CURLOPT_FOLLOWLOCATION => false'), str_contains($wpSourceHtml, "'follow_location' => 0"), (bool) preg_match('/CURLOPT_(COOKIE\w*|USERPWD|HTTPHEADER|HTTPAUTH)\b/', $wpSourceHtml), str_contains($wpSourceHtml, "'Talea-import'")], 'ImageDownloader: redirects are never followed automatically and nothing extra is sent');
    }
}
