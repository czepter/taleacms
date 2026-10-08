<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * QR code without a third-party library: byte mode, error correction level M (handles ~15 % damage), versions 1–40.
 * Enough for an otpauth:// URL for two-factor login (typically versions 5–8). The output is a module matrix or SVG.
 *
 * Procedure per ISO/IEC 18004: data → blocks with Reed–Solomon codes → interleaving → placement into the matrix
 * around the fixed patterns → trying eight masks and picking the one with the lowest penalty → format (and version) bits.
 */
final class Qr
{
    /** Number of error correction codewords per block and number of blocks for level M, index = version (0 is padding). */
    private const array ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    private const array BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    /** Format bits of level M (00) – the mask is added. */
    private const int LEVEL_M = 0;

    /** @var list<list<bool>> dark modules [y][x] */
    private array $modules = [];
    /** @var list<list<bool>> modules of the fixed patterns (neither the mask nor the data changes them) */
    private array $fixed = [];
    private int $size;

    private function __construct(private readonly int $version)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->fixed = array_fill(0, $this->size, $row);
    }

    /**
     * QR code matrix: rows from the top, in each the modules from the left, true = dark. Without a margin (the quiet zone is up to the caller).
     *
     * @param int|null $mask 0–7 forces a mask (tests); null = chosen by penalty as in readers
     * @return list<list<bool>>
     * @throws \InvalidArgumentException when the data does not fit even into version 40
     */
    public static function matrix(string $data, ?int $mask = null): array
    {
        $version = 1;
        while (self::capacity($version) < strlen($data)) {
            if (++$version > 40) {
                throw new \InvalidArgumentException('The text is too long for a QR code.');
            }
        }
        $qr = new self($version);
        $qr->drawFixedPatterns();
        $qr->drawData($qr->codewords(self::bytes($data, $version)));

        if ($mask === null) {
            $best = PHP_INT_MAX;
            for ($m = 0; $m < 8; $m++) {
                $qr->applyMask($m);
                $qr->drawFormat($m);
                $score = $qr->penalty();
                if ($score < $best) {
                    [$best, $mask] = [$score, $m];
                }
                $qr->applyMask($m); // the mask is XOR – applying it a second time reverts it
            }
        }
        $qr->applyMask((int) $mask);
        $qr->drawFormat((int) $mask);

        return $qr->modules;
    }

    /**
     * QR code as a standalone SVG (one path, no scripts and no outgoing links) with a quiet zone of 4 modules.
     * The colors are fixed black on white – phone readers do not expect inverted contrast in dark mode.
     */
    public static function svg(string $data, string $description, int $module = 5): string
    {
        $matrix = self::matrix($data);
        $margin = 4;
        $dimension = count($matrix) + 2 * $margin;
        $path = '';
        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $path .= 'M' . ($x + $margin) . ' ' . ($y + $margin) . 'h1v1h-1z';
                }
            }
        }

        return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dimension . ' ' . $dimension . '" width="' . ($dimension * $module) . '" height="' . ($dimension * $module)
            . '" role="img" aria-label="' . htmlspecialchars($description, ENT_QUOTES) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $path . '"/></svg>';
    }

    /** How many bytes of data fit into the version at level M. */
    private static function capacity(int $version): int
    {
        $bitCount = self::dataCodewordCount($version) * 8 - 4 - ($version < 10 ? 8 : 16);

        return intdiv($bitCount, 8);
    }

    /** Number of data codewords (without error correction ones) in the version. */
    private static function dataCodewordCount(int $version): int
    {
        return intdiv(self::rawModuleCount($version), 8) - self::ECC_PER_BLOCK[$version] * self::BLOCKS[$version];
    }

    /** Modules left for data and error correction codes after subtracting all fixed patterns. */
    private static function rawModuleCount(int $version): int
    {
        $n = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $alignment = intdiv($version, 7) + 2;
            $n -= (25 * $alignment - 10) * $alignment - 55;
            if ($version >= 7) {
                $n -= 36;
            }
        }

        return $n;
    }

    /**
     * Data codewords: mode 0100 (bytes), length, data, terminator and padding 0xEC 0x11.
     *
     * @return list<int>
     */
    private static function bytes(string $data, int $version): array
    {
        $bits = '0100' . str_pad(decbin(strlen($data)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }
        $capacity = self::dataCodewordCount($version) * 8;
        $bits .= str_repeat('0', min(4, $capacity - strlen($bits)));
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
        $words = array_map('bindec', str_split($bits, 8));
        for ($padding = 0xEC; count($words) < $capacity / 8; $padding ^= 0xEC ^ 0x11) {
            $words[] = $padding;
        }

        return $words;
    }

    /**
     * Splits the data into blocks, computes the error correction words for each and interleaves the blocks (data first, then corrections).
     *
     * @param list<int> $data
     * @return list<int>
     */
    private function codewords(array $data): array
    {
        $blockCount = self::BLOCKS[$this->version];
        $eccLength = self::ECC_PER_BLOCK[$this->version];
        $rawCount = intdiv(self::rawModuleCount($this->version), 8);
        $shortBlockCount = $blockCount - $rawCount % $blockCount;
        $shortBlockLength = intdiv($rawCount, $blockCount);
        $divisor = self::generator($eccLength);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $blockCount; $i++) {
            $length = $shortBlockLength - $eccLength + ($i < $shortBlockCount ? 0 : 1);
            $block = array_slice($data, $k, $length);
            $k += $length;
            $blocks[] = [$block, self::remainder($block, $divisor)];
        }
        $result = [];
        for ($i = 0; $i < $shortBlockLength - $eccLength + 1; $i++) {
            foreach ($blocks as [$block]) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($blocks as [, $ecc]) {
                $result[] = $ecc[$i];
            }
        }

        return $result;
    }

    /** Multiplication in the field GF(2^8) with the polynomial x^8 + x^4 + x^3 + x^2 + 1. */
    private static function multiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    /**
     * Coefficients of the Reed–Solomon generator polynomial of the given degree (without the leading one).
     *
     * @return list<int>
     */
    private static function generator(int $degree): array
    {
        $g = array_fill(0, $degree - 1, 0);
        $g[] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $g[$j] = self::multiply($g[$j], $root);
                if ($j + 1 < $degree) {
                    $g[$j] ^= $g[$j + 1];
                }
            }
            $root = self::multiply($root, 0x02);
        }

        return $g;
    }

    /**
     * Error correction codewords: the remainder after dividing the data by the generator polynomial.
     *
     * @param list<int> $data
     * @param list<int> $divisor
     * @return list<int>
     */
    public static function remainder(array $data, array $divisor): array
    {
        $z = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($z);
            $z[] = 0;
            foreach ($divisor as $i => $coefficient) {
                $z[$i] ^= self::multiply($coefficient, $factor);
            }
        }

        return $z;
    }

    /** Error correction words for a data block (for tests with known vectors). @param list<int> $data @return list<int> */
    public static function correctionCodes(array $data, int $count): array
    {
        return self::remainder($data, self::generator($count));
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->fixed[$y][$x] = true;
    }

    /** Timing lines, three finder patterns, alignment patterns, format reservation and, for version 7+, version bits. */
    private function drawFixedPatterns(): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $distance = max(abs($dx), abs($dy));
                        $this->set($x, $y, $distance !== 2 && $distance !== 4);
                    }
                }
            }
        }
        $position = $this->alignmentPositions();
        $count = count($position);
        foreach ($position as $i => $x) {
            foreach ($position as $j => $y) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $count - 1) || ($i === $count - 1 && $j === 0)) {
                    continue; // the finder patterns are there
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormat(0); // only reserving the places for now, the real bits come with the mask
        if ($this->version >= 7) {
            $rest = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rest = ($rest << 1) ^ (($rest >> 11) * 0x1F25);
            }
            $bits = $this->version << 12 | $rest;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    /** @return list<int> centers of the alignment patterns (the same on both axes) */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $count = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $count * 3 + 5, $count * 4 - 4) * 2;
        $position = [6];
        for ($p = $this->size - 7; count($position) < $count; $p -= $step) {
            array_splice($position, 1, 0, [$p]);
        }

        return $position;
    }

    /** Format bits (level M + mask, BCH code, XOR 0x5412) in both places, and the dark module. */
    private function drawFormat(int $mask): void
    {
        $data = self::LEVEL_M << 3 | $mask;
        $rest = $data;
        for ($i = 0; $i < 10; $i++) {
            $rest = ($rest << 1) ^ (($rest >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rest) ^ 0x5412;
        $bit = fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $n = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $n - 15 + $i, $bit($i));
        }
        $this->set(8, $n - 8, true);
    }

    /** Codewords in two-column strips from the right, alternately up and down, outside the fixed patterns. @param list<int> $words */
    private function drawData(array $words): void
    {
        $n = $this->size;
        $i = 0;
        $bitCount = count($words) * 8;
        for ($column = $n - 1; $column >= 1; $column -= 2) {
            if ($column === 6) {
                $column = 5; // vertical timing line
            }
            for ($vertical = 0; $vertical < $n; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $column - $j;
                    $upward = (($column + 1) & 2) === 0;
                    $y = $upward ? $n - 1 - $vertical : $vertical;
                    if (!$this->fixed[$y][$x] && $i < $bitCount) {
                        $this->modules[$y][$x] = (($words[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($flip && !$this->fixed[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    /** Penalty per the standard: long runs, 2×2 blocks, patterns resembling finder patterns and imbalance of dark and light. */
    private function penalty(): int
    {
        $n = $this->size;
        $body = 0;
        $darkCount = 0;
        $pattern = [true, false, true, true, true, false, true];
        for ($axis = 0; $axis < 2; $axis++) {
            for ($a = 0; $a < $n; $a++) {
                $sequence = [];
                for ($b = 0; $b < $n; $b++) {
                    $sequence[] = $axis === 0 ? $this->modules[$a][$b] : $this->modules[$b][$a];
                }
                // runs of five or more identical modules
                $run = 1;
                for ($b = 1; $b <= $n; $b++) {
                    if ($b < $n && $sequence[$b] === $sequence[$b - 1]) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $body += 3 + $run - 5;
                    }
                    $run = 1;
                }
                // 1:1:3:1:1 with four light modules before or after (the matrix edge counts as light)
                for ($b = 0; $b + 7 <= $n; $b++) {
                    if (array_slice($sequence, $b, 7) !== $pattern) {
                        continue;
                    }
                    $lightBefore = true;
                    $lightAfter = true;
                    for ($k = 1; $k <= 4; $k++) {
                        $lightBefore = $lightBefore && !($sequence[$b - $k] ?? false);
                        $lightAfter = $lightAfter && !($sequence[$b + 6 + $k] ?? false);
                    }
                    $body += ($lightBefore ? 40 : 0) + ($lightAfter ? 40 : 0);
                }
            }
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $darkCount += $this->modules[$y][$x] ? 1 : 0;
                if ($x + 1 < $n && $y + 1 < $n && $this->modules[$y][$x] === $this->modules[$y][$x + 1]
                    && $this->modules[$y][$x] === $this->modules[$y + 1][$x] && $this->modules[$y][$x] === $this->modules[$y + 1][$x + 1]) {
                    $body += 3;
                }
            }
        }
        // every 5 % of deviation from half dark modules = 10 points
        $body += (int) (ceil(abs($darkCount * 20 - $n * $n * 10) / ($n * $n)) - 1) * 10;

        return $body;
    }
}
