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

    /** #153: the table names every cookie the code sets, by its name in the code; a scanned cookie is explained only by its exact name */
    public function testTheTableNamesEveryCookieTheCodeSets(): void
    {
        $own = array_column(\Talea\Core\Privacy::OWN, 0);
        $set = [];
        $files = [...glob(TALEA_ROOT . '/image/*.js') ?: [], ...glob(TALEA_ROOT . '/image/builder/*.js') ?: [], ...glob(TALEA_ROOT . '/extensions/*/*.php') ?: [], ...glob(TALEA_ROOT . '/extensions/*/*/*.php') ?: []];
        foreach (['system/src', 'system/views'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(TALEA_ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $files[] = (string) $file;
            }
        }
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            $class = preg_match('/^namespace ([\w\\\\]+);.*?^(?:final |abstract )?class (\w+)/ms', $code, $c) === 1 ? $c[1] . '\\' . $c[2] : '';
            // setcookie(), the session's name, Session::cookie() and document.cookie = '…' in scripts
            preg_match_all("/(?:\\bsetcookie\\(|\\bsession_name\\(|->cookie\\(|document\\.cookie\\s*=\\s*')\\s*([^,)=]+)/", $code, $m);
            foreach ($m[1] as $argument) {
                $argument = trim($argument);
                if (basename($file) === 'Session.php' && $argument === '$name') {
                    continue; // Session::cookie() itself: its callers are found by ->cookie(
                }
                if (preg_match("/^'([\\w-]+)'$|^([\\w-]+)$/", $argument, $literal) === 1) {
                    $set[] = $literal[1] !== '' ? $literal[1] : $literal[2];
                } elseif (preg_match('/^(self|\\\\?[\\w\\\\]+)::([A-Z_]+)(\s*\.)?/', $argument, $constant) === 1) {
                    $set[] = constant(($constant[1] === 'self' ? $class : ltrim($constant[1], '\\')) . '::' . $constant[2]) . (isset($constant[3]) ? '*' : '');
                } else {
                    $set[] = basename($file) . ': ' . $argument;
                }
            }
        }
        $set = array_values(array_unique($set));
        sort($set);
        $this->assertSame(['talea', 'talea_consent', 'talea_consent_id', 'tl_device', 'tl_member', 'tl_page_*', 'tl_preview', 'tl_preview_media', 'tl_remember'], $set, 'the code sets these cookies (a new one belongs in Privacy::OWN and here)');
        $this->assertSame([], array_values(array_diff($set, $own)), 'Privacy::OWN names every cookie the code sets, by the same name');
        $this->assertNotContains('PHPSESSID', $own, 'the session cookie is called talea');

        $this->assertSame([true, true, false, true, false, true, false], [
            \Talea\Core\Privacy::names('talea', 'talea'), \Talea\Core\Privacy::names('talea', 'TALEA'), \Talea\Core\Privacy::names('talea', 'talea_tracker'),
            \Talea\Core\Privacy::names('_ga_*', '_ga_ABC123'), \Talea\Core\Privacy::names('_ga_*', '_gat'), \Talea\Core\Privacy::names('tl_page_*', 'tl_page_12'), \Talea\Core\Privacy::names('_ga', '_ga_ABC123'),
        ], 'Privacy::names – a scanned cookie matches a row by its exact name; only a name ending in * is a prefix');
    }
}
