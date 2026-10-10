<?php

declare(strict_types=1);

/**
 * Maintainer script (run by hand, never by the site): adds one font family to the library in image/fonts/<slug>/.
 *
 *   TALEA_FONT_TOOLS=/path/to/venv/bin php tools/fonts-add.php <slug> <google-fonts-dir> [--category=sans|serif|display|mono|handwritten]
 *       [--weights=400,700] [--italic] [--name="Family Name"] [--work=/tmp/dir]
 *
 * <google-fonts-dir> is the folder name under https://github.com/google/fonts/tree/main/ofl/ (e.g. "sourcesans3").
 * It downloads the TTF files and the licence from raw.githubusercontent.com/google/fonts only (SIL OFL families), pins every
 * variable axis except weight, subsets to Latin + Latin-extended, converts to WOFF2 and writes font.json. Needs
 * `pip install fonttools brotli` (pyftsubset, fonttools varLib.instancer). See docs/FONTS.md.
 */

const RAW = 'https://raw.githubusercontent.com/google/fonts/main/ofl/';
const UNICODES = 'U+0000-00FF,U+0100-024F,U+1E00-1EFF,U+2000-206F,U+20A0-20CF,U+2100-214F,U+2190-21FF,U+2212,U+2215,U+FEFF,U+FFFD';
const CATEGORIES = ['SANS_SERIF' => 'sans', 'SERIF' => 'serif', 'DISPLAY' => 'display', 'MONOSPACE' => 'mono', 'HANDWRITING' => 'handwritten'];

/** Download one file from the google/fonts repository (the only host this script talks to). */
function fetch(string $dir, string $file): string
{
    $url = RAW . $dir . '/' . rawurlencode($file);
    $data = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60]]));
    if ($data === false || $data === '') {
        fwrite(STDERR, "download failed: $url\n");
        exit(1);
    }

    return $data;
}

function run(string $command): void
{
    exec($command . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fwrite(STDERR, "failed: $command\n" . implode("\n", $out) . "\n");
        exit(1);
    }
}

$args = array_slice($argv, 1);
$options = [];
$positional = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--')) {
        [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, '1');
        $options[$k] = $v;
    } else {
        $positional[] = $a;
    }
}
[$slug, $dir] = $positional + [null, null];
$tools = rtrim((string) getenv('TALEA_FONT_TOOLS'), '/');
if ($slug === null || $dir === null || !preg_match('/^[a-z0-9-]+$/', $slug) || !preg_match('/^[a-z0-9]+$/', $dir) || $tools === '') {
    fwrite(STDERR, "usage: TALEA_FONT_TOOLS=<dir with pyftsubset and fonttools> php tools/fonts-add.php <slug> <google-fonts-dir> [--category=…] [--weights=400,700] [--italic] [--name=…]\n");
    exit(2);
}
$work = ($options['work'] ?? sys_get_temp_dir() . '/talea-fonts') . '/' . $slug;
$target = dirname(__DIR__) . '/image/fonts/' . $slug;
@mkdir($work, 0777, true);
@mkdir($target, 0777, true);

// 1. METADATA.pb: name, category, licence, files, axes
$meta = fetch($dir, 'METADATA.pb');
if (!preg_match('/^license:\s*"OFL"/m', $meta)) {
    fwrite(STDERR, "$dir is not licensed under the OFL – not added\n");
    exit(1);
}
preg_match('/^name:\s*"([^"]+)"/m', $meta, $m);
$name = $options['name'] ?? $m[1] ?? $slug;
preg_match('/^category:\s*"([A-Z_]+)"/m', $meta, $m);
$category = $options['category'] ?? (CATEGORIES[$m[1] ?? ''] ?? 'sans');
preg_match_all('/fonts\s*\{\s*name:\s*"[^"]*"\s*style:\s*"(\w+)"\s*weight:\s*(\d+)\s*filename:\s*"([^"]+)"/', $meta, $fonts, PREG_SET_ORDER);
$wantItalic = isset($options['italic']);
$onlyWeights = isset($options['weights']) ? array_map('intval', explode(',', $options['weights'])) : null;
$weightRange = [100, 900];
if (preg_match('/axes\s*\{\s*tag:\s*"wght"\s*min_value:\s*([\d.]+)\s*max_value:\s*([\d.]+)/', $meta, $m)) {
    $weightRange = [(int) $m[1], (int) $m[2]];
}

