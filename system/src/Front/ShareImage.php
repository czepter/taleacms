<?php

declare(strict_types=1);

namespace Kaleta\Front;

use Kaleta\Builder\DesignSystem;
use Kaleta\Core\Antispam;
use Kaleta\Core\App;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;

/**
 * Share images drawn by the site (2.12): a page, a news item or a collection item without a share image gets a 1200×630
 * PNG with its title in the site's colours, the site name and the logo, so social networks show something instead of
 * nothing (Seo::head prints the address when nothing else is there).
 *
 * The address /og/<hash>.png is the HMAC of everything drawn (title, site name, colours, logo) with the site's secret
 * key: a change makes a new address, so Facebook or LinkedIn never keep a stale copy, and nobody can make the site draw
 * text of their own – the site records the brief in storage/cache/og/<hash>.json when it prints the address, and the
 * request checks the hash against that brief before drawing. The picture is drawn on first request (GD with FreeType and
 * the bundled Poppins, image/vendor/poppins) and kept next to the brief. Without GD nothing changes: no og:image, as before.
 */
final class ShareImage
{
    public const int WIDTH = 1200;
    public const int HEIGHT = 630;

    /** Part of every hash: a redesign must not be served from the pictures drawn before it. */
    private const int VERSION = 1;

    private const string FOLDER = KALETA_ROOT . '/storage/cache/og';
    private const string FONT_BOLD = KALETA_ROOT . '/image/vendor/poppins/Poppins-Bold.ttf';
    private const string FONT_REGULAR = KALETA_ROOT . '/image/vendor/poppins/Poppins-Regular.ttf';

    /** Layout: the band with the logo and the site name (like the site's header), the margin, the title's size range (GD points). */
    private const int BAND = 132;
    private const int MARGIN = 80;
    private const int TITLE_MAX = 60;
    private const int TITLE_MIN = 34;
    private const int TITLE_LINES = 3;
    private const int NAME_SIZE = 28;
    private const int LOGO_HEIGHT = 64;
    private const int LOGO_WIDTH = 320;

