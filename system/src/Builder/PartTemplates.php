<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Language;

/**
 * Ready-made templates of site parts (1.7): a few clean skeletons of the header, the footer and the wrappers. They carry
 * structure only – colours, fonts, spacing and corners come from the design system tokens, so every template fits every
 * site (Kaleta is themeless). A template goes into the part's draft; the published version stays until publishing.
 */
final class PartTemplates
{
    /** type => key => [name, description, extension it needs ('' = none)] */
    public const array LIST = [
        'hlavicka' => [
            'klasicka' => ['Logo left, menu right', 'The logo on the left, the menu and a button on the right. Stays at the top while scrolling.', ''],
            'na-stred' => ['Centred logo', 'The logo in the middle with the menu below it – for a site led by the brand.', ''],
            's-listou' => ['With a contact bar', 'A thin bar with the phone and e-mail above the logo and the menu.', ''],
            'minimalni' => ['Minimal', 'Only the logo and one button – for a campaign page without the menu.', ''],
        ],
        'footer' => [
            'columns' => ['Columns', 'The company, the footer menu, contacts and a newsletter sign-up, the copyright below.', ''],
            'kompaktni' => ['Compact', 'One line: the company name, the footer menu and the copyright.', ''],
            'imprint' => ['With the imprint', 'The company details for the imprint next to the footer menu and social networks.', ''],
            'vyzva' => ['With a call to action', 'A coloured call to action above a two-column footer.', ''],
        ],
        'novinka' => [
            's-vyzvou' => ['With a call to action', 'The news item and a call to action below it.', ''],
            'jednoducha' => ['Plain', 'Only the news item.', ''],
        ],
        'vypis' => [
            'jednoduchy' => ['Plain', 'Only the list of news.', ''],
            's-odberem' => ['With a newsletter sign-up', 'The list of news and a newsletter sign-up below it.', 'newsletter_signup'],
        ],
        'nenalezeno' => [
            'jednoducha' => ['Plain', 'Only the message and the links from the system.', ''],
            's-hledanim' => ['With search', 'The message with a search box below it.', ''],
        ],
    ];

    /** @return list<array{klic: string, nazev: string, popis: string}> templates of the part type, without those of disabled extensions */
    public static function forType(string $type, ?array $extensions = null): array
    {
        $out = [];
        foreach (self::LIST[$type] ?? [] as $key => [$name, $description, $extension]) {
            if ($extension === '' || $extensions === null || in_array($extension, $extensions, true)) {
                $out[] = ['key' => $key, 'nazev' => $name, 'popis' => $description];
            }
        }

        return $out;
    }