$picked = [];
foreach ($fonts as [, $style, $weight, $file]) {
    if (($style === 'italic' && !$wantItalic) || ($onlyWeights !== null && !str_contains($file, '[') && !in_array((int) $weight, $onlyWeights, true))) {
        continue;
    }
    $picked[] = ['style' => $style, 'weight' => (int) $weight, 'file' => $file, 'variable' => str_contains($file, '[')];
}
$variable = array_filter($picked, fn (array $p): bool => $p['variable']) !== [];
if ($variable) {
    $picked = array_values(array_filter($picked, fn (array $p): bool => $p['variable']));
}
if ($picked === []) {
    fwrite(STDERR, "no usable font files in METADATA.pb\n");
    exit(1);
}

// 2. download, pin the other axes, subset, WOFF2
$out = [];
foreach ($picked as $p) {
    $ttf = $work . '/' . preg_replace('/[^A-Za-z0-9.-]/', '_', $p['file']);
    file_put_contents($ttf, fetch($dir, $p['file']));
    if ($p['variable'] && preg_match('/\[([^\]]+)\]/', $p['file'], $am)) {
        $pin = [];
        foreach (explode(',', $am[1]) as $axis) {
            if ($axis !== 'wght') {
                $pin[] = $axis . '=drop';
            }
        }
        if ($pin !== []) {
            run(escapeshellarg($tools . '/fonttools') . ' varLib.instancer ' . escapeshellarg($ttf) . ' ' . implode(' ', $pin) . ' -o ' . escapeshellarg($ttf . '.pinned.ttf'));
            $ttf .= '.pinned.ttf';
        }
    }
    $suffix = ($p['variable'] ? '' : '-' . $p['weight']) . ($p['style'] === 'italic' ? '-italic' : '');
    $name2 = $slug . $suffix . '.woff2';
    run(escapeshellarg($tools . '/pyftsubset') . ' ' . escapeshellarg($ttf) . ' --unicodes=' . UNICODES . ' --flavor=woff2 --layout-features=kern,liga,calt,ccmp,locl,mark,mkmk,case,tnum,lnum,onum,frac --no-hinting --output-file=' . escapeshellarg($target . '/' . $name2));
    $out[] = ['file' => $name2, 'weight' => $p['variable'] ? $weightRange[0] . ' ' . $weightRange[1] : $p['weight'], 'style' => $p['style']];
}

// 3. licence and font.json
file_put_contents($target . '/OFL.txt', fetch($dir, 'OFL.txt'));
$reserved = preg_match('/with Reserved Font Name\s+["“\']?([^"”\'\n.]+)/i', (string) file_get_contents($target . '/OFL.txt'), $rm) ? trim($rm[1]) : '';
$weights = $variable ? range(max(100, $weightRange[0]), min(900, $weightRange[1]), 100) : array_values(array_unique(array_column($picked, 'weight')));
sort($weights);
$info = [
    'name' => $name,
    'category' => $category,
    'weights' => $weights,
    'italic' => in_array('italic', array_column($out, 'style'), true),
    'variable' => $variable,
    'scripts' => ['latin', 'latin-ext'],
    'files' => $out,
    'source' => 'https://github.com/google/fonts/tree/main/ofl/' . $dir,
    'licence' => 'OFL-1.1',
];
if ($reserved !== '') {
    $info['reserved_name'] = $reserved;
}
file_put_contents($target . '/font.json', json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$size = array_sum(array_map(fn (array $f): int => (int) filesize($target . '/' . $f['file']), $out));
echo "$name ($category): " . count($out) . ' file(s), ' . round($size / 1024) . " KB" . ($reserved !== '' ? ", Reserved Font Name: $reserved" : '') . "\n";
