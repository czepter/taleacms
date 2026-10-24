<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PrivacyTest extends TestCase
{
    /** 2.14: EU duties as templates (Core\Privacy) */
    public function testTemplates(): void
    {
        $f17Embeds = ['{"type":"video","content":{"url":"https://www.youtube.com/watch?v=abc123","title":""}}', '<p>Text without any embed</p>', '{"type":"map","content":{"address":"Praha"}}'];
        $this->assertSame([['youtube', 'google-analytics', 'google-tag-manager', 'turnstile'], ['google-maps', 'matomo', 'facebook'], []], [
            \Talea\Core\Privacy::providersIn($f17Embeds, ['ga4_id' => 'G-ABCD1234', 'gtm_id' => 'GTM-XYZ12', 'captcha_provider' => 'turnstile']),
            \Talea\Core\Privacy::providersIn(['<iframe src="https://maps.google.com/maps?q=Praha&output=embed"></iframe>', '<script>fbq("init", "1")</script>'], ['matomo_url' => 'https://stats.example.com/']),
            \Talea\Core\Privacy::providersIn(['<p>nothing</p>'], ['ga4_id' => '', 'captcha_provider' => ''])], '2.14 Privacy::providersIn – a YouTube video in a build, Analytics and Tag Manager from the settings, the CAPTCHA from its setting; a map element alone is not Google Maps (it embeds after a click)');
        $this->assertSame(['facebook', 'pinterest'], \Talea\Core\Privacy::providersIn([], ['meta_pixel_id' => '1234567890', 'tiktok_pixel_id' => '', 'pinterest_tag_id' => '2612345678901']),
            'Privacy::providersIn – an advertising pixel id alone puts its provider in the cookie table; an empty one does not');
        $f17Rows = [];
        foreach (\Talea\Core\Privacy::providersIn($f17Embeds, []) as $f17Key) {
            foreach (\Talea\Core\Privacy::KNOWN[$f17Key][2] as $f17Cookie) {
                $f17Rows[] = $f17Cookie[0] . ':' . $f17Cookie[3];
            }
        }
        $this->assertSame([['VISITOR_INFO1_LIVE:marketing', 'YSC:marketing', 'PREF:marketing'], []], [$f17Rows,
            array_values(array_unique(array_filter(array_merge(...array_values(array_map(fn (array $p): array => array_column($p[2], 3), \Talea\Core\Privacy::KNOWN))), fn (string $c): bool => !isset(\Talea\Core\Privacy::CATEGORIES[$c]))))], '2.14 Privacy: the YouTube embed maps to its cookies with the marketing category; every known cookie has a known category');
        $this->assertSame(// check-english: allow
            [['Jméno', ''], ['Email', ''], ['Telefon', ''], ['Zpráva', '']], \Talea\Core\Privacy::anonymiseData([['Jméno', 'Jan Novák'], ['Email', 'jan@example.com'], ['Telefon', '+420 777 123 456'], ['Zpráva', 'Dobrý den, …'], ['CV', 'cv.pdf (120 kB)', '2026/10/abcdefabcdefabcdefabcdef.pdf']]), '2.14 Privacy::anonymiseData – labels stay, values go, the attachment entry is left out'); // check-english: allow
        $f17Md = \Talea\Core\Privacy::markdown([['heading' => 'Forms', 'lines' => ['Form “Contact”: fields Name (text), Email (e-mail)']]], 'Record of processing');
        $this->assertSame([true, true, true, [86400, 'cron', 'What cookies the site sets'], true, 'delete', '0', ['read', ''], ['read', '']], [str_starts_with($f17Md, '# Record of processing'), str_contains($f17Md, "## Forms\n\n- Form “Contact”: fields Name (text), Email (e-mail)"), str_contains($f17Md, t('Generated on %s from the site’s configuration. A template to review and complete – not legal advice.', date('j. n. Y'))),
            \Talea\Core\Scheduler::JOBS['cookie_scan'], isset(\Talea\Core\Scheduler::jobs()['cookie_scan']), \Talea\Core\Settings::DEFAULTS['enquiries_expiry'], \Talea\Core\Settings::DEFAULTS['accessibility_toolbar'],
            \Talea\Mcp\Catalog::TOOLS['processing_record'], \Talea\Mcp\Catalog::TOOLS['accessibility_statement']], '2.14 Privacy::markdown – a title, the template notice and the sections as lists; the scheduler job and the settings are known');
    }
}