    /**
     * The template's build in the language of the part (texts through the site dictionary), or null for an unknown template.
     *
     * @param list<string> $extensions enabled extensions (the newsletter sign-up only with the Newsletter extension)
     */
    public static function build(string $type, string $key, string $language, array $extensions = []): ?array
    {
        if (!isset(self::LIST[$type][$key])) {
            return null;
        }

        return Language::runWith($language, function () use ($type, $key, $extensions): array {
            $n = Build::fresh(...);
            $s = fn (array $p, array $style): array => ['style' => $style] + $p;
            $z = fn (array $p, string $htmlTag): array => ['tag' => $htmlTag] + $p;
            $url = fn (string $page): string => '/' . slugify(t($page));
            $row = fn (array $children, string $distribution = 'space-between'): array => $s($n('container', [], $children),
                ['base' => ['display' => 'flex', 'direction' => 'row', 'justify_content' => $distribution, 'align_items' => 'center', 'wrap' => 'wrap', 'gap' => 'm']]);
            $header = fn (array $children, array $style = []): array => $s($z($n('section', [], $children), 'header'),
                ['base' => $style + ['padding_y' => 's', 'background' => 'background', 'border_bottom' => '1px solid var(--ka-barva-linka)', 'position' => 'sticky', 'top' => '0', 'z_index' => '10']]);
            $footer = fn (array $children): array => $s($z($n('section', [], $children), 'footer'),
                ['base' => ['padding_y' => 'xl', 'background' => 'surface', 'border_top' => '1px solid var(--ka-barva-linka)']]);
            $copyright = $s($n('company_details', ['detail' => 'copyright']), ['base' => ['margin_top' => 'l', 'font_size' => '-1', 'color' => 'muted']]);
            $companyName = $s($z($n('company_details', ['detail' => 'name']), 'p'), ['base' => ['font_weight' => '700']]);
            $footerMenu = $n('navigation', ['menu' => 'footer', 'news_link' => false, 'phone_menu' => false]);
            $button = $n('button', ['text' => t('Request a quote'), 'link' => $url('Contact')]);

            $children = match ($type . ':' . $key) {
                'hlavicka:klasicka' => [$header([$row([$n('logo'), $row([$n('navigation'), $s($button, ['mobile' => ['display' => 'none']])], 'end')])])],
                'hlavicka:na-stred' => [$header([$s($n('container', [], [$n('logo'), $n('navigation')]),
                    ['base' => ['display' => 'flex', 'direction' => 'column', 'align_items' => 'center', 'gap' => 's']])], ['padding_y' => 'm', 'position' => 'static'])],
                'hlavicka:s-listou' => [$header([
                    $s($row([$n('company_details', ['detail' => 'phone']), $n('company_details', ['detail' => 'email'])], 'end'), ['base' => ['font_size' => '-1', 'color' => 'muted', 'margin_bottom' => 's']]),
                    $row([$n('logo'), $n('navigation')]),
                ])],
                'hlavicka:minimalni' => [$header([$row([$n('logo'), $button])], ['position' => 'static'])],
                'footer:columns' => [$footer([
                    $s($n('grid', [], [
                        $n('container', [], [$companyName, $n('company_details', ['detail' => 'description'])]),
                        $n('container', [], [$footerMenu]),
                        $n('container', [], [$n('company_details', ['detail' => 'address']), $n('company_details', ['detail' => 'phone']), $n('company_details', ['detail' => 'email']), $n('company_details', ['detail' => 'social'])]),
                        $n('container', [], [in_array('newsletter_signup', $extensions, true) ? $n('newsletter_signup') : $n('company_details', ['detail' => 'hours'])]),
                    ]), ['base' => ['display' => 'grid', 'columns' => '4', 'gap' => 'l'], 'tablet' => ['columns' => '2'], 'mobile' => ['columns' => '1']]),
                    $copyright,
                ])],
                'footer:kompaktni' => [$footer([$row([$companyName, $footerMenu, $s($n('company_details', ['detail' => 'copyright']), ['base' => ['font_size' => '-1', 'color' => 'muted']])])])],
                'footer:imprint' => [$footer([
                    $s($n('grid', [], [$n('company_details', ['detail' => 'imprint']), $n('container', [], [$footerMenu, $n('company_details', ['detail' => 'social'])])]),
                        ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => 'l'], 'mobile' => ['columns' => '1']]),
                    $copyright,
                ])],
                'footer:vyzva' => [Library::section('vyzva', Language::code())['element'], ...SiteParts::defaults('footer', Language::code())['children']],
                'novinka:s-vyzvou' => [$n('page_content'), Library::section('vyzva', Language::code())['element']],
                'vypis:s-odberem' => [$n('page_content'), $s($n('section', [], [$s($n('container', [], [$n('newsletter_signup')]), ['base' => ['max_width' => '40rem', 'center' => 'auto']])]),
                    ['base' => ['padding_y' => 'xl']])],
                'nenalezeno:s-hledanim' => [$n('page_content'), $s($n('section', [], [$s($n('container', [], [$n('search')]), ['base' => ['max_width' => '32rem', 'center' => 'auto']])]),
                    ['base' => ['padding_y' => 'l']])],
                default => [$n('page_content')], // plain wrappers
            };

            return Build::sanitize(['v' => Build::VERSION, 'children' => $children])[0];
        });
    }
}
