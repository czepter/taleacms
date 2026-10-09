<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Comparison of two versions of a text: by paragraphs, in changed paragraphs by words.
 * The output is safe HTML (<ins>, <del>) - the input HTML is converted to plain text.
 */
final class Diff
{
    /** @return array{html:string, pridano:int, smazano:int} */
    public static function html(string $oldVersion, string $newItems): array
    {
        $a = self::paragraphs($oldVersion);
        $b = self::paragraphs($newItems);
        $html = '';
        $added = $deleted = 0;
        $steps = self::steps($a, $b);
        for ($i = 0; $i < count($steps); $i++) {
            [$type, $text] = $steps[$i];
            if ($type === '=') {
                $html .= '<p>' . e($text) . '</p>';
            } elseif ($type === '-' && ($steps[$i + 1][0] ?? '') === '+') {
                // a deleted and immediately added paragraph = an edited paragraph: difference by words
                $words = self::steps(self::words($text), self::words($steps[$i + 1][1]));
                $html .= '<p>';
                foreach ($words as [$t, $s]) {
                    $html .= $t === '=' ? e($s) : ($t === '+' ? '<ins>' . e($s) . '</ins>' : '<del>' . e($s) . '</del>');
                    $added += (int) ($t === '+' && trim($s) !== '');
                    $deleted += (int) ($t === '-' && trim($s) !== '');
                }
                $html .= '</p>';
                $i++;
            } else {
                $html .= '<p>' . ($type === '+' ? '<ins>' : '<del>') . e($text) . ($type === '+' ? '</ins>' : '</del>') . '</p>';
                $count = count(self::words($text)) / 2;
                $type === '+' ? $added += (int) ceil($count) : $deleted += (int) ceil($count);
            }
        }

        return ['html' => $html, 'added' => $added, 'deleted_at' => $deleted];
    }

    /** @return list<string> */
    private static function paragraphs(string $html): array
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#</(p|h[1-6]|li|blockquote|figcaption|tr|div)>|<br\s*/?>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5);

        return array_values(array_filter(array_map(fn (string $r): string => trim((string) preg_replace('/\s+/u', ' ', $r)), explode("\n", $text)), fn (string $r): bool => $r !== ''));
    }

    /** Words including the whitespace after them, so that the text can be put back together. @return list<string> */
    private static function words(string $text): array
    {
        return preg_split('/(?<=\s)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Longest common subsequence -> steps [type, text]: "=" unchanged, "-" only in the old, "+" only in the new version.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:string, 1:string}>
     */
    private static function steps(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n * $m > 4_000_000) { // too long for an exact comparison: all deleted, all added
            return [...array_map(fn (string $s): array => ['-', $s], $a), ...array_map(fn (string $s): array => ['+', $s], $b)];
        }
        $d = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $d[$i][$j] = $a[$i] === $b[$j] ? $d[$i + 1][$j + 1] + 1 : max($d[$i + 1][$j], $d[$i][$j + 1]);
            }
        }
        $steps = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $steps[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($d[$i + 1][$j] >= $d[$i][$j + 1]) {
                $steps[] = ['-', $a[$i++]];
            } else {
                $steps[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $steps[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $steps[] = ['+', $b[$j++]];
        }

        return $steps;
    }
}