    /** GD with FreeType and the bundled font – without them nothing is generated. */
    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagettftext') && is_file(self::FONT_BOLD) && is_file(self::FONT_REGULAR);
    }

    public static function isOn(Settings $siteSettings): bool
    {
        return $siteSettings->bool('share_image_auto') && self::available();
    }

    /**
     * The address of the picture for a title (an empty title = the home page: its description or the site name), or null
     * when the feature is off, GD is missing or the brief cannot be stored. Giving out the address records the brief the
     * picture will be drawn from.
     */
    public static function url(App $app, string $title): ?string
    {
        if (!self::isOn($app->settings())) {
            return null;
        }
        $brief = self::brief($app->settings(), $title);
        $hash = self::hash($brief, self::key($app));
        if (!is_dir(self::FOLDER)) {
            @mkdir(self::FOLDER, 0775, true);
        }
        $file = self::FOLDER . '/' . $hash . '.json';
        if (!is_file($file) && @file_put_contents($file, self::encode($brief), LOCK_EX) === false) {
            return null;
        }

        return $app->request->origin() . $app->url('og/' . $hash . '.png');
    }

    /**
     * Everything the picture is drawn from; its hash is the address. The logo only when GD reads it (SVG is skipped) and
     * with its file time, so a replaced logo makes a new address too.
     *
     * @return array<string, mixed>
     */
    public static function brief(Settings $siteSettings, string $title): array
    {
        $colors = DesignSystem::load($siteSettings)['colors'];
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            $title = trim($siteSettings->get('site_description')) !== '' ? trim($siteSettings->get('site_description')) : $siteSettings->get('site_name');
        }
        $logo = ltrim($siteSettings->get('logo'), '/');
        $logoFile = KALETA_ROOT . '/' . $logo;
        $withLogo = $logo !== '' && preg_match('#^(media|image)/[A-Za-z0-9/_.-]+\.(png|jpe?g|webp)$#i', $logo) === 1 && !str_contains($logo, '..') && is_file($logoFile);

        return [
            'v' => self::VERSION,
            'title' => mb_substr($title, 0, 300),
            'site' => mb_substr(trim($siteSettings->get('site_name')), 0, 100),
            'colors' => [$colors['primary'], $colors['background'], $colors['text']],
            'logo' => $withLogo ? $logo : '',
            'logo_time' => $withLogo ? (int) filemtime($logoFile) : 0,
        ];
    }

    /** @param array<string, mixed> $brief */
    public static function hash(array $brief, string $key): string
    {
        return substr(hash_hmac('sha256', self::encode($brief), $key), 0, 32);
    }

    /**
     * Whether a requested hash is the one this site made for the brief – a tampered address never draws anything.
     *
     * @param array<string, mixed> $brief
     */
    public static function matches(string $hash, array $brief, string $key): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $hash) === 1 && hash_equals(self::hash($brief, $key), $hash);
    }

    /** GET /og/<hash>.png: the picture, drawn on first request; null = no such picture (404). */
    public static function serve(App $app, string $hash): ?Response
    {
        if (!self::isOn($app->settings()) || preg_match('/^[a-f0-9]{32}$/', $hash) !== 1) {
            return null;
        }
        $png = self::FOLDER . '/' . $hash . '.png';
        $body = is_file($png) ? (string) file_get_contents($png) : '';
        if ($body === '') {
            $brief = json_decode((string) @file_get_contents(self::FOLDER . '/' . $hash . '.json'), true);
            if (!is_array($brief) || !self::matches($hash, $brief, self::key($app))) {
                return null;
            }
            ob_start();
            imagepng(self::draw($brief), null, 6);
            $body = (string) ob_get_clean();
            @file_put_contents($png, $body, LOCK_EX); // next time straight from the file; a read-only folder only means drawing again
        }

        // the address changes with the content, so the picture may be kept for good
        return new Response($body, 200, ['Content-Type' => 'image/png', 'Content-Length' => (string) strlen($body), 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    /**
     * Breaks a text into at most $maxLines lines no wider than $maxWidth, starting at $maxSize and going down by $step
     * to $minSize until it fits; when even the smallest size overflows, the text is cut and the last line ends with an
     * ellipsis.
     *
     * @param callable(string, int): int $width width of a text at a size (GD measures with the font; tests pass a formula)
     * @return array{int, list<string>} the size and the lines
     */
    public static function fit(string $text, callable $width, int $maxWidth, int $maxLines, int $maxSize, int $minSize, int $step = 4): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        for ($size = $maxSize; $size >= $minSize; $size -= $step) {
            $lines = self::wrap($text, fn (string $s): int => $width($s, $size), $maxWidth);
            if (count($lines) <= $maxLines) {
                return [$size, $lines];
            }
        }
        $size = $minSize;
        $lines = array_slice(self::wrap($text, fn (string $s): int => $width($s, $size), $maxWidth), 0, $maxLines);
        $last = $lines[$maxLines - 1];
        while ($last !== '' && $width(rtrim($last) . '…', $size) > $maxWidth) {
            $last = mb_substr($last, 0, -1);
        }
        $lines[$maxLines - 1] = rtrim($last) . '…';

        return [$size, $lines];
    }

    /**
     * Greedy word wrap; a single word wider than a line is broken by characters.
     *
     * @param callable(string): int $width
     * @return list<string>
     */
    public static function wrap(string $text, callable $width, int $maxWidth): array
    {
        $lines = [];
        $line = '';
        foreach (explode(' ', $text) as $word) {
            if ($word === '') {
                continue;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($width($candidate) <= $maxWidth) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            while ($width($word) > $maxWidth && mb_strlen($word) > 1) {
                for ($i = mb_strlen($word) - 1; $i > 1 && $width(mb_substr($word, 0, $i)) > $maxWidth; $i--) {
                }
                $lines[] = mb_substr($word, 0, $i);
                $word = mb_substr($word, $i);
            }
            $line = $word;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    /** @param array<string, mixed> $brief */
    private static function draw(array $brief): \GdImage
    {
        [$primary, $background, $text] = $brief['colors'];
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($image, true);
        $rgb = fn (string $hex): int => (int) imagecolorallocate($image, ...array_map(hexdec(...), str_split(ltrim($hex, '#'), 2)));
        // the band at the top is the site's header: its background with the logo and the name; the title below on the primary colour
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $rgb($primary));
        imagefilledrectangle($image, 0, 0, self::WIDTH - 1, self::BAND - 1, $rgb($background));
        $x = self::MARGIN;
        $logo = $brief['logo'] !== '' ? self::logo(KALETA_ROOT . '/' . $brief['logo']) : null;
        if ($logo !== null) {
            [$w, $h] = [imagesx($logo), imagesy($logo)];
            $scale = min(self::LOGO_HEIGHT / $h, self::LOGO_WIDTH / $w);
            [$tw, $th] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];
            imagecopyresampled($image, $logo, $x, intdiv(self::BAND - $th, 2), 0, 0, $tw, $th, $w, $h);
            $x += $tw + 28;
        }
        // the site name in the text colour when it reads on the background, otherwise in black or white
        $nameColor = DesignSystem::contrast($text, $background) >= 4.5 ? $text : DesignSystem::contrastColor($background);
        if (self::WIDTH - self::MARGIN - $x > 160 && $brief['site'] !== '') {
            [$size, $lines] = self::fit($brief['site'], self::measure(self::FONT_REGULAR), self::WIDTH - self::MARGIN - $x, 1, self::NAME_SIZE, 20, 2);
            [$ascent, $descent] = self::metrics(self::FONT_REGULAR, $size);
            imagettftext($image, $size, 0, $x, intdiv(self::BAND + $ascent - $descent, 2), $rgb($nameColor), self::FONT_REGULAR, $lines[0]);
        }
        // the title: as large as fits in three lines, in the colour that reads on the primary colour (the "text on primary" token)
        [$size, $lines] = self::fit($brief['title'], self::measure(self::FONT_BOLD), self::WIDTH - 2 * self::MARGIN, self::TITLE_LINES, self::TITLE_MAX, self::TITLE_MIN);
        [$ascent, $descent] = self::metrics(self::FONT_BOLD, $size);
        $lineHeight = (int) round(($ascent + $descent) * 1.15);
        $top = self::BAND + intdiv(self::HEIGHT - self::BAND - count($lines) * $lineHeight, 2);
        $titleColor = $rgb(DesignSystem::contrastColor($primary));
        foreach ($lines as $i => $line) {
            imagettftext($image, $size, 0, self::MARGIN, $top + $i * $lineHeight + $ascent, $titleColor, self::FONT_BOLD, $line);
        }

        return $image;
    }

    /** @return callable(string, int): int the width of a text at a size in the font, as GD will draw it */
    private static function measure(string $font): callable
    {
        return function (string $text, int $size) use ($font): int {
            $box = imagettfbbox($size, 0, $font, $text);

            return $box === false ? PHP_INT_MAX : max($box[2], $box[4]) - min($box[0], $box[6]);
        };
    }

    /** @return array{int, int} ascent and descent of the font at a size (from a line with tall and deep letters) */
    private static function metrics(string $font, int $size): array
    {
        $box = imagettfbbox($size, 0, $font, 'ÁŽgjpy'); // check-english: allow (tall accented capitals for the ascent)

        return $box === false ? [$size, intdiv($size, 4)] : [-$box[7], $box[1]];
    }

    private static function logo(string $file): ?\GdImage
    {
        $image = @imagecreatefromstring((string) @file_get_contents($file));
        if (!$image instanceof \GdImage) {
            return null;
        }
        imagepalettetotruecolor($image); // a palette PNG keeps its transparency when resampled only as true colour

        return $image;
    }

    private static function key(App $app): string
    {
        return (new Antispam($app->db(), $app->settings()))->key();
    }

    /** @param array<string, mixed> $brief */
    private static function encode(array $brief): string
    {
        return (string) json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
