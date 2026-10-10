<?php

declare(strict_types=1);

namespace Talea\Tests\Browser;

use Talea\Tests\Site\Support\Site;
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
        $modules = sys_get_temp_dir() . '/talea-playwright';
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
            $site->exec("INSERT INTO tl_booking_services (id, name, duration_min) VALUES (900, 'Browser consultation', 60)");
            $site->exec("INSERT INTO tl_booking_staff (id, name) VALUES (900, 'Browser Staff')");
            $site->exec('INSERT INTO tl_booking_staff_services VALUES (900, 900)');
            for ($day = 1; $day <= 7; $day++) {
                $site->exec("INSERT INTO tl_booking_hours (staff_id, weekday, time_from, time_to) VALUES (900, ?, '09:00', '17:00')", [$day]);
            }
            $build = ['v' => 1, 'children' => [['id' => 's1', 'type' => 'section', 'tag' => 'section',
                'content' => ['width' => 'content', 'background_video' => '', 'on_scroll' => '', 'text_at_top' => ''],
                'children' => [['id' => 'bk1', 'type' => 'booking', 'tag' => 'form', 'content' => ['service' => 0, 'staff_member' => 0, 'button_text' => 'Book', 'thank_you' => 'Thank you.', 'consent' => 'I agree.']]]]]];
            $site->exec("INSERT INTO tl_pages (slug, title, text, in_menu, build) VALUES ('booking-test', 'Booking test', '', 0, ?)", [json_encode($build)]);

            // a page with galleries in several layouts (/gallery-test): real small photos in Media, so width and height are known
            @mkdir($site->path('media/2026/05'), 0775, true);
            $photos = [['gt-a', 400, 300], ['gt-b', 300, 400], ['gt-c', 500, 300], ['gt-d', 300, 300]];
            $photoList = [];
            foreach ($photos as [$name, $w, $h]) {
                $image = imagecreatetruecolor($w, $h);
                imagejpeg($image, $site->path("media/2026/05/$name.jpg"));
                $site->exec("INSERT INTO tl_media (image_path, thumb_path, name, image_width, image_height, created_at) VALUES (?, '', ?, ?, ?, NOW())", ["media/2026/05/$name.jpg", "Photo $name", $w, $h]);
                $photoList[] = ['src' => "media/2026/05/$name.jpg", 'alt' => "Photo $name"];
            }
            $gallery = fn (string $id, string $layout): array => ['id' => $id, 'type' => 'gallery', 'tag' => 'div', 'content' => ['photos' => $photoList, 'layout' => $layout, 'label' => "Gallery $layout", 'ratio' => '4 / 3', 'caption' => '', 'folder' => '']];
            $build = ['v' => 1, 'children' => [['id' => 's2', 'type' => 'section', 'tag' => 'section',
                'content' => ['width' => 'content', 'background_video' => '', 'on_scroll' => '', 'text_at_top' => ''],
                'children' => [$gallery('gj', 'justified'), $gallery('gs', 'slideshow'), $gallery('gf', 'fullscreen')]]]];
            $site->exec("INSERT INTO tl_pages (slug, title, text, in_menu, build) VALUES ('gallery-test', 'Gallery test', '', 0, ?)", [json_encode($build)]);

            // pages for the compose scenarios (direct manipulation on the canvas): two sections, a row of three columns, a row of two with a
            // button in the first, and an image; the same build three times – for the mouse, the keyboard and touch
            $section = fn (string $id, array $children): array => ['id' => $id, 'type' => 'section', 'tag' => 'section', 'content' => ['width' => 'content', 'background_video' => '', 'on_scroll' => '', 'text_at_top' => ''], 'style' => ['base' => ['padding_y' => 'm']], 'children' => $children];
            $paragraph = fn (string $id, string $text): array => ['id' => $id, 'type' => 'text', 'tag' => 'div', 'content' => ['html' => '<p>' . $text . '</p>']];
            $column = fn (string $id, array $children): array => ['id' => $id, 'type' => 'container', 'tag' => 'div', 'content' => ['link' => ''], 'children' => $children];
            $row = fn (string $id, string $columns, array $children): array => ['id' => $id, 'type' => 'grid', 'tag' => 'div', 'content' => [], 'style' => ['base' => ['display' => 'grid', 'columns' => $columns, 'gap' => 'm']], 'children' => $children];
            $composeBuild = ['v' => 1, 'children' => [
                $section('cs1', [['id' => 'ch1', 'type' => 'heading', 'tag' => 'h1', 'content' => ['text' => 'Compose test']]]),
                $section('cs2', [$row('cg3', '3', [$column('col1', [$paragraph('txt1', 'One')]), $column('col2', [$paragraph('txt2', 'Two')]), $column('col3', [$paragraph('txt3', 'Three')])])]),
                $section('cs3', [$row('cg2', '2', [$column('cola', [$paragraph('txt4', 'Left'), ['id' => 'btn1', 'type' => 'button', 'tag' => 'a', 'content' => ['text' => 'Go', 'link' => '/contact']]]), $column('colb', [$paragraph('txt5', 'Right')])])]),
                $section('cs4', [['id' => 'cimg', 'type' => 'image', 'tag' => 'figure', 'content' => ['src' => 'media/2026/05/gt-a.jpg', 'alt' => 'A photo', 'caption' => '', 'link' => '', 'priority' => false], 'style' => ['base' => ['width' => '100%']]]]),
            ]];
            $composeIds = [];
            foreach (['mouse', 'keys', 'touch'] as $kind) {
                $site->exec("INSERT INTO tl_pages (slug, title, text, in_menu, build) VALUES (?, ?, '', 0, ?)", ["compose-$kind", "Compose $kind", json_encode($composeBuild)]);
                $composeIds[$kind] = (string) $site->value('SELECT public_id FROM tl_pages WHERE slug = ?', ["compose-$kind"]);
            }

            $project = dirname(__DIR__, 2);
            $process = proc_open(['node', $project . '/tools/test-browser.mjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project,
                array_merge(getenv(), ['BASE' => $site->base, 'PASSWORD' => $site->password, 'CHROME' => $chrome, 'NODE_PATH' => $modules . '/node_modules', 'COMPOSE_PAGES' => json_encode($composeIds)]));
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            $exit = proc_close($process);

            $this->assertSame(0, $exit, $output);
            $log = $site->path('storage/log/errors.log');
            $this->assertSame('', is_file($log) ? (string) file_get_contents($log) : '', 'application error log');
        } finally {
            $site->close();
        }
    }
}
