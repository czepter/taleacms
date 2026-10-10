<?php

declare(strict_types=1);

namespace Kaleta\Tests\Browser;

use Kaleta\Tests\Site\Support\Site;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A clean English install walked through in Chrome by tools/test-browser.mjs (admin, builder, editors, public site); fails on any
 * script error. Skipped without Node, Chrome (CHROME=/path) or the network for `npm i playwright-core`. Not part of the `site` suite.
 */
#[Group('browser')]
final class BrowserWalkTest extends TestCase
{
    public function testAdminBuilderAndPublicSiteRunWithoutScriptErrors(): void
    {
        $chrome = getenv('CHROME') ?: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
        if (!is_file($chrome)) {
            $this->markTestSkipped('No Chrome (set CHROME=/path/to/chrome).');
        }
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('No node.');
        }
        $modules = sys_get_temp_dir() . '/kaleta-playwright';
        if (!is_dir($modules . '/node_modules/playwright-core')) {
            @mkdir($modules, 0775, true);
            exec('npm i --silent --prefix ' . escapeshellarg($modules) . ' playwright-core@1 2>&1', $out, $code);
            if ($code !== 0) {
                $this->markTestSkipped('npm i playwright-core failed (no network?): ' . implode(' ', array_slice($out, -3)));
            }
        }

        try {
            $site = Site::boot([
                'web' => 'business', 'siteName' => 'Browser Test Ltd', 'language' => 'en', 'doneText' => 'Done, your website is running',
                'extensions' => ['news', 'enquiries', 'newsletter_signup', 'bookings', 'stats', 'redirects', 'claude'],
                'enabledExtensions' => 'news,enquiries,newsletter_signup,bookings,stats,redirects,claude',
            ]);
        } catch (\PDOException $e) {
            $this->markTestSkipped('No MySQL reachable: ' . $e->getMessage());
        }

        try {
            // a bookable service on a public page (/booking-test): the browser picks a day
            $site->exec("INSERT INTO ka_booking_services (id, name, duration_min) VALUES (900, 'Browser consultation', 60)");
            $site->exec("INSERT INTO ka_booking_staff (id, name) VALUES (900, 'Browser Staff')");
            $site->exec('INSERT INTO ka_booking_staff_services VALUES (900, 900)');
            for ($day = 1; $day <= 7; $day++) {
                $site->exec("INSERT INTO ka_booking_hours (staff_id, weekday, time_from, time_to) VALUES (900, ?, '09:00', '17:00')", [$day]);
            }
            $build = ['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'tag' => 'section',
                'content' => ['width' => 'content', 'background_video' => '', 'on_scroll' => '', 'text_at_top' => ''],
                'children' => [['id' => 'bk1', 'type' => 'booking', 'tag' => 'form', 'content' => ['service' => 0, 'staff_member' => 0, 'button_text' => 'Book', 'thank_you' => 'Thank you.', 'consent' => 'I agree.']]]]]];
            $site->exec("INSERT INTO ka_pages (slug, title, text, in_menu, build) VALUES ('booking-test', 'Booking test', '', 0, ?)", [json_encode($build)]);

            $project = dirname(__DIR__, 2);
            $process = proc_open(['node', $project . '/tools/test-browser.mjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project,
                array_merge(getenv(), ['BASE' => $site->base, 'PASSWORD' => $site->password, 'CHROME' => $chrome, 'NODE_PATH' => $modules . '/node_modules']));
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $exit = proc_close($process);

            $this->assertSame(0, $exit, $output);
            $log = $site->path('storage/log/chyby.log');
            $this->assertSame('', is_file($log) ? (string) file_get_contents($log) : '', 'application error log');
        } finally {
            $site->close();
        }
    }
}
