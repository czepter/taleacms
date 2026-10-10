<?php

declare(strict_types=1);

namespace Talea\Builder;

/**
 * Section library for a company site: ready-made builds from design system tokens and a few shared classes, so that after
 * insertion they fit the site's colors and fonts right away. The editor inserts a copy (new ids); the classes the section uses
 * are created when the site does not have them yet.
 */
final class Library
{
    /** Shared classes of the library (name => style). Created on the first insertion of a section that uses them; then they belong to the site. */
    public const array CLASSES = [
        'card' => ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 's', 'padding_y' => 'l', 'padding_x' => 'l', 'background' => 'surface', 'radius' => 'm']],
        'section-heading' => ['base' => ['line_length' => 'var(--tl-text-width)', 'margin_bottom' => 'l']],
        'subtitle' => ['base' => ['font_size' => '1', 'color' => 'muted', 'line_length' => 'var(--tl-text-width)']],
    ];

    /** @return array<string, array{nazev:string, popis:string, stavba:callable(): array}> */
    private static function sections(): array
    {
        $n = Build::fresh(...);
        $s = fn (array $p, array $style): array => ['style' => $style] + $p;       // an element with its own style
        $t = fn (array $p, string ...$classes): array => ['classes' => $classes] + $p; // an element with classes
        $z = fn (array $p, string $htmlTag): array => ['tag' => $htmlTag] + $p;
        $url = fn (string $page): string => '/' . slugify(t($page)); // the installer creates the pages under a translated name
        $buttonRow = fn (array ...$buttons): array => $s($n('container', [], $buttons), ['base' => ['display' => 'flex', 'direction' => 'row', 'wrap' => 'wrap', 'gap' => 's']]);

        return [
            'hero' => ['name' => t('Hero'), 'description' => t('Large headline, subtitle and two buttons.'), 'build' => fn (): array => $s($n('section', [], [
                $s($z($n('heading', ['text' => t('We help businesses grow – quickly and hassle-free')]), 'h1'), ['base' => ['font_size' => '5', 'line_length' => '20ch']]),
                $t($n('text', ['html' => '<p>' . t('In one or two sentences, say what you do, who it is for and why you.') . '</p>']), 'subtitle'),
                $buttonRow($n('button', ['text' => t('Request a quote'), 'link' => $url('Contact')]), $n('button', ['text' => t('Our services'), 'link' => $url('Services'), 'variant' => 'outline'])),
            ]), ['base' => ['padding_y' => '3xl'], 'mobile' => ['padding_y' => '2xl']])],

            'hero-image' => ['name' => t('Hero with image'), 'description' => t('Text and buttons on the left, image on the right; stacked on phones.'), 'build' => fn (): array => $s($n('section', [], [
                $s($n('grid', [], [
                    $n('container', [], [
                        $s($z($n('heading', ['text' => t('Craftsmanship you can rely on')]), 'h1'), ['base' => ['font_size' => '4']]),
                        $t($n('text', ['html' => '<p>' . t('Describe the main benefit for your customer. Briefly, specifically and in their words.') . '</p>']), 'subtitle'),
                        $buttonRow($n('button', ['text' => t('Contact us'), 'link' => $url('Contact')])),
                    ]),
                    $s($n('image', ['alt' => '', 'priority' => true]), ['base' => ['width' => '100%', 'radius' => 'l', 'aspect_ratio' => '4/3', 'object_fit' => 'cover']]),
                ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => '2xl', 'align_items' => 'center'], 'tablet' => ['columns' => '1', 'gap' => 'xl']]),
            ]), ['base' => ['padding_y' => '2xl']])],

            'benefits' => ['name' => t('Benefits'), 'description' => t('A heading and three cards with the main reasons to choose you.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Why choose us')]), 'section-heading'),
                $n('grid', [], array_map(fn (array $d): array => $t($n('container', [], [$z($n('heading', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]), 'card'), [
                    [t('Experience'), t('In fifteen years we have completed hundreds of projects across the country.')],
                    [t('Fair pricing'), t('You know the price upfront and pay only for work that is actually done.')],
                    [t('Speed'), t('We reply to every enquiry within one business day.')],
                ])),
            ])],

            'services' => ['name' => t('Services with images'), 'description' => t('Service cards with an image, description and link.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('What we do for you')]), 'section-heading'),
                $n('grid', [], array_map(fn (string $name): array => $t($n('container', [], [
                    $s($n('image', ['alt' => $name]), ['base' => ['width' => '100%', 'aspect_ratio' => '3/2', 'object_fit' => 'cover', 'radius' => 's']]),
                    $z($n('heading', ['text' => $name]), 'h3'),
                    $n('text', ['html' => '<p>' . t('A short description of the service and who it is for.') . '</p>']),
                    $n('button', ['text' => t('More information'), 'variant' => 'link', 'link' => $url('Services')]),
                ]), 'card'), [t('Design'), t('Delivery'), t('Support')])),
            ])],

            'numbers' => ['name' => t('Numbers'), 'description' => t('A band with four bold numbers in the primary colour.'), 'build' => fn (): array => $s($n('section', [], [
                $s($n('grid', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($z($n('heading', ['text' => $d[0]]), 'p'), ['base' => ['font_size' => '4', 'font_weight' => '800']]),
                    $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => '2xs', 'text_align' => 'center']]), [['15+', t('years in business')], [t('1,200'), t('completed projects')], [t('98%'), t('satisfied customers')], ['24 h', t('response time')]])),
                    ['base' => ['display' => 'grid', 'columns' => '4', 'gap' => 'l'], 'tablet' => ['columns' => '2']]),
            ]), ['base' => ['padding_y' => 'xl', 'background' => 'primary', 'color' => 'on-primary']])],

            'testimonials' => ['name' => t('Testimonials'), 'description' => t('What customers say about you – quotes with names.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('What our customers say')]), 'section-heading'),
                $s($n('grid', [], [
                    $t($n('testimonial', ['text' => t('Everything went exactly as agreed, on time and on budget. We will gladly come back.'), 'author' => t('David Clarke'), 'position' => t('Managing Director, Clarke Ltd')]), 'card'),
                    $t($n('testimonial', ['text' => t('We appreciate the fast communication and that they always recommended the best solution.'), 'author' => t('Emma Johnson'), 'position' => t('Operations Director')]), 'card'),
                ]), ['base' => ['display' => 'grid', 'columns' => 'auto:20rem', 'gap' => 'l']]),
            ])],

            'faq' => ['name' => t('Questions and answers'), 'description' => t('Frequently asked questions – also as structured data for search engines.'), 'build' => fn (): array => $n('section', ['width' => 'narrow'], [
                $n('heading', ['text' => t('Frequently asked questions')]),
                $n('faq'),
            ])],

            'call-to-action' => ['name' => t('Call to action'), 'description' => t('A coloured box with a heading, a sentence and a button.'), 'build' => fn (): array => $n('section', [], [
                $s($n('container', [], [
                    $z($n('heading', ['text' => t('Have a project? Let\'s talk.')]), 'h2'),
                    $n('text', ['html' => '<p>' . t('Get in touch – within 24 hours we will come back with a proposal for next steps.') . '</p>']),
                    $n('button', ['text' => t('Write to us'), 'link' => $url('Contact'), 'variant' => 'secondary']),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'align_items' => 'center', 'gap' => 's', 'text_align' => 'center',
                    'padding_y' => '2xl', 'padding_x' => 'l', 'background' => 'primary', 'color' => 'on-primary', 'radius' => 'l']]),
            ])],

            'news' => ['name' => t('Latest news'), 'description' => t('The three latest news items and a link to all of them.'), 'build' => fn (): array => $n('section', [], [
                $s($n('container', [], [$z($n('heading', ['text' => t('News')]), 'h2'), $n('button', ['text' => t('All news'), 'link' => '/' . \Talea\Core\Routes::publicPath('news', null), 'variant' => 'link'])]),
                    ['base' => ['display' => 'flex', 'direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'baseline', 'wrap' => 'wrap', 'gap' => 's']]),
                $n('news_list'),
            ])],

            'enquiry' => ['name' => t('Enquiry form'), 'description' => t('A heading, a sentence and a form – messages arrive in Enquiries and by email.'), 'build' => fn (): array => $n('section', ['width' => 'narrow'], [
                $z($n('heading', ['text' => t('Write to us')]), 'h2'),
                $t($n('text', ['html' => '<p>' . t('Tell us what you need – we will get back to you within one business day.') . '</p>']), 'subtitle'),
                $s($n('form'), ['base' => ['margin_top' => 'm']]),
            ])],

            'contact' => ['name' => t('Contact'), 'description' => t('Address and contacts on the left, opening hours on the right.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], [
                    // details from "Settings → Business details": filled in once, they also apply to the footer and search engines
                    $s($n('container', [], [
                        $z($n('heading', ['text' => t('Contact details')]), 'h2'),
                        $n('company_details', ['detail' => 'company']),
                        $z($n('company_details', ['detail' => 'address']), 'address'),
                        $n('company_details', ['detail' => 'phone']),
                        $n('company_details', ['detail' => 'email']),
                        $n('company_details', ['detail' => 'map']),
                    ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'xs']]),
                    $t($n('container', [], [
                        $z($n('heading', ['text' => t('Opening hours')]), 'h3'),
                        $n('company_details', ['detail' => 'hours']),
                    ]), 'card'),
                ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => 'xl'], 'tablet' => ['columns' => '1']]),
            ])],

            /* ---------- intro ---------- */

            'hero-centered' => ['name' => t('Centred hero'), 'description' => t('A short label, a big headline and a button – all centred.'), 'build' => fn (): array => $s($n('section', [], [
                $s($n('container', [], [
                    $s($z($n('heading', ['text' => t('New: service within 48 hours')]), 'p'), ['base' => ['font_size' => '-1', 'font_weight' => '600', 'color' => 'primary', 'text_transform' => 'uppercase', 'letter_spacing' => '0.06em']]),
                    $s($z($n('heading', ['text' => t('We take care of everything from design to handover')]), 'h1'), ['base' => ['font_size' => '5', 'line_length' => '20ch']]),
                    $t($n('text', ['html' => '<p>' . t('One company, one contact, a clear price. You focus on your work, we handle the rest.') . '</p>']), 'subtitle'),
                    $n('button', ['text' => t('Book a meeting'), 'link' => $url('Contact')]),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'align_items' => 'center', 'text_align' => 'center', 'gap' => 'm']]),
            ]), ['base' => ['padding_y' => '3xl'], 'mobile' => ['padding_y' => '2xl']])],

            'hero-dark' => ['name' => t('Hero on a dark background'), 'description' => t('A bold hero on a dark background – set a background image in the section style.'), 'build' => fn (): array => $s($n('section', [], [
                $s($z($n('heading', ['text' => t('Quality that lasts for decades')]), 'h1'), ['base' => ['font_size' => '5', 'line_length' => '20ch', 'color' => 'background']]),
                $s($n('text', ['html' => '<p>' . t('We work with honest materials and guarantee every job.') . '</p>']), ['base' => ['font_size' => '1', 'line_length' => 'var(--tl-text-width)', 'color' => 'background']]),
                $buttonRow($n('button', ['text' => t('Request a quote'), 'link' => $url('Contact')])),
            ]), ['base' => ['padding_y' => '3xl', 'background' => 'text', 'display' => 'flex', 'direction' => 'column', 'gap' => 'm'], 'mobile' => ['padding_y' => '2xl']])],

            'hero-video' => ['name' => t('Hero with video'), 'description' => t('Headline and text on the left, video on the right (YouTube or Vimeo).'), 'build' => fn (): array => $s($n('section', [], [
                $s($n('grid', [], [
                    $s($n('container', [], [
                        $s($z($n('heading', ['text' => t('See how we work')]), 'h1'), ['base' => ['font_size' => '4']]),
                        $t($n('text', ['html' => '<p>' . t('A two-minute video says more than a long text. Paste the video address in the Content panel.') . '</p>']), 'subtitle'),
                        $buttonRow($n('button', ['text' => t('Contact us'), 'link' => $url('Contact')])),
                    ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm']]),
                    $n('video', ['url' => '']),
                ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => '2xl', 'align_items' => 'center'], 'tablet' => ['columns' => '1']]),
            ]), ['base' => ['padding_y' => '2xl']])],

            'page-title' => ['name' => t('Page title'), 'description' => t('A title and an intro sentence for the top of a subpage.'), 'build' => fn (): array => $s($n('section', [], [
                $s($z($n('heading', ['text' => t('Page title')]), 'h1'), ['base' => ['font_size' => '4']]),
                $t($n('text', ['html' => '<p>' . t('In one sentence, what this page is about.') . '</p>']), 'subtitle'),
            ]), ['base' => ['padding_y' => 'xl', 'background' => 'surface', 'display' => 'flex', 'direction' => 'column', 'gap' => 's']])],

            'imprint' => ['name' => t('Imprint'), 'description' => t('Who runs the site: company, registered office, identification numbers, register entry and contact from Business details.'), 'build' => fn (): array => $s($n('section', [], [
                $s($n('container', [], [
                    $z($n('heading', ['text' => t('Website operator')]), 'h2'),
                    $n('company_details', ['detail' => 'imprint']),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'm', 'max_width' => '48rem']]),
            ]), ['base' => ['padding_y' => 'xl']])],

            /* ---------- services and content ---------- */

            'benefits-list' => ['name' => t('Benefits with a list'), 'description' => t('Text on the left, a ticked list on the right.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], [
                    $s($n('container', [], [
                        $n('heading', ['text' => t('What you get with us')]),
                        $t($n('text', ['html' => '<p>' . t('More than just finished work. We make sure working together is pleasant from the first meeting.') . '</p>']), 'subtitle'),
                    ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 's']]),
                    $n('list', ['items' => implode("\n", [t('You know the price upfront'), t('A fixed completion date'), t('A guarantee on all work'), t('We always clean up after ourselves')]), 'style' => 'checks']),
                ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => '2xl', 'align_items' => 'center'], 'tablet' => ['columns' => '1', 'gap' => 'l']]),
            ])],

            'alternating' => ['name' => t('Alternating image and text'), 'description' => t('Two rows: image with text, then the other way round.'), 'build' => fn (): array => $n('section', [], [
                $s($n('container', [], array_map(fn (array $d): array => $s($n('grid', [], [
                    $s($n('image', ['alt' => $d[0]]), ['base' => ['width' => '100%', 'aspect_ratio' => '4/3', 'object_fit' => 'cover', 'radius' => 'l'] + ($d[2] ? ['order' => '2'] : []), 'tablet' => ['order' => '0']]),
                    $s($n('container', [], [$n('heading', ['text' => $d[0]]), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 's']]),
                ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => '2xl', 'align_items' => 'center'], 'tablet' => ['columns' => '1', 'gap' => 'l']]), [
                    [t('A design made for you'), t('We measure the space, talk through your habits and prepare a design you can see before we start.'), false],
                    [t('Honest workmanship'), t('We use proven materials and check every detail. We guarantee the result.'), true],
                ])), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => '2xl']]),
            ])],

            'process' => ['name' => t('How it works'), 'description' => t('Four numbered steps of working together.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('How it works')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($z($n('heading', ['text' => $d[0]]), 'p'), ['base' => ['font_size' => '3', 'font_weight' => '800', 'color' => 'primary']]),
                    $z($n('heading', ['text' => $d[1]]), 'h3'),
                    $n('text', ['html' => '<p>' . $d[2] . '</p>']),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'xs', 'border_top' => '2px solid var(--tl-color-primary)', 'padding_y' => 's']]), [
                    ['01', t('Enquiry'), t('You tell us what you need.')], ['02', t('Design and price'), t('Within a week you get a design and a fixed price.')],
                    ['03', t('Delivery'), t('We work on the agreed dates.')], ['04', t('Handover'), t('We go through everything together and hand it over.')],
                ])), ['base' => ['display' => 'grid', 'columns' => '4', 'gap' => 'l'], 'tablet' => ['columns' => '2']]),
            ])],

            'services-list' => ['name' => t('Services with prices'), 'description' => t('A list of services with a short description and a “from” price.'), 'build' => fn (): array => $n('section', ['width' => 'narrow'], [
                $t($n('heading', ['text' => t('Services and prices')]), 'section-heading'),
                $s($n('container', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($n('container', [], [$z($n('heading', ['text' => $d[0]]), 'h3'), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'subtitle')]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => '2xs']]),
                    $s($z($n('heading', ['text' => $d[2]]), 'p'), ['base' => ['font_weight' => '700', 'font_size' => '1']]),
                ]), ['base' => ['display' => 'flex', 'direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'baseline', 'gap' => 'm', 'padding_y' => 'm', 'border_bottom' => '1px solid var(--tl-color-line)'], 'mobile' => ['direction' => 'column', 'gap' => 'xs']]), [
                    [t('Consultation'), t('An hour with an expert at your place or online.'), t('from £40')],
                    [t('Tailored design'), t('A design including visualisation and budget.'), t('from £190')],
                    [t('Delivery'), t('Complete execution according to the design.'), t('depending on scope')],
                ])), ['base' => ['display' => 'flex', 'direction' => 'column']]),
            ])],

            'pricing' => ['name' => t('Pricing – three packages'), 'description' => t('Three cards with price and features, the middle one highlighted.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Choose a package')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (array $d): array => $s($t($n('container', [], [
                    $z($n('heading', ['text' => $d[0]]), 'h3'),
                    $s($z($n('heading', ['text' => $d[1]]), 'p'), ['base' => ['font_size' => '3', 'font_weight' => '800']]),
                    $n('list', ['items' => $d[2], 'style' => 'checks']),
                    $n('button', ['text' => t('I\'m interested'), 'link' => $url('Contact'), 'variant' => $d[3] ? 'primary' : 'outline']),
                ]), 'card'), $d[3] ? ['base' => ['border' => '2px solid var(--tl-color-primary)', 'shadow' => 'm']] : []), [
                    [t('Basic'), t('£120'), t('Consultation') . "\n" . t('Solution design'), false],
                    [t('Standard'), t('£290'), t('Consultation') . "\n" . t('Solution design') . "\n" . t('Delivery') . "\n" . t('A year of free service'), true],
                    [t('Custom'), t('by agreement'), t('Everything in Standard') . "\n" . t('Your own schedule') . "\n" . t('A personal project manager'), false],
                ])), ['base' => ['display' => 'grid', 'columns' => 'auto:16rem', 'gap' => 'l', 'align_items' => 'stretch']]),
            ])],

            'signpost' => ['name' => t('Signpost'), 'description' => t('Three cards linking to subpages.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], array_map(fn (array $d): array => $t($n('container', ['link' => $d[2]], [
                    $z($n('heading', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                    $s($z($n('heading', ['text' => t('More →')]), 'p'), ['base' => ['color' => 'primary', 'font_weight' => '600']]),
                ]), 'card'), [
                    [t('Services'), t('Everything we can do for you.'), $url('Services')], [t('About us'), t('Who we are and how we work.'), $url('About us')], [t('Contact'), t('Where to find us and how to get in touch.'), $url('Contact')],
                ])), ['base' => ['display' => 'grid', 'columns' => '3', 'gap' => 'l'], 'tablet' => ['columns' => '1']]),
            ])],

            'text-columns' => ['name' => t('Text in two columns'), 'description' => t('A heading and a longer text split into two columns.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Section heading')]), 'section-heading'),
                $s($n('grid', [], [
                    $n('text', ['html' => '<p>' . t('Write the first part of the text here. Two columns read well for longer descriptions of services or processes.') . '</p>']),
                    $n('text', ['html' => '<p>' . t('And the second part here. On phones the columns stack by themselves.') . '</p>']),
                ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => 'xl'], 'tablet' => ['columns' => '1']]),
            ])],

            'gallery' => ['name' => t('Gallery'), 'description' => t('A grid of six images – work samples, premises, products.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Samples of our work')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (int $i): array => $s($n('image', ['alt' => t('Sample %s', (string) $i)]), ['base' => ['width' => '100%', 'aspect_ratio' => '1', 'object_fit' => 'cover', 'radius' => 'm']]), range(1, 6))),
                    ['base' => ['display' => 'grid', 'columns' => '3', 'gap' => 's'], 'mobile' => ['columns' => '2']]),
            ])],

            'portfolio' => ['name' => t('Delivery'), 'description' => t('Cards of finished projects with an image, name and location.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Our projects')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($n('image', ['alt' => $d[0]]), ['base' => ['width' => '100%', 'aspect_ratio' => '4/3', 'object_fit' => 'cover', 'radius' => 'm']]),
                    $z($n('heading', ['text' => $d[0]]), 'h3'),
                    $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'subtitle'),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'xs']]), [
                    [t('Family house'), t('Manchester, 2026')], [t('Office'), t('London, 2025')], [t('Flat renovation'), t('York, 2025')],
                ])), ['base' => ['display' => 'grid', 'columns' => 'auto:18rem', 'gap' => 'l']]),
            ])],

            'video' => ['name' => t('Video'), 'description' => t('A heading and a video across the content width.'), 'build' => fn (): array => $n('section', ['width' => 'narrow'], [
                $t($n('heading', ['text' => t('Video')]), 'section-heading'),
                $n('video', ['url' => '']),
            ])],

            /* ---------- trust ---------- */

            'quote' => ['name' => t('Big quote'), 'description' => t('One prominent testimonial in the centre.'), 'build' => fn (): array => $s($n('section', ['width' => 'narrow'], [
                $s($n('testimonial', ['text' => t('The best company we have ever worked with. They kept every deadline and the result exceeded our expectations.'), 'author' => t('Sarah Miller'), 'position' => t('café owner')]),
                    ['base' => ['font_size' => '2', 'text_align' => 'center']]),
            ]), ['base' => ['padding_y' => '2xl', 'background' => 'primary-soft']])],

            'reviews' => ['name' => t('Customer reviews'), 'description' => t('Three short reviews with stars.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Customer reviews')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (array $d): array => $t($n('container', [], [
                    $s($z($n('heading', ['text' => '★★★★★']), 'p'), ['base' => ['color' => 'primary', 'letter_spacing' => '0.12em']]),
                    $n('text', ['html' => '<p>' . $d[0] . '</p>']),
                    $s($z($n('heading', ['text' => $d[1]]), 'p'), ['base' => ['font_weight' => '600', 'font_size' => '-1']]),
                ]), 'card'), [
                    [t('Fast, clean and for the agreed price.'), t('Tom K.')], [t('Helpful people, great communication.'), t('Jane P.')], [t('I recommend them to all my friends.'), t('Paul S.')],
                ])), ['base' => ['display' => 'grid', 'columns' => '3', 'gap' => 'l'], 'tablet' => ['columns' => '1']]),
            ])],

            'logos' => ['name' => t('Client logos'), 'description' => t('A row of logos of companies you work with.'), 'build' => fn (): array => $n('section', [], [
                $s($t($n('heading', ['text' => t('We work with')]), 'subtitle'), ['base' => ['text_align' => 'center', 'margin_bottom' => 'l']]),
                $s($n('grid', [], array_map(fn (int $i): array => $s($n('image', ['alt' => t('Client logo %s', (string) $i)]), ['base' => ['width' => '100%', 'height' => '3rem', 'object_fit' => 'contain', 'opacity' => '0.8']]), range(1, 6))),
                    ['base' => ['display' => 'grid', 'columns' => '6', 'gap' => 'l', 'align_items' => 'center'], 'tablet' => ['columns' => '3']]),
            ])],

            'numbers-light' => ['name' => t('Numbers on a light background'), 'description' => t('Three big numbers with labels, without a coloured band.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($z($n('heading', ['text' => $d[0]]), 'p'), ['base' => ['font_size' => '5', 'font_weight' => '800', 'color' => 'primary']]),
                    $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'subtitle'),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => '2xs']]), [
                    ['20+', t('years of experience')], ['500+', t('satisfied customers')], [t('5 years'), t('guarantee on work')],
                ])), ['base' => ['display' => 'grid', 'columns' => '3', 'gap' => 'l'], 'mobile' => ['columns' => '1']]),
            ])],

            'guarantees' => ['name' => t('Guarantees'), 'description' => t('A strip with four short promises – delivery, guarantee, deadline, payment.'), 'build' => fn (): array => $s($n('section', [], [
                $s($n('grid', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($z($n('heading', ['text' => $d[0]]), 'p'), ['base' => ['font_size' => '1', 'font_weight' => '700']]), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'subtitle'),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => '2xs']]), [
                    [t('Free delivery'), t('Within 50 km.')], [t('5-year guarantee'), t('On all work.')], [t('Fixed deadline'), t('Or a discount for every day late.')], [t('Pay after handover'), t('No large deposits.')],
                ])), ['base' => ['display' => 'grid', 'columns' => '4', 'gap' => 'l'], 'tablet' => ['columns' => '2']]),
            ]), ['base' => ['padding_y' => 'l', 'background' => 'surface']])],

            /* ---------- about the company ---------- */

            'story' => ['name' => t('Our story'), 'description' => t('An image and text about who you are and how you started.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], [
                    $s($n('image', ['alt' => t('Our team')]), ['base' => ['width' => '100%', 'aspect_ratio' => '3/4', 'object_fit' => 'cover', 'radius' => 'l'], 'tablet' => ['aspect_ratio' => '4/3']]),
                    $s($n('container', [], [
                        $n('heading', ['text' => t('We started in a small workshop')]),
                        $n('text', ['html' => '<p>' . t('Describe how the company started and what drives you. People buy from people – a story helps them decide.') . '</p><p>' . t('Add what makes you different and what you are proud of.') . '</p>']),
                    ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 's']]),
                ]), ['base' => ['display' => 'grid', 'columns' => '2fr 3fr', 'gap' => '2xl', 'align_items' => 'center'], 'tablet' => ['columns' => '1', 'gap' => 'l']]),
            ])],

            'team' => ['name' => t('Team'), 'description' => t('Cards of people with a photo, name and role. For a larger team use a collection.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Who works for you')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($n('image', ['alt' => $d[0]]), ['base' => ['width' => '100%', 'aspect_ratio' => '1', 'object_fit' => 'cover', 'radius' => 'full']]),
                    $z($n('heading', ['text' => $d[0]]), 'h3'), $t($n('text', ['html' => '<p>' . $d[1] . '</p>']), 'subtitle'),
                ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'xs', 'text_align' => 'center', 'align_items' => 'center']]), [
                    [t('John Smith'), t('owner')], [t('Emily Smith'), t('design and measuring')], [t('Peter Brown'), t('project lead')],
                ])), ['base' => ['display' => 'grid', 'columns' => 'auto:12rem', 'gap' => 'l']]),
            ])],

            'values' => ['name' => t('Our values'), 'description' => t('Three or four principles you work by.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('What we stand for')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (array $d): array => $s($n('container', [], [$z($n('heading', ['text' => $d[0]]), 'h3'), $n('text', ['html' => '<p>' . $d[1] . '</p>'])]),
                    ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'xs', 'border_top' => '1px solid var(--tl-color-line)', 'padding_y' => 's']]), [
                    [t('Honesty'), t('We say what we will do, and we do what we say.')], [t('Craftsmanship'), t('We make every detail as if it were for ourselves.')], [t('Consideration'), t('For customers, neighbours and nature.')],
                ])), ['base' => ['display' => 'grid', 'columns' => '3', 'gap' => 'l'], 'tablet' => ['columns' => '1']]),
            ])],

            'history' => ['name' => t('History'), 'description' => t('Company milestones with a year and a short description.'), 'build' => fn (): array => $n('section', ['width' => 'narrow'], [
                $t($n('heading', ['text' => t('Our journey')]), 'section-heading'),
                $s($n('container', [], array_map(fn (array $d): array => $s($n('container', [], [
                    $s($z($n('heading', ['text' => $d[0]]), 'p'), ['base' => ['font_weight' => '800', 'color' => 'primary', 'font_size' => '1']]),
                    $n('text', ['html' => '<p>' . $d[1] . '</p>']),
                ]), ['base' => ['display' => 'grid', 'columns' => '6rem 1fr', 'gap' => 'm', 'padding_y' => 's', 'border_bottom' => '1px solid var(--tl-color-line)'], 'mobile' => ['columns' => '1', 'gap' => '2xs']]), [
                    ['2005', t('The company is founded in a family garage.')], ['2012', t('A new workshop and the first employees.')], ['2020', t('The five-hundredth finished project.')], ['2026', t('Opening of the showroom.')],
                ])), ['base' => ['display' => 'flex', 'direction' => 'column']]),
            ])],

            'careers' => ['name' => t('Careers'), 'description' => t('An invitation to join the team with open positions and a link.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], [
                    $s($n('container', [], [
                        $n('heading', ['text' => t('Join us')]),
                        $t($n('text', ['html' => '<p>' . t('We are looking for skilled people who enjoy work done well.') . '</p>']), 'subtitle'),
                    ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 's']]),
                    $t($n('container', [], [
                        $z($n('heading', ['text' => t('Open positions')]), 'h3'),
                        $n('list', ['items' => t('Joiner') . "\n" . t('Installer') . "\n" . t('Sales representative'), 'style' => 'checks']),
                        $n('button', ['text' => t('Send your CV'), 'link' => $url('Contact')]),
                    ]), 'card'),
                ]), ['base' => ['display' => 'grid', 'columns' => '2', 'gap' => '2xl', 'align_items' => 'center'], 'tablet' => ['columns' => '1', 'gap' => 'l']]),
            ])],

            'branches' => ['name' => t('Branches'), 'description' => t('Branch cards with address, phone and opening hours.'), 'build' => fn (): array => $n('section', [], [
                $t($n('heading', ['text' => t('Where to find us')]), 'section-heading'),
                $s($n('grid', [], array_map(fn (array $d): array => $t($n('container', [], [
                    $z($n('heading', ['text' => $d[0]]), 'h3'),
                    $n('text', ['html' => '<p>' . $d[1] . '<br>' . $d[2] . '</p><p>' . $d[3] . '</p>']),
                ]), 'card'), [
                    [t('London'), t('12 High Street'), t('London EC2A 4NE'), t('Mon–Fri 8–17')], [t('Manchester'), t('5 King Street'), t('Manchester M2 5DB'), t('Mon–Fri 9–17')], [t('Leeds'), t('20 Station Road'), t('Leeds LS1 4DY'), t('Mon–Thu 8–16')],
                ])), ['base' => ['display' => 'grid', 'columns' => 'auto:16rem', 'gap' => 'l']]),
            ])],

            /* ---------- contact and calls to action ---------- */

            'cta-bar' => ['name' => t('Call-to-action strip'), 'description' => t('A narrow strip: a sentence on the left, a button on the right.'), 'build' => fn (): array => $s($n('section', [], [
                $s($n('container', [], [
                    $s($z($n('heading', ['text' => t('Need advice? Give us a call.')]), 'p'), ['base' => ['font_size' => '2', 'font_weight' => '700']]),
                    $n('button', ['text' => t('Contact us'), 'link' => $url('Contact'), 'variant' => 'secondary']),
                ]), ['base' => ['display' => 'flex', 'direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'center', 'gap' => 'm', 'wrap' => 'wrap']]),
            ]), ['base' => ['padding_y' => 'l', 'background' => 'primary', 'color' => 'on-primary']])],

            'contact-form' => ['name' => t('Contact with form'), 'description' => t('Company details from Settings on the left, an enquiry form on the right.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], [
                    $s($n('container', [], [
                        $z($n('heading', ['text' => t('Contact details')]), 'h2'),
                        $n('company_details', ['detail' => 'company']), $z($n('company_details', ['detail' => 'address']), 'address'),
                        $n('company_details', ['detail' => 'phone']), $n('company_details', ['detail' => 'email']), $n('company_details', ['detail' => 'hours']), $n('company_details', ['detail' => 'map']),
                    ]), ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 'xs']]),
                    $t($n('container', [], [$z($n('heading', ['text' => t('Write to us')]), 'h2'), $n('form')]), 'card'),
                ]), ['base' => ['display' => 'grid', 'columns' => '2fr 3fr', 'gap' => '2xl'], 'tablet' => ['columns' => '1', 'gap' => 'l']]),
            ])],

            'faq-columns' => ['name' => t('Questions in two columns'), 'description' => t('Heading on the left, questions and answers on the right.'), 'build' => fn (): array => $n('section', [], [
                $s($n('grid', [], [
                    $s($n('container', [], [$n('heading', ['text' => t('Frequently asked questions')]), $t($n('text', ['html' => '<p>' . t('Didn\'t find an answer? Write to us.') . '</p>']), 'subtitle')]),
                        ['base' => ['display' => 'flex', 'direction' => 'column', 'gap' => 's']]),
                    $n('faq'),
                ]), ['base' => ['display' => 'grid', 'columns' => '1fr 2fr', 'gap' => '2xl'], 'tablet' => ['columns' => '1', 'gap' => 'l']]),
            ])],
        ];
    }

    /**
     * Starter sites for the installation: an appearance preset (DesignSystem::PRESETS) and sections for the pages Home, About us, Services, Contact.
     * A page without sections stays a text page. The “page-title” section gets the page title.
     */
    public const array SITES = [
        'business' => ['name' => 'Business website', 'description' => 'A versatile services website: benefits, numbers, testimonials, news.', 'preset' => 'business', 'pages' => [
            ['hero', 'benefits', 'numbers', 'testimonials', 'news', 'call-to-action'], [], ['page-title', 'services', 'faq', 'call-to-action'], ['page-title', 'contact', 'enquiry'],
        ]],
        'crafts' => ['name' => 'Crafts and services', 'description' => 'Warm colours, how you work, projects and guarantees.', 'preset' => 'crafts', 'pages' => [
            ['hero-image', 'guarantees', 'process', 'portfolio', 'testimonials', 'call-to-action'], ['page-title', 'story', 'values', 'team'],
            ['page-title', 'alternating', 'services-list', 'faq', 'cta-bar'], ['page-title', 'contact-form'],
        ]],
        'consulting' => ['name' => 'Consulting and agency', 'description' => 'An elegant look, clients, service packages and the team.', 'preset' => 'elegant', 'pages' => [
            ['hero-centered', 'logos', 'benefits-list', 'numbers-light', 'quote', 'call-to-action'], ['page-title', 'story', 'team', 'history', 'careers'],
            ['page-title', 'services', 'pricing', 'faq-columns'], ['page-title', 'contact-form', 'branches'],
        ]],
    ];

    /**
     * Templates of a new page ("New page → Start from a template"): key => [name, sections].
     * The privacy policy has its own text.
     */
    public const array PAGE_TEMPLATES = [
        'about-us' => ['About us', ['page-title', 'story', 'values', 'team']],
        'services' => ['Services', ['page-title', 'services', 'process', 'faq', 'call-to-action']],
        'landing' => ['Sales page (landing page)', ['hero', 'benefits', 'testimonials', 'pricing', 'faq', 'call-to-action']],
        'references' => ['Testimonials and projects', ['page-title', 'portfolio', 'testimonials', 'call-to-action']],
        'careers' => ['Careers', ['page-title', 'careers', 'enquiry']],
        'contact' => ['Contact', ['page-title', 'contact-form']],
        'privacy-policy' => ['Privacy policy', []],
        'imprint' => ['Imprint (legal notice)', ['page-title', 'imprint']],
    ];

    /**
     * Skeleton of a privacy policy (HTML in the current site language). Since 1.9 it follows the site: sections only for
     * the features that are switched on (enquiries, newsletter, statistics, analytics and marketing codes, the consent
     * record, maps, a CRM webhook), and the company details, periods and services already filled in. What the site does
     * not know stays in square brackets. It is a template with a disclaimer at the top, never legal advice.
     */
    public static function privacyPolicyText(?\Talea\Core\Settings $s = null): string
    {
        $o = fn (string $heading, string ...$texts): string => '<h2>' . e(t($heading)) . '</h2>' . implode('', array_map(fn (string $x): string => '<p>' . $x . '</p>', array_filter($texts)));
        $x = fn (string $text, mixed ...$args): string => e(t($text, ...$args));
        $on = fn (string $extension): bool => $s === null ? $extension === 'enquiries' : \Talea\Core\Extensions::isEnabled($s, $extension);
        $get = fn (string $key): string => $s === null ? '' : trim($s->get($key));
        $address = trim($get('company_street') . ', ' . trim($get('company_postcode') . ' ' . $get('company_city')), ', ');

        $html = '<p><em>' . $x('This text is a starting template generated from the features switched on on the website, not legal advice. Check it against how you really process data and against the rules of your country before you publish it.') . '</em></p>'
            . '<p>' . $x('This policy explains how [COMPANY NAME], company ID [ID], registered at [ADDRESS], processes the personal data you entrust to us.') . '</p>';
        if ($on('enquiries')) {
            $months = (int) ($s?->int('enquiries_months') ?? 0);
            $html .= $o('What data we process', $x('Your name, e-mail, phone and the content of the message you fill in the enquiry form.'))
                . $o('Why and on what basis', $x('So that we can reply and prepare an offer – these are steps prior to entering into a contract and our legitimate interest in answering your enquiry.'))
                . $o('How long', $months > 0 ? str_replace(t('[NUMBER]'), (string) $months, $x('We delete enquiries automatically after [NUMBER] months unless they lead to a contract.')) : $x('We delete enquiries automatically after [NUMBER] months unless they lead to a contract.'));
        }
        if ($on('newsletter_signup')) {
            // a named mailing service is a processor; a generic webhook is described by the administrator
            $service = (\Talea\Core\Newsletter::SERVICES[$get('newsletter_service')][1] ?? false) ? \Talea\Core\Newsletter::SERVICES[$get('newsletter_service')][0] : '';
            $html .= $o('Newsletter', $x('If you subscribe to our newsletter, we keep your e-mail address and the time of your consent. You confirm the subscription in an e-mail (double opt-in) and can unsubscribe with one click in every newsletter; after that we delete the address.'),
                $service !== '' ? $x('We send the newsletter through %s, who processes the addresses for us.', $service) : '');
        }
        if ($on('members')) {
            $html .= $o('Member accounts', $x('If you sign up or are invited as a member, we keep your e-mail address, your name if you give it, the groups you belong to and the time of your last sign-in. A cookie keeps you signed in on your device for up to 30 days; it is strictly necessary for the sign-in. We delete your account when you ask us to.'));
        }
        $webhook = (string) parse_url($get('webhook_enquiries'), PHP_URL_HOST);
        $html .= $o('Who has access to the data', $x('Only us and our hosting provider [HOSTING NAME], who runs the website for us.'),
            $on('enquiries') && $webhook !== '' ? $x('We pass enquiries to %s, where we handle them further.', $webhook) : '');
        $analytics = [];
        if ($on('stats')) {
            $analytics[] = $x('The website measures traffic without cookies. It uses third-party cookies only with your consent.');
        }
        if ($get('ga4_id') !== '') {
            $analytics[] = $x('With your consent, the website uses Google Analytics (Google Ireland Limited) to measure traffic; it stores cookies in your browser.');
        }
        if ($get('gtm_id') !== '') {
            $analytics[] = $x('With your consent, the website uses Google Tag Manager (Google Ireland Limited) to run analytics and advertising tags; they store cookies in your browser.');
        }
        if ($s !== null && \Talea\Core\Captcha::provider($s) !== null) {
            $analytics[] = $x('To protect the forms against spam, the website uses %s, which receives your IP address and details about your browser when you send a form.', \Talea\Core\Captcha::PROVIDERS[$get('captcha_provider')][0]);
        }
        if ($get('matomo_url') !== '') {
            $analytics[] = $x('The website measures traffic with Matomo, run at %s.', (string) parse_url($get('matomo_url'), PHP_URL_HOST));
        }
        if ($get('plausible_domain') !== '') {
            $analytics[] = $x('The website measures traffic with Plausible Analytics without cookies and without personal data.');
        }
        if ($get('marketing_code') !== '') {
            $analytics[] = $x('With your consent, the website loads marketing codes (for example advertising pixels) that store cookies.');
        }
        if ($s !== null && $s->bool('cookies_log') && $s->get('cookies_mode') === 'builtin') {
            $analytics[] = $x('We keep a record of the consent you give in the cookie bar for %d months, without your name or IP address.', max(1, $s->int('cookies_log_months')));
        }
        if ($get('company_map') !== '') {
            $analytics[] = $x('Maps load from the map provider only after you click them.');
        }
        if ($analytics !== []) {
            $html .= $o('Cookies and analytics', ...$analytics);
        }
        $html .= $o('Your rights', $x('You have the right to access, rectify and erase your data, to restrict processing and to object. You can lodge a complaint with the data protection authority.'))
            . $o('Contact', $x('Write to us at [E-MAIL] or call [PHONE].'));

        // what the site already knows goes in; the rest stays in brackets for the administrator
        $email = $get('company_email'); // the public company e-mail – the site e-mail is never published

        // the placeholders are translated with the text ([COMPANY NAME] in Czech), so they are looked up the same way
        $known = ['[COMPANY NAME]' => $get('company_name'), '[ID]' => $get('company_id'), '[ADDRESS]' => $address, '[E-MAIL]' => $email, '[PHONE]' => $get('company_phone')];
        $fill = [];
        foreach ($known as $placeholder => $value) {
            if ($value !== '') {
                $fill[e(t($placeholder))] = e($value);
            }
        }

        return strtr($html, $fill);
    }

    /** Replacement of a section with an element of a disabled extension: contact without a form has at least the company details and opening hours. */
    private const array REPLACEMENTS = ['contact-form' => 'contact'];

    /**
     * Build of a starter site page from sections (in the installation language); the used classes are created.
     *
     * @param list<string> $section
     * @param list<string> $withoutTypes sections with these elements are left out or replaced (disabled extensions)
     * @param bool $withoutImages leave out empty images (the starter site from the installation has no photos, they would leave an empty space on the site)
     */
    public static function page(\Talea\Core\Db $db, array $section, string $title, string $language, array $withoutTypes = [], bool $withoutImages = false): array
    {
        [$build, $classes] = self::assemble($section, $title, $language, $withoutTypes, $withoutImages);
        self::createClasses($db, $classes);

        return $build;
    }

    /**
     * Build of a page from sections and the classes it uses (without writing to the database – see page()).
     *
     * @param list<string> $section
     * @param list<string> $withoutTypes
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function assemble(array $section, string $title, string $language, array $withoutTypes = [], bool $withoutImages = false): array
    {
        $build = ['v' => Build::VERSION, 'children' => []];
        $classes = [];
        foreach ($section as $key) {
            $s = self::section($key, $language);
            if ($s !== null && $withoutTypes !== [] && self::containsType($s['element'], $withoutTypes)) {
                $s = isset(self::REPLACEMENTS[$key]) && !in_array(self::REPLACEMENTS[$key], $section, true) ? self::section(self::REPLACEMENTS[$key], $language) : null;
            }
            $element = $s === null ? null : ($withoutImages ? self::withoutImages($s['element']) : $s['element']);
            if ($element === null) {
                continue;
            }
            if ($key === 'page-title') {
                $element['children'][0]['content']['text'] = e($title);
            }
            $build['children'][] = $element;
            array_push($classes, ...$s['classes']);
        }

        return [$build, array_values(array_unique($classes))];
    }

    /**
     * An element without empty images: a grid in which a single element remains (text next to an image) is replaced by that element;
     * an element in which only headings remain (client logos) is left out entirely – null.
     */
    private static function withoutImages(array $p): ?array
    {
        if ($p['type'] === 'image' && ($p['content']['src'] ?? '') === '') {
            return null;
        }
        if (!isset($p['children'])) {
            return $p;
        }
        $children = array_values(array_filter(array_map(self::withoutImages(...), $p['children'])));
        if (count($children) < count($p['children'])) {
            if ($p['type'] === 'grid' && count($children) === 1) {
                return $children[0];
            }
            if (array_filter($children, fn (array $d): bool => $d['type'] !== 'heading') === []) {
                return null;
            }
        }
        $p['children'] = $children;

        return $p;
    }

    /** Categories in the "Ready-made sections" panel (key => name). */
    public const array CATEGORIES = ['intro' => 'Home', 'content' => 'Services and content', 'trust' => 'Trust', 'company' => 'About the company', 'action' => 'Contact and calls to action'];

    /** Section categories (the others are “content”). */
    private const array SECTION_CATEGORIES = [
        'hero' => 'intro', 'hero-image' => 'intro', 'hero-centered' => 'intro', 'hero-dark' => 'intro', 'hero-video' => 'intro', 'page-title' => 'intro', 'imprint' => 'company',
        'testimonials' => 'trust', 'quote' => 'trust', 'reviews' => 'trust', 'logos' => 'trust', 'numbers' => 'trust', 'numbers-light' => 'trust', 'guarantees' => 'trust',
        'story' => 'company', 'team' => 'company', 'values' => 'company', 'history' => 'company', 'careers' => 'company', 'branches' => 'company',
        'call-to-action' => 'action', 'cta-bar' => 'action', 'enquiry' => 'action', 'contact' => 'action', 'contact-form' => 'action', 'faq' => 'action', 'faq-columns' => 'action',
    ];

    /**
     * @param list<string>|null $extensions enabled extensions (null = all) – sections with elements of disabled ones (news, form) are not offered
     * @return list<array{key: string, name:string, description:string, category:string}>
     */
    public static function listAll(?array $extensions = null): array
    {
        $disabled = Build::disabledTypes($extensions);
        $section = array_filter(self::sections(), fn (string $key): bool => $disabled === [] || !self::containsType(self::create($key)['element'] ?? [], $disabled), ARRAY_FILTER_USE_KEY);

        return array_map(fn (string $key, array $s): array => ['key' => $key, 'name' => $s['name'], 'description' => $s['description'], 'category' => self::SECTION_CATEGORIES[$key] ?? 'content'],
            array_keys($section), $section);
    }

    /** @param list<string> $types */
    private static function containsType(array $element, array $types): bool
    {
        if (in_array($element['type'] ?? '', $types, true)) {
            return true;
        }
        foreach ($element['children'] ?? [] as $d) {
            if (self::containsType($d, $types)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A new copy of a section (new ids) and the names of the classes it uses. The sample texts are in the language of the page the
     * section goes to (not in the admin language) – translations in the site dictionary system/languages/<code>.php.
     *
     * @return array{prvek: array<string, mixed>, tridy: list<string>}|null
     */
    public static function section(string $key, string $language = 'en'): ?array
    {
        return \Talea\Core\Language::runWith($language, fn (): ?array => self::create($key));
    }

    /** @return array{prvek: array<string, mixed>, tridy: list<string>}|null */
    private static function create(string $key): ?array
    {
        $section = self::sections()[$key] ?? null;
        if ($section === null) {
            return null;
        }
        [$build] = Build::sanitize(['v' => 1, 'children' => [($section['build'])()]]);
        $element = $build['children'][0];
        $element['label'] = $section['name'];
        $classes = [];
        $walk = function (array $p) use (&$walk, &$classes): void {
            foreach ($p['classes'] ?? [] as $t) {
                $classes[$t] = true;
            }
            foreach ($p['children'] ?? [] as $d) {
                $walk($d);
            }
        };
        $walk($element);

        return ['element' => $element, 'classes' => array_keys($classes)];
    }

    /** A copy of an element with new ids throughout (a saved section inserted again must not repeat the ids in the build). */
    public static function withNewIds(array $element): array
    {
        $element['id'] = Build::newId();
        if (is_array($element['children'] ?? null)) {
            $element['children'] = array_map(fn (mixed $d): mixed => is_array($d) ? self::withNewIds($d) : $d, $element['children']);
        }

        return $element;
    }

    /** Creates the missing library classes (never overwrites an existing class of the site). */
    public static function createClasses(\Talea\Core\Db $db, array $names): void
    {
        foreach ($names as $name) {
            if (isset(self::CLASSES[$name])) {
                $db->insertIgnore('classes', ['name' => $name, 'style' => (string) json_encode(self::CLASSES[$name], JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]);
            }
        }
    }
}
